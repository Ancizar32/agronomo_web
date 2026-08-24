const cropState = {crops: [], labores: [], loaded: false, catalogTable: null, categoriesCatalogTable: null, cropTable: null, categoryTable: null, allTable: null};
const categoryState = {categories: [], loaded: false};
const cropMessage = document.querySelector('#crop-message');
const cropsCatalogBody = document.querySelector('#crops-catalog-table-body');
const categoriesCatalogBody = document.querySelector('#categories-catalog-table-body');
const cropLaborsBody = document.querySelector('#crop-labors-table-body');
const categoryLaborsBody = document.querySelector('#category-labors-table-body');
const allLaborsBody = document.querySelector('#all-labors-table-body');

async function cropApi(method, data = {}) {
  const response = await fetch('api/index.php', {method: 'POST', cache: 'no-store', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({controller: 'agronomo', method, data})});
  const raw = await response.text(); let payload;
  try { payload = JSON.parse(raw); } catch (_) { throw new Error(`Respuesta inválida del servidor (HTTP ${response.status}).`); }
  if (response.status === 401) { handleSessionExpired(); throw new Error(payload.message || 'Tu sesión expiró. Inicia sesión nuevamente.'); }
  if (!response.ok || payload.success !== true) throw new Error(payload.message || 'No fue posible completar la operación.');
  return payload.detail;
}

function currentCropUser() { try { return JSON.parse(sessionStorage.getItem('agronomo_user')) || {}; } catch (_) { return {}; } }
function cropCan(permission) { const user = currentCropUser(); return user.rol_codigo === 'admin' || (user.permissions || []).includes(permission); }
function escapeCropHtml(value) { const element = document.createElement('span'); element.textContent = value == null ? '' : String(value); return element.innerHTML; }
function showCropMessage(text, success = false) { notifyResult(text, success); }
function destroyCropTable(key) { if (!cropState[key]) return; cropState[key].destroy(); cropState[key] = null; }

function createLaborTable(selector, key, placeholder, actionColumn = 5, emptyLabel = 'labores') {
  if (!window.jQuery?.fn?.DataTable) return null;
  cropState[key] = window.jQuery(selector).DataTable({
    pageLength: 10, order: [[0, 'asc'], [1, 'asc']], autoWidth: false,
    columnDefs: [{targets: actionColumn, orderable: false, searchable: false}], dom: '<"dt-top"lf>rt<"dt-bottom"ip>',
    language: {emptyTable: `No hay ${emptyLabel} registrados`, zeroRecords: 'No se encontraron resultados', info: 'Mostrando _START_ a _END_ de _TOTAL_', infoEmpty: '0 registros', lengthMenu: 'Mostrar _MENU_', search: 'Buscar:', searchPlaceholder: placeholder, paginate: {next: 'Siguiente', previous: 'Anterior'}},
  });
  return cropState[key];
}

async function loadCrops(force = false) {
  if (cropState.loaded && !force) return;
  await refreshUserPermissions();
  showCropMessage('');
  try {
    const [crops, labores, categories] = await Promise.all([cropApi('getCultivosWeb'), cropApi('getLaboresWeb'), cropApi('getCategoriasLaborWeb')]);
    cropState.crops = crops; cropState.labores = labores; categoryState.categories = categories;
    cropState.loaded = true; categoryState.loaded = true;
    renderCropMetrics(); renderCropsTable(); renderCategoriesCatalogTable(); renderCropLaborsTable(); renderCategoryLaborsTable(); renderAllLaborsTable();
  } catch (error) { showCropMessage(error.message); }
}

function loadCategories(force = false) { return loadCrops(force); }

function renderCropMetrics() {
  const active = cropState.crops.filter((crop) => crop.voided === '1');
  const labores = cropState.labores.filter((labor) => labor.voided === '1').length;
  document.querySelector('#crop-count').textContent = active.length;
  document.querySelector('#labor-count').textContent = labores;
  document.querySelector('#labor-average').textContent = active.length ? (labores / active.length).toLocaleString('es-CO', {maximumFractionDigits: 1}) : '—';
}

function laborStatus(labor) {
  if (!labor) return '<span class="status-pill inactive">Sin labores</span>';
  return `<span class="status-pill ${labor.voided === '1' ? 'active' : 'inactive'}">${labor.voided === '1' ? 'Activa' : 'Inactiva'}</span>`;
}

function renderCropsTable() {
  destroyCropTable('catalogTable');
  const canEditCrop = cropCan('cultivos.editar');
  const canAddLabor = cropCan('labores.crear');
  cropsCatalogBody.innerHTML = cropState.crops.map((crop) => {
    const total = cropState.labores.filter((labor) => labor.cultivo_id === crop.id && labor.voided === '1').length;
    const actions = [
      canEditCrop ? `<button class="table-action" data-edit-crop="${escapeCropHtml(crop.id)}">Editar</button>` : '',
      canAddLabor ? `<button class="table-action accent" data-add-labor="${escapeCropHtml(crop.id)}">+ Labor</button>` : '',
    ].join('');
    return `<tr>
      <td><strong>${escapeCropHtml(crop.descripcion)}</strong></td>
      <td>${escapeCropHtml(crop.codigo || '—')}</td>
      <td><strong>${total}</strong></td>
      <td><span class="status-pill ${crop.voided === '1' ? 'active' : 'inactive'}">${crop.voided === '1' ? 'Activo' : 'Inactivo'}</span></td>
      <td><div class="table-actions">${actions}</div></td>
    </tr>`;
  }).join('');
  bindLaborTableActions(cropsCatalogBody);
  createLaborTable('#crops-catalog-table', 'catalogTable', 'Cultivo o código…', 4, 'cultivos');
}

function renderCategoriesCatalogTable() {
  destroyCropTable('categoriesCatalogTable');
  const canEditCategory = cropCan('categorias_labor.editar');
  categoriesCatalogBody.innerHTML = categoryState.categories.map((category) => {
    const total = cropState.labores.filter((labor) => labor.categoria_labor_id === category.id && labor.voided === '1').length;
    return `<tr>
      <td><strong>${escapeCropHtml(category.descripcion)}</strong></td>
      <td>${escapeCropHtml(category.codigo || '—')}</td>
      <td><strong>${total}</strong></td>
      <td><span class="status-pill ${category.voided === '1' ? 'active' : 'inactive'}">${category.voided === '1' ? 'Activa' : 'Inactiva'}</span></td>
      <td><div class="table-actions">${canEditCategory ? `<button class="table-action" data-edit-category="${escapeCropHtml(category.id)}">Editar</button>` : ''}</div></td>
    </tr>`;
  }).join('');
  bindLaborTableActions(categoriesCatalogBody);
  createLaborTable('#categories-catalog-table', 'categoriesCatalogTable', 'Categoría o código…', 4, 'categorías');
}

function renderCropLaborsTable() {
  destroyCropTable('cropTable'); const rows = [];
  const canEditCrop = cropCan('cultivos.editar');
  const canAddLabor = cropCan('labores.crear');
  const canEditLabor = cropCan('labores.editar');
  cropState.crops.forEach((crop) => {
    const labores = cropState.labores.filter((labor) => labor.cultivo_id === crop.id);
    const cropCell = `<div class="catalog-parent"><strong>${escapeCropHtml(crop.descripcion)}</strong><small>${escapeCropHtml(crop.codigo || 'Sin código')}</small></div>`;
    if (!labores.length) {
      const actions = [
        canEditCrop ? `<button class="table-action" data-edit-crop="${escapeCropHtml(crop.id)}">Editar cultivo</button>` : '',
        canAddLabor ? `<button class="table-action accent" data-add-labor="${escapeCropHtml(crop.id)}">+ Labor</button>` : '',
      ].join('');
      rows.push(`<tr><td>${cropCell}</td><td><span class="muted-cell">Aún no tiene labores</span></td><td>—</td><td>—</td><td>${laborStatus(null)}</td><td><div class="table-actions">${actions}</div></td></tr>`); return;
    }
    labores.forEach((labor) => {
      const actions = [
        canEditLabor ? `<button class="table-action" data-edit-labor="${escapeCropHtml(labor.id)}">Editar labor</button>` : '',
        canAddLabor ? `<button class="table-action accent" data-add-labor="${escapeCropHtml(crop.id)}">+ Labor</button>` : '',
        canEditCrop ? `<button class="table-action quiet" data-edit-crop="${escapeCropHtml(crop.id)}">Cultivo</button>` : '',
      ].join('');
      rows.push(`<tr><td>${cropCell}</td><td><strong>${escapeCropHtml(labor.nombre)}</strong></td><td>${escapeCropHtml(labor.categoria_nombre || 'Sin categoría')}</td><td>${escapeCropHtml(labor.codigo || '—')}</td><td>${laborStatus(labor)}</td><td><div class="table-actions">${actions}</div></td></tr>`);
    });
  });
  cropLaborsBody.innerHTML = rows.join(''); bindLaborTableActions(cropLaborsBody);
  createLaborTable('#crop-labors-table', 'cropTable', 'Cultivo, labor o categoría…');
}

function renderCategoryLaborsTable() {
  destroyCropTable('categoryTable'); const rows = [];
  const canEditCategory = cropCan('categorias_labor.editar');
  const canEditLabor = cropCan('labores.editar');
  const categories = [...categoryState.categories, {id: '', descripcion: 'Sin categoría', codigo: '', virtual: true}];
  categories.forEach((category) => {
    const labores = cropState.labores.filter((labor) => (labor.categoria_labor_id || '') === category.id);
    if (category.virtual && !labores.length) return;
    const categoryCell = `<div class="catalog-parent"><strong>${escapeCropHtml(category.descripcion)}</strong><small>${escapeCropHtml(category.codigo || (category.virtual ? 'No clasificadas' : 'Sin código'))}</small></div>`;
    if (!labores.length) {
      rows.push(`<tr><td>${categoryCell}</td><td><span class="muted-cell">Aún no tiene labores</span></td><td>—</td><td>—</td><td><span class="status-pill inactive">Sin labores</span></td><td>${category.virtual || !canEditCategory ? '—' : `<button class="table-action" data-edit-category="${escapeCropHtml(category.id)}">Editar categoría</button>`}</td></tr>`); return;
    }
    labores.forEach((labor) => {
      const actions = [
        canEditLabor ? `<button class="table-action" data-edit-labor="${escapeCropHtml(labor.id)}">Editar labor</button>` : '',
        (category.virtual || !canEditCategory) ? '' : `<button class="table-action quiet" data-edit-category="${escapeCropHtml(category.id)}">Categoría</button>`,
      ].join('');
      rows.push(`<tr><td>${categoryCell}</td><td><strong>${escapeCropHtml(labor.nombre)}</strong></td><td>${escapeCropHtml(labor.cultivo_nombre || '—')}</td><td>${escapeCropHtml(labor.codigo || '—')}</td><td>${laborStatus(labor)}</td><td><div class="table-actions">${actions}</div></td></tr>`);
    });
  });
  categoryLaborsBody.innerHTML = rows.join(''); bindLaborTableActions(categoryLaborsBody);
  createLaborTable('#category-labors-table', 'categoryTable', 'Categoría, labor o cultivo…');
}

function renderAllLaborsTable() {
  destroyCropTable('allTable');
  const canEditLabor = cropCan('labores.editar');
  allLaborsBody.innerHTML = cropState.labores.map((labor) => `<tr><td><strong>${escapeCropHtml(labor.nombre)}</strong></td><td>${escapeCropHtml(labor.cultivo_nombre || '—')}</td><td>${escapeCropHtml(labor.categoria_nombre || 'Sin categoría')}</td><td>${escapeCropHtml(labor.codigo || '—')}</td><td>${laborStatus(labor)}</td><td>${canEditLabor ? `<button class="table-action" data-edit-labor="${escapeCropHtml(labor.id)}">Editar labor</button>` : ''}</td></tr>`).join('');
  bindLaborTableActions(allLaborsBody);
  createLaborTable('#all-labors-table', 'allTable', 'Labor, cultivo o categoría…');
}

function bindLaborTableActions(container) {
  container.querySelectorAll('[data-edit-crop]').forEach((button) => button.onclick = () => openCropDialog(cropState.crops.find((crop) => crop.id === button.dataset.editCrop)));
  container.querySelectorAll('[data-add-labor]').forEach((button) => button.onclick = () => openLaborDialog(null, button.dataset.addLabor));
  container.querySelectorAll('[data-edit-labor]').forEach((button) => button.onclick = () => openLaborDialog(cropState.labores.find((labor) => labor.id === button.dataset.editLabor)));
  container.querySelectorAll('[data-edit-category]').forEach((button) => button.onclick = () => openCategoryDialog(categoryState.categories.find((category) => category.id === button.dataset.editCategory)));
}

function openCropDialog(crop = null) {
  document.querySelector('#crop-dialog-title').textContent = crop ? 'Editar cultivo' : 'Nuevo cultivo';
  document.querySelector('#crop-id').value = crop?.id || ''; document.querySelector('#crop-name').value = crop?.descripcion || ''; document.querySelector('#crop-code').value = crop?.codigo || '';
  document.querySelector('#crop-dialog').showModal();
}

function populateLaborCategorySelect(selectedId = '') {
  document.querySelector('#labor-category').innerHTML = '<option value="">Sin categoría</option>' + categoryState.categories.filter((category) => category.voided === '1').map((category) => `<option value="${escapeCropHtml(category.id)}" ${selectedId === category.id ? 'selected' : ''}>${escapeCropHtml(category.descripcion)}</option>`).join('');
}

function populateLaborCropSelect(selectedId = '') {
  document.querySelector('#labor-crop-id').innerHTML = '<option value="">Selecciona un cultivo</option>' + cropState.crops.filter((crop) => crop.voided === '1').map((crop) => `<option value="${escapeCropHtml(crop.id)}" ${selectedId === crop.id ? 'selected' : ''}>${escapeCropHtml(crop.descripcion)}</option>`).join('');
}

function openLaborDialog(labor = null, cropId = '') {
  document.querySelector('#labor-dialog-title').textContent = labor ? 'Editar labor' : 'Nueva labor';
  document.querySelector('#labor-id').value = labor?.id || ''; populateLaborCropSelect(labor?.cultivo_id || cropId);
  document.querySelector('#labor-name').value = labor?.nombre || ''; document.querySelector('#labor-code').value = labor?.codigo || '';
  populateLaborCategorySelect(labor?.categoria_labor_id || ''); document.querySelector('#labor-dialog').showModal();
}

function openCategoryDialog(category = null) {
  document.querySelector('#category-dialog-title').textContent = category ? 'Editar categoría de labor' : 'Nueva categoría de labor';
  document.querySelector('#category-id').value = category?.id || ''; document.querySelector('#category-name').value = category?.descripcion || ''; document.querySelector('#category-code').value = category?.codigo || '';
  document.querySelector('#category-dialog').showModal();
}

document.querySelectorAll('[data-crop-tab]').forEach((tab) => tab.addEventListener('click', () => {
  document.querySelectorAll('[data-crop-tab]').forEach((item) => item.classList.remove('active')); tab.classList.add('active');
  document.querySelector('#catalogo-panel').hidden = tab.dataset.cropTab !== 'catalogo'; document.querySelector('#categorias-catalogo-panel').hidden = tab.dataset.cropTab !== 'categorias-catalogo'; document.querySelector('#cultivos-panel').hidden = tab.dataset.cropTab !== 'cultivos'; document.querySelector('#categorias-panel').hidden = tab.dataset.cropTab !== 'categorias'; document.querySelector('#todas-panel').hidden = tab.dataset.cropTab !== 'todas';
  const tableKeys = {catalogo: 'catalogTable', 'categorias-catalogo': 'categoriesCatalogTable', cultivos: 'cropTable', categorias: 'categoryTable', todas: 'allTable'};
  cropState[tableKeys[tab.dataset.cropTab]]?.columns.adjust();
}));

document.querySelector('[data-view="crops"]').addEventListener('click', () => loadCrops(true));
document.querySelector('#new-crop-button').addEventListener('click', () => openCropDialog());
document.querySelector('#new-category-button').addEventListener('click', () => openCategoryDialog());
document.querySelector('#new-labor-global-button').addEventListener('click', () => openLaborDialog());

document.querySelector('#crop-form').addEventListener('submit', async (event) => {
  event.preventDefault(); const user = currentCropUser();
  try { await cropApi('saveCultivoWeb', {id: document.querySelector('#crop-id').value, descripcion: document.querySelector('#crop-name').value, codigo: document.querySelector('#crop-code').value, created_by: user.id || ''}); document.querySelector('#crop-dialog').close(); await loadCrops(true); }
  catch (error) { showCropMessage(error.message); }
});

document.querySelector('#labor-form').addEventListener('submit', async (event) => {
  event.preventDefault(); const user = currentCropUser();
  try { await cropApi('saveLaborWeb', {id: document.querySelector('#labor-id').value, cultivo_id: document.querySelector('#labor-crop-id').value, nombre: document.querySelector('#labor-name').value, codigo: document.querySelector('#labor-code').value, categoria_labor_id: document.querySelector('#labor-category').value, created_by: user.id || ''}); document.querySelector('#labor-dialog').close(); await loadCrops(true); }
  catch (error) { showCropMessage(error.message); }
});

document.querySelector('#category-form').addEventListener('submit', async (event) => {
  event.preventDefault(); const user = currentCropUser();
  try { await cropApi('saveCategoriaLaborWeb', {id: document.querySelector('#category-id').value, descripcion: document.querySelector('#category-name').value, codigo: document.querySelector('#category-code').value, created_by: user.id || ''}); document.querySelector('#category-dialog').close(); await loadCrops(true); }
  catch (error) { showCropMessage(error.message); }
});
