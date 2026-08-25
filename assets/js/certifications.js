const certificationState = {items: [], loaded: false, table: null, quickCreate: false};

async function certificationApi(method, data = {}) {
  const response = await fetch('api/index.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({controller:'agronomo',method,data})});
  const raw = await response.text(); let payload;
  try { payload = JSON.parse(raw); } catch (_) { throw new Error(`Respuesta inválida del servidor (HTTP ${response.status}).`); }
  if (response.status === 401) { handleSessionExpired(); throw new Error(payload.message || 'Tu sesión expiró.'); }
  if (!response.ok || payload.success !== true) throw new Error(payload.message || 'No fue posible completar la operación.');
  return payload.detail;
}

function certificationCan(permission) {
  try { const user=JSON.parse(sessionStorage.getItem('agronomo_user'))||{}; return user.rol_codigo==='admin'||(user.permissions||[]).includes(permission); } catch (_) { return false; }
}
function escapeCertification(value) { const node=document.createElement('span'); node.textContent=value==null?'':String(value); return node.innerHTML; }

async function loadCertificationCatalog(force = false) {
  if (certificationState.loaded && !force) return certificationState.items;
  const items = await certificationApi('getTiposCertificacionWeb');
  certificationState.items = items || [];
  certificationState.loaded = true;
  renderCertificationCatalog();
  return certificationState.items;
}
window.loadCertificationCatalog = loadCertificationCatalog;

function renderCertificationCatalog() {
  const body = document.querySelector('#certifications-table-body');
  if (!body) return;
  if (certificationState.table) { certificationState.table.destroy(); certificationState.table=null; }
  const canEdit = certificationCan('certificaciones.editar');
  body.innerHTML = certificationState.items.map((item) => `<tr><td><strong>${escapeCertification(item.nombre)}</strong><small>${escapeCertification(item.descripcion||'Sin descripción')}</small></td><td><code class="catalog-code">${escapeCertification(item.codigo)}</code></td><td>${Number(item.requiere_vencimiento)===1?'Genera alertas':'No requerida'}</td><td><strong>${Number(item.total_predios||0)}</strong></td><td><span class="status-pill ${Number(item.activo)===1?'active':'inactive'}">${Number(item.activo)===1?'Activa':'Inactiva'}</span></td><td><div class="table-actions">${canEdit?`<button class="table-action" data-edit-certification="${escapeCertification(item.codigo)}">Editar</button><button class="table-action table-action-toggle${Number(item.activo)===1?' danger':''}" data-toggle-certification="${escapeCertification(item.codigo)}">${Number(item.activo)===1?'Inactivar':'Activar'}</button>`:''}</div></td></tr>`).join('') || '<tr class="empty-row"><td colspan="6">No hay certificaciones registradas.</td></tr>';
  body.querySelectorAll('[data-edit-certification]').forEach((button)=>button.onclick=()=>openCertificationDialog(certificationState.items.find((item)=>item.codigo===button.dataset.editCertification)));
  body.querySelectorAll('[data-toggle-certification]').forEach((button)=>button.onclick=()=>toggleCertification(button.dataset.toggleCertification));
  if (window.jQuery?.fn?.DataTable && certificationState.items.length) certificationState.table=window.jQuery('#certifications-table').DataTable({pageLength:10,order:[[0,'asc']],columnDefs:[{targets:5,orderable:false,searchable:false}],dom:'<"dt-top"lf>rt<"dt-bottom"ip>',language:{emptyTable:'No hay certificaciones',zeroRecords:'No se encontraron resultados',info:'Mostrando _START_ a _END_ de _TOTAL_',infoEmpty:'0 certificaciones',lengthMenu:'Mostrar _MENU_',search:'Buscar:',searchPlaceholder:'Nombre o código…',paginate:{next:'Siguiente',previous:'Anterior'}}});
}

function certificationCodeFromName(name) {
  return String(name||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toUpperCase().replace(/[^A-Z0-9]+/g,'_').replace(/^_+|_+$/g,'').slice(0,50);
}

function openCertificationDialog(item = null, quickCreate = false) {
  certificationState.quickCreate = quickCreate;
  document.querySelector('#certification-dialog-title').textContent=item?'Editar certificación':'Nueva certificación';
  document.querySelector('#certification-original-code').value=item?.codigo||'';
  document.querySelector('#certification-name').value=item?.nombre||'';
  document.querySelector('#certification-code').value=item?.codigo||'';
  document.querySelector('#certification-code').readOnly=Boolean(item);
  document.querySelector('#certification-description').value=item?.descripcion||'';
  document.querySelector('#certification-requires-expiration').checked=!item||Number(item.requiere_vencimiento)===1;
  document.querySelector('#certification-dialog-message').textContent='';
  document.querySelector('#certification-dialog').showModal();
  window.setTimeout(()=>document.querySelector('#certification-name').focus(),60);
}
window.openCertificationDialog = openCertificationDialog;

async function toggleCertification(code) {
  const item=certificationState.items.find((row)=>row.codigo===code); if(!item)return;
  const active=Number(item.activo)===1;
  const confirmed=await Swal.fire({title:`¿${active?'Inactivar':'Activar'} certificación?`,text:active?'Dejará de aparecer en predios nuevos, pero su historial se conservará.':'Volverá a estar disponible en el formulario de predios.',icon:'warning',showCancelButton:true,confirmButtonText:'Sí, continuar',cancelButtonText:'Cancelar',confirmButtonColor:'#173f32'});
  if(!confirmed.value)return;
  try { await certificationApi('toggleTipoCertificacionWeb',{codigo:code}); await loadCertificationCatalog(true); } catch(error){notifyResult(error.message,false);}
}

document.querySelector('[data-view="certifications"]')?.addEventListener('click',()=>loadCertificationCatalog(true).catch((error)=>notifyResult(error.message,false)));
document.querySelector('#new-certification-button')?.addEventListener('click',()=>openCertificationDialog());
document.querySelector('#quick-new-certification-button')?.addEventListener('click',()=>openCertificationDialog(null,true));
document.querySelector('#certification-name')?.addEventListener('input',(event)=>{if(!document.querySelector('#certification-original-code').value)document.querySelector('#certification-code').value=certificationCodeFromName(event.target.value);});
document.querySelector('#certification-code')?.addEventListener('input',(event)=>{event.target.value=certificationCodeFromName(event.target.value);});
document.querySelector('#certification-form')?.addEventListener('submit',async(event)=>{
  event.preventDefault();
  try {
    const detail=await certificationApi('saveTipoCertificacionWeb',{codigo_original:document.querySelector('#certification-original-code').value,codigo:document.querySelector('#certification-code').value,nombre:document.querySelector('#certification-name').value,descripcion:document.querySelector('#certification-description').value,requiere_vencimiento:document.querySelector('#certification-requires-expiration').checked});
    const quick=certificationState.quickCreate;
    document.querySelector('#certification-dialog').close();
    await loadCertificationCatalog(true);
    if(quick&&window.refreshPropertyCertifications)await window.refreshPropertyCertifications(detail.codigo);
    else await Swal.fire({title:'Certificación guardada',icon:'success',confirmButtonColor:'#173f32'});
  } catch(error){document.querySelector('#certification-dialog-message').textContent=error.message;}
});
