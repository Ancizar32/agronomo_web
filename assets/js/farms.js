const farmState = { farms: [], selected: null, lots: [], crops: [], assignableUsers: [], loaded: false, table: null };
const farmTableBody = document.querySelector('#farms-table-body');
const farmDetail = document.querySelector('#farm-detail');
const farmMessage = document.querySelector('#farm-message');

async function farmApi(method, data = {}) {
  const response = await fetch('api/index.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({controller: 'agronomo', method, data}),
  });
  const raw = await response.text();
  let payload;
  try { payload = JSON.parse(raw); } catch (_) { throw new Error(`Respuesta inválida del servidor (HTTP ${response.status}).`); }
  if (response.status === 401) { handleSessionExpired(); throw new Error(payload.message || 'Tu sesión expiró. Inicia sesión nuevamente.'); }
  if (!response.ok || payload.success !== true) throw new Error(payload.message || 'No fue posible completar la operación.');
  return payload.detail;
}

function currentFarmUser() {
  try { return JSON.parse(sessionStorage.getItem('agronomo_user')) || {}; } catch (_) { return {}; }
}

function farmCan(permission) {
  const user = currentFarmUser();
  return user.rol_codigo === 'admin' || (user.permissions || []).includes(permission);
}

function escapeFarmHtml(value) {
  const element = document.createElement('span');
  element.textContent = value == null ? '' : String(value);
  return element.innerHTML;
}

function showFarmMessage(text, success = false) {
  notifyResult(text, success);
}

function updateFarmUserCount() {
  const select = document.querySelector('#farm-users');
  const selected = select.selectedOptions.length;
  document.querySelector('#farm-users-count').textContent = `${selected} ${selected === 1 ? 'seleccionado' : 'seleccionados'}`;
}

function setAllFarmUsers(selectAll) {
  const select = document.querySelector('#farm-users');
  [...select.options].forEach((option) => { option.selected = selectAll && !option.disabled; });
  if (window.jQuery?.fn?.select2) window.jQuery(select).trigger('change');
  else updateFarmUserCount();
}

async function loadFarms(force = false) {
  if (farmState.loaded && !force) return;
  await refreshUserPermissions();
  farmTableBody.innerHTML = '<tr class="empty-row"><td colspan="7"><strong>Consultando fincas…</strong></td></tr>';
  showFarmMessage('');
  try {
    farmState.farms = await farmApi('getFincasWeb');
    farmState.loaded = true;
    renderFarmTable();
    renderFarmMetrics();
    const requestedFarmId = sessionStorage.getItem('agronomo_open_farm');
    if (requestedFarmId) {
      sessionStorage.removeItem('agronomo_open_farm');
      const requestedStep = Number(sessionStorage.getItem('agronomo_open_property_step') || 0);
      sessionStorage.removeItem('agronomo_open_property_step');
      const requestedFarm = farmState.farms.find((farm) => farm.id === requestedFarmId);
      if (requestedFarm) { await openPropertyWizard(requestedFarm, requestedStep); return; }
    }
    if (farmState.selected) {
      const refreshed = farmState.farms.find((farm) => farm.id === farmState.selected.id);
      if (refreshed) await selectFarm(refreshed);
    }
  } catch (error) {
    farmTableBody.innerHTML = '<tr class="empty-row"><td colspan="7"><strong>No fue posible cargar las fincas.</strong></td></tr>';
    console.error('getFincasWeb:', error.message, '(verifica que la migración 002 esté aplicada)');
    showFarmMessage(error.message);
  }
}

function renderFarmMetrics() {
  const active = farmState.farms.filter((farm) => farm.voided === '1');
  const lots = active.reduce((total, farm) => total + Number(farm.total_lotes || 0), 0);
  const area = active.reduce((total, farm) => total + Number(farm.total_hectareas || 0), 0);
  document.querySelector('#farm-count').textContent = active.length;
  document.querySelector('#lot-count').textContent = lots;
  document.querySelector('#area-count').textContent = `${area.toLocaleString('es-CO', {maximumFractionDigits: 2})} ha`;
}

function renderFarmTable() {
  if (farmState.table) { farmState.table.destroy(); farmState.table = null; }
  const canEditFarm = farmCan('fincas.editar');
  farmTableBody.innerHTML = farmState.farms.map((farm) => {
    const responsibleCount = Number(farm.total_usuarios || 0);
    const area = Number(farm.total_hectareas || 0);
    const alerts = Number(farm.total_alertas || 0);
    const criticalAlerts = Number(farm.alertas_criticas || 0);
    const actions = [
      `<button class="table-action" data-view-farm="${escapeFarmHtml(farm.id)}">Ver lotes</button>`,
      canEditFarm ? `<button class="table-action" data-edit-property="${escapeFarmHtml(farm.id)}">${farm.productor_id ? 'Editar predio' : 'Completar predio'}</button>` : '',
      canEditFarm ? `<button class="table-action" data-edit-farm="${escapeFarmHtml(farm.id)}">Asignar</button>` : '',
    ].join('');
    return `<tr><td><div class="farm-table-name"><strong>${escapeFarmHtml(farm.descripcion)}</strong><small>${farm.productor_id ? 'Predio completo' : 'Finca básica'}${alerts ? ` · <span class="farm-alert-badge ${criticalAlerts?'critical':'warning'}">! ${alerts} ${alerts===1?'alerta':'alertas'}</span>` : ''}</small></div></td><td><div class="farm-location-cell">${icon('pin')}${escapeFarmHtml(farm.ubicacion || 'Sin ubicación')}</div></td><td><div class="farm-directory-count"><strong>${Number(farm.total_lotes || 0)}</strong><span>lotes</span></div></td><td>${area ? `${area.toLocaleString('es-CO',{maximumFractionDigits:2})} ha` : '—'}</td><td>${responsibleCount ? `<div class="farm-responsibles"><strong>${responsibleCount} ${responsibleCount===1?'responsable':'responsables'}</strong><small>${escapeFarmHtml(farm.responsables || 'Equipo asignado')}</small></div>` : '<span class="no-assignment">Sin asignar</span>'}</td><td><span class="status-pill ${farm.voided==='1'?'active':'inactive'}">${farm.voided==='1'?'Activo':'Inactivo'}</span></td><td><div class="table-actions">${actions}</div></td></tr>`;
  }).join('');
  farmTableBody.querySelectorAll('[data-view-farm]').forEach((button) => button.onclick = () => { const farm=farmState.farms.find((item)=>item.id===button.dataset.viewFarm); if(farm)selectFarm(farm); });
  farmTableBody.querySelectorAll('[data-edit-farm]').forEach((button) => button.onclick = () => { const farm=farmState.farms.find((item)=>item.id===button.dataset.editFarm); if(farm)openFarmDialog(farm); });
  farmTableBody.querySelectorAll('[data-edit-property]').forEach((button) => button.onclick = () => { const farm=farmState.farms.find((item)=>item.id===button.dataset.editProperty); if(farm)openPropertyWizard(farm); });
  if (window.jQuery?.fn?.DataTable) {
    farmState.table = window.jQuery('#farms-table').DataTable({pageLength:25,lengthMenu:[[10,25,50,-1],[10,25,50,'Todas']],order:[[0,'asc']],autoWidth:false,columnDefs:[{targets:6,orderable:false,searchable:false}],dom:'<"dt-top"lf>rt<"dt-bottom"ip>',language:{emptyTable:'No hay fincas registradas',zeroRecords:'No se encontraron fincas',info:'Mostrando _START_ a _END_ de _TOTAL_',infoEmpty:'0 fincas',lengthMenu:'Mostrar _MENU_',search:'Buscar:',searchPlaceholder:'Nombre, ubicación o responsable…',paginate:{next:'Siguiente',previous:'Anterior'}}});
    applyFarmFilters();
  }
}

function applyFarmFilters() {
  if (!farmState.table) return;
  const status = document.querySelector('#farm-status-filter').value;
  const assignment = document.querySelector('#farm-assignment-filter').value;
  farmState.table.column(5).search(status ? `^${status}$` : '', true, false);
  farmState.table.column(4).search(assignment, false, true).draw();
}

async function selectFarm(farm) {
  farmState.selected = farm;
  document.querySelector('#farms-list-heading').hidden = true;
  document.querySelector('#farm-summary').hidden = true;
  document.querySelector('#farm-list-panel').hidden = true;
  document.querySelector('#farm-detail-panel').hidden = false;
  farmDetail.innerHTML = '<p class="loading-state">Consultando lotes…</p>';
  try {
    const detail = await farmApi('getFincaDetalleWeb', {finca_id: farm.id});
    farmState.lots = detail.lotes || [];
    farmState.crops = detail.cultivos || [];
    farmState.property = detail.predio || null;
    farmState.certifications = detail.certificaciones || [];
    renderFarmDetail();
  } catch (error) { farmDetail.innerHTML = `<div class="farm-empty"><h3>No fue posible cargar los lotes</h3><p>${escapeFarmHtml(error.message)}</p></div>`; }
}

function renderFarmDetail() {
  const farm = farmState.selected;
  const activeLots = farmState.lots.filter((lot) => lot.voided === '1');
  const canEditFarm = farmCan('fincas.editar');
  const canCreateLot = farmCan('lotes.crear');
  const canEditLot = farmCan('lotes.editar');
  const detailActions = [
    canEditFarm ? `<button id="edit-property-button" class="primary-button compact-action"><span>${farm.productor_id ? 'Editar predio' : 'Completar como predio'}</span></button>` : '',
    canEditFarm ? `<button id="edit-farm-button" class="icon-button">Asignar usuarios</button>` : '',
  ].join('');
  farmDetail.innerHTML = `
    <header class="farm-detail-header"><div><p class="eyebrow">${farm.productor_id ? 'Predio seleccionado' : 'Finca básica seleccionada'}</p><h2>${escapeFarmHtml(farm.descripcion)}</h2><p>${icon('pin')}${escapeFarmHtml(farm.ubicacion || 'Ubicación sin registrar')} · ${Number(farm.total_usuarios || 0)} ${Number(farm.total_usuarios || 0) === 1 ? 'usuario asignado' : 'usuarios asignados'}${Number(farm.total_alertas||0)?` · <span class="farm-alert-badge ${Number(farm.alertas_criticas||0)?'critical':'warning'}">! ${Number(farm.total_alertas)} alertas documentales</span>`:''}</p></div><div class="detail-actions">${detailActions}</div></header>
    <div class="lot-heading"><div><p class="eyebrow">Distribución productiva</p><h3>${activeLots.length} lotes activos · ${farmState.lots.length} totales</h3></div>${canCreateLot ? `<button id="new-lot-button" class="primary-button compact-action"><span>+ Agregar lote</span></button>` : ''}</div>
    <div class="farm-lots-table">${farmState.lots.length ? `<table><thead><tr><th>Lote</th><th>Cultivo</th><th>Área</th><th>Visitas</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>${farmState.lots.map((lot) => `<tr><td><strong>${escapeFarmHtml(lot.nombre)}</strong></td><td>${escapeFarmHtml(lot.cultivo || 'Sin identificar')}</td><td>${lot.hectareas ? `${Number(lot.hectareas).toLocaleString('es-CO')} ha` : '—'}</td><td><span class="visit-count-pill${Number(lot.total_visitas||0)===0?' zero':''}">${Number(lot.total_visitas||0)}</span></td><td><span class="status-pill ${lot.voided==='1'?'active':'inactive'}">${lot.voided==='1'?'Activo':'Inactivo'}</span></td><td>${canEditLot ? `<button class="table-action" data-edit-lot="${escapeFarmHtml(lot.id)}">Editar lote</button>` : ''}</td></tr>`).join('')}</tbody></table>` : '<div class="lot-empty">Esta finca aún no tiene lotes. Agrega el primero para comenzar.</div>'}</div>`;
  document.querySelector('#edit-farm-button')?.addEventListener('click', () => openFarmDialog(farm));
  document.querySelector('#edit-property-button')?.addEventListener('click', () => openPropertyWizard(farm));
  document.querySelector('#new-lot-button')?.addEventListener('click', () => openLotDialog());
  farmDetail.querySelectorAll('[data-edit-lot]').forEach((button) => button.addEventListener('click', () => openLotDialog(farmState.lots.find((lot) => lot.id === button.dataset.editLot))));
}

async function openFarmDialog(farm = null) {
  document.querySelector('#farm-dialog-title').textContent = farm ? 'Editar finca' : 'Nueva finca';
  document.querySelector('#farm-id').value = farm?.id || '';
  document.querySelector('#farm-name').value = farm?.descripcion || '';
  document.querySelector('#farm-location').value = farm?.ubicacion || '';
  const userSelect = document.querySelector('#farm-users');
  userSelect.innerHTML = '<option disabled>Cargando usuarios…</option>';
  document.querySelector('#farm-users-count').textContent = 'Cargando…';
  document.querySelector('#farm-dialog').showModal();
  try {
    farmState.assignableUsers = await farmApi('getUsuariosFincaWeb', {finca_id:farm?.id || ''});
    userSelect.innerHTML = farmState.assignableUsers.map((user) => `<option value="${escapeFarmHtml(user.id)}" ${Number(user.asignado) === 1 ? 'selected' : ''}>${escapeFarmHtml(user.name || user.user)} · @${escapeFarmHtml(user.user)}</option>`).join('');
    if (window.jQuery?.fn?.select2) {
      const $select = window.jQuery(userSelect);
      if ($select.hasClass('select2-hidden-accessible')) $select.select2('destroy');
      $select.select2({dropdownParent:window.jQuery('#farm-dialog'),width:'100%',placeholder:'Buscar y seleccionar usuarios…',closeOnSelect:false,minimumResultsForSearch:0,language:{noResults:()=> 'No se encontraron usuarios'}});
      $select.off('change.assignment').on('change.assignment', updateFarmUserCount);
    }
    updateFarmUserCount();
  } catch (error) {
    userSelect.innerHTML = '';
    showFarmMessage(error.message);
  }
}

function openLotDialog(lot = null) {
  document.querySelector('#lot-dialog-title').textContent = lot ? 'Editar lote' : 'Nuevo lote';
  document.querySelector('#lot-id').value = lot?.id || '';
  document.querySelector('#lot-farm-id').value = farmState.selected.id;
  document.querySelector('#lot-name').value = lot?.nombre || '';
  document.querySelector('#lot-area').value = lot?.hectareas || '';
  document.querySelector('#lot-crop').innerHTML = '<option value="">Selecciona un cultivo</option>' + farmState.crops.map((crop) => `<option value="${escapeFarmHtml(crop.id)}" ${lot?.cultivo_id === crop.id ? 'selected' : ''}>${escapeFarmHtml(crop.descripcion)}</option>`).join('');
  document.querySelector('#lot-dialog').showModal();
}

document.querySelector('[data-view="farms"]').addEventListener('click', () => loadFarms(true));
document.querySelector('#farm-status-filter').addEventListener('change', applyFarmFilters);
document.querySelector('#farm-assignment-filter').addEventListener('change', applyFarmFilters);
function closeFarmDetail() {
  farmState.selected = null;
  document.querySelector('#farm-detail-panel').hidden = true;
  document.querySelector('#farms-list-heading').hidden = false;
  document.querySelector('#farm-summary').hidden = false;
  document.querySelector('#farm-list-panel').hidden = false;
  farmState.table?.columns.adjust();
}
document.querySelector('#farm-detail-back').addEventListener('click', closeFarmDetail);
document.querySelector('#new-farm-button').addEventListener('click', () => openFarmDialog());
document.querySelector('#farm-users-all').addEventListener('click', () => setAllFarmUsers(true));
document.querySelector('#farm-users-none').addEventListener('click', () => setAllFarmUsers(false));
let propertyStep = 0;
const propertyDepartment = document.querySelector('#property-department');
const propertyMunicipality = document.querySelector('#property-municipality');
const propertyLocality = document.querySelector('#property-locality');

function initializePropertySelect2() {
  if (!window.jQuery?.fn?.select2) return;
  [propertyDepartment, propertyMunicipality, propertyLocality].forEach((select) => {
    const element = window.jQuery(select);
    if (!element.hasClass('select2-hidden-accessible')) {
      element.select2({
        width: '100%',
        dropdownParent: window.jQuery('#property-dialog'),
        language: {noResults: () => 'No se encontraron resultados'},
      });
      // Select2 4 dispara un evento jQuery; lo convertimos en un evento DOM
      // para conservar la cascada implementada con addEventListener.
      element.on('select2:select select2:clear', () => {
        select.dispatchEvent(new Event('change', {bubbles: true}));
      });
    }
  });
}

function setTerritoryOptions(select, placeholder, rows = [], labelBuilder = (row) => row.nombre) {
  select.innerHTML = `<option value="">${escapeFarmHtml(placeholder)}</option>` + rows.map((row) =>
    `<option value="${escapeFarmHtml(row.id)}">${escapeFarmHtml(labelBuilder(row))}</option>`
  ).join('');
  select.disabled = rows.length === 0;
  if (window.jQuery?.fn?.select2) window.jQuery(select).trigger('change.select2');
}

async function loadPropertyDepartments() {
  setTerritoryOptions(propertyDepartment, 'Cargando departamentos…');
  propertyDepartment.disabled = true;
  setTerritoryOptions(propertyMunicipality, 'Primero selecciona un departamento');
  setTerritoryOptions(propertyLocality, 'Primero selecciona un municipio');
  try {
    const rows = await farmApi('getDivisionTerritorialWeb', {nivel: 'departamentos'});
    setTerritoryOptions(propertyDepartment, 'Selecciona un departamento', rows);
  } catch (error) {
    setTerritoryOptions(propertyDepartment, 'No fue posible cargar el catálogo');
    console.error('getDivisionTerritorialWeb:', error.message, '(verifica que la migración 007 esté aplicada)');
    showFarmMessage(error.message);
  }
}

async function loadPropertyMunicipalities(departmentId, selectedId = '') {
  if (!departmentId) {
    setTerritoryOptions(propertyMunicipality, 'Primero selecciona un departamento');
    setTerritoryOptions(propertyLocality, 'Primero selecciona un municipio');
    return;
  }
  setTerritoryOptions(propertyMunicipality, 'Cargando municipios…');
  setTerritoryOptions(propertyLocality, 'Primero selecciona un municipio');
  const rows = await farmApi('getDivisionTerritorialWeb', {nivel: 'municipios', parent_id: departmentId});
  setTerritoryOptions(propertyMunicipality, rows.length ? 'Selecciona un municipio' : 'No hay municipios cargados', rows);
  propertyMunicipality.disabled = false;
  if (selectedId) {
    propertyMunicipality.value = String(selectedId);
    if (window.jQuery?.fn?.select2) window.jQuery(propertyMunicipality).trigger('change.select2');
  }
}

async function loadPropertyLocalities(municipalityId, selectedId = '') {
  if (!municipalityId) {
    setTerritoryOptions(propertyLocality, 'Primero selecciona un municipio');
    return;
  }
  setTerritoryOptions(propertyLocality, 'Cargando veredas y corregimientos…');
  const rows = await farmApi('getDivisionTerritorialWeb', {nivel: 'localidades', parent_id: municipalityId});
  setTerritoryOptions(propertyLocality, rows.length ? 'Selecciona una opción (opcional)' : 'Sin veredas o corregimientos cargados', rows, (row) => `${row.nombre} · ${row.tipo === 'CORREGIMIENTO' ? 'Corregimiento' : 'Vereda'}`);
  if (selectedId) {
    propertyLocality.value = String(selectedId);
    if (window.jQuery?.fn?.select2) window.jQuery(propertyLocality).trigger('change.select2');
  }
}

propertyDepartment.addEventListener('change', async () => {
  setTerritoryOptions(propertyMunicipality, propertyDepartment.value ? 'Cargando municipios…' : 'Primero selecciona un departamento');
  setTerritoryOptions(propertyLocality, 'Primero selecciona un municipio');
  if (!propertyDepartment.value) return;
  const selectedDepartment = propertyDepartment.value;
  try {
    await loadPropertyMunicipalities(selectedDepartment);
    if (propertyDepartment.value !== selectedDepartment) return;
  } catch (error) { showFarmMessage(error.message); }
});

propertyMunicipality.addEventListener('change', async () => {
  setTerritoryOptions(propertyLocality, propertyMunicipality.value ? 'Cargando veredas y corregimientos…' : 'Primero selecciona un municipio');
  if (!propertyMunicipality.value) return;
  const selectedMunicipality = propertyMunicipality.value;
  try {
    await loadPropertyLocalities(selectedMunicipality);
    if (propertyMunicipality.value !== selectedMunicipality) return;
  } catch (error) { showFarmMessage(error.message); }
});

function renderPropertyStep() {
  document.querySelectorAll('.wizard-step').forEach((step, index) => step.classList.toggle('active', index === propertyStep));
  document.querySelectorAll('.wizard-progress li').forEach((item, index) => {
    item.classList.toggle('active', index <= propertyStep);
    item.classList.toggle('current', index === propertyStep);
    item.classList.toggle('completed', index < propertyStep);
    item.setAttribute('aria-current', index === propertyStep ? 'step' : 'false');
  });
  document.querySelector('#wizard-back').hidden = propertyStep === 0;
  document.querySelector('#wizard-next').hidden = propertyStep === 3;
  document.querySelector('#wizard-save').hidden = propertyStep !== 3;
}
function setPropertyField(name, value) {
  const field = document.querySelector('#property-form').elements.namedItem(name);
  if (!field) return;
  if (field.type === 'checkbox') field.checked = Number(value) === 1 || value === true || value === '1';
  else field.value = value == null ? '' : value;
}

function syncPropertyExpirationFields() {
  document.querySelectorAll('#property-form [data-expiration-toggle]').forEach((checkbox) => {
    const dateField = document.querySelector(`#property-form [name="${CSS.escape(checkbox.dataset.expirationToggle)}"]`);
    if (!dateField) return;
    dateField.disabled = !checkbox.checked;
    dateField.required = checkbox.checked;
    dateField.closest('label')?.classList.toggle('expiration-required', checkbox.checked);
  });
}

document.querySelector('#property-form').addEventListener('change', (event) => {
  if (event.target.matches('[data-expiration-toggle]')) syncPropertyExpirationFields();
});

async function openPropertyWizard(farm = null, initialStep = 0) {
  const form = document.querySelector('#property-form');
  form.reset();
  syncPropertyExpirationFields();
  propertyStep = Math.max(0, Math.min(3, Number(initialStep) || 0));
  document.querySelector('#property-farm-id').value = farm?.id || '';
  document.querySelector('#property-dialog-title').textContent = farm ? (farm.productor_id ? 'Editar predio' : 'Completar finca como predio') : 'Nuevo predio';
  document.querySelector('#wizard-save-label').textContent = farm ? 'Guardar predio' : 'Registrar predio';
  renderPropertyStep();
  document.querySelector('#property-dialog').showModal();
  initializePropertySelect2();
  try {
    await loadPropertyDepartments();
    if (!farm) return;
    const detail = await farmApi('getFincaDetalleWeb', {finca_id:farm.id});
    const predio = detail.predio || {};
    const values = {
      tipo:predio.productor_tipo || 'TERCERO', productor_nombre:predio.productor_nombre || '', cedula:predio.productor_cedula,
      nit:predio.productor_nit, dv:predio.productor_dv, telefono:predio.productor_telefono, correo:predio.productor_correo,
      predio_nombre:predio.descripcion, estado:predio.estado_predio || 'ACTIVO', hectareas:predio.hectareas_totales,
      latitud:predio.latitud, longitud:predio.longitud, url:predio.url_localizacion, contrato:predio.contrato_proveeduria,
      fecha_contrato:predio.fecha_contrato, fecha_vencimiento_contrato:predio.fecha_vencimiento_contrato, version_contrato:predio.version_contrato, ica:predio.registro_ica,
      numero_ica:predio.numero_ica, vencimiento_ica:predio.vencimiento_ica, anticipo:predio.anticipo, valor_anticipo:predio.valor_anticipo,
    };
    Object.entries(values).forEach(([name,value]) => setPropertyField(name,value));
    if (predio.departamento_id) {
      propertyDepartment.value = String(predio.departamento_id);
      if (window.jQuery?.fn?.select2) window.jQuery(propertyDepartment).trigger('change.select2');
      await loadPropertyMunicipalities(predio.departamento_id, predio.municipio_id);
      if (predio.municipio_id) await loadPropertyLocalities(predio.municipio_id, predio.localidad_rural_id);
    }
    (detail.certificaciones || []).forEach((cert) => {
      setPropertyField(cert.tipo, cert.vigente);
      setPropertyField(`${cert.tipo}_fecha`, cert.valido_hasta);
    });
    syncPropertyExpirationFields();
    window.setTimeout(() => document.querySelector(`.wizard-step[data-step="${propertyStep}"] input:not([disabled]), .wizard-step[data-step="${propertyStep}"] select:not([disabled])`)?.focus(), 80);
  } catch (error) {
    document.querySelector('#property-dialog').close();
    showFarmMessage(error.message);
  }
}

document.querySelector('#new-property-button').addEventListener('click', () => openPropertyWizard());
document.querySelectorAll('[data-property-step]').forEach((tab) => {
  const activate = () => { propertyStep = Number(tab.dataset.propertyStep); renderPropertyStep(); };
  tab.addEventListener('click', activate);
  tab.addEventListener('keydown', (event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); activate(); } });
});
document.querySelector('#wizard-next').addEventListener('click', () => { const required=[...document.querySelector(`.wizard-step[data-step="${propertyStep}"]`).querySelectorAll('[required]')]; if(required.every((field)=>field.reportValidity())){propertyStep++;renderPropertyStep();} });
document.querySelector('#wizard-back').addEventListener('click', () => { propertyStep--; renderPropertyStep(); });
document.querySelectorAll('[data-close-dialog]').forEach((button) => button.addEventListener('click', () => document.querySelector(`#${button.dataset.closeDialog}`).close()));

document.querySelector('#farm-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const user = currentFarmUser();
  try {
    const usuarioIds = [...document.querySelector('#farm-users').selectedOptions].map((option) => option.value);
    await farmApi('saveFincaWeb', {id:document.querySelector('#farm-id').value, descripcion:document.querySelector('#farm-name').value, ubicacion:document.querySelector('#farm-location').value, usuario_ids:usuarioIds, created_by:user.id || ''});
    document.querySelector('#farm-dialog').close();
    closeFarmDetail();
    await loadFarms(true);
  } catch (error) { showFarmMessage(error.message); }
});

document.querySelector('#lot-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const user = currentFarmUser();
  try {
    await farmApi('saveLoteWeb', {id:document.querySelector('#lot-id').value, finca_id:document.querySelector('#lot-farm-id').value, nombre:document.querySelector('#lot-name').value, cultivo_id:document.querySelector('#lot-crop').value, hectareas:document.querySelector('#lot-area').value, created_by:user.id || ''});
    document.querySelector('#lot-dialog').close();
    await loadFarms(true);
  } catch (error) { showFarmMessage(error.message); }
});

document.querySelector('#property-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  // El formulario usa `novalidate`: los 4 pasos del wizard comparten un solo
  // <form> y los pasos inactivos están en display:none. Si dejáramos la
  // validación nativa del navegador, un campo required oculto en un paso no
  // activo (ej. "predio_nombre" en el paso 1) hace que el navegador aborte
  // el envío con "An invalid form control is not focusable" sin avisar al
  // usuario. Por eso validamos manualmente y saltamos al paso con el error.
  const steps = [...document.querySelectorAll('.wizard-step')];
  const invalidStepIndex = steps.findIndex((step) => [...step.querySelectorAll('[required]')].some((field) => !field.checkValidity()));
  if (invalidStepIndex !== -1) {
    propertyStep = invalidStepIndex;
    renderPropertyStep();
    window.setTimeout(() => steps[invalidStepIndex].querySelector('[required]:invalid')?.reportValidity(), 0);
    return;
  }
  const form=new FormData(event.currentTarget); const user=currentFarmUser(); const certs={};
  ['GLOBALGAP','RAINFOREST','FAIRTRADE','MICROBIOLOGICO'].forEach((type)=>{certs[type]={vigente:form.has(type),valido_hasta:form.get(`${type}_fecha`)||''};});
  try { const result=await farmApi('savePredioCompletoWeb',{finca_id:form.get('finca_id')||'',created_by:user.id||'',productor:{tipo:form.get('tipo'),nombre:form.get('productor_nombre'),cedula:form.get('cedula'),nit:form.get('nit'),dv:form.get('dv'),telefono:form.get('telefono'),correo:form.get('correo')},predio:{descripcion:form.get('predio_nombre'),departamento_id:form.get('departamento_id'),municipio_id:form.get('municipio_id'),localidad_rural_id:form.get('localidad_rural_id'),estado_predio:form.get('estado'),hectareas_totales:form.get('hectareas'),latitud:form.get('latitud'),longitud:form.get('longitud'),url_localizacion:form.get('url'),contrato_proveeduria:form.has('contrato'),fecha_contrato:form.get('fecha_contrato'),fecha_vencimiento_contrato:form.get('fecha_vencimiento_contrato'),version_contrato:form.get('version_contrato'),registro_ica:form.has('ica'),numero_ica:form.get('numero_ica'),vencimiento_ica:form.get('vencimiento_ica'),anticipo:form.has('anticipo'),valor_anticipo:form.get('valor_anticipo')},certificaciones:certs}); document.querySelector('#property-dialog').close(); closeFarmDetail(); await loadFarms(true); if(window.Swal) Swal.fire({icon:'success',title:'Predio guardado',text:result?.message || 'La información quedó actualizada.',confirmButtonText:'Aceptar'}); } catch(error){showFarmMessage(error.message); if(window.Swal) Swal.fire({icon:'error',title:'No fue posible guardar',text:error.message,confirmButtonText:'Aceptar'});}
});
