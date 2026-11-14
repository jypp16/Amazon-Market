<?php
declare(strict_types=1);

/**
 * Worker de concurrencia HTTP para Paso 8 (egresos simultáneos, correcciones simultáneas,
 * reintentos simultáneos con la misma referencia).
 */

$options = getopt('', [
    'base-url:',
    'cookie-file:',
    'csrf:',
    'path:',
    'payload-b64:',
    'start-at:',
]);

$baseUrl = (string)($options['base-url'] ?? '');
$cookieFile = (string)($options['cookie-file'] ?? '');
$csrf = (string)($options['csrf'] ?? '');
$path = (string)($options['path'] ?? '');
$payloadJson = base64_decode((string)($options['payload-b64'] ?? ''), true);
$startAt = (float)($options['start-at'] ?? 0);

if ($baseUrl === '' || $cookieFile === '' || $path === '' || $payloadJson === false) {
    fwrite(STDERR, json_encode(['error' => 'Argumentos inválidos']) . "\n");
    exit(2);
}

while (microtime(true) < $startAt) {
    usleep(5000);
}

$ch = curl_init($baseUrl . $path);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-CSRF-Token: ' . $csrf,
]);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);

$raw = curl_exec($ch);
$err = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$json = is_string($raw) ? json_decode($raw, true) : null;
echo json_encode([
    'http_code' => $httpCode,
    'error'     => $err,
    'json'      => is_array($json) ? $json : null,
    'raw'       => is_string($raw) ? $raw : '',
], JSON_UNESCAPED_UNICODE) . "\n";
