<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
date_default_timezone_set('America/Lima');
require_once dirname(__DIR__,3).'/Libraries/Core/Dotenv.php';
\Dotenv::load(dirname(__DIR__,3).'/.env');
final class DespliegueSprint3 {
    public static function root():string { return str_replace('\\','/',dirname(__DIR__,3)); }
    public static function pdo(?string $db=null):PDO {
        $host=getenv('DB_HOST')?:'127.0.0.1';
        if(!in_array(strtolower($host),['localhost','127.0.0.1'],true))throw new RuntimeException('Solo XAMPP local.');
        $p=new PDO('mysql:host=127.0.0.1;port=3306;'.($db!==null?'dbname='.$db.';':'').'charset=utf8mb4',getenv('DB_USER')?:'root',getenv('DB_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $p->exec("SET SESSION time_zone='-05:00'");return $p;
    }
    public static function name(string $db):void {if(!preg_match('/^[A-Za-z0-9_]+$/D',$db))throw new RuntimeException('Nombre inválido.');}
    public static function snapshot(string $db,?array $columns=null):array {
        self::name($db);$p=self::pdo($db);$p->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $out=[];$tables=$columns!==null?array_keys($columns):$p->query('SHOW FULL TABLES WHERE Table_type=\'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN);
            sort($tables);
            foreach($tables as $table){
                self::name($table);$cols=$columns[$table]??$p->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_COLUMN);
                // Identificadores provienen exclusivamente de information_schema/manifest verificado.
                $q='SELECT '.implode(',',array_map(static fn($x)=>chr(96).str_replace(chr(96),chr(96).chr(96),$x).chr(96),$cols)).' FROM '.chr(96).$table.chr(96);
                $rows=$p->query($q);$hashes=[];while($row=$rows->fetch(PDO::FETCH_NUM)){$h=hash('sha256',json_encode(array_map(static fn($v)=>$v===null?null:base64_encode((string)$v),$row),JSON_THROW_ON_ERROR));$hashes[$h]=($hashes[$h]??0)+1;}ksort($hashes);
                $out[$table]=['columns'=>$cols,'count'=>array_sum($hashes),'rows'=>$hashes];
            }
            $triggers=$p->query('SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->fetchAll();
            return ['tables'=>$out,'triggers'=>$triggers];
        } finally {if($p->inTransaction())$p->rollBack();}
    }
    public static function columns(array $snap):array {return array_map(static fn($t)=>$t['columns'],$snap['tables']);}
    public static function preserved(array $before,array $after,bool $migrated):array {
        $errors=[];foreach($before['tables'] as $t=>$b){$a=$after['tables'][$t]??null;if($a===null){$errors[]='Falta tabla '.$t;continue;}
            foreach($b['rows'] as $h=>$n)if(($a['rows'][$h]??0)<$n)$errors[]='Fila original alterada en '.$t;
            $allowed=$migrated&&in_array($t,['permiso','rol_permiso'],true);
            if(!$allowed&&$a['count']!==$b['count'])$errors[]='Conteo alterado en '.$t;
        }
        $triggerIndex=[];foreach($after['triggers'] as $tr)$triggerIndex[$tr['TRIGGER_NAME']]=$tr;
        foreach($before['triggers'] as $tr)if(($triggerIndex[$tr['TRIGGER_NAME']]??null)!==$tr)$errors[]='Trigger previo alterado: '.$tr['TRIGGER_NAME'];
        if($errors)throw new RuntimeException(implode('; ',array_unique($errors)));
        return ['original_tables_preserved'=>count($before['tables']),'original_triggers_preserved'=>count($before['triggers'])];
    }
    public static function json(string $path,array $data):void {if(file_put_contents($path,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL,LOCK_EX)===false)throw new RuntimeException('No se pudo guardar evidencia.');}
    public static function client(string $exe,array $args,?string $input,string $log):int {
        $f=tempnam(sys_get_temp_dir(),'am15_client_');
        $esc=static fn($v)=>'"'.str_replace(['\\','"',"\r","\n"],['\\\\','\\"','','\\n'],(string)$v).'"';
        file_put_contents($f,"[client]\nhost=127.0.0.1\nport=3306\nuser=".$esc(getenv('DB_USER')?:'root')."\npassword=".$esc(getenv('DB_PASS')?:'')."\n");@chmod($f,0600);
        try{$stderrFile=tempnam(dirname($log),'stderr15_');$spec=[0=>$input!==null?['file',$input,'r']:['pipe','r'],1=>['file',$log,'a'],2=>['file',$stderrFile,'w']];$cmd=array_merge([$exe,'--defaults-extra-file='.$f],$args);$proc=proc_open($cmd,$spec,$pipes);if(!is_resource($proc))throw new RuntimeException('No se pudo iniciar el cliente.');if($input===null&&isset($pipes[0]))fclose($pipes[0]);$exitCode=proc_close($proc);$stderr=(string)file_get_contents($stderrFile);if($stderr!=='')file_put_contents($log,"\n[STDERR]\n".$stderr,FILE_APPEND);@unlink($stderrFile);return $exitCode;}finally{@unlink($f);}
    }
    public static function load(string $path):array {if(!is_file($path)||!is_readable($path))throw new RuntimeException('Evidencia requerida no disponible.');$d=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);if(!is_array($d))throw new RuntimeException('Manifest inválido.');return $d;}
    public static function manifest(string $path):array {
        $m=self::load($path);if(($m['source']??'')!=='amazon_market'||($m['state']??'')!=='BACKUP_VERIFIED')throw new RuntimeException('Manifest no verificado.');
        if(!is_file($m['backup'])||!hash_equals($m['sha256'],hash_file('sha256',$m['backup'])))throw new RuntimeException('El respaldo cambió.');
        if(!hash_equals($m['env_sha256'],hash_file('sha256',self::root().'/.env')))throw new RuntimeException('.env cambió.');
        return $m;
    }
    public static function lock(){ $p=self::root().'/.codex/sprint3-deploy-runner.lock';$h=fopen($p,'c');if(!$h||!flock($h,LOCK_EX|LOCK_NB))throw new RuntimeException('Otro despliegue está en curso.');return $h; }
    /** Espera conexiones previas; nunca interrumpe ni mata operaciones del negocio. */
    public static function drain(string $db='amazon_market'):array {
        self::name($db);$p=self::pdo();$started=microtime(true);$quiet=null;
        do {
            $n=(int)$p->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=".$p->quote($db)." AND ID<>CONNECTION_ID()")->fetchColumn();
            if($n===0){$quiet??=microtime(true);if(microtime(true)-$quiet>=5)return ['connections'=>0,'quiet_seconds'=>5,'wait_seconds'=>round(microtime(true)-$started,2)];}else{$quiet=null;}
            usleep(200000);
        }while(microtime(true)-$started<60);
        throw new RuntimeException('No se pudo drenar la base en 60 segundos. Mantener mantenimiento y revisar conexiones; no matar operaciones.');
    }

    public static function schema(string $db):array {
        $p=self::pdo($db);$n=(int)$p->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE=\'BASE TABLE\'')->fetchColumn();$tr=(int)$p->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn();
        $s=$p->query('SELECT estado,paso_actual FROM sprint3_migracion_control WHERE id_migracion=3')->fetch();
        $historical=(int)$p->query('SELECT COUNT(*) FROM venta WHERE id_sesion_caja IS NOT NULL')->fetchColumn();
        $ref=$p->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='movimiento_caja' AND COLUMN_NAME='referencia_visible'")->fetchColumn();
        $check=$p->query("SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='chk_correccion_importes_y_motivo'")->fetchColumn();
        $sale=$p->query("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='trg_movimiento_caja_before_insert_integridad'")->fetchColumn();
        if($n!==43||$tr!==34||($s['estado']??'')!=='COMPLETADA'||$historical!==0||(int)$ref!==500||!preg_match('/monto_vigente_previo[^>]*>=\\s*0/',(string)$check)||!str_contains((string)$sale,'v_venta_estado <> 1'))throw new RuntimeException('Esquema final o conservación histórica incorrectos.');
        return ['tables'=>$n,'triggers'=>$tr,'migration'=>$s,'historical_sales_reassigned'=>$historical,'reference_width'=>(int)$ref];
    }
}
