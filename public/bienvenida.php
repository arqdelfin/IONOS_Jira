<?php
session_start();
require_once __DIR__ . '/../includes/login_manager.php';
require_once __DIR__ . '/../includes/security.php';

// Validar sesión
if (!validar_sesion()) { 
    header('Location: ./login.php'); 
    exit; 
}

require_once __DIR__ . '/../config/conexion.php';

$result = $conn->query("SELECT id, consulta FROM t_consultasweb ORDER BY consulta ASC");
$consultas = [];
while ($row = $result->fetch_assoc()) { $consultas[] = $row; }

$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Bienvenido</title>
<link rel="stylesheet" href="estilos.css">
</head>
<body>

<?php include __DIR__ . '/../includes/header.php'; ?>

<h2 style="margin-top:80px;padding:0 20px;">Bienvenido, <?php echo htmlspecialchars($_SESSION['nombre']); ?></h2>

<form method="POST" action="consultas.php" style="padding-left:20px;">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
  
  <label>Seleccione una consulta:</label>
  <select name="consulta_id" id="select-consulta" required>
    <?php foreach ($consultas as $c): ?>
      <option 
        value="<?php echo (int)$c['id']; ?>"
        data-nombre="<?php echo htmlspecialchars($c['consulta']); ?>">
        <?php echo htmlspecialchars($c['consulta']); ?>
      </option>
    <?php endforeach; ?>
  </select>

  <!-- Aquí viaja el nombre visible de la consulta -->
  <input type="hidden" name="consulta" id="consulta_nombre" value="">

  <button type="submit">Ejecutar</button>
</form>

<script>
  // Rellena el hidden con el texto/label de la consulta seleccionada
  const select = document.getElementById('select-consulta');
  const hidden = document.getElementById('consulta_nombre');
  function syncConsultaNombre() {
    const opt = select.options[select.selectedIndex];
    hidden.value = opt.getAttribute('data-nombre') || opt.text;
  }
  select.addEventListener('change', syncConsultaNombre);
  // Inicializa al cargar
  syncConsultaNombre();
</script>

</body>
</html>