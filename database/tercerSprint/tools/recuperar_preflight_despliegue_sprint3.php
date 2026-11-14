<?php
declare(strict_types=1);
require_once __DIR__.'/DespliegueSprint3.php';
try{
$o=getopt('',['manifest:','target:','confirm-target:']);$target=(string)($o['target']??'');
if(($o['confirm-target']??'')!==$target||($target!=='amazon_market'&&!preg_match('/^amazon_market_sprint3_deploy_rehearsal_[0-9]+$/D',$target)))throw new RuntimeException('Destino no autorizado para esta recuperación acotada.');
$lock=DespliegueSprint3::lock();$mPath=(string)($o['manifest']??'');$m=DespliegueSprint3::manifest($mPath);$mHash=hash_file('sha256',$mPath);
if($target==='amazon_market'){$marker=DespliegueSprint3::load(DespliegueSprint3::root().'/.codex/sprint3-maintenance.lock');if(($marker['manifest_sha256']??'')!==$mHash)throw new RuntimeException('Mantenimiento ajeno o no activo.');}
DespliegueSprint3::drain($target);
$before=DespliegueSprint3::snapshot($target);DespliegueSprint3::preserved($m['baseline'],$before,false);
$expected=array_keys($m['baseline']['tables']);$expected[]='sprint3_migracion_control';sort($expected);$actual=array_keys($before['tables']);sort($actual);
if($actual!==$expected)throw new RuntimeException('No es el único objeto parcial esperado. No continuar.');
foreach($m['baseline']['tables'] as $table=>$b)if($before['tables'][$table]['columns']!==$b['columns'])throw new RuntimeException('Columnas previas modificadas.');
$p=DespliegueSprint3::pdo($target);$rows=$p->query('SELECT * FROM sprint3_migracion_control')->fetchAll();
if(count($rows)!==1||$rows[0]['estado']!=='EN_PROGRESO'||$rows[0]['paso_actual']!=='00_INICIANDO'||$rows[0]['base_datos']!==$target||strtolower($rows[0]['respaldo_sha256'])!==$m['sha256'])throw new RuntimeException('El control no corresponde al fallo acotado de preflight.');
if((int)$p->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE (TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sprint3_migracion_control' AND REFERENCED_TABLE_NAME IS NOT NULL) OR (REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='sprint3_migracion_control')")->fetchColumn()!==0)throw new RuntimeException('Control con relaciones no esperadas.');
if((int)$p->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='sprint3_migracion_control'")->fetchColumn()!==0)throw new RuntimeException('Control con triggers no esperados.');
$dir=str_replace('\\','/',dirname(realpath($mPath)));$stamp=date('Ymd_His').'_'.bin2hex(random_bytes(4));$partial=$dir.'/'.$target.'.partial_'.$stamp.'.sql';
$code=DespliegueSprint3::client('C:/xampp/mysql/bin/mysqldump.exe',['--single-transaction','--quick','--triggers','--hex-blob','--skip-add-drop-table','--default-character-set=utf8mb4','--no-tablespaces','--result-file='.$partial,$target],null,$partial.'.log');if($code!==0||!is_file($partial)||filesize($partial)<1000)throw new RuntimeException('No se verificó respaldo del estado parcial.');
$archive='am_s3_preflight_archive_'.$stamp;
$p->exec('CREATE DATABASE '.$archive.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
// Única tabla nueva, sin FKs/triggers: se conserva fuera de las 32 originales, no se borra.
$p->exec('RENAME TABLE '.$target.'.sprint3_migracion_control TO '.$archive.'.sprint3_migracion_control');
$after=DespliegueSprint3::snapshot($target);$preserved=DespliegueSprint3::preserved($m['baseline'],$after,false);
if(count($after['tables'])!==32)throw new RuntimeException('La fuente no regresó al esquema previo.');
$archived=DespliegueSprint3::pdo($archive)->query('SELECT * FROM sprint3_migracion_control')->fetchAll();if($archived!==$rows)throw new RuntimeException('El control archivado no coincide.');
$report=['state'=>'PREFLIGHT_RECOVERED','target'=>$target,'archive_database'=>$archive,'partial_backup'=>$partial,'partial_backup_sha256'=>hash_file('sha256',$partial),'manifest_sha256'=>$mHash,'preservation'=>$preserved,'control_rows_preserved'=>count($rows),'at'=>date(DATE_ATOM)];$rPath=$dir.'/'.$target.'.preflight_recovery_'.$stamp.'.json';DespliegueSprint3::json($rPath,$report);echo json_encode(['status'=>true,'report'=>$rPath,'archive'=>$archive,'preservation'=>$preserved],JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'ABORTADO: '.$e->getMessage().PHP_EOL);exit(1);}
