<?php
/** Regresión aislada: la limpieza de inventario debe conservar snapshots de caja.
 * Ejecutar: C:\xampp\php\php.exe tests/sprint3/limpieza_snapshots_inventario_test.php
 * Usa exclusivamente SQLite en memoria; no carga Config ni conecta a MariaDB.
 */
require_once __DIR__ . '/../../Libraries/Core/Conexion.php';
require_once __DIR__ . '/../../Libraries/Core/Model.php';
require_once __DIR__ . '/../../Models/KardexModel.php';

// Adapta únicamente las funciones de fecha MySQL; el SQL y las FK se ejecutan.
class SnapshotMemoryPDO extends PDO {
    private function fechas(string $sql): string {
        return str_replace(
            ['DATE_SUB(NOW(), INTERVAL 1 DAY)', 'DATE_ADD(NOW(), INTERVAL 30 MINUTE)', 'NOW()'],
            ["datetime('now', '-1 day')", "datetime('now', '+30 minutes')", "datetime('now')"],
            $sql
        );
    }
    public function exec(string $statement): int|false {
        return parent::exec($this->fechas($statement));
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare($this->fechas($query), $options);
    }
}

class KardexSnapshotMemoryModel extends Models\KardexModel {
    public function __construct(PDO $db) { $this->conect = $db; }
}

function comprobar(bool $condicion, string $mensaje): void {
    if (!$condicion) throw new RuntimeException($mensaje);
    echo "[PASS] $mensaje\n";
}

$fallos = 0;
foreach (['kardex', 'variaciones'] as $tipo) {
    try {
        $db = new SnapshotMemoryPDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('CREATE TABLE reporte_consulta_snapshot (
            id_snapshot INTEGER PRIMARY KEY AUTOINCREMENT, referencia_consulta TEXT,
            id_usuario INTEGER, tipo_reporte TEXT, hash_filtros TEXT, filtros_json TEXT,
            resumen_json TEXT, total_registros INTEGER, exportable INTEGER,
            creado_en TEXT, expira_en TEXT
        )');
        $db->exec('CREATE TABLE reporte_consulta_snapshot_item (
            id_snapshot INTEGER, orden_posicion INTEGER, id_kardex INTEGER,
            FOREIGN KEY (id_snapshot) REFERENCES reporte_consulta_snapshot ON DELETE CASCADE
        )');
        $db->exec('CREATE TABLE reporte_caja_snapshot_item (
            id_snapshot INTEGER, proyeccion_json TEXT,
            FOREIGN KEY (id_snapshot) REFERENCES reporte_consulta_snapshot ON DELETE RESTRICT
        )');
        // Caja vencida protegida, inventario vencido y consultas aún vigentes.
        $db->exec("INSERT INTO reporte_consulta_snapshot (id_snapshot, tipo_reporte, expira_en) VALUES
            (1, 'cierres_caja', datetime('now', '-3 days')),
            (2, 'kardex', datetime('now', '-3 days')),
            (3, 'variaciones', datetime('now', '-3 days')),
            (4, 'kardex', datetime('now', '+1 day')),
            (5, 'variaciones', datetime('now', '-1 hour')),
            (6, 'otro_reporte', datetime('now', '-3 days'))");
        $db->exec("INSERT INTO reporte_caja_snapshot_item VALUES (1, '{\"total\":125}')");
        $db->exec('INSERT INTO reporte_consulta_snapshot_item VALUES (2, 1, 10), (3, 1, 11), (4, 1, 12)');

        $model = new KardexSnapshotMemoryModel($db);
        $resultado = $model->guardarSnapshotConsulta('prueba-' . $tipo, 1, $tipo, 'hash', [], [], 1, true, [42]);
        comprobar($resultado['id_snapshot'] > 6, "$tipo: crea la nueva consulta con caja vencida presente");
        comprobar((int)$db->query('SELECT COUNT(*) FROM reporte_consulta_snapshot WHERE id_snapshot = 1')->fetchColumn() === 1,
            "$tipo: conserva la cabecera protegida de caja");
        comprobar($db->query('SELECT proyeccion_json FROM reporte_caja_snapshot_item WHERE id_snapshot = 1')->fetchColumn() === '{"total":125}',
            "$tipo: conserva intacto el detalle de caja");
        comprobar((int)$db->query('SELECT COUNT(*) FROM reporte_consulta_snapshot WHERE id_snapshot IN (2, 3)')->fetchColumn() === 0,
            "$tipo: limpia ambas consultas de inventario vencidas");
        comprobar((int)$db->query('SELECT COUNT(*) FROM reporte_consulta_snapshot_item WHERE id_snapshot IN (2, 3)')->fetchColumn() === 0,
            "$tipo: limpia sus detalles mediante la cascada existente");
        comprobar((int)$db->query('SELECT COUNT(*) FROM reporte_consulta_snapshot WHERE id_snapshot IN (4, 5, 6)')->fetchColumn() === 3,
            "$tipo: respeta consultas recientes y otros reportes");
        comprobar((int)$db->query('SELECT id_kardex FROM reporte_consulta_snapshot_item WHERE id_snapshot = ' . (int)$resultado['id_snapshot'])->fetchColumn() === 42,
            "$tipo: guarda los movimientos de la nueva consulta");
    } catch (Throwable $error) {
        $fallos++;
        echo "[FAIL] $tipo: " . $error->getMessage() . "\n";
    }
}
exit($fallos > 0 ? 1 : 0);
