<?php

if (SERVER_NAME == 'localhost') {
    $url_base = $_SERVER['DOCUMENT_ROOT'] . '/../../../../../InduSoft/resources/';
} else {
    $url_base = $_SERVER['DOCUMENT_ROOT'] . '/../../../../../../resources/';
}
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<link rel="shortcut icon" href="<?= $url_base ?>img/logo.ico">
<link rel="stylesheet" href="<?= $url_base ?>lib/css/stilos.css" type="text/css" />

<link href="<?= $url_base ?>lib/jquery-ui/jquery-ui.min.css" rel="stylesheet" />
<link href="<?= $url_base ?>lib/preloader/css/main2.css" rel="stylesheet" type="text/css" />
<link href="<?= $url_base ?>lib/bootstrap-4.5.2/dist/css/bootstrap.min.css" rel="stylesheet"
    type="text/css" />

<link href="<?= $url_base ?>lib/css/jquery.mCustomScrollbar.min.css" rel="stylesheet">
<link href="<?= $url_base ?>lib/loading/css/jquery.loadingModal.css" rel="stylesheet">
<link href="<?= $url_base ?>lib/DataTables/datatables.min.css" rel="stylesheet" type="text/css" />

<link rel="stylesheet" href="<?= $url_base ?>lib/loading/css/jquery.loadingModal.css">
<link rel="stylesheet" href="<?= $url_base ?>lib/bootstrap-tagsinput.css">
<link rel="stylesheet" href="<?= $url_base ?>lib/select2/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="<?= $url_base ?>lib/select2/css/select2-bootstrap.min.css"
    rel="stylesheet" />
<link href="<?= $url_base ?>lib/DataTables/select.dataTables.min.css" rel="stylesheet" />
<link href="<?= $url_base ?>lib/fancytree/src/skin-win8/ui.fancytree.css" rel="stylesheet">
<link rel="stylesheet" href="<?= $url_base ?>lib/contextmenu/dist/jquery.contextMenu.min.css">

<!-- jQuery 2.1.4 -->
<script src="<?= $url_base ?>lib/plugins/jQuery/jQuery-2.1.4.min.js"></script>
<script src="<?= $url_base ?>lib/jquery-ui/jquery-ui.min.js"></script>
<script src="<?= $url_base ?>lib/bootstrap-4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script src="<?= $url_base ?>lib/DataTables/datatables.min.js"></script>
<script src="<?= $url_base ?>lib/DataTables/percent.min.js"></script>
<script src="<?= $url_base ?>lib/select2/js/select2.min.js"></script>
<script src="<?= $url_base ?>lib/loading/js/jquery.loadingModal.js"></script>
<script src="<?= $url_base ?>lib/bootstrap-notify.min.js"></script>
<script src="<?= $url_base ?>lib/sweetalert2.all.js"></script>
<script src="<?= $url_base ?>lib/bootstrap4-input-clearer.min.js"></script>
<script src="<?= $url_base ?>lib/all.min.js"></script>

<script src="<?= $url_base ?>lib/moment.js"></script>

<script src="<?= $url_base ?>lib/fancytree/src/jquery.fancytree.js"></script>
<script src="<?= $url_base ?>lib/fancytree/src/jquery.fancytree.columnview.js"></script>
<script src="<?= $url_base ?>lib/fancytree/src/jquery.fancytree.dnd.js"></script>
<script src="<?= $url_base ?>lib/fancytree/src/jquery.fancytree.table.js"></script>

<script src="<?= $url_base ?>lib/bootstrap-tagsinput.js"></script>
<script src="<?= $url_base ?>lib/js/chart.min.js"></script>

<script src="<?= $url_base ?>lib/js/chart.min.js"></script>

<script src="<?= $url_base ?>lib/amchart/core.js"></script>
<script src="<?= $url_base ?>lib/amchart/charts.js"></script>
<script src="<?= $url_base ?>lib/amchart/themes/animated.js"></script>
<script src="<?= $url_base ?>lib/amchart/themes/material.js"></script>

<script src="<?= $url_base ?>lib/contextmenu/dist/jquery.contextMenu.js"></script>
<script src="<?= $url_base ?>lib/contextmenu/dist/jquery.ui.position.min.js"></script>
<script src="<?= $url_base ?>lib/js/file_download.js"></script>
<script src="<?= $url_base ?>lib/js/jquery.mCustomScrollbar.concat.min.js"></script>


<!-- <script async defer src="https://maps.googleapis.com/maps/api/js?key=AIzaSyBYCPoXMkWuioEx1vv27KexAXJBXocdDwU"></script>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.5.0/font/bootstrap-icons.min.css" crossorigin="anonymous"> -->

<link href="<?= $url_base ?>lib/css/fileinput.css" media="all" rel="stylesheet" type="text/css" />
<script src="<?= $url_base ?>lib/js/fileinput.js?v=1"></script>


<script src="<?= $url_base ?>lib/js/es.js?v=1"></script>

<script src="<?= $url_base ?>lib/geoxml3.js?v=1"></script>

<script src="<?= $url_base ?>lib/js/global2.js?v=<?= date("Ymdhmi") ?>"></script>

<?php
if (file_exists('resources/js/functions.js')) {
    echo '<script src="resources/js/functions.js?v=' . date("Ymdhmi") . '"></script>';
}
if (file_exists('resources/js/redirect.js')) {
    echo '<script src="resources/js/redirect.js?v=' . date("Ymdhmi") . '"></script>';
}
?>
<?php
if (file_exists('resources/css/style.css')) {
    echo '<link rel="stylesheet" href="resources/css/style.css?v=' . date("Ymdhmi") . '">';
}
?>

<title>InduSoft</title>