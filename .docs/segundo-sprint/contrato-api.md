# Contrato de API — Sprint 2 (Inventario, Kardex y Variaciones)

**Proyecto:** Amazon-Market  
**Versión de contrato:** 1.1 (Revisado y consolidado en Fase 1)  
**Formato de intercambio:** `application/json; charset=utf-8` (excepto descargas binarias PDF/Excel)

**Aclaraciones verificadas tras la auditoría de fases 1–7:**

- Las escrituras de compras (crear, corregir, anular) y ventas exigen `referencia_solicitud` válida en el cuerpo o cabecera. Primera creación: 201; repetición ya confirmada: 200 sin duplicar movimientos. Se conservan las referencias de intenciones pendientes, incluso al volver de unos datos A a B y luego a A.
- El resumen del Kardex de un producto corresponde a todo el período, independientemente de los filtros históricos de categoría, subcategoría o texto que se apliquen a la tabla. `resumen_producto.total_movimientos` cuenta ese período completo; `movimientos_filtrados` cuenta las filas de la tabla; `alcance` vale `PERIODO_COMPLETO_PRODUCTO`. Un resultado filtrado vacío no implica que el producto no tenga movimientos ni saldo.
- Una nueva consulta de Variaciones exige ambas fechas. Paginar únicamente con `referencia_consulta` reutiliza las fechas guardadas. Categoría y subcategoría deben existir y corresponder entre sí; no se exige que la clasificación actual del producto coincida con la histórica.
- Kardex aplica cambios de filtros al pulsar Consultar o Limpiar. Editar controles y luego paginar mantiene la consulta anterior y su referencia.

Evidencia y entorno aislado: [correcciones-fases1-7.md](correcciones-fases1-7.md).

---

## 1. Convenciones Generales y Enrutamiento

### 1.1 Mecanismo de Enrutamiento (`index.php`)
El enrutador actual ([`index.php`](../../index.php#L40-L76)) resuelve las peticiones bajo `/api/{recurso}/{subruta}`:
- `$resource = strtolower($arrUrl[1])` mapea al controlador en `Controllers/API/{Recurso}ApiController.php`.
- `$params = $arrUrl[2] ?? ''` se entrega como primer argumento al método HTTP (`get($params)`, `post($params)`, etc.).

| Recurso y Ruta | Método HTTP | Controlador y Método | Permiso Requerido | Propósito |
|---|---|---|---|---|
| `/api/inventario/bajas` | `POST` | `InventarioApiController::post('bajas')` | Administrador activo (`id_rol = 1`, `estado = 1`) | Registrar una baja definitiva de stock (`Baja por daño` o `Baja por vencimiento`). |
| `/api/inventario/ajustes` | `POST` | `InventarioApiController::post('ajustes')` | Administrador activo (`id_rol = 1`, `estado = 1`) | Registrar un ajuste de stock por conteo físico (`Ajuste positivo` o `Ajuste negativo`). |
| `/api/reportes/kardex` | `GET` | `ReporteApiController::get('kardex')` | Administrador activo (`id_rol = 1`, `estado = 1`) | Consultar movimientos paginados y resumen del Kardex. |
| `/api/reportes/variaciones` | `GET` | `ReporteApiController::get('variaciones')` | Administrador activo (`id_rol = 1`, `estado = 1`) | Consultar movimientos paginados y resumen del Reporte de Variaciones. |
| `/api/reportes/exportar-pdf` | `GET` | `ReporteApiController::get('exportar-pdf')` | Administrador activo (`id_rol = 1`, `estado = 1`) | Descargar PDF de `tipo=kardex` o `tipo=variaciones` (además de `compras`/`proveedores`). |
| `/api/reportes/exportar-excel` | `GET` | `ReporteApiController::get('exportar-excel')` | Administrador activo (`id_rol = 1`, `estado = 1`) | Descargar Excel de `tipo=kardex` o `tipo=variaciones` (además de `compras`/`proveedores`). |
| `/api/productos` | `GET` | `ProductoApiController::get('')` | Estándar: `productos.listar` (Admin y Vendedor/POS)<br>`modo=reporte`: Administrador activo | Modo estándar: catálogo activo para POS/operaciones.<br>`modo=reporte`: incluye inactivos con historial para filtros de Kardex y Variaciones. |
| `/api/categorias` | `GET` | `CategoriaApiController::get('')` | Estándar: `productos.listar` (Admin y Vendedor/POS)<br>`modo=reporte`: Administrador activo | Modo estándar: categorías activas para POS/catálogo.<br>`modo=reporte`: categorías y subcategorías aplicables para reportes. |

---

## 2. Seguridad, Solicitudes Repetidas y Consistencia de Consulta

### 2.1 Autenticación y Autorización (RN-01 / Acuerdo 7)
Todas las funciones del Sprint 2 (incluyendo vistas, consultas y exportaciones, así como el parámetro `modo=reporte` en catálogos) exigen sesión autenticada de un usuario verificado en base de datos en cada petición con:
- `usuario.estado = 1` (activo).
- `usuario.id_rol = 1` (rol **Administrador**).

**Respuestas de error de acceso:**
- **401 Unauthorized** (sin sesión o usuario desactivado en BD; si estaba en sesión, se destruye la sesión):
```json
{
  "status": false,
  "code": "AUTH_REQUIRED",
  "message": "Su sesión ha expirado o el usuario ya no se encuentra activo.",
  "data": null
}
```
- **403 Forbidden** (usuario autenticado con rol distinto de Administrador, p. ej. Vendedor `id_rol = 2`):
```json
{
  "status": false,
  "code": "FORBIDDEN_ADMIN_ONLY",
  "message": "Acceso denegado. Esta operación está reservada exclusivamente a usuarios con rol Administrador.",
  "data": null
}
```

---

### 2.2 Solicitudes Repetidas e Idempotencia (`referencia_solicitud`)

#### A. Formato, Longitud y Alcance Unificados
- **Nombre del campo:** `referencia_solicitud` (obligatorio en todo `POST` que modifica existencias: `/api/inventario/bajas`, `/api/inventario/ajustes`, `/api/compras`, `/api/ventas`).
- **Formato y longitud:** Cadena de **16 a 64 caracteres** que cumple la expresión regular `^[A-Za-z0-9\-_]{16,64}$` (por ejemplo, UUID v4 de 36 caracteres o prefijo de operación + UUID).
- **Alcance y asociación:** La clave única de control en el servidor se compone de **`(id_usuario, tipo_operacion, referencia_solicitud)`**, donde `tipo_operacion` identifica el flujo (`BAJA`, `AJUSTE`, `COMPRA`, `VENTA`).
- **Datos almacenados por solicitud:** `id_usuario`, `tipo_operacion`, `referencia_solicitud`, `hash_payload` (SHA-256 de los campos funcionales normalizados de la solicitud), `estado_solicitud` (`EN_PROCESO`, `CONFIRMADA`, `FALLIDA`), `codigo_http`, `respuesta_json` (instantánea del resultado confirmado) y marcas de tiempo.

#### B. Comportamiento Diferenciado ante Solicitudes Repetidas
1. **Caso 1 — Solicitud ya confirmada con los mismos datos (`estado_solicitud = CONFIRMADA` y mismo `hash_payload`):**
   - El servidor **no vuelve a ejecutar la operación ni modifica el stock**.
   - Recupera y devuelve el **resultado original** con código **`200 OK`**, indicando `"idempotent_replay": true` y conservando íntegro el objeto `data` de la confirmación original:
   ```json
   {
     "status": true,
     "code": "OPERATION_ALREADY_CONFIRMED",
     "idempotent_replay": true,
     "message": "La operación ya fue confirmada previamente. Se devuelve el resultado original.",
     "data": {
       "id_operacion": 1,
       "id_kardex": 101,
       "referencia_solicitud": "baja-20261025-8f3a1c92-0001",
       "fecha_hora": "2026-10-25 13:15:22",
       "id_producto": 12,
       "producto": "Aceite Vegetal 1L",
       "codigo_barra": "775010000012",
       "categoria": "Abarrotes",
       "subcategoria": "General",
       "unidad": "LTR",
       "tipo_movimiento": "Baja por daño",
       "cantidad": 4,
       "saldo_anterior": 20,
       "saldo_resultante": 16,
       "lote": "L-2026-09",
       "justificacion": "Envases rotos detectados en almacén secundario",
       "responsable": "Administrador Principal"
     }
   }
   ```
2. **Caso 2 — Misma `referencia_solicitud` enviada con datos diferentes (`hash_payload` distinto):**
   - El servidor rechaza el conflicto con **`409 Conflict`**:
   ```json
   {
     "status": false,
     "code": "IDEMPOTENCY_PAYLOAD_MISMATCH",
     "message": "La referencia de solicitud ya fue utilizada con datos diferentes. Genere una nueva solicitud.",
     "errors": {
       "referencia_solicitud": "La referencia de solicitud no coincide con los datos originales."
     },
     "data": {
       "referencia_solicitud": "baja-20261025-8f3a1c92-0001"
     }
   }
   ```
3. **Caso 3 — Solicitud todavía en procesamiento (`estado_solicitud = EN_PROCESO`):**
   - Si llega un reenvío mientras la transacción inicial sigue ejecutándose, el servidor informa su estado sin duplicarla respondiendo **`409 Conflict`**:
   ```json
   {
     "status": false,
     "code": "REQUEST_IN_PROGRESS",
     "message": "La solicitud se encuentra actualmente en procesamiento. Espere su finalización.",
     "data": {
       "referencia_solicitud": "baja-20261025-8f3a1c92-0001",
       "estado_solicitud": "EN_PROCESO"
     }
   }
   ```

---

### 2.3 Mecanismo de Consistencia de Consulta Administrado por el Servidor (`referencia_consulta`)

Un simple `MAX(id_kardex)` no garantiza por sí solo un conjunto inmutable frente a transacciones concurrentes ni asegura que los filtros y resúmenes permanezcan idénticos entre la tabla paginada y los archivos exportados. Por ello, sin mantener transacciones abiertas entre peticiones HTTP, el servidor administra una **referencia de consulta persistida (`referencia_consulta`)**:

#### A. Funcionamiento en el Servidor
1. **Materialización al consultar (`page=1` sin `referencia_consulta` o al pulsar `Consultar`):**
   - Dentro de una lectura consistente (`REPEATABLE READ`), el servidor evalúa los filtros solicitados, calcula los resúmenes sobre todo el conjunto filtrado, obtiene la lista ordenada de identificadores de movimientos (`id_kardex`) y guarda en base de datos:
     - **Cabecera (`reporte_consulta_snapshot`):**
       - `referencia_consulta` (`VARCHAR(64)` único, ej. ` rc-20261025-9a4d2e1b8c7f4a10`)
       - `id_usuario` (usuario Administrador propietario de la consulta)
       - `tipo_reporte` (`kardex` o `variaciones`)
       - `hash_filtros` (SHA-256 del objeto canónico de filtros aplicados)
       - `filtros_json` (filtros normalizados aplicados)
       - `resumen_json` (resumen general y por producto ya calculado sobre el conjunto congelado)
       - `total_registros` (entero $\ge 0$)
       - `creado_en` y `expira_en` (vigencia de 30 minutos, renovable automáticamente en cada cambio de página o exportación válida)
     - **Detalle de filas (`reporte_consulta_snapshot_item`):**
       - `(referencia_consulta, orden_posicion, id_kardex)` con las filas exactas que conforman la consulta.
2. **Aislamiento frente a confirmaciones posteriores:**
   - Cuando el usuario cambia de página (`page=2`, `page=3`, ...) o descarga `exportar-pdf` / `exportar-excel` enviando `referencia_consulta`, el servidor lee **exclusivamente** los `id_kardex` registrados en `reporte_consulta_snapshot_item` (en su `orden_posicion`) y el `resumen_json` guardado en `reporte_consulta_snapshot`.
   - Cualquier operación de compra, venta, baja o ajuste que se confirme después de creada la `referencia_consulta` no altera el total de filas, ni desplaza las páginas, ni modifica el resumen, ni cambia los datos exportados en PDF o Excel.

#### B. Validaciones de Seguridad, Integridad de Filtros y Vencimiento de `referencia_consulta`
1. **Asociación con el usuario autorizado (`403 Forbidden`):**
   - Si un usuario intenta consultar o exportar una `referencia_consulta` cuyo `id_usuario` no coincide con el usuario autenticado:
   ```json
   {
     "status": false,
     "code": "QUERY_REFERENCE_FORBIDDEN",
     "message": "La referencia de consulta no pertenece al usuario actual.",
     "data": null
   }
   ```
2. **Imposibilidad de alterar filtros conservando la misma referencia (`409 Conflict`):**
   - Si la petición incluye una `referencia_consulta` pero envía parámetros de filtro (`fecha_inicio`, `fecha_fin`, `id_producto`, `q`, `id_categoria`, `id_subcategoria`, `tipo_variacion`, `sentido`) cuyo `hash_filtros` difiere del `hash_filtros` almacenado en la referencia:
   ```json
   {
     "status": false,
     "code": "QUERY_FILTERS_MISMATCH",
     "message": "Los filtros enviados no coinciden con la referencia de consulta activa. Ejecute una nueva consulta para aplicar los nuevos filtros.",
     "data": {
       "referencia_consulta": "rc-20261025-9a4d2e1b8c7f4a10",
       "requiere_nueva_consulta": true
     }
   }
   ```
3. **Vencimiento o inexistencia de la referencia (`410 Gone`):**
   - Si `referencia_consulta` no existe o la hora actual superó `expira_en`:
   ```json
   {
     "status": false,
     "code": "QUERY_REFERENCE_EXPIRED",
     "message": "La referencia de la consulta ha expirado. Pulse Consultar nuevamente para actualizar el reporte.",
     "data": {
       "referencia_consulta": "rc-20261025-9a4d2e1b8c7f4a10",
       "requiere_nueva_consulta": true
     }
   }
   ```

---

## 3. Especificación de Endpoints de Inventario (Bajas y Ajustes)

### 3.1 Registro de Baja de Inventario
- **Ruta:** `POST /api/inventario/bajas`
- **Cuerpo de solicitud (`JSON`):**
```json
{
  "referencia_solicitud": "baja-20261025-8f3a1c92-0001",
  "id_producto": 12,
  "motivo_baja": "Baja por vencimiento",
  "cantidad": 4,
  "lote": "L-2026-09",
  "justificacion": "Productos con fecha de vencimiento cumplida retirados de anaquel principal"
}
```

**Reglas de validación de campos:**
| Campo | Tipo | Obligatorio | Control Frontend Asociado | Reglas de Validación |
|---|---|---|---|---|
| `referencia_solicitud` | `string` | Sí | Generado en `inventario.js` | Cadena de 16 a 64 caracteres (`^[A-Za-z0-9\-_]{16,64}$`). Única por `(id_usuario, 'BAJA', referencia_solicitud)`. |
| `id_producto` | `int` | Sí | `#writeoff-product` | Entero $> 0$. El producto debe existir y estar activo (`estado = 1`). |
| `motivo_baja` | `string` | Sí | `#writeoff-reason` | `"Baja por daño"` (o `"Daño"`) o `"Baja por vencimiento"` (o `"Vencimiento"`). Se normaliza a `"Baja por daño"` / `"Baja por vencimiento"`. |
| `cantidad` | `int` | Sí | `#writeoff-quantity` | Entero estricto $\ge 1$. Rechaza cero, negativos, decimales o texto. Debe ser $\le$ `stock_actual` bloqueado con `FOR UPDATE`. |
| `justificacion` | `string` | **Sí (Siempre)** | `#writeoff-justification` | **Obligatoria tanto en `Baja por daño` como en `Baja por vencimiento`.** Texto no vacío tras `trim()` (máx. 255 caracteres). *(Se acepta `observacion` como alias de entrada, pero el campo canónico de solicitud, respuesta y `errors` es `justificacion`).* |
| `lote` | `string\|null` | No | `#writeoff-lot` | Opcional (máx. 50 caracteres). Si falta o va vacío, se guarda `NULL` y se expone como `"Sin lote"`. |

**Respuesta exitosa en primera confirmación (`201 Created`):**
```json
{
  "status": true,
  "code": "WRITEOFF_CREATED",
  "idempotent_replay": false,
  "message": "Baja de inventario registrada correctamente.",
  "data": {
    "id_operacion": 1,
    "id_kardex": 101,
    "referencia_solicitud": "baja-20261025-8f3a1c92-0001",
    "fecha_hora": "2026-10-25 13:15:22",
    "id_producto": 12,
    "producto": "Aceite Vegetal 1L",
    "codigo_barra": "775010000012",
    "categoria": "Abarrotes",
    "subcategoria": "General",
    "unidad": "LTR",
    "tipo_movimiento": "Baja por vencimiento",
    "sentido": "DISMINUCION",
    "cantidad": 4,
    "saldo_anterior": 20,
    "saldo_resultante": 16,
    "lote": "L-2026-09",
    "justificacion": "Productos con fecha de vencimiento cumplida retirados de anaquel principal",
    "responsable": "Administrador Principal"
  }
}
```

---

### 3.2 Registro de Ajuste de Stock por Conteo Físico
- **Ruta:** `POST /api/inventario/ajustes`
- **Cuerpo de solicitud (`JSON`):**
```json
{
  "referencia_solicitud": "ajuste-20261025-4b19e002-0001",
  "id_producto": 12,
  "tipo_ajuste": "Ajuste negativo",
  "cantidad": 2,
  "saldo_anterior_esperado": 16,
  "justificacion": "Diferencia detectada en conteo físico de anaquel A2"
}
```

**Reglas de validación de campos:**
| Campo | Tipo | Obligatorio | Control Frontend Asociado | Reglas de Validación |
|---|---|---|---|---|
| `referencia_solicitud` | `string` | Sí | Generado en `inventario.js` | Cadena de 16 a 64 caracteres (`^[A-Za-z0-9\-_]{16,64}$`). Única por `(id_usuario, 'AJUSTE', referencia_solicitud)`. |
| `id_producto` | `int` | Sí | `#stock-adjustment-product` | Entero $> 0$. El producto debe existir y estar activo (`estado = 1`). |
| `tipo_ajuste` | `string` | Sí | `input[name="stock_adjustment_direction"]` | `"Ajuste positivo"` (o `"entry"`) / `"Ajuste negativo"` (o `"exit"`). Se normaliza a `"Ajuste positivo"` / `"Ajuste negativo"`. |
| `cantidad` | `int` | Sí | `#stock-adjustment-quantity` | Entero estricto $\ge 1$. En `"Ajuste negativo"`, debe ser $\le$ `stock_actual`. |
| `saldo_anterior_esperado` | `int` | Sí | Vista previa `#stock-adjustment-balance-before` | Entero $\ge 0$ correspondiente al `stock_actual` que el administrador visualizó antes de confirmar (CA-08.4). |
| `justificacion` | `string` | **Sí (Siempre)** | `#stock-adjustment-justification` | **Obligatoria en ajustes positivos y negativos.** Texto no vacío tras `trim()` (máx. 255 caracteres). *(Se acepta `motivo` como alias de entrada, pero el campo canónico de solicitud, respuesta y `errors` es `justificacion`).* |

**Respuesta exitosa en primera confirmación (`201 Created`):**
```json
{
  "status": true,
  "code": "ADJUSTMENT_CREATED",
  "idempotent_replay": false,
  "message": "Ajuste de stock registrado correctamente.",
  "data": {
    "id_operacion": 2,
    "id_kardex": 102,
    "referencia_solicitud": "ajuste-20261025-4b19e002-0001",
    "fecha_hora": "2026-10-25 13:18:10",
    "id_producto": 12,
    "producto": "Aceite Vegetal 1L",
    "codigo_barra": "775010000012",
    "categoria": "Abarrotes",
    "subcategoria": "General",
    "unidad": "LTR",
    "tipo_movimiento": "Ajuste negativo",
    "sentido": "DISMINUCION",
    "cantidad": 2,
    "saldo_anterior": 16,
    "saldo_resultante": 14,
    "lote": "Sin lote",
    "justificacion": "Diferencia detectada en conteo físico de anaquel A2",
    "responsable": "Administrador Principal"
  }
}
```

---

### 3.3 Respuestas de Error en Bajas y Ajustes

1. **Errores de validación por campo (`422 Unprocessable Entity`):**
   - Nombres de claves en `errors` unificados con los campos del contrato (`id_producto`, `motivo_baja`, `tipo_ajuste`, `cantidad`, `justificacion`, `lote`, `saldo_anterior_esperado`, `referencia_solicitud`):
```json
{
  "status": false,
  "code": "VALIDATION_ERROR",
  "message": "Verifique los datos ingresados en el formulario.",
  "errors": {
    "cantidad": "La cantidad debe ser un número entero mayor o igual a 1.",
    "justificacion": "Debe ingresar la justificación del movimiento."
  }
}
```

2. **Stock insuficiente (`409 Conflict`):**
```json
{
  "status": false,
  "code": "INSUFFICIENT_STOCK",
  "message": "Stock insuficiente. El producto dispone actualmente de 3 unidades y se intentó descontar 5.",
  "errors": {
    "cantidad": "La cantidad supera el stock disponible (3 unidades)."
  },
  "data": {
    "id_producto": 12,
    "stock_actual": 3
  }
}
```

3. **Saldo base modificado concurrentemente antes de confirmar ajuste (`409 Conflict` — CA-08.4):**
```json
{
  "status": false,
  "code": "STOCK_CHANGED_CONFLICT",
  "message": "El stock del producto cambió de 16 a 14 unidades mientras preparaba el ajuste. Revise el nuevo saldo antes de confirmar.",
  "errors": {
    "saldo_anterior_esperado": "El saldo vigente cambió a 14 unidades."
  },
  "data": {
    "id_producto": 12,
    "saldo_anterior_esperado": 16,
    "stock_actual": 14,
    "requiere_revision": true
  }
}
```

---

## 4. Compatibilidad de Catálogos Compartidos (`/api/productos` y `/api/categorias`)

Para no afectar al rol Vendedor (`id_rol = 2`) ni al POS (`Views/Venta/*`), ni a los buscadores existentes de `productos.js`, `inventario.js` y `kardex.js`:

### 4.1 `GET /api/productos`
- **Modo estándar (por defecto, sin `modo=reporte`):**
  - **Permiso:** `productos.listar` (accesible a Administrador y Vendedor).
  - **Comportamiento y respuesta:** Conserva **exactamente** la estructura actual de [`ProductoApiController.php`](../../Controllers/API/ProductoApiController.php#L79-L88) (`data` como arreglo directo de productos activos con `categoria`, `subcategoria`, `unidad` y `pagination: { page, per_page, total, total_pages }`).
- **Modo reporte (`GET /api/productos?modo=reporte&q=...`):**
  - **Permiso:** Exclusivo de **Administrador activo** (`id_rol = 1`, `estado = 1`). Si un Vendedor invoca `modo=reporte`, responde `403 FORBIDDEN_ADMIN_ONLY`.
  - **Comportamiento:** Incluye productos activos e **inactivos que posean historial de movimientos en `kardex`**, exponiendo `"estado": 0` y `"etiqueta_estado": "Inactivo"` para que puedan seleccionarse en Kardex y Variaciones:
  ```json
  {
    "status": true,
    "data": [
      {
        "id_producto": 12,
        "codigo_barra": "775010000012",
        "nombre": "Aceite Vegetal 1L",
        "id_categoria": 1,
        "categoria": "Abarrotes",
        "id_subcategoria": 1,
        "subcategoria": "General",
        "id_unidad": 3,
        "unidad": "LTR",
        "stock_actual": 14,
        "stock_minimo": 5,
        "precio_venta": "9.50",
        "estado": 1,
        "etiqueta_estado": "Activo"
      }
    ],
    "pagination": {
      "page": 1,
      "per_page": 20,
      "total": 1,
      "total_pages": 1
    }
  }
  ```

### 4.2 `GET /api/categorias`
- **Modo estándar (por defecto, sin `modo=reporte`):**
  - **Permiso:** `productos.listar` (accesible a Administrador y Vendedor).
  - **Respuesta:** Conserva **exactamente** la estructura actual de [`CategoriaApiController.php`](../../Controllers/API/CategoriaApiController.php#L36-L41) (`{ "status": true, "data": [ ... ] }`).
- **Modo reporte (`GET /api/categorias?modo=reporte`):**
  - **Permiso:** Exclusivo de **Administrador activo** (`id_rol = 1`, `estado = 1`).
  - **Respuesta:** Devuelve las categorías y su arreglo anidado `subcategorias` aplicables a los filtros de reportes:
  ```json
  {
    "status": true,
    "data": [
      {
        "id_categoria": 1,
        "nombre": "Abarrotes",
        "estado": 1,
        "subcategorias": [
          {
            "id_subcategoria": 1,
            "id_categoria": 1,
            "nombre": "General"
          }
        ]
      }
    ]
  }
  ```

---

## 5. Especificación de Endpoints de Reportes y Exportaciones

### 5.1 Consulta del Kardex (`GET /api/reportes/kardex`)

**Parámetros Query (`GET`):**
| Parámetro | Tipo | Obligatorio | Descripción |
|---|---|---|---|
| `fecha_inicio` | `YYYY-MM-DD` | No (defecto: `Y-m-01`) | Fecha inicial del período ($\le$ `fecha_fin`). |
| `fecha_fin` | `YYYY-MM-DD` | No (defecto: `Y-m-d`) | Fecha final del período. |
| `id_producto` | `int` | No | ID del producto específico (activo o inactivo con historial). |
| `id_categoria` | `int` | No | Filtra **exclusivamente por la categoría histórica** registrada en el movimiento (`k.id_categoria_snapshot`). |
| `id_subcategoria` | `int` | No | Filtra **exclusivamente por la subcategoría histórica** registrada en el movimiento (`k.id_subcategoria_snapshot`). |
| `page` | `int` | No (defecto: `1`) | Página solicitada ($\ge 1$). |
| `limit` | `int` | No (defecto: `10`, máx. `100`) | Registros por página. |
| `referencia_consulta` | `string` | No | Token devuelto en la consulta inicial para paginar o exportar sobre el mismo conjunto congelado. |

**Respuesta (`200 OK`):**
```json
{
  "status": true,
  "data": {
    "referencia_consulta": "rc-20261025-9a4d2e1b8c7f4a10",
    "expira_en": "2026-10-25 13:50:00",
    "filtros_aplicados": {
      "fecha_inicio": "2026-10-01",
      "fecha_fin": "2026-10-25",
      "id_producto": 12,
      "id_categoria": null,
      "id_subcategoria": null
    },
    "resumen_producto": {
      "aplicable": true,
      "id_producto": 12,
      "producto": "Aceite Vegetal 1L",
      "codigo_barra": "775010000012",
      "estado_producto": "Activo",
      "unidad_inventario": "LTR",
      "saldo_inicial": 0,
      "total_entradas": 48,
      "total_salidas": 6,
      "saldo_final": 42,
      "total_movimientos": 3
    },
    "exportable": true,
    "items": [
      {
        "id_kardex": 100,
        "fecha_hora": "2026-10-25 10:00:00",
        "id_producto": 12,
        "codigo_barra": "775010000012",
        "producto_nombre": "Aceite Vegetal 1L",
        "categoria_nombre": "Abarrotes",
        "subcategoria_nombre": "General",
        "unidad_abreviatura": "LTR",
        "origen": "Compra",
        "tipo_movimiento": "Compra",
        "referencia_operacion": "F001-000125",
        "entrada": 48,
        "salida": null,
        "saldo_anterior": 0,
        "saldo_resultante": 48,
        "responsable": "Administrador Principal",
        "lote": "L-2026-09",
        "justificacion": "Ingreso por compra confirmada (2 Caja x 24 = 48 unidades)"
      }
    ],
    "pagination": {
      "total": 3,
      "page": 1,
      "limit": 10,
      "pages": 1
    }
  }
}
```
> **Reglas de `resumen_producto` y `exportable` en Kardex:**
> - Cuando se filtra un `id_producto` específico, `resumen_producto.aplicable = true` y **`exportable = true` incluso si `total_movimientos = 0` en el período** (en cuyo caso `saldo_inicial` es el saldo real al inicio del período, `total_entradas = 0`, `total_salidas = 0` y `saldo_final = saldo_inicial`).
> - Cuando **no** se filtra un `id_producto` específico (Kardex general), `resumen_producto.aplicable = false` (no se suman saldos entre productos distintos) y `exportable` es `true` únicamente si `pagination.total > 0`.

---

### 5.2 Consulta del Reporte de Variaciones (`GET /api/reportes/variaciones`)

Incluye **exclusivamente** movimientos con `tipo_movimiento` en:
- `"Baja por daño"` (`sentido = "DISMINUCION"`)
- `"Baja por vencimiento"` (`sentido = "DISMINUCION"`)
- `"Ajuste positivo"` (`sentido = "INCREMENTO"`)
- `"Ajuste negativo"` (`sentido = "DISMINUCION"`)

Excluye compras, ventas y sus correcciones o anulaciones.

**Parámetros Query (`GET`):**
| Parámetro | Tipo | Obligatorio | Control Frontend Asociado | Descripción |
|---|---|---|---|---|
| `fecha_inicio` | `YYYY-MM-DD` | Sí | `#variation-start-date` | Fecha inicial obligatoria ($\le$ `fecha_fin`). |
| `fecha_fin` | `YYYY-MM-DD` | Sí | `#variation-end-date` | Fecha final obligatoria. |
| `id_producto` | `int` | No | `#variation-product` | Filtra por ID de producto específico (activo o inactivo con historial). |
| `q` | `string` | No | `#variation-product` | Término de búsqueda por nombre o código de barras histórico (`producto_nombre_snapshot` / `producto_codigo_snapshot`). |
| `id_categoria` | `int` | No | `#variation-category` | Filtra **exclusivamente por la categoría histórica** registrada en el movimiento (`k.id_categoria_snapshot`). |
| `id_subcategoria` | `int` | No | `#variation-subcategory` | Filtra **exclusivamente por la subcategoría histórica** registrada en el movimiento (`k.id_subcategoria_snapshot`). |
| `tipo_variacion` | `string` | No (defecto: `""`) | `#variation-type` | `""` (Todas), `"Ajuste negativo"`, `"Ajuste positivo"`, `"Baja por daño"`, `"Baja por vencimiento"`. |
| `sentido` | `string` | No (defecto: `"TODOS"`) | Nuevo selector `#variation-direction` (“Sentido del movimiento”) | `"TODOS"` (opción visible `Todas`), `"INCREMENTO"` (opción visible `Entradas`), `"DISMINUCION"` (opción visible `Salidas`). |
| `page` | `int` | No (defecto: `1`) | `#variation-pagination` | Página solicitada ($\ge 1$). |
| `limit` | `int` | No (defecto: `10`, máx. `100`) | — | Registros por página. |
| `referencia_consulta` | `string` | No | Estado en `variaciones.js` | Token devuelto en la consulta inicial para mantener consistencia al paginar o exportar. |

> **Combinación estricta mediante `AND`:**
> Todos los filtros (`fecha_inicio`, `fecha_fin`, `id_producto`, `q`, `id_categoria`, `id_subcategoria`, `tipo_variacion` y `sentido`) se combinan con el operador lógico `AND`.
> Si el usuario selecciona una combinación válida pero mutuamente excluyente o sin coincidencias (por ejemplo, `tipo_variacion = "Baja por vencimiento"` y `sentido = "INCREMENTO"`), el servidor **no altera ningún filtro** y devuelve `items: []`, `total_operaciones: 0` y `exportable: false`.

**Respuesta (`200 OK`):**
```json
{
  "status": true,
  "data": {
    "referencia_consulta": "rc-20261025-7c1b3e90a1124f08",
    "expira_en": "2026-10-25 13:55:00",
    "filtros_aplicados": {
      "fecha_inicio": "2026-10-01",
      "fecha_fin": "2026-10-25",
      "id_producto": null,
      "q": "",
      "id_categoria": null,
      "id_subcategoria": null,
      "tipo_variacion": "",
      "sentido": "TODOS"
    },
    "resumen": {
      "total_operaciones": 2,
      "productos_con_variaciones": 1,
      "es_producto_unico": true,
      "resumen_producto_unico": {
        "id_producto": 12,
        "codigo_barra": "775010000012",
        "producto_nombre": "Aceite Vegetal 1L",
        "operaciones": 2,
        "incrementos": 0,
        "disminuciones": 6
      },
      "resumen_por_producto": [
        {
          "id_producto": 12,
          "codigo_barra": "775010000012",
          "producto_nombre": "Aceite Vegetal 1L",
          "operaciones": 2,
          "incrementos": 0,
          "disminuciones": 6
        }
      ],
      "totales_por_tipo": {
        "baja_por_dano": { "operaciones": 0, "unidades": 0 },
        "baja_por_vencimiento": { "operaciones": 1, "unidades": 4 },
        "ajuste_positivo": { "operaciones": 0, "unidades": 0 },
        "ajuste_negativo": { "operaciones": 1, "unidades": 2 }
      }
    },
    "exportable": true,
    "items": [
      {
        "id_kardex": 101,
        "fecha_hora": "2026-10-25 13:15:22",
        "id_producto": 12,
        "codigo_barra": "775010000012",
        "producto_nombre": "Aceite Vegetal 1L",
        "categoria_nombre": "Abarrotes",
        "subcategoria_nombre": "General",
        "unidad_abreviatura": "LTR",
        "tipo_variacion": "Baja por vencimiento",
        "sentido": "DISMINUCION",
        "entrada": null,
        "salida": 4,
        "justificacion": "Productos con fecha de vencimiento cumplida retirados de anaquel principal",
        "saldo_anterior": 20,
        "saldo_resultante": 16,
        "responsable": "Administrador Principal",
        "lote": "L-2026-09"
      },
      {
        "id_kardex": 102,
        "fecha_hora": "2026-10-25 13:18:10",
        "id_producto": 12,
        "codigo_barra": "775010000012",
        "producto_nombre": "Aceite Vegetal 1L",
        "categoria_nombre": "Abarrotes",
        "subcategoria_nombre": "General",
        "unidad_abreviatura": "LTR",
        "tipo_variacion": "Ajuste negativo",
        "sentido": "DISMINUCION",
        "entrada": null,
        "salida": 2,
        "justificacion": "Diferencia detectada en conteo físico de anaquel A2",
        "saldo_anterior": 16,
        "saldo_resultante": 14,
        "responsable": "Administrador Principal",
        "lote": "Sin lote"
      }
    ],
    "pagination": {
      "total": 2,
      "page": 1,
      "limit": 10,
      "pages": 1
    }
  }
}
```

> **Correspondencia directa con los elementos de [`Views/Reporte/variaciones.php`](../../Views/Reporte/variaciones.php#L55-L83):**
> - `#variation-total-operations` $\leftarrow$ `resumen.total_operaciones`
> - `#variation-product-count` $\leftarrow$ `resumen.productos_con_variaciones`
> - `#variation-product-breakdown` $\leftarrow$ `resumen.resumen_por_producto` (columnas: `Producto`, `Operaciones`, `Incrementos`, `Disminuciones`)
> - `#variation-single-product-summary` (cuando `resumen.es_producto_unico === true`) $\leftarrow$ `resumen.resumen_producto_unico` (`#variation-single-product-operations`, `#variation-single-product-increases` con `+N`, `#variation-single-product-decreases` con `−N`).

---

### 5.3 Exportación PDF y Excel (`GET /api/reportes/exportar-pdf` y `GET /api/reportes/exportar-excel`)

#### A. Separación de Parámetros (`tipo`, `tipo_variacion` y `sentido`)
- **`tipo` (obligatorio):** Identifica exclusivamente el reporte a exportar:
  - `tipo=kardex`
  - `tipo=variaciones`
  *(Conserva además compatibilidad con `tipo=compras` y `tipo=proveedores`).*
- **`referencia_consulta` (obligatorio para garantizar igualdad con la vista consultada):** Token vigente emitido por `/api/reportes/kardex` o `/api/reportes/variaciones`.
- **Parámetros de filtro verificados contra `referencia_consulta`:**
  - En `tipo=kardex`: `fecha_inicio`, `fecha_fin`, `id_producto`, `id_categoria`, `id_subcategoria`.
  - En `tipo=variaciones`: `fecha_inicio`, `fecha_fin`, `id_producto`, `q`, `id_categoria`, `id_subcategoria`, `tipo_variacion` (`""`, `"Ajuste negativo"`, `"Ajuste positivo"`, `"Baja por daño"`, `"Baja por vencimiento"`) y `sentido` (`"TODOS"`, `"INCREMENTO"`, `"DISMINUCION"`).

**Ejemplos de invocación:**
- Kardex PDF:
  `GET /api/reportes/exportar-pdf?tipo=kardex&referencia_consulta=rc-20261025-9a4d2e1b8c7f4a10&fecha_inicio=2026-10-01&fecha_fin=2026-10-25&id_producto=12`
- Variaciones Excel filtrando bajas por vencimiento y salidas:
  `GET /api/reportes/exportar-excel?tipo=variaciones&referencia_consulta=rc-20261025-7c1b3e90a1124f08&fecha_inicio=2026-10-01&fecha_fin=2026-10-25&tipo_variacion=Baja%20por%20vencimiento&sentido=DISMINUCION`

#### B. Reglas de Exportación con y sin Movimientos
1. **Kardex de un producto específico (`id_producto` presente) sin movimientos en el período (`total_movimientos = 0`):**
   - **SÍ se permite exportar** tanto en PDF como en Excel (`200 OK`, descarga de archivo).
   - El archivo incluye los datos del producto, el bloque de resumen con el **saldo inicial real** al inicio del período, `Entradas = 0`, `Salidas = 0` y **saldo final (cierre) igual al saldo inicial**, y una fila informativa en la tabla indicando que no hubo movimientos en el rango consultado.
2. **Kardex general (`id_producto` vacío) sin resultados (`total = 0`):**
   - **NO se permite exportar.** El servidor responde `422 Unprocessable Entity`:
   ```json
   {
     "status": false,
     "code": "EMPTY_EXPORT_NOT_ALLOWED",
     "message": "No existen movimientos de Kardex para los filtros seleccionados. Seleccione un producto específico si desea exportar su estado de saldo sin movimientos."
   }
   ```
3. **Reporte de Variaciones sin resultados (`total_operaciones = 0`):**
   - **NO se permite exportar.** El servidor responde `422 Unprocessable Entity`:
   ```json
   {
     "status": false,
     "code": "EMPTY_EXPORT_NOT_ALLOWED",
     "message": "No existen variaciones para los filtros seleccionados; no es posible generar el archivo de exportación."
   }
   ```


---

## 6. Correspondencia entre el Contrato API y el Esquema Físico (Fase 2)

En cumplimiento de la Fase 2, se documenta la correspondencia exacta entre los campos del contrato API y las tablas/columnas físicas creadas en `database/segundo_sprint/01_estructura_inventario_sprint2.sql` y `02_configuracion_permisos_sprint2.sql`:

| Entidad / Concepto del Contrato | Tabla Física | Columnas y Restricciones Físicas |
|---|---|---|
| **Subcategoría de catálogo y filtro dependiente** (`id_subcategoria`, `subcategoria`, `subcategorias[]`) | `subcategoria`, `producto` | `subcategoria(id_subcategoria, id_categoria, nombre, descripcion, estado)` con `uq_subcategoria_cat_nombre (id_categoria, nombre)` y `uq_subcategoria_id_cat (id_subcategoria, id_categoria)`. `producto.id_subcategoria` con FK compuesta `fk_producto_subcategoria_categoria (id_subcategoria, id_categoria)` que garantiza correspondencia estricta con `producto.id_categoria`. |
| **Cantidades enteras y saldos no negativos** (`stock_actual`, `stock_minimo`) | `producto` | `producto.stock_actual INT(11) NOT NULL DEFAULT 0`, `producto.stock_minimo INT(11) NOT NULL DEFAULT 1`, protegidos por `chk_producto_stock_entero_no_negativo (stock_actual >= 0 AND stock_minimo >= 0 AND precio_venta > 0)`. |
| **Presentaciones de compra y versionado de líneas** (`cantidad_presentaciones`, `equivalencia_aplicada`, `cantidad_base`) | `detalle_compra` | `detalle_compra(version_detalle, es_vigente, id_detalle_origen, reemplazado_en)` con FK `fk_detalle_compra_origen` y `chk_detalle_compra_integridad (cantidad_base = cantidad_presentaciones * equivalencia_aplicada AND cantidad_base >= 1)`. |
| **Registro de Bajas y Ajustes** (`id_operacion`, `motivo`/`tipo`, `sentido`, `cantidad`, `saldo_anterior`, `saldo_anterior_esperado`, `saldo_resultante`, `justificacion`, `lote`, `referencia_solicitud`) | `operacion_inventario` | `operacion_inventario(id_operacion, id_producto, id_usuario, categoria_operacion ['BAJA','AJUSTE'], origen_operacion ['BAJA_DANO','BAJA_VENCIMIENTO','AJUSTE_POSITIVO','AJUSTE_NEGATIVO'], tipo_movimiento, sentido_movimiento ['INCREMENTO','DISMINUCION'], cantidad, saldo_anterior, saldo_anterior_esperado, saldo_resultante, justificacion, numero_lote, condicion_lote ['CON_LOTE','SIN_LOTE','NO_APLICA'], referencia_solicitud UNIQUE, fecha_registro)` con `chk_op_inv_integridad`. |
| **Movimientos de Kardex y Variaciones + Clasificación histórica inmutable** (`id_kardex`, `fecha_hora`, `origen_operacion`, `tipo_movimiento`, `sentido`, `entrada`/`salida`, `saldo_anterior`, `saldo_resultante`, `justificacion`, `lote`, `responsable`, snapshots de producto/categoría/subcategoría/unidad) | `kardex` | `kardex` ampliada con `origen_operacion`, `sentido_movimiento`, `id_detalle_venta`, `id_operacion_inventario`, `referencia_operacion`, `justificacion`, `numero_lote`, `condicion_lote`, y columnas snapshot: `producto_codigo_snapshot`, `producto_nombre_snapshot`, `id_categoria_snapshot`, `categoria_nombre_snapshot`, `id_subcategoria_snapshot`, `subcategoria_nombre_snapshot`, `id_unidad_snapshot`, `unidad_abreviatura_snapshot`, `responsable_nombre_snapshot`, `responsable_rol_snapshot`. Protegida por `chk_kardex_integridad`, FKs `ON DELETE RESTRICT` y triggers `trg_kardex_before_insert_integridad` y `trg_kardex_before_update_inmutable`. |
| **Control de solicitudes repetidas y simultáneas** (`referencia_solicitud`, `idempotent_replay`, `OPERATION_ALREADY_CONFIRMED`, `IDEMPOTENCY_PAYLOAD_MISMATCH`, `REQUEST_IN_PROGRESS`) | `control_solicitud_idempotencia` | `control_solicitud_idempotencia(id_solicitud, id_usuario, tipo_operacion, referencia_solicitud, hash_payload, estado_solicitud ['EN_PROCESO','CONFIRMADA','FALLIDA'], id_registro_recurso, codigo_http, respuesta_json, creado_en, actualizado_en)` con clave única `uq_ctrl_idemp_usuario_tipo_ref (id_usuario, tipo_operacion, referencia_solicitud)` y bloqueo en memoria `GET_LOCK(..., 0)` gestionado por `Services\ControlSolicitudService`. |
| **Consistencia entre consulta paginada y exportación PDF/Excel** (`referencia_consulta`) | `reporte_consulta_snapshot`, `reporte_consulta_snapshot_item` | `reporte_consulta_snapshot(id_snapshot, referencia_consulta UNIQUE, tipo_reporte ['KARDEX','VARIACIONES'], id_usuario, hash_filtros, filtros_json, resumen_json, total_registros, exportable, ultimo_id_kardex, creado_en, expira_en)` y `reporte_consulta_snapshot_item(id_snapshot, orden_fila, id_kardex)`. |
