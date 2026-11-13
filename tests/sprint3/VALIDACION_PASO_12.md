# Validación Paso 12 — Sprint 3 (Cierre de Pendientes A y B, Contrato EP-22..EP-24B)

## 1. Campos pendientes y respaldo contractual

La auditoría ampliada posterior al primer cierre registró `45/50` con cinco comprobaciones fallidas agrupadas en dos pendientes:

1. **Pendiente A — `MovimientoCajaResource` completo** (Sección 5.4, HU 15/16, HU 17, HU 19 — afecta `EP-22`, `EP-23C`, `EP-24B`):
   - `session_id`: ID entero de la sesión real del movimiento derivado de BD.
   - `reason`: motivo original almacenado (`motivo`), separado de `reference`, conservando Unicode completo.
   - `status`: `'Confirmado'` cuando el movimiento no tiene correcciones vinculadas; `'Corregido'` cuando el original tiene correcciones vinculadas (incluyendo corrección a `0.00`, sin usar `empty()` sobre valores monetarios).
   - `administrator_id`: ID entero del administrador relacionado cuando aplica; `null` cuando no aplica (conservando `administrator_name`).
   - Declaraciones de retiro omitido: `declared_occurred_in_open_origin_session` y `confirmed_reflected_in_opening` obtenidas directamente de `declarado_ocurrio_en_sesion_origen_abierta` y `confirmado_reflejado_en_apertura` (booleanos JSON cuando aplican y `null` cuando no aplican), junto con `origin_session_id`.
2. **Pendiente B — `session.version` consistente** (HU 17 y Sección 9.1 — afecta `EP-22` y `EP-23C`):
   - `session.version` como entero proveniente de `sesion_caja.version_caja`, leído en la misma transacción `REPEATABLE READ` que resumen, movimientos y conteos, sin escrituras en consulta y sin incrementos ante replays idempotentes.

---

## 2. Archivos y correcciones aplicadas

- [`Models/CajaModel.php`](file:///c:/xampp/htdocs/Amazon-Market/Models/CajaModel.php):
  - `listarMovimientosSesionPaginados()`: incorpora `m.declarado_ocurrio_en_sesion_origen_abierta`, `m.confirmado_reflejado_en_apertura` y `EXISTS(SELECT 1 FROM correccion_movimiento_caja c3 WHERE c3.id_movimiento_original = m.id_movimiento_caja) AS tiene_correcciones`.
  - `obtenerSesionDetallePorId()`: ya selecciona `s.version_caja` dentro de la lectura consistente.
- [`Services/CajaService.php`](file:///c:/xampp/htdocs/Amazon-Market/Services/CajaService.php):
  - `proyectarCabeceraSesionActiva()` y `proyectarCabeceraSesionAdmin()`: agregan `'version' => (int)$detalle['version_caja']`.
  - `proyectarMovimientoConsulta()`: completa `session_id`, `reason`, `status` (`!empty($m['tiene_correcciones']) || $m['monto_corregido_actual'] !== null ? 'Corregido' : 'Confirmado'`), `administrator_id` (`int|null`), `origin_session_id` (`int|null`), `declared_occurred_in_open_origin_session` (`bool|null`) y `confirmed_reflected_in_opening` (`bool|null`), conservando `amount` original inmutable, `current_amount`, `impact`, `effect`, `responsible`, `administrator_name` y referencias de corrección.
- [`tests/sprint3/paso12_consultas_reportes_test.php`](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/paso12_consultas_reportes_test.php): ampliada de `144` a `190` aserciones cubriendo correcciones encadenadas, corrección a `0.00`, declaraciones reales de retiro omitido, campos nulos donde no aplican, consistencia entre `EP-22`/`EP-23C`/`EP-24B` y comportamiento de `session.version` ante lecturas, mutaciones y replays idempotentes.

---

## 3. Evidencia Antes / Después

| Comprobación de auditoría | Antes (audit_results.json) | Después |
|---|---|---|
| `COMMON_movement_required_fields` | Faltaban `session_id`, `reason`, `status`, `administrator_id` | Todos presentes con tipos exactos en `EP-22`, `EP-23C` y `EP-24B` |
| `COMMON_omitted_declarations_present` | `false` (faltaban `declared_occurred_in_open_origin_session`, `confirmed_reflected_in_opening`) | `true` (booleanos desde columnas reales en retiro omitido; `null` en demás movimientos) |
| `COMMON_corrected_original_status` | `null` (esperado `'Corregido'`) | `'Corregido'` en originales con corrección encadenada o a `0.00`; `'Confirmado'` en no corregidos |
| `READ_active_version_present` (`EP-22`) | `false` | `true` (`session.version` entero igual a `sesion_caja.version_caja`) |
| `READ_admin_active_version_present` (`EP-23C`) | `false` | `true` (`session.version` entero igual a `sesion_caja.version_caja`) |

---

## 4. Pruebas y códigos de salida

Todas las baterías se ejecutaron secuencialmente tras la última edición de código sobre copias aisladas restauradas desde respaldo y migradas con `01..09`:

| Suite / Verificación | Resultado | Código de salida |
|---|---|---|
| [`tests/sprint3/paso12_consultas_reportes_test.php`](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/paso12_consultas_reportes_test.php) | **190/190** aserciones correctas | `0` |
| [`tests/sprint3/paso11_cierre_autorizacion_test.php`](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/paso11_cierre_autorizacion_test.php) | **130/130** aserciones correctas | `0` |
| [`tests/sprint3/paso10_arqueos_test.php`](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/paso10_arqueos_test.php) | **154/154** aserciones correctas | `0` |
| [`tests/sprint3/paso9_ventas_caja_test.php`](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/paso9_ventas_caja_test.php) | **107/107** aserciones correctas | `0` |
| [`tests/sprint3/paso8_movimientos_correcciones_test.php`](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/paso8_movimientos_correcciones_test.php) | **162/162** aserciones correctas | `0` |
| [`tests/sprint3/paso7_apertura_caja_test.php`](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/paso7_apertura_caja_test.php) | **214/214** aserciones correctas | `0` |
| [`tests/sprint3/paso6_turnos_programacion_test.php`](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/paso6_turnos_programacion_test.php) (+ HU 18) | **116/116** aserciones superadas | `0` |
| [`tests/sprint2/fase4_ventas_kardex_test.php`](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint2/fase4_ventas_kardex_test.php) | **14/14** (`14 PASS / 0 FAIL`) | `0` |
| [`tests/sprint2/fase8_pdf_test.php`](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint2/fase8_pdf_test.php) | **21/21** (`21 PASS / 0 FAIL`) | `0` |
| [`tests/sprint2/regresion_http_test.php`](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint2/regresion_http_test.php) | **22/22** (`22 PASS / 0 FAIL`) | `0` |
| `php -l` (5 archivos PHP modificados) + `git diff --check` | Sin errores de sintaxis ni espacios inválidos | `0` |

---

## 5. Conservación de datos

- Base principal `amazon_market` intacta: **32** tablas, `productos=108`, `stock=2497`, `ventas=250`, `compras=80`, `kardex=470`.
- Los registros originales de `movimiento_caja`, los acumulados de `sesion_caja`, las cifras cerradas de sesiones anteriores y los snapshots existentes no sufrieron modificaciones por estas correcciones de lectura/proyección.

---

## 6. Limitaciones reales

- No se realizaron cambios en frontend ni generación de PDF/Excel/tickets (fuera del alcance del Paso 12).
- No se modificó DDL ni se agregaron nuevas migraciones (no fueron necesarias al existir ya todas las columnas en `movimiento_caja`, `correccion_movimiento_caja` y `sesion_caja`).
- No se volvió a ejecutar `contract_probe.php` del directorio temporal de auditoría porque esas evidencias pertenecen a una base temporal ajena y no deben modificarse.

---

## 7. Revalidación tras Corrección de Concurrencia del Paso 11

- Tras unificar el orden de bloqueo en [`CajaModel::obtenerSesionAbiertaActual()`](file:///c:/xampp/htdocs/Amazon-Market/Models/CajaModel.php#L126-L163) (`caja_fisica_guardia FOR UPDATE` -> `sesion_caja PRIMARY FOR UPDATE`), se re-ejecutaron secuencialmente las suites sobre copias aisladas migradas (`01..09`):
  - [`tests/sprint3/paso12_consultas_reportes_test.php`](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/paso12_consultas_reportes_test.php): **190/190**, salida `0`.
  - [`tests/sprint3/paso11_cierre_autorizacion_test.php`](file:///c:/xampp/htdocs/Amazon-Market/tests/sprint3/paso11_cierre_autorizacion_test.php): **160/160**, salida `0` (`Concurrencia: 53/53`, sin deadlocks en `SHOW ENGINE INNODB STATUS`).
  - Pasos 10 (**154/154**), 9 (**107/107**), 8 (**162/162**), 7 (**214/214**), 6 + HU 18 (**116/116**) y Sprint 2 (`14/14`, `21/21`, `22/22`): todos con salida `0`.
- Conservación: `amazon_market` mantiene sus **32** tablas intactas y no se avanzó al Paso 13.
