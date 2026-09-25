<?php
require_once __DIR__ . '/login_manager.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/consultas_manager.php';
require_once __DIR__ . '/app_runtime.php';

start_secure_session();

/**
 * Makes a cell safe when the CSV is opened in spreadsheet software.
 *
 * Excel and similar tools can interpret values beginning with a formula prefix
 * as executable formulas. Prefixing the complete value with an apostrophe
 * preserves its visible content while forcing text interpretation.
 */
function csv_safe_cell($value) {
    $value = $value === null ? '' : (string)$value;

    if (preg_match('/^\s*[=+\-@]/u', $value)) {
        return "'" . $value;
    }

    return $value;
}

// Validar sesión y CSRF
if (!validar_sesion()) {
    app_audit_log('csv_export', 'fail', ['reason' => 'session_invalid']);
    app_respond_text_error('Sesión expirada', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { 
    app_audit_log('csv_export', 'fail', ['reason' => 'invalid_method']);
    app_respond_text_error('Método no permitido', 405);
}

// Validar CSRF
$csrf_token = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($csrf_token)) {
    app_audit_log('csv_export', 'fail', ['reason' => 'csrf_invalid']);
    app_respond_text_error('Error de seguridad', 403);
}

$consulta_id = isset($_POST['consulta_id']) ? (int)$_POST['consulta_id'] : 0;
$consulta_nombre = $_POST['consulta_nombre'] ?? 'consulta';

if ($consulta_id <= 0) {
    app_audit_log('csv_export', 'fail', ['reason' => 'consulta_id_invalid']);
    app_respond_text_error('Consulta no valida', 400);
}

$consulta_data = get_consulta_predefinida_por_id($consulta_id);
if (!$consulta_data) {
    app_audit_log('csv_export', 'fail', ['reason' => 'consulta_not_found', 'consulta_id' => $consulta_id]);
    app_respond_text_error('Consulta no encontrada', 404);
}

$validacion_query = validar_consulta_predefinida($consulta_data['query'] ?? '');
if (isset($validacion_query['error'])) {
    app_audit_log('csv_export', 'fail', ['reason' => 'query_policy_denied', 'consulta_id' => $consulta_id]);
    app_respond_text_error('Consulta no permitida', 403);
}

$metadata_query = get_columnas_consulta_predefinida($validacion_query['query']);
if (isset($metadata_query['error'])) {
    app_audit_log('csv_export', 'fail', ['reason' => 'query_metadata_error', 'consulta_id' => $consulta_id]);
    app_respond_text_error('Error en la consulta', 500);
}

$filtros = normalizar_filtros_desde_post($_POST);

$sin_limite = isset($_POST['sin_limite']) && (string)$_POST['sin_limite'] === '1';
if ($sin_limite && (!isset($_POST['confirmar_exportacion_sin_limite']) || (string)$_POST['confirmar_exportacion_sin_limite'] !== '1')) {
    app_audit_log('csv_export', 'fail', ['reason' => 'unlimited_export_not_confirmed', 'consulta_id' => $consulta_id]);
    app_respond_text_error('Confirma la exportación completa antes de continuar', 400);
}
$limite_consulta = $sin_limite ? 0 : 1000;

$resultado = ejecutar_consulta_predefinida($validacion_query['query'], $filtros, $limite_consulta, $metadata_query['columnas']);
if (isset($resultado['error'])) {
    app_audit_log('csv_export', 'fail', ['reason' => 'query_execution_error', 'consulta_id' => $consulta_id]);
    app_respond_text_error('Error en la consulta', 500);
}

app_audit_log('csv_export', 'ok', [
    'consulta_id' => $consulta_id,
    'total' => isset($resultado['total_registros']) ? (int)$resultado['total_registros'] : 0
]);

$consulta_nombre = preg_replace('/[^a-zA-Z0-9_-]/u', '_', $consulta_nombre) ?: 'consulta';
$nombre = $consulta_nombre . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename=\"$nombre\"");

$salida = fopen('php://output', 'w');
fprintf($salida, chr(0xEF).chr(0xBB).chr(0xBF));

$primera = true;
foreach ($resultado['datos'] as $fila) {
    if ($primera) {
        fputcsv($salida, array_map('csv_safe_cell', array_keys($fila)), ';');
        $primera = false;
    }
    fputcsv($salida, array_map('csv_safe_cell', $fila), ';');
}
fclose($salida);
exit;
