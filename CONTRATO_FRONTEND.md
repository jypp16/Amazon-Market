# Contrato frontend: usuarios, turnos y programación

Este contrato describe los datos que la interfaz consume y envía. La implementación temporal vive en `Assets/js/usuarios-horarios-mock.js`. Las funciones relacionadas con turnos y programación deben conectarse al backend cuando esté disponible.

## Convenciones

- Los identificadores `usuarioId` y `turnoId` son números.
- Las fechas `semanaInicio` usan `YYYY-MM-DD` y siempre representan el lunes de la semana seleccionada.
- `dias` es una lista de índices: lunes `0`, martes `1`, miércoles `2`, jueves `3`, viernes `4`, sábado `5`, domingo `6`.
- Las horas usan formato de 24 horas `HH:mm`.
- Los errores deben rechazarse con un mensaje claro para que la interfaz los anuncie y conserve los datos del formulario.

## Funciones

### `getUsuarios(busqueda?)`

Consulta el endpoint existente `GET usuarios` o `GET usuarios?q={busqueda}`. Se conserva porque el listado, el buscador y las acciones existentes ya dependen de él.

Respuesta esperada por la vista:

```json
{
  "ok": true,
  "data": {
    "data": [
      {
        "id_usuario": 4,
        "rol": "Vendedor",
        "username": "alex",
        "nombre": "Alex Garcia",
        "dni": "12345678",
        "telefono": "999999999",
        "email": "alex@example.com",
        "direccion": "Calle Las Rosas"
      }
    ]
  }
}
```

### `getTurnos()`

Devuelve los turnos disponibles para programar y el total de vendedores asignados a cada uno.

```json
[
  {
    "id": 1,
    "nombre": "Mañana",
    "inicio": "07:00",
    "fin": "15:00",
    "vendedoresAsignados": 1
  }
]
```

### `crearTurno({ nombre, inicio, fin })`

Crea un turno. El nombre es obligatorio y único; las horas deben ser válidas y `fin` debe ser posterior a `inicio`.

Respuesta: turno creado con `id`, `nombre`, `inicio`, `fin` y `vendedoresAsignados: 0`.

### `editarTurno(turnoId, { nombre, inicio, fin })`

Actualiza el nombre y las horas de un turno existente. Las asignaciones actuales se conservan.

Respuesta: turno actualizado con el total de vendedores asignados.

### `eliminarTurno(turnoId)`

Elimina el turno solo si ningún vendedor está asignado.

Respuesta:

```json
{ "eliminado": true, "id": 1 }
```

Si está asignado, el backend debe rechazar la operación con un mensaje que indique que el turno no se puede eliminar mientras tenga vendedores asignados.

### `getProgramacion(usuarioId, semanaInicio)`

**CA-04 — Continuidad de la programación:** se devuelve la programación que rige para el vendedor
y la semana solicitados, o `null` si aún no existe una programación base ni una excepción para
esa semana. La programación inicial se guarda como base vigente desde la semana seleccionada y
continúa aplicándose a las semanas siguientes. Un cambio con alcance `semana` es una excepción
temporal: solo rige esa semana y, al terminar, la consulta de la semana siguiente vuelve a
heredar la programación base. Un alcance `desde` actualiza la base desde la semana indicada y
reemplaza las excepciones futuras.

```json
{
  "turnoId": 1,
  "dias": [0, 1, 2, 3, 4],
  "semanaInicio": "2026-11-09",
  "alcance": "semana"
}
```

`alcance` es `semana` para una programación semanal, o `desde` para una programación aplicada desde esa semana en adelante. El servidor decide qué programación rige; el frontend solicita el dato para la semana y lo muestra.

### `guardarProgramacion(usuarioId, semanaInicio, { turnoId, dias, alcance })`

Guarda la programación elegida por el administrador.

Si `getProgramacion` devuelve `null`, la interfaz envía `alcance: "desde"` para establecer la
programación inicial como base. Cuando ya hay una programación vigente, el administrador puede
guardar solo una excepción semanal o cambiar la base desde la semana elegida. El backend debe
resolver cada consulta usando la base más reciente anterior o igual a esa semana, salvo que haya
una excepción explícita para la semana consultada.

```json
{
  "usuarioId": 4,
  "semanaInicio": "2026-11-09",
  "turnoId": 1,
  "dias": [0, 1, 2, 3, 4],
  "alcance": "semana"
}
```

`alcance` acepta `semana` o `desde`. Respuesta esperada: programación confirmada por el servidor con `turnoId`, `dias`, `semanaInicio` y `alcance`.

El servidor vuelve a validar que el usuario sea vendedor, el turno exista y los días sean válidos. La interfaz valida por comodidad, pero no sustituye las validaciones del servidor.

## Caja: apertura, arqueo y cierre

Las pantallas existentes `Views/Caja/abrir.php` y `Views/Caja/arqueo.php` usan temporalmente
`Assets/js/cajaDemoService.js`. Las funciones de ese archivo están marcadas `TODO backend` y
son el único origen de los datos de ejemplo para estos dos flujos. El adaptador de cada pantalla
es `Assets/js/cajaService.js` o `Assets/js/cajaArqueoService.js`.

El mock se activa por defecto en la interfaz mientras no exista el backend. Se puede desactivar
con `CAJA_WORKFLOWS_MOCK_ENABLED=false` o seleccionar un escenario con
`CAJA_WORKFLOWS_MOCK_SCENARIO`. La pantalla de apertura también toma la referencia de la variable
de entorno `CAJA_MOCK_SCENARIO` cuando `CAJA_MOCK_ENABLED=true`; la de arqueo toma
`CAJA_ARQUEO_MOCK_SCENARIO` cuando `CAJA_ARQUEO_MOCK_ENABLED=true`.

### `getTurnoDeHoy(vendedorId)`

Solicitud: identificador del vendedor autenticado.

Respuesta:

```json
{
  "vendedor_id": 7,
  "status": "within_hours",
  "has_shift_today": true,
  "shift": {
    "name": "Mañana",
    "label": "Mañana · 08:00–14:00",
    "start": "08:00",
    "end": "14:00"
  }
}
```

`status` puede ser `within_hours`, `outside_hours` o `no_shift`. Para `no_shift`, `shift` es
`null`. El servidor determina el horario y si corresponde pedir autorización.

### `getEstadoCaja(vendedorId)`

Devuelve la sesión propia del vendedor. La apertura consume `is_open`, `reference_amount`,
`reference_date`, `reference_turn` y `counted_amount`. El arqueo consume `is_open`, `is_owner`,
`session_id`, `seller`, `turn`, `opening_fund`, `cash_sales`, `income`, `expenses`, `expected`,
`card`, `yape_plin`, `version`, `history` y `closed_summary`. Los valores monetarios son números
en soles; `history` contiene arqueos con `id`, `date`, `time`, `counted`, `difference`,
`observation`, `status` y `version`.

### `guardarApertura({ request_id, cash_counted })`

Registra la apertura. Respuesta `type: "opened"` con `data.opening_balance`,
`data.opening_difference` y `data.opened_at`, o tipos `already_open`, `authorization_required`
o `error`. `request_id` es la referencia idempotente.

### `solicitarAutorizacion({ operation, request_id, cash_counted, arqueo_id, admin_username, admin_password, exception_reason })`

`operation` es `open` o `close`; los campos no aplicables a esa operación se omiten. El backend
debe validar rol, credenciales, motivo, autorización de un administrador distinto del vendedor,
sesión propia y vigencia del arqueo. Respuesta de apertura como `guardarApertura`; para cierre,
la misma respuesta que `confirmarCierre`. Un rechazo devuelve `authorization_denied`; un error
de red/servidor se comunica como fallo y no como éxito.

### `getArqueos(sesionId)`

Devuelve `{ "sesion_id": "...", "history": [...] }` con los arqueos de la sesión, en orden
cronológico. El estado (`Vigente`, `Desactualizado` o `Reemplazado`) lo entrega el servidor.

### `guardarArqueo({ request_id, counted, observation, version })`

Guarda un arqueo y devuelve `type: "saved"` más `arqueo`; o `count_invalid`,
`observation_required`, `caja_changed`, `session_closed` o `error`. El servidor valida el conteo,
la observación cuando la diferencia no es cero y la versión de la caja. Un reintento con el mismo
`request_id` no duplica el registro.

### `confirmarCierre({ request_id, arqueo_id, admin_username?, admin_password?, exception_reason? })`

Confirma el cierre con el arqueo vigente. Respuestas posibles: `closed` (resumen provisional),
`authorization_required`, `authorization_denied`, `arqueo_stale`, `session_closed` o `error`.
La interfaz solo comunica éxito después de recuperar el cierre persistido con `getCierre`.

### `getCierre(sesionId)`

Recupera el cierre persistido tras confirmar o después de un error de conexión. Devuelve `null`
si aún no hay cierre; si ya está guardado, devuelve `{ "sesion_id": "...", "summary": { "expected":
0, "counted": 0, "difference": 0, "observation": "", "closed_at": "ISO-8601" } }`.

### Escenarios mock

Configurar `CAJA_WORKFLOWS_MOCK_SCENARIO` con `success`, `no_shift`, `outside_hours`,
`authorization_required`, `invalid_credentials`, `already_open`, `no_arqueos`, `stale`,
`concurrent_sale`, `session_closed`, `close_outside_hours`, `connection_lost` o `server_error`.
Los escenarios `no_shift` y `already_open` corresponden principalmente a Abrir caja; `stale`,
`no_arqueos`, `concurrent_sale`, `close_outside_hours` y `connection_lost` corresponden a
Arqueo y cierre.

## Caja: consulta de sesiones

En `Assets/js/cajaConsultaService.js`, la consulta de administrador ahora es de dos pasos para
evitar escoger silenciosamente una caja cuando hay varias aperturas en la misma fecha. El filtro
`date` representa la **fecha local de apertura** de la sesión. No es la fecha de cierre; el reporte
de cierres conserva su filtro por fecha de cierre.

### `listSessions({ date, seller_id })`

Consulta las aperturas de un vendedor para el día de apertura indicado. El administrador elige
explícitamente una de las sesiones antes de cargar resumen y movimientos.

La interfaz muestra un filtro de turno sobre las sesiones encontradas únicamente si la lista
contiene al menos dos turnos distintos. Si solo hay un turno, no muestra el filtro. Este filtro
reduce las tarjetas visibles en el cliente; el administrador todavía debe elegir una sesión
específica antes de abrir sus movimientos.

```json
[
  {
    "id": "session-123",
    "seller": "Luis",
    "turn": "Mañana",
    "status": "Cerrada",
    "opened_at": "2026-11-10T08:00:00-05:00",
    "closed_at": "2026-11-10T15:00:00-05:00"
  }
]
```

Una sesión activa puede devolver `closed_at: null`. La lista está limitada a la fecha y vendedor
solicitados; si no hay aperturas, se devuelve una lista vacía. No se combinan sesiones ni se
selecciona automáticamente la última.

### `query({ session_id, page, page_size })`

Devuelve el resumen total de la sesión seleccionada, sus movimientos de la página pedida y datos
de paginación. La respuesta incluye el objeto `session` con `session_id`, vendedor, turno, estado,
`opened_at` y `closed_at`, además de `summary`, `movements` y `pagination`. Cada página conserva
el mismo `session_id`; los totales corresponden a toda la sesión.

Para vendedores, `getActiveSession({ page, page_size })` permanece limitado a su propia sesión
activa y no expone filtros ni selección de otras sesiones.

El mock `CAJA_CONSULTA_MOCK_SCENARIO=multiple_sessions` devuelve dos sesiones del mismo vendedor
en la misma fecha (una cerrada y otra abierta) para verificar que el usuario seleccione cuál
consultar.

## Caja: correcciones de movimientos

La pestaña **Corregir registro** en Movimientos de caja sirve solo para corregir un importe mal
registrado con evidencia comprobable. No sirve para cuadrar un faltante físico: ese descuadre se
documenta en el arqueo. El flujo conserva el original, registra una corrección vinculada y requiere
credenciales de un administrador distinto del vendedor. La consulta de caja sigue siendo de solo
lectura.

### `listCorrectableMovements(sesionId)`

Devuelve `{ "movements": [...], "corrections": [...] }`. `movements` contiene registros originales
confirmados y elegibles de la sesión, con `id`, `type`, `label`, `amount`, `current_amount`,
`reference`, `reason`, `recorded_at` y `status`. No debe incluir retiros omitidos ni correcciones
como nuevos movimientos corregibles. `corrections` contiene registros adicionales vinculados con
`id`, `original_movement_id`, `original_reference`, `original_amount`,
`previous_corrected_amount`, `corrected_amount`, `reason`, `effect`, `status`, `recorded_at` y
`responsible`. El original nunca se reemplaza ni elimina.

### `requestCorrection({ request_id, session_id, original_movement_id, corrected_amount, reason, admin_username, admin_password })`

`corrected_amount` es el importe total correcto que debió tener el original; no es el ajuste
compensatorio. La interfaz no calcula el efecto financiero: el servidor valida que el error sea
corregible, autentica y autoriza al administrador, conserva el registro original, registra el
ajuste vinculado y devuelve el efecto y saldo resultantes.

Respuesta confirmada:

```json
{
  "type": "success",
  "data": {
    "correction": {
      "id": "correction-123",
      "original_movement_id": "movement-456",
      "original_amount": 500,
      "previous_corrected_amount": 500,
      "corrected_amount": 50,
      "reason": "El comprobante y la entrega confirman S/ 50,00",
      "effect": "Resta S/ 450,00",
      "status": "Autorizada",
      "responsible": "admin"
    },
    "original_movement": {
      "id": "movement-456",
      "amount": 500,
      "current_amount": 50
    },
    "balance": 300
  }
}
```

El backend debe volver a validar la sesión autenticada y su correspondencia con `session_id`, la propiedad,
el estado del registro y la idempotencia de `request_id`; la comparación local de nombres de usuario es solo una protección
de usabilidad, no autorización. Errores esperados: `MOVEMENT_NOT_FOUND`, `CORRECTION_NOT_ALLOWED`,
`INVALID_AMOUNT`, `MOTIVE_REQUIRED`, `INVALID_ADMIN_CREDENTIALS`, `ADMIN_ROLE_REQUIRED` y
`CASH_CLOSED`.

El mock de Movimientos incluye un original de ingreso y sus correcciones en el historial visible.
La cifra de saldo y el efecto devueltos por el mock simulan la respuesta del servidor.
