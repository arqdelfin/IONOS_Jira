<?php
session_start();
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/login_manager.php';
require_once __DIR__ . '/security.php';

// Validar sesión y CSRF
if (!validar_sesion()) {
    die('Sesión expirada');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { 
    die('Método no permitido'); 
}

// Validar CSRF
$csrf_token = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($csrf_token)) {
    die('Error de seguridad');
}

$query = $_POST['query'] ?? '';
$consulta_nombre = $_POST['consulta_nombre'] ?? 'consulta';

if (!$query) { 
    die('Consulta vacía'); 
}

$resultado = $conn->query($query);
if (!$resultado) { 
    die('Error en la consulta: ' . $conn->error); 
}

$consulta_nombre = preg_replace('/[^a-zA-Z0-9_-]/u', '_', $consulta_nombre) ?: 'consulta';
$nombre = $consulta_nombre . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename=\"$nombre\"");

$salida = fopen('php://output', 'w');
fprintf($salida, chr(0xEF).chr(0xBB).chr(0xBF));

$primera = true;
while ($fila = $resultado->fetch_assoc()) {
    if ($primera) {
        fputcsv($salida, array_keys($fila), ';');
        $primera = false;
    }
    fputcsv($salida, $fila, ';');
}
fclose($salida);
exit;
