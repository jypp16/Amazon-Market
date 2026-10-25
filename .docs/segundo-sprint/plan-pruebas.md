# Plan de Pruebas y Matriz de Trazabilidad — Sprint 2

**Proyecto:** Amazon-Market  
**Base de datos exclusiva de pruebas (Fases 1–9):** `amazon_market_sprint2_test`  
**Bootstrap de aislamiento:** [`tests/sprint2/bootstrap_test.php`](../../tests/sprint2/bootstrap_test.php)  
**Estado de ejecución:** En Fase 1 únicamente se ejecutaron las verificaciones de respaldo, restauración, aislamiento de conexión y escenario limpio. Todos los casos de prueba funcionales, de integración, concurrencia y rendimiento descritos a continuación se encuentran **Pendientes de ejecución** para sus respectivas Fases 2 a 10.

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
| **CP-HU07-01** | Fase 5 | `RF-07.1` / `CA-07.1` | Administrador activo registra `Baja por daño` de 4 unidades sobre producto con `stock_actual = 20`, `lote = "L-01"` y `justificacion` válida. | `201 Created`; `producto.stock_actual` pasa a `16`; se inserta fila en `kardex` con `saldo_anterior = 20`, `salida = 4`, `saldo_resultante = 16`, `lote = "L-01"`, `justificacion`, responsable y fecha/hora del servidor. | Pendiente |
| **CP-HU07-02** | Fase 5 | `RF-07.1` / `CA-07.3` | Administrador activo registra `Baja por vencimiento` de 5 unidades con `justificacion` válida. | `201 Created`; `stock_actual` se reduce en 5 unidades y el movimiento queda trazado en `kardex` con su `justificacion`. | Pendiente |
| **CP-HU07-03** | Fase 5 | `RN-02` / `CA-07.3` | Registro de `Baja por daño` **o** `Baja por vencimiento` con `justificacion` vacía, omitida o compuesta solo por espacios en blanco. | `422 Unprocessable Entity` con error en `errors.justificacion` en **ambos** motivos (`Baja por daño` y `Baja por vencimiento`); no se modifica `stock_actual` ni `kardex`. | Pendiente |
| **CP-HU07-04** | Fase 5 | `RN-03` / `CA-07.2` | Registro de baja con `cantidad = 15` cuando `stock_actual = 10` (o `stock_actual = 0`). | `409 Conflict` (`INSUFFICIENT_STOCK`) informando `stock_actual = 10`; no se descuenta stock ni se crea movimiento. | Pendiente |
| **CP-HU07-05** | Fase 5 | `CL-07.1` / `CA-07.2` | Registro de baja con `cantidad = 10` cuando `stock_actual = 10` (consumo exacto a cero). | `201 Created`; `stock_actual` queda en `0` y `saldo_resultante = 0` en `kardex`. | Pendiente |
| **CP-HU07-06** | Fase 5 | `RN-05` / `CL-07.4` | Registro de baja con `cantidad` igual a `0`, `-3`, `2.5` o `"abc"`. | `422 Unprocessable Entity` con error en `errors.cantidad` exigiendo entero $\ge 1$. | Pendiente |
| **CP-HU07-07** | Fase 5 | `RN-04` / `CA-07.4` | Registro de baja dejando el campo `lote` vacío. | `201 Created`; se guarda `lote = NULL` y se expone como `"Sin lote"` sin bloquear ni afectar otros registros. | Pendiente |
| **CP-HU07-08** | Fase 5 | `CL-07.5` | Intento de registrar baja sobre un producto con `estado = 0` (inactivo). | `422 Unprocessable Entity` rechazando la operación por producto inactivo. | Pendiente |

---

### 2.2 HU-08: Registro de Ajustes por Conteo Físico (Fase 5)

| ID Prueba | Fase | Req. / Criterio | Escenario / Condición de Entrada | Resultado Esperado | Estado |
|---|---|---|---|---|---|
| **CP-HU08-01** | Fase 5 | `RF-08.1` / `CA-08.1` | Administrador registra `Ajuste positivo` de 6 unidades sobre producto con `stock_actual = 10`, `saldo_anterior_esperado = 10` y `justificacion` válida. | `201 Created`; `stock_actual` pasa a `16`; `kardex` registra `entrada = 6`, `saldo_anterior = 10`, `saldo_resultante = 16` y `justificacion`. | Pendiente |
| **CP-HU08-02** | Fase 5 | `RF-08.1` / `CA-08.1` | Administrador registra `Ajuste negativo` de 3 unidades sobre producto con `stock_actual = 16`, `saldo_anterior_esperado = 16` y `justificacion` válida. | `201 Created`; `stock_actual` pasa a `13`; `kardex` registra `salida = 3`, `saldo_anterior = 16`, `saldo_resultante = 13` y `justificacion`. | Pendiente |
| **CP-HU08-03** | Fase 5 | `RN-06` / `CA-08.2` | Ajuste positivo o negativo con `justificacion` vacía o solo espacios en blanco. | `422 Unprocessable Entity` con error en `errors.justificacion`; no se altera el stock. | Pendiente |
| **CP-HU08-04** | Fase 5 | `RN-07` / `CA-08.3` | Ajuste negativo con `cantidad = 20` cuando `stock_actual = 13` (y `saldo_anterior_esperado = 13`). | `409 Conflict` (`INSUFFICIENT_STOCK`); el saldo nunca queda negativo. | Pendiente |
| **CP-HU08-05** | Fase 5 | `CL-08.1` | Ajuste negativo con `cantidad = 13` cuando `stock_actual = 13`. | `201 Created`; `stock_actual` queda en `0` y `saldo_resultante = 0`. | Pendiente |
| **CP-HU08-06** | Fase 5 | `CA-08.4` / `CL-08.3` | El administrador prepara ajuste con `saldo_anterior_esperado = 16`, pero otra operación concurrente dejó `stock_actual = 14` antes de confirmar. | `409 Conflict` (`STOCK_CHANGED_CONFLICT`) devolviendo `stock_actual = 14` y `requiere_revision = true` sin aplicar el ajuste a ciegas. | Pendiente |
| **CP-HU08-07** | Fase 5 | `RN-05` / `CL-08.2` | Ajuste con `cantidad = 0`, negativa o decimal (`1.5`). | `422 Unprocessable Entity` en `errors.cantidad` exigiendo entero $\ge 1$. | Pendiente |

---

### 2.3 Solicitudes Repetidas, Atomicidad, Fallos Intermedios y Concurrencia (Fases 2, 3, 4 y 5)

| ID Prueba | Fase | Req. / Criterio | Escenario / Condición de Entrada | Resultado Esperado | Estado |
|---|---|---|---|---|---|
| **CP-IDEM-01** | Fases 2–5 | `CL-07.3` / `CL-08.4` | Reenvío de una solicitud ya confirmada (`referencia_solicitud` idéntica, mismo usuario, misma operación y mismos datos). | `200 OK` (`OPERATION_ALREADY_CONFIRMED`, `idempotent_replay = true`) devolviendo el resultado original ya registrado **sin volver a descontar ni incrementar stock** y sin insertar otro movimiento en `kardex`. | Pendiente |
| **CP-IDEM-02** | Fases 2–5 | `Acuerdo 9` | Reenvío de la misma `referencia_solicitud` del usuario pero modificando datos del payload (p. ej. cambiando `cantidad` o `id_producto`). | `409 Conflict` (`IDEMPOTENCY_PAYLOAD_MISMATCH`) rechazando el conflicto sin ejecutar la operación. | Pendiente |
| **CP-IDEM-03** | Fases 2–5 | `Acuerdo 9` | Recepción de una segunda petición con la misma `referencia_solicitud` mientras la primera aún está en estado `EN_PROCESO`. | `409 Conflict` (`REQUEST_IN_PROGRESS`, `estado_solicitud = "EN_PROCESO"`) informando su estado sin duplicar la operación. | Pendiente |
| **CP-ATOM-01** | Fases 2–5 | `Acuerdo 9` / `RI-04` | Fallo intermedio inducido durante una compra, venta, baja o ajuste (p. ej. error al insertar en `kardex` tras haber ejecutado el `UPDATE` de `producto.stock_actual`). | `ROLLBACK` completo de la transacción: `producto.stock_actual` conserva su valor previo, no quedan cabeceras huérfanas y la solicitud queda marcada como `FALLIDA`. | Pendiente |
| **CP-CONC-01** | Fases 2–5 | `Acuerdo 9` / `RNF` | Dos operaciones simultáneas intentan descontar 8 y 6 unidades de un mismo producto con `stock_actual = 10` (`SELECT ... FOR UPDATE`). | La primera transacción que obtiene el bloqueo confirma y deja `stock_actual = 2` (o `4`); la segunda lee el nuevo saldo bloqueado, detecta stock insuficiente (`409 INSUFFICIENT_STOCK`) y hace `ROLLBACK` sin perder actualizaciones ni dejar stock negativo. | Pendiente |

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
| **CP-INT-04** | Fase 4 | `RI-03` / `RI-04` | Registrar una venta en el POS de 3 unidades individuales sobre un producto con `stock_actual = 48`. | En una sola transacción actualiza `stock_actual = 45` e inserta el movimiento `"Venta"` en `kardex` (`salida = 3`, `saldo_anterior = 48`, `saldo_resultante = 45`). | Pendiente |
| **CP-SEG-01** | Fase 2 | **Compatibilidad de catálogos con el POS y Vendedor** | Un usuario activo con rol **Vendedor (`id_rol = 2`)** consume `GET /api/productos` y `GET /api/categorias` desde el POS, y luego intenta invocar `GET /api/productos?modo=reporte`, `POST /api/inventario/bajas` o `GET /api/reportes/kardex`. | `GET /api/productos` y `GET /api/categorias` en modo estándar responden `200 OK` con el formato esperado por el POS; en cambio, `modo=reporte` y todos los endpoints de Inventario/Reportes del Sprint 2 responden `403 FORBIDDEN_ADMIN_ONLY`. | Pendiente |

---

### 2.8 Rendimiento y Concurrencia bajo Carga (Fase 10)

| ID Prueba | Fase | Req. / Criterio | Escenario / Condición de Entrada | Resultado Esperado | Estado |
|---|---|---|---|---|---|
| **CP-REND-01** | Fase 10 | `RNF` (1 000 movimientos y 5 usuarios simultáneos) | En `amazon_market_sprint2_test` con un historial de **1 000 movimientos en `kardex`**, **5 sesiones simultáneas** ejecutan consultas y exportaciones de Kardex/Variaciones combinadas con registros concurrentes de operaciones de inventario. | Tiempos de respuesta dentro de los umbrales operativos ($\le 2\text{ s}$ en consultas paginadas, $\le 5\text{ s}$ en exportaciones PDF/Excel de 1 000 filas), 0 interbloqueos no controlados (*deadlocks*), 0 discrepancias entre `producto.stock_actual` y el último `saldo_resultante` en `kardex`, y consistencia intacta en cada `referencia_consulta`. | Pendiente |
