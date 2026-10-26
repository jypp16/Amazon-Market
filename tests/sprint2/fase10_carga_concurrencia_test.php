<?php
/**
 * Fase 10: carga de 1 000 movimientos, sesiones simultáneas y consistencia.
 * Ejecutar solo después de iniciar tests/sprint2/servidor_pruebas.ps1.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo CLI.\n");
}

ob_start();
require_once __DIR__ . '/bootstrap_test.php';

const F10_HTTP_BASE = 'http://127.0.0.1:8092';
const F10_MOVIMIENTOS_BASE = 1000;
const F10_SESIONES = 5;
const F10_MOVIMIENTOS_CONCURRENTES_POR_SESION = 10;

function f10Worker(int $idProducto, string $token, int $worker): never
{
    $stock = 0;
    $service = new Services\InventarioService();
    for ($i = 1; $i <= F10_MOVIMIENTOS_CONCURRENTES_POR_SESION; $i++) {
        $referencia = sprintf('f10-w-%s-%02d-%02d', $token, $worker, $i);
        $service->registrarAjuste([
            'id_producto' => $idProducto,
            'tipo_ajuste' => 'Ajuste positivo',
            'cantidad' => 1,
            'saldo_anterior_esperado' => $stock,
            'justificacion' => 'Carga concurrente de verificación Fase 10',
            'referencia_solicitud' => $referencia,
        ], $referencia, 1);
        $stock++;
    }
    echo "worker={$worker}; movimientos={$stock}\n";
    exit(0);
}

if (($argv[1] ?? '') === '--worker') {
    try {
        f10Worker((int)($argv[2] ?? 0), (string)($argv[3] ?? ''), (int)($argv[4] ?? 0));
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
        exit(1);
    }
}

$passed = 0;
$failed = 0;
$products = [];
$temporaryUsers = [];
$sessions = [];
$snapshots = [];
$workers = [];
$token = bin2hex(random_bytes(6));
$sessionDir = sys_get_temp_dir() . '/amazon-market-sprint2-sessions';
$filters = [];

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0770, true);
}
session_save_path($sessionDir);

function f10Check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $name . ($detail !== '' ? " ({$detail})" : '') . PHP_EOL;
}

function f10NewProduct(string $label): int
{
    $product = (new Services\ProductoService())->crear([
        'codigo_barra' => 'TST-F10-' . strtoupper(bin2hex(random_bytes(4))),
        'nombre' => 'Carga Fase 10 ' . $label . ' ' . bin2hex(random_bytes(2)),
        'id_categoria' => 1,
        'id_subcategoria' => 1,
        'id_unidad' => 1,
        'precio_venta' => 2.50,
        'stock_minimo' => 0,
    ]);
    return (int)$product['id_producto'];
}

function f10NewAdminUser(string $token, int $number): int
{
    global $pdoTest;
    $username = 'F' . strtoupper(substr($token, 0, 8)) . $number;
    $exists = $pdoTest->prepare('SELECT 1 FROM usuario WHERE username = ?');
    $exists->execute([$username]);
    if ($exists->fetchColumn()) {
        throw new RuntimeException('La cuenta temporal de carga ya existe; se aborta para no reutilizar usuarios.');
    }
    $dni = sprintf('%08d', (hexdec(substr($token, 0, 7)) + $number) % 100000000);
    $telefono = sprintf('%09d', 900000000 + $number);
    $insert = $pdoTest->prepare('INSERT INTO usuario (id_rol, username, password_hash, nombre, dni, telefono, direccion, email, estado) VALUES (1, ?, ?, ?, ?, ?, ?, ?, 1)');
    $insert->execute([
        $username,
        password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'Usuario temporal Fase 10 ' . $number,
        $dni,
        $telefono,
        'Cuenta temporal de concurrencia',
        strtolower($username) . '@example.test',
    ]);
    return (int)$pdoTest->lastInsertId();
}

function f10Session(string $directory, int $idUsuario): array
{
    if (!is_dir($directory)) {
        mkdir($directory, 0770, true);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_id('');
    session_start();
    $_SESSION = [
        'login' => true,
        'idUser' => $idUsuario,
        'rol' => 1,
        'csrf_token' => bin2hex(random_bytes(32)),
    ];
    $session = [
        'cookie' => session_name() . '=' . session_id(),
        'csrf' => $_SESSION['csrf_token'],
    ];
    session_write_close();
    return $session;
}

/** @return array<int,array{status:int,content_type:string,body:string,milliseconds:float,error:string}> */
function f10ParallelHttp(array $requests): array
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($requests as $index => $request) {
        $handle = curl_init(F10_HTTP_BASE . $request['path']);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Accept: ' . ($request['accept'] ?? 'application/json'),
                'Cookie: ' . $request['session']['cookie'],
            ],
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[$index] = $handle;
    }

    $running = null;
    do {
        $code = curl_multi_exec($multi, $running);
        if ($code > CURLM_OK) {
            break;
        }
        if ($running > 0 && curl_multi_select($multi, 0.2) === -1) {
            usleep(1000);
        }
    } while ($running > 0);

    $results = [];
    foreach ($handles as $index => $handle) {
        $results[$index] = [
            'status' => (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'content_type' => (string)curl_getinfo($handle, CURLINFO_CONTENT_TYPE),
            'body' => (string)curl_multi_getcontent($handle),
            'milliseconds' => ((float)curl_getinfo($handle, CURLINFO_TOTAL_TIME)) * 1000,
            'error' => curl_error($handle),
        ];
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);
    ksort($results);
    return $results;
}

function f10WorkbookDetailRows(string $body): int
{
    $path = tempnam(sys_get_temp_dir(), 'f10-xlsx-');
    if ($path === false) {
        return -1;
    }
    file_put_contents($path, $body);
    $zip = new ZipArchive();
    $valid = $zip->open($path) === true;
    $rows = -1;
    if ($valid) {
        $sheet = $zip->getFromName('xl/worksheets/sheet2.xml') ?: '';
        $rows = substr_count($sheet, '<row');
        $zip->close();
    }
    unlink($path);
    return $rows;
}

function f10WaitWorkers(array &$workers): array
{
    $failures = [];
    foreach ($workers as $worker => &$entry) {
        if (!is_resource($entry['process'])) {
            continue;
        }
        $stdout = stream_get_contents($entry['pipes'][1]);
        $stderr = stream_get_contents($entry['pipes'][2]);
        fclose($entry['pipes'][1]);
        fclose($entry['pipes'][2]);
        $exit = proc_close($entry['process']);
        if ($exit !== 0) {
            $failures[] = "worker {$worker}: " . trim((string)$stderr . ' ' . (string)$stdout);
        }
        $entry['exit'] = $exit;
        $entry['process'] = null;
    }
    unset($entry);
    return $failures;
}

try {
    if (DB_NAME !== 'amazon_market_sprint2_test') {
        throw new RuntimeException('Guardia F10: el ejecutor solo acepta amazon_market_sprint2_test.');
    }
    if (!extension_loaded('curl') || !class_exists(ZipArchive::class)) {
        throw new RuntimeException('Faltan extensiones requeridas: curl y zip.');
    }
    $today = (string)$pdoTest->query('SELECT CURRENT_DATE()')->fetchColumn();
    $movimientosPrevios = (int)$pdoTest->query('SELECT COUNT(*) FROM kardex WHERE DATE(fecha_movimiento) = CURRENT_DATE()')->fetchColumn();
    if ($movimientosPrevios !== 0) {
        throw new RuntimeException("La BD de pruebas ya tiene {$movimientosPrevios} movimientos de hoy; no se mezclará la carga F10.");
    }
    $filters = ['fecha_inicio' => $today, 'fecha_fin' => $today, 'limit' => 100];

    $products[] = f10NewProduct('principal');
    for ($worker = 1; $worker <= F10_SESIONES; $worker++) {
        $products[] = f10NewProduct('usuario-' . $worker);
    }
    $mainProductId = $products[0];

    $stock = 0;
    $service = new Services\InventarioService();
    $seedStart = hrtime(true);
    for ($i = 1; $i <= F10_MOVIMIENTOS_BASE; $i++) {
        $reference = sprintf('f10-s-%s-%04d', $token, $i);
        $service->registrarAjuste([
            'id_producto' => $mainProductId,
            'tipo_ajuste' => 'Ajuste positivo',
            'cantidad' => 1,
            'saldo_anterior_esperado' => $stock,
            'justificacion' => 'Carga de rendimiento de 1000 movimientos Fase 10',
            'referencia_solicitud' => $reference,
        ], $reference, 1);
        $stock++;
        if (($i % 250) === 0) {
            echo "Preparados {$i}/" . F10_MOVIMIENTOS_BASE . " movimientos base\n";
        }
    }
    $seedMilliseconds = (hrtime(true) - $seedStart) / 1_000_000;
    $seedCount = (int)$pdoTest->query('SELECT COUNT(*) FROM kardex WHERE id_producto = ' . $mainProductId)->fetchColumn();
    $seedStock = (int)$pdoTest->query('SELECT stock_actual FROM producto WHERE id_producto = ' . $mainProductId)->fetchColumn();
    f10Check('Se generan 1 000 movimientos canónicos por InventarioService', $seedCount === F10_MOVIMIENTOS_BASE && $seedStock === F10_MOVIMIENTOS_BASE, "filas={$seedCount}; stock={$seedStock}; carga=" . round($seedMilliseconds, 1) . ' ms');

    for ($i = 1; $i <= F10_SESIONES; $i++) {
        $temporaryUsers[] = f10NewAdminUser($token, $i);
        $sessions[] = f10Session($sessionDir, $temporaryUsers[$i - 1]);
    }
    $queryRequests = [];
    foreach ($sessions as $index => $session) {
        foreach (['kardex', 'variaciones'] as $report) {
            $queryRequests[] = [
                'session_index' => $index,
                'report' => $report,
                'session' => $session,
                'path' => '/api/reportes/' . $report . '?' . http_build_query($filters),
            ];
        }
    }

    for ($worker = 1; $worker <= F10_SESIONES; $worker++) {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $command = [PHP_BINARY, __FILE__, '--worker', (string)$products[$worker], $token, (string)$worker];
        $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 2));
        if (!is_resource($process)) {
            throw new RuntimeException("No se pudo iniciar el escritor concurrente {$worker}.");
        }
        fclose($pipes[0]);
        $workers[$worker] = ['process' => $process, 'pipes' => $pipes];
    }

    $queryStart = hrtime(true);
    $queryResults = f10ParallelHttp($queryRequests);
    $queryWallMilliseconds = (hrtime(true) - $queryStart) / 1_000_000;
    $referencesBySession = [];
    $allQueryTimingsOk = true;
    $allQueryCountsOk = true;
    foreach ($queryResults as $index => $response) {
        $request = $queryRequests[$index];
        $json = json_decode($response['body'], true);
        $data = $json['data'] ?? [];
        $reference = (string)($data['referencia_consulta'] ?? '');
        $total = (int)($data['pagination']['total'] ?? -1);
        if ($reference !== '') {
            $referencesBySession[$request['session_index']][$request['report']] = $reference;
            $snapshots[] = $reference;
        }
        $allQueryTimingsOk = $allQueryTimingsOk && $response['milliseconds'] <= 2000;
        $allQueryCountsOk = $allQueryCountsOk && $response['status'] === 200 && $total >= F10_MOVIMIENTOS_BASE && $total <= (F10_MOVIMIENTOS_BASE + F10_SESIONES * F10_MOVIMIENTOS_CONCURRENTES_POR_SESION);
        echo sprintf("Consulta %02d %s: HTTP %d, %.1f ms, total=%d\n", $index + 1, $request['report'], $response['status'], $response['milliseconds'], $total);
    }
    f10Check('Cinco sesiones consultan Kardex y Variaciones en paralelo', count($queryResults) === F10_SESIONES * 2 && count($referencesBySession) === F10_SESIONES && $allQueryCountsOk, '10 consultas HTTP; cada instantánea contiene entre 1000 y 1050 filas');
    f10Check('Consultas paginadas cumplen el umbral de 2 segundos', $allQueryTimingsOk, 'pared total=' . round($queryWallMilliseconds, 1) . ' ms; máximo individual=' . round(max(array_column($queryResults, 'milliseconds')), 1) . ' ms');

    $workerFailures = f10WaitWorkers($workers);
    f10Check('Cinco escritores paralelos completan 50 operaciones con referencias únicas', count($workerFailures) === 0, count($workerFailures) ? implode('; ', $workerFailures) : '50 operaciones; 10 por producto');
    if ($workerFailures) {
        foreach ($workerFailures as $failure) {
            echo '[INFO] ' . $failure . PHP_EOL;
        }
    }

    $expectedTotal = F10_MOVIMIENTOS_BASE + F10_SESIONES * F10_MOVIMIENTOS_CONCURRENTES_POR_SESION;
    $totalMovimientos = (int)$pdoTest->query('SELECT COUNT(*) FROM kardex WHERE id_producto IN (' . implode(',', array_map('intval', $products)) . ')')->fetchColumn();
    $badBalances = (int)$pdoTest->query('SELECT COUNT(*) FROM producto p JOIN (SELECT k.id_producto,k.stock_resultante FROM kardex k JOIN (SELECT id_producto,MAX(id_kardex) max_id FROM kardex GROUP BY id_producto) x ON x.max_id=k.id_kardex) u ON u.id_producto=p.id_producto WHERE p.id_producto IN (' . implode(',', array_map('intval', $products)) . ') AND p.stock_actual <> u.stock_resultante')->fetchColumn();
    f10Check('Las 1 050 operaciones guardadas dejan stock y último saldo de Kardex iguales', $totalMovimientos === $expectedTotal && $badBalances === 0, "movimientos={$totalMovimientos}; discrepancias={$badBalances}");

    $exports = [];
    $exportSpecs = [
        [0, 'kardex', 'pdf'],
        [1, 'kardex', 'excel'],
        [2, 'variaciones', 'pdf'],
        [3, 'variaciones', 'excel'],
        [4, 'kardex', 'pdf'],
    ];
    $exportRequests = [];
    foreach ($exportSpecs as [$sessionIndex, $report, $format]) {
        $reference = $referencesBySession[$sessionIndex][$report] ?? '';
        $filtersAndReference = $filters + ['referencia_consulta' => $reference];
        $query = $filtersAndReference + ['tipo' => $report];
        $exportRequests[] = [
            'session_index' => $sessionIndex,
            'report' => $report,
            'format' => $format,
            'total' => (int)(json_decode($queryResults[$sessionIndex * 2 + ($report === 'variaciones' ? 1 : 0)]['body'], true)['data']['pagination']['total'] ?? 0),
            'session' => $sessions[$sessionIndex],
            'path' => '/api/reportes/exportar-' . ($format === 'excel' ? 'excel' : 'pdf') . '?' . http_build_query($query),
            'accept' => $format === 'excel'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/json'
                : 'application/pdf, application/json',
        ];
    }
    $exportStart = hrtime(true);
    $exportResults = f10ParallelHttp($exportRequests);
    $exportWallMilliseconds = (hrtime(true) - $exportStart) / 1_000_000;
    $allExportTimingsOk = true;
    $allExportFilesOk = true;
    foreach ($exportResults as $index => $response) {
        $request = $exportRequests[$index];
        $signatureOk = $request['format'] === 'excel'
            ? str_starts_with($response['body'], "PK")
            : str_starts_with($response['body'], '%PDF');
        $rowsOk = $request['format'] !== 'excel' || f10WorkbookDetailRows($response['body']) === ($request['total'] + 1);
        $allExportTimingsOk = $allExportTimingsOk && $response['milliseconds'] <= 5000;
        $allExportFilesOk = $allExportFilesOk && $response['status'] === 200 && $signatureOk && $rowsOk;
        echo sprintf("Exportación %s %s sesión %d: HTTP %d, %.1f ms, bytes=%d, filas_xlsx=%s\n", strtoupper($request['report']), strtoupper($request['format']), $request['session_index'] + 1, $response['status'], $response['milliseconds'], strlen($response['body']), $request['format'] === 'excel' ? (string)f10WorkbookDetailRows($response['body']) : 'n/a');
    }
    f10Check('Cinco sesiones descargan archivos de Kardex/Variaciones con 1 000+ filas', count($exportResults) === F10_SESIONES && $allExportFilesOk, 'cinco descargas concurrentes; firmas PDF/XLSX y detalle XLSX completo');
    f10Check('Exportaciones PDF y Excel cumplen el umbral de 5 segundos', $allExportTimingsOk, 'pared total=' . round($exportWallMilliseconds, 1) . ' ms; máximo individual=' . round(max(array_column($exportResults, 'milliseconds')), 1) . ' ms');

    $snapshotIntegrity = true;
    foreach ($snapshots as $reference) {
        $query = $pdoTest->prepare('SELECT s.id_snapshot, s.total_registros, (SELECT COUNT(*) FROM reporte_consulta_snapshot_item i WHERE i.id_snapshot = s.id_snapshot) AS items FROM reporte_consulta_snapshot s WHERE s.referencia_consulta = ?');
        $query->execute([$reference]);
        $snapshot = $query->fetch(PDO::FETCH_ASSOC);
        $snapshotIntegrity = $snapshotIntegrity && $snapshot && (int)$snapshot['total_registros'] === (int)$snapshot['items'] && (int)$snapshot['items'] >= F10_MOVIMIENTOS_BASE;
    }
    f10Check('Cada referencia_consulta conserva una instantánea cerrada y consistente', $snapshotIntegrity && count($snapshots) === F10_SESIONES * 2, '10 referencias y número de elementos igual al total declarado');
} catch (Throwable $error) {
    f10Check('Ejecución Fase 10 sin excepción inesperada', false, $error->getMessage());
} finally {
    if ($workers) {
        foreach ($workers as $entry) {
            if (is_resource($entry['process'])) {
                $status = proc_get_status($entry['process']);
                if ($status['running']) {
                    proc_terminate($entry['process']);
                }
            }
        }
        f10WaitWorkers($workers);
    }
    if ($products) {
        $ids = implode(',', array_map('intval', $products));
        $pdoTest->beginTransaction();
        try {
            foreach (array_unique($snapshots) as $reference) {
                $find = $pdoTest->prepare('SELECT id_snapshot FROM reporte_consulta_snapshot WHERE referencia_consulta = ?');
                $find->execute([$reference]);
                $idSnapshot = $find->fetchColumn();
                if ($idSnapshot) {
                    $deleteItems = $pdoTest->prepare('DELETE FROM reporte_consulta_snapshot_item WHERE id_snapshot = ?');
                    $deleteItems->execute([(int)$idSnapshot]);
                    $deleteSnapshot = $pdoTest->prepare('DELETE FROM reporte_consulta_snapshot WHERE id_snapshot = ?');
                    $deleteSnapshot->execute([(int)$idSnapshot]);
                }
            }
            $pdoTest->exec('DELETE FROM kardex WHERE id_producto IN (' . $ids . ')');
            $pdoTest->exec('DELETE FROM operacion_inventario WHERE id_producto IN (' . $ids . ')');
            $deleteRequests = $pdoTest->prepare("DELETE FROM control_solicitud_idempotencia WHERE referencia_solicitud LIKE ?");
            $deleteRequests->execute(['f10-%-' . $token . '-%']);
            $pdoTest->exec('DELETE FROM producto WHERE id_producto IN (' . $ids . ')');
            if ($temporaryUsers) {
                $userMarks = implode(',', array_fill(0, count($temporaryUsers), '?'));
                $deleteUsers = $pdoTest->prepare('DELETE FROM usuario WHERE id_usuario IN (' . $userMarks . ')');
                $deleteUsers->execute(array_map('intval', $temporaryUsers));
            }
            $pdoTest->commit();
        } catch (Throwable $cleanupError) {
            if ($pdoTest->inTransaction()) {
                $pdoTest->rollBack();
            }
            $failed++;
            echo '[FAIL] Limpieza de datos de carga F10: ' . $cleanupError->getMessage() . PHP_EOL;
        }
    }
    foreach ($sessions as $session) {
        $sessionId = substr($session['cookie'], strpos($session['cookie'], '=') + 1);
        $sessionFile = $sessionDir . DIRECTORY_SEPARATOR . 'sess_' . $sessionId;
        if (is_file($sessionFile)) {
            unlink($sessionFile);
        }
    }
}

echo "Fase 10 carga/concurrencia: {$passed} PASS / {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
