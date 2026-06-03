<?php
session_start();
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/app_runtime.php';

/**
 * Configura las opciones de sesión de forma segura
 */
function configure_session_security() {
    session_set_cookie_params([
        'lifetime' => get_env('SESSION_TIMEOUT', 1800),
        'path' => '/',
        'secure' => !get_env('APP_DEBUG', 'false'),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
}

/**
 * Verifica el login de un usuario y crea sesión.
 * Devuelve un array con ['status'=>bool, 'mensaje'=>string].
 */
function verificar_login($usuario, $password) {
    global $conn;

    // Sanitizar entrada
    $usuario = trim($usuario);
    $ip = app_get_client_ip();
    
    // Validar entrada
    if (empty($usuario) || empty($password)) {
        app_audit_log('login', 'fail', ['reason' => 'missing_credentials', 'usuario' => $usuario]);
        return ['status' => false, 'mensaje' => 'Usuario y contraseña son requeridos'];
    }

    // Rate limiting por IP/usuario
    $rate = app_login_rate_limit_check($usuario, $ip);
    if ($rate['blocked']) {
        app_audit_log('login', 'blocked', ['usuario' => $usuario, 'retry_after' => $rate['retry_after']]);
        return ['status' => false, 'mensaje' => 'Demasiados intentos. Intenta de nuevo en unos minutos.'];
    }

    // Usar prepared statement
    $stmt = $conn->prepare("SELECT usuario, nombre, apellidos, password_hash FROM t_usuarios WHERE usuario = ?");
    if (!$stmt) {
        error_log("Error en prepared statement: " . $conn->error);
        app_audit_log('login', 'error', ['reason' => 'db_prepare_error']);
        return ['status' => false, 'mensaje' => 'Error en el sistema. Contacta al administrador.'];
    }

    $stmt->bind_param("s", $usuario);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($fila = $resultado->fetch_assoc()) {
        // Verificar password de forma segura con password_verify()
        if (password_verify($password, $fila['password_hash'])) {
            app_login_rate_limit_reset($usuario, $ip);
            session_regenerate_id(true);
            $_SESSION['usuario'] = $fila['usuario'];
            $_SESSION['nombre']  = $fila['nombre'] . ' ' . $fila['apellidos'];
            $_SESSION['session_token'] = generate_session_token();
            $_SESSION['ultima_actividad'] = time();
            app_audit_log('login', 'ok', ['usuario' => $fila['usuario']]);
            return ['status' => true, 'mensaje' => 'Login correcto'];
        }
    }

    // Si llegamos aquí, login fallido
    $rateFail = app_login_rate_limit_record_failure($usuario, $ip);
    app_audit_log('login', 'fail', ['usuario' => $usuario]);
    if ($rateFail['blocked']) {
        return ['status' => false, 'mensaje' => 'Has agotado los intentos. El acceso se ha bloqueado.'];
    }

    return ['status' => false, 'mensaje' => 'Credenciales incorrectas.'];
}

/**
 * Comprueba si el usuario ya está autenticado.
 */
function usuario_autenticado() {
    return isset($_SESSION['usuario']) && isset($_SESSION['session_token']);
}

/**
 * Valida la sesión del usuario
 */
function validar_sesion() {
    if (!usuario_autenticado()) {
        return false;
    }
    
    // Validar timeout de sesión
    $timeout = get_env('SESSION_TIMEOUT', 1800);
    if (isset($_SESSION['ultima_actividad']) && (time() - $_SESSION['ultima_actividad'] > $timeout)) {
        cerrar_sesion();
        return false;
    }
    
    $_SESSION['ultima_actividad'] = time();
    return true;
}

/**
 * Cierra la sesión del usuario.
 */
function cerrar_sesion() {
    if (isset($_SESSION['usuario'])) {
        app_audit_log('logout', 'ok', ['usuario' => $_SESSION['usuario']]);
    }

    session_unset();
    session_destroy();
}
