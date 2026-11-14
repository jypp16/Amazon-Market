<?php
declare(strict_types=1);
// Ejecutar después del Paso 12 sobre una copia NUEVA final14 creada en esta sesión.
if (!preg_match('/^(?:amazon_market_sprint3_final14_[a-z0-9]+_[0-9]+|amazon_market_sprint3_deploy_rehearsal_[0-9]+)$/', getenv('TEST_DB_NAME') ?: '')) exit(2);
define('SPRINT3_PASO12_SOLO_HELPERS', true);
require __DIR__.'/paso12_consultas_reportes_test.php';
$checks14=0;
function check14(bool $ok,string $label):void {global $checks14;if(!$ok)throw new RuntimeException('FAIL '.$label);$checks14++;echo 'PASS '.$label.PHP_EOL;}
function post14(string $path,array $body):array {global $base14,$sellerCookie14,$csrf14;$body['request_id']='p14_'.bin2hex(random_bytes(16));$r=httpRequest('POST',$base14.$path,$sellerCookie14,$body,$csrf14);if(!in_array($r['status'],[200,201],true))throw new RuntimeException($path.' '.$r['status'].' '.$r['raw']);return $r['json']['data'];}
function file14(string $url,?string $cookie):array {$c=curl_init($url);$h=[];curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>45,CURLOPT_HEADERFUNCTION=>function($c,$line)use(&$h){$p=explode(':',$line,2);if(count($p)===2)$h[strtolower(trim($p[0]))]=trim($p[1]);return strlen($line);}]);if($cookie)curl_setopt($c,CURLOPT_COOKIEFILE,$cookie);$raw=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);return ['status'=>$status,'headers'=>$h,'raw'=>$raw===false?'':$raw];}
function rows14(string $xml):array {$x=simplexml_load_string($xml);$out=[];foreach($x->sheetData->row as $row){$values=[];foreach($row->c as $c){$col=preg_replace('/[0-9]/','',(string)$c['r']);$values[$col]=(string)$c['t']==='inlineStr'?(string)$c->is->t:(string)$c->v;}$out[]=$values;}return $out;}
$pdo=\Libraries\Core\Conexion::getInstance()->conect();
$admin=$pdo->query("SELECT * FROM usuario WHERE nombre='Admin Paso 12' ORDER BY id_usuario DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$other=$pdo->query("SELECT * FROM usuario WHERE nombre='Admin Dos P12' ORDER BY id_usuario DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$seller=$pdo->query("SELECT * FROM usuario WHERE nombre='Vendedor Dos P12' ORDER BY id_usuario DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check14(is_array($admin)&&is_array($seller)&&is_array($other),'fixtures Paso 12 disponibles');
$dir14=sys_get_temp_dir().'/p14_acceptance_'.bin2hex(random_bytes(6));mkdir($dir14,0700);
$adminCookie14=$dir14.'/admin.cookie';$otherCookie14=$dir14.'/other.cookie';$sellerCookie14=$dir14.'/seller.cookie';
$srv14=null;$trigger14=false;$restorePermission14=null;
try {
$srv14=startPhpServer($phpBin,$rootDir,(int)(getenv('TEST_HTTP_PORT')?:8391),getenv('TEST_DB_NAME'),$dir14);
$base14=$srv14['baseUrl'];
foreach([[$adminCookie14,$admin],[$otherCookie14,$other],[$sellerCookie14,$seller]] as [$cookie,$u]){ $a=loginViaHttp($base14,$cookie,$u['username'],'ClavePrueba123!');check14($a['ok'],'login '.$u['nombre']);if($u['id_usuario']===$seller['id_usuario'])$csrf14=['X-CSRF-TOKEN: '.$a['csrf']];}
$session=$pdo->query("SELECT * FROM sesion_caja WHERE estado='ABIERTA'")->fetch(PDO::FETCH_ASSOC);
check14((int)$session['id_usuario_vendedor']===(int)$seller['id_usuario'],'sesión abierta del vendedor de ensayo');
// Turno pasado: ninguna dependencia del reloj para cerrar los nuevos turnos.
$pdo->exec("INSERT INTO turno(nombre,hora_inicio,hora_fin,estado) VALUES('P14 pasado','00:00:00','00:01:00',1)");$shift=(int)$pdo->lastInsertId();
$long='=1+1 Ñandú '.str_repeat('W',220);
for($i=0;$i<1005;$i++){$d=post14('/api/caja/ingresos',['type'=>'manual_income','amount'=>'0.01','reason'=>$i===0?$long:'P14 movimiento '.$i]);if($i===0)$original=(int)$d['movement']['id'];}
check14((int)$pdo->query('SELECT COUNT(*) FROM movimiento_caja WHERE id_sesion_caja='.(int)$session['id_sesion_caja'])->fetchColumn()>=1005,'más de 1000 movimientos confirmados por API');
post14('/api/caja/movimientos/correcciones',['original_movement_id'=>$original,'corrected_amount'=>'0.00','reason'=>'Corrección a cero']);
post14('/api/caja/movimientos/correcciones',['original_movement_id'=>$original,'corrected_amount'=>'0.02','reason'=>'Corrección sucesiva']);
post14('/api/caja/ingresos',['type'=>'admin_injection','amount'=>'2.00','reason'=>'Inyección P14','administrator_id'=>(int)$admin['id_usuario']]);
post14('/api/caja/egresos',['type'=>'admin_withdrawal','amount'=>'1.00','reason'=>'Retiro P14','administrator_id'=>(int)$admin['id_usuario']]);
function close14(float $difference=0):void {global $pdo; $s=$pdo->query("SELECT * FROM sesion_caja WHERE estado='ABIERTA'")->fetch(PDO::FETCH_ASSOC);$expected=(float)$s['efectivo_esperado_actual'];$d=post14('/api/caja/arqueo',['counted'=>round($expected+$difference,2),'version'=>(int)$s['version_caja'],'observation'=>$difference!==0.0?'Diferencia explicada P14':'Observación conservada']);$body=['arqueo_id'=>(int)$d['arqueo']['id']];global $admin;if(new DateTimeImmutable('now',new DateTimeZone('America/Lima'))<new DateTimeImmutable($s['fecha_hora_salida_referencia'].' -15 minutes',new DateTimeZone('America/Lima'))){$body+=['admin_email'=>$admin['email'],'admin_password'=>'ClavePrueba123!','exception_reason'=>'Cierre fixture P14'];}post14('/api/caja/cerrar',$body);}
close14();
for($i=0;$i<11;$i++){post14('/api/caja/abrir',['shift_id'=>$shift,'cash_counted'=>'100.00']);close14($i%3===0?-1.00:($i%3===1?1.50:0));}
$filters='desde=2020-01-01&hasta=2030-01-01';
$r=httpRequest('GET',$base14.'/api/reportes/cierres-caja?'.$filters.'&page_size=2',$adminCookie14);check14($r['status']===200,'consulta completa');$data=$r['json']['data'];$snap=(int)$data['snapshot_id'];$total=(int)$data['pagination']['total_closures'];check14($total>10,'más cierres que page_size');
$r=httpRequest('GET',$base14.'/api/reportes/cierres-caja?snapshot_id='.$snap.'&page=2&page_size=2',$adminCookie14);check14($r['status']===200&&count($r['json']['data']['closures'])===2,'página 2 del mismo snapshot');
$rawItems=$pdo->query('SELECT proyeccion_json FROM reporte_caja_snapshot_item WHERE id_snapshot='.$snap.' ORDER BY orden_posicion')->fetchAll(PDO::FETCH_COLUMN);$frozenHash=hash('sha256',json_encode($rawItems));
$closedId=(int)$session['id_sesion_caja'];
foreach(['pdf','excel'] as $format){
$url=$base14.'/api/reportes/exportar-'.$format.'?tipo=cierres-caja&snapshot_id='.$snap;
$f=file14($url,$adminCookie14);check14($f['status']===200,'descarga '.$format);$mime=$format==='pdf'?'application/pdf':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';check14(($f['headers']['content-type']??'')===$mime,'MIME '.$format);check14((int)($f['headers']['content-length']??0)===strlen($f['raw']),'longitud '.$format);check14(str_contains($f['headers']['content-disposition']??'','attachment;'),'attachment '.$format);check14(($f['headers']['x-content-type-options']??'')==='nosniff'&&str_contains($f['headers']['cache-control']??'','no-store'),'privacidad '.$format);check14(str_starts_with($f['raw'],$format==='pdf'?'%PDF-':'PK'),'firma '.$format);file_put_contents($dir14.'/conjunto.'.($format==='pdf'?'pdf':'xlsx'),$f['raw']);
foreach([['',null,401],['',$sellerCookie14,403],['',$otherCookie14,404],['&desde=2000-01-01',$adminCookie14,409]] as [$extra,$cookie,$code]){check14(file14($url.$extra,$cookie)['status']===$code,'rechazo '.$code.' '.$format);}
check14(file14($base14.'/api/reportes/exportar-'.$format.'?tipo=cierres-caja&session_id='.$closedId,$adminCookie14)['status']===200,'sesión individual '.$format);
$empty=file14($base14.'/api/reportes/exportar-'.$format.'?tipo=cierres-caja&desde=2000-01-01&hasta=2000-01-02',$adminCookie14);check14($empty['status']===200,'vacío exportable '.$format);file_put_contents($dir14.'/vacio.'.($format==='pdf'?'pdf':'xlsx'),$empty['raw']);
}
$zip=new ZipArchive();check14($zip->open($dir14.'/conjunto.xlsx')===true,'XLSX recibido abre');$sheets=[];for($i=1;$i<=3;$i++)$sheets[]=rows14($zip->getFromName('xl/worksheets/sheet'.$i.'.xml'));$zip->close();
check14(count($sheets[1])===$total+2,'Excel contiene TODOS los cierres más encabezado y total');
check14(count($sheets[2])>1005,'Excel contiene TODOS los movimientos');
$sum=[];foreach($sheets[0] as $row)$sum[$row['A']??'']=$row['B']??null;
foreach(['Efectivo esperado'=>'expected_cash_total','Efectivo contado'=>'counted_cash_total','Diferencia neta de cierre'=>'closing_difference_net','Diferencia neta de apertura'=>'opening_difference_net','Ventas en efectivo'=>'cash_sales_total','Ventas con tarjeta'=>'card_sales_total','Ventas Yape / Plin'=>'yape_plin_sales_total','Ingresos netos'=>'income_total','Egresos netos'=>'expenses_total'] as $label=>$field)check14(round((float)$sum[$label]*100)===round((float)$data['totals'][$field]*100),'reconciliación '.$label);
check14(in_array($long,array_column($sheets[2],'E'),true)||count(array_filter($sheets[2],fn($r)=>str_starts_with($r['E']??'',$long)))>0,'motivo largo y fórmula aparente conservados');
$roles=array_column($sheets[2],'H');check14(in_array('Entregó',$roles,true)&&in_array('Recibió',$roles,true),'administrador entregó y recibió');
foreach(['reportes.exportar','reportes.cierres_caja','reportes.ver'] as $slug){
$permission=$pdo->prepare('SELECT id_permiso FROM permiso WHERE slug=?');$permission->execute([$slug]);$id=(int)$permission->fetchColumn();check14($id>0,'permiso existente '.$slug);
// Retirar únicamente la asignación de la copia de ensayo y restaurarla en finally.
$delete=$pdo->prepare('DELETE FROM rol_permiso WHERE id_rol=1 AND id_permiso=?');$delete->execute([$id]);$restorePermission14=$id;
try{foreach(['pdf','excel'] as $format){$code=file14($base14.'/api/reportes/exportar-'.$format.'?tipo=cierres-caja&snapshot_id='.$snap,$adminCookie14)['status'];check14($code===($slug==='reportes.ver'?200:403),'permiso retirado '.$slug.' '.$format);}$view=httpRequest('GET',$base14.'/Reporte/cierresCaja',$adminCookie14);check14(str_contains($view['raw'],'id="closures_export_pdf"')===($slug==='reportes.ver'),'botón acorde a permisos '.$slug);}finally{$pdo->prepare('INSERT INTO rol_permiso(id_rol,id_permiso) VALUES(1,?)')->execute([$id]);$restorePermission14=null;}}
$pdo->prepare('UPDATE usuario SET estado=0 WHERE id_usuario=?')->execute([$admin['id_usuario']]);try{foreach(['pdf','excel'] as $format)check14(file14($base14.'/api/reportes/exportar-'.$format.'?tipo=cierres-caja',$adminCookie14)['status']===401,'admin inactivo '.$format);}finally{$pdo->prepare('UPDATE usuario SET estado=1 WHERE id_usuario=?')->execute([$admin['id_usuario']]);}
check14(loginViaHttp($base14,$adminCookie14,$admin['username'],'ClavePrueba123!')['ok'],'restaurar login');
post14('/api/caja/abrir',['shift_id'=>$shift,'cash_counted'=>'100.00']);
foreach(['pdf','excel'] as $format){$id=$pdo->query("SELECT id_sesion_caja FROM sesion_caja WHERE estado='ABIERTA'")->fetchColumn();check14(file14($base14.'/api/reportes/exportar-'.$format.'?tipo=cierres-caja&session_id='.$id,$adminCookie14)['status']===409,'rechazar sesión abierta '.$format);}
post14('/api/caja/egresos',['type'=>'omitted_withdrawal','amount'=>'1.00','reason'=>'Declaración posterior al snapshot P14','administrator_id'=>(int)$admin['id_usuario'],'origin_session_id'=>$closedId,'declared_occurred_in_open_origin_session'=>true,'confirmed_reflected_in_opening'=>true]);
$after=$pdo->query('SELECT proyeccion_json FROM reporte_caja_snapshot_item WHERE id_snapshot='.$snap.' ORDER BY orden_posicion')->fetchAll(PDO::FETCH_COLUMN);check14(hash('sha256',json_encode($after))===$frozenHash,'snapshot inmutable tras retiro declarado posteriormente');
$new=httpRequest('GET',$base14.'/api/reportes/cierres-caja?'.$filters,$adminCookie14);$newId=(int)$new['json']['data']['snapshot_id'];$changed=$pdo->query('SELECT proyeccion_json FROM reporte_caja_snapshot_item WHERE id_snapshot='.$newId.' AND id_sesion_caja='.$closedId)->fetchColumn();check14(str_contains($changed,'linked_omitted_withdrawals')&&count(json_decode($changed,true)['linked_omitted_withdrawals'])===1,'nueva captura incorpora vínculo posterior sin alterar cierre');
$pdo->exec("CREATE TRIGGER p14_fallo_snapshot BEFORE UPDATE ON reporte_consulta_snapshot FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='P14 fallo controlado'");$trigger14=true;
try{foreach(['pdf','excel'] as $format){$r=file14($base14.'/api/reportes/exportar-'.$format.'?tipo=cierres-caja&snapshot_id='.$snap,$adminCookie14);$b=json_decode($r['raw'],true);check14($r['status']===500&&($b['code']??'')===($format==='pdf'?'PDF_GENERATION_FAILED':'EXCEL_GENERATION_FAILED'),'fallo controlado JSON '.$format);check14(!str_contains($r['raw'],'SQLSTATE')&&!str_contains($r['raw'],'P14 fallo'),'sin trazas '.$format);}}finally{$pdo->exec('DROP TRIGGER p14_fallo_snapshot');$trigger14=false;}
foreach(['pdf','excel'] as $format)check14(file14($base14.'/api/reportes/exportar-'.$format.'?tipo=cierres-caja&snapshot_id='.$snap,$adminCookie14)['status']===200,'reintento tras fallo '.$format);
foreach(['pdf','excel'] as $format){
$baseExport=$base14.'/api/reportes/exportar-'.$format.'?tipo=cierres-caja';
check14(file14($baseExport.'&desde=2026-12-31&hasta=2026-01-01',$adminCookie14)['status']===422,'rango invertido '.$format);
check14(file14($baseExport.'&desde=2026-02-30',$adminCookie14)['status']===422,'fecha imposible '.$format);
check14(file14($baseExport.'&snapshot_id=abc',$adminCookie14)['status']===422,'snapshot no entero '.$format);
check14(file14($baseExport.'&session_id='.$closedId.'&sesion_id=99999',$adminCookie14)['status']===422,'alias contradictorios '.$format);
}
$model14=new \Models\CajaModel($pdo);
$header14=$pdo->query('SELECT * FROM reporte_consulta_snapshot WHERE id_snapshot='.$snap)->fetch(PDO::FETCH_ASSOC);
foreach(['movements','linked_omitted_withdrawals'] as $field){
    $legacyItems=array_map(static fn($json)=>json_decode($json,true),$rawItems);
    unset($legacyItems[0][$field]);
    $legacyHeader=$header14;$legacyHeader['referencia_consulta']='legacy14_'.bin2hex(random_bytes(16));
    $legacyId=$model14->crearSnapshotCierresCaja($legacyHeader,$legacyItems);
    foreach(['pdf','excel'] as $format)check14(file14($base14.'/api/reportes/exportar-'.$format.'?tipo=cierres-caja&snapshot_id='.$legacyId,$adminCookie14)['status']===404,'snapshot antiguo sin '.$field.' '.$format);
}
$emptyZip=new ZipArchive();$emptyZip->open($dir14.'/vacio.xlsx');$emptyRows=rows14($emptyZip->getFromName('xl/worksheets/sheet2.xml'));$emptyZip->close();check14(count($emptyRows)===2,'Excel vacío solo encabezado y totales');
$pdo->prepare('UPDATE reporte_consulta_snapshot SET expira_en=? WHERE id_snapshot=?')->execute(['2000-01-01 00:00:00',$snap]);foreach(['pdf','excel'] as $format)check14(file14($base14.'/api/reportes/exportar-'.$format.'?tipo=cierres-caja&snapshot_id='.$snap,$adminCookie14)['status']===404,'snapshot vencido '.$format);
check14((int)$pdo->query('SELECT COUNT(*) FROM reporte_consulta_snapshot WHERE id_snapshot='.$snap)->fetchColumn()===1,'snapshot vencido conserva registro');
echo 'ARTIFACTS '.$dir14.PHP_EOL;
}finally{if($trigger14)$pdo->exec('DROP TRIGGER p14_fallo_snapshot');if($restorePermission14!==null)$pdo->prepare('INSERT INTO rol_permiso(id_rol,id_permiso) VALUES(1,?)')->execute([$restorePermission14]);stopPhpServer($srv14);}
echo 'TOTAL PASO14 '.$checks14.' PASS, 0 FAIL'.PHP_EOL;
