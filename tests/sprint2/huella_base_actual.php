<?php
/** Solo lectura: huella del esquema y contenido de la base habitual. No muestra datos. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../../Libraries/Core/Dotenv.php';
Dotenv::load(__DIR__ . '/../../.env');
$host = getenv('DB_HOST') ?: 'localhost';
if (!in_array($host, ['localhost', '127.0.0.1'], true)) throw new RuntimeException('Solo se admite el servidor local.');
$db = new PDO('mysql:host=' . $host . ';dbname=amazon_market;charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('SET TRANSACTION READ ONLY');
$db->beginTransaction();
$fingerprint = [];
foreach ($db->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $quoted = '`' . str_replace('`', '``', $table) . '`';
    $schema = $db->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM)[1];
    $rows = $db->query('SELECT * FROM ' . $quoted)->fetchAll(PDO::FETCH_ASSOC);
    $hashes = array_map(fn($row) => hash('sha256', json_encode($row)), $rows);
    sort($hashes);
    $fingerprint[$table] = ['filas' => count($rows), 'sha256' => hash('sha256', $schema . implode('', $hashes))];
}
ksort($fingerprint);
$db->rollBack();
echo json_encode($fingerprint, JSON_PRETTY_PRINT) . PHP_EOL;
