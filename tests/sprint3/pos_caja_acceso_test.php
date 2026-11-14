<?php
declare(strict_types=1);
// Solo copias nuevas migradas. Nunca cambia usuarios ni caja de amazon_market.
$db=getenv('TEST_DB_NAME')?:'';
if(!preg_match('/^amazon_market_sprint3_deploy_rehearsal_[0-9]+$/D',$db))exit(2);
putenv('DB_NAME='.$db);putenv('BASE_URL=http://127.0.0.1:8385');
require_once dirname(__DIR__,2).'/database/tercerSprint/tools/DespliegueSprint3.php';
$root=DespliegueSprint3::root();$original=DespliegueSprint3::snapshot('amazon_market');$pdo=DespliegueSprint3::pdo($db);
$dir=sys_get_temp_dir().'/pos_gate_'.bin2hex(random_bytes(5));mkdir($dir,0777,true);
$passed=0;$failed=0;
function checkPos(bool $ok,string $name):void{global $passed,$failed;if($ok)$passed++;else $failed++;echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;}
function reqPos(string $path,string $cookie,?array $body=null,bool $form=false,?string $csrf=null):array{
$c=curl_init('http://127.0.0.1:8385'.$path);$h=[];
curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_TIMEOUT=>15]);
if($body!==null){curl_setopt($c,CURLOPT_POST,true);curl_setopt($c,CURLOPT_POSTFIELDS,$form?http_build_query($body):json_encode($body));$h[]='Content-Type: '.($form?'application/x-www-form-urlencoded':'application/json');}
if($csrf!==null)$h[]='X-CSRF-TOKEN: '.$csrf;curl_setopt($c,CURLOPT_HTTPHEADER,$h);
$r=(string)curl_exec($c);$code=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);$n=(int)curl_getinfo($c,CURLINFO_HEADER_SIZE);curl_close($c);return ['code'=>$code,'headers'=>substr($r,0,$n),'body'=>substr($r,$n),'json'=>json_decode(substr($r,$n),true)];}
function loginPos(array $u,string $cookie):string{
$r=reqPos('/Auth',$cookie);preg_match('/name="csrf_token"\s+value="([a-f0-9]{64})"/i',$r['body'],$m);$token=$m[1]??'';
$r=reqPos('/Auth/login',$cookie,['username'=>$u['username'],'password'=>'PosGate#2026','csrf_token'=>$token],true);
$dest=(int)$u['id_rol']===2?'/Caja/abrir':'/Home';checkPos($r['code']===302&&str_contains($r['headers'],$dest),'Login rol '.$u['id_rol'].' dirige a '.$dest);return $token;}
$srv=proc_open([PHP_BINARY,'-d','session.save_path='.$dir,'-S','127.0.0.1:8385','-t',$root,$root.'/tests/sprint3/server_router_sprint3.php'],[0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$pipes,$root,array_merge(getenv(),['DB_NAME'=>$db,'TEST_DB_NAME'=>$db,'BASE_URL'=>'http://127.0.0.1:8385']));
try{
for($i=0;$i<40;$i++){if(reqPos('/Auth',$dir.'/ready')['code']===200)break;usleep(100000);}
$users=$pdo->query('SELECT id_usuario,id_rol,username FROM usuario WHERE estado=1 AND id_rol IN(1,2) ORDER BY id_rol,id_usuario')->fetchAll();$admin=null;$v=[];foreach($users as $u){if((int)$u['id_rol']===1)$admin=$u;elseif(count($v)<2)$v[]=$u;}if(!$admin||count($v)!==2)throw new RuntimeException('Usuarios insuficientes en clon');
$q=$pdo->prepare('UPDATE usuario SET password_hash=? WHERE id_usuario=?');foreach([$admin,...$v] as $u)$q->execute([password_hash('PosGate#2026',PASSWORD_BCRYPT),$u['id_usuario']]);
$t1=loginPos($v[0],$dir.'/v1');$t2=loginPos($v[1],$dir.'/v2');loginPos($admin,$dir.'/admin');
$r=reqPos('/Venta',$dir.'/v1');checkPos($r['code']===200&&str_contains($r['body'],'Debes abrir caja para realizar una venta'),'Caja cerrada muestra aviso');checkPos(!str_contains($r['body'],'id="pos_shelf"')&&!str_contains($r['body'],'id="pos_cart"'),'Caja cerrada no entrega catálogo ni carrito');checkPos(str_contains($r['body'],'href="http://127.0.0.1:8385/Caja/abrir"'),'Aviso permite abrir caja');checkPos(str_contains(strtolower($r['headers']),'no-store'),'POS no se guarda en caché');
$p=$pdo->query('SELECT id_producto FROM producto WHERE estado=1 AND stock_actual>5 AND precio_venta>0 LIMIT 1')->fetchColumn();$cli=$pdo->query('SELECT id_cliente FROM cliente WHERE estado=1 AND id_tipo_documento<>2 LIMIT 1')->fetchColumn();$comp=$pdo->query("SELECT id_tipo_comprobante FROM tipo_comprobante WHERE estado=1 AND nombre LIKE '%Boleta%' LIMIT 1")->fetchColumn();
$payload=['id_cliente'=>(int)$cli,'id_tipo_comprobante'=>(int)$comp,'id_metodo_pago'=>1,'productos'=>[['id_producto'=>(int)$p,'cantidad'=>1]],'referencia_solicitud'=>'pos_gate_'.bin2hex(random_bytes(8))];$before=DespliegueSprint3::snapshot($db);
$r=reqPos('/api/ventas',$dir.'/v1',$payload,false,$t1);checkPos(($r['json']['code']??'')==='CASH_SESSION_CLOSED','API rechaza venta sin apertura');checkPos($before===DespliegueSprint3::snapshot($db),'Rechazo sin cambios en BD');
$r=reqPos('/Venta',$dir.'/admin');checkPos(!str_contains($r['body'],'Debes abrir caja para realizar una venta')&&!str_contains($r['body'],'id="pos_shelf"'),'Administrador conserva acceso restringido sin aviso vendedor');
$pdo->prepare("INSERT INTO turno(nombre,hora_inicio,hora_fin,estado,id_usuario_creacion) VALUES(?,'08:00:00','23:59:00',1,?)")->execute(['Prueba POS '.bin2hex(random_bytes(4)),$admin['id_usuario']]);$turn=(int)$pdo->lastInsertId();
$r=reqPos('/api/caja/abrir',$dir.'/v1',['shift_id'=>$turn,'cash_counted'=>'0.00','referencia_solicitud'=>'pos_open_'.bin2hex(random_bytes(8))],false,$t1);checkPos($r['code']===201,'Apertura real propia en clon');
$r=reqPos('/Venta',$dir.'/v1');checkPos($r['code']===200&&str_contains($r['body'],'id="pos_shelf"')&&str_contains($r['body'],'id="pos_cart"'),'Caja propia abierta permite POS');
$r=reqPos('/Venta/index',$dir.'/v2');checkPos(str_contains($r['body'],'Debes abrir caja para realizar una venta')&&!str_contains($r['body'],'id="pos_shelf"'),'Caja de otro vendedor no habilita POS');
$r=reqPos('/Auth/logout',$dir.'/v1');loginPos($v[0],$dir.'/v1');$r=reqPos('/Venta',$dir.'/v1');checkPos(str_contains($r['body'],'id="pos_shelf"'),'Reingreso con caja abierta conserva POS');
checkPos($original===DespliegueSprint3::snapshot('amazon_market'),'Base principal sin cambios por las pruebas');
}catch(Throwable $e){checkPos(false,$e->getMessage());}finally{foreach($pipes as $p)if(is_resource($p))fclose($p);proc_terminate($srv);proc_close($srv);}
echo "TOTAL POS: $passed PASS, $failed FAIL".PHP_EOL;exit($failed?1:0);
