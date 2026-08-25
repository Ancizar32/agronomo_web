const recommendationState = { items: [], loaded: false, table: null };
const recommendationsTableBody = document.querySelector('#recommendations-table-body');

async function recommendationApi(method, data = {}) {
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

function currentRecommendationUser() {
  try { return JSON.parse(sessionStorage.getItem('agronomo_user')) || {}; } catch (_) { return {}; }
}

function recommendationCan(permission) {
  const user = currentRecommendationUser();
  return user.rol_codigo === 'admin' || (user.permissions || []).includes(permission);
}

function escapeRecommendationHtml(value) {
  const element = document.createElement('span');
  element.textContent = value == null ? '' : String(value);
  return element.innerHTML;
}

function destroyRecommendationTable() {
  if (!recommendationState.table) return;
  recommendationState.table.destroy();
  recommendationState.table = null;
}

function recommendationDataTable(table, actionColumn) {
  if (!window.jQuery?.fn?.DataTable) return null;
  return window.jQuery(table).DataTable({
    pageLength: 10,
    order: [],
    columnDefs: [{targets: actionColumn, orderable: false, searchable: false}],
    dom: '<"dt-top"lf>rt<"dt-bottom"ip>',
    language: {
      emptyTable: 'No hay recomendaciones registradas', zeroRecords: 'No se encontraron resultados',
      info: 'Mostrando _START_ a _END_ de _TOTAL_', infoEmpty: '0 registros', lengthMenu: 'Mostrar _MENU_',
      search: 'Buscar:', searchPlaceholder: 'Filtrar recomendaciones…',
      paginate: {next: 'Siguiente', previous: 'Anterior'},
    },
  });
}

async function loadRecommendations(force = false) {
  if (recommendationState.loaded && !force) return;
  await refreshUserPermissions();
  try {
    recommendationState.items = await recommendationApi('getRecomendacionesWeb');
    recommendationState.loaded = true;
    renderRecommendations();
  } catch (error) {
    recommendationsTableBody.innerHTML = `<tr class="empty-row"><td colspan="3">${escapeRecommendationHtml(error.message)}</td></tr>`;
  }
}

function renderRecommendations() {
  destroyRecommendationTable();
  const canEdit = recommendationCan('recomendaciones.editar');
  recommendationsTableBody.innerHTML = recommendationState.items.map((item) => {
    const preview = item.descripcion.length > 140 ? `${item.descripcion.slice(0, 140)}…` : item.descripcion;
    const actions = [
      canEdit ? `<button class="table-action" data-edit-recommendation="${escapeRecommendationHtml(item.id)}">Editar</button>` : '',
      canEdit ? `<button class="table-action table-action-toggle${item.voided === '1' ? ' danger' : ''}" data-toggle-recommendation="${escapeRecommendationHtml(item.id)}">${item.voided === '1' ? 'Inactivar' : 'Activar'}</button>` : '',
    ].join('');
    return `<tr>
      <td>${escapeRecommendationHtml(preview)}</td>
      <td><span class="status-pill ${item.voided === '1' ? 'active' : 'inactive'}">${item.voided === '1' ? 'Activa' : 'Inactiva'}</span></td>
      <td><div class="table-actions">${actions}</div></td>
    </tr>`;
  }).join('');
  recommendationsTableBody.querySelectorAll('[data-edit-recommendation]').forEach((button) => button.addEventListener('click', () => {
    const item = recommendationState.items.find((row) => row.id === button.dataset.editRecommendation);
    if (item) openRecommendationDialog(item);
  }));
  recommendationsTableBody.querySelectorAll('[data-toggle-recommendation]').forEach((button) => button.addEventListener('click', () => {
    toggleRecommendation(button.dataset.toggleRecommendation);
  }));
  recommendationState.table = recommendationDataTable('#recommendations-table', 2);
}

function openRecommendationDialog(item = null) {
  document.querySelector('#recommendation-dialog-title').textContent = item ? 'Editar recomendación' : 'Nueva recomendación';
  document.querySelector('#recommendation-id').value = item?.id || '';
  document.querySelector('#recommendation-description').value = item?.descripcion || '';
  document.querySelector('#recommendation-message').textContent = '';
  document.querySelector('#recommendation-dialog').showModal();
}

async function toggleRecommendation(id) {
  const item = recommendationState.items.find((row) => row.id === id);
  if (!item) return;
  const result = await Swal.fire({
    title: `¿${item.voided === '1' ? 'Inactivar' : 'Activar'} recomendación?`,
    text: 'El catálogo se actualizará para la app móvil.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Sí, continuar',
    cancelButtonText: 'Cancelar',
    confirmButtonColor: '#173f32',
  });
  if (!result.value) return;
  try {
    await recommendationApi('toggleRecomendacionWeb', {id});
    await loadRecommendations(true);
  } catch (error) {
    Swal.fire({title: 'No fue posible', text: error.message, icon: 'error'});
  }
}

document.querySelector('[data-view="recommendations"]').addEventListener('click', () => loadRecommendations(true));
document.querySelector('#new-recommendation-button').addEventListener('click', () => openRecommendationDialog());

document.querySelector('#recommendation-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const user = currentRecommendationUser();
  try {
    await recommendationApi('saveRecomendacionWeb', {
      id: document.querySelector('#recommendation-id').value,
      descripcion: document.querySelector('#recommendation-description').value,
      created_by: user.id || '',
    });
    document.querySelector('#recommendation-dialog').close();
    await loadRecommendations(true);
    notifyResult('Recomendación guardada.', true);
  } catch (error) {
    notifyResult(error.message, false);
  }
});
