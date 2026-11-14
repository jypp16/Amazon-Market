<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/database/tercerSprint/tools/DespliegueSprint3.php';
$o=getopt('',['manifest:','phase:']);$phase=(string)($o['phase']??'');$checks=[];
function ensure15(bool $ok,string $label):void {global $checks;$checks[$label]=$ok;if(!$ok)throw new RuntimeException($label);}
try {
$m=DespliegueSprint3::manifest((string)($o['manifest']??''));
if(!in_array($phase,['maintenance','ready','deployed'],true))throw new RuntimeException('Indique --phase=maintenance|ready|deployed.');
$current=DespliegueSprint3::snapshot('amazon_market',DespliegueSprint3::columns($m['baseline']));
$comparison=$current;
// Tras activar, la aplicación puede añadir solicitudes y snapshots legítimos.
// Se exige conservar cada fila anterior y solo se permite crecimiento en estas tablas técnicas.
if($phase==='deployed')foreach(['control_solicitud_idempotencia','reporte_consulta_snapshot'] as $t){
    if($current['tables'][$t]['count']<$m['baseline']['tables'][$t]['count'])throw new RuntimeException('Filas anteriores eliminadas en '.$t);
    $comparison['tables'][$t]['count']=$m['baseline']['tables'][$t]['count'];
}
$proof=DespliegueSprint3::preserved($m['baseline'],$comparison,$phase!=='maintenance');ensure15($proof['original_tables_preserved']===32,'32 tablas originales conservadas');
if($phase!=='maintenance'){
$schema=DespliegueSprint3::schema('amazon_market');ensure15($schema['tables']===43&&$schema['triggers']===34,'esquema y triggers del Sprint 3');
$p=DespliegueSprint3::pdo('amazon_market');
if($phase==='ready')foreach(['turno','programacion_semanal','sesion_caja','movimiento_caja','arqueo_caja','correccion_movimiento_caja','autorizacion_caja','reporte_caja_snapshot_item'] as $table)ensure15((int)$p->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'sin datos de ensayo en '.$table);
ensure15((int)$p->query('SELECT COUNT(*) FROM caja_fisica_guardia WHERE id_caja_fisica=1 AND id_sesion_abierta_actual IS NULL')->fetchColumn()===1,'única caja inicial libre');
foreach([1=>['turnos.programar','turnos.consultar_historico','reportes.cierres_caja','reportes.exportar'],2=>['turnos.abrir','turnos.cerrar','turnos.acceder','turnos.movimientos','turnos.arqueo']] as $role=>$slugs)foreach($slugs as $slug){$q=$p->prepare('SELECT COUNT(*) FROM permiso pe JOIN rol_permiso rp ON rp.id_permiso=pe.id_permiso WHERE pe.slug=? AND pe.estado=1 AND rp.id_rol=?');$q->execute([$slug,$role]);ensure15((int)$q->fetchColumn()>0,'permiso rol '.$role.' '.$slug);}
$ready=0;foreach($p->query('SELECT email,password_hash FROM usuario WHERE id_rol=1 AND estado=1') as $u)if(filter_var($u['email'],FILTER_VALIDATE_EMAIL)&&strlen((string)$u['password_hash'])>=30)$ready++;ensure15($ready>0,'administrador con correo y contraseña configurados');
}
$base='http://localhost/Amazon-Market';
$paths=$phase!=='deployed'?['/Auth'=>503,'/api/caja/estado'=>503]:['/Auth'=>200,'/api/caja/estado'=>401,'/api/reportes/cierres-caja'=>401,'/Assets/js/api.js'=>200,'/.env'=>403,'/.git/config'=>403,'/.codex/tmp/paso14/php-conf.d/session.ini'=>403,'/Config/Config.php'=>403,'/Libraries/Core/Conexion.php'=>403,'/database/tercerSprint/README.md'=>403,'/tests/sprint3/paso14_generadores_test.php'=>403];
foreach($paths as $path=>$expected){$ch=curl_init($base.$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);ensure15($status===$expected,'HTTP '.$expected.' '.$path);if($phase!=='deployed'&&str_contains($path,'/api/'))ensure15((json_decode((string)$body,true)['code']??'')==='MAINTENANCE','mantenimiento API JSON');}
echo json_encode(['status'=>true,'phase'=>$phase,'checks'=>count($checks),'results'=>$checks,'preservation'=>$proof,'schema'=>$schema??null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){echo json_encode(['status'=>false,'phase'=>$phase,'checks'=>$checks,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE).PHP_EOL;exit(1);}
