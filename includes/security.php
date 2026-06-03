<?php
/**
 * Funciones de seguridad para CSRF, tokens, etc.
 */

/**
 * Genera un token CSRF seguro
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Valida un token CSRF
 */
function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Genera token de sesión seguro
 */
function generate_session_token() {
    return bin2hex(random_bytes(32));
}
?>
