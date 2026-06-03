<?php
session_start();
require_once __DIR__ . '/../includes/login_manager.php';
require_once __DIR__ . '/../includes/consultas_manager.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/app_runtime.php';

// Validar sesión
if (!validar_sesion()) { 
    header('Location: ./login.php'); 
    exit; 
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ./bienvenida.php');
    exit;
}

$csrf_token_post = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($csrf_token_post)) {
  app_audit_log('consulta_web', 'fail', ['reason' => 'csrf_invalid']);
  app_respond_text_error('Error de seguridad', 403);
}

$consulta_id      = isset($_POST['consulta_id']) ? (int)$_POST['consulta_id'] : 0;
$consulta_data    = $consulta_id > 0 ? get_consulta_predefinida_por_id($consulta_id) : null;

if (!$consulta_data) {
  app_audit_log('consulta_web', 'fail', ['reason' => 'consulta_id_invalid', 'consulta_id' => $consulta_id]);
  app_respond_text_error('Consulta no valida', 400);
}

$consulta_nombre  = $consulta_data['consulta'] ?? 'consulta';

$parse = parse_query_select_segura($consulta_data['query'] ?? '');
if (isset($parse['error'])) {
  app_audit_log('consulta_web', 'fail', ['reason' => 'query_policy_denied', 'consulta_id' => $consulta_id]);
  app_respond_text_error('Consulta predefinida no permitida', 403);
}

$filtro_columna   = $_POST['filtro_columna'] ?? '';
$filtro_operador  = $_POST['filtro_operador'] ?? '';
$filtro_valor     = $_POST['filtro_valor'] ?? '';
$filtro_desde     = $_POST['filtro_desde'] ?? '';
$filtro_hasta     = $_POST['filtro_hasta'] ?? '';

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
if (!isset($resultado['error'])) {
  app_audit_log('consulta_web', 'ok', [
    'consulta_id' => $consulta_id,
    'tabla' => $parse['tabla'],
    'total' => isset($resultado['total_registros']) ? (int)$resultado['total_registros'] : 0
  ]);
} else {
  app_audit_log('consulta_web', 'fail', ['consulta_id' => $consulta_id, 'reason' => 'query_execution_error']);
}

$fecha     = date('d/m/Y H:i:s');
$registros = isset($resultado['total_registros']) ? $resultado['total_registros'] : 0;
$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Resultado de consulta</title>
<link rel="stylesheet" href="estilos.css">
</head>
<body>

<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="info-consulta">
  Consulta ejecutada el <?php echo $fecha; ?> · <?php echo $registros; ?> registros devueltos.
  <?php if ($filtro_columna): ?>
    <div style="font-size:0.9rem;color:#004a9f;">
      Filtro activo: <b><?php echo htmlspecialchars($filtro_columna); ?></b>
      <?php if ($filtro_operador === 'fecha'): ?>
        entre <?php echo htmlspecialchars($filtro_desde); ?> y <?php echo htmlspecialchars($filtro_hasta); ?>
      <?php else: ?>
        <?php echo htmlspecialchars($filtro_operador); ?> "<?php echo htmlspecialchars($filtro_valor); ?>"
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<h2>Resultado de la consulta: <?php echo htmlspecialchars($consulta_nombre); ?></h2>

<?php if (isset($resultado['error'])): ?>
  <p class="mensaje-error">
    Error: <?php echo htmlspecialchars($resultado['error']); ?>
  </p>

<?php elseif (!empty($resultado['datos'])): ?>

  <!-- Formulario oculto -->
  <form id="filtroForm" method="POST" action="consultas.php" style="display:none;">
    <input type="hidden" name="consulta_id" value="<?php echo (int)$consulta_id; ?>">
    <input type="hidden" name="consulta" value="<?php echo htmlspecialchars($consulta_nombre); ?>">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
  </form>

  <div class="bloque-tabla">
    <!-- Cabecera -->
    <div class="tabla-header">
      <table>
        <colgroup>
          <?php foreach (array_keys($resultado['datos'][0]) as $col): ?>
            <?php
              $colLower = mb_strtolower($col);
              $width = 'auto';
              if ($colLower === 'id') $width = '8ch';
              elseif ($colLower === 'tenion') $width = '6ch';
              elseif ($colLower === 'correo') $width = '260px';
            ?>
            <col style="width:<?php echo $width; ?>;">
          <?php endforeach; ?>
        </colgroup>
        <thead>
          <tr>
            <?php foreach (array_keys($resultado['datos'][0]) as $col): ?>
              <th data-columna="<?php echo htmlspecialchars($col); ?>">
                <?php echo htmlspecialchars($col); ?>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
      </table>
    </div>

    <!-- Cuerpo -->
    <div class="tabla-body">
      <table>
        <colgroup>
          <?php foreach (array_keys($resultado['datos'][0]) as $col): ?>
            <?php
              $colLower = mb_strtolower($col);
              $width = 'auto';
              if ($colLower === 'id') $width = '8ch';
              elseif ($colLower === 'tenion') $width = '6ch';
              elseif ($colLower === 'correo') $width = '260px';
            ?>
            <col style="width:<?php echo $width; ?>;">
          <?php endforeach; ?>
        </colgroup>
        <tbody>
          <?php foreach ($resultado['datos'] as $fila): ?>
            <tr>
              <?php foreach ($fila as $col => $valor): ?>
                <?php
                  $colLower = mb_strtolower($col);
                  $title = '';
                  if ($colLower === 'correo') {
                      $title = ' title="'.htmlspecialchars($valor ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
                  }
                ?>
                <td data-columna="<?php echo htmlspecialchars($col); ?>"<?php echo $title; ?>>
                  <?php echo htmlspecialchars($valor ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Pie -->
  <div class="pie-consulta">
    <div class="izquierda">
      <form method="POST" action="../includes/exportar_csv.php" style="margin:0;">
        <input type="hidden" name="consulta_id" value="<?php echo (int)$consulta_id; ?>">
        <input type="hidden" name="consulta_nombre" value="<?php echo htmlspecialchars($consulta_nombre); ?>">
        <input type="hidden" name="filtro_columna" value="<?php echo htmlspecialchars($filtro_columna); ?>">
        <input type="hidden" name="filtro_operador" value="<?php echo htmlspecialchars($filtro_operador); ?>">
        <input type="hidden" name="filtro_valor" value="<?php echo htmlspecialchars($filtro_valor); ?>">
        <input type="hidden" name="filtro_desde" value="<?php echo htmlspecialchars($filtro_desde); ?>">
        <input type="hidden" name="filtro_hasta" value="<?php echo htmlspecialchars($filtro_hasta); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <button type="submit">Exportar CSV</button>
      </form>
    </div>
    <div class="centro" id="paginador"></div>
    <div class="derecha">
      <form method="POST" action="bienvenida.php" style="margin:0;">
        <button type="submit">Volver</button>
      </form>
    </div>
  </div>

  <!-- Scripts -->
  <script>
    // Ajustar ancho de cabecera
    function ajustarAnchoCabecera() {
      const bodyDiv = document.querySelector('.tabla-body');
      const headerDiv = document.querySelector('.tabla-header');
      if (!bodyDiv || !headerDiv) return;
      const scrollBarWidth = bodyDiv.offsetWidth - bodyDiv.clientWidth;
      headerDiv.style.paddingRight = scrollBarWidth > 0 ? scrollBarWidth + 'px' : '0';
    }
    window.addEventListener('load', ajustarAnchoCabecera);
    window.addEventListener('resize', ajustarAnchoCabecera);

    // Doble clic para abrir modal de filtro
    document.querySelectorAll('.tabla-header th, .tabla-body td').forEach(el => {
      el.addEventListener('dblclick', e => {
        e.preventDefault(); e.stopPropagation();
        const col = el.dataset.columna || el.closest('table').querySelectorAll('th')[el.cellIndex].dataset.columna;
        abrirFiltro(col);
      });
    });

    function abrirFiltro(columna) {
      const modal = document.createElement('div');
      modal.className = 'filtro-modal';
      modal.innerHTML = `
        <div class="filtro-box">
          <h3>Filtrar por: ${columna}</h3>
          <label>Operador:</label>
          <select id="filtro_operador" onchange="actualizarTipoFiltro(this.value)">
            <option value="igual">= Igual</option>
            <option value="contiene">Contiene</option>
            <option value="empieza">Empieza por</option>
            <option value="mayor">&gt; Mayor</option>
            <option value="menor">&lt; Menor</option>
            <option value="fecha">Rango de fechas</option>
          </select>
          <div id="valorCampo" style="margin-top:10px;">
            <input type="text" id="filtro_valor" placeholder="Valor...">
          </div>
          <div style="margin-top:15px;text-align:right;">
            <button type="button" onclick="aplicarFiltro('${columna}')">Aplicar</button>
            <button type="button" onclick="this.closest('.filtro-modal').remove()">Cancelar</button>
          </div>
        </div>`;
      document.body.appendChild(modal);
      const onEsc = ev => { if (ev.key === 'Escape') modal.remove(); };
      window.addEventListener('keydown', onEsc, { once: true });
    }

    function actualizarTipoFiltro(valor) {
      const cont = document.getElementById('valorCampo');
      if (valor === 'fecha') {
        cont.innerHTML = `
          <label>Desde:</label>
          <input type="date" id="filtro_desde"><br>
          <label>Hasta:</label>
          <input type="date" id="filtro_hasta">
        `;
      } else {
        cont.innerHTML = `<input type="text" id="filtro_valor" placeholder="Valor...">`;
      }
    }

    function aplicarFiltro(columna) {
      const operador = document.getElementById('filtro_operador').value;
      const form = document.getElementById('filtroForm');
      form.querySelectorAll('input[name^="filtro_"]').forEach(e => e.remove());

      if (operador === 'fecha') {
        const desde = document.getElementById('filtro_desde').value;
        const hasta = document.getElementById('filtro_hasta').value;
        form.insertAdjacentHTML('beforeend', `
          <input type="hidden" name="filtro_columna" value="${columna}">
          <input type="hidden" name="filtro_operador" value="fecha">
          <input type="hidden" name="filtro_desde" value="${desde}">
          <input type="hidden" name="filtro_hasta" value="${hasta}">
        `);
      } else {
        const valor = document.getElementById('filtro_valor').value;
        form.insertAdjacentHTML('beforeend', `
          <input type="hidden" name="filtro_columna" value="${columna}">
          <input type="hidden" name="filtro_operador" value="${operador}">
          <input type="hidden" name="filtro_valor" value="${valor}">
        `);
      }
      form.submit();
    }

    // Paginador
    const filas = Array.from(document.querySelectorAll('.tabla-body tbody tr'));
    const pageSize = 100;
    let currentPage = 1;
    const totalPages = Math.ceil(filas.length / pageSize);

    function mostrarPagina(num) {
      currentPage = Math.max(1, Math.min(totalPages, num));
      const inicio = (currentPage - 1) * pageSize;
      const fin = inicio + pageSize;
      filas.forEach((tr, i) => tr.style.display = (i >= inicio && i < fin) ? '' : 'none');
      actualizarPaginador();
      document.querySelector('.tabla-body').scrollTop = 0;
    }

    function actualizarPaginador() {
      const div = document.getElementById('paginador');
      if (totalPages <= 1) { div.innerHTML = ''; return; }
      let html = `
        <button onclick="mostrarPagina(1)" ${currentPage===1?'disabled':''}>&laquo;</button>
        <button onclick="mostrarPagina(${currentPage-1})" ${currentPage===1?'disabled':''}>&lsaquo;</button>
        <span style="margin:0 8px;">P�gina ${currentPage} de ${totalPages}</span>
        <button onclick="mostrarPagina(${currentPage+1})" ${currentPage===totalPages?'disabled':''}>&rsaquo;</button>
        <button onclick="mostrarPagina(${totalPages})" ${currentPage===totalPages?'disabled':''}>&raquo;</button>
        <span style="margin-left:10px;">Ir a:</span>
        <select onchange="mostrarPagina(this.value)" style="margin-left:5px;">`;
      for (let i = 1; i <= totalPages; i++) {
        html += `<option value="${i}" ${i===currentPage?'selected':''}>${i}</option>`;
      }
      html += `</select>`;
      div.innerHTML = html;
    }

    window.addEventListener('load', () => mostrarPagina(1));
  </script>

<?php else: ?>
  <p class="mensaje-vacio">No hay resultados.</p>
<?php endif; ?>

</body>
</html>
