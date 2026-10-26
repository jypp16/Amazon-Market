# Fase 10 — Verificación integral y actualización controlada

**Estado:** Completada el 25/10/2026  
**Bases:** `amazon_market_sprint2_test` y `amazon_market`  
**Script:** [`database/segundo_sprint/10_archivado_y_preparacion_produccion.sql`](../../database/segundo_sprint/10_archivado_y_preparacion_produccion.sql)

## Alcance aplicado

1. Ejecutar de nuevo las suites de fases 2–9 y las regresiones HTTP, reportes, referencias e interfaz sobre la base aislada.
2. Cargar 1 000 operaciones reales del `InventarioService`, abrir cinco sesiones HTTP, registrar operaciones desde cinco procesos DB paralelos y medir consultas paginadas y exportaciones PDF/Excel.
3. Crear un respaldo completo y externo de `amazon_market`; restaurarlo sobre la copia aislada y comprobar que conserve las tablas y datos legados.
4. Ensayar el script 10 en `amazon_market_sprint2_test` con `@SPRINT2_FASE10_MODO_PRUEBA='VALIDAR_FASE10'`.
5. Repetir el respaldo inmediatamente antes de aplicar el script 10 a `amazon_market`, archivar operaciones antiguas y verificar el nuevo estado operativo.

## Respaldos

El respaldo usado para restaurar y ensayar el procedimiento fue:

- `C:\xampp\backups\amazon_market\amazon_market_pre_sprint2_fase10.sql`
- 62 679 bytes; SHA-256 `2106BF65E4549C04EF17DB70C0147EE05650833D214438C3B7C39D3BEDCBD934`.

El respaldo final, creado inmediatamente antes de modificar la base habitual y registrado dentro de `sprint2_fase10_control`, fue:

- `C:\xampp\backups\amazon_market\amazon_market_pre_sprint2_fase10_apply.sql`
- 62 679 bytes; SHA-256 `26F5A03125B1B1057A48367B6FBD68E9523A03299AB88519B41ADF6468B19BCD`.

La restauración de ensayo devolvió los conteos originales: 107 productos, 98 ventas, 377 detalles de venta, 4 compras, 4 detalles de compra, 3 registros de historial y 26 filas de Kardex.

## Resultado de carga, concurrencia y rendimiento

`tests/sprint2/fase10_carga_concurrencia_test.php` terminó con **8 PASS / 0 FAIL**:

| Medición | Resultado |
|---|---:|
| Movimientos de carga base | 1 000 |
| Sesiones HTTP activas en la ráfaga | 5 |
| Operaciones añadidas durante consultas | 50 (10 por proceso) |
| Consultas HTTP de Kardex y Variaciones | 10; máximo **418.9 ms** |
| Exportaciones PDF/Excel con más de 1 000 movimientos | 5; máximo **2 137.1 ms** |
| Deadlocks | 0 |
| Discrepancias entre stock y último saldo Kardex | 0 |
| Referencias de consulta con snapshot consistente | 10/10 |
| Descargas PDF/XLSX válidas | 5/5 |

La carga alcanzó 1 050 movimientos cuando terminaron los cinco procesos. Los XLSX se revisaron dentro del ZIP OOXML y cada hoja de detalle tuvo 1 050 filas de datos más el encabezado. Cada una de las cinco cuentas temporales de Administrador consultó ambos reportes; las exportaciones incluyeron Kardex PDF, Kardex Excel, Variaciones PDF y Variaciones Excel. Las cinco cuentas se eliminaron como parte de la limpieza.

### Hallazgo de concurrencia corregido

La primera ejecución mostró dos deadlocks. El registro `LATEST DETECTED DEADLOCK` de InnoDB localizó el bloqueo en la inserción de `control_solicitud_idempotencia`: una búsqueda `SELECT ... FOR UPDATE` de una referencia inexistente retenía un bloqueo de rango en el índice único, y distintas referencias nuevas intentaban insertar en ese mismo espacio. Como `GET_LOCK` ya serializa la combinación exacta de usuario, operación y referencia, se retiró el bloqueo de rango de esa lectura. La siguiente ejecución concurrente completó 50 escrituras con cero deadlocks.

### Límite de concurrencia del servidor de prueba

El cliente envió solicitudes simultáneas desde cinco sesiones, pero el servidor PHP integrado en Windows procesa una solicitud HTTP por proceso. Las cinco operaciones de escritura sí corrieron desde cinco procesos PHP distintos contra MariaDB al mismo tiempo que llegaban las consultas. Los tiempos medidos cumplen los umbrales, aunque la prueba no reproduce cinco workers HTTP ejecutándose en paralelo.

## Sincronización de la base habitual

El script `10_archivado_y_preparacion_produccion.sql` se probó sobre una restauración antes de dirigirse a `amazon_market`. Luego se ejecutó explícitamente con `amazon_market` como base seleccionada y `mysql --abort-source-on-error`.

Se crearon tablas de archivo con los tipos y columnas originales:

- `sprint2_archivo_venta`: 98 filas.
- `sprint2_archivo_detalle_venta`: 377 filas.
- `sprint2_archivo_compra`: 4 filas.
- `sprint2_archivo_detalle_compra`: 4 filas.
- `sprint2_archivo_compra_historial`: 3 filas.
- `sprint2_archivo_kardex`: 26 filas.

La tabla `sprint2_fase10_control` quedó `COMPLETADA` y registra la ruta y SHA-256 del respaldo aplicado. Verificaciones posteriores:

- 107 productos con los mismos IDs y atributos de catálogo que la copia migrada; stock actual de todos en cero.
- 6 categorías con subcategorías y 0 productos sin `id_subcategoria`.
- 0 ventas, compras, detalles operativos, historial de compra o movimientos en las tablas activas.
- Los permisos de ajustes, Kardex, Variaciones y exportación están asignados al Administrador y a ningún otro rol.
- Los checksums de las seis tablas archivadas coinciden con la copia de ensayo.
- La conexión `.env` no se cambió.

## Suites finales

- Fases 2–9 y regresiones HTTP/reportes/referencias/interfaz: **200 PASS / 0 FAIL** después de ensayar la migración sobre la base aislada.
- Carga y concurrencia Fase 10: **8 PASS / 0 FAIL**; el script retira sus productos, movimientos, solicitudes, snapshots y sesiones temporales al finalizar.
- La revisión visual de los PDF de Fase 8 y Excel de Fase 9 sigue pendiente como ya consta en [estado-fases.md](estado-fases.md).
- El chequeo HTTP adicional contra un servidor temporal apuntando a la base habitual no terminó: la revisión automática del entorno excedió su plazo. La migración sí quedó verificada por el registro de control, las consultas de integridad y el ensayo/restauración aislados.
