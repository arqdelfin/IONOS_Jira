<?php
session_start();
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/login_manager.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/consultas_manager.php';
require_once __DIR__ . '/app_runtime.php';

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

$filtro_columna  = $_POST['filtro_columna'] ?? '';
$filtro_operador = $_POST['filtro_operador'] ?? '';
$filtro_valor    = $_POST['filtro_valor'] ?? '';
$filtro_desde    = $_POST['filtro_desde'] ?? '';
$filtro_hasta    = $_POST['filtro_hasta'] ?? '';

if ($consulta_id <= 0) {
    app_audit_log('csv_export', 'fail', ['reason' => 'consulta_id_invalid']);
    app_respond_text_error('Consulta no valida', 400);
}

$consulta_data = get_consulta_predefinida_por_id($consulta_id);
if (!$consulta_data) {
    app_audit_log('csv_export', 'fail', ['reason' => 'consulta_not_found', 'consulta_id' => $consulta_id]);
    app_respond_text_error('Consulta no encontrada', 404);
}

$parse = parse_query_select_segura($consulta_data['query'] ?? '');
if (isset($parse['error'])) {
    app_audit_log('csv_export', 'fail', ['reason' => 'query_policy_denied', 'consulta_id' => $consulta_id]);
    app_respond_text_error('Consulta no permitida', 403);
}

$filtros = [];
if ($filtro_columna !== '' && $filtro_operador !== '') {
    if ($filtro_operador === 'fecha') {
        $filtros[] = [
            'columna' => $filtro_columna,
            'operador' => 'fecha_entre',
            'valor' => '',
            'desde' => $filtro_desde,
            'hasta' => $filtro_hasta
        ];
    } else {
        $filtros[] = [
            'columna' => $filtro_columna,
            'operador' => $filtro_operador,
            'valor' => $filtro_valor
        ];
    }
}

$resultado = ejecutar_consulta_segura($parse['tabla'], $parse['columns'], $filtros);
if (isset($resultado['error'])) {
    app_audit_log('csv_export', 'fail', ['reason' => 'query_execution_error', 'consulta_id' => $consulta_id]);
    app_respond_text_error('Error en la consulta', 500);
}

app_audit_log('csv_export', 'ok', [
    'consulta_id' => $consulta_id,
    'tabla' => $parse['tabla'],
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
        fputcsv($salida, array_keys($fila), ';');
        $primera = false;
    }
    fputcsv($salida, $fila, ';');
}
fclose($salida);
exit;
