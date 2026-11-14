# Validación del Paso 9 — Sprint 3 (`POST /api/ventas` — EP-25)

## 1. Pendientes de auditoría cerrados y causas raíz

1. **`id_metodo_pago` obligatorio (Sección 2)**:
   - *Causa*: `Services/VentaService.php` usaba `1` por defecto cuando faltaba `id_metodo_pago`.
   - *Corrección*: Se exige explícitamente `id_metodo_pago` antes de registrar idempotencia (`422 VALIDATION_ERROR` si falta, es `null`, vacío, booleano o inválido), sin generar efectos en BD y permitiendo reutilizar la `referencia_solicitud` rechazada.
2. **Contrato de respuesta original y replay (Sección 3)**:
   - *Causa*: Faltaban `code` exterior e `idempotent_replay: false` en `201`; `total` y `current_cash_balance` se devolvían como `string`; y en replay se sobrescribía `data.result_code` con `OPERATION_ALREADY_CONFIRMED`.
   - *Corrección*: Primera confirmación (`201`): `status: true`, `code: "SALE_CREATED"`, `idempotent_replay: false`, `data.result_code: "SALE_CREATED"`, `total` y `current_cash_balance` como números JSON (`round($centimos / 100, 2)` tras cálculo exacto en céntimos enteros de 64 bits). Replay (`200`): `code: "OPERATION_ALREADY_CONFIRMED"`, `idempotent_replay: true`, conservando íntegro `data` original (incluido `data.result_code: "SALE_CREATED"`).
3. **Validación de `{}` y envoltorios de error (Sección 4)**:
   - *Causa*: `Controllers/API/VentaApiController.php` devolvía `400 INVALID_REQUEST` ante `{}` y omitía `errors: {}` / `data: null` en `FORBIDDEN` e `INTERNAL_ERROR`.
   - *Corrección*: JSON sintácticamente inválido devuelve `400 INVALID_JSON` (`ApiController`); `{}` pasa por `422 VALIDATION_ERROR`; `FORBIDDEN` (`403`) e `INTERNAL_ERROR` (`500`) devuelven `{ status: false, code, message, errors: {}, data: null }`.
4. **Router aislado de pruebas (Sección 6)**:
   - *Causa*: `tests/sprint3/server_router_sprint3.php` permitía `is_file($fullPath)` sin filtrar rutas privadas, respondiendo `200` a `HEAD /.env` / `GET /.env`.
   - *Corrección*: Bloquea con `403` archivos ocultos, rutas internas (`Config`, `Libraries`, `Services`, `Models`, `Controllers`, `Views`, `tests`, `database`, `vendor`) y extensiones `.sql|.env|.log|.ini|.bak`; solo sirve archivos estáticos no-PHP bajo `/Assets/`; conserva `/__test_env_verify` y protege `amazon_market` y bases reservadas.
5. **Rollback posterior a escrituras reales (Sección 7)**:
   - Se añadió en `tests/sprint3/paso9_ventas_caja_test.php` un fallo controlado exclusivo de ensayo mediante trigger temporal `BEFORE UPDATE ON sesion_caja` (disparado después de insertar/actualizar `serie_comprobante`, `venta`, `detalle_venta`, `producto`, `kardex` y `movimiento_caja`).
   - Verifica `500 INTERNAL_ERROR` sin exponer SQL, rollback total (incluido correlativo comercial y `control_solicitud_idempotencia`) y confirmación única `201 SALE_CREATED` al reintentar con la misma referencia tras retirar el trigger.
6. **Regresiones Sprint 2 completas sin excepciones (Sección 5)**:
   - Se adaptaron `tests/sprint2/fase4_ventas_kardex_test.php`, `tests/sprint2/fase8_pdf_test.php` y `tests/sprint2/regresion_http_test.php` (junto con `tests/sprint2/bootstrap_test.php`) para operar sobre copias aisladas migradas con `01..09`, usando Vendedor (`id_rol = 2`) con sesión de caja abierta y conservando los registros de ensayo sin borrados incompatibles con `fk_mov_venta` / `fk_snapshot_item_kardex`.

## 2. Archivos modificados y creados

- BD / Migración: [09_ajuste_trigger_venta_efectivo_sprint3.sql](file:///C:/xampp/htdocs/Amazon-Market/database/tercerSprint/09_ajuste_trigger_venta_efectivo_sprint3.sql), [ejecutar_ajuste_venta_paso9_sprint3.php](file:///C:/xampp/htdocs/Amazon-Market/database/tercerSprint/tools/ejecutar_ajuste_venta_paso9_sprint3.php), [README.md](file:///C:/xampp/htdocs/Amazon-Market/database/tercerSprint/README.md)
- Backend: [VentaApiController.php](file:///C:/xampp/htdocs/Amazon-Market/Controllers/API/VentaApiController.php), [VentaService.php](file:///C:/xampp/htdocs/Amazon-Market/Services/VentaService.php), [ControlSolicitudService.php](file:///C:/xampp/htdocs/Amazon-Market/Services/ControlSolicitudService.php), [CajaService.php](file:///C:/xampp/htdocs/Amazon-Market/Services/CajaService.php), [CajaModel.php](file:///C:/xampp/htdocs/Amazon-Market/Models/CajaModel.php)
- Pruebas Sprint 3: [server_router_sprint3.php](file:///C:/xampp/htdocs/Amazon-Market/tests/sprint3/server_router_sprint3.php), [paso9_ventas_caja_test.php](file:///C:/xampp/htdocs/Amazon-Market/tests/sprint3/paso9_ventas_caja_test.php), [worker_concurrencia_paso9.php](file:///C:/xampp/htdocs/Amazon-Market/tests/sprint3/worker_concurrencia_paso9.php)
- Pruebas Sprint 2: [bootstrap_test.php](file:///C:/xampp/htdocs/Amazon-Market/tests/sprint2/bootstrap_test.php), [fase4_ventas_kardex_test.php](file:///C:/xampp/htdocs/Amazon-Market/tests/sprint2/fase4_ventas_kardex_test.php), [fase8_pdf_test.php](file:///C:/xampp/htdocs/Amazon-Market/tests/sprint2/fase8_pdf_test.php), [regresion_http_test.php](file:///C:/xampp/htdocs/Amazon-Market/tests/sprint2/regresion_http_test.php)

## 3. Evidencia antes / después y resultados finales (todos con exit code 0)

- **Reproducción previa (`amazon_market_paso9_repro_20261112`)**: reproducidos `GET /.env -> 200`, `{}` -> `400 INVALID_REQUEST`, `id_metodo_pago` ausente -> `201` (con venta/kardex/movimiento en BD) y rechazo posterior al reutilizar la referencia.
- **Batería ampliada Paso 9 (`amazon_market_paso9_fix2_20261112`, `01..09`)**: **107 / 107** aserciones correctas, exit code `0` (`Auth 7/7`, `SesionCaja 8/8`, `Validacion 20/20`, `RouterSeguridad 4/4`, `VentasCaja 34/34`, `Idempotencia 12/12`, `Overflow 3/3`, `RollbackPostEscritura 12/12`, `Concurrencia 6/6`, `Conservacion 1/1`).
- **Suites Sprint 2 (`amazon_market_paso9_s2_fix_20261112`, `01..09`)**:
  - `tests/sprint2/fase4_ventas_kardex_test.php`: **14 / 14 PASS**, exit code `0`.
  - `tests/sprint2/fase8_pdf_test.php`: **21 / 21 PASS**, exit code `0`.
  - `tests/sprint2/regresion_http_test.php`: **22 / 22 PASS**, exit code `0`.
- **Regresiones Sprint 3 en copias nuevas (`01..09`)**:
  - Paso 8 (`amazon_market_paso9_final_p8`): **162 / 162**, exit code `0`.
  - Paso 7 (`amazon_market_paso9_final_p7`): **214 / 214**, exit code `0`.
  - Paso 6 + HU 18 (`amazon_market_paso9_final_p6`): **116 / 116**, exit code `0`.
- **Sintaxis, diff y conservación**: `php -l` sin errores en todos los archivos PHP modificados; `git diff --check` con código de salida `0`; base principal `amazon_market` intacta (`32` tablas, `250` ventas, `80` compras, `108` productos, `470` registros en `kardex`).
