// Módulo web de "Agendar visitas" — mismo control que el módulo móvil
// (finca, fecha/hora, objetivo, duración, observación y estado con las
// 4 fases PROGRAMADA/EN_CURSO/COMPLETADA/CANCELADA sin flujo forzado), con
// selección de técnico habilitada para roles admin.

const agendaState = {items: [], fincas: [], tecnicos: [], isAdmin: false, loaded: false, table: null};

const AGENDA_ESTADOS = {
  PROGRAMADA: {label: 'Programada', css: 'programada'},
  EN_CURSO: {label: 'En curso', css: 'en-curso'},
  COMPLETADA: {label: 'Completada', css: 'completada'},
  CANCELADA: {label: 'Cancelada', css: 'cancelada'},
};

async function agendaApi(method, data = {}) {
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

function currentAgendaUser() {
  try { return JSON.parse(sessionStorage.getItem('agronomo_user')) || {}; } catch (_) { return {}; }
}

function agendaCan(permission) {
  const user = currentAgendaUser();
  return user.rol_codigo === 'admin' || (user.permissions || []).includes(permission);
}

function escapeAgendaHtml(value) {
  const element = document.createElement('span');
  element.textContent = value == null ? '' : String(value);
  return element.innerHTML;
}

function agendaEstadoBadge(estado) {
  const info = AGENDA_ESTADOS[estado] || {label: estado, css: ''};
  return `<span class="status-pill ${info.css}">${escapeAgendaHtml(info.label)}</span>`;
}

function formatAgendaDateTime(value) {
  if (!value) return '—';
  const date = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleString('es-CO', {day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'});
}

function agendaDefaultDateTime() {
  const date = new Date(Date.now() + 60 * 60000);
  date.setMinutes(Math.ceil(date.getMinutes() / 5) * 5, 0, 0);
  const pad = (value) => String(value).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

// Se consulta el registro real de DataTables (isDataTable) en vez de fiarse
// de agendaState.table: select2('destroy')/select2({...}) sobre los filtros
// dispara un 'change' sintético que puede reentrar aquí antes de que la
// variable se actualice, dejándola desincronizada del estado real de la tabla.
function destroyAgendaTable() {
  try {
    if (window.jQuery?.fn?.DataTable?.isDataTable('#agenda-table')) {
      window.jQuery('#agenda-table').DataTable().destroy();
    }
  } catch (_) {
    // Estado transitorio de DataTables (p. ej. instancia a medio construir);
    // se reconstruye igual justo después, así que un fallo aquí no es fatal.
  }
  agendaState.table = null;
}

function agendaDataTable() {
  if (!window.jQuery?.fn?.DataTable) return null;
  return window.jQuery('#agenda-table').DataTable({
    pageLength: 10,
    order: [[0, 'asc']],
    columnDefs: [{targets: 6, orderable: false, searchable: false}],
    dom: '<"dt-top"lf>rt<"dt-bottom"ip>',
    language: {
      emptyTable: 'No hay visitas programadas para el filtro actual',
      zeroRecords: 'No se encontraron programaciones',
      info: 'Mostrando _START_ a _END_ de _TOTAL_',
      infoEmpty: '0 programaciones',
      lengthMenu: 'Mostrar _MENU_',
      search: 'Buscar:',
      searchPlaceholder: 'Objetivo, finca o técnico…',
      paginate: {next: 'Siguiente', previous: 'Anterior'},
    },
  });
}

function populateAgendaSelects() {
  const fincaOptions = agendaState.fincas.map((f) => `<option value="${escapeAgendaHtml(f.id)}">${escapeAgendaHtml(f.nombre)}</option>`).join('');
  const tecnicoOptions = agendaState.tecnicos.map((t) => `<option value="${escapeAgendaHtml(t.id)}">${escapeAgendaHtml(t.name)}</option>`).join('');

  document.querySelector('#agenda-finca').innerHTML = fincaOptions;
  document.querySelector('#agenda-tecnico').innerHTML = tecnicoOptions;
  document.querySelector('#agenda-tecnico-field').hidden = !agendaState.isAdmin;

  document.querySelector('#agenda-filter-finca').innerHTML = '<option value="">Todas las fincas</option>' + fincaOptions;
  document.querySelector('#agenda-filter-tecnico-field').hidden = !agendaState.isAdmin;
  if (agendaState.isAdmin) {
    document.querySelector('#agenda-filter-tecnico').innerHTML = '<option value="">Todos los técnicos</option>' + tecnicoOptions;
  }
  initAgendaFilterSelects();
}

// select2('destroy') dispara un 'change' sintético sobre el <select> oculto
// al restaurar su valor mostrado — sin esta bandera, ese evento reentra en
// el listener de abajo y dispara renderAgendaTable() en medio de la propia
// reinicialización de los filtros.
let agendaFiltersInitializing = false;

function initAgendaFilterSelects() {
  if (!window.jQuery?.fn?.select2) return;
  agendaFiltersInitializing = true;
  [
    ['#agenda-filter-finca', 'Todas las fincas'],
    ['#agenda-filter-tecnico', 'Todos los técnicos'],
    ['#agenda-filter-estado', 'Todos los estados'],
  ].forEach(([selector, placeholder]) => {
    const $select = window.jQuery(selector);
    if ($select.hasClass('select2-hidden-accessible')) $select.select2('destroy');
    $select.select2({width: '100%', placeholder, allowClear: true, minimumResultsForSearch: 0, language: {noResults: () => 'Sin resultados'}});
  });
  agendaFiltersInitializing = false;
}

function agendaFilteredItems() {
  const finca = document.querySelector('#agenda-filter-finca').value;
  const tecnico = agendaState.isAdmin ? document.querySelector('#agenda-filter-tecnico').value : '';
  const estado = document.querySelector('#agenda-filter-estado').value;
  const from = document.querySelector('#agenda-filter-from').value;
  const to = document.querySelector('#agenda-filter-to').value;
  return agendaState.items.filter((item) => {
    if (finca && item.finca_id !== finca) return false;
    if (tecnico && item.usuario_id !== tecnico) return false;
    if (estado && item.estado !== estado) return false;
    const day = String(item.fecha_inicio).slice(0, 10);
    if (from && day < from) return false;
    if (to && day > to) return false;
    return true;
  });
}

// select2/jQuery pueden disparar 'change' de forma anidada dentro de otra
// llamada a renderAgendaTable() en curso (p. ej. mientras un select2 todavía
// se está inicializando). Reentrar ahí destruye una tabla de DataTables a
// medio construir y revienta con "nTableWrapper is null". Esta bandera evita
// tocar el DOM mientras ya hay un render en curso, y encola como mucho un
// re-render extra para cuando termine el actual.
let agendaRendering = false;
let agendaRenderPending = false;

function renderAgendaTable() {
  if (agendaRendering) { agendaRenderPending = true; return; }
  agendaRendering = true;
  destroyAgendaTable();
  const canEdit = agendaCan('agenda.editar');
  const body = document.querySelector('#agenda-table-body');
  const items = agendaFilteredItems();
  // Antes se insertaba una fila manual <td colspan> cuando no había
  // resultados y DESPUÉS se inicializaba DataTables sobre ella — DataTables
  // trataba esa fila como un registro real, buscaba una celda en la columna
  // 6 (que no existe, por el colspan) y reventaba con
  // "Cannot set properties of undefined (setting '_DT_CellIndex')". Con un
  // tbody vacío, DataTables muestra su propio emptyTable sin problema.
  body.innerHTML = items.map((item) => {
    const estadoCell = canEdit
      ? `<select class="agenda-status-select ${(AGENDA_ESTADOS[item.estado] || {}).css || ''}" data-agenda-status="${escapeAgendaHtml(item.id)}">${Object.entries(AGENDA_ESTADOS).map(([value, info]) => `<option value="${value}"${value === item.estado ? ' selected' : ''}>${info.label}</option>`).join('')}</select>`
      : agendaEstadoBadge(item.estado);
    const actions = canEdit ? `<button class="table-action" data-edit-agenda="${escapeAgendaHtml(item.id)}">Editar</button>` : '';
    return `<tr>
      <td>${escapeAgendaHtml(formatAgendaDateTime(item.fecha_inicio))}</td>
      <td>${escapeAgendaHtml(item.finca_nombre || 'Sin nombre')}</td>
      <td>${escapeAgendaHtml(item.tecnico_nombre || 'Sin técnico')}</td>
      <td>${escapeAgendaHtml(item.objetivo)}</td>
      <td>${Number(item.duracion_minutos || 60)} min</td>
      <td>${estadoCell}</td>
      <td><div class="table-actions">${actions}</div></td>
    </tr>`;
  }).join('');
  body.querySelectorAll('[data-edit-agenda]').forEach((button) => button.addEventListener('click', () => {
    const item = agendaState.items.find((row) => row.id === button.dataset.editAgenda);
    if (item) openAgendaDialog(item);
  }));
  body.querySelectorAll('[data-agenda-status]').forEach((select) => select.addEventListener('change', () => changeAgendaStatus(select.dataset.agendaStatus, select.value)));
  agendaState.table = agendaDataTable();
  agendaRendering = false;
  if (agendaRenderPending) {
    agendaRenderPending = false;
    renderAgendaTable();
  }
}

async function changeAgendaStatus(id, estado) {
  try {
    await agendaApi('cambiarEstadoAgendaVisitaWeb', {id, estado});
    const item = agendaState.items.find((row) => row.id === id);
    if (item) item.estado = estado;
    renderAgendaTable();
    notifyResult('Estado de la visita actualizado.', true);
  } catch (error) {
    notifyResult(error.message, false);
    renderAgendaTable();
  }
}

async function loadAgenda(force = false) {
  if (agendaState.loaded && !force) return;
  refreshUserPermissions();
  const messageBox = document.querySelector('#agenda-message');
  messageBox.textContent = '';
  messageBox.classList.remove('visible');
  try {
    const detail = await agendaApi('getAgendaVisitasWeb');
    agendaState.items = detail.items || [];
    agendaState.fincas = detail.fincas || [];
    agendaState.tecnicos = detail.tecnicos || [];
    agendaState.isAdmin = !!detail.is_admin;
    agendaState.loaded = true;
    populateAgendaSelects();
    renderAgendaTable();
  } catch (error) {
    messageBox.textContent = error.message;
    messageBox.classList.add('visible');
  }
}

function openAgendaDialog(item = null) {
  if (!agendaState.fincas.length) {
    notifyResult('No tienes fincas disponibles para programar visitas.', false);
    return;
  }
  const user = currentAgendaUser();
  document.querySelector('#agenda-dialog-title').textContent = item ? 'Editar programación' : 'Programar visita';
  document.querySelector('#agenda-id').value = item?.id || '';
  document.querySelector('#agenda-finca').value = item?.finca_id || agendaState.fincas[0]?.id || '';
  document.querySelector('#agenda-tecnico').value = item?.usuario_id || user.id || agendaState.tecnicos[0]?.id || '';
  document.querySelector('#agenda-fecha').value = item ? String(item.fecha_inicio).slice(0, 16).replace(' ', 'T') : agendaDefaultDateTime();
  document.querySelector('#agenda-duracion').value = item?.duracion_minutos || 60;
  document.querySelector('#agenda-objetivo').value = item?.objetivo || '';
  document.querySelector('#agenda-observacion').value = item?.observacion || '';
  document.querySelector('#agenda-estado-field').hidden = !item;
  document.querySelector('#agenda-estado').value = item?.estado || 'PROGRAMADA';
  document.querySelector('#agenda-dialog-message').textContent = '';
  if (window.jQuery?.fn?.select2) {
    [['#agenda-finca', 'Selecciona una finca…'], ['#agenda-tecnico', 'Selecciona un técnico…'], ['#agenda-estado', 'Selecciona un estado…']].forEach(([selector, placeholder]) => {
      initializeStandardSelect2(selector, {dialog:'#agenda-dialog', placeholder});
    });
  }
  document.querySelector('#agenda-dialog').showModal();
}

document.querySelector('[data-view="agenda"]').addEventListener('click', () => loadAgenda(true));
document.querySelector('#new-agenda-button').addEventListener('click', () => openAgendaDialog());
// Los tres primeros quedan envueltos por select2, que dispara sus cambios vía
// jQuery('change') — un addEventListener nativo en el <select> original no
// siempre lo recibe, así que se enlazan con jQuery para garantizarlo. Los
// campos de fecha no pasan por select2, así que usan addEventListener normal.
function onAgendaFilterChange() {
  if (agendaFiltersInitializing) return;
  renderAgendaTable();
}
if (window.jQuery) {
  window.jQuery('#agenda-filter-finca, #agenda-filter-tecnico, #agenda-filter-estado').on('change', onAgendaFilterChange);
}
document.querySelector('#agenda-filter-from')?.addEventListener('change', onAgendaFilterChange);
document.querySelector('#agenda-filter-to')?.addEventListener('change', onAgendaFilterChange);

document.querySelector('#agenda-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const fecha = document.querySelector('#agenda-fecha').value;
  try {
    await agendaApi('saveAgendaVisitaWeb', {
      id: document.querySelector('#agenda-id').value,
      finca_id: document.querySelector('#agenda-finca').value,
      usuario_id: document.querySelector('#agenda-tecnico').value,
      fecha_inicio: fecha ? `${fecha.replace('T', ' ')}:00` : '',
      duracion_minutos: document.querySelector('#agenda-duracion').value,
      objetivo: document.querySelector('#agenda-objetivo').value,
      observacion: document.querySelector('#agenda-observacion').value,
      estado: document.querySelector('#agenda-estado').value,
    });
    document.querySelector('#agenda-dialog').close();
    await loadAgenda(true);
    notifyResult('Programación guardada.', true);
  } catch (error) {
    document.querySelector('#agenda-dialog-message').textContent = error.message;
  }
});
