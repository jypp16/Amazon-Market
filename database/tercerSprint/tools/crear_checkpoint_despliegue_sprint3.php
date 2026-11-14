<?php
declare(strict_types=1);
require_once __DIR__.'/DespliegueSprint3.php';
try{
$o=getopt('',['backup-dir:','confirm-source:']);if(($o['confirm-source']??'')!=='amazon_market')throw new RuntimeException('Confirme la fuente local.');$lock=DespliegueSprint3::lock();
$dir=str_replace('\\','/',(string)($o['backup-dir']??''));if(!is_dir($dir))throw new RuntimeException('Carpeta externa existente requerida.');$dir=str_replace('\\','/',realpath($dir));if(str_starts_with(strtolower($dir).'/',strtolower(DespliegueSprint3::root()).'/'))throw new RuntimeException('No guardar respaldos en el webroot.');
$p=DespliegueSprint3::pdo('amazon_market');$state=$p->query('SELECT estado FROM sprint3_migracion_control WHERE id_migracion=3')->fetchColumn();if($state!=='COMPLETADA')throw new RuntimeException('El Sprint 3 no está completo.');$p=null;
$before=DespliegueSprint3::snapshot('amazon_market');if(count($before['tables'])!==43)throw new RuntimeException('Se esperaban 43 tablas.');$id=date('Ymd_His').'_'.bin2hex(random_bytes(4));$path=$dir.'/amazon_market_post_sprint3_'.$id.'.sql';
$code=DespliegueSprint3::client('C:/xampp/mysql/bin/mysqldump.exe',['--single-transaction','--quick','--routines','--events','--triggers','--hex-blob','--skip-add-drop-table','--default-character-set=utf8mb4','--no-tablespaces','--result-file='.$path,'amazon_market'],null,$path.'.dump.log');if($code!==0)throw new RuntimeException('Falló el respaldo posterior.');
if($before!==DespliegueSprint3::snapshot('amazon_market'))throw new RuntimeException('La fuente cambió durante el respaldo: conservar archivo pero no darlo por verificado.');
$target='am_s3_checkpoint_'.$id;$p=DespliegueSprint3::pdo();$p->exec('CREATE DATABASE '.$target.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$code=DespliegueSprint3::client('C:/xampp/mysql/bin/mysql.exe',['--default-character-set=utf8mb4','--abort-source-on-error',$target],$path,$path.'.restore.log');if($code!==0)throw new RuntimeException('Restauración posterior falló; conservar copia.');
$preserved=DespliegueSprint3::preserved($before,DespliegueSprint3::snapshot($target),false);
$report=['state'=>'POST_DEPLOY_RESTORE_VERIFIED','source'=>'amazon_market','backup'=>$path,'sha256'=>hash_file('sha256',$path),'restored_database'=>$target,'preservation'=>$preserved,'at'=>date(DATE_ATOM)];$r=$path.'.json';DespliegueSprint3::json($r,$report);echo json_encode(['status'=>true,'report'=>$r,'sha256'=>$report['sha256'],'preservation'=>$preserved],JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'ABORTADO: '.$e->getMessage().PHP_EOL);exit(1);}
