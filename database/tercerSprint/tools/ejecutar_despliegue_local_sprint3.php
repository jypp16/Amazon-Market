<?php
declare(strict_types=1);
require_once __DIR__.'/DespliegueSprint3.php';
try {
$o=getopt('',['mode:','manifest:','target:','confirm-target:','rehearsal-report:','recovery-report:','failure-report:']);
$mode=(string)($o['mode']??'');$target=(string)($o['target']??'');$mPath=(string)($o['manifest']??'');
if(!in_array($mode,['rehearse','restore','simulate-failure','maintenance','apply','activate'],true))throw new RuntimeException('Modo inválido.');
DespliegueSprint3::name($target);$lock=DespliegueSprint3::lock();$m=DespliegueSprint3::manifest($mPath);$mHash=hash_file('sha256',$mPath);
$dir=str_replace('\\','/',dirname(realpath($mPath)));$root=DespliegueSprint3::root();
$pkg=DespliegueSprint3::load($root.'/database/tercerSprint/DESPLIEGUE_LOCAL_PAQUETE.json');
foreach($pkg['sourceScripts'] as $file=>$sha){if(!hash_equals($sha,hash_file('sha256',$root.'/database/tercerSprint/'.$file)))throw new RuntimeException('Cambió script histórico '.$file);}
$sqlPath=$root.'/database/tercerSprint/'.$pkg['releaseSQL'];if(!hash_equals($pkg['main_sql_sha256'],hash_file('sha256',$sqlPath)))throw new RuntimeException('Cambió el paquete SQL.');
$preflightPath=$root.'/database/tercerSprint/'.$pkg['preflightSQL'];
if(!hash_equals($pkg['preflight_sha256'],hash_file('sha256',$preflightPath)) || !hash_equals($pkg['sha256'],hash('sha256',$pkg['preflight_sha256']."\n".$pkg['main_sql_sha256'])))throw new RuntimeException('Cambió el preflight de contexto.');
$marker=$root.'/.codex/sprint3-maintenance.lock';$reportPath=$dir.'/'.$target.'.'.$mode.'.json';
if(in_array($mode,['maintenance','apply','activate'],true)){
    if($target!=='amazon_market'||($o['confirm-target']??'')!==$target)throw new RuntimeException('Confirme el destino local exacto.');
    if($mode!=='activate' && time()-(int)$m['created_epoch']>7200)throw new RuntimeException('Respaldo mayor a dos horas: preparar y ensayar uno nuevo.');
}
if(in_array($mode,['maintenance','apply'],true)){
    foreach(['rehearsal-report'=>['VERIFIED','rehearse'],'recovery-report'=>['VERIFIED','restore'],'failure-report'=>['EXPECTED_FAILURE_VERIFIED','simulate-failure']] as $key=>[$state,$expectedMode]){
        $proof=DespliegueSprint3::load((string)($o[$key]??''));
        if(($proof['state']??'')!==$state||($proof['mode']??'')!==$expectedMode||($proof['manifest_sha256']??'')!==$mHash||($proof['package_sha256']??'')!==$pkg['sha256'])throw new RuntimeException('Ensayo/recuperación no verificados para este respaldo.');
    }
    $now=DespliegueSprint3::snapshot('amazon_market',DespliegueSprint3::columns($m['baseline']));
    DespliegueSprint3::preserved($m['baseline'],$now,false);
}
if($mode==='maintenance'){
    if(is_file($marker))throw new RuntimeException('Ya existe mantenimiento. No reemplazar su propietario.');
    DespliegueSprint3::json($marker,['manifest_sha256'=>$mHash,'target'=>$target,'started_at'=>date(DATE_ATOM)]);
    echo json_encode(['status'=>true,'mode'=>$mode,'target'=>$target,'marker'=>$marker]).PHP_EOL;exit;
}
if($mode==='activate'){
    $markerData=DespliegueSprint3::load($marker);$applied=DespliegueSprint3::load($dir.'/'.$target.'.apply.json');
    if(($markerData['manifest_sha256']??'')!==$mHash||($applied['state']??'')!=='VERIFIED'||($applied['manifest_sha256']??'')!==$mHash||($applied['package_sha256']??'')!==$pkg['sha256'])throw new RuntimeException('No hay despliegue verificado para activar.');
    DespliegueSprint3::schema($target);DespliegueSprint3::preserved($m['baseline'],DespliegueSprint3::snapshot($target,DespliegueSprint3::columns($m['baseline'])),true);
    if(!unlink($marker))throw new RuntimeException('No se pudo finalizar mantenimiento.');
    DespliegueSprint3::json($reportPath,['state'=>'ACTIVATED','target'=>$target,'manifest_sha256'=>$mHash,'at'=>date(DATE_ATOM)]);echo json_encode(['status'=>true,'state'=>'ACTIVATED','report'=>$reportPath]).PHP_EOL;exit;
}
$pdo=DespliegueSprint3::pdo();
if(in_array($mode,['rehearse','restore','simulate-failure'],true)){
    if(!preg_match('/^amazon_market_sprint3_deploy_[A-Za-z0-9_]+$/D',$target))throw new RuntimeException('Los ensayos requieren base NUEVA con prefijo amazon_market_sprint3_deploy_.');
    $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$s->execute([$target]);if((int)$s->fetchColumn()!==0)throw new RuntimeException('La base ya existe: no se borra ni reutiliza.');
    $sourceCollation=$pdo->query("SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='amazon_market'")->fetchColumn();
    if(!in_array($sourceCollation,['utf8mb4_general_ci','utf8mb4_unicode_ci'],true))throw new RuntimeException('Contexto de origen no soportado.');
    $pdo->exec('CREATE DATABASE '.$target.' CHARACTER SET utf8mb4 COLLATE '.$sourceCollation);
    $restoreLog=$dir.'/'.$target.'.restore.log';$code=DespliegueSprint3::client('C:/xampp/mysql/bin/mysql.exe',['--default-character-set=utf8mb4','--abort-source-on-error',$target],$m['backup'],$restoreLog);if($code!==0)throw new RuntimeException('Restauración fallida; conservar la copia.');
    $restored=DespliegueSprint3::snapshot($target);DespliegueSprint3::preserved($m['baseline'],$restored,false);if(count($restored['tables'])!==32)throw new RuntimeException('Restauración de estructura incompleta.');
}else{
    $markerData=DespliegueSprint3::load($marker);if(($markerData['manifest_sha256']??'')!==$mHash)throw new RuntimeException('Mantenimiento no corresponde a este respaldo.');
    $drain=DespliegueSprint3::drain();
    DespliegueSprint3::preserved($m['baseline'],DespliegueSprint3::snapshot('amazon_market',DespliegueSprint3::columns($m['baseline'])),false);
}
$report=['source_default_collation'=>$sourceCollation??null,'drain'=>$drain??null,'mode'=>$mode,'target'=>$target,'manifest_sha256'=>$mHash,'package_sha256'=>$pkg['sha256'],'at'=>date(DATE_ATOM)];
if($mode==='restore'){
    $report['state']='VERIFIED';$report['preservation']=DespliegueSprint3::preserved($m['baseline'],$restored,false);DespliegueSprint3::json($reportPath,$report);echo json_encode(['status'=>true,'report'=>$reportPath,'state'=>$report['state']]).PHP_EOL;exit;
}
$contextSQL=file_get_contents($preflightPath);
if(substr_count($contextSQL,'__SPRINT3_DESTINO__')!==2)throw new RuntimeException('Plantilla de contexto no reconocida.');
$sql=str_replace('__SPRINT3_DESTINO__',$target,$contextSQL)."\n".file_get_contents($sqlPath);
if($mode==='simulate-failure'){
    $needle='-- STEP15_AFTER_02';$pos=strpos($sql,$needle);if($pos===false)throw new RuntimeException('No se puede ubicar la barrera de fallo.');
    $sql=substr($sql,0,$pos)."\nSIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PASO15_FALLO_CONTROLADO_DESPUES_02';\n";
}
$p=DespliegueSprint3::pdo($target);$quote=static fn($v)=>$p->quote((string)$v);
$driver="SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\nSET @SPRINT3_DESPLIEGUE_LOCAL_AUTORIZADO='amazon_market';\nSET @SPRINT3_BASE_CONFIRMADA=".$quote($target).";\nSET @SPRINT3_VENTANA_MANTENIMIENTO=1;\nSET @SPRINT3_RESPALDO_ARCHIVO=".$quote($m['backup']).";\nSET @SPRINT3_RESPALDO_SHA256=".$quote($m['sha256']).";\n".$sql;
$driverFile=$dir.'/'.$target.'.'.$mode.'.driver.sql';file_put_contents($driverFile,$driver);$log=$dir.'/'.$target.'.'.$mode.'.log';
$report['state']='STARTED';DespliegueSprint3::json($reportPath,$report);
$code=DespliegueSprint3::client('C:/xampp/mysql/bin/mysql.exe',['--default-character-set=utf8mb4','--abort-source-on-error','--batch','--raw',$target],$driverFile,$log);$report['exit_code']=$code;
if($mode==='simulate-failure'){
    if($code===0||!str_contains((string)file_get_contents($log),'PASO15_FALLO_CONTROLADO_DESPUES_02'))throw new RuntimeException('No se verificó el fallo esperado.');
    $report['preservation']=DespliegueSprint3::preserved($m['baseline'],DespliegueSprint3::snapshot($target,DespliegueSprint3::columns($m['baseline'])),false);$report['state']='EXPECTED_FAILURE_VERIFIED';
}else{
    if($code!==0)throw new RuntimeException('Migración parcial: mantener mantenimiento, conservar evidencia y usar recuperación ensayada; no reejecutar.');
    $report['schema']=DespliegueSprint3::schema($target);$report['preservation']=DespliegueSprint3::preserved($m['baseline'],DespliegueSprint3::snapshot($target,DespliegueSprint3::columns($m['baseline'])),true);$report['state']='VERIFIED';
}
DespliegueSprint3::json($reportPath,$report);echo json_encode(['status'=>true,'state'=>$report['state'],'report'=>$reportPath,'target'=>$target],JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'ABORTADO: '.$e->getMessage().PHP_EOL);exit(1);}
