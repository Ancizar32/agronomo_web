const notificationState = {loaded:false, items:[], users:[], roles:[], search:''};

function notificationCan(permission) {
  try {
    const user=JSON.parse(sessionStorage.getItem('agronomo_user')||'{}');
    return user.rol_codigo==='admin'||(user.permissions||[]).includes(permission);
  } catch (_) { return false; }
}

async function notificationApi(method, data={}) {
  const response = await fetch('api/index.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({controller:'agronomo',method,data})});
  const raw = await response.text();
  let payload;
  try { payload=JSON.parse(raw); } catch (_) { throw new Error(`Respuesta inválida del servidor (HTTP ${response.status}).`); }
  if (response.status===401) { handleSessionExpired(); throw new Error(payload.message||'Tu sesión expiró.'); }
  if (!response.ok || payload.success!==true) throw new Error(payload.message||'No fue posible completar la operación.');
  return {detail:payload.detail,message:payload.message};
}

function notificationEscape(value) { const el=document.createElement('span'); el.textContent=value==null?'':String(value); return el.innerHTML; }
function notificationDate(value) { if(!value)return '—'; const d=new Date(String(value).replace(' ','T')); return Number.isNaN(d.getTime())?value:d.toLocaleString('es-CO',{dateStyle:'medium',timeStyle:'short'}); }

function initNotificationAudience() {
  if(!window.jQuery?.fn?.select2)return;
  const $select=window.jQuery('#notification-audience');
  if($select.hasClass('select2-hidden-accessible'))$select.select2('destroy');
  $select.select2({dropdownParent:window.jQuery('#notification-form'),width:'100%',minimumResultsForSearch:Infinity});
  $select.off('change.notificationAudience').on('change.notificationAudience',renderNotificationTargets);
}

function updateNotificationTargetCount() {
  const select=document.querySelector('#notification-targets');
  const count=select.selectedOptions.length;
  document.querySelector('#notification-targets-count').textContent=`${count} ${count===1?'seleccionado':'seleccionados'}`;
  document.querySelector('#notification-targets-clear').disabled=count===0;
}

function renderNotificationTargets() {
  const audience=document.querySelector('#notification-audience').value;
  const wrap=document.querySelector('#notification-targets-wrap');
  const select=document.querySelector('#notification-targets');
  const $select=window.jQuery?.fn?.select2?window.jQuery(select):null;
  if($select?.hasClass('select2-hidden-accessible'))$select.select2('destroy');
  if(audience==='TODOS'){wrap.hidden=true;select.innerHTML='';updateNotificationTargetCount();return;}
  const source=audience==='ROL'?notificationState.roles:notificationState.users;
  const byRole=audience==='ROL';
  const placeholder=byRole?'Buscar y seleccionar roles…':'Buscar por nombre o usuario…';
  document.querySelector('#notification-targets-label').textContent=byRole?'Roles destinatarios':'Usuarios destinatarios';
  document.querySelector('#notification-targets-help').textContent=source.length?`${source.length} opciones disponibles. Puedes seleccionar una o varias.`:'No hay opciones disponibles.';
  select.innerHTML=source.map(item=>`<option value="${notificationEscape(item.id)}">${notificationEscape(byRole?item.nombre:`${item.name} (@${item.user})`)}</option>`).join('');
  wrap.hidden=false;
  if($select){$select.select2({dropdownParent:window.jQuery('#notification-form'),width:'100%',placeholder,closeOnSelect:false,minimumResultsForSearch:0,language:{noResults:()=> 'No se encontraron coincidencias',searching:()=> 'Buscando…'}});$select.off('change.notificationTargets').on('change.notificationTargets',updateNotificationTargetCount);}
  updateNotificationTargetCount();
}

function renderNotifications() {
  const list=document.querySelector('#notification-list');
  const term=notificationState.search.trim().toLocaleLowerCase('es');
  const items=term?notificationState.items.filter(item=>[item.titulo,item.mensaje,item.creado_por,item.confirmadas_nombres,item.pendientes_nombres].some(value=>String(value||'').toLocaleLowerCase('es').includes(term))):notificationState.items;
  list.innerHTML=items.length?items.map(item=>{
    const total=Number(item.destinatarios||0), completed=Number(item.confirmadas||0), pending=Math.max(0,total-completed);
    const pushSent=Number(item.push_enviadas||0), pushErrors=Number(item.push_errores||0), pushWithoutToken=Number(item.push_sin_token||0), pushPending=Number(item.push_pendientes||0);
    const pushProblem=pushErrors+pushWithoutToken+pushPending;
    const pushInfo=`<div class="notification-push"><span class="delivery-badge done">${pushSent} enviados</span>${pushErrors?`<span class="delivery-badge error" title="${notificationEscape(item.push_error_detalle||'Error de Firebase')}">${pushErrors} errores</span>`:''}${pushWithoutToken?`<span class="delivery-badge pending">${pushWithoutToken} sin token</span>`:''}${pushProblem&&notificationCan('notificaciones.enviar')?`<button type="button" class="notification-retry" data-notification-id="${notificationEscape(item.id)}">Reintentar push</button>`:''}</div>`;
    const detail=(item.confirmadas_nombres||item.pendientes_nombres)?`<details class="notification-delivery"><summary><span class="delivery-badge done"><svg class="icon"><use href="#icon-check"></use></svg>${completed} actualizados</span><span class="delivery-badge pending"><svg class="icon"><use href="#icon-clock"></use></svg>${pending} pendientes</span><svg class="icon delivery-chevron"><use href="#icon-chevron-down"></use></svg></summary><div class="delivery-people">${item.confirmadas_nombres?`<p><span class="person-state done"><svg class="icon"><use href="#icon-check"></use></svg></span><span><strong>Ya actualizaron</strong><small>${notificationEscape(item.confirmadas_nombres)}</small></span></p>`:''}${item.pendientes_nombres?`<p><span class="person-state pending"><svg class="icon"><use href="#icon-clock"></use></svg></span><span><strong>Pendientes</strong><small>${notificationEscape(item.pendientes_nombres)}</small></span></p>`:''}</div></details>`:`<div class="notification-delivery-static"><span class="delivery-badge done"><svg class="icon"><use href="#icon-check"></use></svg>${completed} actualizados</span><span class="delivery-badge pending"><svg class="icon"><use href="#icon-clock"></use></svg>${pending} pendientes</span></div>`;
    return `<article class="notification-entry"><div class="notification-entry-mark">${item.requiere_actualizacion==1?'↻':'◉'}</div><div><header><strong>${notificationEscape(item.titulo)}</strong><time>${notificationEscape(notificationDate(item.created_at))}</time></header><p>${notificationEscape(item.mensaje)}</p><footer><span>${total} destinatarios</span>${item.data_version?`<b>Datos v${Number(item.data_version)}</b>`:''}</footer>${pushInfo}${detail}</div></article>`;
  }).join(''):`<p class="notification-empty">${term?'No encontramos envíos con ese criterio.':'Todavía no se han enviado notificaciones.'}</p>`;
}

async function loadNotifications(force=false) {
  if(notificationState.loaded&&!force)return;
  const {detail}=await notificationApi('getNotificacionesWeb');
  notificationState.items=detail.notificaciones||[]; notificationState.users=detail.usuarios||[]; notificationState.roles=detail.roles||[]; notificationState.loaded=true;
  document.querySelector('#notification-form').hidden=!notificationCan('notificaciones.enviar');
  document.querySelector('#notification-version').textContent=`v${detail.version?.version||1}`;
  document.querySelector('#notification-version-date').textContent=detail.version?.updated_at?`Actualizada ${notificationDate(detail.version.updated_at)}`:'Versión inicial';
  renderNotificationTargets(); renderNotifications();
}

initNotificationAudience();
document.querySelector('.nav-item[data-view="notifications"]')?.addEventListener('click',()=>loadNotifications().catch(error=>notifyResult(error.message,false)));
document.querySelector('#notification-refresh')?.addEventListener('click',()=>loadNotifications(true).catch(error=>notifyResult(error.message,false)));
document.querySelector('#notification-search')?.addEventListener('input',event=>{notificationState.search=event.target.value;renderNotifications();});
document.querySelector('#notification-audience')?.addEventListener('change',renderNotificationTargets);
document.querySelector('#notification-targets')?.addEventListener('change',updateNotificationTargetCount);
document.querySelector('#notification-targets-clear')?.addEventListener('click',()=>{
  const select=document.querySelector('#notification-targets');
  [...select.options].forEach(option=>{option.selected=false;});
  if(window.jQuery?.fn?.select2)window.jQuery(select).val(null).trigger('change');else select.dispatchEvent(new Event('change'));
  updateNotificationTargetCount();
});
document.querySelector('#notification-targets-all')?.addEventListener('click',()=>{
  const select=document.querySelector('#notification-targets');
  [...select.options].forEach(option=>{option.selected=true;});
  if(window.jQuery?.fn?.select2)window.jQuery(select).trigger('change');else select.dispatchEvent(new Event('change'));
  updateNotificationTargetCount();
});
document.querySelector('#notification-requires-update')?.addEventListener('change',event=>{document.querySelector('#notification-required-wrap').hidden=!event.target.checked;if(!event.target.checked)document.querySelector('#notification-required').checked=false;});
document.querySelector('#notification-list')?.addEventListener('click',async event=>{
  const button=event.target.closest('.notification-retry');
  if(!button)return;
  button.disabled=true; const previous=button.textContent; button.textContent='Reintentando…';
  try { const result=await notificationApi('retryNotificacionPushWeb',{id:button.dataset.notificationId}); await loadNotifications(true); notifyResult(result.message,true); }
  catch(error){notifyResult(error.message,false);button.disabled=false;button.textContent=previous;}
});
document.querySelector('#notification-form')?.addEventListener('submit',async event=>{
  event.preventDefault();
  const audience=document.querySelector('#notification-audience').value;
  const targets=[...document.querySelector('#notification-targets').selectedOptions].map(option=>option.value);
  if(audience!=='TODOS'&&!targets.length){notifyResult('Selecciona al menos un destinatario.',false,'warning');return;}
  const confirmation=await Swal.fire({title:'¿Enviar este aviso?',text:'Los destinatarios lo verán en sus dispositivos.',icon:'question',showCancelButton:true,confirmButtonText:'Sí, enviar',cancelButtonText:'Cancelar',confirmButtonColor:'#173f32'});
  if(!confirmation.value)return;
  const button=document.querySelector('#notification-send');
  const buttonLabel=button.querySelector('span');
  button.disabled=true;
  buttonLabel.textContent='Enviando…';
  try {
    const result=await notificationApi('sendNotificacionWeb',{titulo:document.querySelector('#notification-title').value.trim(),mensaje:document.querySelector('#notification-message').value.trim(),audiencia:audience,audiencia_valores:targets,requiere_actualizacion:document.querySelector('#notification-requires-update').checked?1:0,actualizacion_obligatoria:document.querySelector('#notification-required').checked?1:0});
    event.target.reset(); if(window.jQuery?.fn?.select2)window.jQuery('#notification-audience').val('TODOS').trigger('change'); document.querySelector('#notification-required-wrap').hidden=true; renderNotificationTargets(); await loadNotifications(true); notifyResult(result.message,true);
  } catch(error){notifyResult(error.message,false);} finally{button.disabled=false;buttonLabel.textContent='Enviar notificación';}
});
