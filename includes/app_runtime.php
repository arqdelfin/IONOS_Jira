<?php
/**
 * Utilidades runtime de seguridad: rate limiting, auditoria y respuestas seguras.
 */

function app_get_client_ip() {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $raw = (string)$_SERVER[$key];
            $ip = trim(explode(',', $raw)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

function app_logs_dir() {
    return __DIR__ . '/../logs';
}

function app_ensure_logs_dir() {
    $dir = app_logs_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

function app_env_int($key, $default) {
    $value = get_env($key, (string)$default);
    return is_numeric($value) ? (int)$value : (int)$default;
}

function app_audit_log($event, $status = 'ok', $details = []) {
    app_ensure_logs_dir();

    $record = [
        'ts' => date('c'),
        'event' => (string)$event,
        'status' => (string)$status,
        'ip' => app_get_client_ip(),
        'user' => isset($_SESSION['usuario']) ? (string)$_SESSION['usuario'] : null,
        'details' => is_array($details) ? $details : []
    ];

    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents(app_logs_dir() . '/audit.log', $line, FILE_APPEND | LOCK_EX);
}

function app_error_log($message, $context = []) {
    $ctx = is_array($context) ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    error_log('[APP] ' . $message . ($ctx ? ' ' . $ctx : ''));
}

function app_respond_text_error($message, $httpCode = 400) {
    http_response_code((int)$httpCode);
    echo $message;
    exit;
}

function app_respond_json_error($message, $httpCode = 400) {
    http_response_code((int)$httpCode);
    echo json_encode(['status' => 'error', 'mensaje' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function app_rate_limit_file() {
    app_ensure_logs_dir();
    return app_logs_dir() . '/login_rate_limit.json';
}

function app_login_rate_limit_check($usuario, $ip) {
    $maxAttempts = app_env_int('LOGIN_MAX_ATTEMPTS', 5);
    $windowSeconds = app_env_int('LOGIN_WINDOW_SECONDS', 900);
    $blockSeconds = app_env_int('LOGIN_BLOCK_SECONDS', 900);

    $path = app_rate_limit_file();
    $fp = fopen($path, 'c+');
    if (!$fp) {
        return ['blocked' => false, 'remaining' => $maxAttempts, 'retry_after' => 0];
    }

    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = $raw ? json_decode($raw, true) : [];
    if (!is_array($data)) {
        $data = [];
    }

    $key = strtolower(trim((string)$usuario)) . '|' . (string)$ip;
    $now = time();
    $entry = isset($data[$key]) && is_array($data[$key]) ? $data[$key] : ['fails' => [], 'blocked_until' => 0];

    $fails = [];
    foreach (($entry['fails'] ?? []) as $ts) {
        if (is_numeric($ts) && ((int)$ts) > ($now - $windowSeconds)) {
            $fails[] = (int)$ts;
        }
    }

    $blockedUntil = (int)($entry['blocked_until'] ?? 0);
    $blocked = $blockedUntil > $now;
    $remaining = max(0, $maxAttempts - count($fails));
    $retryAfter = $blocked ? ($blockedUntil - $now) : 0;

    $data[$key] = ['fails' => $fails, 'blocked_until' => $blockedUntil];
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));

    flock($fp, LOCK_UN);
    fclose($fp);

    return ['blocked' => $blocked, 'remaining' => $remaining, 'retry_after' => $retryAfter];
}

function app_login_rate_limit_record_failure($usuario, $ip) {
    $maxAttempts = app_env_int('LOGIN_MAX_ATTEMPTS', 5);
    $windowSeconds = app_env_int('LOGIN_WINDOW_SECONDS', 900);
    $blockSeconds = app_env_int('LOGIN_BLOCK_SECONDS', 900);

    $path = app_rate_limit_file();
    $fp = fopen($path, 'c+');
    if (!$fp) {
        return ['blocked' => false, 'remaining' => max(0, $maxAttempts - 1), 'retry_after' => 0];
    }

    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = $raw ? json_decode($raw, true) : [];
    if (!is_array($data)) {
        $data = [];
    }

    $key = strtolower(trim((string)$usuario)) . '|' . (string)$ip;
    $now = time();
    $entry = isset($data[$key]) && is_array($data[$key]) ? $data[$key] : ['fails' => [], 'blocked_until' => 0];

    $fails = [];
    foreach (($entry['fails'] ?? []) as $ts) {
        if (is_numeric($ts) && ((int)$ts) > ($now - $windowSeconds)) {
            $fails[] = (int)$ts;
        }
    }

    $fails[] = $now;
    $blockedUntil = 0;
    if (count($fails) >= $maxAttempts) {
        $blockedUntil = $now + $blockSeconds;
        $fails = [];
    }

    $data[$key] = ['fails' => $fails, 'blocked_until' => $blockedUntil];
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));

    flock($fp, LOCK_UN);
    fclose($fp);

    return [
        'blocked' => $blockedUntil > $now,
        'remaining' => max(0, $maxAttempts - count($fails)),
        'retry_after' => $blockedUntil > $now ? ($blockedUntil - $now) : 0
    ];
}

function app_login_rate_limit_reset($usuario, $ip) {
    $path = app_rate_limit_file();
    $fp = fopen($path, 'c+');
    if (!$fp) {
        return;
    }

    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = $raw ? json_decode($raw, true) : [];
    if (!is_array($data)) {
        $data = [];
    }

    $key = strtolower(trim((string)$usuario)) . '|' . (string)$ip;
    unset($data[$key]);

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));

    flock($fp, LOCK_UN);
    fclose($fp);
}
