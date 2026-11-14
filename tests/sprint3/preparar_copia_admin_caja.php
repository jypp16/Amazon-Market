<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/database/tercerSprint/tools/DespliegueSprint3.php';
$o=getopt('',['target:','dump:']); $db=(string)($o['target']??''); $dump=(string)($o['dump']??'');
if(!preg_match('/^amazon_market_admin_test_[0-9]+$/D',$db)||!is_file($dump))exit(2);
$p=DespliegueSprint3::pdo();
$q=$p->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$q->execute([$db]);if($q->fetchColumn())throw new RuntimeException('La copia ya existe; no se elimina.');
$p->exec('CREATE DATABASE '.$db.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$code=DespliegueSprint3::client('C:/xampp/mysql/bin/mysql.exe',['--default-character-set=utf8mb4','--abort-source-on-error',$db],$dump,sys_get_temp_dir().'/'.$db.'.restore.log');
if($code!==0)exit($code);
echo json_encode(['database'=>$db,'tables'=>count(DespliegueSprint3::snapshot($db)['tables'])]).PHP_EOL;
