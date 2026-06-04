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

$filtros_activos = normalizar_filtros_desde_post($_POST);
$columnas_disponibles = !empty($parse['columns']) && $parse['columns'] !== ['*']
  ? $parse['columns']
  : get_columnas_tabla($parse['tabla']);

$resultado = ejecutar_consulta_segura($parse['tabla'], $parse['columns'], $filtros_activos);
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
    margin-top: 12px;
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
  .filtro-modal {
    width: min(520px, calc(100vw - 32px));
    background: #fff;
    border-radius: 10px;
    padding: 18px;
    box-shadow: 0 12px 30px rgba(0,0,0,.22);
  }
  .filtro-modal h3 {
    margin-top: 0;
  }
  .filtro-modal label {
    display: block;
    margin-top: 10px;
    margin-bottom: 6px;
  }
  .filtro-modal select,
  .filtro-modal input {
    width: 100%;
    box-sizing: border-box;
    padding: 8px;
  }
</style>
</head>
<body>

<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="info-consulta">
  Consulta ejecutada el <?php echo $fecha; ?> · <?php echo $registros; ?> registros devueltos.
  <div class="filtros-activos">
    <h3>Filtros activos</h3>
    <div class="filtros-lista" id="filtrosLista">
      <?php if (!empty($filtros_activos)): ?>
        <?php foreach ($filtros_activos as $indice => $filtro): ?>
          <span class="filtro-chip" data-indice="<?php echo (int)$indice; ?>">
            <span>
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
    <div class="acciones-filtros">
      <button type="button" id="btnAgregarFiltro">Agregar filtro</button>
      <?php if (!empty($filtros_activos)): ?>
        <button type="button" id="btnLimpiarFiltros">Limpiar filtros</button>
      <?php endif; ?>
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
                  <?php echo htmlspecialchars($col); ?>
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
      <form method="POST" action="../includes/exportar_csv.php" style="margin:0;">
        <input type="hidden" name="consulta_id" value="<?php echo (int)$consulta_id; ?>">
        <input type="hidden" name="consulta_nombre" value="<?php echo htmlspecialchars($consulta_nombre); ?>">
        <?php foreach ($filtros_activos as $filtro): ?>
          <input type="hidden" name="filtro_columna[]" value="<?php echo htmlspecialchars($filtro['columna']); ?>">
          <input type="hidden" name="filtro_operador[]" value="<?php echo htmlspecialchars($filtro['operador']); ?>">
          <input type="hidden" name="filtro_valor[]" value="<?php echo htmlspecialchars($filtro['valor'] ?? ''); ?>">
          <input type="hidden" name="filtro_desde[]" value="<?php echo htmlspecialchars($filtro['desde'] ?? ''); ?>">
          <input type="hidden" name="filtro_hasta[]" value="<?php echo htmlspecialchars($filtro['hasta'] ?? ''); ?>">
        <?php endforeach; ?>
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
    const filtrosActivos = <?php echo json_encode(array_values($filtros_activos), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const columnasDisponibles = <?php echo json_encode(array_values($columnas_disponibles), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const maxFiltros = 5;

    function ajustarAnchoCabecera() {
      const bodyDiv = document.querySelector('.tabla-body');
      const headerDiv = document.querySelector('.tabla-header');
      if (!bodyDiv || !headerDiv) return;
      const scrollBarWidth = bodyDiv.offsetWidth - bodyDiv.clientWidth;
      headerDiv.style.paddingRight = scrollBarWidth > 0 ? scrollBarWidth + 'px' : '0';
    }

    function getFiltroForm() {
      return document.getElementById('filtroForm');
    }

    function limpiarFiltrosDelFormulario(form) {
      form.querySelectorAll('input[data-dynamic-filter="1"]').forEach((elemento) => elemento.remove());
    }

    function appendFiltroAlFormulario(form, filtro) {
      const campos = ['filtro_columna[]', 'filtro_operador[]', 'filtro_valor[]', 'filtro_desde[]', 'filtro_hasta[]'];
      const valores = [filtro.columna || '', filtro.operador || '', filtro.valor || '', filtro.desde || '', filtro.hasta || ''];

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
      modal.className = 'filtro-modal';

      const titulo = document.createElement('h3');
      titulo.textContent = 'Añadir filtro';

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

      const botones = document.createElement('div');
      botones.style.marginTop = '15px';
      botones.style.textAlign = 'right';

      const btnAplicar = document.createElement('button');
      btnAplicar.type = 'button';
      btnAplicar.textContent = 'Aplicar';

      const btnCancelar = document.createElement('button');
      btnCancelar.type = 'button';
      btnCancelar.textContent = 'Cancelar';
      btnCancelar.style.marginLeft = '8px';

      function pintarCampoValor() {
        contenedorValor.innerHTML = '';
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
          const inputValor = document.createElement('input');
          inputValor.type = 'text';
          inputValor.id = 'modal_valor';
          inputValor.placeholder = 'Valor...';
          contenedorValor.appendChild(inputValor);
        }
      }

      function obtenerFiltroDesdeModal() {
        const columna = selectColumna.value.trim();
        const operador = selectOperador.value;

        if (!columna || !operador) {
          return null;
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
            hasta: hasta
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
          hasta: ''
        };
      }

      selectOperador.addEventListener('change', pintarCampoValor);
      btnCancelar.addEventListener('click', () => backdrop.remove());
      btnAplicar.addEventListener('click', () => {
        if (filtrosActivos.length >= maxFiltros) {
          alert('Solo puedes aplicar un máximo de ' + maxFiltros + ' filtros.');
          return;
        }

        const filtro = obtenerFiltroDesdeModal();
        if (!filtro) {
          alert('Completa el filtro antes de aplicarlo.');
          return;
        }

        enviarFiltros(filtrosActivos.concat([filtro]));
      });

      const acciones = document.createElement('div');
      acciones.style.marginTop = '15px';
      acciones.style.textAlign = 'right';
      acciones.appendChild(btnAplicar);
      acciones.appendChild(btnCancelar);

      modal.appendChild(titulo);
      modal.appendChild(labelColumna);
      modal.appendChild(selectColumna);
      modal.appendChild(labelOperador);
      modal.appendChild(selectOperador);
      modal.appendChild(contenedorValor);
      modal.appendChild(acciones);
      backdrop.appendChild(modal);
      document.body.appendChild(backdrop);

      pintarCampoValor();
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
    const pageSize = 100;
    let currentPage = 1;
    const totalPages = Math.max(1, Math.ceil(filas.length / pageSize));

    function mostrarPagina(num) {
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
      if (totalPages <= 1) { div.innerHTML = ''; return; }
      let html = `
        <button onclick="mostrarPagina(1)" ${currentPage===1?'disabled':''}>&laquo;</button>
        <button onclick="mostrarPagina(${currentPage-1})" ${currentPage===1?'disabled':''}>&lsaquo;</button>
        <span style="margin:0 8px;">Página ${currentPage} de ${totalPages}</span>
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

    window.addEventListener('load', () => {
      ajustarAnchoCabecera();
      renderFiltrosActivos();
      conectarEventosFiltros();
      mostrarPagina(1);
    });
    window.addEventListener('resize', ajustarAnchoCabecera);
  </script>

<?php endif; ?>

</body>
</html>
