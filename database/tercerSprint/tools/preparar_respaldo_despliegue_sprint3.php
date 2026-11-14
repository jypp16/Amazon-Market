<?php
declare(strict_types=1);
require_once __DIR__.'/DespliegueSprint3.php';
try {
    $options=getopt('',['backup-dir:','confirm-source:']);
    if(($options['confirm-source']??'')!=='amazon_market')throw new RuntimeException('Confirme --confirm-source=amazon_market.');
    $lock=DespliegueSprint3::lock();$dir=str_replace('\\','/',(string)($options['backup-dir']??''));
    if(!preg_match('~^[A-Za-z]:/~',$dir)||str_contains($dir,'..'))throw new RuntimeException('Indique carpeta absoluta de respaldo.');
    if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('No se pudo crear la carpeta externa.');
    $dir=str_replace('\\','/',(string)realpath($dir));if(str_starts_with(strtolower($dir).'/',strtolower(DespliegueSprint3::root()).'/'))throw new RuntimeException('El respaldo debe estar fuera del webroot del proyecto.');
    $id='amazon_market_pre_sprint3_paso15_'.date('Ymd_His').'_'.bin2hex(random_bytes(4));$path=$dir.'/'.$id.'.sql';
    $before=DespliegueSprint3::snapshot('amazon_market');if(count($before['tables'])!==32)throw new RuntimeException('Se esperaba la base previa sin migrar de 32 tablas.');
    $log=$dir.'/'.$id.'.dump.log';$code=DespliegueSprint3::client('C:/xampp/mysql/bin/mysqldump.exe',['--single-transaction','--quick','--routines','--events','--triggers','--hex-blob','--skip-add-drop-table','--default-character-set=utf8mb4','--no-tablespaces','--result-file='.$path,'amazon_market'],null,$log);
    if($code!==0||!is_file($path)||filesize($path)<1000)throw new RuntimeException('El respaldo falló. Consulte el log externo.');
    $after=DespliegueSprint3::snapshot('amazon_market');if($before!==$after)throw new RuntimeException('La fuente cambió durante el respaldo: no activar esta entrega.');
    $manifest=['version'=>1,'state'=>'BACKUP_VERIFIED','source'=>'amazon_market','created_at'=>date(DATE_ATOM),'created_epoch'=>time(),'backup'=>$path,'sha256'=>hash_file('sha256',$path),'env_sha256'=>hash_file('sha256',DespliegueSprint3::root().'/.env'),'baseline'=>$before];
    $mPath=$dir.'/'.$id.'.manifest.json';DespliegueSprint3::json($mPath,$manifest);echo json_encode(['status'=>true,'manifest'=>$mPath,'backup'=>$path,'sha256'=>$manifest['sha256'],'tables'=>count($before['tables'])],JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch(Throwable $e){fwrite(STDERR,'ABORTADO: '.$e->getMessage().PHP_EOL);exit(1);}
