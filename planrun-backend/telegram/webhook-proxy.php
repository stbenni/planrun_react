<?php
/**
 * Прокси для Telegram webhook — несколько ботов.
 * Размещается на VPS в EU/US.
 *
 * Пути: /webhook-proxy/planrun, /webhook-proxy/hday, /webhook-proxy/gpu-alert, /webhook-proxy/tsd
 * Обратная совместимость: /webhook-proxy.php → planrun
 *
 * Backend URLs в .env: WEBHOOK_BACKEND_PLANRUN, WEBHOOK_BACKEND_HDAY, WEBHOOK_BACKEND_GPU_ALERT, WEBHOOK_BACKEND_TSD
 */
header('Content-Type: application/json');

function proxy_env(string $key, string $default = ''): string {
    $v = getenv($key);
    if ($v !== false && $v !== '') return trim((string) $v);
    $envPath = __DIR__ . '/.env';
    if (is_file($envPath)) {
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                [$k, $val] = explode('=', $line, 2);
                if (trim($k) === $key) return trim(trim($val), " \t\"'");
            }
        }
    }
    return $default;
}

$secret = proxy_env('WEBHOOK_PROXY_SECRET');

$defaults = [
    'planrun'   => 'https://planrun.ru/bot/webhook-internal.php',
    'hday'      => 'https://alter-vision.ru/bots/hday/bot.php',
    'gpu-alert' => 'https://alter-vision.ru/bots/gpu-alert/bot.php',
    'tsd'       => 'https://alter-vision.ru/bots/tsd/tsd.php',
];

$routes = [
    'planrun'   => ['secret' => true],
    'hday'      => ['secret' => false],
    'gpu-alert' => ['secret' => false],
    'tsd'       => ['secret' => false],
];

foreach ($routes as $name => $opts) {
    $key = 'WEBHOOK_BACKEND_' . strtoupper(str_replace('-', '_', $name));
    $routes[$name]['url'] = proxy_env($key) ?: $defaults[$name];
}

$uri = explode('?', $_SERVER['REQUEST_URI'] ?? '', 2)[0];
$path = trim($uri, '/');
$parts = explode('/', $path);

if ($path === 'webhook-proxy.php' || $path === 'webhook-proxy') {
    $bot = 'planrun';
} else {
    $bot = $parts[1] ?? '';
}

if (!isset($routes[$bot])) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'unknown bot']);
    exit;
}

$route = $routes[$bot];
$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empty body']);
    exit;
}

http_response_code(200);
header('Connection: close');
header('Content-Length: 0');
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

$headers = ['Content-Type: application/json'];
if ($route['secret'] && $secret !== '') {
    $headers[] = 'X-Webhook-Proxy-Secret: ' . $secret;
}

$ch = curl_init($route['url']);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $rawBody,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 55,
]);
curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($code !== 200) {
    error_log("Webhook proxy [$bot]: backend returned $code");
}
