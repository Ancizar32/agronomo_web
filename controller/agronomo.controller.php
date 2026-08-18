<?php
date_default_timezone_set("America/Lima");

use function InduSoft\rlog;
use function InduSoft\service_return;
use function InduSoft\crearSentenciaInsert;
use function InduSoft\crearSentenciaSelect;
use function InduSoft\crearSentenciaUpdate;
use function InduSoft\uniqueId;

class AgronomoController extends ControllerBase
{

    private $model;

    public function __construct()
    {
        parent::__construct();
        $this->model = loadModel('model');
    }

    public function validLogin($data)
    {
        $a = $this->model;

        $where['code'] = $data['psw'] ?? '';
        $where['user'] = $data['usuario'] ?? '';
        $sql_cliente = crearSentenciaSelect(['tabla' => 'user', 'where' => $where]);
        $data_cliente = $a->executeScript(['sql' => $sql_cliente]);
        if (empty($data_cliente)) {
            throw new InduSoft\myException("Usuario o contraseña incorrecto", []);
        }
        $data_cliente = $data_cliente[0];

        service_return(['data' => $data_cliente, 'message' => 'El login se realiza con éxito!']);
    }

    public function validSystem($data)
    {
        $data_cliente = [
            'token' => uniqid(),
        ];

        service_return(['data' => $data_cliente, 'message' => 'El sistema se valida con éxito!']);
    }

    public function cambiarPassword($data)
    {

        $a = $this->model;
        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'user', 'where' => $where]);
        $data_select = $a->executeScript(['sql' => $sql_data]);
        $success = false;
        if (empty($data_select)) {
            $msg = "Usuario no existe o esta incorrecto";
        } else {

            $success = true;
            $data_query['code'] = $data['nueva_pass'];
            $data_query['pass_provi'] = '0';

            $data_query['user_modify'] = $data['id'];
            $data_query['date_modify'] = date("Y-m-d h:i:s");

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'user', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'Password actualizado con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg, 'success' => $success]);
    }

    // =====================================================================
    // GESTIÓN DE TÉCNICOS (Solo Superusuario roll='S')
    // =====================================================================

    public function getTecnicos($data)
    {
        $a = $this->model;
        $sql = "SELECT id, name, `user`, roll, void, mail FROM `user` WHERE roll = 'T' AND void = '1' ORDER BY name ASC";
        $data_load = $a->executeScript(['sql' => $sql]);
        service_return(['data' => $data_load, 'message' => 'Listado de técnicos enviado con éxito!']);
    }

    public function crearTecnico($data)
    {
        $a = $this->model;

        if (empty($data['user']) || empty($data['name'])) {
            service_return(['success' => false, 'data' => [], 'message' => 'Usuario y nombre son requeridos']);
        }

        $where_check['user'] = $data['user'];
        $sql_check = crearSentenciaSelect(['tabla' => 'user', 'where' => $where_check]);
        $existing = $a->executeScript(['sql' => $sql_check]);

        if (!empty($existing)) {
            service_return(['success' => false, 'data' => [], 'message' => 'El nombre de usuario ya existe en el sistema']);
        }

        $temp_pass = $data['code'] ?? substr(md5(uniqid()), 0, 8);

        $new_user = [
            'id'          => uniqueId(),
            'name'        => $data['name'],
            'user'        => $data['user'],
            'code'        => $temp_pass,
            'mail'        => $data['mail'] ?? '',
            'void'        => '1',
            'roll'        => 'T',
            'pass_provi'  => '1',
            'user_crea'   => $data['created_by'] ?? '',
            'date_crea'   => date('Y-m-d H:i:s'),
        ];

        $sql = crearSentenciaInsert(['tabla' => 'user', 'conten' => $new_user]);
        $a->executeScript(['sql' => $sql]);

        $new_user['temp_pass'] = $temp_pass;
        service_return(['data' => $new_user, 'message' => 'Técnico creado con éxito!']);
    }

    public function editarTecnico($data)
    {
        $a = $this->model;

        if (empty($data['id'])) {
            service_return(['success' => false, 'data' => [], 'message' => 'ID del técnico es requerido']);
        }

        $sets = [];
        if (!empty($data['name']))  $sets['name'] = $data['name'];
        if (!empty($data['mail']))  $sets['mail'] = $data['mail'];
        if (isset($data['void']))   $sets['void'] = $data['void'];

        $sets['user_modify'] = $data['modified_by'] ?? '';
        $sets['date_modify']  = date('Y-m-d H:i:s');

        $where['id'] = $data['id'];
        $sql = crearSentenciaUpdate(['tabla' => 'user', 'sets' => $sets, 'where' => $where]);
        $a->executeScript(['sql' => $sql]);

        service_return(['data' => $data['id'], 'message' => 'Técnico actualizado con éxito!']);
    }

    public function asignarFincaTecnico($data)
    {
        $a = $this->model;

        $finca_id   = $data['finca_id'] ?? '';
        $tecnico_id = $data['tecnico_id'] ?? '';

        if (empty($finca_id) || empty($tecnico_id)) {
            service_return(['success' => false, 'data' => [], 'message' => 'Finca y técnico son requeridos']);
        }

        $sets['tecnico_id'] = $tecnico_id;
        $where['id']        = $finca_id;

        $sql = crearSentenciaUpdate(['tabla' => 'fincas', 'sets' => $sets, 'where' => $where]);
        $a->executeScript(['sql' => $sql]);

        service_return(['data' => $finca_id, 'message' => 'Finca asignada al técnico con éxito!']);
    }

    public function getVisitasPorTecnico($data)
    {
        $a = $this->model;
        $roll = $data['roll'] ?? '';

        if ($roll !== 'S') {
            service_return(['success' => false, 'data' => [], 'message' => 'Acceso no autorizado']);
        }

        $sql = "SELECT vt.id, vt.finca_id, vt.fecha, vt.descripcion, vt.observacion,
                       vt.sync, vt.voided, vt.created_at, vt.updated_at, vt.created_by,
                       COALESCE(u.name, vt.created_by) AS tecnico_nombre,
                       u.user AS tecnico_usuario,
                       f.descripcion AS finca_nombre
                FROM visitas_tecnicas vt
                LEFT JOIN `user` u ON vt.created_by = u.id
                LEFT JOIN fincas f ON vt.finca_id = f.id
                WHERE vt.voided = '1'
                ORDER BY u.name ASC, vt.fecha DESC";

        $data_load = $a->executeScript(['sql' => $sql]);
        service_return(['data' => $data_load, 'message' => 'Visitas por técnico enviadas con éxito!']);
    }

    // =====================================================================
    // CONFIGURACIÓN
    // =====================================================================

    public function createConfiguracion($data)
    {

        $a = $this->model;
        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'configuracion_usuario', 'where' => $where]);
        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'configuracion_usuario', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'Las configuraciones creó con éxito!';
        } else {

            $data_query['nombre'] = $data['nombre'];
            $data_query['titulo'] = $data['titulo'];
            $data_query['tarjeta_profesional'] = $data['tarjeta_profesional'];
            $data_query['celular'] = $data['celular'];
            $data_query['firma_base64'] = $data['firma_base64'];

            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'configuracion_usuario', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'Las configuraciones editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function descargarConfiguracion()
    {

        $a = $this->model;
        $where['voided'] = '1';
        $sql_ = crearSentenciaSelect(['tabla' => 'configuracion_usuario', 'where' => $where]);
        $data_load = $a->executeScript(['sql' => $sql_]);

        service_return(['data' => $data_load, 'message' => 'El listado de configuraciones se envia con éxito!']);
    }

    // =====================================================================
    // VISITAS TÉCNICAS
    // =====================================================================

    public function createVisitaDetalleRecomendacion($data)
    {

        $a = $this->model;
        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'visita_detalles_recomendaciones', 'where' => $where]);
        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'visita_detalles_recomendaciones', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'La recomendacion de visita por lote se creó con éxito!';
        } else {

            $data_query['visita_id'] = $data['visita_id'];
            $data_query['visita_detalle_id'] = $data['visita_detalle_id'];
            $data_query['recomendacion_id'] = $data['recomendacion_id'];
            $data_query['texto'] = $data['texto'];

            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'visita_detalles_recomendaciones', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'La recomendacion de visita por lote se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function descargarVisitaDetallesRecomendaciones()
    {

        $a = $this->model;
        $where['voided'] = '1';
        $sql_ = crearSentenciaSelect(['tabla' => 'visita_detalles_recomendaciones', 'where' => $where]);
        $data_load = $a->executeScript(['sql' => $sql_]);

        service_return(['data' => $data_load, 'message' => 'El listado de recomendaciones de visita por lote se envia con éxito!']);
    }

    public function createVisitaDetalleFormula($data)
    {

        $a = $this->model;
        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'visita_detalles_formulas', 'where' => $where]);
        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'visita_detalles_formulas', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'La formula de visita por lote se creó con éxito!';
        } else {

            $data_query['visita_id'] = $data['visita_id'];
            $data_query['visita_detalle_id'] = $data['visita_detalle_id'];
            $data_query['formula_id'] = $data['formula_id'];
            $data_query['group_id'] = $data['group_id'];
            $data_query['insumo_id'] = $data['insumo_id'];
            $data_query['dosis'] = $data['dosis'];
            $data_query['unidad'] = $data['unidad'];
            $data_query['obs_insumo'] = $data['obs_insumo'];
            $data_query['obs_global'] = $data['obs_global'];
            $data_query['es_header'] = $data['es_header'];

            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'visita_detalles_formulas', 'sets' => $data_query, 'where' => $data_where]);

            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'La formula de visita por lote se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    function decodeNewlines($row): array
    {
        foreach ($row as $k => $v) {
            if (is_string($v)) {
                $v = str_replace(["\r\n", "\r"], "\n", $v);
                $v = str_replace('\\n', "\n", $v);
                $row[$k] = $v;
            }
        }
        return $row;
    }

    public function descargarVisitaDetallesFormulas()
    {
        $a = $this->model;
        $where['voided'] = '1';
        $sql_ = crearSentenciaSelect(['tabla' => 'visita_detalles_formulas', 'where' => $where]);
        $data_select = array_map([$this, 'decodeNewlines'], $a->executeScript(['sql' => $sql_]));
        service_return(['data' => $data_select, 'message' => 'El listado de formulas de visita por lote se envia con éxito!']);
    }

    public function createActividad($data)
    {

        $a = $this->model;
        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'visita_detalles_actividades', 'where' => $where]);

        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'visita_detalles_actividades', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'La actividad de visita por lote se creó con éxito!';
        } else {

            $data_query['visita_id'] = $data['visita_id'];
            $data_query['visita_detalle_id'] = $data['visita_detalle_id'];
            $data_query['labor_id'] = $data['labor_id'];
            $data_query['estado'] = $data['estado'];
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'visita_detalles_actividades', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'La actividad de visita por lote se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function descargarActividades()
    {

        $a = $this->model;
        $where['voided'] = '1';
        $sql_ = crearSentenciaSelect(['tabla' => 'visita_detalles_actividades', 'where' => $where]);
        $data_load = $a->executeScript(['sql' => $sql_]);

        service_return(['data' => $data_load, 'message' => 'El listado de actividades de visita por lote se envia con éxito!']);
    }

    public function createHallazgo($data)
    {

        $a = $this->model;
        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'visita_detalles_hallazgos', 'where' => $where]);

        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {

            if (!empty($data['imagen_base64']) && !empty($data['imagen_nombre'])) {
                $base64 = $data['imagen_base64'];
                $nombre = $data['imagen_nombre'];
                $rutaDestino = "uploads/hallazgos/" . $nombre;
                file_put_contents($rutaDestino, base64_decode($base64));

                $data['imagen_path_srv'] = $rutaDestino;
                unset($data['imagen_base64']);
                unset($data['imagen_nombre']);
            }

            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'visita_detalles_hallazgos', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'Hallazgos se creó con éxito!';
        } else {


            if (!empty($data['imagen_base64']) && !empty($data['imagen_nombre'])) {
                $base64 = $data['imagen_base64'];
                $nombre = $data['imagen_nombre'];
                $rutaDestino = "uploads/hallazgos/" . $nombre;
                file_put_contents($rutaDestino, base64_decode($base64));

                $data_query['imagen_path_srv'] = $rutaDestino;
                unset($data['imagen_base64']);
                unset($data['imagen_nombre']);
            }

            $data_query['visita_id'] = $data['visita_id'];
            $data_query['visita_detalle_id'] = $data['visita_detalle_id'];
            $data_query['descripcion'] = $data['descripcion'];
            $data_query['imagen_path'] = $data['imagen_path'];

            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'visita_detalles_hallazgos', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'Hallazgos se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function descargarHallazgos()
    {

        $a = $this->model;
        $where['1'] = '1';
        $sql_ = crearSentenciaSelect(['tabla' => 'visita_detalles_hallazgos', 'where' => $where]);
        $data_load = $a->executeScript(['sql' => $sql_]);
        foreach ($data_load as $key => $value) {
            if (!empty($value['imagen_path_srv']) && file_exists($value['imagen_path_srv'])) {
                $contenido = file_get_contents($value['imagen_path_srv']);
                $data_load[$key]['imagen_base64'] = base64_encode($contenido);
            } else {
                $data_load[$key]['imagen_base64'] = null;
            }
        }
        service_return(['data' => $data_load, 'message' => 'El listado de hallazgos se envia con éxito!']);
    }

    public function createVisitaDetalleLote($data)
    {

        $a = $this->model;
        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'visita_detalles_lote', 'where' => $where]);

        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'visita_detalles_lote', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'El detalle de visita por lote se creó con éxito!';
        } else {

            $data_query['visita_id'] = $data['visita_id'];
            $data_query['lote_id'] = $data['lote_id'];
            $data_query['cultivo_id'] = $data['cultivo_id'];
            $data_query['observacion'] = $data['observacion'];
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'visita_detalles_lote', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'El detalle de visita por lote se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getVisitaDetalleLote()
    {

        $a = $this->model;
        $where['1'] = '1';
        $sql_ = crearSentenciaSelect(['tabla' => 'visita_detalles_lote', 'where' => $where]);
        $data_load = $a->executeScript(['sql' => $sql_]);

        service_return(['data' => $data_load, 'message' => 'El listado de detalle de visita por lote se envia con éxito!']);
    }

    public function createVisita($data)
    {

        $a = $this->model;
        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'visitas_tecnicas', 'where' => $where]);
        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'visitas_tecnicas', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'La visita se creó con éxito!';
        } else {

            $data_query['finca_id'] = $data['finca_id'];
            $data_query['fecha'] = $data['fecha'];
            $data_query['descripcion'] = $data['descripcion'];
            $data_query['observacion'] = $data['observacion'];
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'visitas_tecnicas', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'La visita se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getVisitas($data)
    {
        $a = $this->model;
        $roll       = $data['roll'] ?? '';
        $usuario_id = $data['usuario_id'] ?? '';

        if ($roll === 'S') {
            $where['1'] = '1';
        } else {
            $where['created_by'] = $usuario_id;
        }

        $sql_ = crearSentenciaSelect(['tabla' => 'visitas_tecnicas', 'where' => $where]);
        $data_load = $a->executeScript(['sql' => $sql_]);

        service_return(['data' => $data_load, 'message' => 'El listado de visitas tecnicas se envia con éxito!']);
    }

    // =====================================================================
    // CATÁLOGOS
    // =====================================================================

    public function createRecomendacion($data)
    {

        $a = $this->model;


        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'recomendaciones', 'where' => $where]);

        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'recomendaciones', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'La recomendación se creó con éxito!';
        } else {

            $data_query['descripcion'] = $data['descripcion'];
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'recomendaciones', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'La recomendación se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getRecomendaciones()
    {

        $a = $this->model;
        $where['1'] = '1';
        $sql_ = crearSentenciaSelect(['tabla' => 'recomendaciones', 'where' => $where]);
        $data_load = $a->executeScript(['sql' => $sql_]);

        service_return(['data' => $data_load, 'message' => 'El listado de recomendaciones se envia con éxito!']);
    }

    public function createLabor($data)
    {

        $a = $this->model;


        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'labores', 'where' => $where]);

        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'labores', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'La labor se creó con éxito!';
        } else {

            $data_query['nombre'] = $data['nombre'];
            $data_query['cultivo_id'] = $data['cultivo_id'];
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'labores', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'La labor se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getLabores()
    {

        $a = $this->model;
        $where['1'] = '1';
        $sql_ = crearSentenciaSelect(['tabla' => 'labores', 'where' => $where]);
        $data_load = $a->executeScript(['sql' => $sql_]);

        service_return(['data' => $data_load, 'message' => 'El listado de labores se envia con éxito!']);
    }

    public function createDetalleFormula($data)
    {

        $a = $this->model;

        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'formulas_detalle', 'where' => $where]);
        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'formulas_detalle', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'El detalle formula se creó con éxito!';
        } else {

            $data_query['formula_id'] = $data['formula_id'];
            $data_query['insumo_id'] = $data['insumo_id'];
            $data_query['grupo_id'] = $data['grupo_id'];
            $data_query['dosis'] = $data['dosis'];
            $data_query['observacion'] = $data['observacion'];
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'formulas_detalle', 'sets' => $data_query, 'where' => $data_where]);

            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'El detalle formula se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getDetalleFormula()
    {

        $a = $this->model;
        $where['voided'] = '1';
        $sql_ = crearSentenciaSelect(['tabla' => 'formulas_detalle', 'where' => $where]);
        $data_load = $a->executeScript(['sql' => $sql_]);

        service_return(['data' => $data_load, 'message' => 'El listado de detalle de formula se envia con éxito!']);
    }

    public function createFormula($data)
    {

        $a = $this->model;


        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'formulas', 'where' => $where]);

        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'formulas', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'La formula se creó con éxito!';
        } else {

            $data_query['descripcion'] = $data['descripcion'];
            $data_query['unidad'] = $data['unidad'];
            $data_query['observacion'] = $data['observacion'];
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'formulas', 'sets' => $data_query, 'where' => $data_where]);

            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'La formula se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getFormulas($data)
    {

        $a = $this->model;
        $where['voided'] = '1';
        $sql_ = crearSentenciaSelect(['tabla' => 'formulas', 'where' => $where]);
        $data_load = $a->executeScript(['sql' => $sql_]);

        service_return(['data' => $data_load, 'message' => 'El listado de formulas se envia con éxito!']);
    }

    public function createLote($data)
    {

        $a = $this->model;


        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'lotes', 'where' => $where]);
        $data_select = $a->executeScript(['sql' => $sql_data]);
        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'lotes', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'El lote se creó con éxito!';
        } else {

            $data_query['finca_id'] = $data['finca_id'];
            $data_query['nombre'] = $data['nombre'];
            $data_query['cultivo_id'] = $data['cultivo_id'];
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'lotes', 'sets' => $data_query, 'where' => $data_where]);

            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'El lote se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getLotes($data)
    {

        $a = $this->model;
        $where['1'] = '1';
        $sql_ = crearSentenciaSelect(['tabla' => 'lotes', 'where' => $where]);
        $data_load = $a->executeScript(['sql' => $sql_]);

        service_return(['data' => $data_load, 'message' => 'El listado de lotes se envia con éxito!']);
    }

    public function createFinca($data)
    {

        $a = $this->model;


        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'fincas', 'where' => $where]);

        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'fincas', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'La finca se creó con éxito!';
        } else {

            $data_query['descripcion'] = $data['descripcion'];
            $data_query['tecnico_id']  = $data['tecnico_id'] ?? '';
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'fincas', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'La finca se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getFincas($data)
    {
        $a = $this->model;
        $roll       = $data['roll'] ?? '';
        $usuario_id = $data['usuario_id'] ?? '';

        if ($roll === 'S') {
            $where['1'] = '1';
        } else {
            $where['tecnico_id'] = $usuario_id;
        }

        $sql_ = crearSentenciaSelect(['tabla' => 'fincas', 'where' => $where]);
        $data_load = $a->executeScript(['sql' => $sql_]);

        service_return(['data' => $data_load, 'message' => 'El listado de fincas se envia con éxito!']);
    }

    public function createCultivo($data)
    {

        $a = $this->model;


        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'cultivos', 'where' => $where]);

        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'cultivos', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'El cultivo se creó con éxito!';
        } else {

            $data_query['descripcion'] = $data['descripcion'];
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'cultivos', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'El cultivo se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getCultivos($data)
    {

        $a = $this->model;
        $where['1'] = '1';
        $sql_ = crearSentenciaSelect(['tabla' => 'cultivos', 'where' => $where]);
        $data_load = $a->executeScript(['sql' => $sql_]);

        service_return(['data' => $data_load, 'message' => 'El listado de cultivos se envia con éxito!']);
    }

    public function createInsumo($data)
    {

        $a = $this->model;

        $where['id'] = $data['id'];
        $sql_cliente = crearSentenciaSelect(['tabla' => 'insumos', 'where' => $where]);
        $data_cliente = $a->executeScript(['sql' => $sql_cliente]);

        if (empty($data_cliente)) {

            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'insumos', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'El insumo se creó con éxito!';
        } else {

            $data_query['nombre'] = $data['nombre'];
            $data_query['unidad_medida'] = $data['unidad_medida'];
            $data_query['categoria'] = $data['categoria'];
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'insumos', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'El insumo se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getInsumos($data)
    {

        $a = $this->model;
        $where['1'] = '1';
        $sql_cliente = crearSentenciaSelect(['tabla' => 'insumos', 'where' => $where]);
        $data_cliente = $a->executeScript(['sql' => $sql_cliente]);

        service_return(['data' => $data_cliente, 'message' => 'El listado de insumos se envia con éxito!']);
    }
}
