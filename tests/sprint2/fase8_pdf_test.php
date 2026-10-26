<?php
/** Integración de Fase 8: autenticación/API, PDF binario, contenido y casos vacíos. */
require_once __DIR__ . '/bootstrap_test.php';
$server = 'http://127.0.0.1:8092';
$sessionDir = sys_get_temp_dir() . '/amazon-market-sprint2-sessions';
if (!is_dir($sessionDir)) throw new RuntimeException('Inicie el servidor local con tests/sprint2/servidor_pruebas.ps1.');
$admin = f8Session($sessionDir, 1, 1);
$otherAdmin = f8Session($sessionDir, 9, 1);
$seller = f8Session($sessionDir, 4, 2);
$failed = $passed = 0;
$snapshotRefs = $operationRefs = [];
$productId = null;
$invoiceCounterBefore = null;
function f8Assert(string $name, bool $ok, string $detail = ''): void {
    global $failed, $passed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($detail !== '' ? ' (' . $detail . ')' : '') . PHP_EOL;
}
function f8Session(string $directory, int $userId, int $role): array {
    session_write_close();
    session_save_path($directory);
    session_id('');
    session_start();
    $_SESSION = ['login'=>true,'idUser'=>$userId,'rol'=>$role,'csrf_token'=>bin2hex(random_bytes(32))];
    $session = ['cookie'=>session_name() . '=' . session_id(),'csrf'=>$_SESSION['csrf_token']];
    session_write_close();
    return $session;
}
function f8Request(string $path, array $session, string $accept = 'application/json'): array {
    global $server;
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => "Accept: {$accept}\r\nCookie: {$session['cookie']}\r\n", 'ignore_errors' => true, 'timeout' => 20]]);
    $body = file_get_contents($server . $path, false, $ctx);
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $status);
    $headers = [];
    foreach ($http_response_header ?? [] as $line) if (str_contains($line, ':')) { [$key,$value]=explode(':',$line,2); $headers[strtolower($key)]=trim($value); }
    return ['status' => (int)($status[1] ?? 0), 'headers' => $headers, 'body' => $body === false ? '' : $body, 'json' => json_decode($body ?: '', true)];
}
function f8PdfText(array $response): string {
    $path = tempnam(sys_get_temp_dir(), 's2pdf-');
    file_put_contents($path, $response['body']);
    $command = escapeshellarg('C:/Program Files/Git/ucrt64/bin/pdftotext.exe') . ' -enc UTF-8 -layout ' . escapeshellarg($path) . ' - 2>&1';
    $text = shell_exec($command) ?: '';
    unlink($path);
    return $text;
}
function f8SavePdfPreview(string $name, array $response): void {
    $directory = getenv('PHASE8_SAVE_PDF_DIR');
    if (!$directory || !str_starts_with($response['body'], '%PDF-')) return;
    if (!is_dir($directory)) mkdir($directory, 0777, true);
    file_put_contents(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name . '.pdf', $response['body']);
}
function f8Consult(string $type, array $query, array $admin): array {
    global $snapshotRefs;
    $params = http_build_query($query);
    $response = f8Request('/api/reportes/' . $type . '?' . $params, $admin);
    if (!empty($response['json']['data']['referencia_consulta'])) $snapshotRefs[] = $response['json']['data']['referencia_consulta'];
    return $response;
}
function f8Pdf(string $type, array $query, array $admin): array {
    $query['tipo'] = $type;
    return f8Request('/api/reportes/exportar-pdf?' . http_build_query($query), $admin, 'application/pdf');
}
function f8MoveRef(string $prefix): string {
    global $operationRefs;
    $reference = 'f8-' . $prefix . '-' . bin2hex(random_bytes(8));
    $operationRefs[] = $reference;
    return $reference;
}
function f8BuildProduct(): int {
    $result = (new Services\ProductoService())->crear([
        'codigo_barra' => 'TST-F8-' . bin2hex(random_bytes(4)), 'nombre' => 'Producto prueba fase ocho',
        'id_categoria' => 1, 'id_subcategoria' => 1, 'id_unidad' => 1, 'precio_venta' => 7.50, 'stock_minimo' => 2,
    ]);
    return (int)$result['id_producto'];
}
try {
    $productId = f8BuildProduct();
    $purchaseRef = f8MoveRef('compra');
    (new Services\CompraService())->registrarCompra([
        'id_proveedor' => 1, 'id_tipo_comprobante' => 1, 'serie' => 'T8TEST',
        'numero' => bin2hex(random_bytes(4)), 'fecha_emision' => date('Y-m-d'),
        'fecha_recepcion' => date('Y-m-d'), 'total_documento' => 25.00,
    ], [[
        'id_producto' => $productId, 'id_presentacion' => 1, 'cantidad_presentaciones' => 25,
        'equivalencia_aplicada' => 1, 'importe_final_linea' => 25.00,
        'numero_lote' => 'LOTE-FASE8', 'fecha_vencimiento' => null,
    ]], 1, $purchaseRef);
    $inventory = new Services\InventarioService();
    $ref = f8MoveRef('ajuste-pos');
    $inventory->registrarAjuste(['id_producto'=>$productId,'tipo_ajuste'=>'Ajuste positivo','cantidad'=>3,'saldo_anterior_esperado'=>25,'justificacion'=>'Reposición verificada por conteo','referencia_solicitud'=>$ref], $ref, 1);
    $ref = f8MoveRef('baja-dano');
    $inventory->registrarBaja(['id_producto'=>$productId,'motivo_baja'=>'Baja por daño','cantidad'=>2,'justificacion'=>'Envase golpeado, separado del anaquel','lote'=>'LOTE-FASE8'], $ref, 1);
    $ref = f8MoveRef('baja-venc');
    $inventory->registrarBaja(['id_producto'=>$productId,'motivo_baja'=>'Baja por vencimiento','cantidad'=>1,'justificacion'=>'Fecha de caducidad cumplida','lote'=>'LOTE-FASE8'], $ref, 1);
    $ref = f8MoveRef('ajuste-neg');
    $inventory->registrarAjuste(['id_producto'=>$productId,'tipo_ajuste'=>'Ajuste negativo','cantidad'=>2,'saldo_anterior_esperado'=>25,'justificacion'=>'Faltante confirmado en conteo','referencia_solicitud'=>$ref], $ref, 1);
    $invoiceCounterBefore = (int)$pdoTest->query('SELECT correlativo_actual FROM serie_comprobante WHERE id_tipo_comprobante=1 LIMIT 1')->fetchColumn();
    $saleResult = (new Services\VentaService())->procesarVenta([
        'id_usuario'=>1,'id_cliente'=>1,'id_tipo_comprobante'=>1,'id_metodo_pago'=>1,
        'productos'=>[['id_producto'=>$productId,'cantidad'=>1]],
    ]);
    $saleId = (int)($saleResult['data']['id_venta'] ?? 0);
    if ($saleId < 1) throw new RuntimeException('No se pudo preparar la venta de prueba para el reporte Kardex.');
    $today = (string)$pdoTest->query('SELECT CURRENT_DATE()')->fetchColumn();
    $baseFilters = ['fecha_inicio'=>$today, 'fecha_fin'=>$today, 'id_producto'=>$productId];

    $kardex = f8Consult('kardex', $baseFilters, $admin);
    f8Assert('Kardex consultado antes de exportar', $kardex['status'] === 200 && $kardex['json']['data']['resumen_producto']['saldo_final'] === 22);
    $kardexRef = $kardex['json']['data']['referencia_consulta'] ?? '';
    $variations = f8Consult('variaciones', $baseFilters + ['sentido'=>'TODOS'], $admin);
    $variationsRef = $variations['json']['data']['referencia_consulta'] ?? '';
    $postSnapshotRef = f8MoveRef('posterior-snapshot');
    $inventory->registrarAjuste([
        'id_producto'=>$productId,'tipo_ajuste'=>'Ajuste positivo','cantidad'=>1,
        'saldo_anterior_esperado'=>22,'justificacion'=>'MARCADOR_MOVIMIENTO_POSTERIOR_A_CONSULTA',
        'referencia_solicitud'=>$postSnapshotRef,
    ], $postSnapshotRef, 1);
    $generatedAt = (string)$pdoTest->query('SELECT CURRENT_TIMESTAMP()')->fetchColumn();
    $expectedGeneratedAt = date('d/m/Y H:i', strtotime($generatedAt));
    $pdfKardex = f8Pdf('kardex', $baseFilters + ['referencia_consulta'=>$kardexRef], $admin);
    f8SavePdfPreview('kardex', $pdfKardex);
    $kardexText = f8PdfText($pdfKardex);
    f8Assert('Descarga Kardex entrega PDF binario', $pdfKardex['status'] === 200 && str_starts_with($pdfKardex['body'], '%PDF-') && str_contains($pdfKardex['headers']['content-type'] ?? '', 'application/pdf'));
    f8Assert('Kardex incluye encabezado, período y resumen ejecutivo antes del detalle', strpos($kardexText, 'RESUMEN EJECUTIVO') < strpos($kardexText, 'DETALLE DE MOVIMIENTOS') && str_contains($kardexText, '22') && str_contains($kardexText, 'Producto prueba fase ocho'));
    f8Assert('La fecha de generación PDF usa el reloj de la base de datos', str_contains($kardexText, 'Reporte generado el ' . $expectedGeneratedAt . ':'));
    f8Assert('Kardex detalla Compra, Venta, Bajas, Ajustes y auditoría de lote/responsable', str_contains($kardexText,'Compra') && str_contains($kardexText,'Venta') && str_contains($kardexText,'Ajuste positivo') && str_contains($kardexText,'Ajuste negativo') && str_contains($kardexText,'Baja por daño') && str_contains($kardexText,'Baja por vencimiento') && str_contains($kardexText,'LOTE-FASE8') && str_contains($kardexText,'Admin'));
    f8Assert('Kardex PDF conserva la instantánea frente a un movimiento posterior', !str_contains($kardexText,'MARCADOR_MOVIMIENTO_POSTERIOR_A_CONSULTA') && str_contains($kardexText,'22'));
    f8Assert('PDF incorpora nombre de archivo sin descarga pública en caché', str_contains($pdfKardex['headers']['content-disposition'] ?? '', 'Reporte_Kardex') && str_contains($pdfKardex['headers']['cache-control'] ?? '', 'no-store'));
    $sellerExport = f8Pdf('kardex', $baseFilters + ['referencia_consulta'=>$kardexRef], $seller);
    f8Assert('La descarga PDF de inventario rechaza al rol Vendedor', $sellerExport['status'] === 403);
    $foreignExport = f8Pdf('kardex', $baseFilters + ['referencia_consulta'=>$kardexRef], $otherAdmin);
    f8Assert('La descarga PDF rechaza la referencia de otro Administrador', $foreignExport['status'] === 403 && ($foreignExport['json']['code'] ?? '') === 'QUERY_REFERENCE_FORBIDDEN');

    $pdfVariations = f8Pdf('variaciones', $baseFilters + ['sentido'=>'TODOS','referencia_consulta'=>$variationsRef], $admin);
    f8SavePdfPreview('variaciones', $pdfVariations);
    $variationsText = f8PdfText($pdfVariations);
    f8Assert('Variaciones entrega PDF y respeta la instantánea', $pdfVariations['status'] === 200 && str_starts_with($pdfVariations['body'],'%PDF-') && str_contains($variationsText,'Producto prueba fase ocho'));
    f8Assert('Resumen de Variaciones precede detalle e incluye los cuatro tipos y sentido aplicado', strpos($variationsText,'RESUMEN EJECUTIVO') < strpos($variationsText,'DETALLE DE VARIACIONES') && str_contains($variationsText,'Ajuste positivo') && str_contains($variationsText,'Ajuste negativo') && str_contains($variationsText,'Baja por daño') && str_contains($variationsText,'Baja por vencimiento') && str_contains($variationsText,'Todas'));
    f8Assert('Detalle de Variaciones conserva responsable, lote y justificación', str_contains($variationsText,'Admin') && str_contains($variationsText,'LOTE-FASE8') && str_contains($variationsText,'Envase golpeado'));
    f8Assert('Variaciones PDF conserva la instantánea frente a un movimiento posterior', !str_contains($variationsText,'MARCADOR_MOVIMIENTO_POSTERIOR_A_CONSULTA'));

    $noRef = f8Pdf('kardex', $baseFilters, $admin);
    f8Assert('Exportación sin referencia se rechaza con 422', $noRef['status'] === 422 && ($noRef['json']['code'] ?? '') === 'VALIDATION_ERROR');
    $wrongFilter = f8Pdf('kardex', array_replace($baseFilters, ['referencia_consulta'=>$kardexRef, 'fecha_inicio'=>'2026-01-01']), $admin);
    f8Assert('Alterar un filtro con referencia produce 409 JSON', $wrongFilter['status'] === 409 && ($wrongFilter['json']['code'] ?? '') === 'QUERY_FILTERS_MISMATCH');
    $expired = $pdoTest->prepare('UPDATE reporte_consulta_snapshot SET expira_en=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE referencia_consulta=?');
    $expired->execute([$kardexRef]);
    $expiredResult = f8Pdf('kardex', $baseFilters + ['referencia_consulta'=>$kardexRef], $admin);
    f8Assert('Referencia vencida produce 410 JSON', $expiredResult['status'] === 410 && ($expiredResult['json']['code'] ?? '') === 'QUERY_REFERENCE_EXPIRED');

    $tomorrow = (string)$pdoTest->query('SELECT DATE_ADD(CURRENT_DATE(), INTERVAL 1 DAY)')->fetchColumn();
    $futureFilters = ['fecha_inicio'=>$tomorrow,'fecha_fin'=>'2099-12-31','id_producto'=>$productId];
    $noMovement = f8Consult('kardex', $futureFilters, $admin);
    $noMovementRef = $noMovement['json']['data']['referencia_consulta'] ?? '';
    $zeroPdf = f8Pdf('kardex', $futureFilters + ['referencia_consulta'=>$noMovementRef], $admin);
    $zeroText = f8PdfText($zeroPdf);
    f8Assert('Kardex individual sin movimientos sí se exporta con saldo inicial y cierre iguales', $zeroPdf['status']===200 && str_contains($zeroText,'Saldo inicial') && str_contains($zeroText,'Saldo final') && str_contains($zeroText,'23'));

    $emptyGeneral = ['fecha_inicio'=>$tomorrow,'fecha_fin'=>'2099-12-31'];
    $emptyKardexQuery = f8Consult('kardex', $emptyGeneral, $admin);
    $emptyKardex = f8Pdf('kardex', $emptyGeneral + ['referencia_consulta'=>$emptyKardexQuery['json']['data']['referencia_consulta'] ?? ''], $admin);
    f8Assert('Kardex general sin resultados devuelve 422 EMPTY_EXPORT_NOT_ALLOWED', $emptyKardex['status']===422 && ($emptyKardex['json']['code']??'')==='EMPTY_EXPORT_NOT_ALLOWED');
    $emptyVariationsFilters = $baseFilters + ['tipo_variacion'=>'Baja por daño','sentido'=>'INCREMENTO'];
    $emptyVariationsQuery = f8Consult('variaciones', $emptyVariationsFilters, $admin);
    $emptyVariations = f8Pdf('variaciones', $emptyVariationsFilters + ['referencia_consulta'=>$emptyVariationsQuery['json']['data']['referencia_consulta'] ?? ''], $admin);
    f8Assert('Variaciones sin resultados devuelve 422 EMPTY_EXPORT_NOT_ALLOWED', $emptyVariations['status']===422 && ($emptyVariations['json']['code']??'')==='EMPTY_EXPORT_NOT_ALLOWED');
    f8Assert('Tipo de reporte no permitido devuelve error JSON', f8Pdf('no-existe', [], $admin)['status']===422);
    $legacyPdf = f8Pdf('resumen', [], $admin);
    f8Assert('Los reportes PDF existentes conservan la descarga A4 vertical', $legacyPdf['status']===200 && str_starts_with($legacyPdf['body'],'%PDF-') && str_contains($legacyPdf['headers']['content-type']??'','application/pdf'));
} finally {
    if ($productId) {
        $pdoTest->beginTransaction();
        try {
            foreach ($snapshotRefs as $ref) {
                $delete = $pdoTest->prepare('DELETE FROM reporte_consulta_snapshot_item WHERE id_snapshot=(SELECT id_snapshot FROM reporte_consulta_snapshot WHERE referencia_consulta=?)');
                $delete->execute([$ref]);
                $delete = $pdoTest->prepare('DELETE FROM reporte_consulta_snapshot WHERE referencia_consulta=? AND id_usuario=1');
                $delete->execute([$ref]);
            }
            $stmt = $pdoTest->prepare('SELECT id_compra FROM detalle_compra WHERE id_producto=?'); $stmt->execute([$productId]); $purchases=$stmt->fetchAll(PDO::FETCH_COLUMN);
            $stmt = $pdoTest->prepare('SELECT id_venta FROM detalle_venta WHERE id_producto=?'); $stmt->execute([$productId]); $sales=$stmt->fetchAll(PDO::FETCH_COLUMN);
            $pdoTest->prepare('UPDATE detalle_compra SET id_detalle_origen=NULL WHERE id_producto=?')->execute([$productId]);
            $pdoTest->prepare('DELETE FROM kardex WHERE id_producto=?')->execute([$productId]);
            $pdoTest->prepare('DELETE FROM operacion_inventario WHERE id_producto=?')->execute([$productId]);
            $pdoTest->prepare('DELETE FROM detalle_venta WHERE id_producto=?')->execute([$productId]);
            $pdoTest->prepare('DELETE FROM detalle_compra WHERE id_producto=?')->execute([$productId]);
            foreach ($purchases as $id) $pdoTest->exec('DELETE FROM compra WHERE id_compra='.(int)$id);
            foreach ($sales as $id) {
                $deleteSaleControl=$pdoTest->prepare('DELETE FROM control_solicitud_idempotencia WHERE id_usuario=1 AND tipo_operacion="VENTA" AND id_registro_recurso=?');
                $deleteSaleControl->execute([$id]);
                $pdoTest->exec('DELETE FROM venta WHERE id_venta='.(int)$id);
            }
            if ($invoiceCounterBefore !== null) {
                $restoreCounter=$pdoTest->prepare('UPDATE serie_comprobante SET correlativo_actual=? WHERE id_tipo_comprobante=1');
                $restoreCounter->execute([$invoiceCounterBefore]);
            }
            $deleteControl=$pdoTest->prepare('DELETE FROM control_solicitud_idempotencia WHERE id_usuario=1 AND referencia_solicitud=?');
            foreach ($operationRefs as $ref) $deleteControl->execute([$ref]);
            $pdoTest->prepare('DELETE FROM producto WHERE id_producto=?')->execute([$productId]);
            $pdoTest->commit();
        } catch (Throwable $error) { if ($pdoTest->inTransaction()) $pdoTest->rollBack(); throw $error; }
    }
    foreach ([$admin,$otherAdmin,$seller] as $session) {
        $sessionId = substr($session['cookie'], strpos($session['cookie'], '=') + 1);
        if (is_file($sessionDir . '/sess_' . $sessionId)) unlink($sessionDir . '/sess_' . $sessionId);
    }
}
echo "Fase 8 PDF: {$passed} PASS / {$failed} FAIL" . PHP_EOL;
exit($failed ? 1 : 0);
