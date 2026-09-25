<?php
require_once __DIR__ . '/../includes/login_manager.php';
require_once __DIR__ . '/../includes/consultas_manager.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/app_runtime.php';

start_secure_session();

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

$query_predefinida = $consulta_data['query'] ?? '';
$validacion_query = validar_consulta_predefinida($query_predefinida);
if (isset($validacion_query['error'])) {
  app_audit_log('consulta_web', 'fail', ['reason' => 'query_policy_denied', 'consulta_id' => $consulta_id]);
  app_respond_text_error('Consulta predefinida no permitida', 403);
}

$metadata_query = get_columnas_consulta_predefinida($validacion_query['query']);
if (isset($metadata_query['error'])) {
  app_audit_log('consulta_web', 'fail', ['reason' => 'query_metadata_error', 'consulta_id' => $consulta_id]);
  app_respond_text_error('Error en la consulta predefinida', 500);
}

$filtros_activos = normalizar_filtros_desde_post($_POST);
$columnas_disponibles = $metadata_query['columnas'];
$columnas_filtrables = get_columnas_filtrables_consulta_predefinida($columnas_disponibles);
$orden_normalizada = normalizar_orden_usuario_consulta_predefinida(
  $_POST['orden_columna'] ?? '',
  $_POST['orden_direccion'] ?? '',
  $columnas_disponibles
);
if (isset($orden_normalizada['error'])) {
  app_audit_log('consulta_web', 'fail', ['reason' => 'sort_invalid', 'consulta_id' => $consulta_id]);
  app_respond_text_error('Orden no valida', 400);
}
$orden_activo = $orden_normalizada['orden'];

$sin_limite = isset($_POST['sin_limite']) && (string)$_POST['sin_limite'] === '1';
$limite_consulta = $sin_limite ? 0 : 1000;

$registros_por_pagina = isset($_POST['registros_por_pagina']) ? (int)$_POST['registros_por_pagina'] : 100;
if ($registros_por_pagina < 10) { $registros_por_pagina = 10; }
if ($registros_por_pagina > 500) { $registros_por_pagina = 500; }

$resultado = ejecutar_consulta_predefinida($validacion_query['query'], $filtros_activos, $limite_consulta, $columnas_disponibles, $orden_activo);
$resultado_total = contar_consulta_predefinida($validacion_query['query'], $filtros_activos, $columnas_disponibles);
if (isset($resultado_total['error'])) {
  app_audit_log('consulta_web', 'fail', ['consulta_id' => $consulta_id, 'reason' => 'query_count_error']);
}
if (!isset($resultado['error'])) {
  app_audit_log('consulta_web', 'ok', [
    'consulta_id' => $consulta_id,
    'total' => isset($resultado['total_registros']) ? (int)$resultado['total_registros'] : 0
  ]);
} else {
  app_audit_log('consulta_web', 'fail', ['consulta_id' => $consulta_id, 'reason' => 'query_execution_error']);
}

$fecha     = date('d/m/Y H:i:s');
$registros = isset($resultado['total_registros']) ? $resultado['total_registros'] : 0;
$total_disponible = !isset($resultado_total['error']) && isset($resultado_total['total_registros']);
$registros_totales = $total_disponible ? $resultado_total['total_registros'] : null;
$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Resultado de consulta</title>
<link rel="stylesheet" href="estilos.css">
<style>
  .filtros-activos {
    margin: 16px 20px 0;
    padding: 12px 14px;
    border: 1px solid #c7d7ef;
    border-radius: 8px;
    background: #f8fbff;
  }
  .filtros-activos h3 {
    margin: 0 0 10px 0;
    font-size: 1rem;
    color: #0b4fa2;
  }
  .filtros-layout {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 12px;
    align-items: start;
  }
  .filtros-col-botones {
    min-width: max-content;
  }
  .filtros-col-lista {
    min-width: 0;
  }
  .filtros-lista {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .filtro-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 10px;
    border-radius: 999px;
    background: #0b4fa2;
    color: #fff;
    font-size: 0.9rem;
  }
  .filtro-chip button {
    border: 0;
    background: transparent;
    color: inherit;
    cursor: pointer;
    font-size: 1rem;
    line-height: 1;
    padding: 0;
  }
  .acciones-filtros {
    display: flex;
    gap: 8px;
    margin-top: 0;
    flex-wrap: wrap;
  }
  .acciones-filtros button {
    cursor: pointer;
  }
  .filtro-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.35);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
  }
  .filtro-dialog {
    width: min(520px, calc(100vw - 32px));
    background: #fff;
    border-radius: 10px;
    padding: 18px;
    box-shadow: 0 12px 30px rgba(0,0,0,.22);
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .filtro-dialog-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: move;
    user-select: none;
    border-bottom: 1px solid #e5e7eb;
    padding-bottom: 8px;
    margin-bottom: 2px;
  }
  .filtro-dialog h3 {
    margin: 0;
  }
  .filtro-dialog label {
    display: block;
    margin-bottom: 6px;
  }
  .filtro-dialog select,
  .filtro-dialog input {
    width: 100%;
    box-sizing: border-box;
    padding: 8px;
  }
  .filtro-dialog-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 6px;
  }
  .valores-existentes-ayuda {
    font-size: 0.85rem;
    color: #4b5563;
    margin-top: 4px;
  }
  .acciones-pie-inline {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: nowrap;
  }
  .acciones-pie-inline form {
    margin: 0;
  }
  .pie-consulta {
    bottom: 36px;
    z-index: 30;
  }
  .bloque-tabla {
    padding-bottom: 140px !important;
  }
  .cabecera-columna {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
  }
  .cabecera-columna-nombre {
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .controles-orden {
    display: inline-flex;
    gap: 2px;
    margin-left: auto;
  }
  .btn-ordenar {
    min-width: 24px;
    min-height: 24px;
    padding: 2px 4px;
    border: 1px solid rgba(255,255,255,.7);
    border-radius: 3px;
    background: transparent;
    color: inherit;
    cursor: pointer;
    line-height: 1;
  }
  .btn-ordenar:hover,
  .btn-ordenar:focus-visible,
  .btn-ordenar.is-active {
    background: rgba(255,255,255,.22);
    outline: none;
  }
  @media (max-width: 768px) {
    .filtros-layout {
      grid-template-columns: 1fr;
      gap: 10px;
    }
    .filtros-col-botones {
      min-width: 0;
    }
    .acciones-filtros {
      flex-wrap: wrap;
    }
    .acciones-pie-inline {
      flex-wrap: wrap;
    }
    .pie-consulta {
      bottom: 50px;
    }
  }
</style>
</head>
<body>

<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="info-consulta">
  Consulta ejecutada el <?php echo $fecha; ?>
  <?php if ($total_disponible && !empty($filtros_activos)): ?>
    · <?php echo $registros_totales; ?> registros cumplen los filtros activos.
  <?php elseif ($total_disponible): ?>
    · <?php echo $registros_totales; ?> registros totales.
  <?php else: ?>
    · Total de registros no disponible.
  <?php endif; ?>
  · <?php echo $registros; ?> registros devueltos.
  <?php if (!$sin_limite): ?>
    · Límite activo: 1000.
  <?php else: ?>
    · Sin límite solicitado por usuario.
  <?php endif; ?>
  <div class="filtros-activos">
    <h3>Filtros activos</h3>
    <div class="filtros-layout">
      <div class="filtros-col-botones">
        <div class="acciones-filtros">
          <button type="button" id="btnAgregarFiltro">Agregar filtro</button>
          <?php if (!empty($filtros_activos)): ?>
            <button type="button" id="btnLimpiarFiltros">Limpiar filtros</button>
          <?php endif; ?>
        </div>
      </div>
      <div class="filtros-col-lista">
        <div class="filtros-lista" id="filtrosLista">
          <?php if (!empty($filtros_activos)): ?>
            <?php foreach ($filtros_activos as $indice => $filtro): ?>
              <span class="filtro-chip" data-indice="<?php echo (int)$indice; ?>">
                <span>
                  <?php if ($indice > 0): ?>
                    <?php echo htmlspecialchars(strtoupper($filtro['conector'] ?? 'AND')); ?>
                  <?php endif; ?>
                  <?php echo htmlspecialchars($filtro['columna']); ?>
                  <?php if (($filtro['operador'] ?? '') === 'fecha_entre'): ?>
                    <?php if (!empty($filtro['desde']) && !empty($filtro['hasta'])): ?>
                      entre <?php echo htmlspecialchars($filtro['desde']); ?> y <?php echo htmlspecialchars($filtro['hasta']); ?>
                    <?php elseif (!empty($filtro['desde'])): ?>
                      desde <?php echo htmlspecialchars($filtro['desde']); ?>
                    <?php elseif (!empty($filtro['hasta'])): ?>
                      hasta <?php echo htmlspecialchars($filtro['hasta']); ?>
                    <?php endif; ?>
                  <?php else: ?>
                    <?php echo htmlspecialchars($filtro['operador']); ?> "<?php echo htmlspecialchars($filtro['valor']); ?>"
                  <?php endif; ?>
                </span>
                <button type="button" class="btn-quitar-filtro" data-indice="<?php echo (int)$indice; ?>" aria-label="Quitar filtro">×</button>
              </span>
            <?php endforeach; ?>
          <?php else: ?>
            <span style="color:#4b5563;">No hay filtros aplicados.</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<h2>Resultado de la consulta: <?php echo htmlspecialchars($consulta_nombre); ?></h2>

<?php if (isset($resultado['error'])): ?>
  <p class="mensaje-error">
    Error: <?php echo htmlspecialchars($resultado['error']); ?>
  </p>

<?php else: ?>

  <!-- Formulario oculto -->
  <form id="filtroForm" method="POST" action="consultas.php" style="display:none;">
    <input type="hidden" name="consulta_id" value="<?php echo (int)$consulta_id; ?>">
    <input type="hidden" name="consulta" value="<?php echo htmlspecialchars($consulta_nombre); ?>">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    <input type="hidden" name="sin_limite" value="<?php echo $sin_limite ? '1' : '0'; ?>">
    <input type="hidden" name="registros_por_pagina" value="<?php echo (int)$registros_por_pagina; ?>">
    <input type="hidden" name="orden_columna" value="<?php echo htmlspecialchars($orden_activo['columna'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <input type="hidden" name="orden_direccion" value="<?php echo htmlspecialchars($orden_activo['direccion'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <?php foreach ($filtros_activos as $filtro): ?>
      <input type="hidden" data-dynamic-filter="1" name="filtro_columna[]" value="<?php echo htmlspecialchars($filtro['columna']); ?>">
      <input type="hidden" data-dynamic-filter="1" name="filtro_operador[]" value="<?php echo htmlspecialchars($filtro['operador']); ?>">
      <input type="hidden" data-dynamic-filter="1" name="filtro_valor[]" value="<?php echo htmlspecialchars($filtro['valor'] ?? ''); ?>">
      <input type="hidden" data-dynamic-filter="1" name="filtro_desde[]" value="<?php echo htmlspecialchars($filtro['desde'] ?? ''); ?>">
      <input type="hidden" data-dynamic-filter="1" name="filtro_hasta[]" value="<?php echo htmlspecialchars($filtro['hasta'] ?? ''); ?>">
      <input type="hidden" data-dynamic-filter="1" name="filtro_conector[]" value="<?php echo htmlspecialchars($filtro['conector'] ?? 'AND'); ?>">
    <?php endforeach; ?>
  </form>

  <div class="bloque-tabla">
    <?php if (!empty($resultado['datos'])): ?>
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
                  <span class="cabecera-columna">
                    <span class="cabecera-columna-nombre"><?php echo htmlspecialchars($col); ?></span>
                    <span class="controles-orden" aria-label="Ordenar por <?php echo htmlspecialchars($col, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                      <button type="button" class="btn-ordenar<?php echo (($orden_activo['columna'] ?? '') === $col && ($orden_activo['direccion'] ?? '') === 'ASC') ? ' is-active' : ''; ?>" data-orden-columna="<?php echo htmlspecialchars($col, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" data-orden-direccion="ASC" aria-label="Ordenar <?php echo htmlspecialchars($col, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> de forma ascendente" aria-pressed="<?php echo (($orden_activo['columna'] ?? '') === $col && ($orden_activo['direccion'] ?? '') === 'ASC') ? 'true' : 'false'; ?>" title="Orden ascendente">▲</button>
                      <button type="button" class="btn-ordenar<?php echo (($orden_activo['columna'] ?? '') === $col && ($orden_activo['direccion'] ?? '') === 'DESC') ? ' is-active' : ''; ?>" data-orden-columna="<?php echo htmlspecialchars($col, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" data-orden-direccion="DESC" aria-label="Ordenar <?php echo htmlspecialchars($col, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> de forma descendente" aria-pressed="<?php echo (($orden_activo['columna'] ?? '') === $col && ($orden_activo['direccion'] ?? '') === 'DESC') ? 'true' : 'false'; ?>" title="Orden descendente">▼</button>
                    </span>
                  </span>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>
        </table>
      </div>

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
    <?php else: ?>
      <p class="mensaje-vacio">No hay resultados.</p>
    <?php endif; ?>
  </div>

  <!-- Pie -->
  <div class="pie-consulta">
    <div class="izquierda">
      <div class="acciones-pie-inline">
        <form method="POST" action="../includes/exportar_csv.php"<?php if ($sin_limite): ?> onsubmit="if (!confirm('Vas a exportar todos los registros. ¿Deseas continuar?')) { return false; } this.querySelector('[name=confirmar_exportacion_sin_limite]').value = '1';"<?php endif; ?>>
          <input type="hidden" name="consulta_id" value="<?php echo (int)$consulta_id; ?>">
          <input type="hidden" name="consulta_nombre" value="<?php echo htmlspecialchars($consulta_nombre); ?>">
          <input type="hidden" name="sin_limite" value="<?php echo $sin_limite ? '1' : '0'; ?>">
          <input type="hidden" name="orden_columna" value="<?php echo htmlspecialchars($orden_activo['columna'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          <input type="hidden" name="orden_direccion" value="<?php echo htmlspecialchars($orden_activo['direccion'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          <?php if ($sin_limite): ?>
            <input type="hidden" name="confirmar_exportacion_sin_limite" value="">
          <?php endif; ?>
          <?php foreach ($filtros_activos as $filtro): ?>
            <input type="hidden" name="filtro_columna[]" value="<?php echo htmlspecialchars($filtro['columna']); ?>">
            <input type="hidden" name="filtro_operador[]" value="<?php echo htmlspecialchars($filtro['operador']); ?>">
            <input type="hidden" name="filtro_valor[]" value="<?php echo htmlspecialchars($filtro['valor'] ?? ''); ?>">
            <input type="hidden" name="filtro_desde[]" value="<?php echo htmlspecialchars($filtro['desde'] ?? ''); ?>">
            <input type="hidden" name="filtro_hasta[]" value="<?php echo htmlspecialchars($filtro['hasta'] ?? ''); ?>">
            <input type="hidden" name="filtro_conector[]" value="<?php echo htmlspecialchars($filtro['conector'] ?? 'AND'); ?>">
          <?php endforeach; ?>
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
          <button type="submit">Exportar CSV <?php echo $sin_limite ? '(todos los registros)' : '(máx. 1000)'; ?></button>
        </form>

        <form method="POST" action="consultas.php">
          <input type="hidden" name="consulta_id" value="<?php echo (int)$consulta_id; ?>">
          <input type="hidden" name="consulta" value="<?php echo htmlspecialchars($consulta_nombre); ?>">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
          <input type="hidden" name="sin_limite" value="<?php echo $sin_limite ? '0' : '1'; ?>">
          <input type="hidden" name="registros_por_pagina" value="<?php echo (int)$registros_por_pagina; ?>">
          <input type="hidden" name="orden_columna" value="<?php echo htmlspecialchars($orden_activo['columna'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          <input type="hidden" name="orden_direccion" value="<?php echo htmlspecialchars($orden_activo['direccion'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          <?php foreach ($filtros_activos as $filtro): ?>
            <input type="hidden" name="filtro_columna[]" value="<?php echo htmlspecialchars($filtro['columna']); ?>">
            <input type="hidden" name="filtro_operador[]" value="<?php echo htmlspecialchars($filtro['operador']); ?>">
            <input type="hidden" name="filtro_valor[]" value="<?php echo htmlspecialchars($filtro['valor'] ?? ''); ?>">
            <input type="hidden" name="filtro_desde[]" value="<?php echo htmlspecialchars($filtro['desde'] ?? ''); ?>">
            <input type="hidden" name="filtro_hasta[]" value="<?php echo htmlspecialchars($filtro['hasta'] ?? ''); ?>">
            <input type="hidden" name="filtro_conector[]" value="<?php echo htmlspecialchars($filtro['conector'] ?? 'AND'); ?>">
          <?php endforeach; ?>
          <button type="submit"><?php echo $sin_limite ? 'Activar límite 1000' : 'Solicitar todos los registros'; ?></button>
        </form>
      </div>
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
    const filtrosActivos = <?php echo json_encode(array_values($filtros_activos), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const columnasDisponibles = <?php echo json_encode(array_values($columnas_filtrables), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const maxFiltros = 5;
    let pageSize = <?php echo (int)$registros_por_pagina; ?>;

    function ajustarAnchoCabecera() {
      const bodyDiv = document.querySelector('.tabla-body');
      const headerDiv = document.querySelector('.tabla-header');
      if (!bodyDiv || !headerDiv) return;
      const scrollBarWidth = bodyDiv.offsetWidth - bodyDiv.clientWidth;
      headerDiv.style.paddingRight = scrollBarWidth > 0 ? scrollBarWidth + 'px' : '0';
    }

    function ajustarPieSobreFooter() {
      const pie = document.querySelector('.pie-consulta');
      const footer = document.querySelector('.app-footer');
      if (!pie) return;

      const alturaFooter = footer ? footer.offsetHeight : 0;
      pie.style.bottom = alturaFooter + 'px';
    }

    function ajustarEspacioInferiorTabla() {
      const bloqueTabla = document.querySelector('.bloque-tabla');
      const pie = document.querySelector('.pie-consulta');
      const footer = document.querySelector('.app-footer');
      if (!bloqueTabla) return;

      const alturaPie = pie ? pie.offsetHeight : 0;
      const alturaFooter = footer ? footer.offsetHeight : 0;
      const margenSeguridad = 20;
      const paddingNecesario = alturaPie + alturaFooter + margenSeguridad;

      bloqueTabla.style.paddingBottom = paddingNecesario + 'px';
    }

    function getFiltroForm() {
      return document.getElementById('filtroForm');
    }

    function limpiarFiltrosDelFormulario(form) {
      form.querySelectorAll('input[data-dynamic-filter="1"]').forEach((elemento) => elemento.remove());
    }

    function appendFiltroAlFormulario(form, filtro) {
      const campos = ['filtro_columna[]', 'filtro_operador[]', 'filtro_valor[]', 'filtro_desde[]', 'filtro_hasta[]', 'filtro_conector[]'];
      const valores = [filtro.columna || '', filtro.operador || '', filtro.valor || '', filtro.desde || '', filtro.hasta || '', filtro.conector || 'AND'];

      campos.forEach((nombre, indice) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = nombre;
        input.value = valores[indice];
        input.setAttribute('data-dynamic-filter', '1');
        form.appendChild(input);
      });
    }

    function enviarFiltros(filtros) {
      const form = getFiltroForm();
      if (!form) return;
      limpiarFiltrosDelFormulario(form);
      filtros.forEach((filtro) => appendFiltroAlFormulario(form, filtro));
      form.submit();
    }

    function renderFiltrosActivos() {
      const lista = document.getElementById('filtrosLista');
      if (!lista) return;

      lista.querySelectorAll('.btn-quitar-filtro').forEach((boton) => {
        boton.addEventListener('click', () => {
          const indice = Number(boton.dataset.indice);
          const nuevosFiltros = filtrosActivos.filter((_, idx) => idx !== indice);
          enviarFiltros(nuevosFiltros);
        });
      });
    }

    function crearModalFiltro(columnaInicial) {
      if (!Array.isArray(columnasDisponibles) || columnasDisponibles.length === 0) {
        alert('No hay columnas disponibles para aplicar filtros en esta consulta.');
        return;
      }

      const backdrop = document.createElement('div');
      backdrop.className = 'filtro-modal-backdrop';

      const modal = document.createElement('div');
      modal.className = 'filtro-dialog';

      const headerModal = document.createElement('div');
      headerModal.className = 'filtro-dialog-header';

      const titulo = document.createElement('h3');
      titulo.textContent = 'Añadir filtro';

      const btnCerrar = document.createElement('button');
      btnCerrar.type = 'button';
      btnCerrar.textContent = '×';
      btnCerrar.setAttribute('aria-label', 'Cerrar');
      btnCerrar.style.width = '34px';
      btnCerrar.style.height = '34px';
      btnCerrar.style.padding = '0';
      btnCerrar.style.lineHeight = '1';
      btnCerrar.style.fontSize = '1.1rem';

      headerModal.appendChild(titulo);
      headerModal.appendChild(btnCerrar);

      const labelColumna = document.createElement('label');
      labelColumna.textContent = 'Campo';
      const selectColumna = document.createElement('select');
      selectColumna.id = 'modal_columna';

      columnasDisponibles.forEach((columna) => {
        const opcion = document.createElement('option');
        opcion.value = columna;
        opcion.textContent = columna;
        selectColumna.appendChild(opcion);
      });
      if (columnaInicial && columnasDisponibles.includes(columnaInicial)) {
        selectColumna.value = columnaInicial;
      }

      const labelOperador = document.createElement('label');
      labelOperador.textContent = 'Operador';
      const selectOperador = document.createElement('select');
      selectOperador.id = 'modal_operador';
      [
        ['igual', '= Igual'],
        ['contiene', 'Contiene'],
        ['empieza', 'Empieza por'],
        ['mayor', '> Mayor'],
        ['menor', '< Menor'],
        ['fecha', 'Rango de fechas']
      ].forEach(([valor, texto]) => {
        const opcion = document.createElement('option');
        opcion.value = valor;
        opcion.textContent = texto;
        selectOperador.appendChild(opcion);
      });

      const contenedorValor = document.createElement('div');
      contenedorValor.style.marginTop = '10px';

      const contenedorValoresExistentes = document.createElement('div');
      contenedorValoresExistentes.style.marginTop = '8px';

      function normalizarValorFechaLista(valor) {
        const texto = String(valor || '').trim();
        if (/^\d{4}-\d{2}-\d{2}$/.test(texto)) {
          return { valor: texto, esFechaDia: true };
        }

        // Formatos tipo 2026-06-04 10:50:32, 2026-06-04T10:50:32.000Z, etc.
        const isoPrefix = texto.match(/^(\d{4}-\d{2}-\d{2})[ T].*$/);
        if (isoPrefix) {
          return { valor: isoPrefix[1], esFechaDia: true };
        }

        // Formato local tipo 04/06/2026 o 04/06/2026 10:50:32
        const localPrefix = texto.match(/^(\d{2})\/(\d{2})\/(\d{4})(?:\s+.*)?$/);
        if (localPrefix) {
          const dia = localPrefix[1];
          const mes = localPrefix[2];
          const anio = localPrefix[3];
          return { valor: `${anio}-${mes}-${dia}`, esFechaDia: true };
        }

        return { valor: texto, esFechaDia: false };
      }

      function obtenerValoresExistentesDeColumna(columna) {
        if (!columna) {
          return [];
        }

        const celdas = document.querySelectorAll('.tabla-body td[data-columna]');
        const normalizada = columna.toLowerCase();
        const mapa = new Map();

        celdas.forEach((celda) => {
          const nombreColumna = String(celda.dataset.columna || '').toLowerCase();
          if (nombreColumna !== normalizada) {
            return;
          }

          const valorOriginal = (celda.textContent || '').trim();
          if (!valorOriginal || valorOriginal.length > 200) {
            return;
          }

          const valorNormalizado = normalizarValorFechaLista(valorOriginal);
          const clave = valorNormalizado.valor;

          if (!clave) {
            return;
          }

          if (!mapa.has(clave)) {
            mapa.set(clave, {
              valor: valorNormalizado.valor,
              etiqueta: valorNormalizado.valor,
              esFechaDia: valorNormalizado.esFechaDia
            });
          }
        });

        return Array.from(mapa.values()).sort((a, b) => a.etiqueta.localeCompare(b.etiqueta, 'es', { sensitivity: 'base' }));
      }

      const contenedorConector = document.createElement('div');
      contenedorConector.style.marginTop = '10px';
      if (filtrosActivos.length > 0) {
        const labelConector = document.createElement('label');
        labelConector.textContent = 'Combinar con el filtro anterior';
        const selectConector = document.createElement('select');
        selectConector.id = 'modal_conector';

        [['AND', 'AND'], ['OR', 'OR']].forEach(([valor, texto]) => {
          const opcion = document.createElement('option');
          opcion.value = valor;
          opcion.textContent = texto;
          selectConector.appendChild(opcion);
        });

        contenedorConector.appendChild(labelConector);
        contenedorConector.appendChild(selectConector);
      }

      const btnAplicar = document.createElement('button');
      btnAplicar.type = 'button';
      btnAplicar.textContent = 'Aplicar';

      const btnCancelar = document.createElement('button');
      btnCancelar.type = 'button';
      btnCancelar.textContent = 'Cancelar';
      btnCancelar.style.marginLeft = '8px';

      function pintarCampoValor() {
        contenedorValor.innerHTML = '';
        contenedorValoresExistentes.innerHTML = '';
        const operador = selectOperador.value;
        if (operador === 'fecha') {
          const labelDesde = document.createElement('label');
          labelDesde.textContent = 'Desde';
          const inputDesde = document.createElement('input');
          inputDesde.type = 'date';
          inputDesde.id = 'modal_fecha_desde';

          const labelHasta = document.createElement('label');
          labelHasta.textContent = 'Hasta';
          labelHasta.style.marginTop = '10px';
          const inputHasta = document.createElement('input');
          inputHasta.type = 'date';
          inputHasta.id = 'modal_fecha_hasta';

          contenedorValor.appendChild(labelDesde);
          contenedorValor.appendChild(inputDesde);
          contenedorValor.appendChild(labelHasta);
          contenedorValor.appendChild(inputHasta);
        } else {
          const labelValor = document.createElement('label');
          labelValor.textContent = 'Valor';
          const inputValor = document.createElement('input');
          inputValor.type = 'text';
          inputValor.id = 'modal_valor';
          inputValor.placeholder = 'Valor...';
          contenedorValor.appendChild(labelValor);
          contenedorValor.appendChild(inputValor);

        }

        let valoresExistentes = obtenerValoresExistentesDeColumna(selectColumna.value);
        if (operador === 'fecha') {
          valoresExistentes = valoresExistentes.filter((item) => item.esFechaDia);
        }

        if (valoresExistentes.length > 0) {
          const labelLista = document.createElement('label');
          labelLista.textContent = operador === 'fecha'
            ? 'Fechas existentes (una o varias)'
            : 'Valores existentes (uno o varios)';

          const selectLista = document.createElement('select');
          selectLista.id = 'modal_valores_existentes';
          selectLista.multiple = true;
          selectLista.size = Math.min(8, Math.max(4, valoresExistentes.length));

          valoresExistentes.forEach((item) => {
            const opcion = document.createElement('option');
            opcion.value = item.valor;
            opcion.textContent = item.etiqueta;
            opcion.dataset.esFechaDia = item.esFechaDia ? '1' : '0';
            selectLista.appendChild(opcion);
          });

          const ayuda = document.createElement('div');
          ayuda.className = 'valores-existentes-ayuda';
          ayuda.textContent = 'Tip: usa Ctrl/Cmd para seleccionar varios valores.';

          contenedorValoresExistentes.appendChild(labelLista);
          contenedorValoresExistentes.appendChild(selectLista);
          contenedorValoresExistentes.appendChild(ayuda);
        }
      }

      function obtenerValoresSeleccionados() {
        const lista = document.getElementById('modal_valores_existentes');
        if (!lista) {
          return [];
        }

        return Array.from(lista.selectedOptions)
          .map((opcion) => ({
            valor: (opcion.value || '').trim(),
            esFechaDia: opcion.dataset.esFechaDia === '1'
          }))
          .filter((item) => item.valor !== '');
      }

      function obtenerFiltroDesdeModal() {
        const columna = selectColumna.value.trim();
        const operador = selectOperador.value;
        const conector = filtrosActivos.length > 0 ? (document.getElementById('modal_conector')?.value || 'AND') : 'AND';

        if (!columna || !operador) {
          return null;
        }

        const valoresSeleccionados = obtenerValoresSeleccionados();
        if (valoresSeleccionados.length > 0) {
          return valoresSeleccionados.map((valorSeleccionado, indice) => {
            if (valorSeleccionado.esFechaDia && (operador === 'igual' || operador === 'fecha')) {
              return {
                columna: columna,
                operador: 'fecha_entre',
                valor: '',
                desde: valorSeleccionado.valor,
                hasta: valorSeleccionado.valor,
                conector: indice === 0 ? conector : 'OR'
              };
            }

            return {
              columna: columna,
              operador: operador,
              valor: valorSeleccionado.valor,
              desde: '',
              hasta: '',
              conector: indice === 0 ? conector : 'OR'
            };
          });
        }

        if (operador === 'fecha') {
          const desde = document.getElementById('modal_fecha_desde')?.value || '';
          const hasta = document.getElementById('modal_fecha_hasta')?.value || '';
          if (!desde && !hasta) {
            return null;
          }
          return {
            columna: columna,
            operador: 'fecha_entre',
            valor: '',
            desde: desde,
            hasta: hasta,
            conector: conector
          };
        }

        const valor = document.getElementById('modal_valor')?.value || '';
        if (!valor) {
          return null;
        }

        return {
          columna: columna,
          operador: operador,
          valor: valor,
          desde: '',
          hasta: '',
          conector: conector
        };
      }

      function habilitarArrastreModal() {
        let arrastrando = false;
        let offsetX = 0;
        let offsetY = 0;

        headerModal.addEventListener('mousedown', (evento) => {
          if (evento.target === btnCerrar) {
            return;
          }

          const rect = modal.getBoundingClientRect();
          modal.style.position = 'fixed';
          modal.style.left = rect.left + 'px';
          modal.style.top = rect.top + 'px';
          modal.style.margin = '0';

          offsetX = evento.clientX - rect.left;
          offsetY = evento.clientY - rect.top;
          arrastrando = true;
          document.body.style.userSelect = 'none';
        });

        window.addEventListener('mousemove', (evento) => {
          if (!arrastrando) {
            return;
          }

          const ancho = modal.offsetWidth;
          const alto = modal.offsetHeight;
          const maxX = Math.max(0, window.innerWidth - ancho);
          const maxY = Math.max(0, window.innerHeight - alto);

          const x = Math.min(maxX, Math.max(0, evento.clientX - offsetX));
          const y = Math.min(maxY, Math.max(0, evento.clientY - offsetY));

          modal.style.left = x + 'px';
          modal.style.top = y + 'px';
        });

        window.addEventListener('mouseup', () => {
          if (!arrastrando) {
            return;
          }
          arrastrando = false;
          document.body.style.userSelect = '';
        });
      }

      selectOperador.addEventListener('change', pintarCampoValor);
      selectColumna.addEventListener('change', pintarCampoValor);
      btnCancelar.addEventListener('click', () => backdrop.remove());
      btnCerrar.addEventListener('click', () => backdrop.remove());
      btnAplicar.addEventListener('click', () => {
        if (filtrosActivos.length >= maxFiltros) {
          alert('Solo puedes aplicar un máximo de ' + maxFiltros + ' filtros.');
          return;
        }

        const filtro = obtenerFiltroDesdeModal();
        if (!filtro || (Array.isArray(filtro) && filtro.length === 0)) {
          alert('Completa el filtro antes de aplicarlo.');
          return;
        }

        const filtrosNuevos = Array.isArray(filtro) ? filtro : [filtro];
        if ((filtrosActivos.length + filtrosNuevos.length) > maxFiltros) {
          alert('La selección supera el máximo de ' + maxFiltros + ' filtros.');
          return;
        }

        enviarFiltros(filtrosActivos.concat(filtrosNuevos));
      });

      const acciones = document.createElement('div');
      acciones.className = 'filtro-dialog-actions';
      acciones.appendChild(btnAplicar);
      acciones.appendChild(btnCancelar);

      modal.appendChild(headerModal);
      modal.appendChild(labelColumna);
      modal.appendChild(selectColumna);
      modal.appendChild(labelOperador);
      modal.appendChild(selectOperador);
      if (filtrosActivos.length > 0) {
        modal.appendChild(contenedorConector);
      }
      modal.appendChild(contenedorValor);
      modal.appendChild(contenedorValoresExistentes);
      modal.appendChild(acciones);
      backdrop.appendChild(modal);
      document.body.appendChild(backdrop);

      pintarCampoValor();
      habilitarArrastreModal();
      selectColumna.focus();

      backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) {
          backdrop.remove();
        }
      });

      window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          backdrop.remove();
        }
      }, { once: true });
    }

    function conectarEventosFiltros() {
      const btnAgregarFiltro = document.getElementById('btnAgregarFiltro');
      if (btnAgregarFiltro) {
        btnAgregarFiltro.addEventListener('click', () => {
          crearModalFiltro(columnasDisponibles[0] || '');
        });
      }

      const btnLimpiarFiltros = document.getElementById('btnLimpiarFiltros');
      if (btnLimpiarFiltros) {
        btnLimpiarFiltros.addEventListener('click', () => enviarFiltros([]));
      }

      document.querySelectorAll('.btn-quitar-filtro').forEach((boton) => {
        boton.addEventListener('click', () => {
          const indice = Number(boton.dataset.indice);
          const nuevosFiltros = filtrosActivos.filter((_, idx) => idx !== indice);
          enviarFiltros(nuevosFiltros);
        });
      });

      document.querySelectorAll('.btn-ordenar').forEach((boton) => {
        boton.addEventListener('click', (evento) => {
          evento.preventDefault();
          evento.stopPropagation();
          const form = getFiltroForm();
          if (!form) return;

          const columna = boton.dataset.ordenColumna || '';
          const direccion = boton.dataset.ordenDireccion || '';
          const inputColumna = form.querySelector('input[name="orden_columna"]');
          const inputDireccion = form.querySelector('input[name="orden_direccion"]');
          if (!columna || !direccion || !inputColumna || !inputDireccion) return;

          inputColumna.value = columna;
          inputDireccion.value = direccion;
          form.submit();
        });
      });

      document.querySelectorAll('.tabla-header th, .tabla-body td').forEach((celda) => {
        celda.addEventListener('dblclick', (evento) => {
          evento.preventDefault();
          evento.stopPropagation();
          const columna = celda.dataset.columna || celda.textContent.trim();
          crearModalFiltro(columnasDisponibles.includes(columna) ? columna : (columnasDisponibles[0] || columna));
        });
      });
    }

    const filas = Array.from(document.querySelectorAll('.tabla-body tbody tr'));
    let currentPage = 1;

    function getTotalPages() {
      return Math.max(1, Math.ceil(filas.length / pageSize));
    }

    function actualizarTamPagina(valor) {
      const numero = Number(valor);
      if (!Number.isFinite(numero)) return;
      pageSize = Math.max(10, Math.min(500, Math.floor(numero)));

      const form = getFiltroForm();
      if (form) {
        const inputTam = form.querySelector('input[name="registros_por_pagina"]');
        if (inputTam) {
          inputTam.value = String(pageSize);
        }
      }

      mostrarPagina(1);
    }
    window.actualizarTamPagina = actualizarTamPagina;

    function mostrarPagina(num) {
      const totalPages = getTotalPages();
      currentPage = Math.max(1, Math.min(totalPages, Number(num) || 1));
      const inicio = (currentPage - 1) * pageSize;
      const fin = inicio + pageSize;
      filas.forEach((tr, i) => tr.style.display = (i >= inicio && i < fin) ? '' : 'none');
      actualizarPaginador();
      const tablaBody = document.querySelector('.tabla-body');
      if (tablaBody) {
        tablaBody.scrollTop = 0;
      }
    }

    function actualizarPaginador() {
      const div = document.getElementById('paginador');
      if (!div) return;
      const totalPages = getTotalPages();
      let html = `
        <button onclick="mostrarPagina(1)" ${currentPage===1?'disabled':''}>&laquo;</button>
        <button onclick="mostrarPagina(${currentPage-1})" ${currentPage===1?'disabled':''}>&lsaquo;</button>
        <span style="margin:0 8px;">Página ${currentPage} de ${totalPages}</span>
        <button onclick="mostrarPagina(${currentPage+1})" ${currentPage===totalPages?'disabled':''}>&rsaquo;</button>
        <button onclick="mostrarPagina(${totalPages})" ${currentPage===totalPages?'disabled':''}>&raquo;</button>
        <span style="margin-left:10px;">Registros/página:</span>
        <input type="number" min="10" max="500" step="10" value="${pageSize}" onchange="actualizarTamPagina(this.value)" style="width:70px;margin-left:5px;">
        <span style="margin-left:10px;">Ir a:</span>
        <select onchange="mostrarPagina(this.value)" style="margin-left:5px;">`;
      for (let i = 1; i <= totalPages; i++) {
        html += `<option value="${i}" ${i===currentPage?'selected':''}>${i}</option>`;
      }
      html += `</select>`;
      div.innerHTML = html;
    }

    window.addEventListener('load', () => {
      ajustarAnchoCabecera();
      ajustarPieSobreFooter();
      ajustarEspacioInferiorTabla();
      renderFiltrosActivos();
      conectarEventosFiltros();
      mostrarPagina(1);
    });
    window.addEventListener('resize', () => {
      ajustarAnchoCabecera();
      ajustarPieSobreFooter();
      ajustarEspacioInferiorTabla();
    });
  </script>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

</body>
</html>
