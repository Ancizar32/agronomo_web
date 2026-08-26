// Build Query — constructor de reportes SQL reutilizables, con generación
// de enlaces para Excel (Power Query) y una API JSON con clientes propios.
// Núcleo reducido del módulo build.query de AgroSoft_dev2 (sin asistente de
// IA para SQL ni los widgets de dashboard móvil de ese original).

const buildQueryState = {queries: [], clients: [], loaded: false, queriesTable: null, clientsTable: null, currentClientId: null};

async function buildQueryApi(method, data = {}) {
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

function currentBuildQueryUser() {
  try { return JSON.parse(sessionStorage.getItem('agronomo_user')) || {}; } catch (_) { return {}; }
}

function buildQueryCan(permission) {
  const user = currentBuildQueryUser();
  return user.rol_codigo === 'admin' || (user.permissions || []).includes(permission);
}

function escapeBuildQueryHtml(value) {
  const element = document.createElement('span');
  element.textContent = value == null ? '' : String(value);
  return element.innerHTML;
}

function formatBuildQueryDate(value) {
  if (!value) return '—';
  const date = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleDateString('es-CO', {day: '2-digit', month: 'short', year: 'numeric'});
}

function buildQueryParamNames(raw) {
  return String(raw || '').split(',').map((value) => value.trim()).filter(Boolean);
}

// ---------- Pestañas ----------
document.querySelectorAll('#build-query-tabs .admin-tab').forEach((tab) => tab.addEventListener('click', () => {
  document.querySelectorAll('#build-query-tabs .admin-tab').forEach((item) => item.classList.remove('active'));
  tab.classList.add('active');
  document.querySelector('#query-list-panel').hidden = tab.dataset.queryTab !== 'queries';
  document.querySelector('#clients-list-panel').hidden = tab.dataset.queryTab !== 'clients';
  if (tab.dataset.queryTab === 'clients') loadApiClients();
}));

// ---------- Tabla de consultas ----------
function destroyReportQueriesTable() {
  try {
    if (window.jQuery?.fn?.DataTable?.isDataTable('#report-queries-table')) {
      window.jQuery('#report-queries-table').DataTable().destroy();
    }
  } catch (_) {
    // Estado transitorio de DataTables; se reconstruye igual justo después.
  }
  buildQueryState.queriesTable = null;
}

function reportQueriesDataTable() {
  if (!window.jQuery?.fn?.DataTable) return null;
  return window.jQuery('#report-queries-table').DataTable({
    pageLength: 10,
    order: [[0, 'asc']],
    columnDefs: [{targets: 4, orderable: false, searchable: false}],
    dom: '<"dt-top"lf>rt<"dt-bottom"ip>',
    language: {
      emptyTable: 'No hay consultas guardadas',
      zeroRecords: 'No se encontraron consultas',
      info: 'Mostrando _START_ a _END_ de _TOTAL_',
      infoEmpty: '0 consultas',
      lengthMenu: 'Mostrar _MENU_',
      search: 'Buscar:',
      searchPlaceholder: 'Descripción…',
      paginate: {next: 'Siguiente', previous: 'Anterior'},
    },
  });
}

function renderReportQueriesTable() {
  destroyReportQueriesTable();
  const canEdit = buildQueryCan('build_query.editar');
  const body = document.querySelector('#report-queries-table-body');
  body.innerHTML = buildQueryState.queries.map((item) => {
    const parametros = buildQueryParamNames(item.parametros);
    const actions = [
      `<button class="table-action" data-link-query="${escapeBuildQueryHtml(item.id)}">Generar link</button>`,
      canEdit ? `<button class="table-action table-action-toggle${item.voided === '1' ? ' danger' : ''}" data-toggle-query="${escapeBuildQueryHtml(item.id)}">${item.voided === '1' ? 'Desactivar' : 'Activar'}</button>` : '',
      rowMenu([
        canEdit ? `<button type="button" class="row-menu-item" data-edit-query="${escapeBuildQueryHtml(item.id)}">Editar</button>` : '',
      ]),
    ].join('');
    return `<tr>
      <td><strong>${escapeBuildQueryHtml(item.descripcion)}</strong><br><small>${escapeBuildQueryHtml(formatBuildQueryDate(item.updated_at))} · ${escapeBuildQueryHtml(item.creado_por || '')}</small></td>
      <td>${parametros.length ? escapeBuildQueryHtml(parametros.join(', ')) : '<span class="status-pill inactive">Sin parámetros</span>'}</td>
      <td>${item.api_habilitada === '1' ? `<span class="status-pill active">Habilitada · ${Number(item.clientes_autorizados || 0)} clientes</span>` : '<span class="status-pill inactive">Solo Excel</span>'}</td>
      <td><span class="status-pill ${item.voided === '1' ? 'active' : 'inactive'}">${item.voided === '1' ? 'Activa' : 'Inactiva'}</span></td>
      <td><div class="table-actions">${actions}</div></td>
    </tr>`;
  }).join('');
  body.querySelectorAll('[data-link-query]').forEach((button) => button.addEventListener('click', () => openReportQueryLinkDialog(button.dataset.linkQuery)));
  body.querySelectorAll('[data-edit-query]').forEach((button) => button.addEventListener('click', () => {
    const item = buildQueryState.queries.find((row) => row.id === button.dataset.editQuery);
    if (item) openReportQueryDialog(item);
  }));
  body.querySelectorAll('[data-toggle-query]').forEach((button) => button.addEventListener('click', () => toggleReportQuery(button.dataset.toggleQuery)));
  buildQueryState.queriesTable = reportQueriesDataTable();
}

async function loadReportQueries(force = false) {
  if (buildQueryState.loaded && !force) return;
  refreshUserPermissions();
  const messageBox = document.querySelector('#build-query-message');
  messageBox.textContent = '';
  messageBox.classList.remove('visible');
  try {
    buildQueryState.queries = await buildQueryApi('getReportQueriesWeb');
    buildQueryState.loaded = true;
    renderReportQueriesTable();
  } catch (error) {
    messageBox.textContent = error.message;
    messageBox.classList.add('visible');
  }
}

async function toggleReportQuery(id) {
  const item = buildQueryState.queries.find((row) => row.id === id);
  if (!item) return;
  const result = await Swal.fire({
    title: `¿${item.voided === '1' ? 'Desactivar' : 'Activar'} esta consulta?`,
    text: item.voided === '1' ? 'Dejará de estar disponible para generar enlaces.' : 'Volverá a estar disponible.',
    icon: 'warning', showCancelButton: true,
    confirmButtonText: 'Sí, continuar', cancelButtonText: 'Cancelar',
    confirmButtonColor: '#173f32',
  });
  if (!result.value) return;
  try {
    await buildQueryApi('toggleReportQueryWeb', {id});
    await loadReportQueries(true);
  } catch (error) {
    Swal.fire({title: 'No fue posible', text: error.message, icon: 'error'});
  }
}

// ---------- Diálogo de consulta ----------
function openReportQueryDialog(item = null) {
  document.querySelector('#report-query-dialog-title').textContent = item ? 'Editar consulta' : 'Nueva consulta';
  document.querySelector('#report-query-id').value = item?.id || '';
  document.querySelector('#report-query-descripcion').value = item?.descripcion || '';
  document.querySelector('#report-query-consulta').value = item?.consulta || '';
  document.querySelector('#report-query-parametros').value = item?.parametros || '';
  document.querySelector('#report-query-api-habilitada').checked = item?.api_habilitada === '1';
  document.querySelector('#report-query-api-descripcion').value = item?.api_descripcion || '';
  document.querySelector('#report-query-api-max-filas').value = item?.api_max_filas || 1000;
  document.querySelector('#report-query-api-fields').hidden = item?.api_habilitada !== '1';
  document.querySelector('#report-query-preview').hidden = true;
  document.querySelector('#report-query-preview').innerHTML = '';
  document.querySelector('#report-query-message').textContent = '';
  document.querySelector('#report-query-dialog').showModal();
}

document.querySelector('#new-report-query-button').addEventListener('click', () => openReportQueryDialog());
document.querySelector('[data-view="build-query"]').addEventListener('click', () => loadReportQueries(true));

document.querySelector('#report-query-api-habilitada').addEventListener('change', (event) => {
  document.querySelector('#report-query-api-fields').hidden = !event.target.checked;
});

document.querySelector('#report-query-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const messageBox = document.querySelector('#report-query-message');
  messageBox.textContent = '';
  try {
    await buildQueryApi('saveReportQueryWeb', {
      id: document.querySelector('#report-query-id').value,
      descripcion: document.querySelector('#report-query-descripcion').value,
      consulta: document.querySelector('#report-query-consulta').value,
      parametros: document.querySelector('#report-query-parametros').value,
      api_habilitada: document.querySelector('#report-query-api-habilitada').checked ? '1' : '0',
      api_descripcion: document.querySelector('#report-query-api-descripcion').value,
      api_max_filas: document.querySelector('#report-query-api-max-filas').value,
    });
    document.querySelector('#report-query-dialog').close();
    await loadReportQueries(true);
    notifyResult('Consulta guardada.', true);
  } catch (error) {
    messageBox.textContent = error.message;
  }
});

// ---------- Vista previa ----------
document.querySelector('#preview-report-query-button').addEventListener('click', async () => {
  const consulta = document.querySelector('#report-query-consulta').value;
  const parametros = buildQueryParamNames(document.querySelector('#report-query-parametros').value);
  const box = document.querySelector('#report-query-preview');
  document.querySelector('#report-query-message').textContent = '';
  const valores = {};
  for (const nombre of parametros) {
    // window.prompt() lo puede bloquear el navegador (o queda oculto detrás
    // del <dialog> abierto, igual que pasaba con los toasts) — se usa un
    // Swal.fire con input de texto en su lugar.
    const {value: valor, isDismissed} = await Swal.fire({
      title: `Valor de prueba para "${nombre}"`,
      text: 'Solo se usa para esta vista previa.',
      input: 'text',
      showCancelButton: true,
      confirmButtonText: 'Aceptar',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#173f32',
    });
    if (isDismissed) return;
    valores[nombre] = valor || '';
  }
  box.hidden = false;
  box.innerHTML = '<div class="build-query-preview-empty">Consultando…</div>';
  try {
    const rows = await buildQueryApi('previewReportQueryWeb', {consulta, valores});
    if (!rows.length) {
      box.innerHTML = '<div class="build-query-preview-empty">La consulta no devolvió filas.</div>';
      return;
    }
    const columnas = Object.keys(rows[0]);
    box.innerHTML = `<table><thead><tr>${columnas.map((c) => `<th>${escapeBuildQueryHtml(c)}</th>`).join('')}</tr></thead><tbody>${rows.map((row) => `<tr>${columnas.map((c) => `<td>${escapeBuildQueryHtml(row[c])}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
  } catch (error) {
    box.innerHTML = `<div class="build-query-preview-empty">${escapeBuildQueryHtml(error.message)}</div>`;
  }
});

// ---------- Navegador de tablas/columnas ----------
let schemaSearchTimer = null;

document.querySelector('#open-schema-browser-button').addEventListener('click', () => {
  document.querySelector('#schema-search-input').value = '';
  document.querySelector('#schema-columns-list').innerHTML = '<div class="build-query-preview-empty">Selecciona una tabla…</div>';
  loadSchemaTables();
  document.querySelector('#schema-browser-dialog').showModal();
});

// Copia un nombre de tabla o columna al portapapeles, para pegarlo directo
// en el editor de SQL. navigator.clipboard requiere contexto seguro; si el
// navegador lo bloquea (permiso denegado, http sin TLS, etc.) cae a un
// textarea temporal + execCommand como respaldo.
// A diferencia de notifyResult() (un modal que hay que cerrar), copiar un
// nombre es una confirmación menor: un toast que se cierra solo, sin pedirle
// al usuario que lo descarte, para no interrumpir mientras arma el SQL.
// Un Swal.fire en modo toast, aunque se ancle al <dialog> abierto (parche
// de app.js), no queda pintado por encima de él — el fondo oscuro del modal
// lo tapa/atenúa igual. En vez de eso, se inserta el toast como hijo
// directo del propio <dialog> abierto: al ser parte de su misma capa
// superior del navegador, sí queda visible encima de todo lo demás.
function showBuildQueryToast(text) {
  // El navegador de tablas se abre anidado dentro de "Nueva consulta", así
  // que puede haber dos <dialog> abiertos a la vez: hay que anclar el toast
  // al último (el que realmente está encima), no al primero del DOM.
  const openDialogs = document.querySelectorAll('dialog[open]');
  const host = openDialogs[openDialogs.length - 1] || document.body;
  const toast = document.createElement('div');
  toast.className = 'build-query-toast';
  toast.textContent = text;
  host.appendChild(toast);
  requestAnimationFrame(() => toast.classList.add('visible'));
  window.setTimeout(() => {
    toast.classList.remove('visible');
    window.setTimeout(() => toast.remove(), 200);
  }, 2000);
}

async function copyBuildQueryText(text) {
  try {
    await navigator.clipboard.writeText(text);
  } catch (_) {
    const input = document.createElement('textarea');
    input.value = text;
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.select();
    document.execCommand('copy');
    document.body.removeChild(input);
  }
  showBuildQueryToast(`"${text}" copiado al portapapeles.`);
}

function wireSchemaCopyButtons(list) {
  list.querySelectorAll('[data-copy-value]').forEach((button) => button.addEventListener('click', (event) => {
    event.stopPropagation();
    copyBuildQueryText(button.dataset.copyValue);
  }));
}

async function loadSchemaTables(busqueda = '') {
  const list = document.querySelector('#schema-tables-list');
  list.innerHTML = '<div class="build-query-preview-empty">Consultando…</div>';
  try {
    const tablas = await buildQueryApi('getSchemaTablesWeb', {busqueda});
    if (!tablas.length) {
      list.innerHTML = '<div class="build-query-preview-empty">Sin resultados.</div>';
      return;
    }
    list.innerHTML = tablas.map((t) => `<div class="schema-row">
      <button type="button" class="schema-select" data-schema-table="${escapeBuildQueryHtml(t.tabla)}">${escapeBuildQueryHtml(t.tabla)}</button>
      <button type="button" class="schema-copy-icon" data-copy-value="${escapeBuildQueryHtml(t.tabla)}" title="Copiar nombre de la tabla"><svg class="icon"><use href="#icon-copy"></use></svg></button>
    </div>`).join('');
    list.querySelectorAll('[data-schema-table]').forEach((button) => button.addEventListener('click', () => {
      list.querySelectorAll('.schema-select').forEach((b) => b.classList.remove('active'));
      button.classList.add('active');
      loadSchemaColumns(button.dataset.schemaTable);
    }));
    wireSchemaCopyButtons(list);
  } catch (error) {
    list.innerHTML = `<div class="build-query-preview-empty">${escapeBuildQueryHtml(error.message)}</div>`;
  }
}

async function loadSchemaColumns(tabla) {
  const list = document.querySelector('#schema-columns-list');
  list.innerHTML = '<div class="build-query-preview-empty">Consultando…</div>';
  try {
    const columnas = await buildQueryApi('getSchemaColumnsWeb', {tabla});
    list.innerHTML = columnas.map((c) => `<div class="schema-col-row">
      <button type="button" class="schema-col-name" data-copy-value="${escapeBuildQueryHtml(c.columna)}" title="Copiar nombre de la columna"><svg class="icon"><use href="#icon-copy"></use></svg>${escapeBuildQueryHtml(c.columna)}</button>
      <span class="schema-col-type">${escapeBuildQueryHtml(c.tipo)}</span>
    </div>`).join('');
    wireSchemaCopyButtons(list);
  } catch (error) {
    list.innerHTML = `<div class="build-query-preview-empty">${escapeBuildQueryHtml(error.message)}</div>`;
  }
}

document.querySelector('#schema-search-input').addEventListener('input', (event) => {
  clearTimeout(schemaSearchTimer);
  const valor = event.target.value.trim();
  schemaSearchTimer = setTimeout(() => loadSchemaTables(valor), 250);
});

// ---------- Generar link ----------
// El formato ["nombre";""] no se codifica: es la sintaxis que el editor de
// parámetros de Power Query reconoce al pegar la URL en "Desde la Web",
// por eso el link se arma como texto plano y no con URLSearchParams (que
// codificaría los corchetes/comillas y rompería ese reconocimiento).
function buildExcelReportUrl(id, parametros) {
  const base = new URL('reports/excel.php', window.location.href).toString();
  const partes = [`id=${encodeURIComponent(id)}`, ...parametros.map((nombre) => `${nombre}=["${nombre}";""]`)];
  return `${base}?${partes.join('&')}`;
}

function buildApiReportUrl(id, parametros) {
  const base = new URL('reports/api.php', window.location.href).toString();
  const partes = [`token=${encodeURIComponent(id)}`, ...parametros.map((nombre) => `${nombre}=`)];
  return `${base}?${partes.join('&')}`;
}

function openReportQueryLinkDialog(id) {
  const item = buildQueryState.queries.find((row) => row.id === id);
  if (!item) return;
  const parametros = buildQueryParamNames(item.parametros);
  document.querySelector('#report-query-link-title').textContent = item.descripcion;
  document.querySelector('#report-query-link-excel').value = buildExcelReportUrl(item.id, parametros);
  const apiWrap = document.querySelector('#report-query-link-api-wrap');
  if (item.api_habilitada === '1') {
    document.querySelector('#report-query-link-api').value = buildApiReportUrl(item.id, parametros);
    apiWrap.hidden = false;
  } else {
    apiWrap.hidden = true;
  }
  document.querySelector('#report-query-link-dialog').showModal();
}

document.querySelectorAll('[data-copy-target]').forEach((button) => button.addEventListener('click', async () => {
  const field = document.querySelector(`#${button.dataset.copyTarget}`);
  field.select();
  try {
    await navigator.clipboard.writeText(field.value);
    notifyResult('Enlace copiado.', true);
  } catch (_) {
    document.execCommand('copy');
  }
}));

// ---------- Clientes de la API ----------
function destroyApiClientsTable() {
  try {
    if (window.jQuery?.fn?.DataTable?.isDataTable('#api-clients-table')) {
      window.jQuery('#api-clients-table').DataTable().destroy();
    }
  } catch (_) {
    // Estado transitorio de DataTables; se reconstruye igual justo después.
  }
  buildQueryState.clientsTable = null;
}

function apiClientsDataTable() {
  if (!window.jQuery?.fn?.DataTable) return null;
  return window.jQuery('#api-clients-table').DataTable({
    pageLength: 10,
    order: [[0, 'asc']],
    columnDefs: [{targets: 4, orderable: false, searchable: false}],
    dom: '<"dt-top"lf>rt<"dt-bottom"ip>',
    language: {
      emptyTable: 'No hay clientes de API',
      zeroRecords: 'No se encontraron clientes',
      info: 'Mostrando _START_ a _END_ de _TOTAL_',
      infoEmpty: '0 clientes',
      lengthMenu: 'Mostrar _MENU_',
      search: 'Buscar:',
      searchPlaceholder: 'Nombre…',
      paginate: {next: 'Siguiente', previous: 'Anterior'},
    },
  });
}

function renderApiClientsTable() {
  destroyApiClientsTable();
  const canEdit = buildQueryCan('build_query.editar');
  const body = document.querySelector('#api-clients-table-body');
  body.innerHTML = buildQueryState.clients.map((item) => {
    const actions = [
      canEdit ? `<button class="table-action table-action-toggle${item.voided === '1' ? ' danger' : ''}" data-toggle-client="${escapeBuildQueryHtml(item.id)}">${item.voided === '1' ? 'Desactivar' : 'Activar'}</button>` : '',
      rowMenu([
        canEdit ? `<button type="button" class="row-menu-item" data-edit-client="${escapeBuildQueryHtml(item.id)}">Editar</button>` : '',
        canEdit ? `<button type="button" class="row-menu-item" data-permissions-client="${escapeBuildQueryHtml(item.id)}">Permisos</button>` : '',
      ]),
    ].join('');
    return `<tr>
      <td><strong>${escapeBuildQueryHtml(item.nombre)}</strong>${item.notas ? `<br><small>${escapeBuildQueryHtml(item.notas)}</small>` : ''}</td>
      <td><code>${escapeBuildQueryHtml(item.client_key)}</code></td>
      <td>${Number(item.reportes_autorizados || 0)}</td>
      <td><span class="status-pill ${item.voided === '1' ? 'active' : 'inactive'}">${item.voided === '1' ? 'Activo' : 'Inactivo'}</span></td>
      <td><div class="table-actions">${actions}</div></td>
    </tr>`;
  }).join('');
  body.querySelectorAll('[data-edit-client]').forEach((button) => button.addEventListener('click', () => {
    const item = buildQueryState.clients.find((row) => row.id === button.dataset.editClient);
    if (item) openApiClientDialog(item);
  }));
  body.querySelectorAll('[data-permissions-client]').forEach((button) => button.addEventListener('click', () => openApiClientReportsDialog(button.dataset.permissionsClient)));
  body.querySelectorAll('[data-toggle-client]').forEach((button) => button.addEventListener('click', () => toggleApiClient(button.dataset.toggleClient)));
  buildQueryState.clientsTable = apiClientsDataTable();
}

async function loadApiClients(force = false) {
  if (buildQueryState.clients.length && !force) {
    renderApiClientsTable();
    return;
  }
  try {
    buildQueryState.clients = await buildQueryApi('getApiClientesWeb');
    renderApiClientsTable();
  } catch (error) {
    notifyResult(error.message, false);
  }
}

async function toggleApiClient(id) {
  const item = buildQueryState.clients.find((row) => row.id === id);
  if (!item) return;
  const result = await Swal.fire({
    title: `¿${item.voided === '1' ? 'Desactivar' : 'Activar'} este cliente?`,
    text: item.voided === '1' ? 'Dejará de poder consumir la API.' : 'Volverá a poder consumir la API.',
    icon: 'warning', showCancelButton: true,
    confirmButtonText: 'Sí, continuar', cancelButtonText: 'Cancelar',
    confirmButtonColor: '#173f32',
  });
  if (!result.value) return;
  try {
    await buildQueryApi('toggleApiClienteWeb', {id});
    await loadApiClients(true);
  } catch (error) {
    Swal.fire({title: 'No fue posible', text: error.message, icon: 'error'});
  }
}

function openApiClientDialog(item = null) {
  document.querySelector('#api-client-dialog-title').textContent = item ? 'Editar cliente' : 'Nuevo cliente API';
  document.querySelector('#api-client-id').value = item?.id || '';
  document.querySelector('#api-client-nombre').value = item?.nombre || '';
  document.querySelector('#api-client-notas').value = item?.notas || '';
  document.querySelector('#api-client-secret-box').hidden = true;
  document.querySelector('#api-client-secret-box').innerHTML = '';
  document.querySelector('#api-client-message').textContent = '';
  document.querySelector('#api-client-dialog').showModal();
}

document.querySelector('#new-api-client-button').addEventListener('click', () => openApiClientDialog());

document.querySelector('#api-client-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const messageBox = document.querySelector('#api-client-message');
  messageBox.textContent = '';
  try {
    const detail = await buildQueryApi('saveApiClienteWeb', {
      id: document.querySelector('#api-client-id').value,
      nombre: document.querySelector('#api-client-nombre').value,
      notas: document.querySelector('#api-client-notas').value,
    });
    await loadApiClients(true);
    if (detail.client_secret) {
      const box = document.querySelector('#api-client-secret-box');
      box.hidden = false;
      box.innerHTML = `<span>Client key</span><strong>${escapeBuildQueryHtml(detail.client_key)}</strong><span>Client secret (solo se muestra una vez)</span><strong>${escapeBuildQueryHtml(detail.client_secret)}</strong><p>Copia y guarda estas credenciales ahora — no podrás volver a verlas.</p>`;
      document.querySelector('#api-client-dialog-title').textContent = 'Cliente creado';
    } else {
      document.querySelector('#api-client-dialog').close();
      notifyResult('Cliente actualizado.', true);
    }
  } catch (error) {
    messageBox.textContent = error.message;
  }
});

// ---------- Permisos de reportes por cliente ----------
async function openApiClientReportsDialog(clienteId) {
  buildQueryState.currentClientId = clienteId;
  const client = buildQueryState.clients.find((row) => row.id === clienteId);
  document.querySelector('#api-client-reports-title').textContent = client ? `Reportes autorizados · ${client.nombre}` : 'Reportes autorizados';
  const list = document.querySelector('#api-client-reports-list');
  list.innerHTML = '<div class="build-query-preview-empty">Consultando…</div>';
  document.querySelector('#api-client-reports-message').textContent = '';
  document.querySelector('#api-client-reports-dialog').showModal();
  try {
    if (!buildQueryState.loaded) await loadReportQueries(true);
    const asignados = await buildQueryApi('getApiClienteReportesWeb', {cliente_id: clienteId});
    const habilitados = buildQueryState.queries.filter((item) => item.api_habilitada === '1');
    if (!habilitados.length) {
      list.innerHTML = '<div class="build-query-preview-empty">No hay consultas habilitadas para la API todavía.</div>';
      return;
    }
    list.innerHTML = habilitados.map((item) => `<label class="schema-col-row schema-checklist-row" style="cursor:pointer"><span><input type="checkbox" value="${escapeBuildQueryHtml(item.id)}"${asignados.includes(item.id) ? ' checked' : ''}> ${escapeBuildQueryHtml(item.descripcion)}</span></label>`).join('');
  } catch (error) {
    list.innerHTML = `<div class="build-query-preview-empty">${escapeBuildQueryHtml(error.message)}</div>`;
  }
}

document.querySelector('#save-api-client-reports-button').addEventListener('click', async () => {
  const messageBox = document.querySelector('#api-client-reports-message');
  messageBox.textContent = '';
  const seleccionados = Array.from(document.querySelectorAll('#api-client-reports-list input[type=checkbox]:checked')).map((input) => input.value);
  try {
    await buildQueryApi('saveApiClienteReportesWeb', {cliente_id: buildQueryState.currentClientId, reporte_ids: seleccionados});
    document.querySelector('#api-client-reports-dialog').close();
    await loadApiClients(true);
    notifyResult('Permisos actualizados.', true);
  } catch (error) {
    messageBox.textContent = error.message;
  }
});
