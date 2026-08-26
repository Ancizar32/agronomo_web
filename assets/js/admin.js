const adminState = {usuarios: [], roles: [], permisos: [], fincas: [], loaded: false};
const adminTables = {users: null, roles: null, userFarms: null};
const userFarmSelection = new Set();
const adminMessage = document.querySelector('#admin-message');

async function adminApi(method, data = {}) {
  const response = await fetch('api/index.php', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({controller: 'agronomo', method, data}),
  });
  const raw = await response.text();
  let payload;
  try { payload = JSON.parse(raw); } catch (_) { throw new Error(`Respuesta inválida del servidor (HTTP ${response.status}).`); }
  if (response.status === 401) { handleSessionExpired(); throw new Error(payload.message || 'Tu sesión expiró. Inicia sesión nuevamente.'); }
  if (!response.ok || payload.success !== true) throw new Error(payload.message || 'No fue posible completar la operación.');
  return payload.detail;
}

function adminUser() {
  try { return JSON.parse(sessionStorage.getItem('agronomo_user')) || {}; } catch (_) { return {}; }
}

function hasAdminPermission(permission) {
  const user = adminUser();
  return user.rol_codigo === 'admin' || (user.permissions || []).includes(permission);
}

function escapeAdmin(value) {
  const span = document.createElement('span'); span.textContent = value == null ? '' : String(value); return span.innerHTML;
}

function showAdminMessage(text, success = false) {
  notifyResult(text, success);
}

async function loadAdministration(force = false) {
  if (adminState.loaded && !force) return;
  refreshUserPermissions();
  showAdminMessage('');
  try {
    const detail = await adminApi('getAdministracionWeb');
    Object.assign(adminState, detail, {loaded: true});
    populateUserRoles(); renderUsers(); renderRoles();
  } catch (error) { showAdminMessage(error.message); }
}

function renderUsers() {
  destroyAdminTable('users');
  const users = adminState.usuarios;
  const body = document.querySelector('#users-table-body');
  if (!users.length) { body.innerHTML = `<tr class="empty-row"><td colspan="7"><span>${icon('team')}</span><strong>No encontramos usuarios</strong><p>Ajusta los filtros o crea una cuenta nueva.</p></td></tr>`; return; }
  body.innerHTML = users.map((user) => {
    const initials = (user.name || user.user).split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    const actions = [
      hasAdminPermission('usuarios.editar') && user.id !== adminUser().id ? `<button class="table-action table-action-toggle ${user.void === '1' ? 'danger' : ''}" data-toggle-user="${escapeAdmin(user.id)}">${user.void === '1' ? 'Desactivar' : 'Activar'}</button>` : '',
      rowMenu([
        `<button type="button" class="row-menu-item" data-view-user="${escapeAdmin(user.id)}">Ver</button>`,
        hasAdminPermission('usuarios.editar') ? `<button type="button" class="row-menu-item" data-edit-user="${escapeAdmin(user.id)}">Editar</button>` : '',
        hasAdminPermission('usuarios.cambiar_password') ? `<button type="button" class="row-menu-item" data-password-user="${escapeAdmin(user.id)}">Clave</button>` : '',
      ]),
    ].join('');
    const provisional = user.pass_provi === '1' && user.clave_provisional
      ? `<span class="provisional-key"><small>Clave provisional</small><strong>${escapeAdmin(user.clave_provisional)}</strong></span>`
      : '';
    const farms = user.fincas || [];
    const farmSummary = farms.length
      ? `<div class="assigned-farms"><strong>${farms.length}</strong><span>${farms.length === 1 ? 'predio' : 'predios'}</span><small>${escapeAdmin(farms.slice(0, 2).map((farm) => farm.nombre).join(' · '))}${farms.length > 2 ? ` +${farms.length - 2}` : ''}</small></div>`
      : '<span class="no-assignment">Sin asignar</span>';
    return `<tr><td><div class="user-cell"><span>${escapeAdmin(initials)}</span><div><strong>${escapeAdmin(user.name || 'Sin nombre')}</strong><small>${escapeAdmin(user.mail || 'Sin correo')}</small></div></div></td><td><code class="username-code">${escapeAdmin(user.user)}</code>${provisional}</td><td><span class="role-pill">${escapeAdmin(user.rol_nombre || 'Sin rol')}</span></td><td>${farmSummary}</td><td><span class="status-pill ${user.void === '1' ? 'active' : 'inactive'}">${user.void === '1' ? 'Activo' : 'Inactivo'}</span></td><td>${escapeAdmin(user.ultimo_acceso || '—')}</td><td><div class="table-actions">${actions || '—'}</div></td></tr>`;
  }).join('');
  body.querySelectorAll('[data-edit-user]').forEach((button) => button.addEventListener('click', () => openUserDialog(adminState.usuarios.find((user) => user.id === button.dataset.editUser))));
  body.querySelectorAll('[data-view-user]').forEach((button) => button.addEventListener('click', () => openUserDetail(adminState.usuarios.find((user) => user.id === button.dataset.viewUser))));
  body.querySelectorAll('[data-password-user]').forEach((button) => button.addEventListener('click', () => openPasswordDialog(button.dataset.passwordUser)));
  body.querySelectorAll('[data-toggle-user]').forEach((button) => button.addEventListener('click', () => toggleUser(button.dataset.toggleUser)));
  adminTables.users = initializeAdminTable(body.closest('table'), [6]);
}

function renderRoles() {
  destroyAdminTable('roles');
  document.querySelector('#roles-caption').textContent = `${adminState.roles.length} roles configurados`;
  const body = document.querySelector('#roles-table-body');
  body.innerHTML = adminState.roles.map((role) => {
    const assignedUsers = Number(role.total_usuarios);
    const deleteAction = Number(role.es_sistema) !== 0 || !hasAdminPermission('roles.eliminar')
      ? ''
      : assignedUsers > 0
        ? `<button class="table-action danger blocked" data-blocked-delete-role="${role.id}" title="Este rol tiene usuarios asignados">Eliminar</button>`
        : `<button class="table-action danger" data-delete-role="${role.id}">Eliminar</button>`;
    const actions = role.codigo === 'admin' ? '<span class="protected-role">Protegido</span>' : [
      hasAdminPermission('roles.editar') ? `<button class="table-action" data-edit-role="${role.id}">Editar permisos</button>` : '',
      deleteAction,
    ].join('');
    return `<tr><td><strong>${escapeAdmin(role.nombre)}</strong><small class="role-code">${escapeAdmin(role.codigo)}</small></td><td>${escapeAdmin(role.descripcion || '—')}</td><td><strong>${Number(role.total_usuarios)}</strong></td><td><strong>${Number(role.total_permisos)}</strong></td><td><span class="status-pill ${Number(role.activo) === 1 ? 'active' : 'inactive'}">${Number(role.activo) === 1 ? 'Activo' : 'Inactivo'}</span></td><td><div class="table-actions">${actions || '—'}</div></td></tr>`;
  }).join('');
  body.querySelectorAll('[data-edit-role]').forEach((button) => button.addEventListener('click', () => openRoleDialog(adminState.roles.find((role) => String(role.id) === button.dataset.editRole))));
  body.querySelectorAll('[data-delete-role]').forEach((button) => button.addEventListener('click', () => deleteRole(button.dataset.deleteRole)));
  body.querySelectorAll('[data-blocked-delete-role]').forEach((button) => button.addEventListener('click', () => explainBlockedRoleDeletion(button.dataset.blockedDeleteRole)));
  adminTables.roles = initializeAdminTable(body.closest('table'), [5]);
}

async function explainBlockedRoleDeletion(id) {
  const role = adminState.roles.find((item) => String(item.id) === String(id));
  if (!role) return;
  const total = Number(role.total_usuarios);
  await Swal.fire({
    title: 'Este rol está en uso',
    text: `“${role.nombre}” está asignado a ${total} ${total === 1 ? 'usuario' : 'usuarios'}. Primero debes asignarle otro rol ${total === 1 ? 'a ese usuario' : 'a esos usuarios'} y luego podrás eliminarlo.`,
    icon: 'warning',
    confirmButtonText: 'Entendido',
    confirmButtonColor: '#173f32',
  });
}

function destroyAdminTable(name) {
  if (adminTables[name]) {
    adminTables[name].destroy();
    adminTables[name] = null;
  }
}

function initializeAdminTable(table, nonOrderable = []) {
  if (!window.jQuery?.fn?.DataTable || !table) return null;
  return window.jQuery(table).DataTable({
    pageLength: 10,
    lengthMenu: [[10, 25, 50, -1], [10, 25, 50, 'Todos']],
    autoWidth: false,
    responsive: false,
    order: [],
    columnDefs: nonOrderable.length ? [{targets: nonOrderable, orderable: false, searchable: false}] : [],
    dom: '<"dt-top"lf>rt<"dt-bottom"ip>',
    language: {
      emptyTable: 'No hay datos disponibles', zeroRecords: 'No se encontraron resultados',
      info: 'Mostrando _START_ a _END_ de _TOTAL_ registros', infoEmpty: 'Mostrando 0 registros',
      infoFiltered: '(filtrado de _MAX_)', lengthMenu: 'Mostrar _MENU_', search: 'Buscar:',
      searchPlaceholder: 'Escribe para filtrar…',
      paginate: {first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior'},
    },
  });
}

function populateUserRoles() {
  const options = adminState.roles.filter((role) => Number(role.activo) === 1).map((role) => `<option value="${role.id}">${escapeAdmin(role.nombre)}</option>`).join('');
  document.querySelector('#admin-user-role').innerHTML = '<option value="">Selecciona un rol</option>' + options;
}

function populateUserFarms(selectedIds = []) {
  const select = document.querySelector('#admin-user-farms');
  userFarmSelection.clear();
  selectedIds.map(String).forEach((id) => userFarmSelection.add(id));
  select.innerHTML = adminState.fincas.map((farm) => `<option value="${escapeAdmin(farm.id)}">${escapeAdmin(farm.descripcion)}</option>`).join('');
  if (adminTables.userFarms) { adminTables.userFarms.destroy(); adminTables.userFarms = null; }
  const body = document.querySelector('#user-farms-table-body');
  body.innerHTML = adminState.fincas.map((farm) => `<tr><td><input class="farm-row-check" type="checkbox" data-farm-assignment="${escapeAdmin(farm.id)}" aria-label="Asignar ${escapeAdmin(farm.descripcion)}"></td><td><strong>${escapeAdmin(farm.descripcion)}</strong></td><td>${escapeAdmin(farm.ubicacion || 'Sin ubicación')}</td></tr>`).join('');
  body.onchange = (event) => {
    const checkbox = event.target.closest('[data-farm-assignment]');
    if (!checkbox) return;
    if (checkbox.checked) userFarmSelection.add(String(checkbox.dataset.farmAssignment));
    else userFarmSelection.delete(String(checkbox.dataset.farmAssignment));
    syncUserFarmSelection();
  };
  if (window.jQuery?.fn?.DataTable) {
    adminTables.userFarms = window.jQuery('#user-farms-table').DataTable({pageLength:10,lengthChange:false,autoWidth:false,order:[[1,'asc']],columnDefs:[{targets:0,orderable:false,searchable:false}],dom:'rt<"assignment-table-footer"ip>',language:{emptyTable:'No hay fincas disponibles',zeroRecords:'No se encontraron fincas',info:'Mostrando _START_–_END_ de _TOTAL_',infoEmpty:'0 fincas',paginate:{next:'Siguiente',previous:'Anterior'}},drawCallback:function () {
      syncUserFarmSelection();
      const api = this.api();
      const pagination = api.table().container().querySelector('.dataTables_paginate');
      if (pagination) pagination.hidden = api.page.info().pages <= 1;
    }});
    window.jQuery('#farm-assignment-search').off('input.assignment').on('input.assignment', function () { adminTables.userFarms.search(this.value).draw(); });
  }
  document.querySelector('#farm-assignment-search').value = '';
  updateUserFarmCount();
}

function updateUserFarmCount() {
  const selected = userFarmSelection.size;
  const total = adminState.fincas.length;
  document.querySelector('#admin-user-farms-count').textContent = `${selected} de ${total} ${total === 1 ? 'seleccionada' : 'seleccionadas'}`;
}

function setAllUserFarms(selectAll) {
  userFarmSelection.clear();
  if (selectAll) adminState.fincas.forEach((farm) => userFarmSelection.add(String(farm.id)));
  syncUserFarmSelection();
}

function syncUserFarmSelection() {
  const select = document.querySelector('#admin-user-farms');
  [...select.options].forEach((option) => { option.selected = userFarmSelection.has(String(option.value)); });
  document.querySelectorAll('[data-farm-assignment]').forEach((checkbox) => { checkbox.checked = userFarmSelection.has(String(checkbox.dataset.farmAssignment)); });
  const pageRows = adminTables.userFarms ? adminTables.userFarms.rows({page:'current'}).nodes().toArray() : [];
  const pageChecks = pageRows.map((row) => row.querySelector('[data-farm-assignment]')).filter(Boolean);
  const pageCheck = document.querySelector('#farm-assignment-page-check');
  pageCheck.checked = pageChecks.length > 0 && pageChecks.every((checkbox) => checkbox.checked);
  pageCheck.indeterminate = pageChecks.some((checkbox) => checkbox.checked) && !pageCheck.checked;
  updateUserFarmCount();
}

function selectFilteredUserFarms() {
  if (!adminTables.userFarms) return;
  adminTables.userFarms.rows({search:'applied'}).nodes().toArray().forEach((row) => {
    const checkbox = row.querySelector('[data-farm-assignment]');
    if (checkbox) userFarmSelection.add(String(checkbox.dataset.farmAssignment));
  });
  syncUserFarmSelection();
}

function toggleVisibleUserFarms(checked) {
  if (!adminTables.userFarms) return;
  adminTables.userFarms.rows({page:'current'}).nodes().toArray().forEach((row) => {
    const checkbox = row.querySelector('[data-farm-assignment]');
    if (!checkbox) return;
    if (checked) userFarmSelection.add(String(checkbox.dataset.farmAssignment));
    else userFarmSelection.delete(String(checkbox.dataset.farmAssignment));
  });
  syncUserFarmSelection();
}

function openUserDialog(user = null) {
  const editing = Boolean(user);
  document.querySelector('#user-dialog-title').textContent = editing ? 'Editar usuario' : 'Nuevo usuario';
  document.querySelector('#admin-user-id').value = user?.id || '';
  document.querySelector('#admin-user-name').value = user?.name || '';
  document.querySelector('#admin-user-login').value = user?.user || '';
  document.querySelector('#admin-user-mail').value = user?.mail || '';
  document.querySelector('#admin-user-role').value = user?.rol_id || '';
  document.querySelector('.provisional-note').hidden = editing;
  document.querySelector('#user-dialog-message').textContent = '';
  document.querySelector('#user-dialog').showModal();
  initializeStandardSelect2('#admin-user-role', {dialog:'#user-dialog', placeholder:'Busca y selecciona un rol…'});
  populateUserFarms((user?.fincas || []).map((farm) => farm.id));
}

async function openPasswordDialog(id) {
  const user = adminState.usuarios.find((item) => item.id === id);
  const confirmed = await Swal.fire({title:'¿Generar una clave provisional?',text:`La clave actual de ${user?.name || 'este usuario'} dejará de funcionar. Se generarán 4 números aleatorios.`,icon:'warning',showCancelButton:true,confirmButtonText:'Sí, generar clave',cancelButtonText:'Cancelar',confirmButtonColor:'#173f32',cancelButtonColor:'#7b837e',reverseButtons:true});
  if (!confirmed.value) return;
  try {
    const detail = await adminApi('resetUsuarioPasswordWeb', {id});
    await loadAdministration(true);
    await showProvisionalPassword(detail.provisional_password, user?.name || user?.user || 'el usuario');
  } catch (error) {
    await Swal.fire({title:'No fue posible generar la clave',text:error.message,icon:'error',confirmButtonColor:'#173f32'});
  }
}

async function showProvisionalPassword(password, recipient) {
  await Swal.fire({title:'Clave provisional generada',html:`<p>Comparte esta clave con <strong>${escapeAdmin(recipient)}</strong>. Solo se mostrará ahora.</p><div class="temporary-password" aria-label="Clave provisional">${escapeAdmin(password)}</div><p class="swal-password-note">Al ingresar, deberá reemplazarla por una contraseña personal.</p>`,icon:'success',confirmButtonText:'Entendido',confirmButtonColor:'#173f32',allowOutsideClick:false});
}

function openUserDetail(user) {
  if (!user) return;
  document.querySelector('#detail-user-name').textContent = user.name || user.user;
  const provisionalDetail = user.pass_provi === '1' && user.clave_provisional
    ? `<dt>Clave provisional</dt><dd><span class="detail-provisional-key">${escapeAdmin(user.clave_provisional)}</span><small class="detail-key-note">Se ocultará cuando el usuario defina su contraseña.</small></dd>`
    : '';
  const assignedFarms = (user.fincas || []).length
    ? `<div class="detail-farm-list">${user.fincas.map((farm) => `<span>${escapeAdmin(farm.nombre)}</span>`).join('')}</div>`
    : '<span class="no-assignment">Sin fincas asignadas</span>';
  document.querySelector('#user-detail-content').innerHTML = `<dl><dt>Usuario</dt><dd><code>@${escapeAdmin(user.user)}</code></dd>${provisionalDetail}<dt>Correo</dt><dd>${escapeAdmin(user.mail || '—')}</dd><dt>Rol</dt><dd><span class="role-pill">${escapeAdmin(user.rol_nombre || 'Sin rol')}</span></dd><dt>Fincas o predios</dt><dd>${assignedFarms}</dd><dt>Estado</dt><dd><span class="status-pill ${user.void === '1' ? 'active' : 'inactive'}">${user.void === '1' ? 'Activo' : 'Inactivo'}</span></dd><dt>Último acceso</dt><dd>${escapeAdmin(user.ultimo_acceso || '—')}</dd><dt>Registrado</dt><dd>${escapeAdmin(user.date_crea || '—')}</dd></dl>`;
  const edit = document.querySelector('#detail-edit-user');
  edit.hidden = !hasAdminPermission('usuarios.editar');
  edit.onclick = () => { document.querySelector('#user-detail-dialog').close(); openUserDialog(user); };
  document.querySelector('#user-detail-dialog').showModal();
}

function permissionRoles(permission) {
  return String(permission.roles || '').split(',').filter(Boolean);
}

function openRoleDialog(role = null) {
  document.querySelector('#role-dialog-title').textContent = role ? 'Editar rol' : 'Nuevo rol';
  document.querySelector('#admin-role-id').value = role?.id || '';
  document.querySelector('#admin-role-name').value = role?.nombre || '';
  document.querySelector('#admin-role-description').value = role?.descripcion || '';
  document.querySelector('#role-dialog-message').textContent = '';
  const grouped = adminState.permisos.reduce((result, permission) => { (result[permission.modulo] ||= {})[permission.accion] = permission; return result; }, {});
  const preferredActions = ['ver','crear','editar','anular','eliminar','cambiar_password','importar'];
  const remaining = [...new Set(adminState.permisos.map((permission) => permission.accion))].filter((action) => !preferredActions.includes(action)).sort();
  const actions = [...preferredActions.filter((action) => adminState.permisos.some((permission) => permission.accion === action)), ...remaining];
  const actionLabel = (action) => ({cambiar_password:'Cambiar clave'}[action] || action.replaceAll('_', ' '));
  document.querySelector('#permission-matrix').innerHTML = `<table><thead><tr><th>Módulo</th>${actions.map((action) => `<th>${escapeAdmin(actionLabel(action))}</th>`).join('')}<th>Acciones rápidas</th></tr></thead><tbody>${Object.entries(grouped).map(([module, permissions]) => `<tr><td><strong>${escapeAdmin(module.replaceAll('_', ' '))}</strong></td>${actions.map((action) => { const permission = permissions[action]; return `<td>${permission ? `<input class="permission-checkbox" data-module="${escapeAdmin(module)}" data-action="${escapeAdmin(action)}" type="checkbox" name="permission" value="${permission.id}" ${role && permissionRoles(permission).includes(String(role.id)) ? 'checked' : ''}>` : '<span>—</span>'}</td>`; }).join('')}<td><div class="permission-shortcuts"><button type="button" data-permission-row="${escapeAdmin(module)}" data-mode="all" title="Marcar todo">${icon('check')}</button><button type="button" data-permission-row="${escapeAdmin(module)}" data-mode="view" title="Solo ver">${icon('eye')}</button><button type="button" data-permission-row="${escapeAdmin(module)}" data-mode="none" title="Ninguno">${icon('close')}</button></div></td></tr>`).join('')}</tbody></table>`;
  document.querySelectorAll('[data-permission-row]').forEach((button) => button.addEventListener('click', () => {
    document.querySelectorAll(`.permission-checkbox[data-module="${CSS.escape(button.dataset.permissionRow)}"]`).forEach((checkbox) => { checkbox.checked = button.dataset.mode === 'all' || (button.dataset.mode === 'view' && checkbox.dataset.action === 'ver'); });
  }));
  document.querySelector('#admin-list-heading').hidden = true;
  document.querySelector('#admin-tabs').hidden = true;
  document.querySelector('#admin-message').hidden = true;
  document.querySelector('#users-panel').hidden = true;
  document.querySelector('#roles-panel').hidden = true;
  document.querySelector('#role-editor-page').hidden = false;
  document.querySelector('#role-editor-page').scrollIntoView({behavior: 'smooth', block: 'start'});
}

function closeRoleEditor() {
  document.querySelector('#role-editor-page').hidden = true;
  document.querySelector('#admin-list-heading').hidden = false;
  document.querySelector('#admin-tabs').hidden = false;
  document.querySelector('#admin-message').hidden = false;
  document.querySelector('#users-panel').hidden = true;
  document.querySelector('#roles-panel').hidden = false;
  document.querySelectorAll('.admin-tab').forEach((tab) => tab.classList.toggle('active', tab.dataset.adminTab === 'roles'));
  document.querySelector('#admin-view').scrollIntoView({behavior: 'smooth', block: 'start'});
}

async function toggleUser(id) {
  const user = adminState.usuarios.find((item) => item.id === id);
  if (!user) return;
  const action = user.void === '1' ? 'desactivar' : 'activar';
  const confirmed = await Swal.fire({
    title: `¿${action === 'desactivar' ? 'Desactivar' : 'Activar'} usuario?`,
    text: `${user.name || user.user} ${action === 'desactivar' ? 'perderá el acceso al sistema.' : 'podrá ingresar nuevamente.'}`,
    icon: action === 'desactivar' ? 'warning' : 'question',
    showCancelButton: true,
    confirmButtonText: action === 'desactivar' ? 'Sí, desactivar' : 'Sí, activar',
    cancelButtonText: 'Cancelar',
    confirmButtonColor: '#173f32',
    cancelButtonColor: '#7b837e',
    reverseButtons: true,
  });
  if (!confirmed.value) return;
  try { await adminApi('toggleUsuarioWeb', {id}); await loadAdministration(true); showAdminMessage('Estado actualizado.', true); } catch (error) { showAdminMessage(error.message); }
}

async function deleteRole(id) {
  const role = adminState.roles.find((item) => String(item.id) === String(id));
  if (!role) return;
  const confirmation = await Swal.fire({title:'¿Eliminar este rol?',text:`Se eliminará “${role.nombre}” y su configuración de permisos.`,icon:'warning',showCancelButton:true,confirmButtonText:'Sí, eliminar',cancelButtonText:'Cancelar',confirmButtonColor:'#a04431',cancelButtonColor:'#7b837e',reverseButtons:true});
  if (!confirmation.value) return;
  try {
    await adminApi('deleteRolWeb', {id});
    await loadAdministration(true);
    await Swal.fire({title:'Rol eliminado',text:'El rol fue eliminado correctamente.',icon:'success',confirmButtonColor:'#173f32'});
  } catch (error) {
    await Swal.fire({title:'No se puede eliminar',text:error.message,icon:'error',confirmButtonColor:'#173f32'});
  }
}

async function openAdministrationHome() {
  const defaultTab = hasAdminPermission('usuarios.ver') ? 'users' : 'roles';
  document.querySelector('#role-editor-page').hidden = true;
  document.querySelector('#admin-list-heading').hidden = false;
  document.querySelector('#admin-tabs').hidden = false;
  document.querySelector('#admin-message').hidden = false;
  document.querySelector('#users-panel').hidden = defaultTab !== 'users';
  document.querySelector('#roles-panel').hidden = defaultTab !== 'roles';
  document.querySelectorAll('.admin-tab').forEach((tab) => tab.classList.toggle('active', tab.dataset.adminTab === defaultTab));
  await loadAdministration(true);
  window.setTimeout(() => {
    if (defaultTab === 'users') adminTables.users?.columns.adjust().draw(false);
    else adminTables.roles?.columns.adjust().draw(false);
  }, 0);
}

document.querySelector('[data-view="admin"]').addEventListener('click', openAdministrationHome);
document.querySelectorAll('.admin-tab').forEach((tab) => tab.addEventListener('click', () => {
  window.setTimeout(() => {
    if (tab.dataset.adminTab === 'users') adminTables.users?.columns.adjust();
    if (tab.dataset.adminTab === 'roles') adminTables.roles?.columns.adjust();
  }, 0);
}));
document.querySelector('#new-user-button').addEventListener('click', () => openUserDialog());
document.querySelector('#new-role-button').addEventListener('click', () => openRoleDialog());
document.querySelector('#admin-user-farms-all').addEventListener('click', () => setAllUserFarms(true));
document.querySelector('#admin-user-farms-none').addEventListener('click', () => setAllUserFarms(false));
document.querySelector('#admin-user-farms-filtered').addEventListener('click', selectFilteredUserFarms);
document.querySelector('#user-dialog').addEventListener('change', (event) => {
  if (event.target.matches('#farm-assignment-page-check')) toggleVisibleUserFarms(event.target.checked);
});
document.querySelector('#back-to-roles').addEventListener('click', closeRoleEditor);
document.querySelector('#cancel-role-editor').addEventListener('click', closeRoleEditor);
document.querySelectorAll('[data-admin-close]').forEach((button) => button.addEventListener('click', () => document.querySelector(`#${button.dataset.adminClose}`).close()));

document.querySelector('#user-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  try {
    const editing = Boolean(document.querySelector('#admin-user-id').value);
    const name = document.querySelector('#admin-user-name').value;
    const fincaIds = [...document.querySelector('#admin-user-farms').selectedOptions].map((option) => option.value);
    const detail = await adminApi('saveUsuarioWeb', {id:document.querySelector('#admin-user-id').value,name,user:document.querySelector('#admin-user-login').value,mail:document.querySelector('#admin-user-mail').value,rol_id:document.querySelector('#admin-user-role').value,finca_ids:fincaIds});
    document.querySelector('#user-dialog').close(); await loadAdministration(true); showAdminMessage('Usuario guardado con éxito.', true);
    if (!editing && detail.provisional_password) await showProvisionalPassword(detail.provisional_password, name);
  } catch (error) { notifyResult(error.message, false); }
});

document.querySelector('#toggle-all-permissions').addEventListener('click', () => {
  const boxes = [...document.querySelectorAll('#permission-matrix input[type="checkbox"]')];
  const check = boxes.some((box) => !box.checked); boxes.forEach((box) => { box.checked = check; });
  document.querySelector('#toggle-all-permissions').textContent = check ? 'Limpiar selección' : 'Seleccionar todos';
});
document.querySelector('#clear-all-permissions').addEventListener('click', () => {
  document.querySelectorAll('#permission-matrix input[type="checkbox"]').forEach((box) => { box.checked = false; });
});

document.querySelector('#role-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const permissions = [...document.querySelectorAll('#permission-matrix input:checked')].map((input) => input.value);
  try { await adminApi('saveRolWeb', {id:document.querySelector('#admin-role-id').value,nombre:document.querySelector('#admin-role-name').value,descripcion:document.querySelector('#admin-role-description').value,permisos:permissions}); await loadAdministration(true); closeRoleEditor(); showAdminMessage('Rol y permisos guardados.', true); } catch (error) { notifyResult(error.message, false); }
});
