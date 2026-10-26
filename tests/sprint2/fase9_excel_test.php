<?php
/** Integración Fase 9: exportación XLSX de Kardex y Variaciones desde snapshots. */
require_once __DIR__ . '/bootstrap_test.php';
$server = 'http://127.0.0.1:8092';
$sessionDir = sys_get_temp_dir() . '/amazon-market-sprint2-sessions';
if (!is_dir($sessionDir)) throw new RuntimeException('Inicie el servidor local con tests/sprint2/servidor_pruebas.ps1.');
$admin = f9Session($sessionDir, 1, 1);
$otherAdmin = f9Session($sessionDir, 9, 1);
$seller = f9Session($sessionDir, 4, 2);
$failed = $passed = 0;
$snapshotRefs = $operationRefs = [];
$productId = null;
$productIds = [];
$invoiceCounterBefore = null;

function f9Assert(string $name, bool $ok, string $detail = ''): void {
    global $failed, $passed;
    $id=sprintf('F9-%02d',$failed+$passed+1);
    $ok ? $passed++ : $failed++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . '['.$id.'] ' . $name . ($detail !== '' ? ' (' . $detail . ')' : '') . PHP_EOL;
}

function f9Session(string $directory, int $userId, int $role): array {
    session_write_close();
    session_save_path($directory);
    session_id('');
    session_start();
    $_SESSION = ['login'=>true,'idUser'=>$userId,'rol'=>$role,'csrf_token'=>bin2hex(random_bytes(32))];
    $session = ['cookie'=>session_name() . '=' . session_id(),'csrf'=>$_SESSION['csrf_token']];
    session_write_close();
    return $session;
}

function f9Request(string $path, array $session, string $accept = 'application/json'): array {
    global $server;
    $ctx = stream_context_create(['http'=>['method'=>'GET','header'=>"Accept: {$accept}\r\nCookie: {$session['cookie']}\r\n",'ignore_errors'=>true,'timeout'=>30]]);
    $body = file_get_contents($server . $path, false, $ctx);
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $status);
    $headers=[];
    foreach ($http_response_header ?? [] as $line) if (str_contains($line, ':')) { [$key,$value]=explode(':',$line,2); $headers[strtolower($key)]=trim($value); }
    return ['status'=>(int)($status[1]??0),'headers'=>$headers,'body'=>$body===false?'':$body,'json'=>json_decode($body?:'',true)];
}

function f9Consult(string $type, array $query, array $admin): array {
    global $snapshotRefs;
    $response=f9Request('/api/reportes/'.$type.'?'.http_build_query($query),$admin);
    if (!empty($response['json']['data']['referencia_consulta'])) $snapshotRefs[]=$response['json']['data']['referencia_consulta'];
    return $response;
}

function f9Export(string $type, array $query, array $session): array {
    $query['tipo']=$type;
    return f9Request('/api/reportes/exportar-excel?'.http_build_query($query),$session,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/json');
}

function f9Pdf(string $type, array $query, array $session): array {
    $query['tipo']=$type;
    return f9Request('/api/reportes/exportar-pdf?'.http_build_query($query),$session,'application/pdf, application/json');
}

function f9PdfText(array $response): string {
    $path=tempnam(sys_get_temp_dir(),'f9pdf-');
    file_put_contents($path,$response['body']);
    $command=escapeshellarg('C:/Program Files/Git/ucrt64/bin/pdftotext.exe').' -enc UTF-8 -layout '.escapeshellarg($path).' - 2>&1';
    $text=shell_exec($command)?:'';
    unlink($path);
    return $text;
}

function f9ReadWorkbook(array $response): array {
    $path=tempnam(sys_get_temp_dir(),'f9xlsx-');
    file_put_contents($path,$response['body']);
    $zip=new ZipArchive();
    $valid=$zip->open($path)===true;
    $names=[]; $text=''; $styles=''; $rowCounts=[];
    if ($valid) {
        $workbook=$zip->getFromName('xl/workbook.xml')?:'';
        preg_match_all('/<sheet\b[^>]*\bname="([^"]+)"/',$workbook,$matches);
        $names=array_map(static fn($name)=>html_entity_decode($name,ENT_QUOTES|ENT_XML1,'UTF-8'),$matches[1]??[]);
        for ($i=1;$i<=count($names);$i++) {
            $sheetXml=$zip->getFromName('xl/worksheets/sheet'.$i.'.xml')?:'';
            $text.=' '.$sheetXml;
            $rowCounts[$i]=substr_count($sheetXml,'<row');
        }
        $text.=' '.($zip->getFromName('xl/sharedStrings.xml')?:'');
        $styles=$zip->getFromName('xl/styles.xml')?:'';
        $zip->close();
    }
    unlink($path);
    $text=html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_XML1,'UTF-8');
    return ['valid'=>$valid,'sheet_names'=>$names,'text'=>$text,'styles'=>$styles,'row_counts'=>$rowCounts];
}

function f9MoveRef(string $kind): string {
    global $operationRefs;
    $ref='f9-'.$kind.'-'.bin2hex(random_bytes(8));
    $operationRefs[]=$ref;
    return $ref;
}

function f9CreateProduct(int $unitId=1, string $suffix=''): int {
    $result=(new Services\ProductoService())->crear([
        'codigo_barra'=>'TST-F9-'.bin2hex(random_bytes(4)), 'nombre'=>'Producto prueba fase nueve '.$suffix,
        'id_categoria'=>1, 'id_subcategoria'=>1, 'id_unidad'=>$unitId, 'precio_venta'=>8.40, 'stock_minimo'=>2,
    ]);
    return (int)$result['id_producto'];
}

try {
    $productId=f9CreateProduct(1,'principal');
    $productIds[]=$productId;
    $today=(string)$pdoTest->query('SELECT CURRENT_DATE()')->fetchColumn();
    $yesterday=(string)$pdoTest->query('SELECT DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY)')->fetchColumn();
    $invoiceCounterBefore=(int)$pdoTest->query('SELECT correlativo_actual FROM serie_comprobante WHERE id_tipo_comprobante=1 LIMIT 1')->fetchColumn();
    $purchaseRef=f9MoveRef('compra');
    (new Services\CompraService())->registrarCompra([
        'id_proveedor'=>1,'id_tipo_comprobante'=>1,'serie'=>'F9TEST','numero'=>bin2hex(random_bytes(4)),
        'fecha_emision'=>$yesterday,'fecha_recepcion'=>$yesterday,'total_documento'=>210.00,
    ],[[
        'id_producto'=>$productId,'id_presentacion'=>1,'cantidad_presentaciones'=>25,'equivalencia_aplicada'=>1,
        'importe_final_linea'=>210.00,'numero_lote'=>'LOTE-FASE9','fecha_vencimiento'=>null,
    ]],1,$purchaseRef);
    $inventario=new Services\InventarioService();
    $positiveRef=f9MoveRef('ajuste-pos');
    $inventario->registrarAjuste(['id_producto'=>$productId,'tipo_ajuste'=>'Ajuste positivo','cantidad'=>3,'saldo_anterior_esperado'=>25,'justificacion'=>'Reposición validada para Excel','referencia_solicitud'=>$positiveRef],$positiveRef,1);
    $writeoffRef=f9MoveRef('baja-dano');
    $inventario->registrarBaja(['id_producto'=>$productId,'motivo_baja'=>'Baja por daño','cantidad'=>1,'justificacion'=>'Empaque dañado para prueba de exportación','lote'=>'LOTE-FASE9'],$writeoffRef,1);

    $secondProductId=f9CreateProduct(2,'unidad distinta');
    $productIds[]=$secondProductId;
    $secondUnitRef=f9MoveRef('unidad-distinta');
    $inventario->registrarAjuste(['id_producto'=>$secondProductId,'tipo_ajuste'=>'Ajuste positivo','cantidad'=>2,'saldo_anterior_esperado'=>0,'justificacion'=>'Unidad distinta para comprobar resumen por producto','referencia_solicitud'=>$secondUnitRef],$secondUnitRef,1);
    $secondProductStock=2;
    for ($i=0;$i<11;$i++) {
        $bulkRef=f9MoveRef('bulk');
        $inventario->registrarAjuste(['id_producto'=>$secondProductId,'tipo_ajuste'=>'Ajuste positivo','cantidad'=>1,'saldo_anterior_esperado'=>$secondProductStock,'justificacion'=>'Fila adicional para exportación sin paginar','referencia_solicitud'=>$bulkRef],$bulkRef,1);
        $secondProductStock++;
    }

    $filters=['fecha_inicio'=>$today,'fecha_fin'=>$today,'id_producto'=>$productId];
    $kardexQuery=f9Consult('kardex',$filters,$admin);
    $kardexRef=$kardexQuery['json']['data']['referencia_consulta']??'';
    $variationFilters=$filters+['sentido'=>'TODOS'];
    $variationQuery=f9Consult('variaciones',$variationFilters,$admin);
    $variationRef=$variationQuery['json']['data']['referencia_consulta']??'';
    f9Assert('Consultas de Kardex y Variaciones emiten referencias antes de exportar',$kardexQuery['status']===200&&$kardexRef!==''&&$variationQuery['status']===200&&$variationRef!=='');

    $largeVariationFilters=['fecha_inicio'=>$today,'fecha_fin'=>$today,'id_producto'=>$secondProductId,'sentido'=>'INCREMENTO','limit'=>1];
    $largeVariationQuery=f9Consult('variaciones',$largeVariationFilters,$admin);
    $largeVariationData=$largeVariationQuery['json']['data']??[];
    $largeVariationRef=$largeVariationData['referencia_consulta']??'';
    $largeVariationExport=f9Export('variaciones',$largeVariationFilters+['referencia_consulta'=>$largeVariationRef],$admin);
    $largeVariationBook=f9ReadWorkbook($largeVariationExport);
    f9Assert('Excel exporta todas las filas del snapshot aunque la consulta de pantalla esté paginada',$largeVariationExport['status']===200&&($largeVariationData['pagination']['total']??0)===12&&count($largeVariationData['items']??[])===1&&($largeVariationBook['row_counts'][2]??0)===13);

    $generalFilters=['fecha_inicio'=>$today,'fecha_fin'=>$today];
    $generalQuery=f9Consult('kardex',$generalFilters,$admin);
    $generalRef=$generalQuery['json']['data']['referencia_consulta']??'';
    $generalExport=f9Export('kardex',$generalFilters+['referencia_consulta'=>$generalRef],$admin);
    $generalBook=f9ReadWorkbook($generalExport);
    f9Assert('Kardex general separa productos y unidades sin totalizar existencias distintas',$generalExport['status']===200&&$generalBook['valid']&&str_contains($generalBook['text'],'Producto prueba fase nueve principal')&&str_contains($generalBook['text'],'Producto prueba fase nueve unidad distinta')&&str_contains($generalBook['text'],'no se suman existencias de unidades diferentes'));

    $posteriorRef=f9MoveRef('posterior-snapshot');
    $inventario->registrarAjuste(['id_producto'=>$productId,'tipo_ajuste'=>'Ajuste positivo','cantidad'=>2,'saldo_anterior_esperado'=>27,'justificacion'=>'MARCADOR_POSTERIOR_NO_EXPORTAR_F9','referencia_solicitud'=>$posteriorRef],$posteriorRef,1);
    $kardex=f9Export('kardex',$filters+['referencia_consulta'=>$kardexRef],$admin);
    $kardexBook=f9ReadWorkbook($kardex);
    f9Assert('Kardex descarga XLSX válido con resumen ejecutivo primero y detalle después',$kardex['status']===200&&str_starts_with($kardex['body'],"PK")&&str_contains($kardex['headers']['content-type']??'','spreadsheetml')&&$kardexBook['valid']&&array_slice($kardexBook['sheet_names'],0,2)===['Resumen ejecutivo','Detalle']);
    f9Assert('Kardex Excel refleja los saldos de la consulta y excluye movimientos posteriores',str_contains($kardexBook['text'],'Saldo inicial')&&str_contains($kardexBook['text'],'Entradas')&&str_contains($kardexBook['text'],'Salidas')&&str_contains($kardexBook['text'],'Saldo final')&&str_contains($kardexBook['text'],'27')&&!str_contains($kardexBook['text'],'MARCADOR_POSTERIOR_NO_EXPORTAR_F9'));
    f9Assert('Kardex Excel conserva período, producto, lote y filtros del snapshot',str_contains($kardexBook['text'],'Producto prueba fase nueve')&&str_contains($kardexBook['text'],'LOTE-FASE9')&&str_contains($kardexBook['text'],$kardexRef)&&str_contains($kardexBook['text'],'Ajuste positivo'));
    $kardexPdf=f9Pdf('kardex',$filters+['referencia_consulta'=>$kardexRef],$admin);
    $kardexPdfText=f9PdfText($kardexPdf);
    f9Assert('Resumen y filas de Kardex Excel coinciden con la consulta y el PDF de la misma referencia',$kardexPdf['status']===200&&($kardexQuery['json']['data']['resumen_producto']['saldo_final']??null)===27&&str_contains($kardexBook['text'],'27')&&str_contains($kardexPdfText,'27')&&str_contains($kardexBook['text'],'Ajuste positivo')&&str_contains($kardexPdfText,'Ajuste positivo')&&str_contains($kardexBook['text'],'Baja por daño')&&str_contains($kardexPdfText,'Baja por daño'));
    $variations=f9Export('variaciones',$variationFilters+['referencia_consulta'=>$variationRef],$admin);
    $variationBook=f9ReadWorkbook($variations);
    f9Assert('Variaciones descarga XLSX con resumen, tipos y detalle de la misma referencia',$variations['status']===200&&$variationBook['valid']&&array_slice($variationBook['sheet_names'],0,2)===['Resumen ejecutivo','Detalle']&&str_contains($variationBook['text'],'Baja por daño')&&str_contains($variationBook['text'],'Ajuste positivo')&&str_contains($variationBook['text'],'Empaque dañado para prueba de exportación')&&str_contains($variationBook['text'],$variationRef));
    f9Assert('Variaciones Excel mantiene sentido Todas y datos de auditoría',str_contains($variationBook['text'],'Todas')&&str_contains($variationBook['text'],'Admin')&&str_contains($variationBook['text'],'LOTE-FASE9'));
    $variationPdf=f9Pdf('variaciones',$variationFilters+['referencia_consulta'=>$variationRef],$admin);
    $variationPdfText=f9PdfText($variationPdf);
    f9Assert('Resumen y tipos de Variaciones Excel coinciden con la consulta y el PDF',$variationPdf['status']===200&&($variationQuery['json']['data']['resumen']['total_operaciones']??null)===2&&str_contains($variationBook['text'],'2')&&str_contains($variationPdfText,'2')&&str_contains($variationBook['text'],'Ajuste positivo')&&str_contains($variationPdfText,'Ajuste positivo')&&str_contains($variationBook['text'],'Baja por daño')&&str_contains($variationPdfText,'Baja por daño'));
    f9Assert('Los libros conservan colores institucionales azul marino y dorado',str_contains($kardexBook['styles'],'001F3F')&&str_contains($kardexBook['styles'],'D4AF37')&&str_contains($variationBook['styles'],'001F3F')&&str_contains($variationBook['styles'],'D4AF37'));

    $filteredVariationFilters=$filters+['id_categoria'=>1,'id_subcategoria'=>1,'tipo_variacion'=>'Baja por daño','sentido'=>'DISMINUCION'];
    $filteredVariationQuery=f9Consult('variaciones',$filteredVariationFilters,$admin);
    $filteredVariationRef=$filteredVariationQuery['json']['data']['referencia_consulta']??'';
    $filteredVariationExport=f9Export('variaciones',$filteredVariationFilters+['referencia_consulta'=>$filteredVariationRef],$admin);
    $filteredVariationBook=f9ReadWorkbook($filteredVariationExport);
    f9Assert('Excel respeta tipo, sentido y clasificación histórica combinados',$filteredVariationExport['status']===200&&$filteredVariationBook['valid']&&str_contains($filteredVariationBook['text'],'Salidas')&&str_contains($filteredVariationBook['text'],'Baja por daño')&&!str_contains($filteredVariationBook['text'],'Ajuste positivo'));

    $sellerExport=f9Export('kardex',$filters+['referencia_consulta'=>$kardexRef],$seller);
    f9Assert('El rol Vendedor no puede descargar Excel de Kardex',$sellerExport['status']===403);
    $foreignExport=f9Export('variaciones',$variationFilters+['referencia_consulta'=>$variationRef],$otherAdmin);
    f9Assert('Otro Administrador no puede descargar Excel con referencia ajena',$foreignExport['status']===403&&($foreignExport['json']['code']??'')==='QUERY_REFERENCE_FORBIDDEN');
    $noRef=f9Export('kardex',$filters,$admin);
    f9Assert('Excel sin referencia se rechaza con 422',$noRef['status']===422&&($noRef['json']['code']??'')==='VALIDATION_ERROR');
    $mismatch=f9Export('variaciones',array_replace($variationFilters,['referencia_consulta'=>$variationRef,'sentido'=>'INCREMENTO']),$admin);
    f9Assert('Cambiar sentido con la referencia existente se rechaza con 409',$mismatch['status']===409&&($mismatch['json']['code']??'')==='QUERY_FILTERS_MISMATCH');
    $expired=$pdoTest->prepare('UPDATE reporte_consulta_snapshot SET expira_en=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE referencia_consulta=?');
    $expired->execute([$kardexRef]);
    $expiredExport=f9Export('kardex',$filters+['referencia_consulta'=>$kardexRef],$admin);
    f9Assert('Referencia Kardex vencida se rechaza con 410',$expiredExport['status']===410&&($expiredExport['json']['code']??'')==='QUERY_REFERENCE_EXPIRED');

    $tomorrow=(string)$pdoTest->query('SELECT DATE_ADD(CURRENT_DATE(), INTERVAL 1 DAY)')->fetchColumn();
    $zeroFilters=['fecha_inicio'=>$tomorrow,'fecha_fin'=>'2099-12-31','id_producto'=>$productId];
    $zeroQuery=f9Consult('kardex',$zeroFilters,$admin);
    $zeroExport=f9Export('kardex',$zeroFilters+['referencia_consulta'=>$zeroQuery['json']['data']['referencia_consulta']??''],$admin);
    $zeroBook=f9ReadWorkbook($zeroExport);
    f9Assert('Kardex individual sin movimientos sigue exportable con saldo de cierre igual al inicial',$zeroExport['status']===200&&$zeroBook['valid']&&str_contains($zeroBook['text'],'25')&&str_contains($zeroBook['text'],'No hubo movimientos'));

    $emptyFilters=['fecha_inicio'=>$tomorrow,'fecha_fin'=>'2099-12-31'];
    $emptyKardexQuery=f9Consult('kardex',$emptyFilters,$admin);
    $emptyKardex=f9Export('kardex',$emptyFilters+['referencia_consulta'=>$emptyKardexQuery['json']['data']['referencia_consulta']??''],$admin);
    f9Assert('Kardex general vacío devuelve 422 EMPTY_EXPORT_NOT_ALLOWED',$emptyKardex['status']===422&&($emptyKardex['json']['code']??'')==='EMPTY_EXPORT_NOT_ALLOWED');
    $emptyVariationFilters=$filters+['tipo_variacion'=>'Baja por vencimiento','sentido'=>'INCREMENTO'];
    $emptyVariationQuery=f9Consult('variaciones',$emptyVariationFilters,$admin);
    $emptyVariations=f9Export('variaciones',$emptyVariationFilters+['referencia_consulta'=>$emptyVariationQuery['json']['data']['referencia_consulta']??''],$admin);
    f9Assert('Variaciones vacías respetan los filtros y devuelven 422',$emptyVariations['status']===422&&($emptyVariations['json']['code']??'')==='EMPTY_EXPORT_NOT_ALLOWED');
} finally {
    if ($productIds) {
        $pdoTest->beginTransaction();
        try {
            foreach ($snapshotRefs as $ref) {
                $delete=$pdoTest->prepare('DELETE FROM reporte_consulta_snapshot_item WHERE id_snapshot=(SELECT id_snapshot FROM reporte_consulta_snapshot WHERE referencia_consulta=?)');
                $delete->execute([$ref]);
                $delete=$pdoTest->prepare('DELETE FROM reporte_consulta_snapshot WHERE referencia_consulta=? AND id_usuario=1');
                $delete->execute([$ref]);
            }
            $marks=implode(',',array_fill(0,count($productIds),'?'));
            $stmt=$pdoTest->prepare('SELECT DISTINCT id_compra FROM detalle_compra WHERE id_producto IN ('.$marks.')'); $stmt->execute($productIds); $purchases=$stmt->fetchAll(PDO::FETCH_COLUMN);
            $pdoTest->prepare('UPDATE detalle_compra SET id_detalle_origen=NULL WHERE id_producto IN ('.$marks.')')->execute($productIds);
            $pdoTest->prepare('DELETE FROM kardex WHERE id_producto IN ('.$marks.')')->execute($productIds);
            $pdoTest->prepare('DELETE FROM operacion_inventario WHERE id_producto IN ('.$marks.')')->execute($productIds);
            $pdoTest->prepare('DELETE FROM detalle_compra WHERE id_producto IN ('.$marks.')')->execute($productIds);
            foreach ($purchases as $id) $pdoTest->exec('DELETE FROM compra WHERE id_compra='.(int)$id);
            $deleteControl=$pdoTest->prepare('DELETE FROM control_solicitud_idempotencia WHERE id_usuario=1 AND referencia_solicitud=?');
            foreach ($operationRefs as $ref) $deleteControl->execute([$ref]);
            $pdoTest->prepare('DELETE FROM producto WHERE id_producto IN ('.$marks.')')->execute($productIds);
            if ($invoiceCounterBefore!==null) $pdoTest->prepare('UPDATE serie_comprobante SET correlativo_actual=? WHERE id_tipo_comprobante=1')->execute([$invoiceCounterBefore]);
            $pdoTest->commit();
        } catch (Throwable $error) { if ($pdoTest->inTransaction()) $pdoTest->rollBack(); throw $error; }
    }
    foreach ([$admin,$otherAdmin,$seller] as $session) {
        $sessionId=substr($session['cookie'],strpos($session['cookie'],'=')+1);
        if (is_file($sessionDir.'/sess_'.$sessionId)) unlink($sessionDir.'/sess_'.$sessionId);
    }
}
echo "Fase 9 Excel: {$passed} PASS / {$failed} FAIL".PHP_EOL;
exit($failed?1:0);
