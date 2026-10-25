# Estado de Fases — Sprint 2

**Proyecto:** Amazon-Market (`C:\xampp\htdocs\Amazon-Market`)  
**Rama activa:** `feature/inventario`  
**Última actualización:** 2026-10-25 (Cierre de Fase 4)

---

## 1. Plan Oficial y Estado de Fases del Sprint 2

| Fase | Nombre Oficial de la Fase | Alcance Principal | Base de Datos Utilizada | Estado |
|---|---|---|---|---|
| **Fase 1** | **Preparación y contratos** | Respaldo verificado, copia aislada de pruebas con escenario limpio, acuerdos, matriz de diferencias, contrato API (incluyendo selector de sentido, `referencia_solicitud` y `referencia_consulta`) y plan de pruebas. | `amazon_market_sprint2_test` (`amazon_market` intacta) | **Completada** |
| **Fase 2** | **Datos, seguridad y base común de movimientos** | Migraciones de estructura (`subcategoria`, instantáneas históricas en `kardex`, control de idempotencia `referencia_solicitud`, almacenamiento de `referencia_consulta`), validación de Administrador activo, base común transaccional de movimientos y compatibilidad de catálogos con POS. | `amazon_market_sprint2_test` (`amazon_market` intacta) | **Completada** |
| **Fase 3** | **Productos y Compras** | Productos nuevos con `stock_actual = 0`, bloqueo de edición de stock desde catálogo, validación entera de `stock_minimo`, conservación de presentaciones y equivalencias de Compras (ej. 2 cajas de 24 = 48 unidades individuales) y corrección/anulación sin movimientos ficticios en `kardex`. | `amazon_market_sprint2_test` | **Completada** |
| **Fase 4** | **Ventas** | Registro atómico de salidas de Venta en `kardex` junto con el descuento de `stock_actual` en unidades enteras individuales (`equivalencia = 1`), bloqueo `FOR UPDATE` e idempotencia. | `amazon_market_sprint2_test` | **Completada** |
| **Fase 5** | **Bajas y ajustes** | Registro transaccional de Bajas (`Baja por daño`, `Baja por vencimiento` con `justificacion` obligatoria en ambas y lote opcional) y Ajustes por conteo (`Ajuste positivo`, `Ajuste negativo` con `justificacion` obligatoria y validación de `saldo_anterior_esperado`), conectando `Views/Inventario/ajustes.php`. | `amazon_market_sprint2_test` | Pendiente |
| **Fase 6** | **Kardex** | Consulta paginada de todos los orígenes que afectan existencias, cálculo de `resumen_producto`, clasificación histórica, consistencia mediante `referencia_consulta` y conexión de `Views/Reporte/kardex.php`. | `amazon_market_sprint2_test` | Pendiente |
| **Fase 7** | **Variaciones y nuevo selector de sentido** | Incorporación del nuevo selector independiente “Sentido del movimiento” (`Todas`, `Entradas`, `Salidas`) en `Views/Reporte/variaciones.php`, consulta con `tipo_variacion` y `sentido` combinados por `AND`, resúmenes general y por producto único, y consistencia mediante `referencia_consulta`. | `amazon_market_sprint2_test` | Pendiente |
| **Fase 8** | **PDF** | Generación y descarga de PDF para Kardex (incluyendo producto específico sin movimientos con saldo inicial y cierre igual al inicial) y Variaciones, conservando colores institucionales, resumen ejecutivo previo al detalle e igualdad con `referencia_consulta`. | `amazon_market_sprint2_test` | Pendiente |
| **Fase 9** | **Excel** | Generación y descarga de Excel para Kardex (incluyendo producto específico sin movimientos) y Variaciones, conservando estilos, resumen ejecutivo previo al detalle e igualdad con `referencia_consulta` y PDF. | `amazon_market_sprint2_test` | Pendiente |
| **Fase 10** | **Verificación integral y actualización controlada** | Pruebas integrales de extremo a extremo, concurrencia y rendimiento (1 000 movimientos y 5 usuarios simultáneos) sobre `amazon_market_sprint2_test`, seguidas de respaldo, archivado de operaciones antiguas de prueba y sincronización controlada en `amazon_market`. | `amazon_market_sprint2_test` y `amazon_market` | Pendiente |

---

## 2. Evidencias Reales de Cierre de la Fase 1

### 2.1 Respaldo Verificado de la Base Actual (`amazon_market`)
Los respaldos se encuentran almacenados fuera del directorio público del sitio en `C:\xampp\backups\amazon_market\`:

| Archivo de Respaldo | Tamaño (bytes) | Hash SHA-256 | Propósito |
|---|---|---|---|
| `C:\xampp\backups\amazon_market\amazon_market_pre_sprint2_portable.sql` | `62,679` | `B565F4EC85F3E53443AC436F6EF8C87D36AF14BC75C5411BB611A4174156B731` | Volcado completo de tablas, datos, rutinas, triggers y eventos sin sentencia `USE`, apto para restaurar sobre cualquier base de destino. |
| `C:\xampp\backups\amazon_market\amazon_market_pre_sprint2_fulldb.sql` | `62,873` | `A1D2BAC324AC1A28FCB143DDE6165DEDD2CD439D2F279EEC347FC1678D4BB6D3` | Volcado completo con `--databases amazon_market` para recuperación directa de la base principal. |

### 2.2 Procedimiento de Recuperación Comprobado
1. **Para recuperar la base principal (`amazon_market`) en caso de contingencia:**
   ```powershell
   C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 -e "SOURCE C:/xampp/backups/amazon_market/amazon_market_pre_sprint2_fulldb.sql"
   ```
2. **Para recrear desde cero la copia aislada de pruebas (`amazon_market_sprint2_test`) y aplicar el escenario limpio:**
   ```powershell
   C:\xampp\mysql\bin\mysql.exe -u root -e "DROP DATABASE IF EXISTS amazon_market_sprint2_test; CREATE DATABASE amazon_market_sprint2_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 amazon_market_sprint2_test -e "SOURCE C:/xampp/backups/amazon_market/amazon_market_pre_sprint2_portable.sql"
   C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 amazon_market_sprint2_test -e "SOURCE C:/xampp/htdocs/Amazon-Market/database/segundo_sprint/00_preparacion_escenario_limpio_pruebas.sql"
   ```

### 2.3 Estado Comparativo Verificado: `amazon_market` vs. `amazon_market_sprint2_test`

| Tabla / Métrica | `amazon_market` (Base actual intacta) | `amazon_market_sprint2_test` (Tras restaurar respaldo) | `amazon_market_sprint2_test` (Escenario limpio aplicado) |
|---|---:|---:|---:|
| `categoria` | 6 | 6 | 6 |
| `unidad_medida` | 4 | 4 | 4 |
| `presentacion` | 3 | 3 | 3 |
| `producto` (total catálogo) | 107 | 107 | 107 |
| `producto` con `stock_actual > 0` | 106 | 106 | **0** |
| `producto` con `stock_actual = 0` | 1 | 1 | **107** |
| Diferencias en atributos de catálogo (`producto`) | — | 0 | **0** |
| `proveedor` | 3 | 3 | 3 |
| `cliente` | 14 | 14 | 14 |
| `usuario` / `rol` / `permiso` / `rol_permiso` | 6 / 2 / 27 / 26 | 6 / 2 / 27 / 26 | 6 / 2 / 27 / 26 |
| `tipo_comprobante` / `serie_comprobante` / `tipo_documento` / `metodo_pago` | 2 / 2 / 4 / 3 | 2 / 2 / 4 / 3 | 2 / 2 / 4 / 3 |
| `compra` / `detalle_compra` / `compra_historial` | 4 / 4 / 3 | 4 / 4 / 3 | **0 / 0 / 0** |
| `venta` / `detalle_venta` | 98 / 377 | 98 / 377 | **0 / 0** |
| `kardex` | 26 | 26 | **0** |

---

## 3. Condición de Cierre de Fase 1 y Dependencias para la Fase 2

### Condición de Cierre Cumplida
- Documentación de acuerdos, matriz de diferencias, contrato API, plan de pruebas y estado de fases revisada y alineada sin contradicciones en `.docs/segundo-sprint/`.
- Respaldo fuera del directorio público comprobado mediante restauración completa.
- Copia de pruebas `amazon_market_sprint2_test` aislada, con escenario operativo limpio aplicado y verificado.
- Script `database/segundo_sprint/00_preparacion_escenario_limpio_pruebas.sql` documentado y protegido con guardia activa `SIGNAL SQLSTATE '45000'`.
- Base de datos habitual (`amazon_market`) 100 % intacta y conexión por defecto de la aplicación inalterada.

### Dependencias para Iniciar la Fase 2 (Datos, seguridad y base común de movimientos)
- Crear y ejecutar sobre `amazon_market_sprint2_test` las migraciones `01_estructura_inventario_sprint2.sql` (tablas `subcategoria`, operaciones de inventario, campos snapshot históricos en `kardex`, tabla de control de idempotencia `referencia_solicitud` y tablas de consistencia de consulta `referencia_consulta`) y `02_configuracion_permisos_sprint2.sql`.
- Implementar la validación de Administrador activo para las rutas del Sprint 2 conservando la compatibilidad de `/api/productos` y `/api/categorias` con el POS y el rol Vendedor, así como la base común transaccional de movimientos.


---

## 4. Evidencias Reales de Cierre de la Fase 2

1. **Scripts SQL creados y ejecutados en `amazon_market_sprint2_test`**:
   - [`database/segundo_sprint/01_estructura_inventario_sprint2.sql`](../../database/segundo_sprint/01_estructura_inventario_sprint2.sql): Crea `subcategoria`, convierte `producto.stock_actual` y `stock_minimo` a `INT(11)` con `chk_producto_stock_entero_no_negativo` y FK compuesta `fk_producto_subcategoria_categoria`, agrega versionado a `detalle_compra` con `chk_detalle_compra_integridad`, crea `operacion_inventario` con `chk_op_inv_integridad`, amplía `kardex` con `origen_operacion`, `sentido_movimiento`, FKs `ON DELETE RESTRICT`, columnas snapshot históricas e inmutabilidad mediante triggers (`trg_kardex_before_insert_integridad` y `trg_kardex_before_update_inmutable`), y crea `control_solicitud_idempotencia`, `reporte_consulta_snapshot` y `reporte_consulta_snapshot_item`.
   - [`database/segundo_sprint/02_configuracion_permisos_sprint2.sql`](../../database/segundo_sprint/02_configuracion_permisos_sprint2.sql): Crea la subcategoría predeterminada `"General"` para cada una de las 6 categorías, vincula los 107 productos a la subcategoría `"General"` de su categoría, registra y asigna los permisos `inventario.ajustes`, `reportes.kardex`, `reportes.variaciones` y `reportes.exportar` exclusivamente al rol Administrador (`id_rol = 1`), e instala los triggers de correspondencia categoría-subcategoría.
2. **Infraestructura transaccional común, idempotencia y seguridad**:
   - [`Libraries/Core/DefinicionMovimiento.php`](../../Libraries/Core/DefinicionMovimiento.php): Constantes canónicas y reglas determinísticas de `origen_operacion`, `sentido_movimiento`, `condicion_lote` y validación de cantidades enteras positivas.
   - [`Libraries/Core/InventarioException.php`](../../Libraries/Core/InventarioException.php): Excepciones tipadas con códigos HTTP y códigos de dominio (`VALIDATION_ERROR`, `INSUFFICIENT_STOCK`, `STOCK_CHANGED_CONFLICT`, `IDEMPOTENCY_PAYLOAD_MISMATCH`, `REQUEST_IN_PROGRESS`, `AUTH_REQUIRED`, `FORBIDDEN_ADMIN_ONLY`).
   - [`Services/MovimientoInventarioService.php`](../../Services/MovimientoInventarioService.php): Infraestructura transaccional común con bloqueo determinístico (`ORDER BY p.id_producto ASC FOR UPDATE`), verificación de responsable activo en BD, validación de `saldo_anterior_esperado`, prohibición de saldos negativos y persistencia de snapshots históricos en `kardex`.
   - [`Services/ControlSolicitudService.php`](../../Services/ControlSolicitudService.php): Control de solicitudes repetidas y simultáneas por `(id_usuario, tipo_operacion, referencia_solicitud)` con bloqueo `GET_LOCK(..., 0)` y tabla `control_solicitud_idempotencia`.
   - [`Libraries/Middleware/AuthMiddleware.php`](../../Libraries/Middleware/AuthMiddleware.php), [`Libraries/Middleware/RBACMiddleware.php`](../../Libraries/Middleware/RBACMiddleware.php), [`Libraries/Middleware/CSRFMiddleware.php`](../../Libraries/Middleware/CSRFMiddleware.php), [`Libraries/Core/ApiController.php`](../../Libraries/Core/ApiController.php), [`Controllers/API/ProductoApiController.php`](../../Controllers/API/ProductoApiController.php), [`Controllers/API/CategoriaApiController.php`](../../Controllers/API/CategoriaApiController.php), [`Controllers/API/ReporteApiController.php`](../../Controllers/API/ReporteApiController.php) y [`Views/Header.php`](../../Views/Header.php).
3. **Suite de pruebas automatizadas**:
   - [`tests/sprint2/fase2_infraestructura_test.php`](../../tests/sprint2/fase2_infraestructura_test.php): Ejecutada sobre `amazon_market_sprint2_test` con resultado **24 PASS / 0 FAIL**.
4. **Dependencias para la Fase 3 (Productos y Compras)**:
   - Conectar `ProductoApiController`, `ProductoService` y `CompraService` a `MovimientoInventarioService` y `ControlSolicitudService`, garantizando que productos nuevos inicien con `stock_actual = 0`, que `stock_actual` no pueda editarse desde Productos y que Compras conserve presentaciones/equivalencias y registre correcciones/anulaciones en `kardex` sin movimientos ficticios.

---

## 5. Evidencias Reales de Cierre de la Fase 3

1. **Productos estrictamente alineados con el inventario**:
   - [`Services/ProductoService.php`](../../Services/ProductoService.php): Método `crear()` fuerza `stock_actual = 0` sin importar lo provisto en la petición. Método `actualizar()` excluye y no procesa `stock_actual` en el `UPDATE`, impidiendo modificaciones manuales de existencias. Valida `stock_minimo >= 0` entero y asignación de `id_subcategoria` con verificación de correspondencia compuesta (`(id_subcategoria, id_categoria)`). Se eliminó la lógica de unidades fraccionarias (`$unidadesDecimales`), manteniendo enteros en unidades de envases individuales.
   - [`Controllers/API/ProductoApiController.php`](../../Controllers/API/ProductoApiController.php): Endpoints `POST /api/productos` y `PUT /api/productos/{id}` alineados con la regla de stock 0 en creación y exclusión de stock en actualización.
   - [`Controllers/ProductoController.php`](../../Controllers/ProductoController.php), [`Views/Producto/nuevo.php`](../../Views/Producto/nuevo.php), [`Views/Producto/editar.php`](../../Views/Producto/editar.php), [`Assets/js/producto.js`](../../Assets/js/producto.js) y [`Assets/js/productos.js`](../../Assets/js/productos.js): Selector dinámico de subcategorías dependiente de la categoría seleccionada, campo de stock en modo solo lectura/informativo con advertencia explícita y pasos enteros (`step = "1"`) en `stock_minimo`.

2. **Compras coherentes con presentaciones, versionamiento inmutable y Kardex canónico**:
   - [`Models/DetalleCompraModel.php`](../../Models/DetalleCompraModel.php): `obtenerDetallesPorCompra($id_compra, $soloVigentes = true)` filtra `es_vigente = 1` por defecto y añade `obtenerMaxVersion($id_compra)` para el control de versiones consecutivas.
   - [`Models/CompraModel.php`](../../Models/CompraModel.php): Métodos `obtenerResumenCompras`, `obtenerDetallesFiltrados` y cálculo de productos más comprados filtran estrictamente `detalle_compra.es_vigente = 1`, evitando que las versiones reemplazadas de compras corregidas dupliquen unidades o disparen discrepancias en `compras_con_diferencias`. Métodos `anularCompraConMotivo`, `corregirCompraConMotivo`, `registrarHistorial` y `obtenerHistorialPorCompra`.
   - [`Services/CompraService.php`](../../Services/CompraService.php):
     - `registrarCompra`: Conserva presentaciones (`Unidad`, `Caja x 12`, `Caja x 24`), valida equivalencias (`cantidad_base = presentaciones * equivalencia`), bloquea productos ordenadamente (`bloquearProductosOrdenados`) y aplica movimiento canónico `ORIGEN_COMPRA` (`INCREMENTO`) con snapshots históricos completos y FKs de compra y detalle.
     - `corregirCompra`: En lugar de eliminar detalles antiguos, calcula la diferencia neta por producto $\Delta(P) = U_{\text{nueva}}(P) - U_{\text{vigente}}(P)$. Si $\Delta = 0$ (corrección solo de costo, comprobante o lote), no genera movimientos ficticios en `kardex`. Si $\Delta \ne 0$, aplica `ORIGEN_CORRECCION_COMPRA` (`INCREMENTO` si $\Delta > 0$ o `DISMINUCION` si $\Delta < 0$). Si $\Delta < 0$, valida suficiencia de existencias antes de alterar saldos (`409 INSUFFICIENT_STOCK`). Marca la versión anterior con `es_vigente = 0, reemplazado_en = NOW()` e inserta la nueva versión con `version_detalle = $max + 1, es_vigente = 1, id_detalle_origen`.
     - `anularCompra`: Valida que el stock cubra todas las unidades vigentes a revertir, aplica `ORIGEN_ANULACION_COMPRA` (`DISMINUCION`) por cada detalle vigente, marca la cabecera como `Anulada` e impide anulaciones duplicadas.
     - `obtenerReporteCompras`: Reporte común para PDF y Excel filtrado por `es_vigente = 1`, garantizando cuadratura contable exacta.
   - [`Controllers/API/CompraApiController.php`](../../Controllers/API/CompraApiController.php): Requiere rol Administrador activo en `PUT` (`compras.editar`) y `DELETE` (`compras.eliminar`), extrae `referencia_solicitud` para ejecución idempotente y maneja `InventarioException`.

3. **Suite de pruebas automatizadas de la Fase 3**:
   - [`tests/sprint2/fase3_productos_compras_test.php`](../../tests/sprint2/fase3_productos_compras_test.php): Ejecutada sobre `amazon_market_sprint2_test` con resultado **13 PASS / 0 FAIL**:
     - `F3-PROD-01`: Creación de producto nuevo con `stock_actual = 0`.
     - `F3-PROD-02`: Edición de producto omite `stock_actual` sin alterarlo.
     - `F3-COMP-01`: Caso 1 — Compra de 2 cajas de 24: entrada de 48 unidades en stock y Kardex canónico.
     - `F3-COMP-02`: Caso 2 — Compra 20, venta posterior 15 y corrección a 18: saldo final 3 sin error de stock negativo.
     - `F3-COMP-03`: Caso 3 — Corrección solo de costo: stock y movimientos sin cambios ($\Delta = 0$).
     - `F3-COMP-04`: Caso 4 — Corrección con fallo intermedio: rollback completo de detalles y existencias.
     - `F3-COMP-05`: Caso 5 — Segunda corrección: delta calculado contra la versión vigente ($12 - 14 = -2$).
     - `F3-COMP-06`: Caso 6 — Compra concurrente con venta: serializada por `FOR UPDATE` sin actualizaciones perdidas ($10 - 4 = 6$).
     - `F3-COMP-07`: Caso 7 — Anulación repetida: una sola reversión sin duplicar decremento.
     - `F3-REP-01`: Reporte de compras filtra `es_vigente = 1` sin duplicación de líneas ni falsas discrepancias.
     - `F3-IDEM-01`: Idempotencia de solicitudes repetidas devuelve resultado sin sumar stock duplicado.
     - `F3-CLEAN-01`: Limpieza de registros de prueba y stock restablecido a 0.
     - `F3-ISO-01`: Aislamiento verificado: Base principal `amazon_market` 100 % intacta (20 tablas, 106 productos con stock > 0, 98 ventas, 4 compras, 26 kardex).

---

## 6. Evidencias Reales de Cierre de la Fase 4

1. **Ventas atómicas integradas con Kardex y bloqueo pesimista**:
   - [`Services/VentaService.php`](../../Services/VentaService.php):
     - `procesarVenta`: Valida usuario responsable activo en BD, cliente activo, tipo de comprobante activo y método de pago. Si es Factura, valida que el cliente posea RUC (`id_tipo_documento = 2`). Exige cantidades enteras positivas $\ge 1$ mediante `DefinicionMovimiento::validarCantidadEnteraPositiva`.
     - Manejo de demanda agregada: Si el carrito contiene múltiples líneas del mismo producto, acumula la demanda total ($\sum \text{cantidad}$) para validar suficiencia antes de autorizar.
     - Bloqueo determinístico: Ejecuta en transacción llamando a `bloquearProductosOrdenados` (`ORDER BY p.id_producto ASC FOR UPDATE`), garantizando serialización ante ventas concurrentes y previniendo interbloqueos (*deadlocks*).
     - Serie y correlativo seguros: Bloquea la fila en `serie_comprobante` con `FOR UPDATE` dentro de la misma transacción. En caso de error, el correlativo no se quema ni genera huecos en la serie.
     - Aplicación canónica en `kardex`: Descuenta existencias mediante `MovimientoInventarioService::aplicarMovimiento` con `ORIGEN_VENTA` y `SENTIDO_DISMINUCION`, vinculando las claves foráneas exclusivas `id_accion_venta` e `id_detalle_venta`, y registrando snapshots históricos completos (código, nombre, categoría, subcategoría, unidad, responsable y rol).
     - Idempotencia: Integra `ControlSolicitudService::ejecutarConIdempotencia` bajo el canal `SOLICITUD_VENTA`. Solicitudes repetidas con idéntica referencia devuelven la confirmación original sin duplicar el descuento de existencias ni quemar correlativos; cargas alteradas con la misma referencia son rechazadas con `409 IDEMPOTENCY_PAYLOAD_MISMATCH`.
     - `anularVenta`: Restituye existencias mediante `ORIGEN_ANULACION_VENTA` (`INCREMENTO`) con justificación obligatoria y previene re-anulaciones de ventas ya anuladas.
   - [`Controllers/API/VentaApiController.php`](../../Controllers/API/VentaApiController.php):
     - Requiere permiso `ventas.procesar`.
     - Fuerza `$data['id_usuario'] = (int)$this->authenticatedUserId;` (prevención de suplantación de identidad BOLA/IDOR).
     - Extrae `referencia_solicitud` desde el cuerpo JSON o encabezados `X-Request-Reference` / `X-Idempotency-Key`.
     - Mapea `InventarioException` a códigos canónicos de respuesta HTTP (409, 422, etc.).
   - [`Controllers/VentaController.php`](../../Controllers/VentaController.php):
     - En método `guardar()`, asigna usuario autenticado de sesión y extrae encabezado de referencia de solicitud, delegando la operación atómica a `VentaService`.
   - [`Assets/js/venta.js`](../../Assets/js/venta.js):
     - Forza cantidades enteras (`parseInt`), pasos de 1 en inputs (`step = "1"`, `min = "1"`), envía `referencia_solicitud` única (`VTA-<timestamp>-<rand>`) en el payload y formatea cantidades enteras en el ticket impreso.

2. **Suite de pruebas automatizadas de la Fase 4**:
   - [`tests/sprint2/fase4_ventas_kardex_test.php`](../../tests/sprint2/fase4_ventas_kardex_test.php): Ejecutada sobre `amazon_market_sprint2_test` con resultado **14 PASS / 0 FAIL**:
     - `F4-VTA-01`: Venta normal de línea única: descuenta stock atómicamente, incrementa correlativo y genera Kardex con snapshots y FKs (`id_accion_venta`, `id_detalle_venta`).
     - `F4-VTA-02`: Venta multi-línea del mismo producto: valida demanda agregada (4 + 6 = 10), genera múltiples detalles y movimientos Kardex correlativos.
     - `F4-VTA-03`: Consumo de stock exacto hasta 0: permite agotar existencias exactamente sin inconsistencia.
     - `F4-VTA-04`: Rechazo por stock insuficiente (`409 INSUFFICIENT_STOCK`): no altera stock ni quema correlativo de serie.
     - `F4-VTA-05`: Rechazo estricto de cantidades inválidas (decimal, decimal string, cero, negativo, texto) con `422 VALIDATION_ERROR`.
     - `F4-VTA-06`: Concurrencia entre ventas: bloqueo ordenado `FOR UPDATE` previene sobreventa; primera venta descuenta y segunda es rechazada 409.
     - `F4-VTA-07`: Operación combinada Compra y Venta: saldos kardex consistentes en secuencia (1 -> 11 compra -> 4 venta).
     - `F4-VTA-08`: Atomicidad y Rollback completo: fallo inducido revierte venta, kardex, stock y correlativo sin efectos residuales.
     - `F4-VTA-09`: Idempotencia de Venta: reintento devuelve respuesta idéntica sin doble descuento; payload alterado rechaza `409 IDEMPOTENCY_PAYLOAD_MISMATCH`.
     - `F4-VTA-10`: Seguridad de identidad del responsable: rechaza usuarios inexistentes (403/401) o no autenticados (401), blindando contra BOLA/IDOR.
     - `F4-VTA-11`: Validación comercial: Factura exige obligatoriamente cliente con RUC (`id_tipo_documento = 2`); rechaza DNI (422) y acepta RUC con serie F001.
     - `F4-VTA-12` Anulación de Venta: restituye existencias mediante `ANULACION_VENTA` (`INCREMENTO`), marca venta anulada y previene doble anulación.
     - `F4-CLEAN-01`: Limpieza final en base de pruebas: registros temporales eliminados y stock en 0 (`kardex = 0`, `ventas = 0`, `detalles = 0`).
     - `F4-ISO-01`: Aislamiento verificado: Base principal `amazon_market` no sufrió modificaciones (20 tablas, 106 productos con stock > 0, 98 ventas, 4 compras, 26 kardex).

3. **Verificación de regresión cruzada**:
   - Fase 2 (`fase2_infraestructura_test.php`): **24 PASS / 0 FAIL**.
   - Fase 3 (`fase3_productos_compras_test.php`): **13 PASS / 0 FAIL**.
   - Fase 4 (`fase4_ventas_kardex_test.php`): **14 PASS / 0 FAIL**.
   - Total acumulado de pruebas: **51 PASS / 0 FAIL**.

---

## 7. Dependencias para la Fase 5 (Bajas y ajustes)

- Conectar `Views/Inventario/ajustes.php` y los endpoints `POST /api/inventario/bajas` y `POST /api/inventario/ajustes` a `MovimientoInventarioService` y `ControlSolicitudService`.
- Implementar validación de `justificacion` obligatoria en ambas operaciones.
- Implementar validación de `saldo_anterior_esperado` en ajustes positivos y negativos (`409 STOCK_CHANGED_CONFLICT`).
- Conservar campo `lote` opcional en bajas ("Sin lote" si no se especifica).
- Manejo estricto de cantidades enteras positivas en envases individuales.
