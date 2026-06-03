<?php
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/includes/login_manager.php';
require_once __DIR__ . '/includes/consultas_manager.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/app_runtime.php';

// Configurar seguridad de sesión
configure_session_security();

$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';
if ($accion === '') { 
    header('Location: ./public/login.php'); 
    exit; 
}

switch ($accion) {
    case 'login':
        $usuario = trim($_POST['usuario'] ?? '');
        $password = $_POST['password_hash'] ?? '';
        $csrf_token = $_POST['csrf_token'] ?? '';
        
        // Validar CSRF
        if (!verify_csrf_token($csrf_token)) {
            app_audit_log('login', 'fail', ['reason' => 'csrf_invalid']);
            header('Location: ./public/login.php?msg=' . urlencode('Error de seguridad. Intenta de nuevo.'));
            exit;
        }

        // Ejecutar login
        $resultado = verificar_login($usuario, $password);

        if ($resultado['status']) {
            header('Location: ./public/bienvenida.php');
            exit;
        } else {
            header('Location: ./public/login.php?msg=' . urlencode($resultado['mensaje']));
            exit;
        }
        break;

    case 'logout':
        cerrar_sesion();
        header('Location: ./public/login.php');
        exit;

    case 'consulta':
        // Validar sesión
        if (!validar_sesion()) {
            app_audit_log('consulta_api', 'fail', ['reason' => 'session_invalid']);
            app_respond_json_error('Sesión expirada. Por favor, inicia sesión nuevamente.', 401);
        }
        
        // Validar CSRF
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (!verify_csrf_token($csrf_token)) {
            app_audit_log('consulta_api', 'fail', ['reason' => 'csrf_invalid']);
            app_respond_json_error('Error de seguridad', 403);
        }

        $consulta_id = isset($_POST['consulta_id']) ? (int)$_POST['consulta_id'] : 0;
        if ($consulta_id <= 0) {
            app_audit_log('consulta_api', 'fail', ['reason' => 'consulta_id_invalid']);
            app_respond_json_error('Consulta no válida', 400);
        }

        $consulta_data = get_consulta_predefinida_por_id($consulta_id);
        if (!$consulta_data) {
            app_audit_log('consulta_api', 'fail', ['reason' => 'consulta_not_found', 'consulta_id' => $consulta_id]);
            app_respond_json_error('Consulta no encontrada', 404);
        }

        $parse = parse_query_select_segura($consulta_data['query'] ?? '');
        if (isset($parse['error'])) {
            app_audit_log('consulta_api', 'fail', ['reason' => 'query_policy_denied', 'consulta_id' => $consulta_id]);
            app_respond_json_error('Consulta no permitida', 403);
        }

        $filtros = $_POST['filtros'] ?? [];
        
        // Ejecutar consulta segura
        $resultado = ejecutar_consulta_segura($parse['tabla'], $parse['columns'], $filtros);
        
        if (isset($resultado['error'])) {
            app_audit_log('consulta_api', 'fail', ['reason' => 'query_execution_error', 'consulta_id' => $consulta_id]);
            app_respond_json_error($resultado['error'], 500);
        } else {
            app_audit_log('consulta_api', 'ok', [
                'consulta_id' => $consulta_id,
                'tabla' => $parse['tabla'],
                'total' => isset($resultado['total_registros']) ? (int)$resultado['total_registros'] : 0
            ]);
            echo json_encode(['status'=>'ok','resultado'=>$resultado], JSON_UNESCAPED_UNICODE);
        }
        exit;

    default:
        echo json_encode(['status'=>'error','mensaje'=>'Acción no válida']);
        exit;
}
?>
