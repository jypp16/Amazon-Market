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

Devuelve la programación que rige para el vendedor y la semana solicitados, o `null` si no existe.

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
