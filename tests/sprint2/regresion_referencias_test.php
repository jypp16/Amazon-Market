<?php
require_once __DIR__ . '/bootstrap_test.php';
$failed = 0;
$passed = 0;
foreach ([Controllers\API\CompraApiController::class, Controllers\API\VentaApiController::class] as $class) {
    $reflection = new ReflectionClass($class);
    $controller = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('extraerReferenciaSolicitud');
    foreach ([null, '', '   ', 'corta', ['no-es-texto']] as $value) {
        try {
            $method->invoke($controller, ['referencia_solicitud' => $value]);
            echo '[FAIL] ' . $class . ' admite referencia inválida: ' . json_encode($value) . PHP_EOL;
            $failed++;
        } catch (Libraries\Core\InventarioException $e) {
            if ($e->getStatusCode() !== 422) throw $e;
            echo '[PASS] ' . $class . ' rechaza referencia inválida' . PHP_EOL;
            $passed++;
        }
    }
    $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] = 'cabecera-valida-123456';
    if ($method->invoke($controller, []) !== $_SERVER['HTTP_X_IDEMPOTENCY_KEY']) {
        $failed++;
    } else {
        $passed++;
        echo '[PASS] ' . $class . ' acepta referencia por cabecera' . PHP_EOL;
    }
    unset($_SERVER['HTTP_X_IDEMPOTENCY_KEY']);
}
echo "Regresión referencias: {$passed} PASS / {$failed} FAIL" . PHP_EOL;
exit($failed ? 1 : 0);
