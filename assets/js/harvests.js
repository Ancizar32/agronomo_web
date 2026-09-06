const harvestState={planes:[],fincas:[],lotes:[],selectedId:'',detail:null,loaded:false};

async function harvestApi(method,data={}){
  const response=await fetch('api/index.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({controller:'agronomo',method,data})});
  const raw=await response.text();let payload;
  try{payload=JSON.parse(raw)}catch(_){throw new Error(`Respuesta inválida del servidor (HTTP ${response.status}).`)}
  if(response.status===401){if(typeof handleSessionExpired==='function')handleSessionExpired();throw new Error(payload.message||'Tu sesión expiró.')}
  if(!response.ok||payload.success!==true)throw new Error(payload.message||'No fue posible completar la operación.');
  return payload.detail;
}
function harvestUser(){try{return JSON.parse(sessionStorage.getItem('agronomo_user'))||{}}catch(_){return{}}}
function harvestCan(permission){const u=harvestUser();return u.rol_codigo==='admin'||(u.permissions||[]).includes(permission)}
function harvestEscape(value){const e=document.createElement('span');e.textContent=value==null?'':String(value);return e.innerHTML}
function harvestNumber(value,decimals=0){return Number(value||0).toLocaleString('es-CO',{minimumFractionDigits:decimals,maximumFractionDigits:decimals})}
function harvestDate(value){if(!value)return'—';const p=String(value).slice(0,10).split('-');return p.length===3?`${p[2]}/${p[1]}/${p[0]}`:value}
function harvestToday(){return new Date().toISOString().slice(0,10)}
function harvestMessage(text,error=false){const el=document.querySelector('#harvest-message');el.textContent=text||'';el.classList.toggle('visible',!!text);el.classList.toggle('error',error)}
async function harvestNotifySuccess(title,text){
  if(window.Swal&&typeof window.Swal.fire==='function'){
    await window.Swal.fire({title,text,icon:'success',confirmButtonText:'Aceptar',confirmButtonColor:'#173f32'});
  }else window.alert(`${title}\n${text}`);
}
function harvestStatus(value){const labels={BORRADOR:'Borrador',ACTIVO:'Activo',FINALIZADO:'Finalizado',CANCELADO:'Cancelado',PENDIENTE:'Pendiente',EN_CURSO:'En curso',CUMPLIDA:'Cumplida'};return `<span class="harvest-status ${String(value).toLowerCase().replace('_','-')}"><i></i>${harvestEscape(labels[value]||value)}</span>`}
function initHarvestSelect(selector,{placeholder='',dialog='',allowClear=false}={}){
  if(!window.jQuery?.fn?.select2)return;
  const $select=window.jQuery(selector);
  if(!$select.length)return;
  const value=$select.val();
  if(typeof initializeStandardSelect2==='function')initializeStandardSelect2(selector,{placeholder,dialog,allowClear});
  else{
    if($select.hasClass('select2-hidden-accessible'))$select.select2('destroy');
    $select.select2({width:'100%',placeholder,allowClear,minimumResultsForSearch:0,
      dropdownParent:dialog?window.jQuery(dialog):undefined,
      language:{noResults:()=> 'No se encontraron resultados'}});
  }
  $select.val(value).trigger('change.select2');
}
function initHarvestFilterSelects(){
  initHarvestSelect('#harvest-farm-filter',{placeholder:'Todas las fincas',allowClear:true});
  initHarvestSelect('#harvest-status-filter',{placeholder:'Todos los estados',allowClear:true});
  if(window.jQuery?.fn?.select2){
    window.jQuery('#harvest-farm-filter,#harvest-status-filter')
      .off('change.harvestFilter')
      .on('change.harvestFilter',applyHarvestFilters);
  }
}
function initHarvestPlanSelects(){
  initHarvestSelect('#harvest-plan-farm',{placeholder:'Busca una finca…',dialog:'#harvest-plan-dialog'});
  initHarvestSelect('#harvest-plan-crop',{placeholder:'Busca un cultivo…',dialog:'#harvest-plan-dialog'});
  initHarvestSelect('#harvest-plan-lot',{placeholder:'Todos los lotes',dialog:'#harvest-plan-dialog',allowClear:true});
  initHarvestSelect('#harvest-plan-status',{placeholder:'Selecciona un estado',dialog:'#harvest-plan-dialog'});
  if(window.jQuery?.fn?.select2){
    window.jQuery('#harvest-plan-farm').off('change.harvest').on('change.harvest',function(){populateHarvestCrops(this.value)});
    window.jQuery('#harvest-plan-crop').off('change.harvest').on('change.harvest',function(){populateHarvestLots(document.querySelector('#harvest-plan-farm').value,this.value)});
  }
}

async function loadHarvests(selectId=''){
  harvestMessage('');
  try{
    const data=await harvestApi('getPlanesCosechaWeb');
    harvestState.planes=data.planes||[];harvestState.fincas=data.fincas||[];harvestState.lotes=data.lotes||[];harvestState.loaded=true;
    populateHarvestFilters();renderHarvestMetrics();renderHarvestList();
    const target=selectId||harvestState.selectedId;
    if(target&&harvestState.planes.some(p=>p.id===target))await selectHarvestPlan(target);else if(harvestState.planes.length)await selectHarvestPlan(harvestState.planes[0].id);else renderHarvestEmpty();
  }catch(error){harvestMessage(error.message,true)}
}
function populateHarvestFilters(){
  const selected=document.querySelector('#harvest-farm-filter').value;
  if(window.jQuery?.fn?.select2){
    ['#harvest-farm-filter','#harvest-plan-farm'].forEach(selector=>{
      const $select=window.jQuery(selector);
      if($select.hasClass('select2-hidden-accessible'))$select.select2('destroy');
    });
  }
  document.querySelector('#harvest-farm-filter').innerHTML='<option value="">Todas las fincas</option>'+harvestState.fincas.map(f=>`<option value="${harvestEscape(f.id)}">${harvestEscape(f.nombre)}</option>`).join('');
  document.querySelector('#harvest-farm-filter').value=selected;
  document.querySelector('#harvest-plan-farm').innerHTML='<option value="">Selecciona una finca</option>'+harvestState.fincas.map(f=>`<option value="${harvestEscape(f.id)}">${harvestEscape(f.nombre)}</option>`).join('');
  initHarvestFilterSelects();
}
function renderHarvestMetrics(){
  const active=harvestState.planes.filter(p=>p.estado==='ACTIVO').length;
  const projected=harvestState.planes.filter(p=>p.estado!=='CANCELADO').reduce((s,p)=>s+Number(p.kg_proyectado||0),0);
  const real=harvestState.planes.filter(p=>p.estado!=='CANCELADO').reduce((s,p)=>s+Number(p.kg_real||0),0);
  const execution=projected>0?real/projected*100:0;
  document.querySelector('#harvest-active-count').textContent=harvestNumber(active);
  document.querySelector('#harvest-projected-total').textContent=`${harvestNumber(projected,1)} kg`;
  document.querySelector('#harvest-real-total').textContent=`${harvestNumber(real,1)} kg`;
  document.querySelector('#harvest-execution-total').textContent=`${harvestNumber(execution,1)}%`;
  document.querySelector('#harvest-execution-bar').style.width=`${Math.min(execution,100)}%`;
}
function filteredHarvestPlans(){const q=document.querySelector('#harvest-search').value.trim().toLowerCase(),f=document.querySelector('#harvest-farm-filter').value,s=document.querySelector('#harvest-status-filter').value;return harvestState.planes.filter(p=>(!f||p.finca_id===f)&&(!s||p.estado===s)&&(!q||[p.nombre,p.finca_nombre,p.lote_nombre,p.cultivo_nombre].some(v=>String(v||'').toLowerCase().includes(q))))}
function applyHarvestFilters(){
  const visible=filteredHarvestPlans();
  renderHarvestList();
  if(!visible.length){
    harvestState.selectedId='';
    document.querySelector('#harvest-plan-detail').innerHTML='<div class="harvest-empty"><svg class="icon"><use href="#icon-crops"></use></svg><p class="eyebrow">Sin coincidencias</p><h3>No hay planes para estos filtros</h3><p>Cambia la finca, el estado o el texto de búsqueda.</p></div>';
  }else if(!visible.some(plan=>plan.id===harvestState.selectedId))selectHarvestPlan(visible[0].id);
}
function renderHarvestList(){const list=document.querySelector('#harvest-plan-list'),items=filteredHarvestPlans();if(!items.length){list.innerHTML='<div class="harvest-empty-list">No hay planes para estos filtros.</div>';return}list.innerHTML=items.map(p=>`<button class="harvest-plan-card ${p.id===harvestState.selectedId?'active':''}" data-harvest-plan="${harvestEscape(p.id)}"><header><h3>${harvestEscape(p.nombre)}</h3>${harvestStatus(p.estado)}</header><p>${harvestEscape(p.finca_nombre)} · ${harvestEscape(p.cultivo_nombre)} · ${harvestEscape(p.lote_nombre||'Todos los lotes')}</p><div class="harvest-progress"><i style="width:${Math.min(Number(p.porcentaje_ejecucion||0),100)}%"></i></div><footer><span>${harvestNumber(p.kg_real,1)} / ${harvestNumber(p.kg_proyectado,1)} kg</span><strong>${harvestNumber(p.porcentaje_ejecucion,1)}%</strong></footer></button>`).join('');list.querySelectorAll('[data-harvest-plan]').forEach(b=>b.addEventListener('click',()=>selectHarvestPlan(b.dataset.harvestPlan)))}
function renderHarvestEmpty(){harvestState.selectedId='';harvestState.detail=null;document.querySelector('#harvest-plan-detail').innerHTML='<div class="harvest-empty"><svg class="icon"><use href="#icon-crops"></use></svg><p class="eyebrow">Seguimiento productivo</p><h3>Aún no hay planes</h3><p>Crea el primer plan para comenzar a comparar la producción proyectada con la real.</p></div>'}
async function selectHarvestPlan(id){harvestState.selectedId=id;renderHarvestList();const detail=document.querySelector('#harvest-plan-detail');detail.innerHTML='<p class="loading-state">Consultando detalle…</p>';try{harvestState.detail=await harvestApi('getPlanCosechaDetalleWeb',{id});renderHarvestDetail()}catch(error){detail.innerHTML=`<div class="harvest-empty"><h3>No fue posible abrir el plan</h3><p>${harvestEscape(error.message)}</p></div>`}}
function renderHarvestDetail(){const {plan,proyecciones}=harvestState.detail;const projected=proyecciones.reduce((s,p)=>s+Number(p.kg_proyectado||0),0),real=proyecciones.reduce((s,p)=>s+Number(p.kg_real||0),0),execution=projected>0?real/projected*100:0;document.querySelector('#harvest-plan-detail').innerHTML=`<header class="harvest-detail-head"><div><p class="eyebrow">${harvestEscape(plan.finca_nombre)} · ${harvestEscape(plan.lote_nombre||'Todos los lotes')}</p><h2>${harvestEscape(plan.nombre)}</h2><p>${harvestEscape(plan.cultivo_nombre)} · Inicio ${harvestDate(plan.fecha_inicio)} · ${harvestNumber(plan.plantas_productivas)} plantas</p></div><div class="harvest-detail-actions">${harvestCan('cosechas.editar')?'<button class="secondary-button" id="edit-harvest-plan" type="button">Editar plan</button>':''}${harvestStatus(plan.estado)}</div></header><section class="harvest-detail-summary"><div><span>Proyectado</span><strong>${harvestNumber(projected,1)} kg</strong></div><div><span>Real acumulado</span><strong>${harvestNumber(real,1)} kg</strong></div><div><span>Ejecución</span><strong>${harvestNumber(execution,1)}%</strong></div></section><div class="harvest-projection-list">${proyecciones.map(p=>`<article class="harvest-projection-card"><header><div><p class="eyebrow">Cosecha ${p.numero}</p><h4>${harvestDate(p.fecha_estimada)}</h4></div>${harvestStatus(p.estado)}</header><div class="numbers"><div>Proyectado<strong>${harvestNumber(p.kg_proyectado,1)} kg</strong></div><div>Real<strong>${harvestNumber(p.kg_real,1)} kg</strong></div><div>Kg/planta<strong>${harvestNumber(p.kg_planta_proyectado,3)}</strong></div></div><div class="harvest-progress"><i style="width:${Math.min(Number(p.porcentaje_ejecucion||0),100)}%"></i></div><footer><span>${harvestNumber(p.porcentaje_ejecucion,1)}% ejecutado · ${p.total_registros_reales} registro(s)</span>${harvestCan('cosechas.editar')?`<button type="button" data-register-harvest="${harvestEscape(p.id)}" data-number="${p.numero}">+ Registrar cosecha real</button>`:''}</footer></article>`).join('')}</div>`;
  document.querySelector('#edit-harvest-plan')?.addEventListener('click',()=>openHarvestPlanDialog(harvestState.detail));document.querySelectorAll('[data-register-harvest]').forEach(b=>b.addEventListener('click',()=>openHarvestRealDialog(b.dataset.registerHarvest,b.dataset.number)))}

function populateHarvestCrops(farmId,selected=''){const seen=new Set(),crops=harvestState.lotes.filter(l=>l.finca_id===farmId&&l.cultivo_id&&!seen.has(l.cultivo_id)&&seen.add(l.cultivo_id)),select=document.querySelector('#harvest-plan-crop');if(window.jQuery?.fn?.select2&&window.jQuery(select).hasClass('select2-hidden-accessible'))window.jQuery(select).select2('destroy');select.innerHTML='<option value="">Selecciona un cultivo</option>'+crops.map(c=>`<option value="${harvestEscape(c.cultivo_id)}">${harvestEscape(c.cultivo_nombre)}</option>`).join('');select.value=selected;initHarvestSelect('#harvest-plan-crop',{placeholder:'Busca un cultivo…',dialog:'#harvest-plan-dialog'});populateHarvestLots(farmId,selected?'':null)}
function populateHarvestLots(farmId,cropId,selected=''){const lots=harvestState.lotes.filter(l=>l.finca_id===farmId&&l.cultivo_id===cropId),select=document.querySelector('#harvest-plan-lot');if(window.jQuery?.fn?.select2&&window.jQuery(select).hasClass('select2-hidden-accessible'))window.jQuery(select).select2('destroy');select.innerHTML='<option value="">Todos los lotes del cultivo</option>'+lots.map(l=>`<option value="${harvestEscape(l.id)}">${harvestEscape(l.nombre)}</option>`).join('');select.value=selected||'';initHarvestSelect('#harvest-plan-lot',{placeholder:'Todos los lotes',dialog:'#harvest-plan-dialog',allowClear:true})}
function projectionValues(){return[...document.querySelectorAll('.harvest-projection-row')].map(row=>({id:row.dataset.id||'',fecha_estimada:row.querySelector('[data-field="date"]').value,kg_planta_proyectado:Number(row.querySelector('[data-field="kg-plant"]').value||0),plantas_proyectadas:Number(row.querySelector('[data-field="plants"]').value||0),kg_proyectado:Number(row.querySelector('[data-field="total"]').dataset.value||0),observacion:''}))}
function renderProjectionRows(count,values=[]){const defaultPlants=document.querySelector('#harvest-plan-plants').value||'',start=document.querySelector('#harvest-plan-date').value||harvestToday();const rows=[];for(let i=0;i<count;i++){const v=values[i]||{};rows.push(`<div class="harvest-projection-row" data-id="${harvestEscape(v.id||'')}"><strong>#${i+1}</strong><label>Fecha estimada<input data-field="date" type="date" required value="${harvestEscape(String(v.fecha_estimada||start).slice(0,10))}"></label><label>Kg por planta<input data-field="kg-plant" type="number" min="0.0001" step="0.0001" required value="${harvestEscape(v.kg_planta_proyectado||'')}"></label><label>Plantas<input data-field="plants" type="number" min="1" step="1" required value="${harvestEscape(v.plantas_proyectadas||defaultPlants)}"></label><label>Total proyectado<span class="harvest-projection-total" data-field="total" data-value="${harvestEscape(v.kg_proyectado||0)}">${harvestNumber(v.kg_proyectado,1)} kg</span></label></div>`)}document.querySelector('#harvest-projection-rows').innerHTML=rows.join('');document.querySelectorAll('.harvest-projection-row input').forEach(i=>i.addEventListener('input',updateProjectionTotals));updateProjectionTotals()}
function updateProjectionTotals(){document.querySelectorAll('.harvest-projection-row').forEach(row=>{const kg=Number(row.querySelector('[data-field="kg-plant"]').value||0),plants=Number(row.querySelector('[data-field="plants"]').value||0),total=kg*plants,el=row.querySelector('[data-field="total"]');el.dataset.value=String(total);el.textContent=`${harvestNumber(total,1)} kg`})}
function openHarvestPlanDialog(detail=null){const form=document.querySelector('#harvest-plan-form');form.reset();const plan=detail?.plan||{},dialog=document.querySelector('#harvest-plan-dialog');document.querySelector('#harvest-plan-dialog-title').textContent=detail?'Editar plan de cosecha':'Nuevo plan de cosecha';document.querySelector('#harvest-plan-id').value=plan.id||'';document.querySelector('#harvest-plan-name').value=plan.nombre||'';document.querySelector('#harvest-plan-farm').value=plan.finca_id||'';populateHarvestCrops(plan.finca_id||'',plan.cultivo_id||'');populateHarvestLots(plan.finca_id||'',plan.cultivo_id||'',plan.lote_id||'');document.querySelector('#harvest-plan-date').value=String(plan.fecha_inicio||harvestToday()).slice(0,10);document.querySelector('#harvest-plan-plants').value=plan.plantas_productivas||'';document.querySelector('#harvest-plan-status').value=plan.estado||'BORRADOR';document.querySelector('#harvest-plan-notes').value=plan.observacion||'';const values=detail?.proyecciones||[];document.querySelector('#harvest-plan-count').value=values.length||1;document.querySelector('#harvest-plan-dialog-message').textContent='';renderProjectionRows(values.length||1,values);dialog.showModal();initHarvestPlanSelects()}
function openHarvestRealDialog(id,number,record=null){document.querySelector('#harvest-real-form').reset();document.querySelector('#harvest-real-id').value=record?.id||'';document.querySelector('#harvest-real-projection-id').value=id;document.querySelector('#harvest-real-date').value=String(record?.fecha||harvestToday()).slice(0,10);document.querySelector('#harvest-real-kg').value=record?Number(record.kg_real||0).toFixed(2):'';document.querySelector('#harvest-real-plants').value=record?.plantas_cosechadas||'';document.querySelector('#harvest-real-notes').value=record?.observacion||'';document.querySelector('#harvest-real-title').textContent=`${record?'Editar registro':'Registrar cosecha real'} · Cosecha ${number}`;document.querySelector('#harvest-real-submit-label').textContent=record?'Guardar cambios':'Registrar producción';document.querySelector('#harvest-real-message').textContent='';document.querySelector('#harvest-real-dialog').showModal()}

document.querySelector('[data-view="harvests"]')?.addEventListener('click',()=>{if(!harvestState.loaded)loadHarvests()});
document.querySelector('#new-harvest-plan-button')?.addEventListener('click',()=>openHarvestPlanDialog());
document.querySelector('#harvest-search')?.addEventListener('input',applyHarvestFilters);
document.querySelector('#harvest-plan-count')?.addEventListener('change',e=>{const values=projectionValues(),count=Math.max(1,Math.min(100,Number(e.target.value||1)));e.target.value=count;renderProjectionRows(count,values)});
document.querySelector('#harvest-plan-plants')?.addEventListener('change',e=>{document.querySelectorAll('[data-field="plants"]').forEach(i=>{if(!i.value)i.value=e.target.value});updateProjectionTotals()});
document.querySelectorAll('[data-harvest-close]').forEach(b=>b.addEventListener('click',()=>document.querySelector(`#${b.dataset.harvestClose}`)?.close()));
document.querySelector('#harvest-plan-form')?.addEventListener('submit',async e=>{e.preventDefault();const message=document.querySelector('#harvest-plan-dialog-message');message.textContent='Guardando plan…';const id=document.querySelector('#harvest-plan-id').value;try{const result=await harvestApi('savePlanCosechaWeb',{id,nombre:document.querySelector('#harvest-plan-name').value,finca_id:document.querySelector('#harvest-plan-farm').value,cultivo_id:document.querySelector('#harvest-plan-crop').value,lote_id:document.querySelector('#harvest-plan-lot').value,fecha_inicio:document.querySelector('#harvest-plan-date').value,plantas_productivas:Number(document.querySelector('#harvest-plan-plants').value),estado:document.querySelector('#harvest-plan-status').value,observacion:document.querySelector('#harvest-plan-notes').value,proyecciones:projectionValues()});document.querySelector('#harvest-plan-dialog').close();await harvestNotifySuccess(id?'Plan actualizado':'Plan creado',id?'Los cambios del plan de cosecha se guardaron correctamente.':'El nuevo plan de cosecha se creó correctamente.');harvestState.loaded=false;await loadHarvests(result.id)}catch(error){message.textContent=error.message}});
document.querySelector('#harvest-real-form')?.addEventListener('submit',async e=>{e.preventDefault();const message=document.querySelector('#harvest-real-message');message.textContent='Guardando producción…';const id=document.querySelector('#harvest-real-id').value;try{await harvestApi('saveCosechaRealWeb',{id,cosecha_proyectada_id:document.querySelector('#harvest-real-projection-id').value,fecha:document.querySelector('#harvest-real-date').value,kg_real:Number(document.querySelector('#harvest-real-kg').value),plantas_cosechadas:document.querySelector('#harvest-real-plants').value,observacion:document.querySelector('#harvest-real-notes').value});document.querySelector('#harvest-real-dialog').close();await harvestNotifySuccess(id?'Registro actualizado':'Cosecha registrada',id?'El registro real de cosecha se actualizó correctamente.':'La producción real se registró correctamente.');harvestState.loaded=false;await loadHarvests(harvestState.selectedId)}catch(error){message.textContent=error.message}});

function harvestDateTime(value){
  if(!value)return '—';
  const date=new Date(String(value).replace(' ','T'));
  if(Number.isNaN(date.getTime()))return harvestEscape(value);
  return date.toLocaleString('es-CO',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
}

function openHarvestRecords(projectionId,number){
  const records=(harvestState.detail?.reales||[]).filter(item=>item.cosecha_proyectada_id===projectionId);
  const table=document.querySelector('#harvest-records-table');
  if(window.jQuery?.fn?.DataTable?.isDataTable(table))window.jQuery(table).DataTable().destroy();
  document.querySelector('#harvest-records-title').textContent=`Registros reales · Cosecha ${number}`;
  table.querySelector('tbody').innerHTML=records.map(item=>{const active=String(item.voided)==='1';return `<tr class="${active?'':'harvest-record-voided'}"><td data-order="${harvestEscape(item.created_at)}">${harvestDateTime(item.created_at)}</td><td data-order="${harvestEscape(item.fecha)}">${harvestDate(item.fecha)}</td><td data-order="${Number(item.kg_real||0)}"><strong>${harvestNumber(item.kg_real,2)} kg</strong></td><td data-order="${Number(item.plantas_cosechadas||0)}">${item.plantas_cosechadas?harvestNumber(item.plantas_cosechadas):'—'}</td><td>${harvestEscape(item.usuario_nombre)}</td><td>${harvestEscape(item.observacion||'—')}</td><td data-order="${active?1:0}"><span class="harvest-record-state ${active?'active':'voided'}"><i></i>${active?'Activo':'Anulado'}</span></td><td>${active?`<div class="harvest-record-actions"><button type="button" data-edit-real="${harvestEscape(item.id)}" data-projection="${harvestEscape(projectionId)}" data-number="${number}">Editar</button><button class="danger" type="button" data-void-real="${harvestEscape(item.id)}">Anular</button></div>`:'<span class="harvest-record-no-actions">Sin acciones</span>'}</td></tr>`}).join('');
  document.querySelector('#harvest-records-dialog').showModal();
  if(window.jQuery?.fn?.DataTable)window.jQuery(table).DataTable({pageLength:10,order:[[0,'desc']],autoWidth:false,scrollX:false,columnDefs:[{targets:0,width:'150px'},{targets:1,width:'115px'},{targets:2,width:'90px'},{targets:3,width:'70px'},{targets:4,width:'130px'},{targets:6,width:'85px'},{targets:7,width:'120px',orderable:false,searchable:false}],dom:'<"dt-top"lf>rt<"dt-bottom"ip>',language:{emptyTable:'No hay registros reales para esta cosecha',zeroRecords:'No se encontraron registros',info:'Mostrando _START_ a _END_ de _TOTAL_',infoEmpty:'0 registros',lengthMenu:'Mostrar _MENU_',search:'Buscar:',searchPlaceholder:'Usuario, estado u observación…',paginate:{next:'Siguiente',previous:'Anterior'}}});
  table.querySelectorAll('[data-edit-real]').forEach(button=>button.addEventListener('click',event=>{
    event.stopPropagation();
    const record=(harvestState.detail?.reales||[]).find(item=>item.id===button.dataset.editReal);
    document.querySelector('#harvest-records-dialog').close();
    if(record)openHarvestRealDialog(button.dataset.projection,button.dataset.number,record);
  }));
  table.querySelectorAll('[data-void-real]').forEach(button=>button.addEventListener('click',async event=>{
    event.stopPropagation();
    const confirmation=window.Swal&&typeof window.Swal.fire==='function'
      ? await window.Swal.fire({title:'¿Anular este registro?',text:'Los totales y el porcentaje de ejecución se recalcularán. El registro se conservará para auditoría.',icon:'warning',showCancelButton:true,confirmButtonText:'Sí, anular',cancelButtonText:'Cancelar',confirmButtonColor:'#9a4e3c'})
      : {isConfirmed:window.confirm('¿Anular este registro de cosecha?')};
    const confirmed=confirmation?.isConfirmed===true||confirmation?.value===true;
    if(!confirmed)return;
    button.disabled=true;
    button.textContent='Anulando…';
    try{
      await harvestApi('anularCosechaRealWeb',{id:button.dataset.voidReal});
      document.querySelector('#harvest-records-dialog').close();
      await harvestNotifySuccess('Registro anulado','El registro se anuló y los totales de la cosecha fueron recalculados.');
      harvestState.loaded=false;
      await loadHarvests(harvestState.selectedId);
    }catch(error){
      button.disabled=false;
      button.textContent='Anular';
      if(window.Swal&&typeof window.Swal.fire==='function')window.Swal.fire({title:'No fue posible anular',text:error.message,icon:'error'});
      else window.alert(error.message);
    }
  }));
}

// El encabezado numerado de cada proyección abre sus registros reales en una
// tabla independiente, manteniendo visible el comparativo del plan al cerrar.
renderHarvestDetail=function(){
  const {plan,proyecciones,reales=[]}=harvestState.detail;
  const projected=proyecciones.reduce((sum,item)=>sum+Number(item.kg_proyectado||0),0);
  const real=proyecciones.reduce((sum,item)=>sum+Number(item.kg_real||0),0);
  const execution=projected>0?real/projected*100:0;
  document.querySelector('#harvest-plan-detail').innerHTML=`<header class="harvest-detail-head"><div><p class="eyebrow">${harvestEscape(plan.finca_nombre)} · ${harvestEscape(plan.lote_nombre||'Todos los lotes')}</p><h2>${harvestEscape(plan.nombre)}</h2><p>${harvestEscape(plan.cultivo_nombre)} · Inicio ${harvestDate(plan.fecha_inicio)} · ${harvestNumber(plan.plantas_productivas)} plantas</p></div><div class="harvest-detail-actions">${harvestCan('cosechas.editar')?'<button class="secondary-button" id="edit-harvest-plan" type="button">Editar plan</button>':''}${harvestStatus(plan.estado)}</div></header><section class="harvest-detail-summary"><div><span>Proyectado</span><strong>${harvestNumber(projected,1)} kg</strong></div><div><span>Real acumulado</span><strong>${harvestNumber(real,1)} kg</strong></div><div><span>Ejecución</span><strong>${harvestNumber(execution,1)}%</strong></div></section><div class="harvest-projection-list">${proyecciones.map(item=>`<article class="harvest-projection-card"><header><div><button class="harvest-record-toggle" type="button" data-harvest-records="${harvestEscape(item.id)}" data-number="${item.numero}">Cosecha ${item.numero}</button><h4>${harvestDate(item.fecha_estimada)}</h4></div>${harvestStatus(item.estado)}</header><div class="numbers"><div>Proyectado<strong>${harvestNumber(item.kg_proyectado,1)} kg</strong></div><div>Real<strong>${harvestNumber(item.kg_real,1)} kg</strong></div><div>Kg/planta<strong>${harvestNumber(item.kg_planta_proyectado,3)}</strong></div></div><div class="harvest-progress"><i style="width:${Math.min(Number(item.porcentaje_ejecucion||0),100)}%"></i></div><footer><span>${harvestNumber(item.porcentaje_ejecucion,1)}% ejecutado · ${item.total_registros_reales} registro(s)</span>${harvestCan('cosechas.editar')?`<button type="button" data-register-harvest="${harvestEscape(item.id)}" data-number="${item.numero}">+ Registrar cosecha real</button>`:''}</footer></article>`).join('')}</div>`;
  document.querySelector('#edit-harvest-plan')?.addEventListener('click',()=>openHarvestPlanDialog(harvestState.detail));
  document.querySelectorAll('[data-register-harvest]').forEach(button=>button.addEventListener('click',()=>openHarvestRealDialog(button.dataset.registerHarvest,button.dataset.number)));
  document.querySelectorAll('[data-harvest-records]').forEach(button=>button.addEventListener('click',()=>openHarvestRecords(button.dataset.harvestRecords,button.dataset.number)));
};

document.querySelector('#harvest-records-dialog')?.addEventListener('click',async event=>{
  const editButton=event.target.closest('[data-edit-real]');
  if(editButton){
    const record=(harvestState.detail?.reales||[]).find(item=>item.id===editButton.dataset.editReal);
    document.querySelector('#harvest-records-dialog').close();
    if(record)openHarvestRealDialog(editButton.dataset.projection,editButton.dataset.number,record);
    return;
  }
  const voidButton=event.target.closest('[data-void-real]');
  if(!voidButton)return;
  const confirmation=window.Swal
    ? await Swal.fire({title:'¿Anular este registro?',text:'Los totales y el porcentaje de ejecución se recalcularán. El registro se conservará para auditoría.',icon:'warning',showCancelButton:true,confirmButtonText:'Sí, anular',cancelButtonText:'Cancelar',confirmButtonColor:'#9a4e3c'})
    : {isConfirmed:window.confirm('¿Anular este registro de cosecha?')};
  const confirmed=confirmation?.isConfirmed===true||confirmation?.value===true;
  if(!confirmed)return;
  try{
    await harvestApi('anularCosechaRealWeb',{id:voidButton.dataset.voidReal});
    document.querySelector('#harvest-records-dialog').close();
    await harvestNotifySuccess('Registro anulado','El registro se anuló y los totales de la cosecha fueron recalculados.');
    harvestState.loaded=false;
    await loadHarvests(harvestState.selectedId);
  }catch(error){
    if(window.Swal)Swal.fire({title:'No fue posible anular',text:error.message,icon:'error'});
    else alert(error.message);
  }
});
