<?php
/**
 * Gestor de consultas a base de datos
 * IMPORTANTE: Usa prepared statements para prevenir SQL Injection
 */

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/security.php';

/**
 * Prepara una sentencia y transforma excepciones del driver en un fallo controlado.
 */
function preparar_consulta_controlada($sql, $contexto = 'consulta') {
    global $conn;

    try {
        $stmt = $conn->prepare($sql);
    } catch (Throwable $exception) {
        error_log('Error al preparar ' . $contexto . ': ' . $exception->getMessage());
        return null;
    }

    if (!$stmt) {
        error_log('Error al preparar ' . $contexto . ': ' . $conn->error);
        return null;
    }

    return $stmt;
}

/**
 * Obtiene una consulta predefinida por ID.
 */
function get_consulta_predefinida_por_id($consulta_id) {
    global $conn;

    $stmt = preparar_consulta_controlada("SELECT id, consulta, query FROM t_consultasweb WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("i", $consulta_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $consulta = $resultado ? $resultado->fetch_assoc() : null;
    $stmt->close();

    return $consulta ?: null;
}

/**
 * Valida y parsea una consulta SELECT con politica estricta.
 * Formato permitido: SELECT col1,col2 FROM tabla
 */
function parse_query_select_segura($query) {
    $query = trim((string)$query);
    $query = rtrim($query, "; \t\n\r\0\x0B");

    if ($query === '') {
        return ['error' => 'Consulta vacia'];
    }

    if (preg_match('/(--|#|\/\*)/', $query)) {
        return ['error' => 'Consulta no permitida'];
    }

    if (preg_match('/\b(union|insert|update|delete|drop|alter|create|grant|revoke|truncate|outfile|load_file|sleep|benchmark)\b/i', $query)) {
        return ['error' => 'Consulta no permitida'];
    }

    // Permite SELECT con clausulas seguras posteriores (WHERE, ORDER BY, LIMIT, etc.)
    // y captura al menos la tabla principal despues de FROM.
    if (!preg_match('/^SELECT\s+(.+?)\s+FROM\s+`?([a-zA-Z0-9_]+)`?(?:\s+.*)?$/i', $query, $matches)) {
        return ['error' => 'Formato de consulta no permitido'];
    }

    $columns_raw = trim($matches[1]);
    $tabla = $matches[2];

    $columns = [];
    if ($columns_raw === '*') {
        $columns = ['*'];
    } else {
        $partes = array_map('trim', explode(',', $columns_raw));
        foreach ($partes as $col) {
            $col = trim($col, "` ");
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $col)) {
                return ['error' => 'Columnas no permitidas en consulta'];
            }
            $columns[] = $col;
        }
    }

    return [
        'tabla' => $tabla,
        'columns' => $columns
    ];
}


/**
 * Valida una consulta administrada antes de usarla como conjunto base.
 *
 * La consulta permanece intacta (salvo un punto y coma final) y se ejecuta
 * dentro de una tabla derivada. Los filtros del usuario se agregan fuera de
 * esa tabla derivada, por lo que nunca reemplazan sus clausulas.
 */
function validar_consulta_predefinida($query) {
    $query = trim((string)$query);

    if ($query === '') {
        return ['error' => 'Consulta vacia'];
    }

    $analisis = analizar_sql_consulta_predefinida($query);
    if (isset($analisis['error'])) {
        return $analisis;
    }

    $tokens_superiores = $analisis['tokens_superiores'];
    if (empty($tokens_superiores) || $tokens_superiores[0]['word'] !== 'SELECT') {
        return ['error' => 'Formato de consulta no permitido'];
    }

    // Solo se admite un punto y coma terminal. Los de literales no llegan
    // aqui; los comentarios se rechazan de forma explicita en el analizador.
    $puntos_y_coma = $analisis['puntos_y_coma'];
    if (count($puntos_y_coma) > 1) {
        return ['error' => 'La consulta debe contener una unica sentencia SELECT'];
    }
    if (count($puntos_y_coma) === 1) {
        $punto_y_coma = $puntos_y_coma[0];
        foreach ($tokens_superiores as $token) {
            if ($token['start'] > $punto_y_coma) {
                return ['error' => 'La consulta debe contener una unica sentencia SELECT'];
            }
        }
        foreach ($analisis['simbolos_superiores'] as $simbolo) {
            if ($simbolo > $punto_y_coma) {
                return ['error' => 'La consulta debe contener una unica sentencia SELECT'];
            }
        }
        $query = rtrim(substr($query, 0, $punto_y_coma));
    }

    $operaciones_no_permitidas = [
        'UNION', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'MERGE', 'DROP',
        'ALTER', 'CREATE', 'GRANT', 'REVOKE', 'TRUNCATE', 'CALL', 'DO',
        'HANDLER', 'LOAD', 'LOAD_FILE', 'OUTFILE', 'DUMPFILE', 'SET', 'USE',
        'PREPARE', 'EXECUTE', 'DEALLOCATE', 'BEGIN', 'START', 'COMMIT',
        'ROLLBACK', 'SAVEPOINT', 'RELEASE', 'KILL', 'FLUSH', 'RESET',
        'ANALYZE', 'OPTIMIZE', 'REPAIR', 'CHECK', 'INSTALL', 'UNINSTALL',
        'SHUTDOWN', 'RESTART', 'XA', 'SIGNAL', 'RESIGNAL', 'SLEEP',
        'BENCHMARK', 'GET_LOCK', 'RELEASE_LOCK', 'IS_FREE_LOCK',
        'IS_USED_LOCK', 'LAST_INSERT_ID', 'MASTER_POS_WAIT'
    ];
    foreach ($analisis['tokens'] as $token) {
        if (in_array($token['word'], $operaciones_no_permitidas, true)) {
            return ['error' => 'Consulta no permitida'];
        }
    }
    if ($analisis['usa_variables'] || $analisis['usa_asignacion']) {
        return ['error' => 'Consulta no permitida'];
    }

    $orden = extraer_orden_superior_consulta_predefinida($query, $analisis);
    if (isset($orden['error'])) {
        return $orden;
    }

    return ['query' => $query, 'orden' => $orden['orden']];
}

/**
 * Analiza los elementos lexicos relevantes de una sentencia SQL sin confundir
 * literales ni identificadores entre backticks con instrucciones.
 * No pretende validar toda la gramatica SQL: solo aporta los limites que esta
 * capa necesita para componer una unica consulta SELECT de forma segura.
 */
function analizar_sql_consulta_predefinida($query) {
    $length = strlen($query);
    $depth = 0;
    $tokens = [];
    $tokens_superiores = [];
    $puntos_y_coma = [];
    $simbolos_superiores = [];
    $usa_variables = false;
    $usa_asignacion = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $query[$i];

        if (ctype_space($char)) {
            continue;
        }

        // Los comentarios no se admiten: los ejecutables de MySQL (/*! ... */)
        // pueden introducir instrucciones que un escaneo convencional omite.
        if (($char === '-' && $i + 2 < $length && $query[$i + 1] === '-'
                && ctype_space($query[$i + 2])) || $char === '#') {
            return ['error' => 'Los comentarios SQL no están permitidos'];
        }
        if ($char === '/' && $i + 1 < $length && $query[$i + 1] === '*') {
            return ['error' => 'Los comentarios SQL no están permitidos'];
        }
        if ($char === "'" || $char === '"' || $char === '`') {
            $delimitador = $char;
            $cerrado = false;
            for ($i++; $i < $length; $i++) {
                if ($query[$i] === '\\' && $delimitador !== '`') {
                    $i++;
                    continue;
                }
                if ($query[$i] !== $delimitador) {
                    continue;
                }
                if ($i + 1 < $length && $query[$i + 1] === $delimitador) {
                    $i++;
                    continue;
                }
                $cerrado = true;
                break;
            }
            if (!$cerrado) {
                return ['error' => 'Literal o identificador SQL sin cerrar'];
            }
            continue;
        }
        if ($char === '(') {
            if ($depth === 0) { $simbolos_superiores[] = $i; }
            $depth++;
            continue;
        }
        if ($char === ')') {
            if ($depth === 0) {
                return ['error' => 'Parentesis SQL sin abrir'];
            }
            $depth--;
            if ($depth === 0) { $simbolos_superiores[] = $i; }
            continue;
        }
        if ($char === ';') {
            if ($depth !== 0) {
                return ['error' => 'Punto y coma no permitido en la consulta'];
            }
            $puntos_y_coma[] = $i;
            continue;
        }
        if ($char === '@') {
            $usa_variables = true;
        }
        if ($char === ':' && $i + 1 < $length && $query[$i + 1] === '=') {
            $usa_asignacion = true;
        }
        if (ctype_alpha($char) || $char === '_') {
            $start = $i;
            while ($i + 1 < $length && (ctype_alnum($query[$i + 1]) || $query[$i + 1] === '_' || $query[$i + 1] === '$')) {
                $i++;
            }
            $token = ['word' => strtoupper(substr($query, $start, $i - $start + 1)), 'start' => $start, 'end' => $i + 1, 'depth' => $depth];
            $tokens[] = $token;
            if ($depth === 0) {
                $tokens_superiores[] = $token;
            }
            continue;
        }
        if ($depth === 0) {
            $simbolos_superiores[] = $i;
        }
    }

    if ($depth !== 0) {
        return ['error' => 'Parentesis SQL sin cerrar'];
    }

    return compact('tokens', 'tokens_superiores', 'puntos_y_coma', 'simbolos_superiores', 'usa_variables', 'usa_asignacion');
}

/**
 * Localiza ORDER BY al nivel superior usando el analisis lexico de la consulta.
 */
function extraer_orden_superior_consulta_predefinida($query, $analisis = null) {
    $analisis = $analisis ?: analizar_sql_consulta_predefinida($query);
    if (isset($analisis['error'])) {
        return $analisis;
    }
    $tokens = $analisis['tokens_superiores'];
    $length = strlen($query);

    for ($index = 0; $index < count($tokens) - 1; $index++) {
        if ($tokens[$index]['word'] !== 'ORDER' || $tokens[$index + 1]['word'] !== 'BY') { continue; }
        $end = $length;
        for ($next = $index + 2; $next < count($tokens); $next++) {
            if (in_array($tokens[$next]['word'], ['LIMIT', 'FOR', 'LOCK', 'PROCEDURE'], true)) {
                $end = $tokens[$next]['start'];
                break;
            }
        }
        $clause = trim(substr($query, $tokens[$index + 1]['end'], $end - $tokens[$index + 1]['end']));
        if ($clause === '') { return ['error' => 'ORDER BY incompleto en la consulta predefinida']; }
        return ['orden' => $clause];
    }

    return ['orden' => ''];
}

/**
 * Convierte un ORDER BY superior simple a una clausula segura para el resultado
 * derivado. Expresiones que no sobreviven fuera de la consulta base se rechazan
 * con un mensaje accionable en lugar de perder el orden almacenado.
 */
function construir_orden_consulta_predefinida($orden, $columnas) {
    if ($orden === '') { return ['sql' => '']; }
    $por_nombre = [];
    foreach ((array)$columnas as $columna) {
        $clave = strtolower((string)$columna);
        $por_nombre[$clave] = ($por_nombre[$clave] ?? 0) + 1;
    }
    $partes = preg_split('/,/', $orden);
    $normalizadas = [];
    foreach ($partes as $parte) {
        $parte = trim($parte);
        if ($parte === '') {
            return ['error' => 'El ORDER BY almacenado contiene un termino vacio'];
        }
        if (preg_match('/^([1-9][0-9]*)\s*(ASC|DESC)?$/i', $parte, $matches)) {
            $ordinal = (int)$matches[1];
            if ($ordinal > count($columnas)) {
                return ['error' => 'El ordinal del ORDER BY no corresponde a una columna publicada'];
            }
            $direccion = isset($matches[2]) && $matches[2] !== '' ? ' ' . strtoupper($matches[2]) : '';
            $normalizadas[] = $ordinal . $direccion;
            continue;
        }
        if (!preg_match('/^(?:`((?:``|[^`])+)`|([a-zA-Z_][a-zA-Z0-9_$]*))\s*(ASC|DESC)?$/i', $parte, $matches)) {
            return ['error' => 'El ORDER BY almacenado no es componible: use un alias de salida o un ordinal'];
        }
        $columna = isset($matches[1]) && $matches[1] !== '' ? str_replace('``', '`', $matches[1]) : $matches[2];
        if (($por_nombre[strtolower($columna)] ?? 0) !== 1) {
            return ['error' => 'El ORDER BY debe referirse a un alias único publicado por la consulta'];
        }
        $direccion = isset($matches[3]) && $matches[3] !== '' ? ' ' . strtoupper($matches[3]) : '';
        $normalizadas[] = '`' . str_replace('`', '``', $columna) . '`' . $direccion;
    }
    return ['sql' => ' ORDER BY ' . implode(', ', $normalizadas)];
}

/**
 * Obtiene las columnas publicadas por una consulta predefinida sin devolver
 * registros. Los filtros de usuario solo pueden referirse a estas columnas.
 */
function get_columnas_consulta_predefinida($query) {
    global $conn;

    $validacion = validar_consulta_predefinida($query);
    if (isset($validacion['error'])) {
        return $validacion;
    }

    $sql = 'SELECT * FROM (' . $validacion['query'] . ') AS consulta_base LIMIT 0';
    $stmt = preparar_consulta_controlada($sql);
    if (!$stmt) {
        error_log('Error al preparar metadatos de consulta predefinida: ' . $conn->error);
        return ['error' => 'Error en la consulta. Contacta al administrador.'];
    }

    try {
        $ejecutada = $stmt->execute();
    } catch (Throwable $exception) {
        error_log('Error al ejecutar metadatos de consulta predefinida: ' . $exception->getMessage());
        $stmt->close();
        return ['error' => 'Error en la consulta'];
    }
    if (!$ejecutada) {
        error_log('Error al ejecutar metadatos de consulta predefinida: ' . $stmt->error);
        $stmt->close();
        return ['error' => 'Error en la consulta'];
    }

    $metadata = $stmt->result_metadata();
    if (!$metadata) {
        $stmt->close();
        return ['error' => 'Error en la consulta'];
    }

    $columnas = [];
    while ($field = $metadata->fetch_field()) {
        $columnas[] = (string)$field->name;
    }
    $metadata->free();
    $stmt->close();

    if (empty($columnas)) {
        return ['error' => 'La consulta predefinida no devuelve columnas'];
    }

    $aliases = validar_aliases_consulta_predefinida($columnas);
    if (isset($aliases['error'])) {
        return $aliases;
    }

    return ['columnas' => $columnas];
}

/**
 * Impide que mysqli::fetch_assoc() descarte silenciosamente una columna
 * cuando una consulta publicada expone el mismo alias mas de una vez.
 */
function validar_aliases_consulta_predefinida($columnas) {
    $vistos = [];
    foreach ((array)$columnas as $columna) {
        $clave = strtolower((string)$columna);
        if (isset($vistos[$clave])) {
            return ['error' => 'La consulta predefinida debe publicar aliases únicos'];
        }
        $vistos[$clave] = true;
    }
    return ['ok' => true];
}

/**
 * Devuelve solo aliases que el backend puede usar de forma no ambigua.
 */
function get_columnas_filtrables_consulta_predefinida($columnas) {
    $conteo = [];
    foreach ((array)$columnas as $columna) {
        $clave = strtolower((string)$columna);
        $conteo[$clave] = ($conteo[$clave] ?? 0) + 1;
    }
    return array_values(array_filter((array)$columnas, function ($columna) use ($conteo) {
        return preg_match('/^[a-zA-Z0-9_]+$/', (string)$columna)
            && ($conteo[strtolower((string)$columna)] ?? 0) === 1;
    }));
}

/**
 * Construye filtros parameterizados sobre el resultado de una consulta base.
 */
function construir_filtros_consulta_predefinida($filtros, $columnas_permitidas) {
    $por_nombre = [];
    foreach ((array)$columnas_permitidas as $columna) {
        $clave = strtolower((string)$columna);
        $por_nombre[$clave][] = (string)$columna;
    }
    $fragments = [];

    foreach ((array)$filtros as $filtro) {
        if (!isset($filtro['columna'], $filtro['operador'])) {
            continue;
        }

        $columna = (string)$filtro['columna'];
        $coincidencias = $por_nombre[strtolower($columna)] ?? [];
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $columna) || count($coincidencias) !== 1) {
            return ['error' => 'El filtro requiere un alias único y compatible'];
        }
        $columna = $coincidencias[0];

        $operador = strtolower((string)$filtro['operador']);
        if (!in_array($operador, ['igual', 'contiene', 'empieza', 'mayor', 'menor', 'fecha_entre'], true)) {
            return ['error' => 'Filtro no permitido'];
        }
        $connector = strtoupper(trim((string)($filtro['conector'] ?? 'AND')));
        if (!in_array($connector, ['AND', 'OR'], true)) {
            $connector = 'AND';
        }

        $clause = null;
        $types = '';
        $params = [];

        switch ($operador) {
            case 'igual':
                if (!array_key_exists('valor', $filtro)) { break; }
                $clause = "CONVERT(`$columna` USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci";
                $types = 's';
                $params[] = $filtro['valor'];
                break;
            case 'contiene':
                if (!array_key_exists('valor', $filtro)) { break; }
                $clause = "CONVERT(`$columna` USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci";
                $types = 's';
                $params[] = '%' . $filtro['valor'] . '%';
                break;
            case 'empieza':
                if (!array_key_exists('valor', $filtro)) { break; }
                $clause = "CONVERT(`$columna` USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci";
                $types = 's';
                $params[] = $filtro['valor'] . '%';
                break;
            case 'mayor':
                if (!array_key_exists('valor', $filtro)) { break; }
                $clause = "`$columna` > ?";
                $types = 's';
                $params[] = $filtro['valor'];
                break;
            case 'menor':
                if (!array_key_exists('valor', $filtro)) { break; }
                $clause = "`$columna` < ?";
                $types = 's';
                $params[] = $filtro['valor'];
                break;
            case 'fecha_entre':
                $desde = isset($filtro['desde']) ? trim((string)$filtro['desde']) : '';
                $hasta = isset($filtro['hasta']) ? trim((string)$filtro['hasta']) : '';
                if ($desde !== '' && $hasta !== '') {
                    if ($desde === $hasta) {
                        $clause = "(`$columna` >= ? AND `$columna` < DATE_ADD(?, INTERVAL 1 DAY))";
                        $types = 'ss';
                        $params = [$desde, $desde];
                    } else {
                        $clause = "`$columna` BETWEEN ? AND ?";
                        $types = 'ss';
                        $params = [$desde, $hasta];
                    }
                } elseif ($desde !== '') {
                    $clause = "`$columna` >= ?";
                    $types = 's';
                    $params[] = $desde;
                } elseif ($hasta !== '') {
                    $clause = "`$columna` <= ?";
                    $types = 's';
                    $params[] = $hasta;
                }
                break;
        }

        if ($clause !== null) {
            $fragments[] = compact('connector', 'types', 'params', 'clause');
        }
    }

    $where = '';
    $types = '';
    $params = [];
    foreach ($fragments as $indice => $fragment) {
        $where = $indice === 0
            ? $fragment['clause']
            : '(' . $where . ' ' . $fragment['connector'] . ' ' . $fragment['clause'] . ')';
        $types .= $fragment['types'];
        foreach ($fragment['params'] as $param) {
            $params[] = $param;
        }
    }

    return ['where' => $where, 'types' => $types, 'params' => $params];
}

/**
 * Ejecuta una consulta predefinida completa y filtra solo su resultado.
 */
function ejecutar_consulta_predefinida($query, $filtros = [], $limite = 1000, $columnas_permitidas = []) {
    global $conn;

    $validacion = validar_consulta_predefinida($query);
    if (isset($validacion['error'])) {
        return $validacion;
    }

    if (empty($columnas_permitidas)) {
        $metadata = get_columnas_consulta_predefinida($validacion['query']);
        if (isset($metadata['error'])) {
            return $metadata;
        }
        $columnas_permitidas = $metadata['columnas'];
    }

    $filtros_sql = construir_filtros_consulta_predefinida($filtros, $columnas_permitidas);
    if (isset($filtros_sql['error'])) {
        return $filtros_sql;
    }
    $orden_sql = construir_orden_consulta_predefinida($validacion['orden'], $columnas_permitidas);
    if (isset($orden_sql['error'])) {
        return $orden_sql;
    }
    $sql = 'SELECT * FROM (' . $validacion['query'] . ') AS consulta_base';
    if ($filtros_sql['where'] !== '') {
        $sql .= ' WHERE ' . $filtros_sql['where'];
    }

    $sql .= $orden_sql['sql'];

    $limite_num = is_numeric($limite) ? (int)$limite : 1000;
    if ($limite_num > 0) {
        $sql .= ' LIMIT ' . $limite_num;
    }

    $stmt = preparar_consulta_controlada($sql);
    if (!$stmt) {
        error_log('Error al preparar consulta predefinida: ' . $conn->error);
        return ['error' => 'Error en la consulta. Contacta al administrador.'];
    }
    if ($filtros_sql['params']) {
        bind_params_stmt($stmt, $filtros_sql['types'], $filtros_sql['params']);
    }

    try {
        $ejecutada = $stmt->execute();
    } catch (Throwable $exception) {
        error_log('Error al ejecutar consulta predefinida: ' . $exception->getMessage());
        $stmt->close();
        return ['error' => 'Error en la consulta'];
    }
    if (!$ejecutada) {
        error_log('Error al ejecutar consulta predefinida: ' . $stmt->error);
        $stmt->close();
        return ['error' => 'Error en la consulta'];
    }

    $resultado = $stmt->get_result();
    if (!$resultado) {
        $stmt->close();
        return ['error' => 'Error en la consulta'];
    }

    $columnas = [];
    foreach ($resultado->fetch_fields() as $field) {
        $columnas[] = $field->name;
    }
    $aliases = validar_aliases_consulta_predefinida($columnas);
    if (isset($aliases['error'])) {
        $stmt->close();
        return $aliases;
    }
    $datos = [];
    while ($fila = $resultado->fetch_assoc()) {
        $datos[] = $fila;
    }
    $stmt->close();

    return ['datos' => $datos, 'total_registros' => count($datos), 'columnas' => $columnas];
}

/**
 * Cuenta el resultado de una consulta predefinida completa, con los mismos
 * filtros parameterizados que la vista y la exportacion.
 */
function contar_consulta_predefinida($query, $filtros = [], $columnas_permitidas = []) {
    global $conn;

    $validacion = validar_consulta_predefinida($query);
    if (isset($validacion['error'])) {
        return $validacion;
    }

    if (empty($columnas_permitidas)) {
        $metadata = get_columnas_consulta_predefinida($validacion['query']);
        if (isset($metadata['error'])) {
            return $metadata;
        }
        $columnas_permitidas = $metadata['columnas'];
    }

    $filtros_sql = construir_filtros_consulta_predefinida($filtros, $columnas_permitidas);
    if (isset($filtros_sql['error'])) {
        return $filtros_sql;
    }
    $sql = 'SELECT COUNT(*) AS total FROM (' . $validacion['query'] . ') AS consulta_base';
    if ($filtros_sql['where'] !== '') {
        $sql .= ' WHERE ' . $filtros_sql['where'];
    }

    $stmt = preparar_consulta_controlada($sql);
    if (!$stmt) {
        error_log('Error al preparar conteo de consulta predefinida: ' . $conn->error);
        return ['error' => 'Error en la consulta. Contacta al administrador.'];
    }
    if ($filtros_sql['params']) {
        bind_params_stmt($stmt, $filtros_sql['types'], $filtros_sql['params']);
    }

    try {
        $ejecutada = $stmt->execute();
    } catch (Throwable $exception) {
        error_log('Error al ejecutar conteo de consulta predefinida: ' . $exception->getMessage());
        $stmt->close();
        return ['error' => 'Error en la consulta'];
    }
    if (!$ejecutada) {
        error_log('Error al ejecutar conteo de consulta predefinida: ' . $stmt->error);
        $stmt->close();
        return ['error' => 'Error en la consulta'];
    }

    $resultado = $stmt->get_result();
    if (!$resultado) {
        $stmt->close();
        return ['error' => 'Error en la consulta'];
    }
    $fila = $resultado->fetch_assoc();
    $stmt->close();

    return ['total_registros' => isset($fila['total']) ? (int)$fila['total'] : 0];
}

/**
 * Ejecuta bind_param con numero variable de parametros por referencia.
 */
function bind_params_stmt($stmt, $types, $params) {
    $refs = [];
    $refs[] = $types;
    foreach ($params as $key => $value) {
        $refs[] = &$params[$key];
    }

    return call_user_func_array([$stmt, 'bind_param'], $refs);
}

/**
 * Normaliza filtros recibidos desde POST en una lista segura y compatible.
 */
function normalizar_filtros_desde_post($post, $max_filtros = 5) {
    $columnas = $post['filtro_columna'] ?? [];
    $operadores = $post['filtro_operador'] ?? [];
    $valores = $post['filtro_valor'] ?? [];
    $desdes = $post['filtro_desde'] ?? [];
    $hastas = $post['filtro_hasta'] ?? [];
    $conectores = $post['filtro_conector'] ?? [];

    if (!is_array($columnas)) { $columnas = [$columnas]; }
    if (!is_array($operadores)) { $operadores = [$operadores]; }
    if (!is_array($valores)) { $valores = [$valores]; }
    if (!is_array($desdes)) { $desdes = [$desdes]; }
    if (!is_array($hastas)) { $hastas = [$hastas]; }
    if (!is_array($conectores)) { $conectores = [$conectores]; }

    $filtros = [];
    $vistos = [];

    $total = max(count($columnas), count($operadores), count($valores), count($desdes), count($hastas));
    for ($i = 0; $i < $total && count($filtros) < $max_filtros; $i++) {
        $columna = isset($columnas[$i]) ? trim((string)$columnas[$i]) : '';
        $operador = isset($operadores[$i]) ? trim((string)$operadores[$i]) : '';
        $valor = isset($valores[$i]) ? trim((string)$valores[$i]) : '';
        $desde = isset($desdes[$i]) ? trim((string)$desdes[$i]) : '';
        $hasta = isset($hastas[$i]) ? trim((string)$hastas[$i]) : '';
        $conector = isset($conectores[$i]) ? strtoupper(trim((string)$conectores[$i])) : 'AND';

        if ($i === 0) {
            $conector = 'AND';
        } elseif (!in_array($conector, ['AND', 'OR'], true)) {
            $conector = 'AND';
        }

        if ($columna === '' || $operador === '') {
            continue;
        }

        $filtro = ['columna' => $columna, 'operador' => $operador, 'conector' => $conector];

        if ($operador === 'fecha' || $operador === 'fecha_entre') {
            if ($desde === '' && $hasta === '') {
                continue;
            }

            $filtro['operador'] = 'fecha_entre';
            $filtro['valor'] = '';
            $filtro['desde'] = $desde;
            $filtro['hasta'] = $hasta;
            $dedupeKey = 'fecha|' . $columna . '|' . $desde . '|' . $hasta;
        } else {
            if ($valor === '') {
                continue;
            }

            $filtro['valor'] = $valor;
            $dedupeKey = $operador . '|' . $columna . '|' . $valor;
        }

        if (isset($vistos[$dedupeKey])) {
            continue;
        }

        $vistos[$dedupeKey] = true;
        $filtros[] = $filtro;
    }

    return $filtros;
}

/**
 * Obtiene listado seguro de tablas disponibles
 */
function get_tablas_disponibles() {
    global $conn;
    $database = get_env('DB_NAME');
    
    $stmt = preparar_consulta_controlada("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ?");
    if (!$stmt) {
        return [];
    }
    
    $stmt->bind_param("s", $database);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    $tablas = [];
    while ($fila = $resultado->fetch_assoc()) {
        $tablas[] = $fila['TABLE_NAME'];
    }
    $stmt->close();
    
    return $tablas;
}

/**
 * Obtiene las columnas de una tabla concreta desde INFORMATION_SCHEMA.
 */
function get_columnas_tabla($tabla) {
    global $conn;
    $database = get_env('DB_NAME');

    $stmt = preparar_consulta_controlada("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("ss", $database, $tabla);
    $stmt->execute();
    $resultado = $stmt->get_result();

    $columnas = [];
    while ($fila = $resultado->fetch_assoc()) {
        $columnas[] = $fila['COLUMN_NAME'];
    }

    $stmt->close();
    return $columnas;
}

/**
 * Ejecuta una consulta SELECT con filtrado dinámico SEGURO
 * IMPORTANTE: Solo permite SELECT, usa prepared statements para filtros
 * 
 * @param string $tabla - Tabla a consultar (validada contra lista blanca)
 * @param array $columns - Columnas a retornar (['*'] o lista específica)
 * @param array $filtros - Filtros dinámicos
 */
function ejecutar_consulta_segura($tabla, $columns = ['*'], $filtros = [], $limite = 1000) {
    global $conn;
    
    // Validar tabla contra lista blanca
    $tablas_permitidas = get_tablas_disponibles();
    if (!in_array($tabla, $tablas_permitidas)) {
        return ['error' => 'Tabla no permitida'];
    }
    
    // Validar y escapar columnas (básico: solo caracteres alfanuméricos)
    $cols_seguras = [];
    foreach ((array)$columns as $col) {
        if ($col === '*' || preg_match('/^[a-zA-Z0-9_]+$/', $col)) {
            $cols_seguras[] = ($col === '*') ? '*' : "`$col`";
        }
    }
    
    if (empty($cols_seguras)) {
        $cols_seguras = ['*'];
    }
    
    $select = implode(',', $cols_seguras);
    $query = "SELECT $select FROM `$tabla`";
    
    // Construir condiciones WHERE con prepared statement
    $fragments = [];
    
    foreach ($filtros as $filtro) {
        if (!isset($filtro['columna']) || !isset($filtro['operador']) || !isset($filtro['valor'])) {
            continue;
        }
        
        $columna = $filtro['columna'];
        
        // Validar nombre de columna
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $columna)) {
            continue;
        }
        
        $operador = strtolower($filtro['operador']);
        $connector = strtoupper(trim((string)($filtro['conector'] ?? 'AND')));
        if (!in_array($connector, ['AND', 'OR'], true)) {
            $connector = 'AND';
        }

        $clause = null;
        $clause_types = '';
        $clause_params = [];
        
        switch ($operador) {
            case 'igual':
                $clause = "CONVERT(`$columna` USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci";
                $clause_types = 's';
                $clause_params[] = $filtro['valor'];
                break;
                
            case 'contiene':
                $clause = "CONVERT(`$columna` USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci";
                $clause_types = 's';
                $clause_params[] = '%' . $filtro['valor'] . '%';
                break;
                
            case 'empieza':
                $clause = "CONVERT(`$columna` USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci";
                $clause_types = 's';
                $clause_params[] = $filtro['valor'] . '%';
                break;
                
            case 'mayor':
                $clause = "`$columna` > ?";
                $clause_types = 's';
                $clause_params[] = $filtro['valor'];
                break;
                
            case 'menor':
                $clause = "`$columna` < ?";
                $clause_types = 's';
                $clause_params[] = $filtro['valor'];
                break;
                
            case 'fecha_entre':
                $desde = isset($filtro['desde']) ? trim((string)$filtro['desde']) : '';
                $hasta = isset($filtro['hasta']) ? trim((string)$filtro['hasta']) : '';

                if ($desde !== '' && $hasta !== '') {
                    if ($desde === $hasta) {
                        // Si la columna es DATETIME, incluir todo el dia: [desde 00:00:00, siguiente dia)
                        $clause = "(`$columna` >= ? AND `$columna` < DATE_ADD(?, INTERVAL 1 DAY))";
                        $clause_types = 'ss';
                        $clause_params[] = $desde;
                        $clause_params[] = $desde;
                    } else {
                        $clause = "`$columna` BETWEEN ? AND ?";
                        $clause_types = 'ss';
                        $clause_params[] = $desde;
                        $clause_params[] = $hasta;
                    }
                } elseif ($desde !== '') {
                    $clause = "`$columna` >= ?";
                    $clause_types = 's';
                    $clause_params[] = $desde;
                } elseif ($hasta !== '') {
                    $clause = "`$columna` <= ?";
                    $clause_types = 's';
                    $clause_params[] = $hasta;
                }
                break;
        }

        if ($clause !== null) {
            $fragments[] = [
                'connector' => $connector,
                'types' => $clause_types,
                'params' => $clause_params,
                'sql' => $clause,
            ];
        }
    }
    
    $types = '';
    $params = [];
    if (!empty($fragments)) {
        $where_sql = '';

        foreach ($fragments as $indice => $fragment) {
            if ($indice === 0) {
                $where_sql .= $fragment['sql'];
            } else {
                $where_sql = '(' . $where_sql . ' ' . $fragment['connector'] . ' ' . $fragment['sql'] . ')';
            }

            $types .= $fragment['types'];
            foreach ($fragment['params'] as $param) {
                $params[] = $param;
            }
        }

        $query .= ' WHERE ' . $where_sql;
    }

    // Agregar LIMIT para prevenir sobrecarga (por defecto 1000, opcionalmente sin limite)
    $limite_num = is_numeric($limite) ? (int)$limite : 1000;
    if ($limite_num > 0) {
        $query .= ' LIMIT ' . $limite_num;
    }

    // Ejecutar con prepared statement
    $stmt = preparar_consulta_controlada($query);
    if (!$stmt) {
        error_log("Error en prepared statement: " . $conn->error);
        return ['error' => 'Error en la consulta. Contacta al administrador.'];
    }

    // Bind parameters si existen
    if (!empty($params)) {
        bind_params_stmt($stmt, $types, $params);
    }

    $stmt->execute();
    $resultado = $stmt->get_result();

    if (!$resultado) {
        error_log("Error en get_result: " . $conn->error);
        return ['error' => 'Error en la consulta'];
    }
    
    $datos = [];
    while ($fila = $resultado->fetch_assoc()) {
        $datos[] = $fila;
    }
    
    $stmt->close();
    
    return [
        'datos' => $datos,
        'total_registros' => count($datos)
    ];
}

/**
 * Cuenta registros de una tabla permitida aplicando los mismos filtros seguros.
 */
function contar_consulta_segura($tabla, $filtros = []) {
    global $conn;

    $tablas_permitidas = get_tablas_disponibles();
    if (!in_array($tabla, $tablas_permitidas, true)) {
        return ['error' => 'Tabla no permitida'];
    }

    $query = "SELECT COUNT(*) AS total FROM `$tabla`";
    $fragments = [];

    foreach ($filtros as $filtro) {
        if (!isset($filtro['columna']) || !isset($filtro['operador']) || !isset($filtro['valor'])) {
            continue;
        }

        $columna = $filtro['columna'];
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $columna)) {
            continue;
        }

        $operador = strtolower($filtro['operador']);
        $connector = strtoupper(trim((string)($filtro['conector'] ?? 'AND')));
        if (!in_array($connector, ['AND', 'OR'], true)) {
            $connector = 'AND';
        }

        $clause = null;
        $clause_types = '';
        $clause_params = [];

        switch ($operador) {
            case 'igual':
                $clause = "CONVERT(`$columna` USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci";
                $clause_types = 's';
                $clause_params[] = $filtro['valor'];
                break;

            case 'contiene':
                $clause = "CONVERT(`$columna` USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci";
                $clause_types = 's';
                $clause_params[] = '%' . $filtro['valor'] . '%';
                break;

            case 'empieza':
                $clause = "CONVERT(`$columna` USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci";
                $clause_types = 's';
                $clause_params[] = $filtro['valor'] . '%';
                break;

            case 'mayor':
                $clause = "`$columna` > ?";
                $clause_types = 's';
                $clause_params[] = $filtro['valor'];
                break;

            case 'menor':
                $clause = "`$columna` < ?";
                $clause_types = 's';
                $clause_params[] = $filtro['valor'];
                break;

            case 'fecha_entre':
                $desde = isset($filtro['desde']) ? trim((string)$filtro['desde']) : '';
                $hasta = isset($filtro['hasta']) ? trim((string)$filtro['hasta']) : '';

                if ($desde !== '' && $hasta !== '') {
                    if ($desde === $hasta) {
                        $clause = "(`$columna` >= ? AND `$columna` < DATE_ADD(?, INTERVAL 1 DAY))";
                        $clause_types = 'ss';
                        $clause_params[] = $desde;
                        $clause_params[] = $desde;
                    } else {
                        $clause = "`$columna` BETWEEN ? AND ?";
                        $clause_types = 'ss';
                        $clause_params[] = $desde;
                        $clause_params[] = $hasta;
                    }
                } elseif ($desde !== '') {
                    $clause = "`$columna` >= ?";
                    $clause_types = 's';
                    $clause_params[] = $desde;
                } elseif ($hasta !== '') {
                    $clause = "`$columna` <= ?";
                    $clause_types = 's';
                    $clause_params[] = $hasta;
                }
                break;
        }

        if ($clause !== null) {
            $fragments[] = [
                'connector' => $connector,
                'types' => $clause_types,
                'params' => $clause_params,
                'sql' => $clause,
            ];
        }
    }

    $types = '';
    $params = [];
    if (!empty($fragments)) {
        $where_sql = '';

        foreach ($fragments as $indice => $fragment) {
            if ($indice === 0) {
                $where_sql .= $fragment['sql'];
            } else {
                $where_sql = '(' . $where_sql . ' ' . $fragment['connector'] . ' ' . $fragment['sql'] . ')';
            }

            $types .= $fragment['types'];
            foreach ($fragment['params'] as $param) {
                $params[] = $param;
            }
        }

        $query .= ' WHERE ' . $where_sql;
    }

    $stmt = preparar_consulta_controlada($query);
    if (!$stmt) {
        error_log("Error en prepared statement de conteo: " . $conn->error);
        return ['error' => 'Error en la consulta. Contacta al administrador.'];
    }

    if (!empty($params)) {
        bind_params_stmt($stmt, $types, $params);
    }

    $stmt->execute();
    $resultado = $stmt->get_result();
    if (!$resultado) {
        error_log("Error en get_result de conteo: " . $conn->error);
        return ['error' => 'Error en la consulta'];
    }

    $fila = $resultado->fetch_assoc();
    $stmt->close();

    return [
        'total_registros' => isset($fila['total']) ? (int)$fila['total'] : 0
    ];
}

/**
 * Función compatible para consultas legadas (DEPRECATED)
 * Úsala solo para transición, usa ejecutar_consulta_segura() para código nuevo
 */
function ejecutar_consulta($query,
                           $filtro_columna = '',
                           $filtro_operador = '',
                           $filtro_valor = '',
                           $filtro_desde = '',
                           $filtro_hasta = '') {
    global $conn;
    
    // NOTA: Esta función es vulnerable y solo se mantiene para compatibilidad
    // Debes migrar a ejecutar_consulta_segura()
    
    error_log("ADVERTENCIA: ejecutar_consulta() es deprecated. Usa ejecutar_consulta_segura()");
    
    $query = rtrim($query, '; ');
    
    // Filtro dinámico (con validación mejorada)
    if ($filtro_columna && preg_match('/^[a-zA-Z0-9_]+$/', $filtro_columna)) {
        $hayWhere = stripos($query, ' where ') !== false;
        $prefijo = $hayWhere ? ' AND ' : ' WHERE ';

        if ($filtro_operador === 'fecha') {
            if ($filtro_desde && $filtro_hasta) {
                $valor_desde = $conn->real_escape_string($filtro_desde);
                $valor_hasta = $conn->real_escape_string($filtro_hasta);
                if ($valor_desde === $valor_hasta) {
                    $query .= $prefijo . "(`$filtro_columna` >= '$valor_desde' AND `$filtro_columna` < DATE_ADD('$valor_desde', INTERVAL 1 DAY))";
                } else {
                    $query .= $prefijo . "`$filtro_columna` BETWEEN '$valor_desde' AND '$valor_hasta'";
                }
            } elseif ($filtro_desde) {
                $valor_desde = $conn->real_escape_string($filtro_desde);
                $query .= $prefijo . "`$filtro_columna` >= '$valor_desde'";
            } elseif ($filtro_hasta) {
                $valor_hasta = $conn->real_escape_string($filtro_hasta);
                $query .= $prefijo . "`$filtro_columna` <= '$valor_hasta'";
            }
        } else {
            $valor = $conn->real_escape_string($filtro_valor);
            switch ($filtro_operador) {
                case 'igual':
                    $query .= $prefijo . "`$filtro_columna` = '$valor'";
                    break;
                case 'contiene':
                    $query .= $prefijo . "`$filtro_columna` LIKE '%$valor%'";
                    break;
                case 'empieza':
                    $query .= $prefijo . "`$filtro_columna` LIKE '$valor%'";
                    break;
                case 'mayor':
                    $query .= $prefijo . "`$filtro_columna` > '$valor'";
                    break;
                case 'menor':
                    $query .= $prefijo . "`$filtro_columna` < '$valor'";
                    break;
            }
        }
    }

    $resultado = $conn->query($query);

    if (!$resultado) {
        error_log("Error en la consulta: " . $conn->error);
        return ['error' => 'Error en la consulta: ' . $conn->error];
    }

    $datos = [];
    while ($fila = $resultado->fetch_assoc()) {
        $datos[] = $fila;
    }

    return [
        'datos' => $datos,
        'total_registros' => count($datos)
    ];
}
