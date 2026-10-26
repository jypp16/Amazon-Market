<?php
/** Prueba real sobre copias efímeras del respaldo antiguo; nunca migra amazon_market. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
session_save_path(sys_get_temp_dir());
session_start();
$root = dirname(__DIR__, 2);
$mysql = 'C:/xampp/mysql/bin/mysql.exe';
$backup = getenv('SPRINT2_BACKUP_ORIGEN') ?: 'C:/xampp/backups/amazon_market/amazon_market_pre_sprint2_fase10_apply.sql';
$script = $root . '/database/segundo_sprint/11_migracion_sprint2_sin_perdida_de_datos.sql';
$server = new PDO('mysql:host=localhost;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$created = [];
$passed = 0;
function check11(string $message, bool $condition): void {
    global $passed;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    ++$passed; echo '[PASS] ' . $message . PHP_EOL;
}
function cli11(array $args): array {
    global $mysql;
    $p = proc_open(array_merge([$mysql, '-uroot', '--default-character-set=utf8mb4', '--abort-source-on-error'], $args), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($p)) { throw new RuntimeException('No inició mysql'); }
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($p), $out . $err];
}
function fixture11(): array {
    global $server,$created,$backup;
    $name='amazon_market_s2_keep_test_'.bin2hex(random_bytes(5));
    $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created[]=$name;
    [$code,$out]=cli11(['--database='.$name,'-e','SOURCE '.str_replace('\\','/',$backup)]);
    if ($code!==0) { throw new RuntimeException('Error restaurando copia: '.substr($out,-1000)); }
    return [$name,new PDO('mysql:host=localhost;dbname='.$name.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION])];
}
function migrate11(string $name, bool $metadata=true, ?string $confirmed=null): array {
    global $server,$backup,$script;
    $init='SET @SPRINT2_11_BASE_CONFIRMADA='.$server->quote($confirmed??$name).", @SPRINT2_11_MANTENIMIENTO='APLICACION_DETENIDA'";
    if ($metadata) { $init.=', @SPRINT2_11_RESPALDO='.$server->quote($backup).', @SPRINT2_11_SHA256='.$server->quote(hash_file('sha256',$backup)); }
    return cli11(['--database='.$name,'--init-command='.$init,'-e','SOURCE '.str_replace('\\','/',$script)]);
}
function original11(PDO $db, ?array $definition=null, string $kardex='kardex'): array {
    if ($definition===null) {
        $definition=[];
        foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            if (in_array($t,['permiso','rol_permiso'],true)) { continue; }
            $definition[$t]=array_column($db->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC),'Field');
        }
    }
    $rows=[];
    foreach ($definition as $t=>$columns) {
        $actual=$t==='kardex'?$kardex:$t;
        $fields=implode(',',array_map(fn($v)=>'`'.$v.'`',$columns));
        $pk=$db->query("SHOW KEYS FROM `$actual` WHERE Key_name='PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
        usort($pk,fn($a,$b)=>$a['Seq_in_index']<=>$b['Seq_in_index']);
        $order=implode(',',array_map(fn($v)=>'`'.$v['Column_name'].'`',$pk));
        $data=$db->query("SELECT $fields FROM `$actual`".($order?' ORDER BY '.$order:''))->fetchAll(PDO::FETCH_ASSOC);
        foreach ($data as &$row) { foreach ($row as $key=>&$value) { if ($value!==null) { $value=$t==='producto'&&in_array($key,['stock_actual','stock_minimo'],true)?number_format((float)$value,2,'.',''):(string)$value; } } unset($value); } unset($row);
        $rows[$t]=hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
    }
    return [$definition,$rows];
}
function business11(PDO $db): string {
    $tables=['producto','venta','detalle_venta','compra','detalle_compra','compra_historial','kardex','serie_comprobante'];
    $result=[];
    foreach ($tables as $t) { $data=$db->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC); $encoded=array_map(fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$data);sort($encoded);$result[$t]=$encoded; }
    return hash('sha256',json_encode($result,JSON_THROW_ON_ERROR));
}
try {
    if (!is_file($backup)) { throw new RuntimeException('Falta respaldo original'); }
    if (preg_match('/^\s*(?:USE\s|CREATE\s+DATABASE|DROP\s+DATABASE)/im',file_get_contents($backup))) { throw new RuntimeException('El respaldo altera la base seleccionada'); }
    $real=new PDO('mysql:host=localhost;dbname=amazon_market;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $realBefore=business11($real);
    [$name,$db]=fixture11();
    [$definition,$before]=original11($db);
    check11('El respaldo contiene ventas, compras y Kardex anteriores', (int)$db->query('SELECT COUNT(*) FROM venta')->fetchColumn()>0 && (int)$db->query('SELECT COUNT(*) FROM compra')->fetchColumn()>0 && (int)$db->query('SELECT COUNT(*) FROM kardex')->fetchColumn()>0);
    [$code,$out]=migrate11($name,false);
    check11('Sin respaldo declarado se detiene antes de crear estructura', $code!==0 && str_contains($out,'RESPALDO_REQUERIDO') && original11($db,$definition)[1]===$before && (int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sprint2_migracion11_control'")->fetchColumn()===0);
    [$code,$out]=migrate11($name,true,'otra_base');
    check11('Rechaza una base distinta a la confirmada', $code!==0 && str_contains($out,'BASE_NO_CONFIRMADA') && original11($db,$definition)[1]===$before);
    foreach (['stock_actual=1.25','stock_minimo=0.25','stock_actual=-1','precio_venta=0'] as $bad) {
        $original=$db->query('SELECT stock_actual,stock_minimo,precio_venta FROM producto WHERE id_producto=1')->fetch(PDO::FETCH_ASSOC);
        $db->exec('UPDATE producto SET '.$bad.' WHERE id_producto=1');
        $badBefore=original11($db,$definition)[1];
        [$code,$out]=migrate11($name);
        check11('Diagnostica incompatibilidad sin alterar datos: '.$bad, $code!==0 && str_contains($out,'DATOS_INCOMPATIBLES') && original11($db,$definition)[1]===$badBefore && (int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sprint2_migracion11_control'")->fetchColumn()===0);
        $q=$db->prepare('UPDATE producto SET stock_actual=?,stock_minimo=?,precio_venta=? WHERE id_producto=1');$q->execute(array_values($original));
    }
    $categoriaOriginal=$db->query('SELECT id_categoria,estado FROM categoria ORDER BY id_categoria LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $db->exec('UPDATE categoria SET estado=2 WHERE id_categoria='.(int)$categoriaOriginal['id_categoria']);
    $badBefore=original11($db,$definition)[1];
    [$code,$out]=migrate11($name);
    check11('Estado de categoría incompatible se rechaza antes del DDL sin modificar datos', $code!==0 && str_contains($out,'CATEGORIA_INCOMPATIBLE') && original11($db,$definition)[1]===$badBefore && (int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sprint2_migracion11_control'")->fetchColumn()===0);
    $q=$db->prepare('UPDATE categoria SET estado=? WHERE id_categoria=?');$q->execute([$categoriaOriginal['estado'],$categoriaOriginal['id_categoria']]);
    $serials=$db->query("SELECT table_name,auto_increment FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('producto','venta','compra','detalle_venta','detalle_compra','serie_comprobante') ORDER BY table_name")->fetchAll(PDO::FETCH_KEY_PAIR);
    [$code,$out]=migrate11($name);
    check11('La migración conserva datos en una base de nombre diferente', $code===0 && str_contains($out,'COMPLETADA'));
    check11('Todas las columnas originales permanecen iguales, incluido Kardex legado', original11($db,$definition,'kardex_legado_sprint2')[1]===$before);
    $afterSerials=$db->query("SELECT table_name,auto_increment FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('producto','venta','compra','detalle_venta','detalle_compra','serie_comprobante') ORDER BY table_name")->fetchAll(PDO::FETCH_KEY_PAIR);
    check11('Conserva AUTO_INCREMENT y correlativos comerciales', $serials===$afterSerials);
    check11('Permisos Sprint 2 exclusivamente para Administrador', (int)$db->query("SELECT COUNT(*) FROM rol_permiso rp JOIN permiso p ON p.id_permiso=rp.id_permiso WHERE rp.id_rol=1 AND p.slug IN ('inventario.ajustes','reportes.kardex','reportes.variaciones','reportes.exportar')")->fetchColumn()===4 && (int)$db->query("SELECT COUNT(*) FROM rol_permiso rp JOIN permiso p ON p.id_permiso=rp.id_permiso WHERE rp.id_rol<>1 AND p.slug IN ('inventario.ajustes','reportes.kardex','reportes.variaciones','reportes.exportar','reportes.ver')")->fetchColumn()===0);
    check11('Conserva exactamente las ventas fraccionarias antiguas', (int)$db->query('SELECT COUNT(*) FROM detalle_venta WHERE cantidad<>FLOOR(cantidad)')->fetchColumn()>0);
    check11('Cada existencia positiva tiene un saldo de migración identificado y exacto', (int)$db->query("SELECT COUNT(*) FROM producto p LEFT JOIN kardex k ON k.id_producto=p.id_producto AND k.origen_operacion='SALDO_INICIAL_MIGRACION' WHERE p.stock_actual>0 AND (k.id_kardex IS NULL OR k.stock_anterior<>0 OR k.cantidad<>p.stock_actual OR k.stock_resultante<>p.stock_actual)")->fetchColumn()===0 && (int)$db->query("SELECT COUNT(*) FROM kardex WHERE origen_operacion='SALDO_INICIAL_MIGRACION'")->fetchColumn()===(int)$db->query('SELECT COUNT(*) FROM producto WHERE stock_actual>0')->fetchColumn());
    check11('Productos de stock cero no generan entradas ficticias', (int)$db->query("SELECT COUNT(*) FROM kardex WHERE cantidad<=0")->fetchColumn()===0);
    $after=business11($db);
    [$code,$out]=migrate11($name);
    check11('Una segunda ejecución queda bloqueada y no duplica ni cambia datos', $code!==0 && str_contains($out,'MIGRACION11_YA_REGISTRADA') && business11($db)===$after);
    $immutable=false;try{$db->exec('UPDATE kardex_legado_sprint2 SET cantidad=cantidad+1 LIMIT 1');}catch(PDOException $e){$immutable=str_contains($e->getMessage(),'LEGADO_INMUTABLE');}
    check11('Kardex legado queda protegido de modificaciones', $immutable);
    $immutable=false;try{$db->exec('UPDATE kardex SET cantidad=cantidad+1 LIMIT 1');}catch(PDOException $e){$immutable=str_contains($e->getMessage(),'INMUTABILIDAD');}
    check11('Kardex nuevo mantiene su inmutabilidad', $immutable);
    $blocked=false;try{$db->exec("INSERT INTO kardex (id_producto,id_usuario,origen_operacion,sentido_movimiento,tipo_movimiento,cantidad,stock_anterior,stock_resultante,producto_codigo_snapshot,producto_nombre_snapshot,categoria_nombre_snapshot,subcategoria_nombre_snapshot,unidad_abreviatura_snapshot,responsable_nombre_snapshot) VALUES(1,1,'SALDO_INICIAL_MIGRACION','INCREMENTO','Saldo inicial de migración',1,0,1,'x','x','x','x','x','x')");}catch(PDOException $e){$blocked=str_contains($e->getMessage(),'SALDO_MIGRACION_CERRADO');}
    check11('No permite introducir saldos de migración después del cierre', $blocked);
    $_ENV['DB_NAME']=$name;putenv('DB_NAME='.$name);
    require_once $root.'/Config/Config.php';require_once $root.'/Libraries/Core/Autoload.php';
    check11('Servicios conectados exclusivamente a la copia temporal', \Libraries\Core\Conexion::getInstance()->conect()->query('SELECT DATABASE()')->fetchColumn()===$name);
    $ventaService=new \Services\VentaService();
    $fractionalSale=(int)$db->query('SELECT dv.id_venta FROM detalle_venta dv JOIN venta v ON v.id_venta=dv.id_venta WHERE dv.cantidad<>FLOOR(dv.cantidad) AND v.estado=1 LIMIT 1')->fetchColumn();
    $unchanged=business11($db);$blocked=false;
    try{$ventaService->anularVenta($fractionalSale,1,'Intento sobre venta fraccionaria histórica');}catch(\Libraries\Core\InventarioException $e){$blocked=$e->getErrorCode()==='LEGACY_FRACTIONAL_QUANTITY';}
    check11('Anular una venta fraccionaria antigua no trunca unidades ni altera registros', $blocked && business11($db)===$unchanged);
    $stock=(int)$db->query('SELECT stock_actual FROM producto WHERE id_producto=1')->fetchColumn();
    $venta=$ventaService->procesarVenta(['id_usuario'=>1,'id_cliente'=>1,'id_tipo_comprobante'=>1,'id_metodo_pago'=>1,'productos'=>[['id_producto'=>1,'cantidad'=>2]],'referencia_solicitud'=>'keep11_venta_'.bin2hex(random_bytes(8))]);
    $idVenta=(int)($venta['data']['id_venta']??0);
    check11('Nuevas ventas descuentan desde el stock conservado', $idVenta>0 && (int)$db->query('SELECT stock_actual FROM producto WHERE id_producto=1')->fetchColumn()===$stock-2);
    $ventaService->anularVenta($idVenta,1,'Anulación de venta nueva con cantidad entera');
    check11('Una venta nueva guardada como 2.00 se anula sin perder unidades', (int)$db->query('SELECT stock_actual FROM producto WHERE id_producto=1')->fetchColumn()===$stock);
    $compraService=new \Services\CompraService();
    $compra=$compraService->registrarCompra(['id_proveedor'=>1,'id_tipo_comprobante'=>2,'serie'=>'K11','numero'=>'0000001','fecha_emision'=>date('Y-m-d'),'total_documento'=>10],[['id_producto'=>1,'id_presentacion'=>1,'cantidad_presentaciones'=>2,'equivalencia_aplicada'=>1,'importe_final_linea'=>10,'numero_lote'=>null,'fecha_vencimiento'=>null]],1);
    check11('Nuevas compras suman desde el saldo conservado', !empty($compra['id_compra']) && (int)$db->query('SELECT stock_actual FROM producto WHERE id_producto=1')->fetchColumn()===$stock+2);
    $inventory=new \Services\InventarioService();
    $inventory->registrarBaja(['id_producto'=>1,'cantidad'=>1,'motivo_baja'=>'Baja por daño','justificacion'=>'Baja sobre saldo conservado','referencia_solicitud'=>'keep11_baja_'.bin2hex(random_bytes(8))],null,1);
    check11('Bajas generan nuevas variaciones sin tocar el legado', (int)$db->query('SELECT stock_actual FROM producto WHERE id_producto=1')->fetchColumn()===$stock+1 && original11($db,['kardex'=>$definition['kardex']],'kardex_legado_sprint2')[1]['kardex']===$before['kardex']);
    $legacyPurchase=$db->query("SELECT * FROM compra WHERE estado<>'Anulada' ORDER BY id_compra LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $legacyId=(int)$legacyPurchase['id_compra'];
    $oldDetails=$db->query("SELECT * FROM detalle_compra WHERE id_compra=$legacyId AND es_vigente=1 ORDER BY id_detalle_compra")->fetchAll(PDO::FETCH_ASSOC);
    $newDetails=[];
    foreach($oldDetails as $detail){$newDetails[]=['id_producto'=>(int)$detail['id_producto'],'id_presentacion'=>(int)$detail['id_presentacion'],'cantidad_presentaciones'=>(int)$detail['cantidad_presentaciones']+1,'equivalencia_aplicada'=>(int)$detail['equivalencia_aplicada'],'importe_final_linea'=>$detail['importe_final_linea'],'numero_lote'=>$detail['numero_lote'],'fecha_vencimiento'=>$detail['fecha_vencimiento']];}
    $stockBeforeCorrection=(int)$db->query('SELECT stock_actual FROM producto WHERE id_producto=1')->fetchColumn();
    $compraService->corregirCompra($legacyId,['id_proveedor'=>$legacyPurchase['id_proveedor'],'id_tipo_comprobante'=>$legacyPurchase['id_tipo_comprobante'],'serie'=>$legacyPurchase['serie'],'numero'=>$legacyPurchase['numero'],'fecha_emision'=>$legacyPurchase['fecha_emision'],'total_documento'=>$legacyPurchase['total_documento']],$newDetails,1,'Corrección de compra previa a la migración');
    $oldDetailId=(int)$oldDetails[0]['id_detalle_compra'];
    check11('Corregir una compra antigua conserva su detalle y crea una versión vinculada', (int)$db->query("SELECT COUNT(*) FROM detalle_compra WHERE id_detalle_compra=$oldDetailId AND es_vigente=0")->fetchColumn()===1 && (int)$db->query("SELECT COUNT(*) FROM detalle_compra WHERE id_detalle_origen=$oldDetailId AND es_vigente=1 AND version_detalle=2")->fetchColumn()===1 && (int)$db->query('SELECT stock_actual FROM producto WHERE id_producto=1')->fetchColumn()===$stockBeforeCorrection+1);
    $today=(string)$db->query('SELECT CURRENT_DATE()')->fetchColumn();
    $reports=new \Services\ReporteService();$filters=['fecha_inicio'=>$today,'fecha_fin'=>$today,'id_producto'=>1];
    $k=$reports->reporteKardex($filters,1);$v=$reports->reporteVariaciones($filters,1);
    check11('Kardex concilia saldo de migración y operaciones nuevas con stock real', (int)$k['resumen_producto']['saldo_final']===(int)$db->query('SELECT stock_actual FROM producto WHERE id_producto=1')->fetchColumn());
    check11('La apertura se muestra como Saldo inicial de migración y no como compra', count(array_filter($k['items'],fn($item)=>$item['tipo_movimiento']==='Saldo inicial de migración'))===1);
    check11('Variaciones no cuenta el saldo inicial como baja ni ajuste', (int)$v['pagination']['total']===1);
    require_once $root.'/Libraries/PDF/ReporteKardexPDF.php';require_once $root.'/Libraries/PDF/ReporteVariacionesPDF.php';require_once $root.'/Libraries/Excel/ReporteKardexExcel.php';require_once $root.'/Libraries/Excel/ReporteVariacionesExcel.php';
    foreach(['kardex'=>$k,'variaciones'=>$v] as $type=>$query){
        $input=$filters+['referencia_consulta'=>$query['referencia_consulta']];
        $pdfData=$type==='kardex'?$reports->reporteKardexParaPdf($input,1):$reports->reporteVariacionesParaPdf($input,1);
        $pdf=$type==='kardex'?(new ReporteKardexPDF())->generar($pdfData,'Admin'):(new ReporteVariacionesPDF())->generar($pdfData,'Admin');
        check11('PDF '.$type.' válido sobre la base migrada', str_starts_with($pdf,'%PDF-'));
        $excelData=$type==='kardex'?$reports->reporteKardexParaExcel($input,1):$reports->reporteVariacionesParaExcel($input,1);
        $excel=$type==='kardex'?(new ReporteKardexExcel())->generar($excelData,'Admin'):(new ReporteVariacionesExcel())->generar($excelData,'Admin');
        check11('Excel '.$type.' válido sobre la base migrada', str_starts_with($excel,'PK') && str_contains($excel,'xl/workbook.xml'));
    }
    [$other,$otherDb]=fixture11();$otherDb->exec("ALTER TABLE kardex ADD origen_operacion VARCHAR(40) DEFAULT NULL");$existing=business11($otherDb);[$code,$out]=migrate11($other);
    check11('No aplica sobre una base que ya tiene estructura Sprint 2', $code!==0 && str_contains($out,'SPRINT2_YA_INSTALADO') && business11($otherDb)===$existing);
    check11('La base habitual permanece intacta', business11($real)===$realBefore);
    echo "RESULTADO: $passed PASS / 0 FAIL\n";
} catch(Throwable $error) { fwrite(STDERR,$error->getMessage().PHP_EOL); exitCode11(1); }
finally { foreach($created as $name){ if(preg_match('/^amazon_market_s2_keep_test_[a-f0-9]{10}$/D',$name)){$server->exec("DROP DATABASE `$name`");} } if(session_status()===PHP_SESSION_ACTIVE){session_destroy();} }
function exitCode11(int $code): void { $GLOBALS['exit11']=$code; }
exit($GLOBALS['exit11']??0);
