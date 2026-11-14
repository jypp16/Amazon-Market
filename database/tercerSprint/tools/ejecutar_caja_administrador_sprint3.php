<?php
declare(strict_types=1);
require_once __DIR__.'/DespliegueSprint3.php';
try {
 $o=getopt('',['db:','confirm-db:','backup-report:','activate']);$db=(string)($o['db']??'');
 $live=$db==='amazon_market';if(!$live&&!preg_match('/^amazon_market_(?:admin_test_|sprint3_deploy_rehearsal_)[0-9]+$/D',$db))throw new RuntimeException('Solo original autorizado o copia administrativa de ensayo.');
 if($live&&($o['confirm-db']??'')!==$db)throw new RuntimeException('Confirme destino local.');
 $lock=DespliegueSprint3::lock();$root=DespliegueSprint3::root();$marker=$root.'/.codex/sprint3-maintenance.lock';
 $sqlPath=dirname(__DIR__).'/12_caja_administrador_sin_horario.sql';$sqlSha=hash_file('sha256',$sqlPath);
 $reportDir=$live?'C:/xampp/backups/Amazon-Market/Sprint3/Paso15':sys_get_temp_dir();$reportPath=$reportDir.'/'.$db.'.admin12.json';
 if(isset($o['activate'])){
  $r=DespliegueSprint3::load($reportPath);$m=DespliegueSprint3::load($marker);
  if(!$live||($r['state']??'')!=='VERIFIED'||($m['migration']??'')!=='ADMIN12'||$r['sql_sha256']!==$sqlSha||$m['sql_sha256']!==$sqlSha)throw new RuntimeException('No hay despliegue propio verificado.');
  if(hash_file('sha256',$root.'/.env')!==$r['env_sha256'])throw new RuntimeException('.env cambió.');
  if(!unlink($marker))throw new RuntimeException('No se pudo activar.');$r['activated_at']=date(DATE_ATOM);DespliegueSprint3::json($reportPath,$r);echo 'ACTIVATED'.PHP_EOL;exit;
 }
 $p=DespliegueSprint3::pdo($db);$exists=$p->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sesion_caja' AND COLUMN_NAME='modalidad'")->fetchColumn();if($exists)throw new RuntimeException('Migración aplicada; no repetir.');
 $before=DespliegueSprint3::snapshot($db);$dump=null;
 if($live){
  $backup=DespliegueSprint3::load((string)($o['backup-report']??''));
  if(($backup['state']??'')!=='POST_DEPLOY_RESTORE_VERIFIED'||!is_file($backup['backup'])||hash_file('sha256',$backup['backup'])!==$backup['sha256'])throw new RuntimeException('Respaldo restaurado requerido.');
  if(time()-filemtime($backup['backup'])>7200)throw new RuntimeException('Respaldo demasiado antiguo.');
  if(DespliegueSprint3::snapshot($backup['restored_database'])!==$before)throw new RuntimeException('La fuente cambió desde el respaldo.');
  if(is_file($marker))throw new RuntimeException('Ya hay mantenimiento; no reemplazarlo.');
  DespliegueSprint3::json($marker,['migration'=>'ADMIN12','sql_sha256'=>$sqlSha,'started_at'=>date(DATE_ATOM)]);
  $p=null; // No contar la conexión propia como actividad pendiente.
  DespliegueSprint3::drain($db);
  if($before!==DespliegueSprint3::snapshot($db))throw new RuntimeException('Cambios durante drenaje; mantener mantenimiento.');
 }
 $p=DespliegueSprint3::pdo($db);
 $driver=$reportDir.'/'.$db.'.admin12.driver.sql';$log=$reportDir.'/'.$db.'.admin12.log';
 file_put_contents($driver,"SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\nSET @CAJA_ADMIN_MIGRACION_AUTORIZADA=".$p->quote($db).";\n".file_get_contents($sqlPath));
 $code=DespliegueSprint3::client('C:/xampp/mysql/bin/mysql.exe',['--default-character-set=utf8mb4','--abort-source-on-error',$db],$driver,$log);
 if($code!==0)throw new RuntimeException('DDL parcial; conservar evidencia y mantenimiento, no reejecutar: '.$log);
 $after=DespliegueSprint3::snapshot($db,DespliegueSprint3::columns($before));
 $checkBefore=$before;$checkBefore['triggers']=array_values(array_filter($before['triggers'],static fn($t)=>!in_array($t['TRIGGER_NAME'],['trg_sesion_caja_before_insert_integridad','trg_sesion_caja_before_update_integridad'],true)));
 $preserved=DespliegueSprint3::preserved($checkBefore,$after,true);
 if((int)$p->query("SELECT COUNT(*) FROM sesion_caja WHERE modalidad<>'CON_TURNO'")->fetchColumn()!==0)throw new RuntimeException('Sesión anterior alterada.');
 $r=['state'=>'VERIFIED','database'=>$db,'sql_sha256'=>$sqlSha,'env_sha256'=>hash_file('sha256',$root.'/.env'),'preserved'=>$preserved,'before'=>$before,'at'=>date(DATE_ATOM)];DespliegueSprint3::json($reportPath,$r);echo json_encode(['state'=>'VERIFIED','report'=>$reportPath,'preserved'=>$preserved]).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
