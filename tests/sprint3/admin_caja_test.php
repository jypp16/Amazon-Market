<?php
declare(strict_types=1);
$db=getenv('TEST_DB_NAME')?:'';
if(!preg_match('/^amazon_market_admin_test_[0-9]+$/D',$db))exit(2);
putenv('DB_NAME='.$db);putenv('BASE_URL=http://127.0.0.1:8390');
require_once dirname(__DIR__,2).'/database/tercerSprint/tools/DespliegueSprint3.php';
$root=DespliegueSprint3::root();require_once $root.'/Config/Config.php';require_once $root.'/Libraries/Core/Autoload.php';$original=DespliegueSprint3::snapshot('amazon_market');$pdo=DespliegueSprint3::pdo($db);
$dir=sys_get_temp_dir().'/admin_cash_'.bin2hex(random_bytes(5));mkdir($dir,0777,true);$pass=0;$fail=0;
function ac(bool $ok,string $name):void{global $pass,$fail;if($ok)$pass++;else $fail++;echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;}
function ar(string $path,string $cookie,?array $body=null,?string $csrf=null,bool $form=false):array{
 $c=curl_init('http://127.0.0.1:8390'.$path);$h=[];curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_TIMEOUT=>15]);
 if($body!==null){curl_setopt($c,CURLOPT_POST,true);curl_setopt($c,CURLOPT_POSTFIELDS,$form?http_build_query($body):json_encode($body));$h[]='Content-Type: '.($form?'application/x-www-form-urlencoded':'application/json');}if($csrf)$h[]='X-CSRF-TOKEN: '.$csrf;curl_setopt($c,CURLOPT_HTTPHEADER,$h);
 $r=(string)curl_exec($c);$code=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);$n=(int)curl_getinfo($c,CURLINFO_HEADER_SIZE);curl_close($c);return ['code'=>$code,'head'=>substr($r,0,$n),'body'=>substr($r,$n),'json'=>json_decode(substr($r,$n),true)];
}
function al(array $u,string $cookie):string{$r=ar('/Auth',$cookie);preg_match('/name="csrf_token"\s+value="([a-f0-9]{64})"/i',$r['body'],$m);$t=$m[1]??'';$r=ar('/Auth/login',$cookie,['username'=>$u['username'],'password'=>'AdminCash#2026','csrf_token'=>$t],null,true);ac($r['code']===302,'login '.$u['id_rol']);return $t;}
$server=proc_open([PHP_BINARY,'-d','session.save_path='.$dir,'-S','127.0.0.1:8390','-t',$root,$root.'/tests/sprint3/server_router_sprint3.php'],[0=>['pipe','r'],1=>['file',$dir.'/out.log','a'],2=>['file',$dir.'/err.log','a']],$pipes,$root,array_merge(getenv(),['DB_NAME'=>$db,'TEST_DB_NAME'=>$db,'BASE_URL'=>'http://127.0.0.1:8390']));
try{
 for($i=0;$i<40;$i++){if(ar('/Auth',$dir.'/ready')['code']===200)break;usleep(100000);}
 $admin=$pdo->query('SELECT id_usuario,id_rol,username FROM usuario WHERE estado=1 AND id_rol=1 LIMIT 1')->fetch();$seller=$pdo->query('SELECT id_usuario,id_rol,username FROM usuario WHERE estado=1 AND id_rol=2 LIMIT 1')->fetch();
 $q=$pdo->prepare('UPDATE usuario SET password_hash=? WHERE id_usuario=?');foreach([$admin,$seller] as $u)$q->execute([password_hash('AdminCash#2026',PASSWORD_BCRYPT),$u['id_usuario']]);$a=al($admin,$dir.'/a');$v=al($seller,$dir.'/v');
 $r=ar('/api/caja/turno-hoy',$dir.'/a');ac($r['code']===200&&($r['json']['data']['mode']??'')==='SIN_HORARIO','administrador sin horario');
 $r=ar('/Caja/abrir',$dir.'/a');ac($r['code']===200&&str_contains($r['body'],'cash_opening_form'),'pantalla apertura administrador');
 $r=ar('/Venta',$dir.'/a');ac(str_contains($r['body'],'Debes abrir caja para realizar una venta')&&!str_contains($r['body'],'id="pos_shelf"'),'POS administrativo bloqueado sin caja');
 $bad=ar('/api/caja/abrir',$dir.'/v',['cash_counted'=>'0.00','modalidad'=>'SIN_HORARIO','referencia_solicitud'=>'seller_fake_'.bin2hex(random_bytes(8))],$v);ac($bad['code']===422,'vendedor no puede inyectar modalidad');
 $turnAdmin=(int)$pdo->query('SELECT id_turno FROM turno WHERE estado=1 LIMIT 1')->fetchColumn();$bad=ar('/api/caja/abrir',$dir.'/a',['cash_counted'=>'0.00','shift_id'=>$turnAdmin,'referencia_solicitud'=>'admin_fake_shift_'.bin2hex(random_bytes(8))],$a);ac($bad['code']===422,'administrador no acepta turno ficticio');
 $open=['cash_counted'=>'100.00','referencia_solicitud'=>'admin_open_'.bin2hex(random_bytes(8))];$r=ar('/api/caja/abrir',$dir.'/a',$open,$a);ac($r['code']===201,'apertura administrativa sin turno');
 if($r['code']!==201)throw new RuntimeException('Apertura no disponible: '.$r['body']);
 $id=$r['json']['data']['session_id'];$s=$pdo->query('SELECT * FROM sesion_caja WHERE id_sesion_caja='.(int)$id)->fetch();ac($s['modalidad']==='SIN_HORARIO'&&$s['id_turno']===null&&$s['fecha_hora_salida_referencia']===null,'snapshot sin turno ficticio');
 $r=ar('/api/caja/abrir',$dir.'/a',$open,$a);ac($r['code']===200&&($r['json']['idempotent_replay']??false),'replay apertura');
 $r=ar('/Venta',$dir.'/a');ac(str_contains($r['body'],'id="pos_shelf"'),'POS con caja propia administrativa');
 $r=ar('/api/caja/sesion-actual',$dir.'/v');ac($r['json']['data']['balance']===null,'vendedor no recibe saldo ajeno');
 $r=ar('/api/caja/ingresos',$dir.'/a',['type'=>'manual_income','amount'=>'10.00','reason'=>'Ingreso prueba administrador','referencia_solicitud'=>'admin_income_'.bin2hex(random_bytes(8))],$a);ac($r['code']===201,'ingreso propio administrador');
 $incomeId=(int)$pdo->query("SELECT id_movimiento_caja FROM movimiento_caja WHERE id_sesion_caja=".(int)$id." AND tipo_movimiento='INGRESO_MANUAL' LIMIT 1")->fetchColumn();
 $r=ar('/api/caja/movimientos/correcciones',$dir.'/a',['session_id'=>$id,'original_movement_id'=>$incomeId,'corrected_amount'=>'12.00','reason'=>'Corrección de importe','referencia_solicitud'=>'admin_correct_'.bin2hex(random_bytes(8))],$a);ac($r['code']===201,'corrección vinculada propia');
 $product=(int)$pdo->query('SELECT id_producto FROM producto WHERE estado=1 AND stock_actual>10 AND precio_venta>0 LIMIT 1')->fetchColumn();$cli=(int)$pdo->query('SELECT id_cliente FROM cliente WHERE estado=1 AND id_tipo_documento<>2 LIMIT 1')->fetchColumn();$comp=(int)$pdo->query("SELECT id_tipo_comprobante FROM tipo_comprobante WHERE estado=1 AND nombre LIKE '%Boleta%' LIMIT 1")->fetchColumn();
 foreach([1,2,3] as $method){$r=ar('/api/ventas',$dir.'/a',['id_cliente'=>$cli,'id_tipo_comprobante'=>$comp,'id_metodo_pago'=>$method,'productos'=>[['id_producto'=>$product,'cantidad'=>1]],'referencia_solicitud'=>'admin_sale_'.bin2hex(random_bytes(8))],$a);ac($r['code']===201,'venta administrativa medio '.$method);}
 $clockService=new Services\CajaService($pdo);
 foreach(['2026-11-14 00:01:00','2026-11-14 08:00:00','2026-11-15 23:59:00'] as $instant){$clockService->setClockProvider(fn()=>new DateTimeImmutable($instant,new DateTimeZone('America/Lima')));$clockState=$clockService->consultarEstadoArqueo((int)$admin['id_usuario'],[])['data']['schedule'];ac($clockState['requires_authorization']===false&&$clockState['scheduled_end']===null,'administrador sin restricción a '.$instant);}
 $clockService->setClockProvider(null);
 $r=ar('/api/caja/arqueo/estado',$dir.'/a');ac($r['code']===200&&$r['json']['data']['schedule']['requires_authorization']===false&&$r['json']['data']['schedule']['scheduled_end']===null,'cierre libre de horario');
 $r=ar('/api/caja/cerrar',$dir.'/a',['arqueo_id'=>999999,'referencia_solicitud'=>'admin_close_missing_'.bin2hex(random_bytes(8))],$a);ac($r['code']===409,'sin arqueo no cierra');
 $state=ar('/api/caja/arqueo/estado',$dir.'/a')['json']['data'];
 $r=ar('/api/caja/arqueo',$dir.'/a',['version'=>$state['version'],'counted'=>(string)$state['expected'],'referencia_solicitud'=>'admin_count_'.bin2hex(random_bytes(8))],$a);ac($r['code']===201,'arqueo administrador');$countId=$r['json']['data']['arqueo']['id']??$r['json']['data']['id']??$r['json']['data']['arqueo_id']??null;
 if(!$countId)throw new RuntimeException('Revisar forma arqueo: '.$r['body']);
 $staleId=$countId;
 $r=ar('/api/caja/ingresos',$dir.'/a',['type'=>'manual_income','amount'=>'1.00','reason'=>'Desactualiza arqueo','referencia_solicitud'=>'admin_stale_income_'.bin2hex(random_bytes(8))],$a);ac($r['code']===201,'movimiento posterior a arqueo');
 $r=ar('/api/caja/cerrar',$dir.'/a',['arqueo_id'=>$staleId,'referencia_solicitud'=>'admin_stale_close_'.bin2hex(random_bytes(8))],$a);ac($r['code']===409&&($r['json']['code']??'')==='ARQUEO_STALE','administrador tampoco cierra con arqueo desactualizado');
 $state=ar('/api/caja/arqueo/estado',$dir.'/a')['json']['data'];$r=ar('/api/caja/arqueo',$dir.'/a',['version'=>$state['version'],'counted'=>(string)$state['expected'],'referencia_solicitud'=>'admin_recount_'.bin2hex(random_bytes(8))],$a);$countId=$r['json']['data']['arqueo']['id']??$r['json']['data']['id']??$r['json']['data']['arqueo_id']??null;ac($r['code']===201&&$countId,'nuevo arqueo vigente');
 $close=['arqueo_id'=>$countId,'referencia_solicitud'=>'admin_close_'.bin2hex(random_bytes(8))];$r=ar('/api/caja/cerrar',$dir.'/a',$close,$a);ac($r['code']===200,'cierre administrador sin credenciales');if($r['code']!==200)echo $r['body'].PHP_EOL;
 $r=ar('/api/caja/cerrar',$dir.'/a',$close,$a);ac($r['code']===200&&($r['json']['idempotent_replay']??false),'replay cierre sin duplicación');
 $query='desde='.date('Y-m-d').'&hasta='.date('Y-m-d').'&turno_id=sin_horario&vendedor_id='.$admin['id_usuario'];
 $r=ar('/api/reportes/cierres-caja?'.$query,$dir.'/a');ac($r['code']===200,'reporte filtrado Sin horario y administrador');
 $snapshot=$r['json']['data']['snapshot_id']??null;
 if(!$snapshot)throw new RuntimeException('Snapshot no disponible: '.$r['body']);
 foreach(['pdf','excel'] as $format){$r=ar('/api/reportes/exportar-'.$format.'?tipo=cierres-caja&'.$query.'&snapshot_id='.$snapshot,$dir.'/a');ac($r['code']===200&&str_starts_with($r['body'],$format==='pdf'?'%PDF-':'PK'),'exportación administrativa '.$format);if($r['code']!==200)echo $r['body'].PHP_EOL;}
 $r=ar('/api/reportes/cierres-caja/'.(int)$id,$dir.'/a');ac($r['code']===200&&($r['json']['data']['closure']['reference_schedule']['label']??'')==='Sin horario','detalle de cierre sin horario ficticio');
 $r=ar('/api/caja/consulta/vendedores',$dir.'/a');ac(in_array((int)$admin['id_usuario'],array_column($r['json']['data']??[],'id'),true),'responsable administrador en filtros');
 $r=ar('/Caja/consultar?propia=1',$dir.'/a');ac(!str_contains($r['body'],'id="cash_query_filters"'),'consulta propia separada del histórico');
 $turn=(int)$pdo->query('SELECT id_turno FROM turno WHERE estado=1 LIMIT 1')->fetchColumn();
 $r=ar('/api/caja/abrir',$dir.'/v',['cash_counted'=>'0.00','referencia_solicitud'=>'seller_no_shift_'.bin2hex(random_bytes(8))],$v);ac($r['code']===422,'vendedor mantiene turno obligatorio');
 $r=ar('/api/caja/abrir',$dir.'/v',['cash_counted'=>'0.00','shift_id'=>$turn,'referencia_solicitud'=>'seller_open_'.bin2hex(random_bytes(8))],$v);ac($r['code']===201,'vendedor sigue abriendo con turno');
 $r=ar('/api/caja/abrir',$dir.'/a',['cash_counted'=>'0.00','referencia_solicitud'=>'admin_occupied_'.bin2hex(random_bytes(8))],$a);ac($r['code']===409&&($r['json']['code']??'')==='CASH_ALREADY_OPEN','administrador no abre segunda caja física');
 $r=ar('/api/caja/ingresos',$dir.'/a',['type'=>'manual_income','amount'=>'1.00','reason'=>'No debe registrarse','referencia_solicitud'=>'admin_foreign_'.bin2hex(random_bytes(8))],$a);ac($r['code']===403,'administrador no opera caja de vendedor');
 $currentSeller=$pdo->query("SELECT * FROM sesion_caja WHERE estado='ABIERTA'")->fetch();$end=new DateTimeImmutable($currentSeller['fecha_hora_salida_referencia'],new DateTimeZone('America/Lima'));
 foreach([16=>true,15=>false,-30=>false] as $minutes=>$auth){$clockService->setClockProvider(fn()=>$end->modify(($minutes>=0?'-':'+').abs($minutes).' minutes'));$clockState=$clockService->consultarEstadoArqueo((int)$seller['id_usuario'],[])['data']['schedule'];ac($clockState['requires_authorization']===$auth,'vendedor conserva margen '.$minutes.' minutos');}
 $clockService->setClockProvider(null);
 // Revocar y restaurar una asignación solamente en esta copia.
 $permission=(int)$pdo->query("SELECT id_permiso FROM permiso WHERE slug='ventas.procesar'")->fetchColumn();$pdo->prepare('DELETE FROM rol_permiso WHERE id_rol=1 AND id_permiso=?')->execute([$permission]);
 $nSales=(int)$pdo->query('SELECT COUNT(*) FROM venta')->fetchColumn();$denied=ar('/api/ventas',$dir.'/a',['id_metodo_pago'=>1],$a);ac($denied['code']===403,'API respeta permiso administrativo revocado');$denied=ar('/Venta/guardar',$dir.'/a',['id_metodo_pago'=>1],$a);ac(in_array($denied['code'],[302,403],true)&&(int)$pdo->query('SELECT COUNT(*) FROM venta')->fetchColumn()===$nSales,'ruta legacy no permite bypass administrativo');$pdo->prepare('INSERT INTO rol_permiso(id_rol,id_permiso) VALUES(1,?)')->execute([$permission]);
 ac($original===DespliegueSprint3::snapshot('amazon_market'),'base principal conservada');
}catch(Throwable $e){ac(false,$e->getMessage());}finally{foreach($pipes as $p)if(is_resource($p))fclose($p);proc_terminate($server);proc_close($server);}
echo "TOTAL ADMIN: $pass PASS, $fail FAIL".PHP_EOL;exit($fail?1:0);
