// Biblioteca de "Reportes en Excel" — un administrador sube archivos
// .xlsx/.xls/.xlsm ya armados (mismo concepto que el módulo "Reportes en
// Excel" de AgroSoft_hostinger); no genera reportes dinámicamente, solo
// almacena y sirve los archivos para descarga.

const reportExcelState = {items: [], loaded: false, table: null};

async function reportExcelApi(method, data = {}) {
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

function currentReportExcelUser() {
  try { return JSON.parse(sessionStorage.getItem('agronomo_user')) || {}; } catch (_) { return {}; }
}

function reportExcelCan(permission) {
  const user = currentReportExcelUser();
  return user.rol_codigo === 'admin' || (user.permissions || []).includes(permission);
}

function escapeReportExcelHtml(value) {
  const element = document.createElement('span');
  element.textContent = value == null ? '' : String(value);
  return element.innerHTML;
}

function formatReportExcelSize(bytes) {
  const value = Number(bytes || 0);
  if (!value) return '—';
  if (value < 1024 * 1024) return `${Math.round(value / 1024)} KB`;
  return `${(value / (1024 * 1024)).toFixed(1)} MB`;
}

function formatReportExcelDate(value) {
  if (!value) return '—';
  const date = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleDateString('es-CO', {day: '2-digit', month: 'short', year: 'numeric'});
}

function destroyReportExcelTable() {
  try {
    if (window.jQuery?.fn?.DataTable?.isDataTable('#reports-excel-table')) {
      window.jQuery('#reports-excel-table').DataTable().destroy();
    }
  } catch (_) {
    // Estado transitorio de DataTables; se reconstruye igual justo después.
  }
  reportExcelState.table = null;
}

function reportExcelDataTable() {
  if (!window.jQuery?.fn?.DataTable) return null;
  return window.jQuery('#reports-excel-table').DataTable({
    pageLength: 10,
    order: [[0, 'asc']],
    columnDefs: [{targets: 4, orderable: false, searchable: false}],
    dom: '<"dt-top"lf>rt<"dt-bottom"ip>',
    language: {
      emptyTable: 'No hay reportes en Excel subidos',
      zeroRecords: 'No se encontraron reportes',
      info: 'Mostrando _START_ a _END_ de _TOTAL_',
      infoEmpty: '0 reportes',
      lengthMenu: 'Mostrar _MENU_',
      search: 'Buscar:',
      searchPlaceholder: 'Nombre o descripción…',
      paginate: {next: 'Siguiente', previous: 'Anterior'},
    },
  });
}

function renderReportExcelTable() {
  destroyReportExcelTable();
  const canEdit = reportExcelCan('reportes_excel.editar');
  const body = document.querySelector('#reports-excel-table-body');
  body.innerHTML = reportExcelState.items.map((item) => {
    const actions = [
      `<a class="table-action" href="${escapeReportExcelHtml(item.archivo)}" download>Descargar</a>`,
      canEdit ? `<button class="table-action table-action-toggle${item.voided === '1' ? ' danger' : ''}" data-toggle-report-excel="${escapeReportExcelHtml(item.id)}">${item.voided === '1' ? 'Desactivar' : 'Activar'}</button>` : '',
      rowMenu([
        canEdit ? `<button type="button" class="row-menu-item" data-edit-report-excel="${escapeReportExcelHtml(item.id)}">Editar</button>` : '',
      ]),
    ].join('');
    return `<tr>
      <td><strong>${escapeReportExcelHtml(item.nombre)}</strong>${item.descripcion ? `<br><small>${escapeReportExcelHtml(item.descripcion)}</small>` : ''}</td>
      <td>${escapeReportExcelHtml((item.extension || '').toUpperCase())} · ${formatReportExcelSize(item.tamano_bytes)}</td>
      <td>${escapeReportExcelHtml(formatReportExcelDate(item.updated_at))}</td>
      <td><span class="status-pill ${item.voided === '1' ? 'active' : 'inactive'}">${item.voided === '1' ? 'Activo' : 'Inactivo'}</span></td>
      <td><div class="table-actions">${actions}</div></td>
    </tr>`;
  }).join('');
  body.querySelectorAll('[data-edit-report-excel]').forEach((button) => button.addEventListener('click', () => {
    const item = reportExcelState.items.find((row) => row.id === button.dataset.editReportExcel);
    if (item) openReportExcelDialog(item);
  }));
  body.querySelectorAll('[data-toggle-report-excel]').forEach((button) => button.addEventListener('click', () => toggleReportExcel(button.dataset.toggleReportExcel)));
  reportExcelState.table = reportExcelDataTable();
}

async function loadReportsExcel(force = false) {
  if (reportExcelState.loaded && !force) return;
  await refreshUserPermissions();
  const messageBox = document.querySelector('#reports-excel-message');
  messageBox.textContent = '';
  messageBox.classList.remove('visible');
  try {
    reportExcelState.items = await reportExcelApi('getReportesExcelWeb');
    reportExcelState.loaded = true;
    renderReportExcelTable();
  } catch (error) {
    messageBox.textContent = error.message;
    messageBox.classList.add('visible');
  }
}

async function toggleReportExcel(id) {
  const item = reportExcelState.items.find((row) => row.id === id);
  if (!item) return;
  const result = await Swal.fire({
    title: `¿${item.voided === '1' ? 'Desactivar' : 'Activar'} este reporte?`,
    text: item.voided === '1' ? 'Dejará de estar visible para el equipo.' : 'Volverá a estar disponible para descargar.',
    icon: 'warning', showCancelButton: true,
    confirmButtonText: 'Sí, continuar', cancelButtonText: 'Cancelar',
    confirmButtonColor: '#173f32',
  });
  if (!result.value) return;
  try {
    await reportExcelApi('toggleReporteExcelWeb', {id});
    await loadReportsExcel(true);
  } catch (error) {
    Swal.fire({title: 'No fue posible', text: error.message, icon: 'error'});
  }
}

function readFileAsBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result).split(',')[1] || '');
    reader.onerror = () => reject(new Error('No fue posible leer el archivo seleccionado.'));
    reader.readAsDataURL(file);
  });
}

function openReportExcelDialog(item = null) {
  document.querySelector('#report-excel-dialog-title').textContent = item ? 'Editar reporte' : 'Subir reporte';
  document.querySelector('#report-excel-id').value = item?.id || '';
  document.querySelector('#report-excel-nombre').value = item?.nombre || '';
  document.querySelector('#report-excel-descripcion').value = item?.descripcion || '';
  document.querySelector('#report-excel-archivo').value = '';
  document.querySelector('#report-excel-archivo').required = !item;
  document.querySelector('#report-excel-archivo-label').textContent = item
    ? 'Reemplazar archivo (opcional)'
    : 'Archivo (.xlsx, .xls, .xlsm) *';
  document.querySelector('#report-excel-message').textContent = '';
  document.querySelector('#report-excel-dialog').showModal();
}

document.querySelector('[data-view="reports-excel"]').addEventListener('click', () => loadReportsExcel(true));
document.querySelector('#new-report-excel-button').addEventListener('click', () => openReportExcelDialog());

document.querySelector('#report-excel-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const messageBox = document.querySelector('#report-excel-message');
  messageBox.textContent = '';
  const fileInput = document.querySelector('#report-excel-archivo');
  const file = fileInput.files[0] || null;
  if (file && file.size > 5 * 1024 * 1024) {
    messageBox.textContent = 'El archivo no puede superar 5 MB.';
    return;
  }
  try {
    const payload = {
      id: document.querySelector('#report-excel-id').value,
      nombre: document.querySelector('#report-excel-nombre').value,
      descripcion: document.querySelector('#report-excel-descripcion').value,
    };
    if (file) {
      payload.archivo_base64 = await readFileAsBase64(file);
      payload.archivo_nombre = file.name;
    }
    await reportExcelApi('saveReporteExcelWeb', payload);
    document.querySelector('#report-excel-dialog').close();
    await loadReportsExcel(true);
    notifyResult('Reporte guardado.', true);
  } catch (error) {
    messageBox.textContent = error.message;
  }
});
