# Scripts SQL — Segundo Sprint (`database/segundo_sprint/`)

Este directorio contiene exclusivamente los scripts SQL correspondientes al **Sprint 2** (Control de Inventario: Bajas, Ajustes por Conteo, Kardex, Reporte de Variaciones e Integridad de Existencias).

## Reglas de Organización y Seguridad

1. **Separación estricta de responsabilidades**:
   - **Preparación de datos (escenario limpio)**: Scripts separados de las migraciones estructurales. Nunca se oculta una limpieza de registros dentro de una migración de estructura.
   - **Migraciones de estructura (`DDL`)**: Cambios de tablas, columnas, índices y claves foráneas.
   - **Configuración y catálogos (`DML` de configuración)**: Permisos, roles o catálogos base requeridos por las reglas del Sprint 2.
   - **Escenarios reproducibles de prueba (`Fixtures`)**: Se ejecutan exclusivamente desde `tests/sprint2/` sobre la base aislada `amazon_market_sprint2_test` y jamás se mezclan con migraciones ni configuración.

2. **Protección de la base actual (`amazon_market`)**:
   - Durante las **Fases 1 a 9**, todos los scripts se desarrollan, ejecutan y verifican **exclusivamente** sobre la copia aislada de pruebas: `amazon_market_sprint2_test`.
   - La preparación, archivado y actualización controlada en la base actual (`amazon_market`) corresponde **exclusivamente a la Fase 10**, previo respaldo verificado.

## Convención de Numeración y Orden de Ejecución

| N.º | Archivo | Tipo | Fase Asociada | Base de Destino | Propósito | Estado |
|---|---|---|---|---|---|---|
| `00` | `00_preparacion_escenario_limpio_pruebas.sql` | Preparación de datos (Pruebas) | **Fase 1** (Preparación y contratos) | `amazon_market_sprint2_test` | Retira operaciones antiguas de prueba (`kardex`, `compra_historial`, `detalle_compra`, `compra`, `detalle_venta`, `venta`) respetando dependencias FK, establece `producto.stock_actual = 0` y conserva intactos todos los catálogos, identificadores y numeraciones. Incluye guardia activa que aborta si se intenta ejecutar fuera de `amazon_market_sprint2_test`. | Ejecutado en Fase 1 |
| `01` | `01_estructura_inventario_sprint2.sql` | Estructura (`DDL`) | **Fase 2** (Datos, seguridad y base común de movimientos) | `amazon_market_sprint2_test` | *(Reservado para Fase 2)* Adecuación de tablas de subcategorías, operaciones de baja y ajuste, snapshots históricos en `kardex`, control de solicitudes repetidas (`referencia_solicitud`), almacenamiento de consistencia de consulta (`referencia_consulta`) y enteros de stock. | Pendiente (Fase 2) |
| `02` | `02_configuracion_permisos_sprint2.sql` | Configuración (`DML`) | **Fase 2** (Datos, seguridad y base común de movimientos) | `amazon_market_sprint2_test` | *(Reservado para Fase 2)* Registro de permisos y configuración de subcategorías base requeridas para el Sprint 2. | Pendiente (Fase 2) |
| `10` | `10_archivado_y_preparacion_produccion.sql` | Actualización controlada | **Fase 10** (Verificación integral y actualización controlada) | `amazon_market` | *(Reservado exclusivamente para Fase 10)* Archivado de operaciones históricas de prueba y puesta en marcha del inventario con stock cero en la base principal tras concluir las fases 1 a 9. | Pendiente (Fase 10) |

## Procedimiento de Ejecución en Entorno de Pruebas

```powershell
# 1. Restaurar respaldo base en la copia de pruebas (únicamente cuando se requiera reiniciar desde el respaldo original)
C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 amazon_market_sprint2_test -e "SOURCE C:/xampp/backups/amazon_market/amazon_market_pre_sprint2_portable.sql"

# 2. Aplicar el escenario operativo limpio en la copia de pruebas
C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 amazon_market_sprint2_test -e "SOURCE C:/xampp/htdocs/Amazon-Market/database/segundo_sprint/00_preparacion_escenario_limpio_pruebas.sql"
```
