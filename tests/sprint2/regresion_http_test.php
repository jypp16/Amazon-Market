<?php
/** Pruebas HTTP contra el servidor aislado. Fixtures y limpieza solo en BD test. */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/bootstrap_test.php';
$base = 'http://127.0.0.1:8092';
$sessionDir = sys_get_temp_dir() . '/amazon-market-sprint2-sessions';
if (!is_dir($sessionDir)) throw new RuntimeException('Inicie primero servidor_pruebas.ps1.');
session_write_close();
session_save_path($sessionDir);
function testSession(int $user): array {
    session_id('');
    session_start();
    $_SESSION = ['login' => true, 'idUser' => $user, 'rol' => 1, 'csrf_token' => bin2hex(random_bytes(32))];
    $result = ['cookie' => session_name() . '=' . session_id(), 'csrf' => $_SESSION['csrf_token']];
    session_write_close();
    return $result;
}
$admin = testSession(1);
$sellerId = (int)$pdoTest->query('SELECT id_usuario FROM usuario WHERE estado=1 AND id_rol=2 LIMIT 1')->fetchColumn();
if (!$sellerId) throw new RuntimeException('Falta un vendedor activo en la copia de pruebas.');
$seller = testSession($sellerId);
$failed = 0;
$passed = 0;
$references = [];
$snapshots = [];
function httpReference(string $kind): string {
    global $references;
    $ref = 'http-' . $kind . '-' . bin2hex(random_bytes(10));
    $references[] = $ref;
    return $ref;
}
function checkHttp(string $name, bool $ok): void {
    global $failed, $passed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
}
function httpTest(string $path, string $method = 'GET', ?array $body = null, ?array $session = null): array {
    global $base;
    $headers = "Accept: application/json\r\nContent-Type: application/json\r\n";
    if ($session) $headers .= 'Cookie: ' . $session['cookie'] . "\r\nX-CSRF-Token: " . $session['csrf'] . "\r\n";
    $context = stream_context_create(['http' => ['method' => $method, 'header' => $headers, 'content' => $body === null ? '' : json_encode($body), 'ignore_errors' => true, 'timeout' => 15]]);
    $raw = file_get_contents($base . $path, false, $context);
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $status);
    return ['status' => (int)($status[1] ?? 0), 'json' => json_decode($raw ?: '', true), 'raw' => $raw];
}
$idProduct = null;
$correlativo = $pdoTest->query('SELECT id_serie, id_tipo_comprobante, serie, correlativo_actual FROM serie_comprobante')->fetchAll(PDO::FETCH_ASSOC);
try {
    $anonymous = httpTest('/api/productos');
    checkHttp('API exige sesión (401 JSON)', $anonymous['status'] === 401 && $anonymous['json']['code'] === 'AUTH_REQUIRED');
    $products = httpTest('/api/productos?limit=2', 'GET', null, $admin);
    checkHttp('Productos responde JSON real en la base de pruebas', $products['status'] === 200 && $products['json']['status'] === true && count($products['json']['data']) === 2);
    $forbidden = httpTest('/api/reportes/variaciones?fecha_inicio=2026-10-01&fecha_fin=2026-10-31', 'GET', null, $seller);
    checkHttp('Vendedor no puede consultar Variaciones', $forbidden['status'] === 403 && $forbidden['json']['code'] === 'FORBIDDEN_ADMIN_ONLY');
    checkHttp('Router no expone .env', httpTest('/.env')['status'] === 403);
    checkHttp('Router no ejecuta las suites por HTTP', httpTest('/tests/sprint2/fase2_infraestructura_test.php')['status'] === 403);

    $product = (new Services\ProductoService())->crear(['codigo_barra' => 'TST-HTTP-' . bin2hex(random_bytes(5)), 'nombre' => 'Regresión HTTP', 'id_categoria' => 1, 'id_subcategoria' => 1, 'id_unidad' => 1, 'precio_venta' => 5, 'stock_minimo' => 0]);
    $idProduct = (int)$product['id_producto'];
    $purchase = ['id_proveedor' => 1, 'id_tipo_comprobante' => 1, 'serie' => 'TSTH', 'numero' => bin2hex(random_bytes(4)), 'fecha_emision' => date('Y-m-d'), 'fecha_recepcion' => date('Y-m-d'), 'total_documento' => 60,
        'detalles' => [['id_producto' => $idProduct, 'id_presentacion' => 1, 'cantidad_presentaciones' => 12, 'equivalencia_aplicada' => 1, 'importe_final_linea' => 60]]];
    $missing = httpTest('/api/compras', 'POST', $purchase, $admin);
    checkHttp('Compra sin referencia rechazada con 422', $missing['status'] === 422 && isset($missing['json']['errors']['referencia_solicitud']));
    $purchase['referencia_solicitud'] = httpReference('compra');
    $created = httpTest('/api/compras', 'POST', $purchase, $admin);
    $purchaseId = (int)($created['json']['id_compra'] ?? $created['json']['data']['id_compra'] ?? 0);
    checkHttp('Compra confirmada (201)', $created['status'] === 201 && $purchaseId > 0);
    if (!$purchaseId) throw new RuntimeException('No se pudo preparar compra HTTP: ' . json_encode($created['json']));
    $stock = fn() => (int)$pdoTest->query("SELECT stock_actual FROM producto WHERE id_producto={$idProduct}")->fetchColumn();
    $replay = httpTest('/api/compras', 'POST', $purchase, $admin);
    checkHttp('Reintento compra responde 200 sin duplicar entrada', $replay['status'] === 200 && $replay['json']['idempotent_replay'] === true && $stock() === 12);
    $sale = ['id_cliente' => 1, 'id_tipo_comprobante' => 1, 'id_metodo_pago' => 1, 'productos' => [['id_producto' => $idProduct, 'cantidad' => 2]]];
    checkHttp('Venta sin referencia rechazada con 422', httpTest('/api/ventas', 'POST', $sale, $admin)['status'] === 422);
    $sale['referencia_solicitud'] = httpReference('venta');
    $sold = httpTest('/api/ventas', 'POST', $sale, $admin);
    checkHttp('Venta real descuenta 2 unidades', $sold['status'] === 201 && $stock() === 10);
    $replay = httpTest('/api/ventas', 'POST', $sale, $admin);
    checkHttp('Reintento venta no duplica salida ni comprobante', $replay['status'] === 200 && $replay['json']['idempotent_replay'] === true && $stock() === 10);
    $correction = $purchase;
    $correction['referencia_solicitud'] = httpReference('correccion');
    $correction['motivo'] = 'Corrección de prueba después de venta';
    $correction['detalles'][0]['cantidad_presentaciones'] = 10;
    $correction['detalles'][0]['importe_final_linea'] = $correction['total_documento'] = 50;
    $corrected = httpTest('/api/compras/' . $purchaseId, 'PUT', $correction, $admin);
    checkHttp('Corrección posterior a venta deja saldo 8', $corrected['status'] === 200 && $stock() === 8);
    $replay = httpTest('/api/compras/' . $purchaseId, 'PUT', $correction, $admin);
    checkHttp('Reintento corrección no altera saldo ni versiones', $replay['status'] === 200 && !empty($replay['json']['idempotent_replay']) && $stock() === 8);
    $old = (int)$pdoTest->query("SELECT COUNT(*) FROM detalle_compra WHERE id_compra={$purchaseId} AND es_vigente=0")->fetchColumn();
    checkHttp('Detalles anteriores siguen conservados', $old === 1);

    $dates = '?fecha_inicio=' . date('Y-m-01') . '&fecha_fin=' . date('Y-m-t') . '&id_producto=' . $idProduct;
    $kardex = httpTest('/api/reportes/kardex' . $dates, 'GET', null, $admin);
    if (!empty($kardex['json']['data']['referencia_consulta'])) $snapshots[] = $kardex['json']['data']['referencia_consulta'];
    checkHttp('Kardex HTTP cuadra con las operaciones reales', $kardex['status'] === 200 && $kardex['json']['data']['resumen_producto']['saldo_final'] === 8);
    $emptyDate = httpTest('/api/reportes/variaciones?fecha_inicio=&fecha_fin=', 'GET', null, $admin);
    checkHttp('Variaciones devuelve 422 JSON con fechas vacías', $emptyDate['status'] === 422 && $emptyDate['json']['code'] === 'VALIDATION_ERROR');
    foreach (['TODOS','INCREMENTO','DISMINUCION'] as $direction) {
        $response = httpTest('/api/reportes/variaciones' . $dates . '&sentido=' . $direction, 'GET', null, $admin);
        if (!empty($response['json']['data']['referencia_consulta'])) $snapshots[] = $response['json']['data']['referencia_consulta'];
        checkHttp('Selector de Variaciones ' . $direction . ' conectado al backend', $response['status'] === 200 && $response['json']['data']['filtros_aplicados']['sentido'] === $direction);
    }
    $cancel = ['motivo' => 'Anulación de prueba', 'referencia_solicitud' => httpReference('anular')];
    checkHttp('Anulación no permite saldo negativo', httpTest('/api/compras/' . $purchaseId, 'DELETE', $cancel, $admin)['status'] === 409 && $stock() === 8);
    $second = $purchase;
    $second['numero'] = bin2hex(random_bytes(4));
    $second['referencia_solicitud'] = httpReference('reserva');
    httpTest('/api/compras', 'POST', $second, $admin);
    $cancelled = httpTest('/api/compras/' . $purchaseId, 'DELETE', $cancel, $admin);
    checkHttp('Anulación con existencias suficientes se guarda', $cancelled['status'] === 200 && $stock() === 10);
    $replay = httpTest('/api/compras/' . $purchaseId, 'DELETE', $cancel, $admin);
    checkHttp('Reintento anulación no vuelve a retirar unidades', $replay['status'] === 200 && !empty($replay['json']['idempotent_replay']) && $stock() === 10);
} finally {
    // Los identificadores provienen de un único producto creado por esta suite.
    if ($idProduct) {
        $pdoTest->beginTransaction();
        try {
            $deleteSnapshot = $pdoTest->prepare('DELETE FROM reporte_consulta_snapshot WHERE id_usuario=1 AND referencia_consulta=?');
            foreach ($snapshots as $ref) $deleteSnapshot->execute([$ref]);
            $pdoTest->exec("DELETE FROM kardex WHERE id_producto={$idProduct}");
            $pdoTest->exec("DELETE h FROM compra_historial h JOIN compra c ON c.id_compra=h.id_compra JOIN detalle_compra d ON d.id_compra=c.id_compra WHERE d.id_producto={$idProduct}");
            $purchaseIds = $pdoTest->query("SELECT DISTINCT id_compra FROM detalle_compra WHERE id_producto={$idProduct}")->fetchAll(PDO::FETCH_COLUMN);
            $saleIds = $pdoTest->query("SELECT DISTINCT id_venta FROM detalle_venta WHERE id_producto={$idProduct}")->fetchAll(PDO::FETCH_COLUMN);
            $pdoTest->exec("UPDATE detalle_compra SET id_detalle_origen=NULL WHERE id_producto={$idProduct}");
            $pdoTest->exec("DELETE FROM detalle_compra WHERE id_producto={$idProduct}");
            $pdoTest->exec("DELETE FROM detalle_venta WHERE id_producto={$idProduct}");
            foreach ($purchaseIds as $id) $pdoTest->exec('DELETE FROM compra WHERE id_compra=' . (int)$id);
            foreach ($saleIds as $id) $pdoTest->exec('DELETE FROM venta WHERE id_venta=' . (int)$id);
            $pdoTest->exec("DELETE FROM producto WHERE id_producto={$idProduct}");
            $deleteReference = $pdoTest->prepare('DELETE FROM control_solicitud_idempotencia WHERE id_usuario=1 AND referencia_solicitud=?');
            foreach ($references as $ref) $deleteReference->execute([$ref]);
            // Solo restaurar la serie utilizada si nadie avanzó su numeración
            // después de nuestra venta. Nunca retroceder avances concurrentes.
            if (($sold['status'] ?? 0) === 201) {
                $restore = $pdoTest->prepare('UPDATE serie_comprobante SET correlativo_actual=? WHERE id_serie=? AND correlativo_actual=?');
                foreach ($correlativo as $row) {
                    if ((int)$row['id_tipo_comprobante'] === 1 && $row['serie'] === ($sold['json']['data']['serie'] ?? null)) {
                        $restore->execute([(int)$row['correlativo_actual'], (int)$row['id_serie'], (int)$row['correlativo_actual'] + 1]);
                    }
                }
            }
            $pdoTest->commit();
        } catch (Throwable $e) { $pdoTest->rollBack(); throw $e; }
    }
    foreach ([$admin, $seller] as $session) {
        $file = $sessionDir . '/sess_' . substr($session['cookie'], strpos($session['cookie'], '=') + 1);
        if (is_file($file)) unlink($file);
    }
}
echo "Regresión HTTP: {$passed} PASS / {$failed} FAIL" . PHP_EOL;
exit($failed ? 1 : 0);
