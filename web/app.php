<?php
// Sin esto, el navegador podía servir app.php desde su caché en un F5
// normal — como el HTML trae el $assetVersion (y por tanto las URLs de JS/CSS
// con ?v=...) ya resuelto en el momento en que se generó, una copia en caché
// del documento apunta a versiones viejas de los assets aunque estos ya
// hayan cambiado en el servidor. Solo Ctrl+Shift+R (que ignora la caché por
// completo) forzaba a pedir un app.php fresco. Estas cabeceras obligan a
// revalidar/repetir la petición en cada carga, así un F5 normal siempre
// trae el HTML —y por tanto las URLs de assets— al día.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
$assetVersion = (string) max(filemtime(dirname(__DIR__) . '/assets/js/app.js'), filemtime(dirname(__DIR__) . '/assets/js/admin.js'), filemtime(dirname(__DIR__) . '/assets/js/farms.js'), filemtime(dirname(__DIR__) . '/assets/js/crops.js'), filemtime(dirname(__DIR__) . '/assets/js/inputs-formulas.js'), filemtime(dirname(__DIR__) . '/assets/js/recommendations.js'), filemtime(dirname(__DIR__) . '/assets/js/certifications.js'), filemtime(dirname(__DIR__) . '/assets/js/visits.js'), filemtime(dirname(__DIR__) . '/assets/js/team.js'), filemtime(dirname(__DIR__) . '/assets/css/app.css'), filemtime(dirname(__DIR__) . '/assets/css/admin.css'), filemtime(dirname(__DIR__) . '/assets/css/inputs-formulas.css'), filemtime(dirname(__DIR__) . '/assets/css/farms.css'), filemtime(dirname(__DIR__) . '/assets/css/farms-scale.css'), filemtime(dirname(__DIR__) . '/assets/css/certifications.css'), filemtime(dirname(__DIR__) . '/assets/css/polish.css'), filemtime(dirname(__DIR__) . '/assets/css/sidebar.css'), filemtime(dirname(__DIR__) . '/assets/css/wizard.css'), filemtime(dirname(__DIR__) . '/assets/css/typography.css'), filemtime(dirname(__DIR__) . '/assets/css/visits.css'), filemtime(dirname(__DIR__) . '/assets/css/team.css'), filemtime(dirname(__DIR__) . '/assets/css/summary.css'), filemtime(dirname(__DIR__) . '/assets/js/profile.js'), filemtime(dirname(__DIR__) . '/assets/css/profile.css'), filemtime(dirname(__DIR__) . '/assets/js/icons.js'), filemtime(dirname(__DIR__) . '/assets/js/visit-report.js'), filemtime(dirname(__DIR__) . '/assets/js/agenda.js'), filemtime(dirname(__DIR__) . '/assets/css/agenda.css'), filemtime(dirname(__DIR__) . '/assets/js/reports-excel.js'), filemtime(dirname(__DIR__) . '/assets/css/build-query.css'), filemtime(dirname(__DIR__) . '/assets/js/build-query.js'), filemtime(dirname(__DIR__) . '/assets/js/notifications.js'), filemtime(dirname(__DIR__) . '/assets/css/notifications.css'));
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173f32">
    <title>AgroSoft Agrónomo</title>
    <script>
        // Se ejecuta de forma síncrona durante el parseo del <head>, antes de que
        // el navegador pinte el <body> — por eso puede decidir "hay sesión" sin
        // esperar a app.js (que es defer y solo corre tras parsear todo el
        // documento). Sin esto, cada recarga pintaba primero el login (estado
        // por defecto del HTML) y recién después el JS lo ocultaba, produciendo
        // el parpadeo de login → módulo en cada F5 con sesión activa.
        (function() {
            try {
                var stored = JSON.parse(sessionStorage.getItem('agronomo_user'));
                if (stored && stored.id && String(stored.pass_provi) !== '1') {
                    document.documentElement.classList.add('agronomo-has-session');
                    // Mismo problema, pero con el módulo: por defecto el HTML deja
                    // visible #summary-view (Resumen). Si veníamos de otro módulo
                    // (agronomo_active_view), se marca aquí para ocultar Resumen desde
                    // ya — app.js restaura el módulo real un instante después.
                    var savedView = sessionStorage.getItem('agronomo_active_view');
                    if (savedView) document.documentElement.setAttribute('data-agronomo-view', savedView);
                }
            } catch (_) {}
        })();
    </script>
    <style>
        html.agronomo-has-session #login-view {
            display: none !important;
        }

        html.agronomo-has-session #dashboard-view[hidden] {
            display: grid !important;
        }

        html[data-agronomo-view] #summary-view {
            display: none !important;
        }

        /* Cubre el reacomodo inicial (iconos SVG sin su tamaño final, tablas
         en placeholder, etc.) con una marca simple mientras app.js termina
         de cargar todos los módulos y restaura la vista guardada. */
        #boot-loader {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 9999;
            align-items: center;
            justify-content: center;
            background: #fff;
        }

        html.agronomo-has-session #boot-loader {
            display: flex;
        }

        #boot-loader[hidden] {
            display: none !important;
        }

        #boot-loader .boot-logo-img {
            width: 260px;
            max-width: 60vw;
            height: auto;
            animation: boot-pulse 1.3s ease-in-out infinite;
        }

        @keyframes boot-pulse {

            0%,
            100% {
                transform: scale(1);
                opacity: .96;
            }

            50% {
                transform: scale(1.06);
                opacity: 1;
            }
        }
    </style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Newsreader:opsz,wght@6..72,500;6..72,600&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="lib/DataTables/datatables.min.css">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= $assetVersion ?>">
    <link rel="stylesheet" href="assets/css/admin.css?v=<?= $assetVersion ?>">
    <link rel="stylesheet" href="assets/css/farms.css?v=<?= $assetVersion ?>">
    <link rel="stylesheet" href="assets/css/polish.css?v=<?= $assetVersion ?>">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= $assetVersion ?>">
    <link rel="stylesheet" href="assets/css/wizard.css?v=<?= $assetVersion ?>">
    <link rel="stylesheet" href="assets/css/inputs-formulas.css?v=<?= $assetVersion ?>">
    <link rel="stylesheet" href="assets/css/certifications.css?v=<?= $assetVersion ?>">
    <link rel="stylesheet" href="lib/select2/css/select2.min.css">
    <link rel="stylesheet" href="assets/css/visits.css?v=<?= $assetVersion ?>">
    <link rel="stylesheet" href="assets/css/team.css?v=<?= $assetVersion ?>">
    <link rel="stylesheet" href="assets/css/farms-scale.css?v=<?= $assetVersion ?>">
    <link rel="stylesheet" href="assets/css/summary.css?v=<?= $assetVersion ?>">
    <link rel="stylesheet" href="assets/css/profile.css?v=<?= $assetVersion ?>">
    <link rel="stylesheet" href="assets/css/agenda.css?v=<?= $assetVersion ?>">
    <link rel="stylesheet" href="assets/css/build-query.css?v=<?= $assetVersion ?>">
    <link rel="stylesheet" href="assets/css/notifications.css?v=<?= $assetVersion ?>">
    <link rel="stylesheet" href="assets/css/typography.css?v=<?= $assetVersion ?>">
    <script src="lib/js/jquery-2.1.4.min.js" defer></script>
    <script src="lib/DataTables/datatables.min.js" defer></script>
    <script src="lib/select2/js/select2.min.js" defer></script>
    <script src="lib/sweetalert2.all.js" defer></script>
    <script src="lib/jspdf.umd.min.js" defer></script>
    <script src="lib/jspdf.plugin.autotable.min.js" defer></script>
    <script src="assets/js/icons.js?v=<?= $assetVersion ?>" defer></script>
    <script src="assets/js/app.js?v=<?= $assetVersion ?>" defer></script>
    <script src="assets/js/admin.js?v=<?= $assetVersion ?>" defer></script>
    <script src="assets/js/farms.js?v=<?= $assetVersion ?>" defer></script>
    <script src="assets/js/crops.js?v=<?= $assetVersion ?>" defer></script>
    <script src="assets/js/inputs-formulas.js?v=<?= $assetVersion ?>" defer></script>
    <script src="assets/js/recommendations.js?v=<?= $assetVersion ?>" defer></script>
    <script src="assets/js/certifications.js?v=<?= $assetVersion ?>" defer></script>
    <script src="assets/js/visits.js?v=<?= $assetVersion ?>" defer></script>
    <script src="assets/js/visit-report.js?v=<?= $assetVersion ?>" defer></script>
    <script src="assets/js/team.js?v=<?= $assetVersion ?>" defer></script>
    <script src="assets/js/profile.js?v=<?= $assetVersion ?>" defer></script>
    <script src="assets/js/agenda.js?v=<?= $assetVersion ?>" defer></script>
    <script src="assets/js/reports-excel.js?v=<?= $assetVersion ?>" defer></script>
    <script src="assets/js/build-query.js?v=<?= $assetVersion ?>" defer></script>
    <script src="assets/js/notifications.js?v=<?= $assetVersion ?>" defer></script>
</head>

<body>
    <svg style="display:none" aria-hidden="true">
        <symbol id="icon-home" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 11.5 12 4l8 7.5" />
            <path d="M6 10v9a1 1 0 0 0 1 1h3v-6h4v6h3a1 1 0 0 0 1-1v-9" />
        </symbol>
        <symbol id="icon-visits" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <rect x="6" y="4" width="12" height="17" rx="2" />
            <path d="M9 4V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1" />
            <path d="m9 13 2.2 2.2L15.5 11" />
        </symbol>
        <symbol id="icon-farms" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 4 4 6v14l5-2 6 2 5-2V4l-5 2-6-2Z" />
            <path d="M9 4v14" />
            <path d="M15 6v14" />
        </symbol>
        <symbol id="icon-crops" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 21v-8" />
            <path d="M12 13C12 8 8 6 4 6c0 5 3 8 8 7Z" />
            <path d="M12 11c0-4 3-6 7-6 0 4-2 7-7 6Z" />
        </symbol>
        <symbol id="icon-team" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="17" cy="8.5" r="2.4" />
            <path d="M15.3 13.4c2.8.4 4.7 2.7 4.7 5.8" />
            <circle cx="9" cy="8.5" r="3.2" />
            <path d="M3.5 20c0-3.6 2.5-6.2 5.5-6.2s5.5 2.6 5.5 6.2" />
        </symbol>
        <symbol id="icon-inputs" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 8 12 4l8 4-8 4-8-4Z" />
            <path d="M4 8v9l8 4 8-4V8" />
            <path d="M12 12v9" />
        </symbol>
        <symbol id="icon-formulas" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 2h6" />
            <path d="M10 2v6.5L4.8 17a2 2 0 0 0 1.7 3h11a2 2 0 0 0 1.7-3L14 8.5V2" />
            <path d="M7.5 14h9" />
        </symbol>
        <symbol id="icon-recommendations" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 18h6" />
            <path d="M10 21h4" />
            <path d="M12 3a6 6 0 0 0-3.5 10.9c.6.4 1 1.1 1 1.9v.2h5v-.2c0-.8.4-1.5 1-1.9A6 6 0 0 0 12 3Z" />
        </symbol>
        <symbol id="icon-admin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="3" />
            <path d="M19.4 13a7.6 7.6 0 0 0 0-2l2-1.6-2-3.4-2.4 1a7.6 7.6 0 0 0-1.7-1l-.4-2.6H9.1l-.4 2.6a7.6 7.6 0 0 0-1.7 1l-2.4-1-2 3.4L4.6 11a7.6 7.6 0 0 0 0 2l-2 1.6 2 3.4 2.4-1c.5.4 1.1.8 1.7 1l.4 2.6h4.8l.4-2.6c.6-.2 1.2-.6 1.7-1l2.4 1 2-3.4-2-1.6Z" />
        </symbol>
        <symbol id="icon-search" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="10.5" cy="10.5" r="6.5" />
            <path d="m20 20-4.8-4.8" />
        </symbol>
        <symbol id="icon-filter" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 6h16" />
            <circle cx="9" cy="6" r="2" fill="currentColor" stroke="none" />
            <path d="M4 12h16" />
            <circle cx="15" cy="12" r="2" fill="currentColor" stroke="none" />
            <path d="M4 18h16" />
            <circle cx="10" cy="18" r="2" fill="currentColor" stroke="none" />
        </symbol>
        <symbol id="icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="m6 6 12 12" />
            <path d="M18 6 6 18" />
        </symbol>
        <symbol id="icon-arrow-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5" />
            <path d="m11 6-6 6 6 6" />
        </symbol>
        <symbol id="icon-arrow-right" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 12h14" />
            <path d="m13 6 6 6-6 6" />
        </symbol>
        <symbol id="icon-logout" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3" />
            <path d="m14 16 4-4-4-4" />
            <path d="M18 12H9" />
        </symbol>
        <symbol id="icon-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="m5 12.5 4.5 4.5L19 7" />
        </symbol>
        <symbol id="icon-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 5v14" />
            <path d="M5 12h14" />
        </symbol>
        <symbol id="icon-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="8.5" />
            <path d="M12 7.5V12l3 2" />
        </symbol>
        <symbol id="icon-document" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M7 3h7l4 4v14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" />
            <path d="M14 3v4h4" />
            <path d="M9 13h6" />
            <path d="M9 17h6" />
        </symbol>
        <symbol id="icon-pin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 21s7-6.5 7-12a7 7 0 1 0-14 0c0 5.5 7 12 7 12Z" />
            <circle cx="12" cy="9" r="2.5" />
        </symbol>
        <symbol id="icon-user" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="8" r="3.6" />
            <path d="M4.5 20c0-4.1 3.4-7 7.5-7s7.5 2.9 7.5 7" />
        </symbol>
        <symbol id="icon-chevron-down" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="m6 9 6 6 6-6" />
        </symbol>
        <symbol id="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" />
            <circle cx="12" cy="12" r="3" />
        </symbol>
        <symbol id="icon-edit" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16v4Z" />
            <path d="m13.8 6.2 4 4" />
        </symbol>
        <symbol id="icon-trash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 7h16" />
            <path d="M9 7V4h6v3" />
            <path d="m6.5 7 .8 14h9.4l.8-14" />
            <path d="M10 11v6M14 11v6" />
        </symbol>
        <symbol id="icon-key" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="8" cy="15" r="4" />
            <path d="m11 12 8-8M16 7l2 2M14 9l2 2" />
        </symbol>
        <symbol id="icon-save" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 3h12l2 2v16H5V3Z" />
            <path d="M8 3v6h8V3M8 21v-7h8v7" />
        </symbol>
        <symbol id="icon-power" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 3v9" />
            <path d="M7.2 5.8a8 8 0 1 0 9.6 0" />
        </symbol>
        <symbol id="icon-alert" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 3 2.8 20h18.4L12 3Z" />
            <path d="M12 9v5" />
            <path d="M12 17.5h.01" />
        </symbol>
        <symbol id="icon-calendar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="5" width="18" height="16" rx="2" />
            <path d="M7 3v4M17 3v4M3 10h18" />
        </symbol>
        <symbol id="icon-leaf" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 4C11 4 5 8 5 15c0 3 2 5 5 5 7 0 10-7 10-16Z" />
            <path d="M4 21c2-6 6-10 12-13" />
        </symbol>
        <symbol id="icon-database" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <ellipse cx="12" cy="5.5" rx="7.5" ry="2.7" />
            <path d="M4.5 5.5v6.5c0 1.5 3.4 2.7 7.5 2.7s7.5-1.2 7.5-2.7V5.5" />
            <path d="M4.5 12v6.5c0 1.5 3.4 2.7 7.5 2.7s7.5-1.2 7.5-2.7V12" />
        </symbol>
        <symbol id="icon-copy" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <rect x="9" y="9" width="12" height="12" rx="2" />
            <path d="M6 15H4.5A1.5 1.5 0 0 1 3 13.5v-9A1.5 1.5 0 0 1 4.5 3h9A1.5 1.5 0 0 1 15 4.5V6" />
        </symbol>
        <symbol id="icon-kebab" viewBox="0 0 24 24" fill="currentColor" stroke="none">
            <circle cx="12" cy="5" r="2" />
            <circle cx="12" cy="12" r="2" />
            <circle cx="12" cy="19" r="2" />
        </symbol>
    </svg>
    <div id="boot-loader" aria-hidden="true"><img src="assets/img/logo.png?v=<?= $assetVersion ?>" alt="" class="boot-logo-img"></div>
    <div class="grain" aria-hidden="true"></div>
    <main id="login-view" class="login-layout">
        <section class="brand-panel">
            <div class="brand-mark"><span class="brand-leaf">A</span><span>AgroSoft <strong>Agrónomo</strong></span></div>
            <div class="brand-copy">
                <p class="eyebrow">Gestión agronómica · web + campo</p>
                <h1>El cultivo habla.<br><em>Ahora queda registrado.</em></h1>
                <p>Centraliza visitas, hallazgos y recomendaciones. Tu equipo conserva el contexto desde el lote hasta la oficina.</p>
            </div>
            <div class="field-note"><span>01</span>
                <p>Una operación conectada para tomar mejores decisiones agrícolas.</p>
            </div>
        </section>
        <section class="access-panel">
            <form id="login-form" class="login-card" novalidate>
                <div class="login-card-heading">
                    <p class="eyebrow">Acceso al sistema</p>
                    <h2 class="login-card-title">Bienvenido de nuevo</h2>
                </div>
                <div class="login-card-logo"><img src="assets/img/logo.png?v=<?= $assetVersion ?>" alt="AgroSoft Agrónomo"></div>
                <p class="muted login-card-caption">Ingresa con las credenciales asignadas por tu organización.</p>
                <label class="field"><span>Usuario</span><input id="user" autocomplete="username" required placeholder="tu.usuario"></label>
                <label class="field"><span>Contraseña</span><input id="password" type="password" autocomplete="current-password" required placeholder="••••••••"></label>
                <p id="login-message" class="form-message" role="alert" aria-live="polite"></p>
                <button id="login-button" class="primary-button" type="submit"><span>Ingresar a Agrónomo</span><span class="icon-inline"><svg class="icon">
                            <use href="#icon-arrow-right"></use>
                        </svg></span></button>
                <p class="privacy-note">Al continuar aceptas la política de tratamiento de datos de tu organización.</p>
            </form>
        </section>
    </main>
    <main id="dashboard-view" class="dashboard" hidden>
        <aside class="sidebar">
            <div class="brand-mark compact"><span class="brand-leaf">A</span><span>AgroSoft<br><strong>Agrónomo</strong></span></div>
            <button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Ocultar menú" aria-expanded="true" title="Ocultar menú"><svg class="icon chevron-left">
                    <use href="#icon-chevron-down"></use>
                </svg></button>
            <nav>
                <button class="nav-item active" data-section="Resumen" title="Resumen"><span><svg class="icon">
                            <use href="#icon-home"></use>
                        </svg></span><span class="nav-text">Resumen</span></button>
                <p class="nav-label">Operación</p>
                <button class="nav-item" data-section="Agendar visitas" data-view="agenda" title="Agendar visitas"><span><svg class="icon">
                            <use href="#icon-calendar"></use>
                        </svg></span><span class="nav-text">Agendar visitas</span></button>
                <button class="nav-item" data-section="Visitas técnicas" data-view="visits" title="Visitas técnicas"><span><svg class="icon">
                            <use href="#icon-visits"></use>
                        </svg></span><span class="nav-text">Visitas técnicas</span></button>
                <button class="nav-item" data-section="Equipo técnico" data-view="team" title="Equipo técnico"><span><svg class="icon">
                            <use href="#icon-team"></use>
                        </svg></span><span class="nav-text">Equipo técnico</span></button>
                <p class="nav-label">Catálogos</p>
                <button class="nav-item" data-section="Fincas y lotes" data-view="farms" title="Fincas y lotes"><span><svg class="icon">
                            <use href="#icon-farms"></use>
                        </svg></span><span class="nav-text">Fincas y lotes</span></button>
                <button class="nav-item" data-section="Cultivos y labores" data-view="crops" title="Cultivos y labores"><span><svg class="icon">
                            <use href="#icon-crops"></use>
                        </svg></span><span class="nav-text">Cultivos y labores</span></button>
                <button class="nav-item" data-section="Insumos" data-view="inputs" title="Insumos"><span><svg class="icon">
                            <use href="#icon-inputs"></use>
                        </svg></span><span class="nav-text">Insumos</span></button>
                <button class="nav-item" data-section="Fórmulas" data-view="formulas" title="Fórmulas"><span><svg class="icon">
                            <use href="#icon-formulas"></use>
                        </svg></span><span class="nav-text">Fórmulas</span></button>
                <button class="nav-item" data-section="Recomendaciones" data-view="recommendations" title="Recomendaciones"><span><svg class="icon">
                            <use href="#icon-recommendations"></use>
                        </svg></span><span class="nav-text">Recomendaciones</span></button>
                <button class="nav-item" data-section="Certificaciones" data-view="certifications" title="Certificaciones"><span><svg class="icon">
                            <use href="#icon-document"></use>
                        </svg></span><span class="nav-text">Certificaciones</span></button>
                <p class="nav-label">Comunicación</p>
                <button class="nav-item" data-section="Notificaciones" data-view="notifications" title="Notificaciones"><span><svg class="icon">
                            <use href="#icon-alert"></use>
                        </svg></span><span class="nav-text">Notificaciones</span></button>
                <p class="nav-label">Reportes</p>
                <button class="nav-item" data-section="Reportes en Excel" data-view="reports-excel" title="Reportes en Excel"><span><svg class="icon">
                            <use href="#icon-document"></use>
                        </svg></span><span class="nav-text">Reportes en Excel</span></button>
                <button class="nav-item" data-section="Build Query" data-view="build-query" title="Build Query"><span><svg class="icon">
                            <use href="#icon-database"></use>
                        </svg></span><span class="nav-text">Build Query</span></button>
                <p class="nav-label">Sistema</p>
                <button class="nav-item" data-section="Administración" data-view="admin" title="Usuarios y roles"><span><svg class="icon">
                            <use href="#icon-admin"></use>
                        </svg></span><span class="nav-text">Usuarios y roles</span></button>
            </nav>
            <button id="logout-button" class="logout-button" title="Cerrar sesión"><span class="nav-text">Cerrar sesión</span><span><svg class="icon">
                        <use href="#icon-logout"></use>
                    </svg></span></button>
        </aside>
        <section class="workspace">
            <header class="topbar">
                <div>
                    <p class="eyebrow">Panel de control</p>
                    <h2 id="section-title">Resumen</h2>
                </div>
                <button id="open-profile-button" class="user-chip" type="button" title="Mi perfil"><span id="user-initial">A</span>
                    <div><strong id="user-name">Administrador</strong><small id="user-role">Equipo agronómico</small></div>
                </button>
            </header>
            <div id="summary-view" class="dashboard-content app-view">
                <section class="summary-hero">
                    <div>
                        <p class="eyebrow light">Pulso de la operación</p>
                        <h1><span id="welcome-greeting">Buenos días</span>, <span id="welcome-name">equipo</span>.</h1>
                        <p>Una lectura clara del territorio, el equipo y el trabajo técnico registrado en campo.</p>
                        <div class="summary-hero-actions"><button type="button" data-summary-view="visits">Revisar visitas <span class="icon-inline"><svg class="icon">
                                        <use href="#icon-arrow-right"></use>
                                    </svg></span></button><button type="button" data-summary-view="farms">Explorar fincas</button></div>
                    </div>
                    <div class="summary-day"><span id="summary-weekday">HOY</span><strong id="summary-day-number">—</strong><small id="summary-month-year">—</small><i></i></div>
                </section>
                <div id="resumen-message" class="module-message" role="status"></div>
                <section class="summary-metrics">
                    <article class="primary">
                        <div><span>Visitas este mes</span><strong id="metric-visitas-mes">—</strong></div><small id="metric-visits-trend">Comparando actividad…</small><i><svg class="icon">
                                <use href="#icon-visits"></use>
                            </svg></i>
                    </article>
                    <article><span>Fincas activas</span><strong id="metric-fincas">—</strong><small id="metric-predios-caption">— predios completos</small></article>
                    <article><span>Área registrada</span><strong id="metric-hectareas">—</strong><small>Hectáreas de operación</small></article>
                    <article><span>Lotes activos</span><strong id="metric-lotes">—</strong><small>Unidades productivas</small></article>
                    <article><span>Equipo técnico</span><strong id="metric-tecnicos">—</strong><small>Usuarios de campo</small></article>
                </section>
                <section class="summary-layout">
                    <article class="summary-activity">
                        <header>
                            <div>
                                <p class="eyebrow">Trabajo de campo</p>
                                <h2>Actividad reciente</h2>
                            </div><button type="button" data-summary-view="visits">Ver todas las visitas <svg class="icon">
                                    <use href="#icon-arrow-right"></use>
                                </svg></button>
                        </header>
                        <ol id="recent-activity-list">
                            <li><span>—</span>
                                <div><strong>Consultando visitas…</strong>
                                    <p>Un momento por favor.</p>
                                </div>
                            </li>
                        </ol>
                    </article>
                    <aside class="summary-side">
                        <section class="summary-coverage">
                            <header>
                                <p class="eyebrow">Cobertura operativa</p>
                                <h2>Territorio organizado</h2>
                            </header>
                            <div class="coverage-item">
                                <div><span>Predios completos</span><strong id="summary-predios-ratio">—</strong></div><progress id="summary-predios-progress" max="100" value="0"></progress>
                            </div>
                            <div class="coverage-item">
                                <div><span>Fincas con responsable</span><strong id="summary-assigned-ratio">—</strong></div><progress id="summary-assigned-progress" max="100" value="0"></progress>
                            </div>
                            <div class="summary-catalog-line"><span><strong id="metric-cultivos">—</strong> cultivos</span><span><strong id="metric-insumos">—</strong> insumos</span><span><strong id="metric-formulas">—</strong> fórmulas</span></div>
                        </section>
                        <section class="summary-shortcuts">
                            <p class="eyebrow">Accesos rápidos</p>
                            <div><button type="button" data-summary-view="farms"><span><svg class="icon">
                                            <use href="#icon-farms"></use>
                                        </svg></span><strong>Fincas y lotes</strong><small>Territorio y asignaciones</small></button><button type="button" data-summary-view="team"><span><svg class="icon">
                                            <use href="#icon-team"></use>
                                        </svg></span><strong>Equipo técnico</strong><small>Cobertura y seguimiento</small></button><button type="button" data-summary-view="inputs"><span><svg class="icon">
                                            <use href="#icon-inputs"></use>
                                        </svg></span><strong>Insumos</strong><small>Catálogo disponible</small></button></div>
                        </section>
                    </aside>
                </section>
                <section class="summary-alerts">
                    <header>
                        <div>
                            <p class="eyebrow">Vigencias documentales</p>
                            <h2>Alertas de predios</h2>
                            <p>Certificaciones, registros y contratos que requieren atención.</p>
                        </div><strong id="summary-alert-count">0 alertas</strong>
                    </header>
                    <div id="summary-alert-list">
                        <div class="summary-alert-empty">Consultando vigencias…</div>
                    </div>
                </section>
            </div>
            <div id="certifications-view" class="dashboard-content app-view" hidden>
                <section class="module-heading certification-heading">
                    <div><p class="eyebrow">Catálogo documental</p><h1>Certificaciones</h1><p>Define los documentos y vigencias que pueden asociarse a cada predio.</p></div>
                    <button id="new-certification-button" class="primary-button compact-action" type="button"><span>+ Nueva certificación</span></button>
                </section>
                <div id="certification-message" class="module-message" role="status"></div>
                <section class="certification-catalog-card">
                    <table id="certifications-table" class="data-table"><thead><tr><th>Certificación</th><th>Código</th><th>Vencimiento</th><th>Predios</th><th>Estado</th><th>Acciones</th></tr></thead><tbody id="certifications-table-body"><tr class="empty-row"><td colspan="6">Consultando certificaciones…</td></tr></tbody></table>
                </section>
            </div>
            <div id="notifications-view" class="dashboard-content app-view" hidden>
                <section class="module-heading notification-heading">
                    <div><p class="eyebrow">Comunicación de campo</p><h1>Avisos al equipo</h1><p>Informa cambios importantes y solicita la actualización de datos en los dispositivos.</p></div>
                    <div class="notification-version"><small>Versión de datos</small><strong id="notification-version">—</strong><span id="notification-version-date">Sin consultar</span></div>
                </section>
                <section class="notification-layout">
                    <form id="notification-form" class="notification-compose">
                        <header><span class="notification-signal"><svg class="icon"><use href="#icon-alert"></use></svg></span><div><p class="eyebrow">Nuevo aviso</p><h2>Enviar al equipo</h2></div></header>
                        <label class="field"><span>Título *</span><input id="notification-title" maxlength="100" required placeholder="Ej. Nuevos cultivos disponibles"></label>
                        <label class="field"><span>Mensaje *</span><textarea id="notification-message" rows="4" maxlength="500" required placeholder="Explica brevemente qué cambió y qué debe hacer el técnico."></textarea></label>
                        <label class="field notification-audience-field"><span>Destinatarios</span><select id="notification-audience"><option value="TODOS">Todos los demás usuarios</option><option value="ROL">Por rol</option><option value="USUARIOS">Usuarios específicos</option></select></label>
                        <div id="notification-targets-wrap" class="field notification-targets" hidden><div class="notification-targets-heading"><span id="notification-targets-label">Seleccionar destinatarios</span><strong id="notification-targets-count">0 seleccionados</strong></div><select id="notification-targets" multiple></select><div class="notification-targets-footer"><small id="notification-targets-help">Busca y selecciona uno o varios destinatarios.</small><span><button id="notification-targets-all" type="button">Seleccionar todos</button><button id="notification-targets-clear" type="button" disabled>Limpiar</button></span></div></div>
                        <label class="notification-check"><input id="notification-requires-update" type="checkbox"><span><strong>Solicitar actualización de datos</strong><small>Crea una nueva versión de catálogos para Mobile.</small></span></label>
                        <label id="notification-required-wrap" class="notification-check danger" hidden><input id="notification-required" type="checkbox"><span><strong>Actualización obligatoria</strong><small>El usuario deberá actualizar antes de continuar.</small></span></label>
                        <button id="notification-send" class="primary-button" type="submit"><span>Enviar notificación</span></button>
                    </form>
                    <section class="notification-history"><header><div><p class="eyebrow">Trazabilidad</p><h2>Envíos recientes</h2></div><div class="notification-history-tools"><label><svg class="icon"><use href="#icon-search"></use></svg><input id="notification-search" type="search" placeholder="Buscar en los envíos…" aria-label="Buscar en los envíos recientes"></label><button id="notification-refresh" class="secondary-button" type="button">Actualizar</button></div></header><div id="notification-list" class="notification-list"><p class="notification-empty">Consultando notificaciones…</p></div></section>
                </section>
            </div>
            <div id="admin-view" class="dashboard-content app-view" hidden>
                <section id="admin-list-heading" class="module-heading">
                    <div>
                        <p class="eyebrow">Control de acceso</p>
                        <h1>Usuarios y roles</h1>
                        <p>Administra quién entra a Agrónomo y qué puede hacer dentro del sistema.</p>
                    </div><button id="new-user-button" class="primary-button compact-action" type="button"><span>+ Nuevo usuario</span></button>
                </section>
                <div id="admin-tabs" class="admin-tabs" role="tablist"><button class="admin-tab active" data-admin-tab="users">Usuarios</button><button class="admin-tab" data-admin-tab="roles">Roles y permisos</button></div>
                <div id="admin-message" class="module-message" role="status"></div>
                <section id="users-panel" class="admin-panel">
                    <div class="data-table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Usuario</th>
                                    <th>Rol</th>
                                    <th>Fincas asignadas</th>
                                    <th>Estado</th>
                                    <th>Último acceso</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="users-table-body">
                                <tr class="empty-row">
                                    <td colspan="7"><span><svg class="icon">
                                                <use href="#icon-team"></use>
                                            </svg></span><strong>Cargando directorio</strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <section id="roles-panel" class="admin-panel" hidden>
                    <div class="roles-toolbar">
                        <div><strong>Perfiles de acceso</strong><small id="roles-caption">Permisos agrupados por módulo y acción.</small></div><button id="new-role-button" class="secondary-button" type="button">+ Nuevo rol</button>
                    </div>
                    <div class="data-table-wrap">
                        <table class="data-table roles-table">
                            <thead>
                                <tr>
                                    <th>Rol</th>
                                    <th>Descripción</th>
                                    <th>Usuarios</th>
                                    <th>Permisos</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="roles-table-body">
                                <tr class="empty-row">
                                    <td colspan="6"><span><svg class="icon">
                                                <use href="#icon-team"></use>
                                            </svg></span><strong>Cargando roles</strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <section id="role-editor-page" class="role-editor-page" hidden>
                    <form id="role-form">
                        <header class="role-page-heading"><button id="back-to-roles" class="role-back" type="button"><svg class="icon">
                                    <use href="#icon-arrow-left"></use>
                                </svg> Volver a roles</button>
                            <div>
                                <p class="eyebrow">Matriz de acceso</p>
                                <h1 id="role-dialog-title">Nuevo rol</h1>
                                <p>Define el alcance del rol por módulo y acción.</p>
                            </div>
                        </header><input id="admin-role-id" type="hidden">
                        <div class="role-editor-layout">
                            <section class="role-data-card"><strong>Datos del rol</strong><label class="field"><span>Nombre *</span><input id="admin-role-name" maxlength="60" required></label><label class="field"><span>Descripción</span><textarea id="admin-role-description" maxlength="255" rows="5"></textarea></label></section>
                            <section class="permission-card">
                                <div class="permission-heading">
                                    <div><strong>Matriz de permisos</strong><small>Módulos en filas y acciones en columnas</small></div>
                                    <div><button id="toggle-all-permissions" class="text-button" type="button">Marcar todos</button><button id="clear-all-permissions" class="text-button" type="button">Desmarcar</button></div>
                                </div>
                                <div id="permission-matrix" class="permission-matrix"></div>
                            </section>
                        </div>
                        <p id="role-dialog-message" class="dialog-message" role="alert"></p>
                        <footer class="role-page-actions"><button class="secondary-button" id="cancel-role-editor" type="button">Cancelar</button><button class="primary-button" type="submit"><span>Guardar rol</span></button></footer>
                    </form>
                </section>
            </div>
            <div id="farms-view" class="dashboard-content app-view" hidden>
                <section id="farms-list-heading" class="module-heading farms-heading">
                    <div>
                        <p class="eyebrow">Estructura agrícola</p>
                        <h1>Fincas y lotes</h1>
                        <p>Organiza el territorio donde ocurre la operación técnica.</p>
                    </div>
                    <div class="heading-actions"><button id="new-farm-button" class="secondary-button"><span>Creación rápida</span></button><button id="new-property-button" class="primary-button compact-action"><span>+ Registrar predio</span></button></div>
                </section>
                <section id="farm-summary" class="farm-summary">
                    <div><span>Fincas registradas</span><strong id="farm-count">—</strong></div>
                    <div><span>Lotes activos</span><strong id="lot-count">—</strong></div>
                    <div><span>Área registrada</span><strong id="area-count">—</strong></div>
                </section>
                <div id="farm-message" class="module-message" role="status"></div>
                <section id="farm-list-panel" class="farm-directory">
                    <div class="farm-directory-toolbar">
                        <div><strong>Directorio de fincas</strong><small>Busca por nombre, ubicación o responsable.</small></div>
                        <div><label>Estado<select id="farm-status-filter">
                                    <option value="">Todos</option>
                                    <option value="Activo">Activas</option>
                                    <option value="Inactivo">Inactivas</option>
                                </select></label><label>Asignación<select id="farm-assignment-filter">
                                    <option value="">Todas</option>
                                    <option value="responsable">Con responsables</option>
                                    <option value="Sin asignar">Sin responsables</option>
                                </select></label></div>
                    </div>
                    <div class="data-table-wrap farm-table-card catalog-table">
                        <table id="farms-table" class="data-table">
                            <thead>
                                <tr>
                                    <th>Finca o predio</th>
                                    <th>Ubicación</th>
                                    <th>Lotes</th>
                                    <th>Área</th>
                                    <th>Responsables</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="farms-table-body">
                                <tr class="empty-row">
                                    <td colspan="7"><strong>Consultando fincas…</strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <section id="farm-detail-panel" class="farm-scale-detail" hidden>
                    <button id="farm-detail-back" class="role-back" type="button"><svg class="icon">
                            <use href="#icon-arrow-left"></use>
                        </svg> Volver al directorio de fincas</button>
                    <div id="farm-detail" class="farm-detail">
                        <p class="loading-state">Consultando lotes…</p>
                    </div>
                </section>
            </div>
            <div id="crops-view" class="dashboard-content app-view" hidden>
                <section class="module-heading farms-heading">
                    <div>
                        <p class="eyebrow">Catálogo agronómico</p>
                        <h1>Cultivos y labores</h1>
                        <p>Administra los cultivos disponibles, sus labores y las categorías que las agrupan.</p>
                    </div>
                    <div class="heading-actions"><button id="new-category-button" class="secondary-button"><span>+ Nueva categoría</span></button><button id="new-labor-global-button" class="secondary-button"><span>+ Nueva labor</span></button><button id="new-crop-button" class="primary-button compact-action"><span>+ Nuevo cultivo</span></button></div>
                </section>
                <div id="crop-tabs" class="admin-tabs" role="tablist"><button class="admin-tab active" data-crop-tab="catalogo">Cultivos</button><button class="admin-tab" data-crop-tab="categorias-catalogo">Categorías</button><button class="admin-tab" data-crop-tab="cultivos">Labores por cultivo</button><button class="admin-tab" data-crop-tab="categorias">Por categoría</button><button class="admin-tab" data-crop-tab="todas">Todas las labores</button></div>
                <div id="crop-message" class="module-message" role="status"></div>
                <section id="catalogo-panel" class="admin-panel">
                    <section class="farm-summary">
                        <div><span>Cultivos registrados</span><strong id="crop-count">—</strong></div>
                        <div><span>Labores registradas</span><strong id="labor-count">—</strong></div>
                        <div><span>Promedio por cultivo</span><strong id="labor-average">—</strong></div>
                    </section>
                    <div class="data-table-wrap labor-catalog-table">
                        <table id="crops-catalog-table" class="data-table">
                            <thead>
                                <tr>
                                    <th>Cultivo</th>
                                    <th>Código</th>
                                    <th>Labores</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="crops-catalog-table-body">
                                <tr class="empty-row">
                                    <td colspan="5"><strong>Cargando cultivos…</strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <section id="categorias-catalogo-panel" class="admin-panel" hidden>
                    <div class="data-table-wrap labor-catalog-table">
                        <table id="categories-catalog-table" class="data-table">
                            <thead>
                                <tr>
                                    <th>Categoría</th>
                                    <th>Código</th>
                                    <th>Labores</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="categories-catalog-table-body">
                                <tr class="empty-row">
                                    <td colspan="5"><strong>Cargando categorías…</strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <section id="cultivos-panel" class="admin-panel" hidden>
                    <div class="data-table-wrap labor-catalog-table">
                        <table id="crop-labors-table" class="data-table">
                            <thead>
                                <tr>
                                    <th>Cultivo</th>
                                    <th>Labor</th>
                                    <th>Categoría</th>
                                    <th>Código</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="crop-labors-table-body">
                                <tr class="empty-row">
                                    <td colspan="6"><strong>Cargando labores por cultivo…</strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <section id="categorias-panel" class="admin-panel" hidden>
                    <div class="data-table-wrap labor-catalog-table">
                        <table id="category-labors-table" class="data-table">
                            <thead>
                                <tr>
                                    <th>Categoría</th>
                                    <th>Labor</th>
                                    <th>Cultivo</th>
                                    <th>Código</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="category-labors-table-body">
                                <tr class="empty-row">
                                    <td colspan="6"><strong>Cargando labores por categoría…</strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <section id="todas-panel" class="admin-panel" hidden>
                    <div class="data-table-wrap labor-catalog-table">
                        <table id="all-labors-table" class="data-table">
                            <thead>
                                <tr>
                                    <th>Labor</th>
                                    <th>Cultivo</th>
                                    <th>Categoría</th>
                                    <th>Código</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="all-labors-table-body">
                                <tr class="empty-row">
                                    <td colspan="6"><strong>Cargando todas las labores…</strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
            <div id="inputs-view" class="dashboard-content app-view" hidden>
                <section class="module-heading">
                    <div>
                        <p class="eyebrow">Catálogo técnico</p>
                        <h1>Insumos agroquímicos</h1>
                        <p>Organiza productos por categoría y unidad para utilizarlos en fórmulas y visitas.</p>
                    </div><button id="new-input-button" class="primary-button compact-action" type="button"><span>+ Nuevo insumo</span></button>
                </section>
                <section class="catalog-metrics">
                    <article><span>Insumos activos</span><strong id="active-input-count">—</strong></article>
                    <article><span>Categorías</span><strong id="input-category-count">—</strong></article>
                    <article><span>En fórmulas</span><strong id="formula-input-count">—</strong></article>
                </section>
                <div class="data-table-wrap catalog-table">
                    <table id="inputs-table" class="data-table">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Insumo</th>
                                <th>Categoría</th>
                                <th>Unidad</th>
                                <th>Fórmulas</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="inputs-table-body"></tbody>
                    </table>
                </div>
            </div>
            <div id="formulas-view" class="dashboard-content app-view" hidden>
                <section id="formula-list-heading" class="module-heading">
                    <div>
                        <p class="eyebrow">Recetario agronómico</p>
                        <h1>Fórmulas</h1>
                        <p>Construye mezclas por grupos de insumos, dosis y unidad de aplicación.</p>
                    </div><button id="new-formula-button" class="primary-button compact-action" type="button"><span>+ Nueva fórmula</span></button>
                </section>
                <section id="formula-list-panel">
                    <div class="data-table-wrap catalog-table">
                        <table id="formulas-table" class="data-table">
                            <thead>
                                <tr>
                                    <th>Fórmula</th>
                                    <th>Unidad</th>
                                    <th>Grupos</th>
                                    <th>Insumos</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="formulas-table-body"></tbody>
                        </table>
                    </div>
                </section>
                <section id="formula-editor" class="formula-editor" hidden>
                    <form id="formula-form">
                        <header><button id="formula-back" type="button"><svg class="icon">
                                    <use href="#icon-arrow-left"></use>
                                </svg> Volver a fórmulas</button>
                            <div>
                                <p class="eyebrow">Composición técnica</p>
                                <h1 id="formula-editor-title">Nueva fórmula</h1>
                                <p>Los insumos dentro de un mismo grupo son alternativas que comparten dosis.</p>
                            </div>
                        </header><input id="formula-id" type="hidden">
                        <div class="formula-layout">
                            <aside><label class="field"><span>Descripción *</span><input id="formula-description" maxlength="255" required></label><label class="field"><span>Unidad por fórmula *</span><input id="formula-unit" maxlength="100" required placeholder="Ej. x 200 L"></label><label class="field"><span>Observaciones</span><textarea id="formula-observation" rows="5"></textarea></label></aside>
                            <main>
                                <div class="formula-groups-heading">
                                    <div><strong>Grupos de insumos</strong><small>Cada grupo requiere dosis y al menos un insumo.</small></div><button id="add-formula-group" type="button">+ Agregar grupo</button>
                                </div>
                                <div id="formula-groups" class="formula-groups"></div>
                            </main>
                        </div>
                        <p id="formula-message" class="dialog-message"></p>
                        <footer><button id="cancel-formula" class="secondary-button" type="button">Cancelar</button><button class="primary-button" type="submit"><span>Guardar fórmula</span></button></footer>
                    </form>
                </section>
            </div>
            <div id="recommendations-view" class="dashboard-content app-view" hidden>
                <section class="module-heading">
                    <div>
                        <p class="eyebrow">Guía técnica</p>
                        <h1>Recomendaciones</h1>
                        <p>Textos base que el equipo técnico reutiliza al registrar visitas.</p>
                    </div><button id="new-recommendation-button" class="primary-button compact-action" type="button"><span>+ Nueva recomendación</span></button>
                </section>
                <div class="data-table-wrap catalog-table">
                    <table id="recommendations-table" class="data-table">
                        <thead>
                            <tr>
                                <th>Recomendación</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="recommendations-table-body"></tbody>
                    </table>
                </div>
            </div>
            <div id="reports-excel-view" class="dashboard-content app-view" hidden>
                <section class="module-heading">
                    <div>
                        <p class="eyebrow">Biblioteca de archivos</p>
                        <h1>Reportes en Excel</h1>
                        <p>Reportes armados por el equipo técnico, disponibles para consultar y descargar.</p>
                    </div><button id="new-report-excel-button" class="primary-button compact-action" type="button"><span>+ Subir reporte</span></button>
                </section>
                <div id="reports-excel-message" class="module-message" role="status"></div>
                <div class="data-table-wrap catalog-table">
                    <table id="reports-excel-table" class="data-table">
                        <thead>
                            <tr>
                                <th>Reporte</th>
                                <th>Archivo</th>
                                <th>Actualizado</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="reports-excel-table-body"></tbody>
                    </table>
                </div>
            </div>
            <div id="build-query-view" class="dashboard-content app-view" hidden>
                <section class="module-heading">
                    <div>
                        <p class="eyebrow">Constructor de reportes</p>
                        <h1>Build Query</h1>
                        <p>Escribe consultas SQL reutilizables y genera enlaces para Excel (Power Query) o para integraciones vía API.</p>
                    </div><button id="new-report-query-button" class="primary-button compact-action" type="button"><span>+ Nueva consulta</span></button>
                </section>
                <div id="build-query-message" class="module-message" role="status"></div>
                <div id="build-query-tabs" class="admin-tabs" role="tablist">
                    <button class="admin-tab active" data-query-tab="queries">Consultas</button>
                    <button class="admin-tab" data-query-tab="clients">Clientes API</button>
                </div>
                <section id="query-list-panel" class="admin-panel">
                    <div class="data-table-wrap catalog-table">
                        <table id="report-queries-table" class="data-table">
                            <thead>
                                <tr>
                                    <th>Consulta</th>
                                    <th>Parámetros</th>
                                    <th>API</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="report-queries-table-body"></tbody>
                        </table>
                    </div>
                </section>
                <section id="clients-list-panel" class="admin-panel" hidden>
                    <div class="table-tools">
                        <div><strong>Clientes de la API</strong><small>Credenciales para consumir reportes habilitados vía JSON.</small></div><button id="new-api-client-button" class="secondary-button" type="button">+ Nuevo cliente</button>
                    </div>
                    <div class="data-table-wrap catalog-table">
                        <table id="api-clients-table" class="data-table">
                            <thead>
                                <tr>
                                    <th>Cliente</th>
                                    <th>Client key</th>
                                    <th>Reportes autorizados</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="api-clients-table-body"></tbody>
                        </table>
                    </div>
                </section>
            </div>
            <div id="team-view" class="dashboard-content app-view" hidden>
                <section id="team-list-heading" class="module-heading">
                    <div>
                        <p class="eyebrow">Operación de campo</p>
                        <h1>Equipo técnico</h1>
                        <p>Supervisa cobertura, asignaciones y actividad registrada por el equipo en campo.</p>
                    </div>
                </section>
                <div id="team-message" class="module-message" role="status"></div>
                <section id="team-list-panel">
                    <section class="team-metrics">
                        <article><span>Técnicos activos</span><strong id="team-active-count">—</strong><small>Con acceso al sistema</small></article>
                        <article><span>Fincas cubiertas</span><strong id="team-covered-farms">—</strong><small id="team-coverage-label">De — fincas activas</small></article>
                        <article><span>Visitas este mes</span><strong id="team-month-visits">—</strong><small>Actividad sincronizada</small></article>
                        <article><span>Visitas históricas</span><strong id="team-total-visits">—</strong><small>Registros activos</small></article>
                    </section>
                    <div class="data-table-wrap team-table-card">
                        <table id="team-table" class="data-table">
                            <thead>
                                <tr>
                                    <th>Técnico</th>
                                    <th>Rol</th>
                                    <th>Fincas</th>
                                    <th>Visitas del mes</th>
                                    <th>Última visita</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="team-table-body">
                                <tr class="empty-row">
                                    <td colspan="7"><strong>Consultando equipo técnico…</strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <section id="team-detail-panel" class="team-detail-page" hidden>
                    <header class="team-detail-heading"><button id="team-back" class="role-back" type="button"><svg class="icon">
                                <use href="#icon-arrow-left"></use>
                            </svg> Volver al equipo</button>
                        <div>
                            <p class="eyebrow">Ficha operativa</p>
                            <h1 id="team-detail-name">Técnico</h1>
                            <p id="team-detail-caption"></p>
                        </div><button id="team-manage-user" class="secondary-button" type="button">Administrar usuario y fincas</button>
                    </header>
                    <section id="team-detail-metrics" class="team-detail-metrics"></section>
                    <div class="team-detail-grid">
                        <section class="team-detail-card">
                            <header>
                                <div>
                                    <p class="eyebrow">Cobertura asignada</p>
                                    <h2>Fincas y predios</h2>
                                </div>
                            </header>
                            <div id="team-detail-farms"></div>
                        </section>
                        <section class="team-detail-card">
                            <header>
                                <div>
                                    <p class="eyebrow">Actividad de campo</p>
                                    <h2>Visitas registradas</h2>
                                </div>
                            </header>
                            <div id="team-detail-visits"></div>
                        </section>
                    </div>
                </section>
            </div>
            <div id="agenda-view" class="dashboard-content app-view" hidden>
                <section class="module-heading">
                    <div>
                        <p class="eyebrow">Planeación de campo</p>
                        <h1>Agendar visitas</h1>
                        <p>Programa, reasigna y sigue el estado de las visitas técnicas planeadas.</p>
                    </div><button id="new-agenda-button" class="primary-button compact-action" type="button"><span>+ Programar visita</span></button>
                </section>
                <div id="agenda-message" class="module-message" role="status"></div>
                <div class="agenda-filter-bar">
                    <label>Finca<select id="agenda-filter-finca">
                            <option value="">Todas las fincas</option>
                        </select></label>
                    <label id="agenda-filter-tecnico-field">Técnico<select id="agenda-filter-tecnico">
                            <option value="">Todos los técnicos</option>
                        </select></label>
                    <label>Estado<select id="agenda-filter-estado">
                            <option value="">Todos los estados</option>
                            <option value="PROGRAMADA">Programada</option>
                            <option value="EN_CURSO">En curso</option>
                            <option value="COMPLETADA">Completada</option>
                            <option value="CANCELADA">Cancelada</option>
                        </select></label>
                    <label>Desde<input id="agenda-filter-from" type="date"></label>
                    <label>Hasta<input id="agenda-filter-to" type="date"></label>
                </div>
                <div class="data-table-wrap catalog-table">
                    <table id="agenda-table" class="data-table">
                        <thead>
                            <tr>
                                <th>Fecha y hora</th>
                                <th>Finca</th>
                                <th>Técnico</th>
                                <th>Objetivo</th>
                                <th>Duración</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="agenda-table-body">
                            <tr class="empty-row">
                                <td colspan="7"><strong>Consultando agenda…</strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div id="visits-view" class="dashboard-content app-view" hidden>
                <section class="module-heading farms-heading visit-heading">
                    <div>
                        <p class="eyebrow">Bitácora de campo</p>
                        <h1>Visitas técnicas</h1>
                        <p>Revisa el trabajo registrado desde la app móvil y encuentra rápidamente qué ocurrió en cada lote.</p>
                    </div>
                    <div class="visit-heading-mark" aria-hidden="true"><span>CAMPO</span><strong><svg class="icon">
                                <use href="#icon-leaf"></use>
                            </svg></strong></div>
                </section>
                <section class="visit-metrics" aria-label="Resumen de visitas">
                    <article><span>Visitas registradas</span><strong id="visit-total-count">—</strong><small>Histórico sincronizado</small></article>
                    <article><span>Este mes</span><strong id="visit-month-count">—</strong><small id="visit-month-label">Actividad reciente</small></article>
                    <article><span>Lotes visitados</span><strong id="visit-lot-count">—</strong><small>En las visitas visibles</small></article>
                    <article><span>Técnicos en campo</span><strong id="visit-tech-count">—</strong><small>Con visitas registradas</small></article>
                </section>
                <div id="visit-message" class="module-message" role="status"></div>
                <section class="visit-browser">
                    <aside class="visit-directory">
                        <header>
                            <div><strong>Registro de visitas</strong><small id="visit-results-count">Consultando información…</small></div>
                        </header>
                        <div class="visit-filters visit-filter-bar"><label class="visit-search"><span><svg class="icon">
                                        <use href="#icon-search"></use>
                                    </svg></span><input id="visit-search" type="search" placeholder="Buscar en las visitas…" aria-label="Buscar visitas"></label>
                            <div class="visit-filter-actions"><button id="visit-open-filters" type="button"><span><svg class="icon">
                                            <use href="#icon-filter"></use>
                                        </svg></span> Filtrar <strong id="visit-active-filter-count" hidden>0</strong></button><button id="visit-clear-all-filters" class="clear" type="button" hidden>Limpiar</button></div>
                        </div>
                        <div id="visit-list" class="visit-list">
                            <p class="loading-state">Consultando visitas…</p>
                        </div>
                    </aside>
                    <div id="visit-detail" class="visit-detail">
                        <div class="visit-empty"><span><svg class="icon">
                                    <use href="#icon-visits"></use>
                                </svg></span>
                            <p class="eyebrow">Detalle de campo</p>
                            <h3>Selecciona una visita</h3>
                            <p>Verás sus lotes, hallazgos, actividades, fórmulas y recomendaciones en una sola lectura.</p>
                        </div>
                    </div>
                </section>
            </div>
        </section>
    </main>
    <dialog id="visit-filter-dialog" class="visit-filter-dialog">
        <form method="dialog">
            <header>
                <div>
                    <p class="eyebrow">Consulta avanzada</p>
                    <h2>Filtrar visitas</h2>
                    <p>Combina criterios operativos y contenido registrado en campo.</p>
                </div><button class="dialog-close" type="button" id="visit-close-filters"><svg class="icon">
                        <use href="#icon-close"></use>
                    </svg></button>
            </header>
            <div class="visit-filter-modal-grid"><label><span>Periodo rápido</span><select id="visit-period-filter">
                        <option value="">Todo el histórico</option>
                        <option value="month">Este mes</option>
                        <option value="90">Últimos 90 días</option>
                        <option value="year">Este año</option>
                    </select></label><label><span>Estado</span><select id="visit-status-filter">
                        <option value="">Todos</option>
                        <option value="1">Activas</option>
                        <option value="0">Anuladas</option>
                    </select></label>
                <div class="visit-date-range wide"><label><span>Desde</span><input id="visit-date-from" type="date" aria-label="Fecha inicial"></label><span class="visit-date-line">→</span><label><span>Hasta</span><input id="visit-date-to" type="date" aria-label="Fecha final"></label><button id="visit-clear-dates" type="button" title="Limpiar rango" aria-label="Limpiar rango de fechas"><svg class="icon">
                            <use href="#icon-close"></use>
                        </svg></button></div><small id="visit-date-error" class="visit-date-error wide" role="alert"></small><label><span>Finca o predio</span><select id="visit-farm-filter">
                        <option value="">Todas las fincas</option>
                    </select></label><label><span>Labor realizada</span><select id="visit-labor-filter">
                        <option value="">Todas las labores</option>
                    </select></label><label><span>Contiene insumo</span><select id="visit-input-filter">
                        <option value="">Cualquier insumo</option>
                    </select></label><label><span>Contiene fórmula</span><select id="visit-formula-filter">
                        <option value="">Cualquier fórmula</option>
                    </select></label><label class="wide"><span>Descripción de la visita contiene</span><input id="visit-description-filter" type="search" placeholder="Ej. seguimiento, control, cosecha…"></label><label class="wide"><span>Observaciones contienen</span><input id="visit-observation-filter" type="search" placeholder="Buscar texto dentro de las observaciones…"></label>
            </div>
            <footer><button id="visit-modal-clear" class="secondary-button" type="button">Limpiar todos</button><span></span><button id="visit-cancel-filters" class="secondary-button" type="button">Cancelar</button><button id="visit-apply-filters" class="primary-button" type="button"><span>Aplicar filtros</span></button></footer>
        </form>
    </dialog>
    <dialog id="farm-dialog" class="entity-dialog assignment-dialog">
        <form id="farm-form">
            <header>
                <div>
                    <p class="eyebrow">Estructura agrícola</p>
                    <h2 id="farm-dialog-title">Nueva finca</h2>
                </div><button class="dialog-close" type="button" data-close-dialog="farm-dialog"><svg class="icon">
                        <use href="#icon-close"></use>
                    </svg></button>
            </header><input id="farm-id" type="hidden"><label class="field"><span>Nombre de la finca *</span><input id="farm-name" maxlength="90" required placeholder="Ej. Hacienda El Porvenir"></label><label class="field"><span>Ubicación</span><input id="farm-location" maxlength="160" placeholder="Municipio, departamento"></label>
            <div class="field farm-user-field assignment-picker">
                <div class="assignment-picker-heading"><span>Usuarios responsables</span><strong id="farm-users-count">0 seleccionados</strong></div>
                <div class="assignment-picker-actions"><button id="farm-users-all" type="button">Asignar todos</button><button id="farm-users-none" type="button">Quitar todos</button></div><select id="farm-users" multiple></select><small>Busca por nombre o usuario y selecciona las personas responsables.</small>
            </div>
            <footer><button class="secondary-button" type="button" data-close-dialog="farm-dialog">Cancelar</button><button class="primary-button" type="submit"><span>Guardar finca</span></button></footer>
        </form>
    </dialog>
    <dialog id="lot-dialog" class="entity-dialog">
        <form id="lot-form">
            <header>
                <div>
                    <p class="eyebrow">Unidad productiva</p>
                    <h2 id="lot-dialog-title">Nuevo lote</h2>
                </div><button class="dialog-close" type="button" data-close-dialog="lot-dialog"><svg class="icon">
                        <use href="#icon-close"></use>
                    </svg></button>
            </header><input id="lot-id" type="hidden"><input id="lot-farm-id" type="hidden"><label class="field"><span>Nombre del lote *</span><input id="lot-name" maxlength="255" required placeholder="Ej. Lote Norte 01"></label><label class="field"><span>Cultivo *</span><select id="lot-crop" required></select></label><label class="field"><span>Área en hectáreas</span><input id="lot-area" type="number" min="0" step="0.01" placeholder="0.00"></label>
            <footer><button class="secondary-button" type="button" data-close-dialog="lot-dialog">Cancelar</button><button class="primary-button" type="submit"><span>Guardar lote</span></button></footer>
        </form>
    </dialog>
    <dialog id="crop-dialog" class="entity-dialog">
        <form id="crop-form">
            <header>
                <div>
                    <p class="eyebrow">Catálogo agronómico</p>
                    <h2 id="crop-dialog-title">Nuevo cultivo</h2>
                </div><button class="dialog-close" type="button" data-close-dialog="crop-dialog"><svg class="icon">
                        <use href="#icon-close"></use>
                    </svg></button>
            </header><input id="crop-id" type="hidden"><label class="field"><span>Nombre del cultivo *</span><input id="crop-name" maxlength="90" required placeholder="Ej. Café"></label><label class="field"><span>Código</span><input id="crop-code" maxlength="50" placeholder="Código en AgroSoft (opcional)"></label>
            <footer><button class="secondary-button" type="button" data-close-dialog="crop-dialog">Cancelar</button><button class="primary-button" type="submit"><span>Guardar cultivo</span></button></footer>
        </form>
    </dialog>
    <dialog id="labor-dialog" class="entity-dialog">
        <form id="labor-form">
            <header>
                <div>
                    <p class="eyebrow">Actividad agronómica</p>
                    <h2 id="labor-dialog-title">Nueva labor</h2>
                </div><button class="dialog-close" type="button" data-close-dialog="labor-dialog"><svg class="icon">
                        <use href="#icon-close"></use>
                    </svg></button>
            </header><input id="labor-id" type="hidden"><label class="field"><span>Cultivo *</span><select id="labor-crop-id" required>
                    <option value="">Selecciona un cultivo</option>
                </select></label><label class="field"><span>Nombre de la labor *</span><input id="labor-name" maxlength="255" required placeholder="Ej. Poda de formación"></label><label class="field"><span>Código</span><input id="labor-code" maxlength="50" placeholder="Código en AgroSoft (opcional)"></label><label class="field"><span>Categoría</span><select id="labor-category">
                    <option value="">Sin categoría</option>
                </select></label>
            <footer><button class="secondary-button" type="button" data-close-dialog="labor-dialog">Cancelar</button><button class="primary-button" type="submit"><span>Guardar labor</span></button></footer>
        </form>
    </dialog>
    <dialog id="category-dialog" class="entity-dialog">
        <form id="category-form">
            <header>
                <div>
                    <p class="eyebrow">Catálogo agronómico</p>
                    <h2 id="category-dialog-title">Nueva categoría de labor</h2>
                </div><button class="dialog-close" type="button" data-close-dialog="category-dialog"><svg class="icon">
                        <use href="#icon-close"></use>
                    </svg></button>
            </header><input id="category-id" type="hidden"><label class="field"><span>Nombre de la categoría *</span><input id="category-name" maxlength="90" required placeholder="Ej. Fitosanitaria"></label><label class="field"><span>Código</span><input id="category-code" maxlength="50" placeholder="Código en AgroSoft (opcional)"></label>
            <footer><button class="secondary-button" type="button" data-close-dialog="category-dialog">Cancelar</button><button class="primary-button" type="submit"><span>Guardar categoría</span></button></footer>
        </form>
    </dialog>
    <dialog id="user-dialog" class="entity-dialog admin-dialog user-editor-dialog">
        <form id="user-form">
            <header>
                <div>
                    <p class="eyebrow">Directorio del equipo</p>
                    <h2 id="user-dialog-title">Nuevo usuario</h2>
                </div><button class="dialog-close" type="button" data-admin-close="user-dialog"><svg class="icon">
                        <use href="#icon-close"></use>
                    </svg></button>
            </header><input id="admin-user-id" type="hidden">
            <div class="admin-form-grid"><label class="field wide"><span>Nombre completo *</span><input id="admin-user-name" maxlength="80" required></label><label class="field"><span>Usuario *</span><input id="admin-user-login" maxlength="20" required pattern="[a-z][a-z0-9.]{2,19}" placeholder="nombre.apellido"></label><label class="field"><span>Correo</span><input id="admin-user-mail" type="email" maxlength="70"></label><label class="field wide"><span>Rol *</span><select id="admin-user-role" required></select></label>
                <section class="wide farm-assignment-table">
                    <div class="assignment-table-heading">
                        <div><span>Fincas o predios asignados</span><small>La selección se conserva al buscar o cambiar de página.</small></div><strong id="admin-user-farms-count">0 de 0 seleccionadas</strong>
                    </div>
                    <div class="assignment-table-toolbar"><label><span><svg class="icon">
                                    <use href="#icon-search"></use>
                                </svg></span><input id="farm-assignment-search" type="search" placeholder="Buscar finca o ubicación…"></label>
                        <div><button id="admin-user-farms-filtered" type="button">Seleccionar resultados</button><button id="admin-user-farms-all" type="button">Todas</button><button id="admin-user-farms-none" type="button">Ninguna</button></div>
                    </div>
                    <div class="assignment-table-wrap">
                        <table id="user-farms-table">
                            <thead>
                                <tr>
                                    <th class="check-column"><input id="farm-assignment-page-check" type="checkbox" aria-label="Seleccionar página visible"></th>
                                    <th>Finca o predio</th>
                                    <th>Ubicación</th>
                                </tr>
                            </thead>
                            <tbody id="user-farms-table-body"></tbody>
                        </table>
                    </div><select id="admin-user-farms" multiple hidden></select>
                </section>
            </div>
            <p class="provisional-note">Al crear la cuenta, Agrónomo generará automáticamente una clave provisional de 4 números.</p>
            <p id="user-dialog-message" class="dialog-message" role="alert"></p>
            <footer><button class="secondary-button" type="button" data-admin-close="user-dialog">Cancelar</button><button class="primary-button" type="submit"><span>Guardar usuario</span></button></footer>
        </form>
    </dialog>
    <dialog id="user-detail-dialog" class="entity-dialog admin-dialog">
        <div class="detail-dialog-shell">
            <header>
                <div>
                    <p class="eyebrow">Ficha de usuario</p>
                    <h2 id="detail-user-name">Usuario</h2>
                </div><button class="dialog-close" type="button" data-admin-close="user-detail-dialog"><svg class="icon">
                        <use href="#icon-close"></use>
                    </svg></button>
            </header>
            <div id="user-detail-content" class="user-detail-content"></div>
            <footer><button class="secondary-button" type="button" data-admin-close="user-detail-dialog">Cerrar</button><button id="detail-edit-user" class="primary-button" type="button"><span>Editar usuario</span></button></footer>
        </div>
    </dialog>
    <dialog id="profile-dialog" class="entity-dialog">
        <form id="profile-form">
            <header>
                <div>
                    <p class="eyebrow">Mi cuenta</p>
                    <h2>Mi perfil</h2>
                </div><button class="dialog-close" type="button" data-close-dialog="profile-dialog"><svg class="icon">
                        <use href="#icon-close"></use>
                    </svg></button>
            </header><label class="field"><span>Nombre completo</span><input id="profile-nombre" maxlength="100" placeholder="Tu nombre completo"></label><label class="field"><span>Título / cargo</span><input id="profile-titulo" maxlength="100" placeholder="Ej. Ingeniero Agrónomo"></label><label class="field"><span>Tarjeta profesional</span><input id="profile-tarjeta" maxlength="50"></label><label class="field"><span>Celular</span><input id="profile-celular" maxlength="20"></label>
            <div class="field"><span>Firma</span>
                <div class="signature-pad-wrap"><canvas id="profile-signature-canvas" width="440" height="160"></canvas></div>
                <div class="signature-pad-actions"><button type="button" class="secondary-button" id="clear-signature-button">Limpiar firma</button><label class="secondary-button file-button">Subir imagen<input type="file" id="signature-upload-input" accept="image/*" hidden></label></div><small class="signature-hint">Dibuja con el mouse o el dedo, o sube una foto de tu firma.</small>
            </div>
            <p id="profile-message" class="dialog-message"></p>
            <footer><button class="secondary-button" type="button" data-close-dialog="profile-dialog">Cancelar</button><button class="primary-button" type="submit"><span>Guardar perfil</span></button></footer>
        </form>
    </dialog>
    <dialog id="forced-password-dialog" class="forced-password-dialog">
        <form id="forced-password-form">
            <div class="security-mark">••••</div>
            <p class="eyebrow">Primer ingreso</p>
            <h2>Crea tu contraseña personal</h2>
            <p class="muted">La clave de 4 números era temporal. Define una nueva para continuar a Agrónomo.</p><label class="field"><span>Nueva contraseña *</span><input id="forced-password" type="password" minlength="6" required autocomplete="new-password" placeholder="Mínimo 6 caracteres"></label><label class="field"><span>Confirmar contraseña *</span><input id="forced-password-confirm" type="password" minlength="6" required autocomplete="new-password"></label>
            <p class="password-hint">Incluye al menos una letra y un número.</p>
            <p id="forced-password-message" class="form-message" role="alert"></p><button class="primary-button" type="submit"><span>Actualizar y continuar</span><span class="icon-inline"><svg class="icon">
                        <use href="#icon-arrow-right"></use>
                    </svg></span></button><button id="forced-password-logout" class="password-logout" type="button">Cerrar sesión</button>
        </form>
    </dialog>
    <dialog id="property-dialog" class="entity-dialog wizard-dialog">
        <form id="property-form" novalidate>
            <header>
                <div>
                    <p class="eyebrow">Registro completo</p>
                    <h2 id="property-dialog-title">Nuevo predio</h2>
                </div><button class="dialog-close" type="button" data-close-dialog="property-dialog"><svg class="icon">
                        <use href="#icon-close"></use>
                    </svg></button>
            </header><input id="property-farm-id" name="finca_id" type="hidden">
            <ol class="wizard-progress" aria-label="Secciones del predio">
                <li class="active" data-property-step="0" role="button" tabindex="0">Productor</li>
                <li data-property-step="1" role="button" tabindex="0">Predio</li>
                <li data-property-step="2" role="button" tabindex="0">Legal</li>
                <li data-property-step="3" role="button" tabindex="0">Certificaciones</li>
            </ol>
            <section class="wizard-step active" data-step="0">
                <div class="form-grid"><label class="field"><span>Tipo productor *</span><select name="tipo" required>
                            <option>TERCERO</option>
                            <option>SOCIO</option>
                            <option>JOIN VENTURE</option>
                        </select></label><label class="field wide"><span>Nombre productor *</span><input name="productor_nombre" required></label><label class="field"><span>Cédula</span><input name="cedula"></label><label class="field"><span>NIT</span><input name="nit"></label><label class="field"><span>DV</span><input name="dv" maxlength="3"></label><label class="field"><span>Teléfono</span><input name="telefono"></label><label class="field wide"><span>Correo</span><input name="correo" type="email"></label></div>
            </section>
            <section class="wizard-step" data-step="1">
                <div class="form-grid"><label class="field wide"><span>Nombre predio *</span><input name="predio_nombre" required></label><label class="field"><span>Departamento *</span><select id="property-department" name="departamento_id" required disabled>
                            <option value="">Cargando departamentos…</option>
                        </select></label><label class="field"><span>Municipio *</span><select id="property-municipality" name="municipio_id" required disabled>
                            <option value="">Primero selecciona un departamento</option>
                        </select></label><label class="field"><span>Vereda o corregimiento</span><select id="property-locality" name="localidad_rural_id" disabled>
                            <option value="">Primero selecciona un municipio</option>
                        </select></label><label class="field"><span>Estado</span><select name="estado">
                            <option>ACTIVO</option>
                            <option>PENDIENTE</option>
                            <option>INACTIVO</option>
                            <option>ERRADICAR</option>
                        </select></label><label class="field"><span>Hectáreas totales</span><input name="hectareas" type="number" min="0" step=".01"></label><label class="field"><span>Latitud</span><input name="latitud" type="number" step=".0000001"></label><label class="field"><span>Longitud</span><input name="longitud" type="number" step=".0000001"></label><label class="field wide"><span>URL localización</span><input name="url" type="url"></label></div>
            </section>
            <section class="wizard-step" data-step="2">
                <div class="legal-sections">
                    <article class="legal-card legal-card-contract">
                        <header class="legal-card-heading">
                            <div><span class="legal-card-index">01</span><div><h3>Contrato de proveeduría</h3><p>Información del acuerdo comercial vigente con el productor.</p></div></div>
                            <label class="legal-card-toggle"><input name="contrato" type="checkbox" data-expiration-toggle="fecha_vencimiento_contrato"><span>Cuenta con contrato</span></label>
                        </header>
                        <div class="legal-card-fields legal-contract-fields"><label class="field"><span>Fecha del contrato</span><input name="fecha_contrato" type="date"></label><label class="field"><span>Fecha de vencimiento *</span><input name="fecha_vencimiento_contrato" type="date" disabled></label><label class="field"><span>Versión del contrato</span><input name="version_contrato"></label></div>
                    </article>
                    <article class="legal-card">
                        <header class="legal-card-heading">
                            <div><span class="legal-card-index">02</span><div><h3>Registro ICA</h3><p>Identificación y vigencia del registro sanitario.</p></div></div>
                            <label class="legal-card-toggle"><input name="ica" type="checkbox" data-expiration-toggle="vencimiento_ica"><span>Tiene registro</span></label>
                        </header>
                        <div class="legal-card-fields"><label class="field"><span>Número ICA</span><input name="numero_ica"></label><label class="field"><span>Fecha de vencimiento *</span><input name="vencimiento_ica" type="date" disabled></label></div>
                    </article>
                    <article class="legal-card">
                        <header class="legal-card-heading">
                            <div><span class="legal-card-index">03</span><div><h3>Anticipo</h3><p>Control del valor anticipado al productor.</p></div></div>
                            <label class="legal-card-toggle"><input name="anticipo" type="checkbox"><span>Recibe anticipo</span></label>
                        </header>
                        <div class="legal-card-fields legal-advance-fields"><label class="field"><span>Valor del anticipo</span><input name="valor_anticipo" type="number" min="0" step=".01"></label></div>
                    </article>
                </div>
                <p class="expiration-help">Las fechas de vencimiento alimentan las alertas preventivas del sistema.</p>
            </section>
            <section class="wizard-step" data-step="3">
                <div class="certification-step-heading"><div><h3>Certificaciones del predio</h3><p>Selecciona únicamente las que aplican y registra su vigencia.</p></div><button id="quick-new-certification-button" class="secondary-button" type="button">+ Nueva certificación</button></div>
                <div id="property-certifications" class="cert-grid"><div class="certification-loading">Cargando catálogo…</div></div>
                <p class="expiration-help">Al marcar una certificación, se solicitará la vigencia cuando ese tipo de documento la requiera.</p>
            </section>
            <footer><button id="wizard-back" class="secondary-button" type="button" hidden>Anterior</button><span class="wizard-spacer"></span><button id="wizard-next" class="primary-button" type="button"><span>Continuar</span></button><button id="wizard-save" class="primary-button" type="submit" hidden><span id="wizard-save-label">Registrar predio</span></button></footer>
        </form>
    </dialog>
    <dialog id="certification-dialog" class="entity-dialog certification-dialog">
        <form id="certification-form">
            <header><div><p class="eyebrow">Catálogo documental</p><h2 id="certification-dialog-title">Nueva certificación</h2></div><button class="dialog-close" type="button" data-close-dialog="certification-dialog"><svg class="icon"><use href="#icon-close"></use></svg></button></header>
            <input id="certification-original-code" type="hidden">
            <label class="field"><span>Nombre de la certificación *</span><input id="certification-name" maxlength="120" required placeholder="Ej. Certificación orgánica"></label>
            <label class="field"><span>Código *</span><input id="certification-code" maxlength="50" required pattern="[A-Z][A-Z0-9_]{1,49}" placeholder="Ej. ORGANICA"></label>
            <label class="field"><span>Descripción</span><textarea id="certification-description" rows="3" maxlength="255" placeholder="Indica qué acredita este documento."></textarea></label>
            <label class="certification-expiration-check"><input id="certification-requires-expiration" type="checkbox" checked><span><strong>Requiere fecha de vencimiento</strong><small>El sistema generará alertas preventivas por su vigencia.</small></span></label>
            <p id="certification-dialog-message" class="dialog-message"></p>
            <footer><button class="secondary-button" type="button" data-close-dialog="certification-dialog">Cancelar</button><button class="primary-button" type="submit"><span>Guardar certificación</span></button></footer>
        </form>
    </dialog>
    <dialog id="recommendation-dialog" class="entity-dialog">
        <form id="recommendation-form">
            <header>
                <div>
                    <p class="eyebrow">Guía técnica</p>
                    <h2 id="recommendation-dialog-title">Nueva recomendación</h2>
                </div><button class="dialog-close" type="button" data-close-dialog="recommendation-dialog"><svg class="icon">
                        <use href="#icon-close"></use>
                    </svg></button>
            </header><input id="recommendation-id" type="hidden"><label class="field"><span>Texto de la recomendación *</span><textarea id="recommendation-description" rows="6" maxlength="2000" required placeholder="Ej. Aplicar fungicida sistémico en dosis de..."></textarea></label>
            <p id="recommendation-message" class="dialog-message"></p>
            <footer><button class="secondary-button" type="button" data-close-dialog="recommendation-dialog">Cancelar</button><button class="primary-button" type="submit"><span>Guardar recomendación</span></button></footer>
        </form>
    </dialog>
    <dialog id="report-excel-dialog" class="entity-dialog">
        <form id="report-excel-form">
            <header>
                <div>
                    <p class="eyebrow">Biblioteca de archivos</p>
                    <h2 id="report-excel-dialog-title">Subir reporte</h2>
                </div><button class="dialog-close" type="button" data-close-dialog="report-excel-dialog"><svg class="icon">
                        <use href="#icon-close"></use>
                    </svg></button>
            </header><input id="report-excel-id" type="hidden">
            <label class="field"><span>Nombre del reporte *</span><input id="report-excel-nombre" maxlength="150" required placeholder="Ej. Consolidado de visitas por finca"></label>
            <label class="field"><span>Descripción</span><textarea id="report-excel-descripcion" rows="3" maxlength="255"></textarea></label>
            <label class="field"><span id="report-excel-archivo-label">Archivo (.xlsx, .xls, .xlsm) *</span><input id="report-excel-archivo" type="file" accept=".xlsx,.xls,.xlsm"></label>
            <p id="report-excel-message" class="dialog-message"></p>
            <footer><button class="secondary-button" type="button" data-close-dialog="report-excel-dialog">Cancelar</button><button class="primary-button" type="submit"><span>Guardar reporte</span></button></footer>
        </form>
    </dialog>
    <dialog id="report-query-dialog" class="entity-dialog build-query-dialog">
        <form id="report-query-form">
            <header>
                <div>
                    <p class="eyebrow">Constructor de reportes</p>
                    <h2 id="report-query-dialog-title">Nueva consulta</h2>
                </div><button class="dialog-close" type="button" data-close-dialog="report-query-dialog"><svg class="icon">
                        <use href="#icon-close"></use>
                    </svg></button>
            </header><input id="report-query-id" type="hidden">
            <label class="field"><span>Descripción *</span><input id="report-query-descripcion" maxlength="150" required placeholder="Ej. Visitas por finca y periodo"></label>
            <label class="field"><span>Consulta SQL * (solo SELECT/WITH)</span><textarea id="report-query-consulta" class="build-query-sql" rows="7" required spellcheck="false" placeholder="SELECT ... FROM fincas WHERE voided = :estado"></textarea></label>
            <label class="field"><span>Parámetros (separados por coma, deben coincidir con los :nombres del SQL)</span><input id="report-query-parametros" maxlength="255" placeholder="Ej. estado, fecha_ini, fecha_fin"></label>
            <div class="build-query-toolbar"><button type="button" id="open-schema-browser-button" class="secondary-button">Ver tablas y columnas</button><button type="button" id="preview-report-query-button" class="secondary-button">Vista previa</button></div>
            <div id="report-query-preview" class="build-query-preview" hidden></div>
            <label class="check-field"><input id="report-query-api-habilitada" type="checkbox"><span>Exponer también como API JSON</span></label>
            <div id="report-query-api-fields" class="admin-form-grid" hidden>
                <label class="field wide"><span>Descripción para la API</span><input id="report-query-api-descripcion" maxlength="255" placeholder="Lo que verá el cliente de la API"></label>
                <label class="field"><span>Máximo de filas</span><input id="report-query-api-max-filas" type="number" min="1" max="10000" value="1000"></label>
            </div>
            <p id="report-query-message" class="dialog-message"></p>
            <footer><button class="secondary-button" type="button" data-close-dialog="report-query-dialog">Cancelar</button><button class="primary-button" type="submit"><span>Guardar consulta</span></button></footer>
        </form>
    </dialog>
    <dialog id="schema-browser-dialog" class="entity-dialog build-query-dialog">
        <div class="detail-dialog-shell">
            <header>
                <div>
                    <p class="eyebrow">Ayuda para escribir SQL</p>
                    <h2>Tablas y columnas</h2>
                </div><button class="dialog-close" type="button" data-close-dialog="schema-browser-dialog"><svg class="icon">
                        <use href="#icon-close"></use>
                    </svg></button>
            </header>
            <label class="field"><span>Buscar tabla</span><input id="schema-search-input" type="search" placeholder="Ej. fincas, visitas…"></label>
            <div class="build-query-schema-layout">
                <div id="schema-tables-list" class="build-query-schema-list"></div>
                <div id="schema-columns-list" class="build-query-schema-list"></div>
            </div>
            <footer><button class="secondary-button" type="button" data-close-dialog="schema-browser-dialog">Cerrar</button></footer>
        </div>
    </dialog>
    <dialog id="report-query-link-dialog" class="entity-dialog">
        <div class="detail-dialog-shell">
            <header>
                <div>
                    <p class="eyebrow">Enlaces generados</p>
                    <h2 id="report-query-link-title">Enlaces del reporte</h2>
                </div><button class="dialog-close" type="button" data-close-dialog="report-query-link-dialog"><svg class="icon">
                        <use href="#icon-close"></use>
                    </svg></button>
            </header>
            <label class="field"><span>Enlace para Excel (Power Query · Desde la Web)</span><textarea id="report-query-link-excel" class="build-query-sql" rows="3" readonly></textarea></label>
            <button type="button" class="secondary-button" data-copy-target="report-query-link-excel">Copiar enlace de Excel</button>
            <div id="report-query-link-api-wrap" hidden>
                <label class="field"><span>Enlace de API (requiere un cliente autorizado, ver pestaña "Clientes API")</span><textarea id="report-query-link-api" class="build-query-sql" rows="3" readonly></textarea></label>
                <button type="button" class="secondary-button" data-copy-target="report-query-link-api">Copiar enlace de API</button>
            </div>
            <p class="build-query-hint">El enlace de Excel pide usuario y contraseña de la app la primera vez que Excel lo consulta. El enlace de API necesita un client key/secret con permiso sobre este reporte.</p>
            <footer><button class="secondary-button" type="button" data-close-dialog="report-query-link-dialog">Cerrar</button></footer>
        </div>
    </dialog>
    <dialog id="api-client-dialog" class="entity-dialog">
        <form id="api-client-form">
            <header>
                <div>
                    <p class="eyebrow">Integraciones externas</p>
                    <h2 id="api-client-dialog-title">Nuevo cliente API</h2>
                </div><button class="dialog-close" type="button" data-close-dialog="api-client-dialog"><svg class="icon">
                        <use href="#icon-close"></use>
                    </svg></button>
            </header><input id="api-client-id" type="hidden">
            <label class="field"><span>Nombre del cliente *</span><input id="api-client-nombre" maxlength="120" required placeholder="Ej. Power BI comercial"></label>
            <label class="field"><span>Notas</span><textarea id="api-client-notas" rows="2" maxlength="255"></textarea></label>
            <div id="api-client-secret-box" class="build-query-secret-box" hidden></div>
            <p id="api-client-message" class="dialog-message"></p>
            <footer><button class="secondary-button" type="button" data-close-dialog="api-client-dialog">Cerrar</button><button class="primary-button" type="submit"><span>Guardar cliente</span></button></footer>
        </form>
    </dialog>
    <dialog id="api-client-reports-dialog" class="entity-dialog">
        <div class="detail-dialog-shell">
            <header>
                <div>
                    <p class="eyebrow">Permisos por cliente</p>
                    <h2 id="api-client-reports-title">Reportes autorizados</h2>
                </div><button class="dialog-close" type="button" data-close-dialog="api-client-reports-dialog"><svg class="icon">
                        <use href="#icon-close"></use>
                    </svg></button>
            </header>
            <div id="api-client-reports-list" class="build-query-schema-list"></div>
            <p id="api-client-reports-message" class="dialog-message"></p>
            <footer><button class="secondary-button" type="button" data-close-dialog="api-client-reports-dialog">Cancelar</button><button id="save-api-client-reports-button" class="primary-button" type="button"><span>Guardar permisos</span></button></footer>
        </div>
    </dialog>
    <dialog id="agenda-dialog" class="entity-dialog agenda-dialog">
        <form id="agenda-form">
            <header>
                <div>
                    <p class="eyebrow">Agenda operativa</p>
                    <h2 id="agenda-dialog-title">Programar visita</h2>
                </div><button class="dialog-close" type="button" data-close-dialog="agenda-dialog"><svg class="icon">
                        <use href="#icon-close"></use>
                    </svg></button>
            </header><input id="agenda-id" type="hidden">
            <div class="admin-form-grid"><label class="field"><span>Finca *</span><select id="agenda-finca" required></select></label><label class="field" id="agenda-tecnico-field"><span>Técnico *</span><select id="agenda-tecnico" required></select></label><label class="field"><span>Fecha y hora *</span><input id="agenda-fecha" type="datetime-local" required></label><label class="field"><span>Duración (min)</span><input id="agenda-duracion" type="number" min="15" step="5" value="60"></label><label class="field wide"><span>Objetivo *</span><input id="agenda-objetivo" maxlength="255" required placeholder="Ej. Seguimiento a control fitosanitario"></label><label class="field wide"><span>Observaciones</span><textarea id="agenda-observacion" rows="3" maxlength="500"></textarea></label><label class="field wide" id="agenda-estado-field" hidden><span>Estado</span><select id="agenda-estado">
                        <option value="PROGRAMADA">Programada</option>
                        <option value="EN_CURSO">En curso</option>
                        <option value="COMPLETADA">Completada</option>
                        <option value="CANCELADA">Cancelada</option>
                    </select></label></div>
            <p id="agenda-dialog-message" class="dialog-message"></p>
            <footer><button class="secondary-button" type="button" data-close-dialog="agenda-dialog">Cancelar</button><button class="primary-button" type="submit"><span>Guardar programación</span></button></footer>
        </form>
    </dialog>
    <dialog id="input-dialog" class="entity-dialog">
        <form id="input-form">
            <header>
                <div>
                    <p class="eyebrow">Catálogo técnico</p>
                    <h2 id="input-dialog-title">Nuevo insumo</h2>
                </div><button class="dialog-close" type="button" data-catalog-close="input-dialog"><svg class="icon">
                        <use href="#icon-close"></use>
                    </svg></button>
            </header><input id="input-original-id" type="hidden"><label class="field"><span>Código *</span><input id="input-id" maxlength="6" required pattern="[A-Za-z0-9]{1,6}" placeholder="Ej. FUN001"></label><label class="field"><span>Nombre *</span><input id="input-name" maxlength="30" required></label><label class="field"><span>Unidad de medida *</span><select id="input-unit" required>
                    <option value="">Selecciona una unidad</option>
                    <option>Kg</option>
                    <option>Gr</option>
                    <option>Lt</option>
                    <option>Cc</option>
                    <option>Und</option>
                </select></label><label class="field"><span>Categoría *</span><input id="input-category" list="input-categories" maxlength="100" required></label><datalist id="input-categories"></datalist>
            <p class="edit-warning" id="input-edit-warning" hidden>Modificar el nombre o la unidad afecta su visualización histórica en fórmulas y visitas.</p>
            <p id="input-message" class="dialog-message"></p>
            <footer><button class="secondary-button" type="button" data-catalog-close="input-dialog">Cancelar</button><button class="primary-button" type="submit"><span>Guardar insumo</span></button></footer>
        </form>
    </dialog>
</body>

</html>
