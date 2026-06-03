<?php
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/includes/login_manager.php';
require_once __DIR__ . '/includes/consultas_manager.php';
require_once __DIR__ . '/includes/security.php';

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
            echo json_encode(['status'=>'error','mensaje'=>'Sesión expirada. Por favor, inicia sesión nuevamente.']);
            exit;
        }
        
        // Validar CSRF
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (!verify_csrf_token($csrf_token)) {
            echo json_encode(['status'=>'error','mensaje'=>'Error de seguridad']);
            exit;
        }

        $consulta_id = isset($_POST['consulta_id']) ? (int)$_POST['consulta_id'] : 0;
        if ($consulta_id <= 0) {
            echo json_encode(['status'=>'error','mensaje'=>'Consulta no válida']);
            exit;
        }

        $consulta_data = get_consulta_predefinida_por_id($consulta_id);
        if (!$consulta_data) {
            echo json_encode(['status'=>'error','mensaje'=>'Consulta no encontrada']);
            exit;
        }

        $parse = parse_query_select_segura($consulta_data['query'] ?? '');
        if (isset($parse['error'])) {
            echo json_encode(['status'=>'error','mensaje'=>'Consulta no permitida']);
            exit;
        }

        $filtros = $_POST['filtros'] ?? [];
        
        // Ejecutar consulta segura
        $resultado = ejecutar_consulta_segura($parse['tabla'], $parse['columns'], $filtros);
        
        if (isset($resultado['error'])) {
            echo json_encode(['status'=>'error','mensaje'=>$resultado['error']]);
        } else {
            echo json_encode(['status'=>'ok','resultado'=>$resultado], JSON_UNESCAPED_UNICODE);
        }
        exit;

    default:
        echo json_encode(['status'=>'error','mensaje'=>'Acción no válida']);
        exit;
}
?>
