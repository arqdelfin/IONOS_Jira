<?php
/**
 * Gestor de consultas a base de datos
 * IMPORTANTE: Usa prepared statements para prevenir SQL Injection
 */

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/security.php';

/**
 * Obtiene una consulta predefinida por ID.
 */
function get_consulta_predefinida_por_id($consulta_id) {
    global $conn;

    $stmt = $conn->prepare("SELECT id, consulta, query FROM t_consultasweb WHERE id = ? LIMIT 1");
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
 * Obtiene listado seguro de tablas disponibles
 */
function get_tablas_disponibles() {
    global $conn;
    $database = get_env('DB_NAME');
    
    $stmt = $conn->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ?");
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
 * Ejecuta una consulta SELECT con filtrado dinámico SEGURO
 * IMPORTANTE: Solo permite SELECT, usa prepared statements para filtros
 * 
 * @param string $tabla - Tabla a consultar (validada contra lista blanca)
 * @param array $columns - Columnas a retornar (['*'] o lista específica)
 * @param array $filtros - Filtros dinámicos
 */
function ejecutar_consulta_segura($tabla, $columns = ['*'], $filtros = []) {
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
    $where_conditions = [];
    $types = '';
    $params = [];
    
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
        
        switch ($operador) {
            case 'igual':
                $where_conditions[] = "`$columna` = ?";
                $types .= 's';
                $params[] = $filtro['valor'];
                break;
                
            case 'contiene':
                $where_conditions[] = "`$columna` LIKE ?";
                $types .= 's';
                $params[] = '%' . $filtro['valor'] . '%';
                break;
                
            case 'empieza':
                $where_conditions[] = "`$columna` LIKE ?";
                $types .= 's';
                $params[] = $filtro['valor'] . '%';
                break;
                
            case 'mayor':
                $where_conditions[] = "`$columna` > ?";
                $types .= 's';
                $params[] = $filtro['valor'];
                break;
                
            case 'menor':
                $where_conditions[] = "`$columna` < ?";
                $types .= 's';
                $params[] = $filtro['valor'];
                break;
                
            case 'fecha_entre':
                $desde = isset($filtro['desde']) ? trim((string)$filtro['desde']) : '';
                $hasta = isset($filtro['hasta']) ? trim((string)$filtro['hasta']) : '';

                if ($desde !== '' && $hasta !== '') {
                    $where_conditions[] = "`$columna` BETWEEN ? AND ?";
                    $types .= 'ss';
                    $params[] = $desde;
                    $params[] = $hasta;
                } elseif ($desde !== '') {
                    $where_conditions[] = "`$columna` >= ?";
                    $types .= 's';
                    $params[] = $desde;
                } elseif ($hasta !== '') {
                    $where_conditions[] = "`$columna` <= ?";
                    $types .= 's';
                    $params[] = $hasta;
                }
                break;
        }
    }
    
    // Agregar condiciones WHERE si existen
    if (!empty($where_conditions)) {
        $query .= ' WHERE ' . implode(' AND ', $where_conditions);
    }
    
    // Agregar LIMIT para prevenir sobrecarga
    $query .= ' LIMIT 1000';
    
    // Ejecutar con prepared statement
    $stmt = $conn->prepare($query);
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
                $query .= $prefijo . "`$filtro_columna` BETWEEN '$valor_desde' AND '$valor_hasta'";
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
