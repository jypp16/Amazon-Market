<?php
// Se ejecuta antes de abrir sesiones o conexiones; no expone rutas ni datos operativos.
$isolatedSprint3Test = PHP_SAPI === 'cli-server'
    && getenv('DB_NAME') === getenv('TEST_DB_NAME')
    && preg_match('/^amazon_market_sprint3_deploy_rehearsal_[0-9]+$/D', (string)getenv('DB_NAME'));
if (PHP_SAPI !== 'cli' && !$isolatedSprint3Test && is_file(dirname(__DIR__) . '/.codex/sprint3-maintenance.lock')) {
    http_response_code(503);
    header('Retry-After: 120');
    header('Cache-Control: no-store');
    $route = (string)($_GET['url'] ?? parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
    if (preg_match('~(?:^|/)api(?:/|$)~i', $route)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status'=>false,'code'=>'MAINTENANCE','message'=>'Actualización en curso. Intente nuevamente en unos minutos.','errors'=>new stdClass(),'data'=>null]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Mantenimiento</title><h1>Actualización en curso</h1><p>Intente nuevamente en unos minutos.</p></html>';
    }
    exit;
}
