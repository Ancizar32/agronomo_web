const loginView = document.querySelector('#login-view');
const dashboardView = document.querySelector('#dashboard-view');
const form = document.querySelector('#login-form');
const message = document.querySelector('#login-message');
const button = document.querySelector('#login-button');
const dashboard = document.querySelector('#dashboard-view');
const sidebarToggle = document.querySelector('#sidebar-toggle');
const forcedPasswordDialog = document.querySelector('#forced-password-dialog');
const forcedPasswordForm = document.querySelector('#forced-password-form');

// SweetAlert2 se dibuja en document.body con z-index fijo, pero un <dialog>
// abierto vive en el "top layer" del navegador y siempre se pinta encima de
// cualquier elemento normal, sin importar su z-index. Sin este parche, un
// Swal.fire() disparado mientras hay un <dialog> abierto (ej. el formulario
// de usuario) queda oculto detrás del modal. Al fijar `target` en el propio
// <dialog>, SweetAlert2 se inserta dentro de su top layer y sí se ve.
if (window.Swal && !Swal.__dialogAwarePatch) {
  const nativeSwalFire = Swal.fire.bind(Swal);
  Swal.fire = (options) => {
    if (options && typeof options === 'object' && !options.target) {
      const openDialog = document.querySelector('dialog[open]');
      if (openDialog) options = {...options, target: openDialog};
    }
    return nativeSwalFire(options);
  };
  Swal.__dialogAwarePatch = true;
}

// Punto único para mostrar avisos de éxito/error con SweetAlert2 en vez de
// texto plano en la página (que además de perderse detrás de los modales,
// era inconsistente con el resto de confirmaciones de la app).
function notifyResult(text, success = false, icon) {
  if (!text) return;
  Swal.fire({
    icon: icon || (success ? 'success' : 'error'),
    title: success ? 'Listo' : 'Error',
    text,
    confirmButtonColor: '#173f32',
  });
}
window.notifyResult = notifyResult;

// El backend responde 401 cuando la sesión de PHP ya no existe (expiró o el
// servidor se reinició) — antes esto se confundía con un 403 "No tienes
// permiso" porque canWeb() devuelve false igual sin importar la causa. Cada
// xApi() de los módulos llama esto en un 401 para sacar al usuario de vuelta
// al login con un mensaje claro, en vez de dejarlo viendo una pantalla
// muerta con errores de permisos que no tienen sentido.
let sessionExpiredHandled = false;
function handleSessionExpired() {
  if (sessionExpiredHandled) return;
  sessionExpiredHandled = true;
  sessionStorage.removeItem('agronomo_user');
  sessionStorage.removeItem('agronomo_active_view');
  document.documentElement.classList.remove('agronomo-has-session');
  document.documentElement.removeAttribute('data-agronomo-view');
  if (forcedPasswordDialog.open) forcedPasswordDialog.close();
  dashboardView.hidden = true;
  loginView.hidden = false;
  form.reset();
  message.textContent = 'Tu sesión expiró. Inicia sesión nuevamente.';
  window.setTimeout(() => { sessionExpiredHandled = false; }, 1000);
}
window.handleSessionExpired = handleSessionExpired;

function setSidebarCollapsed(collapsed) {
  dashboard.classList.toggle('sidebar-collapsed', collapsed);
  sidebarToggle.setAttribute('aria-expanded', String(!collapsed));
  sidebarToggle.setAttribute('aria-label', collapsed ? 'Mostrar menú' : 'Ocultar menú');
  sidebarToggle.title = collapsed ? 'Mostrar menú' : 'Ocultar menú';
  sidebarToggle.firstElementChild.classList.toggle('chevron-right', collapsed);
  sidebarToggle.firstElementChild.classList.toggle('chevron-left', !collapsed);
}

function showDashboard(user) {
  const name = user.name || user.user || 'Equipo';
  loginView.hidden = true;
  dashboardView.hidden = false;
  document.querySelector('#user-name').textContent = name;
  document.querySelector('#welcome-name').textContent = name.split(' ')[0];
  const hour = new Date().getHours();
  document.querySelector('#welcome-greeting').textContent = hour < 12 ? 'Buenos días' : hour < 19 ? 'Buenas tardes' : 'Buenas noches';
  document.querySelector('#user-initial').textContent = name.charAt(0).toUpperCase();
  document.querySelector('#user-role').textContent = user.rol_nombre || (user.roll === 'S' ? 'Administración' : 'Equipo técnico');
  const today = new Date();
  document.querySelector('#summary-weekday').textContent = today.toLocaleDateString('es-CO', {weekday:'long'}).toUpperCase();
  document.querySelector('#summary-day-number').textContent = today.toLocaleDateString('es-CO', {day:'2-digit'});
  document.querySelector('#summary-month-year').textContent = today.toLocaleDateString('es-CO', {month:'long', year:'numeric'});
  applyUserPermissions(user);
  if (userCan(user, 'dashboard.ver')) loadResumen();
  restoreLastView();
}

// Recuerda el último módulo visitado (sessionStorage.agronomo_active_view,
// escrito en el listener de .nav-item de más abajo) para no mandar siempre
// al usuario a Resumen al recargar la página estando en otro módulo.
//
// El click se difiere con setTimeout: app.js es el primer <script defer> del
// documento, así que si se dispara aquí mismo (síncrono), el listener que
// cada módulo (agenda.js, visits.js, etc.) registra sobre su propio
// .nav-item para cargar sus datos TODAVÍA no existe — ese script aún no ha
// corrido. El click sí cambia la vista visible, pero nunca dispara la carga
// de datos, dejando el módulo pegado en su placeholder ("Consultando...")
// hasta que el usuario cambia de módulo y vuelve. Al encolarlo con
// setTimeout, corre después de que todos los <script defer> ya se
// ejecutaron y registraron sus listeners.
function restoreLastView() {
  const savedView = sessionStorage.getItem('agronomo_active_view');
  window.setTimeout(() => {
    hideBootLoader();
    // Este atributo solo existe para tapar con CSS el parpadeo de Resumen
    // durante el primer pintado (ver <script> del <head>). Si no se quita
    // aquí, la regla `html[data-agronomo-view] #summary-view{display:none}`
    // se queda activa para siempre en esta pestaña — cualquier clic
    // posterior en "Resumen" cambia el atributo .hidden correctamente, pero
    // esa regla !important lo sigue tapando, dejando la vista en blanco.
    document.documentElement.removeAttribute('data-agronomo-view');
    if (!savedView) return;
    const target = document.querySelector(`.nav-item[data-view="${savedView}"]`);
    if (target && !target.hidden) target.click();
  }, 0);
}

function hideBootLoader() {
  const loader = document.querySelector('#boot-loader');
  if (loader) loader.hidden = true;
}

function escapeResumenHtml(value) {
  const element = document.createElement('span');
  element.textContent = value == null ? '' : String(value);
  return element.innerHTML;
}

function formatResumenDay(value) {
  const date = value ? new Date(`${String(value).slice(0, 10)}T12:00:00`) : null;
  return date && !Number.isNaN(date.getTime()) ? date.toLocaleDateString('es-CO', {day: '2-digit', month: 'short'}) : '—';
}

async function loadResumen() {
  const message = document.querySelector('#resumen-message');
  try {
    const response = await fetch('api/index.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({controller: 'agronomo', method: 'getResumenWeb', data: {}}),
    });
    const raw = await response.text();
    let payload;
    try { payload = JSON.parse(raw); } catch (_) { throw new Error(`Respuesta inválida del servidor (HTTP ${response.status}).`); }
    if (response.status === 401) { handleSessionExpired(); throw new Error(payload.message || 'Tu sesión expiró. Inicia sesión nuevamente.'); }
    if (!response.ok || payload.success !== true) throw new Error(payload.message || 'No fue posible cargar el resumen.');
    const {contadores, actividad_reciente: actividad, alertas_predio: alertas = []} = payload.detail;
    document.querySelector('#metric-visitas-mes').textContent = contadores.visitas_mes ?? 0;
    document.querySelector('#metric-fincas').textContent = contadores.total_fincas ?? 0;
    document.querySelector('#metric-hectareas').textContent = `${Number(contadores.total_hectareas || 0).toLocaleString('es-CO', {maximumFractionDigits:1})} ha`;
    document.querySelector('#metric-lotes').textContent = contadores.total_lotes ?? 0;
    document.querySelector('#metric-cultivos').textContent = contadores.total_cultivos ?? 0;
    document.querySelector('#metric-insumos').textContent = contadores.total_insumos ?? 0;
    document.querySelector('#metric-formulas').textContent = contadores.total_formulas ?? 0;
    document.querySelector('#metric-tecnicos').textContent = contadores.total_tecnicos ?? 0;
    const farms = Number(contadores.total_fincas || 0);
    const properties = Number(contadores.total_predios || 0);
    const assigned = Number(contadores.fincas_asignadas || 0);
    const propertyPercent = farms ? Math.min(100, Math.round((properties / farms) * 100)) : 0;
    const assignedPercent = farms ? Math.min(100, Math.round((assigned / farms) * 100)) : 0;
    document.querySelector('#metric-predios-caption').textContent = `${properties} ${properties === 1 ? 'predio completo' : 'predios completos'}`;
    document.querySelector('#summary-predios-ratio').textContent = `${properties} de ${farms}`;
    document.querySelector('#summary-predios-progress').value = propertyPercent;
    document.querySelector('#summary-assigned-ratio').textContent = `${assigned} de ${farms}`;
    document.querySelector('#summary-assigned-progress').value = assignedPercent;
    const currentVisits = Number(contadores.visitas_mes || 0);
    const previousVisits = Number(contadores.visitas_mes_anterior || 0);
    const difference = currentVisits - previousVisits;
    document.querySelector('#metric-visits-trend').textContent = difference === 0 ? 'Mismo ritmo que el mes anterior' : `${difference > 0 ? '↑' : '↓'} ${Math.abs(difference)} frente al mes anterior`;
    const list = document.querySelector('#recent-activity-list');
    list.innerHTML = actividad.length ? actividad.map((visita) => `
      <li><span>${escapeResumenHtml(formatResumenDay(visita.fecha))}</span><div><strong>${escapeResumenHtml(visita.finca_nombre || 'Finca sin nombre')}</strong><p>${escapeResumenHtml(visita.tecnico_nombre || 'Sin técnico')}${visita.descripcion ? ` · ${escapeResumenHtml(visita.descripcion)}` : ''}</p></div><small>${Number(visita.total_lotes || 0)} ${Number(visita.total_lotes || 0) === 1 ? 'lote' : 'lotes'}</small></li>`).join('')
      : '<li><span>—</span><div><strong>Sin visitas registradas</strong><p>Aún no hay visitas técnicas capturadas.</p></div></li>';
    const alertList = document.querySelector('#summary-alert-list');
    const alertCount = alertas.length;
    document.querySelector('#summary-alert-count').textContent = `${alertCount} ${alertCount === 1 ? 'alerta' : 'alertas'}`;
    alertList.innerHTML = alertCount ? alertas.map((alerta) => {
      const days = alerta.dias_restantes == null ? null : Number(alerta.dias_restantes);
      const timing = days == null ? 'Fecha pendiente' : days < 0 ? `Venció hace ${Math.abs(days)} ${Math.abs(days) === 1 ? 'día' : 'días'}` : days === 0 ? 'Vence hoy' : `Vence en ${days} ${days === 1 ? 'día' : 'días'}`;
      const targetStep = ['Registro ICA','Contrato de proveeduría'].includes(alerta.documento) ? 2 : 3;
      return `<article class="summary-alert ${escapeResumenHtml(alerta.nivel)}"><span class="summary-alert-mark">${icon('alert')}</span><div><strong>${escapeResumenHtml(alerta.documento)}</strong><p>${escapeResumenHtml(alerta.finca_nombre)}</p></div><time>${escapeResumenHtml(timing)}</time><button type="button" data-alert-farm="${escapeResumenHtml(alerta.finca_id)}" data-alert-step="${targetStep}">Revisar predio</button></article>`;
    }).join('') : `<div class="summary-alert-empty"><span>${icon('check')}</span><strong>Todo al día</strong><p>No hay documentos próximos a vencer en los siguientes 90 días.</p></div>`;
    alertList.querySelectorAll('[data-alert-farm]').forEach((button) => button.addEventListener('click', () => {
      const target = document.querySelector('.nav-item[data-view="farms"]');
      if (target && !target.hidden) {
        sessionStorage.setItem('agronomo_open_farm', button.dataset.alertFarm);
        sessionStorage.setItem('agronomo_open_property_step', button.dataset.alertStep || '0');
        target.click();
      }
    }));
  } catch (error) {
    message.textContent = error.message;
    message.classList.add('visible');
  }
}

// Trae el rol y los permisos actuales del usuario desde la base de datos
// (endpoint getPermisosUsuario, ya existía pero no se estaba usando) y
// actualiza tanto sessionStorage como los botones visibles. Se llama al
// recargar la página y al cambiar de módulo, para que una sesión que ya
// estaba abierta refleje cambios de permisos sin necesidad de cerrar sesión.
async function refreshUserPermissions() {
  let stored;
  try { stored = JSON.parse(sessionStorage.getItem('agronomo_user')) || {}; } catch (_) { stored = {}; }
  if (!stored.id) return stored;
  try {
    const response = await fetch('api/index.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({controller: 'agronomo', method: 'getPermisosUsuario', data: {usuario_id: stored.id}}),
    });
    const payload = await response.json();
    if (payload.success !== true) return stored;
    const updated = {
      ...stored,
      rol_id: payload.detail.rol_id,
      rol_codigo: payload.detail.rol_codigo,
      rol_nombre: payload.detail.rol_nombre,
      permissions: payload.detail.permissions,
    };
    sessionStorage.setItem('agronomo_user', JSON.stringify(updated));
    applyUserPermissions(updated);
    return updated;
  } catch (_) {
    return stored;
  }
}
window.refreshUserPermissions = refreshUserPermissions;

async function continueAfterLogin(user) {
  if (String(user.pass_provi) === '1') {
    loginView.hidden = false;
    dashboardView.hidden = true;
    document.querySelector('#forced-password-message').textContent = '';
    document.querySelector('#forced-password').value = '';
    document.querySelector('#forced-password-confirm').value = '';
    if (!forcedPasswordDialog.open) forcedPasswordDialog.showModal();
    window.setTimeout(() => document.querySelector('#forced-password').focus(), 50);
    return;
  }
  if (forcedPasswordDialog.open) forcedPasswordDialog.close();
  const fresh = await refreshUserPermissions();
  showDashboard(fresh);
}

function userCan(user, permission) {
  return user.rol_codigo === 'admin' || (user.permissions || []).includes(permission);
}

function applyUserPermissions(user) {
  const modules = {farms:'fincas.ver', crops:'cultivos.ver', inputs:'insumos.ver', formulas:'formulas.ver', recommendations:'recomendaciones.ver', visits:'visitas.ver', team:'tecnicos.ver', agenda:'agenda.ver'};
  document.querySelectorAll('.nav-item[data-view]').forEach((item) => {
    const permission = modules[item.dataset.view];
    if (permission) item.hidden = !userCan(user, permission);
  });
  document.querySelector('.nav-item[data-view="admin"]').hidden = !userCan(user, 'usuarios.ver') && !userCan(user, 'roles.ver');
  const canSeeUsers = userCan(user, 'usuarios.ver');
  const canSeeRoles = userCan(user, 'roles.ver');
  document.querySelector('.admin-tab[data-admin-tab="users"]').hidden = !canSeeUsers;
  document.querySelector('.admin-tab[data-admin-tab="roles"]').hidden = !canSeeRoles;
  document.querySelector('#users-panel').hidden = !canSeeUsers;
  document.querySelector('#roles-panel').hidden = canSeeUsers || !canSeeRoles;
  if (!canSeeUsers && canSeeRoles) {
    document.querySelector('.admin-tab[data-admin-tab="roles"]').classList.add('active');
    document.querySelector('.admin-tab[data-admin-tab="users"]').classList.remove('active');
  }
  document.querySelector('#new-user-button').hidden = !userCan(user, 'usuarios.crear');
  document.querySelector('#new-role-button').hidden = !userCan(user, 'roles.crear');
  document.querySelector('#new-farm-button').hidden = !userCan(user, 'fincas.crear');
  document.querySelector('#new-property-button').hidden = !userCan(user, 'fincas.crear');
  document.querySelector('#new-input-button').hidden = !userCan(user, 'insumos.crear');
  document.querySelector('#new-formula-button').hidden = !userCan(user, 'formulas.crear');
  document.querySelector('#new-recommendation-button').hidden = !userCan(user, 'recomendaciones.crear');
  document.querySelector('#new-agenda-button').hidden = !userCan(user, 'agenda.crear');
  document.querySelector('#new-crop-button').hidden = !userCan(user, 'cultivos.crear');
  document.querySelector('#new-category-button').hidden = !userCan(user, 'categorias_labor.crear');
  document.querySelector('#new-labor-global-button').hidden = !userCan(user, 'labores.crear');
  document.querySelector('[data-crop-tab="categorias-catalogo"]').hidden = !userCan(user, 'categorias_labor.ver');
  document.querySelector('[data-crop-tab="categorias"]').hidden = !userCan(user, 'categorias_labor.ver');
  document.querySelector('[data-crop-tab="todas"]').hidden = !userCan(user, 'labores.ver');
  document.querySelectorAll('[data-summary-view]').forEach((button) => {
    const target = document.querySelector(`.nav-item[data-view="${button.dataset.summaryView}"]`);
    button.hidden = !target || target.hidden;
  });
}

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  const usuario = document.querySelector('#user').value.trim();
  const psw = document.querySelector('#password').value;
  if (!usuario || !psw) {
    message.textContent = 'Ingresa usuario y contraseña.';
    return;
  }
  button.disabled = true;
  button.firstElementChild.textContent = 'Validando acceso…';
  message.textContent = '';
  try {
    const response = await fetch('api/index.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({controller: 'agronomo', method: 'validLogin', data: {usuario, psw}}),
    });
    const rawResponse = await response.text();
    let payload;
    try {
      payload = JSON.parse(rawResponse);
    } catch (_) {
      throw new Error(`El servidor no entregó una respuesta válida (HTTP ${response.status}).`);
    }
    if (!response.ok || payload.success !== true) throw new Error(payload.message || 'No fue posible iniciar sesión.');
    const user = payload.detail || {};
    sessionStorage.setItem('agronomo_user', JSON.stringify(user));
    continueAfterLogin(user);
  } catch (error) {
    message.textContent = error.message || 'No fue posible conectar con el servidor.';
  } finally {
    button.disabled = false;
    button.firstElementChild.textContent = 'Ingresar a Agrónomo';
  }
});

async function logout() {
  try { await fetch('api/index.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({controller:'agronomo',method:'logoutWeb',data:{}})}); } catch (_) {}
  sessionStorage.removeItem('agronomo_user');
  sessionStorage.removeItem('agronomo_active_view');
  // La clase/atributo que el <script> del <head> agrega en el primer pintado
  // (para saltar directo al dashboard sin el parpadeo del login) fuerzan por
  // CSS que #dashboard-view se vea y #login-view no, sin importar su
  // propiedad .hidden — si no se quitan aquí, cerrar sesión deja el
  // dashboard visible por más que el JS de abajo lo oculte.
  document.documentElement.classList.remove('agronomo-has-session');
  document.documentElement.removeAttribute('data-agronomo-view');
  if (forcedPasswordDialog.open) forcedPasswordDialog.close();
  dashboardView.hidden = true;
  loginView.hidden = false;
  form.reset();
}

document.querySelector('#logout-button').addEventListener('click', logout);
document.querySelector('#forced-password-logout').addEventListener('click', logout);

forcedPasswordDialog.addEventListener('cancel', (event) => event.preventDefault());
forcedPasswordForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  const password = document.querySelector('#forced-password').value;
  const confirmation = document.querySelector('#forced-password-confirm').value;
  const forcedMessage = document.querySelector('#forced-password-message');
  if (password !== confirmation) { forcedMessage.textContent = 'Las contraseñas no coinciden.'; return; }
  if (password.length < 6 || !/[A-Za-z]/.test(password) || !/\d/.test(password)) { forcedMessage.textContent = 'Usa mínimo 6 caracteres, con al menos una letra y un número.'; return; }
  const submitButton = forcedPasswordForm.querySelector('[type="submit"]');
  submitButton.disabled = true;
  forcedMessage.textContent = '';
  try {
    const response = await fetch('api/index.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({controller:'agronomo',method:'changeProvisionalPasswordWeb',data:{password}})});
    const payload = await response.json();
    if (!response.ok || payload.success !== true) throw new Error(payload.message || 'No fue posible actualizar la contraseña.');
    const user = JSON.parse(sessionStorage.getItem('agronomo_user') || '{}');
    user.pass_provi = '0';
    sessionStorage.setItem('agronomo_user', JSON.stringify(user));
    forcedPasswordDialog.close();
    showDashboard(user);
  } catch (error) { forcedMessage.textContent = error.message; }
  finally { submitButton.disabled = false; }
});

sidebarToggle.addEventListener('click', () => {
  setSidebarCollapsed(!dashboard.classList.contains('sidebar-collapsed'));
});

document.querySelectorAll('.nav-item').forEach((item) => item.addEventListener('click', () => {
  document.querySelectorAll('.nav-item').forEach((nav) => nav.classList.remove('active'));
  item.classList.add('active');
  document.querySelector('#section-title').textContent = item.dataset.section;
  document.querySelectorAll('.app-view').forEach((view) => { view.hidden = true; });
  const target = document.querySelector(`#${item.dataset.view || 'summary'}-view`);
  if (target) target.hidden = false;
  if (item.dataset.view) sessionStorage.setItem('agronomo_active_view', item.dataset.view);
  else sessionStorage.removeItem('agronomo_active_view');
  setSidebarCollapsed(true);
}));

document.querySelectorAll('[data-summary-view]').forEach((button) => button.addEventListener('click', () => {
  const target = document.querySelector(`.nav-item[data-view="${button.dataset.summaryView}"]`);
  if (target && !target.hidden) target.click();
}));

document.querySelectorAll('.admin-tab').forEach((tab) => tab.addEventListener('click', () => {
  document.querySelectorAll('.admin-tab').forEach((item) => item.classList.remove('active'));
  tab.classList.add('active');
  document.querySelector('#users-panel').hidden = tab.dataset.adminTab !== 'users';
  document.querySelector('#roles-panel').hidden = tab.dataset.adminTab !== 'roles';
}));

try {
  const storedUser = JSON.parse(sessionStorage.getItem('agronomo_user'));
  if (storedUser && storedUser.id && String(storedUser.pass_provi) !== '1') {
    // Al recargar ya tenemos permisos completos en caché (guardados por
    // refreshUserPermissions en la sesión anterior): se pinta el dashboard
    // de una vez con esos datos en vez de esperar la respuesta del servidor,
    // que es lo que causaba el parpadeo de la pantalla de login en cada
    // recarga. refreshUserPermissions() igual corre después para reconciliar
    // en silencio cualquier cambio de rol/permiso reciente.
    showDashboard(storedUser);
    refreshUserPermissions();
  } else if (storedUser) {
    continueAfterLogin(storedUser);
  }
} catch (_) {
  sessionStorage.removeItem('agronomo_user');
}

setSidebarCollapsed(true);
