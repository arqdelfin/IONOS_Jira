<?php
/**
 * Cargador de variables de entorno desde archivo .env
 */

function load_env_file($filepath) {
    if (!file_exists($filepath)) {
        throw new Exception("Archivo .env no encontrado: $filepath");
    }

    $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        // Ignorar comentarios
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Procesar variables
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remover comillas si existen
            if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
                (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
                $value = substr($value, 1, -1);
            }

            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

/**
 * Obtiene una variable de entorno
 */
function get_env($key, $default = null) {
    $value = getenv($key);
    return $value !== false ? $value : $default;
}

// Cargar archivo .env al iniciar
$env_file = __DIR__ . '/../.env';
load_env_file($env_file);
?>
