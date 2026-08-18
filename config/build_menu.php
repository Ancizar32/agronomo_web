<?php

use function InduSoft\mfex;
use function InduSoft\rlog;
use function InduSoft\crearSentenciaUpdate;
use function InduSoft\service_return;

global $user_id;
global $user_text;

$p = new Global_class();
$urlImage = dirname($_SERVER['SCRIPT_NAME']) . "/../resources/img";


?>

</script>
<style type="text/css">
    #alert_pay {
        margin-bottom: 0px !important;
    }

    .nav-container {
        min-height: 100px;
        z-index: 1000;
    }

    .container {
        max-width: 100%;
        background-color: #F8F9FA;
        z-index: 1000;
    }

    a,
    a:hover,
    a:focus {
        color: inherit;
        text-decoration: none;
        transition: all 0.3s;
    }

    .line {
        width: 100%;
        height: 1px;
        border-bottom: 1px dashed #ddd;
        margin: 40px 0;
    }

    .task_selected {
        background-color: lightblue;
    }

    .task_pointer {
        cursor: pointer;
    }

    #sidebar {
        width: 320px;
        position: fixed;
        top: 0;
        left: -320px;
        height: 100vh;
        z-index: 1070;
        background: #fff;
        color: #000;
        transition: all 0.3s;
        overflow-y: scroll;
        box-shadow: 3px 3px 3px rgba(0, 0, 0, 0.2);
    }

    #sidebar.active {
        left: 0;
    }

    #dismiss {
        width: 35px;
        height: 35px;
        line-height: 35px;
        text-align: center;
        background: #EFEFEF;
        position: absolute;
        border: 1px solid;
        border-radius: 3px;
        top: 10px;
        right: 10px;
        cursor: pointer;
        -webkit-transition: all 0.3s;
        -o-transition: all 0.3s;
        transition: all 0.3s;
        font-family: 'Poppins', sans-serif;
    }

    #dismiss:hover {
        background: #fff;
        color: #000;
    }

    .overlay {
        display: none;
        position: fixed;
        width: 100vw;
        height: 100vh;
        background: rgba(0, 0, 0, 0.7);
        z-index: 1040;
        opacity: 0;
        transition: all 0.5s ease-in-out;
    }

    .overlay.active {
        display: block;
        opacity: 1;
    }

    #sidebar .sidebar-header {
        padding: 10px;
        background: #F2F2F2;
    }

    #sidebar ul.components {
        padding: 20px 0;
        /*border-bottom: 1px solid #CFCFCF;*/
    }

    #sidebar ul p {
        color: #fff;
        padding: 10px;
    }

    #sidebar ul li a {
        padding: 10px;
        font-size: 1.1em;
        display: block;
        border: solid 0.5px;
        border-color: #F5F5F5;
    }

    #sidebar ul li a:hover {
        color: #000;
        background: #E6E5E5;
    }

    #sidebar ul li.active>a,
    a[aria-expanded="true"] {
        color: #000;
        background: #E6E5E5;
    }

    a[data-toggle="collapse"] {
        position: relative;
    }

    ul ul a {
        font-size: 0.9em !important;
        padding-left: 30px !important;
        background: #F5F5F5;
    }

    a.article,
    a.article:hover {
        background: #6d7fcc !important;
        color: #fff !important;
    }

    #content {
        width: 100%;
        padding: 20px;
        min-height: 100vh;
        transition: all 0.3s;
        position: absolute;
        top: 0;
        right: 0;
    }
</style>
<div class="overlay"></div>

<div class="nav-container">
    <nav id="menu" class="navbar navbar-expand-lg fixed-top navbar-light" style="background-color: #F7F7F7;">
        <a class="navbar-brand" href="#" id="sidebarCollapse"><span class="navbar-toggler-icon"></span>
            <img src="../../resources/img/logo.png" width="30" height="30" class="d-inline-block align-top" alt="">
            InduSoft
        </a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent"
            aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <ul class="navbar-nav mr-auto">

                <li class="nav-item active">
                    <span class="nav-link">Usuario:
                        <?php echo $user_text ?>
                    </span>
                </li>

                <li class="nav-item active">
                    <span class="nav-link">Fecha:
                        <?php echo date("Y-m-d"); ?>
                    </span>
                </li>

            </ul>
            <!-- <div id="alert_pay" class="alert alert-warning" role="alert">
                Aviso importante, su licencia se vence el pr&oacute;ximo el 30 de diciembre de 2022.
            </div> -->
        </div>
    </nav>
</div>

<nav id="sidebar">
    <div class="sidebar-header text-center">
        <a href="../start/"><img src="../../resources/img/logo.png" width="60" height="60"
                class="d-inline-block align-top" alt=""></a>
    </div>
    <ul class="list-unstyled components">
        <span class="text-center">
            <h4>Men&uacute;</h4>
        </span>
        <?php
        $menu_res = [
            [
                'main_menu_id' => 'sacale',
                'main_menu_desc' => 'Gestión de Báscula',
                'main_menu_icon' => 'fas fa-truck',
                'code_menu' => 'SCL01',
                'sub_menu' => [
                    [
                        'id' => 'scale_reading',
                        'desc' => 'Registro de báscula',
                        'code_sub_menu' => 'SCL02'
                    ],
                    [
                        'id' => 'driver_truck',
                        'desc' => 'Gestión de Transporte',
                        'code_sub_menu' => 'SCL03'
                    ],
                    [
                        'id' => 'type_scale',
                        'desc' => 'Tipo de operacion',
                        'code_sub_menu' => 'SCL04'
                    ]
                ]
            ],
            [
                'main_menu_id' => 'third',
                'main_menu_desc' => 'Terceros',
                'main_menu_icon' => 'fas fa-address-card',
                'code_menu' => 'THI01',
                'sub_menu' => [
                    [
                        'id' => 'third_party',
                        'desc' => 'Clientes y proveedores',
                        'code_sub_menu' => 'THI02'
                    ],
                    [
                        'id' => 'farm_third',
                        'desc' => 'Origen proveedor',
                        'code_sub_menu' => 'THI03'
                    ]
                ]
            ],
            [
                'main_menu_id' => 'unit_convertion',
                'main_menu_desc' => 'Conversor de unidades',
                'main_menu_icon' => 'fas fa-balance-scale-right',
                'code_menu' => 'UNI01',
                'sub_menu' => [
                    [
                        'id' => 'measurement',
                        'desc' => 'Unidades de medida',
                        'code_sub_menu' => 'UNI02'
                    ]
                ]
            ],
            [
                'main_menu_id' => 'product',
                'main_menu_desc' => 'Productos',
                'main_menu_icon' => 'fas fa-lemon',
                'code_menu' => 'PRD01',
                'sub_menu' => [
                    [
                        'id' => 'inv_group',
                        'desc' => 'Grupos de inventario',
                        'code_sub_menu' => 'PRD02'
                    ],
                    [
                        'id' => 'quality',
                        'desc' => 'Calidades',
                        'code_sub_menu' => 'PRD07'
                    ],
                    [
                        'id' => 'products',
                        'desc' => 'Administración Productos',
                        'code_sub_menu' => 'PRD12'
                    ],
                ]
            ],
            [
                'main_menu_id' => 'warehouse',
                'main_menu_desc' => 'Almacén',
                'main_menu_icon' => 'fas fa-warehouse',
                'code_menu' => 'WRH01',
                'sub_menu' => [
                    [
                        'id' => 'warehouse_admin',
                        'desc' => 'Administración Almacenes',
                        'code_sub_menu' => 'WRH02'
                    ],
                ]
            ],
            [
                'main_menu_id' => 'move_in_out_feedstock',
                'main_menu_desc' => 'Movimiento materia prima',
                'main_menu_icon' => 'fas fa-clipboard-list',
                'code_menu' => 'MMP01',
                'sub_menu' => [
                    [
                        'id' => 'feedstock_in',
                        'desc' => 'Entrada materia prima',
                        'code_sub_menu' => 'MMP02'
                    ],
                    [
                        'id' => 'warehouse_feedstock',
                        'desc' => 'Stock materia prima',
                        'code_sub_menu' => 'MMP09'
                    ],
                    [
                        'id' => 'feedstock_transform',
                        'desc' => 'Transformación materia prima',
                        'code_sub_menu' => 'MMP11'
                    ],
                    [
                        'id' => 'move_transfer_mp',
                        'desc' => 'Traslado materia prima',
                        'code_sub_menu' => 'MMP14000' // No va para tres lomas
                    ],
                    [
                        'id' => 'move_out_fs',
                        'desc' => 'Salida materia prima',
                        'code_sub_menu' => 'MMP17000' // No va para tres lomas
                    ]
                ]
            ],
            [
                'main_menu_id' => 'move_in_out',
                'main_menu_desc' => 'Movimiento prod. terminado',
                'main_menu_icon' => 'fas fa-dolly',
                'code_menu' => 'MPT01',
                'sub_menu' => [
                    [
                        'id' => 'warehouse_stock',
                        'desc' => 'Stock prod. terminado',
                        'code_sub_menu' => 'MPT02'
                    ],
                    // [
                    //     'id' => 'move_transfer',
                    //     'desc' => 'Traslado prod. terminado',
                    //     'code_sub_menu' => 'MPT03'
                    // ],
                    [
                        'id' => 'move_out_fp',
                        'desc' => 'Salida prod. terminado',
                        'code_sub_menu' => 'MPT05'
                    ]
                ]
            ],
            [
                'main_menu_id' => 'process_con',
                'main_menu_desc' => 'Recursos y Procesos',
                'main_menu_icon' => 'fas fa-random',
                'code_menu' => 'RCP01',
                'sub_menu' => [
                    [
                        'id' => 'process_admin',
                        'desc' => 'Administración procesos',
                        'code_sub_menu' => 'RCP02'
                    ],
                    [
                        'id' => 'packing_admin',
                        'desc' => 'Administración recursos',
                        'code_sub_menu' => 'RCP07'
                    ],
                    [
                        'id' => 'packing_move',
                        'desc' => 'Movimiento recursos',
                        'code_sub_menu' => 'RCP12'
                    ],
                ]
            ],
            [
                'main_menu_id' => 'configurations',
                'main_menu_desc' => 'Configuraciones',
                'main_menu_icon' => 'fas fa-tools',
                'code_menu' => 'PRF01',
                'sub_menu' => [
                    [
                        'id' => 'profiles',
                        'desc' => 'Administración perfiles',
                        'code_sub_menu' => 'PRF02'
                    ],
                    [
                        'id' => 'users',
                        'desc' => 'Administración usuarios',
                        'code_sub_menu' => 'PRF03'
                    ],
                ]
            ],
            [
                'main_menu_id' => 'reports',
                'main_menu_desc' => 'Reportes',
                'main_menu_icon' => 'fas fa-chart-bar',
                'code_menu' => 'REP01',
                'sub_menu' => [
                    [
                        'id' => 'excel_reports',
                        'desc' => 'Reportes en excel',
                        'code_sub_menu' => 'REP02'
                    ],
                ]
            ],
        ];
        $build_menu = '';
        $obj = new Global_class();
        foreach ($menu_res as $key => $value) {
            $main_menu_id = empty($value['main_menu_id']) ? '' : $value['main_menu_id'];
            $main_menu_desc = empty($value['main_menu_desc']) ? '' : $value['main_menu_desc'];
            $main_menu_icon = empty($value['main_menu_icon']) ? '' : $value['main_menu_icon'];
            $sub_menu = empty($value['sub_menu']) ? '' : $value['sub_menu'];
            $sec_obj = empty($value['code_menu']) ? '' : $value['code_menu'];
            $valid_item_sec = $obj->validSecurityObject($sec_obj);
            if ($valid_item_sec) {
                $build_menu .= '
                <li>
                <a href="#' . $main_menu_id . '" data-toggle="collapse" aria-expanded="false">
                <i class="' . $main_menu_icon . '"></i><span> ' . $main_menu_desc . '</span></a>
                <ul class="collapse list-unstyled" id="' . $main_menu_id . '">';
                if (!empty($sub_menu)) {
                    foreach ($sub_menu as $key => $value1) {
                        $valid_item_sec_sub = $obj->validSecurityObject($value1['code_sub_menu']);
                        if (!empty($valid_item_sec_sub)) {
                            $build_menu .= '<li><a href="../' . $value1['id'] . '/">' . $value1['desc'] . '</a></li>';
                        }
                    }
                }

                $build_menu .= '</ul>
                </li>';
            }
        }
        echo $build_menu;
        ?>
        <li><a href="../../config/logout.php"><i class="fas fa-door-open"></i> Salir</a></li>
    </ul>
</nav>
<script type="text/javascript">
    $(document).ready(function() {
        $("#sidebar").mCustomScrollbar("scrollTo", "bottom", {
            scrollInertia: 3000
        });

        $('#dismiss, .overlay').on('click', function() {
            $('#sidebar').removeClass('active');
            $('.overlay').removeClass('active');
        });

        $('#sidebarCollapse').on('click', function() {
            $('#sidebar').addClass('active');
            $('.overlay').addClass('active');
            $('.collapse.in').toggleClass('in');
            $('a[aria-expanded=true]').attr('aria-expanded', 'false');
        });
    });
</script>