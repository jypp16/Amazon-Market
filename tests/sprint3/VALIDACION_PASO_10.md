# Validación del Paso 10 — Sprint 3 (Arqueo de Efectivo, Historial y Control de Vigencia)

## 1. Alcance implementado

Endpoints implementados para el **Paso 10** (`HU 12` y evaluación horaria informativa):
- `EP-17`: `GET /api/caja/arqueo/estado`
- `EP-18`: `GET /api/caja/arqueos`
- `EP-19`: `POST /api/caja/arqueo`

Archivos modificados / creados:
- `Models/CajaModel.php`
- `Controllers/API/CajaApiController.php`
- `Services/CajaService.php`
- `tests/sprint3/paso10_arqueos_test.php`

## 2. Reglas de negocio y contrato verificadas

1. **Seguridad y permisos (`turnos.arqueo`)**:
   - Exclusivo para usuarios con rol `Vendedor` (`id_rol = 2`) y permiso `turnos.arqueo`, sin requerir `turnos.abrir` ni `turnos.movimientos`.
   - Rechaza sesiones ausentes o usuarios desactivados en BD (`401 AUTH_REQUIRED`), rol `Administrador` (`403`), falta de permiso `turnos.arqueo` (`403`), CSRF inválido (`403`) y JSON malformado (`400 INVALID_JSON`).
2. **`EP-17` (`GET /api/caja/arqueo/estado`)**:
   - Lectura consistente `REPEATABLE READ` + `START TRANSACTION READ ONLY`.
   - Caja cerrada: `200`, `is_open: false`, `is_owner: false`, `session_id: null`, `expected: null`, `history: []`, `has_valid_arqueo: false`, `active_arqueo_id: null`, `closed_summary: null`, `schedule: null`.
   - Caja abierta por otro vendedor: `200`, `is_open: true`, `is_owner: false`, ocultando importes e historial ajenos (`expected: null`, `history: []`).
   - Sesión propia abierta: calcula `expected`, `card`, `yape_plin`, `omitted_withdrawals_zero_impact_total`, `version`, `has_valid_arqueo`, `active_arqueo_id`, `reference_schedule` y `schedule` (`requires_authorization = now < fecha_hora_salida_referencia - 15 minutes`, inmutable ante ediciones posteriores del turno o cruce de medianoche).
3. **`EP-18` (`GET /api/caja/arqueos`)**:
   - Soporta `session_id` / `sesion_id` opcional (`422 AMBIGUOUS_ALIAS_VALUES` si son contradictorios, `404 CASH_SESSION_NOT_FOUND`, `403 SESSION_NOT_OWNED`, `409 CASH_SESSION_CLOSED`).
   - Retorna historial cronológico ascendente (`ORDER BY fecha_hora_arqueo ASC, id_arqueo_caja ASC`) con estados mapeados a `Vigente`, `Reemplazado`, `Desactualizado`.
4. **`EP-19` (`POST /api/caja/arqueo`)**:
   - Valida `counted >= 0.00` hasta `99999999.99` (máximo 2 decimales, rechazo de clave duplicada incluso con escapes Unicode vía `JsonDecimalExtractor`, booleanos), `version >= 1` entero no booleano y `observation` (`0..500` caracteres Unicode tras `trim`, obligatoria si `difference != 0.00` -> `422 OBSERVATION_REQUIRED`).
   - Idempotencia (`CAJA_ARQUEO`) evaluada antes del estado/versión de sesión: los replays devuelven `200 OPERATION_ALREADY_CONFIRMED` con `idempotent_replay: true` y el `data` original íntegro incluso después de reemplazo, desactualización o cierre de sesión.
   - Bloquea `sesion_caja FOR UPDATE`, valida `version === version_caja` (`409 CASH_CHANGED` devolviendo `expected` y `version` actuales), pasa cualquier arqueo `VIGENTE` previo a `REEMPLAZADO`, conserva filas `DESACTUALIZADO`, inserta el nuevo arqueo `VIGENTE` y actualiza `sesion_caja.id_ultimo_arqueo` sin incrementar `version_caja`.
   - Permitido sin credenciales de administrador incluso cuando `schedule.requires_authorization = true`.
5. **Control de vigencia y concurrencia**:
   - Toda operación posterior que incrementa `version_caja` (venta en efectivo, tarjeta, Yape/Plin, ingreso, egreso, retiro omitido y corrección) desactualiza el arqueo `VIGENTE` a `DESACTUALIZADO` (`has_valid_arqueo = false`, `active_arqueo_id = null`).
   - El replay idempotente de una venta anterior no invalida un arqueo posterior.
   - Rollback ante fallo posterior a escrituras de `arqueo_caja` revierte el nuevo arqueo, restaura el estado `VIGENTE` del anterior e `id_ultimo_arqueo`.
   - Concurrencia real (`curl_multi` con sesiones PHP independientes `PHPSESSID A != PHPSESSID B`): dos arqueos nuevos simultáneos serializan manteniendo exactamente 1 `VIGENTE`; reintentos concurrentes con misma referencia insertan exactamente 1 fila; carrera simultánea Arqueo vs Venta finaliza con venta `201` y arqueo `201` (invalidado después) o `409 CASH_CHANGED`.

## 3. Evidencia reproducible de ejecución

Preparación de cada base de datos aislada `$db`:
1. Restaurar respaldo limpio `C:\xampp\backups\Amazon-Market\Sprint3\amazon_market_sprint3_pre_step3_20261112_012312.sql`.
2. Ejecutar en secuencia:
   - `C:\xampp\php\php.exe database/tercerSprint/tools/ejecutar_migracion_sprint3.php $db --apply`
   - `C:\xampp\php\php.exe database/tercerSprint/tools/ejecutar_ajuste_paso8_sprint3.php $db`
   - `C:\xampp\php\php.exe database/tercerSprint/tools/ejecutar_ajuste_referencia_paso8_sprint3.php $db`
   - `C:\xampp\php\php.exe database/tercerSprint/tools/ejecutar_ajuste_venta_paso9_sprint3.php $db`

Resultados ejecutados tras la edición final de las pruebas del Paso 10:
- **Paso 10 (`tests/sprint3/paso10_arqueos_test.php` sobre `amazon_market_paso10_fix_20261112`)**: **154/154** aserciones correctas, salida `0` (`PRE: 4/4`, `Seguridad: 22/22`, `Estado: 21/21`, `Conteo: 12/12`, `Historial: 6/6`, `Persistencia: 13/13`, `Idempotencia: 10/10`, `Vigencia: 43/43`, `Concurrencia: 8/8`, `Horario: 15/15`).
- **Sintaxis y formato**: `php -l tests/sprint3/paso10_arqueos_test.php` sin errores y `git diff --check` limpio.

## 4. Corrección de la prueba horaria dependiente del reloj real

- **Causa del fallo (`148/149`)**: en `tests/sprint3/paso10_arqueos_test.php`, el bloque HTTP final abría una sesión con turno `00:00:00`–`23:59:00` usando el reloj real y esperaba incondicionalmente `requires_authorization = true`. Como la regla de negocio exige `ahora < fecha_hora_salida_referencia - 15 minutos`, desde las `23:44:00` en adelante el backend devuelve `false`, provocando un falso negativo dependiente de la hora de ejecución.
- **Corrección aplicada (solo en pruebas y documentación, sin alterar el backend)**:
  - **Pruebas directas deterministas con reloj controlado (`CajaService::setClockProvider`)**: sobre la sesión con `fecha_hora_salida_referencia = '2026-11-12 12:00:00'` (`America/Lima`), se verifican de forma determinista los cuatro escenarios horarios y el guardado sin credenciales administrativas:
    - `11:44:59` (antes del margen): `consultarEstadoArqueo()` devuelve `requires_authorization = true` y `reason !== null`; `guardarArqueo()` con ese mismo reloj controlado responde `201 ARQUEO_SAVED` y persiste la fila `VIGENTE` en `arqueo_caja` sin credenciales de administrador.
    - `11:45:00` (instante exacto del margen): `consultarEstadoArqueo()` devuelve `requires_authorization = false` y `reason = null`; `guardarArqueo()` responde `201`.
    - `12:35:00` (después de la salida): `requires_authorization = false`.
    - `2026-11-13 00:15:00` (después de medianoche): conserva la salida original del turno (`requires_authorization = false`).
  - **Pruebas HTTP con reloj real**: al consultar `GET /api/caja/arqueo/estado`, la expectativa de `requires_authorization` se calcula independientemente a partir de la hora actual del servidor en `America/Lima` y de `fecha_hora_salida_referencia` leída de `sesion_caja` (esperando `3s` si estuviera a `<= 2s` del umbral `-15m` para evitar cruce de frontera durante la petición), y se verifica `POST /api/caja/arqueo` (`201`) sin credenciales administrativas en cualquier horario.
