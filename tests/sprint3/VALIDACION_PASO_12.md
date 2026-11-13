# Validación del Paso 12 - Sprint 3 (Consultas de Caja y Reporte de Cierres)

## 1. Alcance Implementado
- **EP-22** (`GET /api/caja/consulta/activa`): Consulta de sesión activa para vendedor (`id_rol = 2`, permiso `turnos.acceder`), con `summary`, `arqueos`, `movements` paginados (incluyendo `current_amount` en movimientos corregidos, `origin_session_id` en retiros omitidos, `original_movement_id` y `correction_id` en correcciones, y `reference` completa sin truncamiento) y `409 CASH_SESSION_OTHER_SELLER` si la sesión abierta pertenece a otro vendedor.
- **EP-23A** (`GET /api/caja/consulta/vendedores`): Listado de vendedores activos (`id_rol = 2`, `estado = 1`) para administrador (`id_rol = 1`, permiso `turnos.consultar_historico`).
- **EP-23B** (`GET /api/caja/consulta/sesiones`): Listado de sesiones de caja filtradas por `date` (`YYYY-MM-DD`) y `seller_id` opcional para administrador (`turnos.consultar_historico`).
- **EP-23C** (`GET /api/caja/consulta?session_id=...`): Detalle administrativo completo de una sesión (resumen monetario con `expected_cash`, `counted_cash` y `closing_difference`, `authorizations` / `authorization`, `arqueos`, `movements` paginados).
- **EP-24A** (`GET /api/reportes/cierres-caja`): Reporte administrativo de cierres de caja (`id_rol = 1`, permiso `reportes.cierres_caja`, sin exigir `reportes.ver`), con lectura consistente (`REPEATABLE READ`), totales calculados en céntimos enteros, persistencia de snapshots inmutables (`reporte_consulta_snapshot` + `reporte_caja_snapshot_item`), renovación deslizante de expiración de 30 minutos (`expira_en`), detección de incompatibilidad de filtros (`422 SNAPSHOT_FILTER_MISMATCH`) y expiración lógica (`410 SNAPSHOT_EXPIRED`) sin borrado global de la tabla.
- **EP-24B** (`GET /api/reportes/cierres-caja/{id}`): Detalle completo de un cierre específico (`closure`, `authorization`, `arqueos`, `movements` paginados), rechazando sesiones inexistentes (`404 CASH_SESSION_NOT_FOUND`) o abiertas (`409 SESSION_NOT_CLOSED`).

## 2. Archivos Modificados y Creados
- `Models/CajaModel.php`: Métodos de lectura y gestión de snapshots (`obtenerSesionDetallePorId`, `listarVendedoresParaConsulta`, `listarSesionesPorFechaYVendedor`, `contarMovimientosSesion`, `listarMovimientosSesionPaginados`, `listarAutorizacionesPorSesion`, `listarArqueosPorSesionAsc`, `listarSesionesCerradasParaReporte`, `crearSnapshotCierresCaja`, `obtenerSnapshotCierresCajaParaUsuario`, `renovarExpiracionSnapshot`, `listarItemsSnapshotCierresCaja`).
- `Services/CajaService.php`: Casos de uso `EP-22`..`EP-24B` y helpers de paginación, validación de fechas, proyección de movimientos y agregación en céntimos.
- `Controllers/API/CajaApiController.php`: Enrutamiento y autorización por rol/permiso para `consulta/activa`, `consulta/vendedores`, `consulta/sesiones` y `consulta`.
- `Controllers/API/ReporteApiController.php`: Enrutamiento de `cierres-caja` y `cierres-caja/{id}` bajo `requireAdminActivo('reportes.cierres_caja')` sin exigir `reportes.ver`.
- `tests/sprint3/paso12_consultas_reportes_test.php`: Batería HTTP + MariaDB para `EP-22`..`EP-24B` y expiración determinista mediante `setClockProvider()`.

## 3. Resultados de Pruebas Ejecutadas (sobre copias aisladas migradas `01..09`)
- **Paso 12** (`tests/sprint3/paso12_consultas_reportes_test.php`): **87/87 aserciones correctas**, salida `0` (`AUTH: 3/3`, `RBAC: 12/12`, `EP-22: 22/22`, `SETUP: 13/13`, `EP-23A: 2/2`, `EP-23B: 3/3`, `EP-23C: 8/8`, `EP-24A: 16/16`, `EP-24B: 8/8`).
- **Regresiones Sprint 3**:
  - `paso11_cierre_autorizacion_test.php`: **129/129**, salida `0`.
  - `paso10_arqueos_test.php`: **154/154**, salida `0`.
  - `paso9_ventas_caja_test.php`: **107/107**, salida `0`.
  - `paso8_movimientos_correcciones_test.php`: **162/162**, salida `0`.
  - `paso7_apertura_caja_test.php`: **214/214**, salida `0`.
  - `paso6_turnos_programacion_test.php`: **115/116** en ejecución con `HU18_DB_NAME` sin prefijo `amazon_market_sprint3_migration_test_` (único fallo `E-01` por nombre de base de prueba; el relanzamiento con el prefijo quedó colgado en el sub-runner `verificar_baja_turnos_programacion_sprint3.php` y fue cancelado).
- **Regresiones Sprint 2** (sobre copia aislada migrada `01..09`):
  - `fase4_ventas_kardex_test.php`: **14/14**, salida `0`.
  - `fase8_pdf_test.php`: **21/21**, salida `0`.
  - `regresion_http_test.php`: **22/22**, salida `0`.
- **Calidad y Base Principal**:
  - `php -l` sin errores de sintaxis en todos los archivos modificados y nuevos.
  - `git diff --check` salida `0`.
  - `amazon_market` intacta: **32 tablas**, `productos=108`, `stock=2497`, `ventas=250`, `compras=80`, `kardex=470`.
