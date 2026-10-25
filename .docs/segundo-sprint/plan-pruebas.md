# Plan de Pruebas y Matriz de Trazabilidad — Sprint 2

**Proyecto:** Amazon-Market  
**Base de datos exclusiva de pruebas (Fases 1–9):** `amazon_market_sprint2_test`  
**Bootstrap de aislamiento:** [`tests/sprint2/bootstrap_test.php`](../../tests/sprint2/bootstrap_test.php)  
**Estado de ejecución:** En Fase 1 se verificaron respaldo, restauración, aislamiento de conexión y escenario limpio. En **Fase 2** se ejecutó y aprobó la suite automatizada [`tests/sprint2/fase2_infraestructura_test.php`](../../tests/sprint2/fase2_infraestructura_test.php) (`24 PASS / 0 FAIL`). En **Fase 3** se ejecutó y aprobó la suite automatizada [`tests/sprint2/fase3_productos_compras_test.php`](../../tests/sprint2/fase3_productos_compras_test.php) (`13 PASS / 0 FAIL`). En **Fase 4** se ejecutó y aprobó la suite automatizada [`tests/sprint2/fase4_ventas_kardex_test.php`](../../tests/sprint2/fase4_ventas_kardex_test.php) (`14 PASS / 0 FAIL`). En **Fase 5** se ejecutó y aprobó la suite automatizada [`tests/sprint2/fase5_bajas_ajustes_test.php`](../../tests/sprint2/fase5_bajas_ajustes_test.php) (`14 PASS / 0 FAIL`) cubriendo Bajas por daño y vencimiento (con y sin lote), validación estricta de motivo y justificación obligatoria, Ajustes positivos y negativos por conteo físico, rechazo con 409 `STOCK_CHANGED_CONFLICT` ante saldo base modificado, rechazo de salidas que generen negativos, serialización FOR UPDATE entre salidas simultáneas, rollback integral ante fallo, idempotencia por `referencia_solicitud` y restricción exclusiva a Administradores activos. Los casos específicos de las Fases 6 a 10 permanecen pendientes para sus respectivas fases.

---

## 1. Estrategia para Escenarios de Prueba Reproducibles (Tarea 9)

1. **Separación estricta entre migraciones y datos artificiales:**
   - Los scripts en `database/segundo_sprint/` contienen exclusivamente preparación de escenario limpio (`00`), estructura (`01`), configuración de permisos (`02`) y actualización controlada final (`10`).
   - Ningún dato artificial de prueba se incluye en archivos SQL de migración ni de configuración.
2. **Sembrado reproducible desde `stock_actual = 0` sobre `amazon_market_sprint2_test`:**
   - Todas las pruebas automatizadas incluyen `tests/sprint2/bootstrap_test.php`, el cual garantiza la conexión a `amazon_market_sprint2_test` y aborta si la base activa es distinta.
   - Antes de cada suite se restablece el estado limpio en `amazon_market_sprint2_test` y se generan los movimientos invocando los servicios transaccionales reales (`CompraService`, `VentaService`, `InventarioService`), de modo que cada unidad de stock esté respaldada en `kardex`.

---

## 2. Matriz de Casos de Prueba por Historia, Integración y Rendimiento

### 2.1 HU-07: Registro de Bajas de Inventario (Fase 5)

| ID Prueba | Fase | Req. / Criterio | Escenario / Condición de Entrada | Resultado Esperado | Estado |
|---|---|---|---|---|---|
| **CP-HU07-01** | Fase 5 | `RF-07.1` / `CA-07.1` | Administrador activo registra `Baja por daño` de 4 unidades sobre producto con `stock_actual = 20`, `lote = "L-01"` y `justificacion` válida. | `201 Created`; `producto.stock_actual` pasa a `16`; se inserta fila en `kardex` con `saldo_anterior = 20`, `salida = 4`, `saldo_resultante = 16`, `lote = "L-01"`, `justificacion`, responsable y fecha/hora del servidor. | **Aprobado en Fase 5** (`F5-BAJA-01`) |
| **CP-HU07-02** | Fase 5 | `RF-07.1` / `CA-07.3` | Administrador activo registra `Baja por vencimiento` de 5 unidades con `justificacion` válida. | `201 Created`; `stock_actual` se reduce en 5 unidades y el movimiento queda trazado en `kardex` con su `justificacion`. | **Aprobado en Fase 5** (`F5-BAJA-02`) |
| **CP-HU07-03** | Fase 5 | `RN-02` / `CA-07.3` | Registro de `Baja por daño` **o** `Baja por vencimiento` con `justificacion` vacía, omitida o compuesta solo por espacios en blanco. | `422 Unprocessable Entity` con error en `errors.justificacion` en **ambos** motivos (`Baja por daño` y `Baja por vencimiento`); no se modifica `stock_actual` ni `kardex`. | **Aprobado en Fase 5** (`F5-BAJA-03`) |
| **CP-HU07-04** | Fase 5 | `RN-03` / `CA-07.2` | Registro de baja con `cantidad = 15` cuando `stock_actual = 10` (o `stock_actual = 0`). | `409 Conflict` (`INSUFFICIENT_STOCK`) informando `stock_actual = 10`; no se descuenta stock ni se crea movimiento. | **Aprobado en Fase 5** (`F5-BAJA-04`) |
| **CP-HU07-05** | Fase 5 | `CL-07.1` / `CA-07.2` | Registro de baja con `cantidad = 10` cuando `stock_actual = 10` (consumo exacto a cero). | `201 Created`; `stock_actual` queda en `0` y `saldo_resultante = 0` en `kardex`. | **Aprobado en Fase 5** (`F5-BAJA-01`) |
| **CP-HU07-06** | Fase 5 | `RN-05` / `CL-07.4` | Registro de baja con `cantidad` igual a `0`, `-3`, `2.5` o `"abc"`. | `422 Unprocessable Entity` con error en `errors.cantidad` exigiendo entero $\ge 1$. | **Aprobado en Fase 5** (`F5-BAJA-04`) |
| **CP-HU07-07** | Fase 5 | `RN-04` / `CA-07.4` | Registro de baja dejando el campo `lote` vacío. | `201 Created`; se guarda `lote = NULL` y se expone como `"Sin lote"` sin bloquear ni afectar otros registros. | **Aprobado en Fase 5** (`F5-BAJA-02`) |
| **CP-HU07-08** | Fase 5 | `CL-07.5` | Intento de registrar baja sobre un producto con `estado = 0` (inactivo). | `422 Unprocessable Entity` rechazando la operación por producto inactivo. | **Aprobado en Fase 5** (`F5-BAJA-01`) |

---

### 2.2 HU-08: Registro de Ajustes por Conteo Físico (Fase 5)

| ID Prueba | Fase | Req. / Criterio | Escenario / Condición de Entrada | Resultado Esperado | Estado |
|---|---|---|---|---|---|
| **CP-HU08-01** | Fase 5 | `RF-08.1` / `CA-08.1` | Administrador registra `Ajuste positivo` de 6 unidades sobre producto con `stock_actual = 10`, `saldo_anterior_esperado = 10` y `justificacion` válida. | `201 Created`; `stock_actual` pasa a `16`; `kardex` registra `entrada = 6`, `saldo_anterior = 10`, `saldo_resultante = 16` y `justificacion`. | **Aprobado en Fase 5** (`F5-AJUSTE-01`) |
| **CP-HU08-02** | Fase 5 | `RF-08.1` / `CA-08.1` | Administrador registra `Ajuste negativo` de 3 unidades sobre producto con `stock_actual = 16`, `saldo_anterior_esperado = 16` y `justificacion` válida. | `201 Created`; `stock_actual` pasa a `13`; `kardex` registra `salida = 3`, `saldo_anterior = 16`, `saldo_resultante = 13` y `justificacion`. | **Aprobado en Fase 5** (`F5-AJUSTE-02`) |
| **CP-HU08-03** | Fase 5 | `RN-06` / `CA-08.2` | Ajuste positivo o negativo con `justificacion` vacía o solo espacios en blanco. | `422 Unprocessable Entity` con error en `errors.justificacion`; no se altera el stock. | **Aprobado en Fase 5** (`F5-BAJA-03`, `F5-AJUSTE-01`) |
| **CP-HU08-04** | Fase 5 | `RN-07` / `CA-08.3` | Ajuste negativo con `cantidad = 20` cuando `stock_actual = 13` (y `saldo_anterior_esperado = 13`). | `409 Conflict` (`INSUFFICIENT_STOCK`); el saldo nunca queda negativo. | **Aprobado en Fase 5** (`F5-AJUSTE-04`) |
| **CP-HU08-05** | Fase 5 | `CL-08.1` | Ajuste negativo con `cantidad = 13` cuando `stock_actual = 13`. | `201 Created`; `stock_actual` queda en `0` y `saldo_resultante = 0`. | **Aprobado en Fase 5** (`F5-AJUSTE-02`) |
| **CP-HU08-06** | Fase 5 | `CA-08.4` / `CL-08.3` | El administrador prepara ajuste con `saldo_anterior_esperado = 16`, pero otra operación concurrente dejó `stock_actual = 14` antes de confirmar. | `409 Conflict` (`STOCK_CHANGED_CONFLICT`) devolviendo `stock_actual = 14` y `requiere_revision = true` sin aplicar el ajuste a ciegas. | **Aprobado en Fase 5** (`F5-AJUSTE-03`) |
| **CP-HU08-07** | Fase 5 | `RN-05` / `CL-08.2` | Ajuste con `cantidad = 0`, negativa o decimal (`1.5`). | `422 Unprocessable Entity` en `errors.cantidad` exigiendo entero $\ge 1$. | **Aprobado en Fase 5** (`F5-BAJA-04`) |

---

### 2.2.1 HU-Productos y HU-Compras: Coherencia con el Inventario (Fase 3)

| ID Prueba | Fase | Req. / Criterio | Escenario / Condición de Entrada | Resultado Esperado | Estado |
|---|---|---|---|---|---|
| **CP-PROD-01** | Fase 3 | `Acuerdo 8` / `RN-01` | Registro de producto nuevo intentando inyectar `stock_actual = 50`. | `201 Created`; el producto se inserta estrictamente con `stock_actual = 0` y `stock_minimo >= 0` entero; subcategoría validada. | **Aprobado en Fase 3** (`F3-PROD-01`) |
| **CP-PROD-02** | Fase 3 | `Acuerdo 8` / `RN-01` | Edición de producto enviando `stock_actual = 999` en el cuerpo de la petición. | `200 OK`; se actualizan los datos de catálogo, pero `stock_actual` permanece inalterado (el campo se omite en la consulta de actualización). | **Aprobado en Fase 3** (`F3-PROD-02`) |
| **CP-COMP-01** | Fase 3 | `Caso Obligatorio 1` | Compra de 2 cajas de presentación x 24 unidades individuales. | `201 Created`; `cantidad_base = 48`; se incrementa `stock_actual` en +48 y se registra movimiento canónico `COMPRA` en `kardex` con FKs completas. | **Aprobado en Fase 3** (`F3-COMP-01`) |
| **CP-COMP-02** | Fase 3 | `Caso Obligatorio 2` | Compra de 20 unidades, venta posterior de 15 (saldo = 5) y corrección de compra a 18 unidades. | `200 OK`; se calcula delta neto $\Delta = 18 - 20 = -2$; como hay 5 unidades, se descuentan 2 dejando `stock_actual = 3` sin error de stock negativo. | **Aprobado en Fase 3** (`F3-COMP-02`) |
| **CP-COMP-03** | Fase 3 | `Caso Obligatorio 3` | Corrección exclusiva de costo o datos de cabecera manteniendo las cantidades originales. | `200 OK`; $\Delta = 0$; se genera nueva versión de detalle (`version_detalle = 2`, `es_vigente = 1`), pero **no se inserta ningún movimiento en Kardex** ni se altera el stock. | **Aprobado en Fase 3** (`F3-COMP-03`) |
| **CP-COMP-04** | Fase 3 | `Caso Obligatorio 4` | Corrección con múltiples productos donde uno falla por stock insuficiente o producto inválido. | Excepción capturada; `ROLLBACK` integral; ningún producto sufre alteración de existencias y los detalles vigentes se mantienen intactos. | **Aprobado en Fase 3** (`F3-COMP-04`) |
| **CP-COMP-05** | Fase 3 | `Caso Obligatorio 5` | Compra original de 10 (v1), primera corrección a 14 (v2) y segunda corrección a 12 (v3). | `200 OK`; la segunda corrección evalúa $\Delta = 12 - 14 = -2$ contra la versión vigente v2, dejando saldo final exacto en 12. | **Aprobado en Fase 3** (`F3-COMP-05`) |
| **CP-COMP-06** | Fase 3 | `Caso Obligatorio 6` | Operaciones simultáneas sobre el mismo producto (compra +10 y venta -4) bajo serialización `FOR UPDATE`. | Ambas transacciones confirman en orden sin actualizaciones perdidas (`stock_resultante = 6`). | **Aprobado en Fase 3** (`F3-COMP-06`) |
| **CP-COMP-07** | Fase 3 | `Caso Obligatorio 7` | Intento de anular dos veces consecutivas la misma compra. | Primera anulación exitosa (`estado = "Anulada"`, reversión de existencias); segundo intento rechazado con excepción sin volver a restar stock. | **Aprobado en Fase 3** (`F3-COMP-07`) |
| **CP-COMP-08** | Fase 3 | `Integridad Reportes` | Generación de reporte de compras con historial de versiones corregidas. | `obtenerResumenCompras` y `obtenerReporteCompras` filtran `es_vigente = 1`; `compras_con_diferencias = 0`; no se duplican importes ni unidades. | **Aprobado en Fase 3** (`F3-REP-01`) |

---

### 2.3 Solicitudes Repetidas, Atomicidad, Fallos Intermedios y Concurrencia (Fases 2, 3, 4 y 5)

| ID Prueba | Fase | Req. / Criterio | Escenario / Condición de Entrada | Resultado Esperado | Estado |
|---|---|---|---|---|---|
| **CP-IDEM-01** | Fases 2–5 | `CL-07.3` / `CL-08.4` | Reenvío de una solicitud ya confirmada (`referencia_solicitud` idéntica, mismo usuario, misma operación y mismos datos). | `200 OK` (`OPERATION_ALREADY_CONFIRMED`, `idempotent_replay = true`) devolviendo el resultado original ya registrado **sin volver a descontar ni incrementar stock** y sin insertar otro movimiento en `kardex`. | **Aprobado en Fase 2** (`F2-IDEM-01`, `F2-IDEM-02` en infraestructura común; se revalidará en endpoints Fases 3–5) |
| **CP-IDEM-02** | Fases 2–5 | `Acuerdo 9` | Reenvío de la misma `referencia_solicitud` del usuario pero modificando datos del payload (p. ej. cambiando `cantidad` o `id_producto`). | `409 Conflict` (`IDEMPOTENCY_PAYLOAD_MISMATCH`) rechazando el conflicto sin ejecutar la operación. | **Aprobado en Fase 2** (`F2-IDEM-03` en infraestructura común) |
| **CP-IDEM-03** | Fases 2–5 | `Acuerdo 9` | Recepción de una segunda petición con la misma `referencia_solicitud` mientras la primera aún está en estado `EN_PROCESO`. | `409 Conflict` (`REQUEST_IN_PROGRESS`, `estado_solicitud = "EN_PROCESO"`) informando su estado sin duplicar la operación. | **Aprobado en Fase 2** (`F2-IDEM-04` con doble conexión PDO concurrente) |
| **CP-ATOM-01** | Fases 2–5 | `Acuerdo 9` / `RI-04` | Fallo intermedio inducido durante una compra, venta, baja o ajuste (p. ej. error al insertar en `kardex` tras haber ejecutado el `UPDATE` de `producto.stock_actual`). | `ROLLBACK` completo de la transacción: `producto.stock_actual` conserva su valor previo, no quedan cabeceras huérfanas y la solicitud queda marcada como `FALLIDA`. | **Aprobado en Fase 2** (`F2-MOV-03` en infraestructura transaccional común) |
| **CP-CONC-01** | Fases 2–5 | `Acuerdo 9` / `RNF` | Dos operaciones simultáneas intentan descontar 8 y 6 unidades de un mismo producto con `stock_actual = 10` (`SELECT ... FOR UPDATE`). | La primera transacción que obtiene el bloqueo confirma y deja `stock_actual = 2` (o `4`); la segunda lee el nuevo saldo bloqueado, detecta stock insuficiente (`409 INSUFFICIENT_STOCK`) y hace `ROLLBACK` sin perder actualizaciones ni dejar stock negativo. | **Aprobado en Fase 2** (`F2-MOV-01`, `F2-MOV-02`) |

---

### 2.4 HU-09: Consulta y Exportación del Kardex (Fases 6, 8 y 9)

| ID Prueba | Fase | Req. / Criterio | Escenario / Condición de Entrada | Resultado Esperado | Estado |
|---|---|---|---|---|---|
| **CP-HU09-01** | Fase 6 | `CA-09.1` / `RF-09.1` | Carga inicial de Kardex sin parámetros adicionales. | Consulta automáticamente desde el día 1 del mes en curso (`Y-m-01`) hasta la fecha actual (`Y-m-d`) y emite una `referencia_consulta`. | Pendiente |
| **CP-HU09-02** | Fase 6 | `CA-09.2` / `RN-09` | Consulta de Kardex de un producto específico con movimientos previos al rango (`saldo_inicial = 10`) y movimientos dentro del rango (`+48 compra`, `-5 venta`, `-3 baja`, `+2 ajuste pos`, `-1 ajuste neg`). | Las 5 tarjetas de `#kardex-product-summary` muestran `Unidad de inventario`, `Saldo inicial = 10`, `Entradas = 50`, `Salidas = 9`, `Saldo final = 51`, y cada fila enlaza `saldo_resultante` con el `saldo_anterior` siguiente. | Pendiente |
| **CP-HU09-03** | Fase 6 | `Acuerdo 15` | Consulta de Kardex general (sin seleccionar un producto específico). | Lista los movimientos paginados de todos los productos, oculta `#kardex-product-summary` (`resumen_producto.aplicable = false`) y **no suma existencias de productos diferentes** como un saldo global. | Pendiente |
| **CP-HU09-04** | Fase 6 | `Acuerdo 14` / `RF-09.2` | Verificación de orígenes incluidos en Kardex. | Incluye compras, anulaciones/correcciones de compra, ventas, anulaciones de venta, bajas por daño/vencimiento y ajustes positivos/negativos. | Pendiente |
| **CP-HU09-05** | Fase 6 | `Acuerdo 10` / `CA-09.6` | Se registra movimiento de un producto bajo Categoría A / Subcategoría A1; luego se cambia en catálogo a Categoría B / Subcategoría B1 y se desactiva (`estado = 0`). | Al filtrar en Kardex por Categoría A / Subcategoría A1 aparece el movimiento con su clasificación histórica A/A1; al filtrar por B/B1 no aparece ese movimiento previo; el producto inactivo es buscable en `GET /api/productos?modo=reporte`. | Pendiente |
| **CP-HU09-06** | Fases 6, 8 y 9 | **Exportación Kardex individual sin movimientos** | Producto específico (`id_producto = 12`) tiene `saldo_inicial = 25` al inicio del período y **0 movimientos** en el rango de fechas consultado; se solicita exportar PDF y Excel. | `exportable = true`; tanto `exportar-pdf?tipo=kardex` como `exportar-excel?tipo=kardex` generan el archivo (`200 OK`) mostrando el resumen del producto con `Saldo inicial = 25`, `Entradas = 0`, `Salidas = 0` y `Saldo final (cierre) = 25`. | Pendiente |
| **CP-HU09-07** | Fases 6, 8 y 9 | **Exportación Kardex general sin resultados** | Consulta de Kardex general (sin `id_producto`) en un rango donde `total = 0`; se intenta exportar PDF o Excel. | `exportable = false`; los endpoints de exportación devuelven `422 Unprocessable Entity` (`EMPTY_EXPORT_NOT_ALLOWED`). | Pendiente |

---

### 2.5 HU-10: Reporte de Variaciones y Nuevo Selector de Sentido (Fases 7, 8 y 9)

| ID Prueba | Fase | Req. / Criterio | Escenario / Condición de Entrada | Resultado Esperado | Estado |
|---|---|---|---|---|---|
| **CP-HU10-01** | Fase 7 | `Acuerdo 14` / `RN-12` | Existen en `kardex` compras, correcciones de compra, ventas, bajas por daño, bajas por vencimiento, ajustes positivos y ajustes negativos. | `GET /api/reportes/variaciones` devuelve **únicamente** bajas y ajustes, excluyendo compras, ventas y sus correcciones o anulaciones. | Pendiente |
| **CP-HU10-02** | Fase 7 | **Nuevo selector de sentido (`Todas`, `Entradas`, `Salidas`)** | En `Views/Reporte/variaciones.php` se verifica el selector “Sentido del movimiento”: 1) Valor inicial `Todas` (`sentido=TODOS`) muestra entradas y salidas; 2) `Entradas` (`sentido=INCREMENTO`) devuelve solo `Ajuste positivo`; 3) `Salidas` (`sentido=DISMINUCION`) devuelve `Ajuste negativo`, `Baja por daño` y `Baja por vencimiento`; 4) `Limpiar filtros` restablece a `Todas`. | Cada opción envía el parámetro `sentido` correspondiente y filtra correctamente tanto la tabla como el resumen. | Pendiente |
| **CP-HU10-03** | Fase 7 | **Combinaciones de `tipo_variacion` y `sentido` (`AND`)** | Se prueban combinaciones: 1) `tipo_variacion = "Ajuste positivo"` + `sentido = "INCREMENTO"` (devuelve ajustes positivos); 2) `tipo_variacion = "Baja por vencimiento"` + `sentido = "INCREMENTO"` (`Entradas`). | En la combinación 2 (`Baja por vencimiento` + `Entradas`), el servidor aplica `AND`, devuelve conjunto vacío (`items: []`, `total_operaciones: 0`, `exportable: false`) y **no modifica automáticamente ningún filtro**. | Pendiente |
| **CP-HU10-04** | Fase 7 | **Resúmenes reales de la vista `variaciones.php`** | 1) Consulta con múltiples productos; 2) Consulta filtrada a un único producto. | En vista general puebla `Total de operaciones` (`#variation-total-operations`), `Productos con variaciones` (`#variation-product-count`) y la tabla desplegable `#variation-product-breakdown` (`Producto`, `Operaciones`, `Incrementos`, `Disminuciones`). En vista de producto único muestra `#variation-single-product-summary` con `Total de operaciones`, `Incrementos` (`+N`) y `Disminuciones` (`−N`). | Pendiente |
| **CP-HU10-05** | Fase 7 | **Conservación de filtros al paginar** | Consulta en Variaciones con `tipo_variacion = "Baja por daño"`, `sentido = "DISMINUCION"`, categoría histórica y fechas que producen 3 páginas; el usuario navega a las páginas 2 y 3. | Al cambiar de página se conservan todos los filtros activos (`tipo_variacion`, `sentido`, fechas, clasificación histórica, búsqueda) y la misma `referencia_consulta`. | Pendiente |
| **CP-HU10-06** | Fases 7, 8 y 9 | **Exportación Variaciones sin resultados** | Consulta de Variaciones sin coincidencias (`total_operaciones = 0`) e intento de exportar PDF o Excel. | `exportable = false`; la solicitud de exportación responde `422 Unprocessable Entity` (`EMPTY_EXPORT_NOT_ALLOWED`). | Pendiente |

---

### 2.6 Consistencia de Consulta (`referencia_consulta`) e Igualdad con PDF y Excel (Fases 6, 7, 8 y 9)

| ID Prueba | Fase | Req. / Criterio | Escenario / Condición de Entrada | Resultado Esperado | Estado |
|---|---|---|---|---|---|
| **CP-CONS-01** | Fases 6–9 | **Consulta consistente frente a confirmaciones concurrentes** | Administrador A ejecuta `Consultar` en Kardex o Variaciones (obtiene `referencia_consulta` con 15 filas en 2 páginas). Inmediatamente después, Administrador B confirma una nueva baja o venta que entraría en el mismo rango de fechas. Administrador A pasa a la página 2 y luego exporta PDF y Excel. | La página 2, el resumen, el PDF y el Excel de Administrador A contienen **exactamente las mismas 15 filas y los mismos totales** congelados en su `referencia_consulta`, sin incluir la operación posterior de Administrador B. | Pendiente |
| **CP-CONS-02** | Fases 6–9 | **Imposibilidad de alterar filtros conservando la misma `referencia_consulta`** | El cliente envía una petición de página 2 o de exportación PDF/Excel reutilizando una `referencia_consulta` previa pero cambiando `sentido`, `tipo_variacion`, `id_producto` o `fecha_inicio`. | `409 Conflict` (`QUERY_FILTERS_MISMATCH`) rechazando la petición e indicando que debe ejecutarse una nueva consulta. | Pendiente |
| **CP-CONS-03** | Fases 6–9 | **Propiedad de usuario y expiración de `referencia_consulta`** | 1) Otro administrador intenta usar la `referencia_consulta` de un usuario distinto; 2) Se intenta paginar o exportar con una `referencia_consulta` cuyo `expira_en` ya venció. | 1) `403 Forbidden` (`QUERY_REFERENCE_FORBIDDEN`); 2) `410 Gone` (`QUERY_REFERENCE_EXPIRED`). | Pendiente |
| **CP-CONS-04** | Fases 8 y 9 | **Igualdad exacta entre consulta, resumen, PDF y Excel** | Se consulta Kardex y Variaciones con filtros de `tipo_variacion`, `sentido`, clasificación histórica y fechas, y se exporta a PDF y Excel. | Los filtros mostrados en el encabezado, las cifras del resumen ejecutivo y todas las filas del detalle coinciden al 100 % entre la consulta web, el archivo PDF y el archivo Excel. | Pendiente |

---

### 2.7 Productos, Presentaciones de Compras, Ventas y Compatibilidad con POS (Fases 2, 3 y 4)

| ID Prueba | Fase | Req. / Criterio | Escenario / Condición de Entrada | Resultado Esperado | Estado |
|---|---|---|---|---|---|
| **CP-INT-01** | Fase 3 | `RI-01` / `Acuerdo 8` | Crear un producto nuevo intentando enviar `stock_actual = 50`; editar un producto existente intentando cambiar `stock_actual = 99`. | El producto nuevo se crea con `stock_actual = 0` y la edición no modifica `stock_actual`. | Pendiente |
| **CP-INT-02** | Fase 3 | **Presentaciones de Compras conservadas** | Registrar una compra confirmada de **2 unidades de la presentación `Caja x 24` (`factor_conversion = 24`)** para un producto con `stock_actual = 0`. | `producto.stock_actual` se incrementa en **48 unidades individuales** (`stock_actual = 48`) y `kardex` registra una entrada de `48` unidades individuales (`saldo_anterior = 0`, `saldo_resultante = 48`). | Pendiente |
| **CP-INT-03** | Fase 3 | `RI-02` / `RI-06` | Corregir una compra confirmada cambiando únicamente el costo unitario o lote sin cambiar las unidades; luego corregir cambiando la cantidad; luego anular la compra. | El cambio solo de costo/lote **no genera movimientos ficticios en `kardex`**; el cambio de cantidad ajusta `stock_actual` y `kardex` solo por la diferencia real de unidades individuales; la anulación revierte las unidades individuales si el stock alcanza. | Pendiente |
| **CP-INT-04** | Fase 4 | `RI-03` / `RI-04` | Registrar una venta en el POS de 3 unidades individuales sobre un producto con `stock_actual = 48`. | En una sola transacción actualiza `stock_actual = 45` e inserta el movimiento `"Venta"` en `kardex` (`salida = 3`, `saldo_anterior = 48`, `saldo_resultante = 45`). | **Aprobado en Fase 4** (`F4-VTA-01`) |
| **CP-SEG-01** | Fase 2 | **Compatibilidad de catálogos con el POS y Vendedor** | Un usuario activo con rol **Vendedor (`id_rol = 2`)** consume `GET /api/productos` y `GET /api/categorias` desde el POS, y luego intenta invocar `GET /api/productos?modo=reporte`, `POST /api/inventario/bajas` o `GET /api/reportes/kardex`. | `GET /api/productos` y `GET /api/categorias` en modo estándar responden `200 OK` con el formato esperado por el POS; en cambio, `modo=reporte` y todos los endpoints de Inventario/Reportes del Sprint 2 responden `403 FORBIDDEN_ADMIN_ONLY`. | **Aprobado en Fase 2** (`F2-SEC-01` a `F2-SEC-05`) |

---

### 2.8 Rendimiento y Concurrencia bajo Carga (Fase 10)

| ID Prueba | Fase | Req. / Criterio | Escenario / Condición de Entrada | Resultado Esperado | Estado |
|---|---|---|---|---|---|
| **CP-REND-01** | Fase 10 | `RNF` (1 000 movimientos y 5 usuarios simultáneos) | En `amazon_market_sprint2_test` con un historial de **1 000 movimientos en `kardex`**, **5 sesiones simultáneas** ejecutan consultas y exportaciones de Kardex/Variaciones combinadas con registros concurrentes de operaciones de inventario. | Tiempos de respuesta dentro de los umbrales operativos ($\le 2\text{ s}$ en consultas paginadas, $\le 5\text{ s}$ en exportaciones PDF/Excel de 1 000 filas), 0 interbloqueos no controlados (*deadlocks*), 0 discrepancias entre `producto.stock_actual` y el último `saldo_resultante` en `kardex`, y consistencia intacta en cada `referencia_consulta`. | Pendiente |


---

## 3. Resultados Ejecutados en Fase 2 (`tests/sprint2/fase2_infraestructura_test.php`)

- **Comando ejecutado:** `C:\xampp\php\php.exe tests\sprint2\fase2_infraestructura_test.php`
- **Base de datos utilizada:** `amazon_market_sprint2_test`
- **Resultado global:** **24 PASS / 0 FAIL**

| Código | Verificación Ejecutada | Resultado |
|---|---|---|
| `F2-DB-01` | 107 productos con `stock_actual` y `stock_minimo` enteros no negativos y `id_subcategoria` poblado. | **PASS** |
| `F2-DB-02` | Las 6 categorías existentes (5 activas y 1 inactiva) cuentan con su subcategoría `"General"`. | **PASS** |
| `F2-DB-03` | Trigger `trg_categoria_after_insert_subcategoria_general` crea subcategoría `"General"` automáticamente al crear categoría. | **PASS** |
| `F2-DB-04` | FK compuesta `fk_producto_subcategoria_categoria` rechaza asignar a un producto una subcategoría de otra categoría. | **PASS** |
| `F2-QTY-01` | `DefinicionMovimiento::validarCantidadEnteraPositiva` rechaza `0`, `-5`, `'2.5'`, `'abc'`, `''` y `null` con `422 VALIDATION_ERROR`. | **PASS** |
| `F2-QTY-02` | Restricción `chk_producto_stock_entero_no_negativo` impide `stock_actual < 0` en tabla `producto`. | **PASS** |
| `F2-QTY-03` | Restricción `chk_detalle_compra_integridad` impide `cantidad_base <> cantidad_presentaciones * equivalencia_aplicada`. | **PASS** |
| `F2-REF-01` | Trigger `trg_kardex_before_insert_integridad` rechaza movimientos en `kardex` sin referencia de operación válida. | **PASS** |
| `F2-MOV-01` | `MovimientoInventarioService` impide dejar stock en negativo y responde `409 INSUFFICIENT_STOCK`. | **PASS** |
| `F2-MOV-02` | `MovimientoInventarioService` detecta cambio concurrente de `saldo_anterior_esperado` y responde `409 STOCK_CHANGED_CONFLICT`. | **PASS** |
| `F2-MOV-03` | Reversión atómica completa (`ROLLBACK`) cuando falla un paso intermedio en un lote de movimientos. | **PASS** |
| `F2-IDEM-01` | Primera solicitud válida ejecuta la operación, actualiza stock y devuelve `201 Created`. | **PASS** |
| `F2-IDEM-02` | Solicitud repetida con misma `referencia_solicitud` y mismo payload devuelve `200 OK` (`OPERATION_ALREADY_CONFIRMED`) sin volver a modificar el stock. | **PASS** |
| `F2-IDEM-03` | Misma `referencia_solicitud` con payload distinto es rechazada con `409 IDEMPOTENCY_PAYLOAD_MISMATCH`. | **PASS** |
| `F2-IDEM-04` | Solicitud simultánea en curso desde otra conexión es detectada mediante `GET_LOCK(..., 0)` y rechazada con `409 REQUEST_IN_PROGRESS`. | **PASS** |
| `F2-HIST-01` | El movimiento en `kardex` conserva snapshots históricos inmutables aunque cambie el nombre del producto en catálogo después. | **PASS** |
| `F2-HIST-02` | Trigger `trg_kardex_before_update_inmutable` impide alterar registros históricos en `kardex`. | **PASS** |
| `F2-SEC-01` | Sesión no autenticada es rechazada por `AuthMiddleware::verificarUsuarioActivoEnSesion()`. | **PASS** |
| `F2-SEC-02` | Administrador activo es validado contra BD (sincronizando nombre real) y posee permisos de Sprint 2. | **PASS** |
| `F2-SEC-03` | Usuario desactivado en BD (`estado = 0`) es rechazado inmediatamente con `401 AUTH_REQUIRED` aunque tenga sesión abierta. | **PASS** |
| `F2-SEC-04` | Rol Vendedor (`id_rol = 2`) tiene denegadas las funciones del Sprint 2 (`403 FORBIDDEN_ADMIN_ONLY`) pero conserva acceso al POS y catálogo. | **PASS** |
| `F2-SEC-05` | `CSRFMiddleware` rechaza `POST` sin token o con token inválido y acepta token válido. | **PASS** |
| `F2-CLEAN-01` | `amazon_market_sprint2_test` restaurada tras las pruebas a `stock_actual = 0` y `0` movimientos. | **PASS** |
| `F2-ISO-01` | `amazon_market` (base principal) verificada 100 % intacta (`20` tablas, `106` productos con stock > 0, `98` ventas, `4` compras, `26` kardex). | **PASS** |

---

## 4. Resultados Ejecutados en Fase 3 (`tests/sprint2/fase3_productos_compras_test.php`)

- **Comando ejecutado:** `C:\xampp\php\php.exe tests\sprint2\fase3_productos_compras_test.php`
- **Base de datos utilizada:** `amazon_market_sprint2_test`
- **Resultado global:** **13 PASS / 0 FAIL**

| Código | Verificación Ejecutada | Resultado |
|---|---|---|
| `F3-PROD-01` | Producto nuevo ignora stock enviado y se crea estrictamente con `stock_actual = 0`. | **PASS** |
| `F3-PROD-02` | Edición de producto actualiza atributos de catálogo pero no altera `stock_actual`. | **PASS** |
| `F3-COMP-01` | Caso 1: Compra de 2 cajas de 24 genera entrada de 48 unidades en stock y Kardex canónico. | **PASS** |
| `F3-COMP-02` | Caso 2: Compra 20, venta posterior 15 y corrección a 18 deja saldo final 3 sin error de stock negativo. | **PASS** |
| `F3-COMP-03` | Caso 3: Corrección solo de costo no altera stock ni genera movimientos en Kardex ($\Delta = 0$). | **PASS** |
| `F3-COMP-04` | Caso 4: Corrección con fallo intermedio realiza rollback completo sin corromper versiones ni stock. | **PASS** |
| `F3-COMP-05` | Caso 5: Segunda corrección calcula delta contra la versión vigente ($12 - 14 = -2$) dejando stock en 12. | **PASS** |
| `F3-COMP-06` | Caso 6: Bloqueo `FOR UPDATE` preserva actualizaciones concurrentes ($10 - 4 = 6$) sin actualizaciones perdidas. | **PASS** |
| `F3-COMP-07` | Caso 7: Anulación repetida es rechazada sin duplicar la reversión de stock. | **PASS** |
| `F3-REP-01` | Reporte de Compras filtra estrictamente `es_vigente = 1`, sin duplicar unidades ni generar falsas diferencias. | **PASS** |
| `F3-IDEM-01` | Control de solicitud idempotente responde repetición sin volver a sumar existencias. | **PASS** |
| `F3-CLEAN-01` | Limpieza final en base de pruebas: registros temporales eliminados y stock en 0. | **PASS** |
| `F3-ISO-01` | Aislamiento verificado: Base principal `amazon_market` no sufrió modificaciones (20 tablas, 106 productos con stock > 0, 98 ventas, 4 compras, 26 kardex). | **PASS** |

---

## 5. Resultados Ejecutados en Fase 4 (`tests/sprint2/fase4_ventas_kardex_test.php`)

- **Comando ejecutado:** `C:\xampp\php\php.exe tests\sprint2\fase4_ventas_kardex_test.php`
- **Base de datos utilizada:** `amazon_market_sprint2_test`
- **Resultado global:** **14 PASS / 0 FAIL**

| Código | Verificación Ejecutada | Resultado |
|---|---|---|
| `F4-VTA-01` | Venta normal de línea única: descuenta stock atómicamente, incrementa correlativo y genera Kardex con snapshots y FKs (`id_accion_venta`, `id_detalle_venta`). | **PASS** |
| `F4-VTA-02` | Venta multi-línea del mismo producto: valida demanda agregada ($\sum \text{cantidad}$), genera múltiples detalles y movimientos Kardex correlativos. | **PASS** |
| `F4-VTA-03` | Consumo de stock exacto hasta 0: permite agotar existencias exactamente sin inconsistencias. | **PASS** |
| `F4-VTA-04` | Rechazo por stock insuficiente (`409 INSUFFICIENT_STOCK`): no altera stock ni quema correlativo de serie. | **PASS** |
| `F4-VTA-05` | Rechazo estricto de cantidades inválidas (decimal, decimal string, cero, negativo, texto) con `422 VALIDATION_ERROR`. | **PASS** |
| `F4-VTA-06` | Concurrencia entre ventas: bloqueo ordenado `FOR UPDATE` previene sobreventa; primera venta descuenta y segunda es rechazada `409`. | **PASS** |
| `F4-VTA-07` | Operación combinada Compra y Venta: saldos kardex consistentes en secuencia (1 -> 11 compra -> 4 venta). | **PASS** |
| `F4-VTA-08` | Atomicidad y Rollback completo: fallo inducido revierte venta, kardex, stock y correlativo sin efectos residuales. | **PASS** |
| `F4-VTA-09` | Idempotencia de Venta: reintento devuelve respuesta idéntica sin doble descuento; payload alterado rechaza `409 IDEMPOTENCY_PAYLOAD_MISMATCH`. | **PASS** |
| `F4-VTA-10` | Seguridad de identidad del responsable: rechaza usuarios inexistentes (403/401) o no autenticados (401), previniendo BOLA/IDOR. | **PASS** |
| `F4-VTA-11` | Validación comercial: Factura exige obligatoriamente cliente con RUC (`id_tipo_documento = 2`); rechaza DNI (422) y acepta RUC con serie F001. | **PASS** |
| `F4-VTA-12` | Anulación de Venta: restituye existencias mediante `ANULACION_VENTA` (`INCREMENTO`), marca venta anulada y previene doble anulación. | **PASS** |
| `F4-CLEAN-01` | Limpieza final en base de pruebas: registros temporales eliminados y stock en 0 (`kardex = 0`, `ventas = 0`, `detalles = 0`). | **PASS** |
| `F4-ISO-01` | Aislamiento verificado: Base principal `amazon_market` no sufrió modificaciones (20 tablas, 106 productos con stock > 0, 98 ventas, 4 compras, 26 kardex). | **PASS** |

---

## 6. Resultados Ejecutados en Fase 5 (`tests/sprint2/fase5_bajas_ajustes_test.php`)

- **Comando ejecutado:** `C:\xampp\php\php.exe tests\sprint2\fase5_bajas_ajustes_test.php`
- **Base de datos utilizada:** `amazon_market_sprint2_test`
- **Resultado global:** **14 PASS / 0 FAIL**

| Código | Verificación Ejecutada | Resultado |
|---|---|---|
| `F5-BAJA-01` | Baja con lote válido: registra `operacion_inventario`, descuenta stock y genera Kardex con snapshots y lote `CON_LOTE`. | **PASS** |
| `F5-BAJA-02` | Baja sin lote: registra con `numero_lote = NULL` y condición `SIN_LOTE` sin bloquear la operación. | **PASS** |
| `F5-BAJA-03` | Rechazo de Baja por motivo inválido, justificación vacía o >255 caracteres con `422 VALIDATION_ERROR` y stock intacto. | **PASS** |
| `F5-BAJA-04` | Rechazo de Baja por cantidad > stock (`409 INSUFFICIENT_STOCK`), decimales o cero (`422 VALIDATION_ERROR`). | **PASS** |
| `F5-AJUSTE-01` | Ajuste positivo por conteo físico: registra `operacion_inventario` (`INCREMENTO`), suma stock y genera Kardex. | **PASS** |
| `F5-AJUSTE-02` | Ajuste negativo por conteo físico: registra `operacion_inventario` (`DISMINUCION`), resta stock y genera Kardex. | **PASS** |
| `F5-AJUSTE-03` | Saldo cambiado durante preparación: rechaza con `409 STOCK_CHANGED_CONFLICT`, devuelve `stock_actual` vigente y `requiere_revision`. | **PASS** |
| `F5-AJUSTE-04` | Rechazo de Ajuste negativo que produciría saldo negativo con `409 INSUFFICIENT_STOCK`. | **PASS** |
| `F5-CONC-01` | Concurrencia entre salidas simultáneas (`FOR UPDATE`): primera descuenta y segunda es rechazada `409`. | **PASS** |
| `F5-IDEM-01` | Idempotencia en Bajas y Ajustes: reintento devuelve respuesta confirmada sin duplicar decremento; payload alterado rechaza `409`. | **PASS** |
| `F5-ATOM-01` | Atomicidad y Rollback completo: fallo inducido revierte operacion_inventario, kardex y stock sin efectos residuales. | **PASS** |
| `F5-SEC-01` | Seguridad y RBAC: rol Vendedor rechazado `403 FORBIDDEN_ADMIN_ONLY`; usuario inexistente rechazado `401`. | **PASS** |
| `F5-CLEAN-01` | Limpieza final en base de pruebas: registros temporales eliminados y stock en 0. | **PASS** |
| `F5-ISO-01` | Aislamiento verificado: Base principal `amazon_market` no sufrió modificaciones (20 tablas, 106 productos con stock > 0, 98 ventas, 4 compras, 26 kardex). | **PASS** |
