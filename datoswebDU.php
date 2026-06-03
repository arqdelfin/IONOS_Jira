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

        // Obtener parámetros de consulta
        $tabla = $_POST['tabla'] ?? '';
        $columns = $_POST['columns'] ?? ['*'];
        $filtros = $_POST['filtros'] ?? [];
        
        // Validar entrada
        if (empty($tabla)) {
            echo json_encode(['status'=>'error','mensaje'=>'Tabla no especificada']);
            exit;
        }
        
        // Ejecutar consulta segura
        $resultado = ejecutar_consulta_segura($tabla, $columns, $filtros);
        
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
