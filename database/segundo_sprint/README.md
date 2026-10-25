# Scripts SQL — Segundo Sprint (`database/segundo_sprint/`)

Este directorio contiene exclusivamente los scripts SQL correspondientes al **Sprint 2** (Control de Inventario: Bajas, Ajustes por Conteo, Kardex, Reporte de Variaciones e Integridad de Existencias).

## Reglas de Organización y Seguridad

1. **Separación estricta de responsabilidades**:
   - **Preparación de datos (escenario limpio)**: Scripts separados de las migraciones estructurales. Nunca se oculta una limpieza de registros dentro de una migración de estructura.
   - **Migraciones de estructura (`DDL`)**: Cambios de tablas, columnas, índices, restricciones `CHECK`, claves foráneas y triggers de integridad estructural.
   - **Configuración y catálogos (`DML` de configuración)**: Permisos, roles o subcategorías base requeridos por las reglas del Sprint 2.
   - **Escenarios reproducibles de prueba (`Fixtures`)**: Se ejecutan exclusivamente desde `tests/sprint2/` sobre la base aislada `amazon_market_sprint2_test` y jamás se mezclan con migraciones ni configuración.

2. **Protección de la base actual (`amazon_market`)**:
   - Durante las **Fases 1 a 9**, todos los scripts se desarrollan, ejecutan y verifican **exclusivamente** sobre la copia aislada de pruebas: `amazon_market_sprint2_test`.
   - Cada script incluye una guardia activa (`SIGNAL SQLSTATE '45000'`) que aborta inmediatamente si `DATABASE() <> 'amazon_market_sprint2_test'`.
   - La preparación, archivado y actualización controlada en la base actual (`amazon_market`) corresponde **exclusivamente a la Fase 10**, previo respaldo verificado.

3. **Nota técnica sobre sentencias `DDL` y `COMMIT` implícito en MySQL/MariaDB**:
   - En MariaDB/MySQL, las instrucciones `CREATE TABLE`, `ALTER TABLE`, `DROP TABLE` y `CREATE TRIGGER` producen confirmación implícita (`implicit commit`).
   - Por ello, en `01_estructura_inventario_sprint2.sql` las precondiciones de datos (ausencia de cantidades fraccionarias o negativas) se validan **antes** de iniciar cualquier sentencia `DDL`, y cada alteración está diseñada de forma idempotente (`IF NOT EXISTS` / `DROP CONSTRAINT IF EXISTS`) para permitir reejecución segura.
   - En `02_configuracion_permisos_sprint2.sql`, toda la inserción y vinculación de datos (`DML` de subcategorías predeterminadas y permisos RBAC) se ejecuta primero dentro de una transacción `START TRANSACTION ... COMMIT` real, y recién después se crean los triggers asociados.

## Convención de Numeración y Orden de Ejecución

| N.º | Archivo | Tipo | Fase Asociada | Precondiciones | Base de Destino | Propósito | Estado |
|---|---|---|---|---|---|---|---|
| `00` | `00_preparacion_escenario_limpio_pruebas.sql` | Preparación de datos (Pruebas) | **Fase 1** | Copia restaurada desde el respaldo de `amazon_market` en `amazon_market_sprint2_test`. | `amazon_market_sprint2_test` | Retira operaciones antiguas de prueba (`kardex`, `compra_historial`, `detalle_compra`, `compra`, `detalle_venta`, `venta`) respetando dependencias FK, establece `producto.stock_actual = 0` y conserva intactos todos los catálogos, identificadores y numeraciones. | **Ejecutado (Fase 1)** |
| `01` | `01_estructura_inventario_sprint2.sql` | Estructura (`DDL`) | **Fase 2** | `00_preparacion_escenario_limpio_pruebas.sql` aplicado; 0 productos con stock negativo o fraccionario. | `amazon_market_sprint2_test` | Crea `subcategoria`, convierte `producto.stock_actual` y `stock_minimo` a `INT(11)` con `chk_producto_stock_entero_no_negativo` y FK compuesta `(id_subcategoria, id_categoria)`, agrega versionado a `detalle_compra`, crea `operacion_inventario`, amplía `kardex` con `origen_operacion`, `sentido_movimiento`, FKs `RESTRICT`, columnas snapshot históricas e inmutabilidad por trigger, y crea `control_solicitud_idempotencia`, `reporte_consulta_snapshot` y `reporte_consulta_snapshot_item`. | **Ejecutado (Fase 2)** |
| `02` | `02_configuracion_permisos_sprint2.sql` | Configuración (`DML` + Triggers catálogo) | **Fase 2** | `01_estructura_inventario_sprint2.sql` ejecutado satisfactoriamente. | `amazon_market_sprint2_test` | Crea la subcategoría predeterminada `"General"` por cada categoría existente, vincula los 107 productos a la subcategoría `"General"` de su categoría, registra los permisos `inventario.ajustes`, `reportes.kardex`, `reportes.variaciones` y `reportes.exportar` asignándolos exclusivamente al rol Administrador (`id_rol = 1`), e instala triggers para mantener la correspondencia categoría-subcategoría. | **Ejecutado (Fase 2)** |
| `10` | `10_archivado_y_preparacion_produccion.sql` | Actualización controlada | **Fase 10** | Fases 1 a 9 completadas y verificadas en `amazon_market_sprint2_test`; nuevo respaldo previo de `amazon_market`. | `amazon_market` | *(Reservado exclusivamente para Fase 10)* Archivado de operaciones históricas de prueba, aplicación de estructura/configuración y puesta en marcha del inventario con stock cero en la base principal. | Pendiente (Fase 10) |

## Procedimiento de Ejecución en Entorno de Pruebas

```powershell
# 1. Restaurar respaldo base en la copia de pruebas (únicamente cuando se requiera reiniciar desde el respaldo original)
C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 amazon_market_sprint2_test -e "SOURCE C:/xampp/backups/amazon_market/amazon_market_pre_sprint2_portable.sql"

# 2. Aplicar el escenario operativo limpio en la copia de pruebas (Fase 1)
C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 amazon_market_sprint2_test -e "SOURCE C:/xampp/htdocs/Amazon-Market/database/segundo_sprint/00_preparacion_escenario_limpio_pruebas.sql"

# 3. Aplicar la migración estructural del Sprint 2 (Fase 2)
C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 amazon_market_sprint2_test -e "SOURCE C:/xampp/htdocs/Amazon-Market/database/segundo_sprint/01_estructura_inventario_sprint2.sql"

# 4. Aplicar la configuración de subcategorías y permisos del Sprint 2 (Fase 2)
C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 amazon_market_sprint2_test -e "SOURCE C:/xampp/htdocs/Amazon-Market/database/segundo_sprint/02_configuracion_permisos_sprint2.sql"
```
