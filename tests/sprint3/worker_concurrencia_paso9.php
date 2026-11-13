<?php
declare(strict_types=1);

/**
 * Worker CLI para simular un cierre concurrente de sesión de caja en MariaDB
 * manteniendo un bloqueo `FOR UPDATE` sobre `sesion_caja` durante unos milisegundos
 * mientras una petición HTTP `POST /api/ventas` compite por la misma sesión.
 */

$dbName = getenv('TEST_DB_NAME') ?: '';
if ($dbName === '') {
    fwrite(STDERR, "TEST_DB_NAME requerido\n");
    exit(2);
}

$idSesion = (int)($argv[1] ?? 0);
$holdMs = (int)($argv[2] ?? 1200);
$lockSignalFile = $argv[3] ?? '';

if ($idSesion <= 0 || $lockSignalFile === '') {
    fwrite(STDERR, "Uso: php worker_concurrencia_paso9.php <id_sesion> <hold_ms> <signal_file>\n");
    exit(2);
}

$rootDir = dirname(__DIR__, 2);
putenv("DB_NAME={$dbName}");
$_ENV['DB_NAME'] = $dbName;
$_SERVER['DB_NAME'] = $dbName;

require_once $rootDir . '/Libraries/Core/Dotenv.php';
\Dotenv::load($rootDir . '/.env');
require_once $rootDir . '/Config/Config.php';

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, $dbName, DB_CHARSET);
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id_sesion_caja, id_usuario_vendedor, version_caja, estado, efectivo_esperado_actual FROM sesion_caja WHERE id_sesion_caja = :id FOR UPDATE');
    $stmt->execute([':id' => $idSesion]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException("Sesión {$idSesion} no encontrada");
    }

    // Señalar al proceso padre que el bloqueo FOR UPDATE está adquirido
    file_put_contents($lockSignalFile, 'LOCKED');

    usleep($holdMs * 1000);

    $pdo->prepare("UPDATE arqueo_caja SET estado = 'REEMPLAZADO', vigencia_activa = NULL, causa_cambio_estado = 'NUEVO_ARQUEO', fecha_hora_cambio_estado = NOW() WHERE id_sesion_caja = :id AND estado = 'VIGENTE'")
        ->execute([':id' => $idSesion]);

    $stmtArq = $pdo->prepare(
        "INSERT INTO arqueo_caja (id_sesion_caja, id_usuario_vendedor, version_caja_evaluada, monto_esperado, monto_contado, diferencia, observacion, estado, vigencia_activa)
         VALUES (:id_sesion, :id_vend, :ver, :esp, :esp, 0.00, NULL, 'VIGENTE', 1)"
    );
    $stmtArq->execute([
        ':id_sesion' => $idSesion,
        ':id_vend' => (int)$row['id_usuario_vendedor'],
        ':ver' => (int)$row['version_caja'],
        ':esp' => $row['efectivo_esperado_actual'],
    ]);
    $idArqueo = (int)$pdo->lastInsertId();

    $pdo->prepare('UPDATE sesion_caja SET id_ultimo_arqueo = :arq WHERE id_sesion_caja = :id')
        ->execute([':arq' => $idArqueo, ':id' => $idSesion]);

    $stmtAnt = $pdo->prepare(
        "SELECT (NOW() < DATE_SUB(fecha_hora_salida_referencia, INTERVAL 15 MINUTE)) AS es_anticipado,
                TIME(fecha_hora_salida_referencia) AS hora_salida_referencia,
                fecha_hora_salida_referencia,
                GREATEST(16, CEIL(TIMESTAMPDIFF(SECOND, NOW(), fecha_hora_salida_referencia) / 60)) AS mins
           FROM sesion_caja
          WHERE id_sesion_caja = :id"
    );
    $stmtAnt->execute([':id' => $idSesion]);
    $ant = $stmtAnt->fetch();
    if ($ant && (int)$ant['es_anticipado'] === 1) {
        $idAdmin = (int)$pdo->query("SELECT id_usuario FROM usuario WHERE id_rol = 1 AND estado = 1 ORDER BY id_usuario ASC LIMIT 1")->fetchColumn();
        if ($idAdmin > 0) {
            $pdo->prepare(
                "INSERT INTO autorizacion_caja (
                    id_sesion_caja, tipo_operacion, id_usuario_vendedor, id_usuario_admin,
                    motivo, hora_salida_referencia, fecha_hora_salida_referencia,
                    hora_cierre_real, minutos_anticipacion
                 ) VALUES (
                    :s, 'CIERRE_ANTICIPADO', :v, :a,
                    'Cierre worker concurrencia paso 9', :hs, :fhs,
                    NOW(), :m
                 )"
            )->execute([
                ':s'   => $idSesion,
                ':v'   => (int)$row['id_usuario_vendedor'],
                ':a'   => $idAdmin,
                ':hs'  => $ant['hora_salida_referencia'],
                ':fhs' => $ant['fecha_hora_salida_referencia'],
                ':m'   => (int)$ant['mins'],
            ]);
        }
    }

    $stmtClose = $pdo->prepare(
        "UPDATE sesion_caja
            SET id_arqueo_cierre = :arq,
                estado = 'CERRADA',
                efectivo_esperado_cierre = :efectivo,
                efectivo_contado_cierre = :efectivo,
                diferencia_cierre = 0.00,
                fecha_hora_cierre = NOW(),
                version_caja = version_caja + 1
          WHERE id_sesion_caja = :id"
    );
    $stmtClose->execute([
        ':arq' => $idArqueo,
        ':efectivo' => $row['efectivo_esperado_actual'],
        ':id' => $idSesion,
    ]);
    $pdo->commit();
    echo "CLOSED_OK\n";
    exit(0);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'WORKER_ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
