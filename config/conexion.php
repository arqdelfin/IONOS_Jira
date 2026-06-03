<?php
/**
 * Configuración y conexión a base de datos
 * Las credenciales se cargan desde archivo .env
 */

require_once __DIR__ . '/env_loader.php';

// Configurar niveles de error
$app_debug = get_env('APP_DEBUG', 'false') === 'true';
error_reporting(E_ALL);
ini_set('display_errors', $app_debug ? 1 : 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

// Obtener credenciales desde .env
$host_name = get_env('DB_HOST');
$database  = get_env('DB_NAME');
$user_name = get_env('DB_USER');
$password  = get_env('DB_PASSWORD');

// Validar que las credenciales estén configuradas
if (!$host_name || !$database || !$user_name) {
    error_log("Credenciales de BD no configuradas en .env");
    die("Error de configuración. Por favor, contacta al administrador.");
}

$conn = new mysqli($host_name, $user_name, $password, $database);
if ($conn->connect_error) {
    error_log("Error de conexión a BD: " . $conn->connect_error);
    die("Error de conexión. Por favor, contacta al administrador.");
}

$conn->set_charset("utf8mb4");
$conn->query("SET NAMES 'utf8mb4'");
$conn->query("SET CHARACTER SET 'utf8mb4'");
$conn->query("SET COLLATION_CONNECTION = 'utf8mb4_general_ci'");
