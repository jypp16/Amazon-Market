<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/database/tercerSprint/tools/DespliegueSprint3.php';
$o=getopt('',['active']);$p=DespliegueSprint3::pdo('amazon_market');$report=DespliegueSprint3::load('C:/xampp/backups/Amazon-Market/Sprint3/Paso15/amazon_market.admin12.json');
$before=$report['before'];$after=DespliegueSprint3::snapshot('amazon_market',DespliegueSprint3::columns($before));$before['triggers']=array_values(array_filter($before['triggers'],fn($t)=>!in_array($t['TRIGGER_NAME'],['trg_sesion_caja_before_insert_integridad','trg_sesion_caja_before_update_integridad'],true)));$checks=[];
$checks['filas previas y triggers no afectados']=DespliegueSprint3::preserved($before,$after,true);
$checks['sesiones anteriores con turno']=(int)$p->query("SELECT COUNT(*) FROM sesion_caja WHERE modalidad<>'CON_TURNO'")->fetchColumn()===0;
$checks['sin cambios .env']=hash_file('sha256',DespliegueSprint3::root().'/.env')===$report['env_sha256'];
$checks['43 tablas']=count(DespliegueSprint3::snapshot('amazon_market')['tables'])===43;
$checks['34 triggers']=(int)$p->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn()===34;
foreach(['turnos.abrir','turnos.movimientos','turnos.arqueo','turnos.cerrar','turnos.acceder','ventas.acceder','ventas.procesar'] as $slug){$q=$p->prepare('SELECT COUNT(*) FROM rol_permiso rp JOIN permiso pe ON pe.id_permiso=rp.id_permiso WHERE rp.id_rol=1 AND pe.slug=? AND pe.estado=1');$q->execute([$slug]);$checks['permiso admin '.$slug]=(int)$q->fetchColumn()===1;}
require_once dirname(__DIR__,2).'/Config/Config.php';require_once dirname(__DIR__,2).'/Libraries/Core/Autoload.php';$admin=(int)$p->query('SELECT id_usuario FROM usuario WHERE id_rol=1 AND estado=1 LIMIT 1')->fetchColumn();$svc=new Services\CajaService($p);$checks['API service modo sin horario']=$svc->consultarTurnoHoy($admin,[])['data']['mode']==='SIN_HORARIO';
$paths=isset($o['active'])?['/Auth'=>200,'/api/caja/estado'=>401,'/.env'=>403,'/database/tercerSprint/12_caja_administrador_sin_horario.sql'=>403]:['/Auth'=>503,'/api/caja/estado'=>503];foreach($paths as $path=>$expected){$c=curl_init('http://localhost/Amazon-Market'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);curl_exec($c);$checks['HTTP '.$expected.' '.$path]=(int)curl_getinfo($c,CURLINFO_HTTP_CODE)===$expected;curl_close($c);}
$ok=!in_array(false,$checks,true);$out=['status'=>$ok,'phase'=>isset($o['active'])?'active':'ready','checks'=>$checks];echo json_encode($out,JSON_UNESCAPED_UNICODE).PHP_EOL;exit($ok?0:1);
