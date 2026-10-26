# Estado de Fases — Sprint 2

**Proyecto:** Amazon-Market (`C:\xampp\htdocs\Amazon-Market`)  
**Rama activa:** `feature/inventario`  
**Última actualización:** 2026-10-25 (Implementación de Fase 8 PDF; revisión visual pendiente)

---

## 1. Plan Oficial y Estado de Fases del Sprint 2

| Fase | Nombre Oficial de la Fase | Alcance Principal | Base de Datos Utilizada | Estado |
|---|---|---|---|---|
| **Fase 1** | **Preparación y contratos** | Respaldo verificado, copia aislada de pruebas con escenario limpio, acuerdos, matriz de diferencias, contrato API (incluyendo selector de sentido, `referencia_solicitud` y `referencia_consulta`) y plan de pruebas. | `amazon_market_sprint2_test` (`amazon_market` intacta) | **Completada** |
| **Fase 2** | **Datos, seguridad y base común de movimientos** | Migraciones de estructura (`subcategoria`, instantáneas históricas en `kardex`, control de idempotencia `referencia_solicitud`, almacenamiento de `referencia_consulta`), validación de Administrador activo, base común transaccional de movimientos y compatibilidad de catálogos con POS. | `amazon_market_sprint2_test` (`amazon_market` intacta) | **Completada** |
| **Fase 3** | **Productos y Compras** | Productos nuevos con `stock_actual = 0`, bloqueo de edición de stock desde catálogo, validación entera de `stock_minimo`, conservación de presentaciones y equivalencias de Compras (ej. 2 cajas de 24 = 48 unidades individuales) y corrección/anulación sin movimientos ficticios en `kardex`. | `amazon_market_sprint2_test` | **Completada** |
| **Fase 4** | **Ventas** | Registro atómico de salidas de Venta en `kardex` junto con el descuento de `stock_actual` en unidades enteras individuales (`equivalencia = 1`), bloqueo `FOR UPDATE` e idempotencia. | `amazon_market_sprint2_test` | **Completada** |
| **Fase 5** | **Bajas y ajustes** | Registro transaccional de Bajas (`Baja por daño`, `Baja por vencimiento` con `justificacion` obligatoria en ambas y lote opcional) y Ajustes por conteo (`Ajuste positivo`, `Ajuste negativo` con `justificacion` obligatoria y validación de `saldo_anterior_esperado`), conectando `Views/Inventario/ajustes.php`. | `amazon_market_sprint2_test` | **Completada** |
| **Fase 6** | **Kardex** | Consulta paginada de todos los orígenes que afectan existencias, cálculo de `resumen_producto`, clasificación histórica, consistencia mediante `referencia_consulta` y conexión de `Views/Reporte/kardex.php`. | `amazon_market_sprint2_test` | **Completada** |
| **Fase 7** | **Variaciones y nuevo selector de sentido** | Conexión real del Reporte de Variaciones reutilizando selector de sentido (`Todas`, `Entradas`, `Salidas`), consulta con `tipo_variacion` y `sentido` combinados por `AND`, resúmenes general y de producto único, exclusión de compras y ventas, y consistencia mediante `referencia_consulta`. | `amazon_market_sprint2_test` | **Completada** |
| **Fase 8** | **PDF** | Generación y descarga de PDF para Kardex (incluyendo producto específico sin movimientos con saldo inicial y cierre igual al inicial) y Variaciones, conservando colores institucionales, resumen ejecutivo previo al detalle e igualdad con `referencia_consulta`; botones existentes conectados. | `amazon_market_sprint2_test` | **Implementación y 24 comprobaciones aprobadas; revisión visual pendiente** |
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

## 7. Evidencias Reales de Cierre de la Fase 5

1. **Modelo y Servicio transaccional de Inventario**:
   - [`Models/OperacionInventarioModel.php`](../../Models/OperacionInventarioModel.php): Modelo para la tabla `operacion_inventario`.
   - [`Services/InventarioService.php`](../../Services/InventarioService.php):
     - `registrarBaja`: Valida administrador activo en BD, producto activo, motivo ("Baja por daño" o "Baja por vencimiento"), cantidad entera $\ge 1$, justificación obligatoria ($\le 255$ caracteres) y lote opcional. Ejecuta transacción con bloqueo determinístico (`bloquearProductosOrdenados`), inserta en `operacion_inventario`, descuenta `producto.stock_actual`, registra en `kardex` con FK `id_operacion_inventario` y snapshots históricos completos, e implementa idempotencia mediante `ControlSolicitudService` (`SOLICITUD_BAJA`).
     - `registrarAjuste`: Valida administrador activo en BD, producto activo, sentido/tipo ("Ajuste positivo" o "Ajuste negativo"), cantidad entera $\ge 1$, `saldo_anterior_esperado` obligatorio y justificación obligatoria. Comprueba bajo bloqueo `FOR UPDATE` que el saldo vigente en BD coincida exactamente con `saldo_anterior_esperado`; si cambió, rechaza con `409 Conflict` (`STOCK_CHANGED_CONFLICT`) devolviendo el saldo vigente sin aplicar diferencias obsoletas a ciegas. Inserta en `operacion_inventario`, actualiza stock, registra en `kardex` con snapshots e implementa idempotencia (`SOLICITUD_AJUSTE`).
2. **Controladores y Enrutamiento**:
   - [`Controllers/API/InventarioApiController.php`](../../Controllers/API/InventarioApiController.php): Endpoint RESTful `POST /api/inventario/bajas` y `POST /api/inventario/ajustes`. Requiere rol Administrador activo (`inventario.ajustes`), extrae `referencia_solicitud` y mapea `InventarioException` a códigos HTTP 409 y 422.
   - [`Controllers/InventarioController.php`](../../Controllers/InventarioController.php): Mantiene la renderización de `Views/Inventario/ajustes.php` y provee endpoints de respaldo para peticiones web tradicionales.
   - [`index.php`](../../index.php): Agregado `'inventario' => 'InventarioApiController'` al mapa de rutas de la API.
3. **Conexión del Frontend**:
   - [`Assets/js/inventario.js`](../../Assets/js/inventario.js):
     - Muestra subcategorías reales del catálogo en el resumen del producto (`product.subcategoria || 'General'`).
     - Conecta el modal de confirmación `#writeoff-confirmation` para ambas pestañas (Bajas y Ajustes).
     - Genera `referencia_solicitud` única (`baja-...` y `ajuste-...`) para garantizar idempotencia.
     - Gestiona retroalimentación visual desactivando el botón de envío durante el procesamiento.
     - Muestra alerta detallada con producto, cantidad aplicada y nuevo saldo tras confirmar.
     - Diferencia claramente un resultado confirmado nuevo, un resultado recuperado por repetición (`idempotent_replay: true`), un rechazo del servidor y un fallo de red.
     - Ante error `STOCK_CHANGED_CONFLICT`, actualiza el saldo en pantalla con el valor devuelto por el backend sin limpiar el formulario, permitiendo revisar y confirmar el nuevo intento.
4. **Suite de pruebas automatizadas de la Fase 5**:
   - [`tests/sprint2/fase5_bajas_ajustes_test.php`](../../tests/sprint2/fase5_bajas_ajustes_test.php): Ejecutada sobre `amazon_market_sprint2_test` con resultado **14 PASS / 0 FAIL**:
     - `F5-BAJA-01`: Baja con lote válido: registra `operacion_inventario`, descuenta stock y genera Kardex con snapshots y lote `CON_LOTE`.
     - `F5-BAJA-02`: Baja sin lote: registra con `numero_lote = NULL` y condición `SIN_LOTE` sin bloquear la operación.
     - `F5-BAJA-03`: Rechazo de Baja por motivo inválido, justificación vacía o >255 caracteres con `422 VALIDATION_ERROR` y stock intacto.
     - `F5-BAJA-04`: Rechazo de Baja por cantidad > stock (`409 INSUFFICIENT_STOCK`), decimales o cero (`422 VALIDATION_ERROR`).
     - `F5-AJUSTE-01`: Ajuste positivo por conteo físico: registra `operacion_inventario` (`INCREMENTO`), suma stock y genera Kardex.
     - `F5-AJUSTE-02`: Ajuste negativo por conteo físico: registra `operacion_inventario` (`DISMINUCION`), resta stock y genera Kardex.
     - `F5-AJUSTE-03`: Saldo cambiado durante preparación: rechaza con `409 STOCK_CHANGED_CONFLICT`, devuelve `stock_actual` vigente y `requiere_revision`.
     - `F5-AJUSTE-04`: Rechazo de Ajuste negativo que produciría saldo negativo con `409 INSUFFICIENT_STOCK`.
     - `F5-CONC-01`: Concurrencia entre salidas simultáneas (`FOR UPDATE`): primera descuenta y segunda es rechazada `409`.
     - `F5-IDEM-01`: Idempotencia en Bajas y Ajustes: reintento devuelve respuesta confirmada sin duplicar decremento; payload alterado rechaza `409`.
     - `F5-ATOM-01`: Atomicidad y Rollback completo: fallo inducido revierte operacion_inventario, kardex y stock sin efectos residuales.
     - `F5-SEC-01`: Seguridad y RBAC: rol Vendedor rechazado `403 FORBIDDEN_ADMIN_ONLY`; usuario inexistente rechazado `401`.
     - `F5-CLEAN-01`: Limpieza final en base de pruebas: registros temporales eliminados y stock en 0.
     - `F5-ISO-01`: Aislamiento verificado: Base principal `amazon_market` no sufrió modificaciones (20 tablas, 106 productos con stock > 0, 98 ventas, 4 compras, 26 kardex).

5. **Verificación de regresión cruzada acumulada**:
   - Fase 2 (`fase2_infraestructura_test.php`): **24 PASS / 0 FAIL**.
   - Fase 3 (`fase3_productos_compras_test.php`): **13 PASS / 0 FAIL**.
   - Fase 4 (`fase4_ventas_kardex_test.php`): **14 PASS / 0 FAIL**.
   - Fase 5 (`fase5_bajas_ajustes_test.php`): **14 PASS / 0 FAIL**.
   - Total acumulado de pruebas: **65 PASS / 0 FAIL**.

---

## 8. Evidencias Reales de Cierre de la Fase 6

1. **Modelo y Servicio canónico de Kardex**:
   - [`Models/KardexModel.php`](../../Models/KardexModel.php):
     - Eliminada completamente la reconstrucción inversa desde `stock_actual` y uniones sintéticas con compras/ventas sin registrar.
     - `obtenerSaldoInicial`: Consulta estrictamente el `stock_resultante` del último movimiento registrado antes de `fecha_inicio 00:00:00`. Si no hay movimientos previos, devuelve `0`.
     - `consultarKardexCanonico`: Consulta la tabla canónica `kardex` con rango inclusivo de fechas, producto, categoría histórica (`id_categoria_snapshot`), subcategoría histórica (`id_subcategoria_snapshot`) y texto `q`, ordenado por `fecha_movimiento ASC, id_kardex ASC`.
     - `guardarSnapshotConsulta` y `obtenerItemsSnapshotPaginados`: Persisten y recuperan el conjunto de filas ordenadas en `reporte_consulta_snapshot` y `reporte_consulta_snapshot_item` para asegurar consistencia inmutable en paginación y exportaciones.
   - [`Services/ReporteService.php`](../../Services/ReporteService.php):
     - `reporteKardex`:
       - Normalización de fechas con valores predeterminados canónicos (`fecha_inicio` primer día del mes actual, `fecha_fin` fecha actual). Validación de no inversión (`fecha_inicio <= fecha_fin`) y días completos (`00:00:00` a `23:59:59`).
       - Cálculo de `resumen_producto` con 5 tarjetas (`unidad_inventario`, `saldo_inicial`, `total_entradas`, `total_salidas`, `saldo_final`) exclusivamente cuando se selecciona un producto específico. Si el producto no tuvo movimientos en el período, conserva `saldo_inicial` real, `entradas = 0`, `salidas = 0`, `saldo_final = saldo_inicial` y marca `exportable = true`.
       - En consulta general (sin producto específico), omite `resumen_producto` (`resumen_producto.aplicable = false`) y no suma existencias de productos distintos. Si no hay movimientos en la consulta general, marca `exportable = false`.
       - Administración de `referencia_consulta`: genera token único y hash SHA-256 de filtros canónicos. Si se envía una referencia, comprueba vigencia (renovando 30 minutos), valida pertenencia de usuario autenticado (`403 QUERY_REFERENCE_FORBIDDEN`) y detecta alteración de filtros (`409 QUERY_FILTERS_MISMATCH`). Ante referencia vencida responde `410 QUERY_REFERENCE_EXPIRED`.
       - Devuelve estructura contractual con colecciones `items`, `pagination` y alias de compatibilidad `movimientos`, `paginacion`.
   - [`Controllers/API/ReporteApiController.php`](../../Controllers/API/ReporteApiController.php):
     - Endpoint `GET /api/reportes/kardex` protegido con `requireAdminActivo('reportes.kardex')`. Pasa el identificador de usuario autenticado al servicio y captura `InventarioException` respondiendo los códigos HTTP y formatos canónicos (403, 409, 410, 422).
   - [`Controllers/ReporteController.php`](../../Controllers/ReporteController.php):
     - Acción `kardex()` verifica sesión activa y rol Administrador activo (`id_rol = 1`).

2. **Conexión de Frontend**:
   - [`Views/Reporte/kardex.php`](../../Views/Reporte/kardex.php):
     - Fecha inicial predeterminada al primer día del mes en curso (`Y-m-01`).
     - Selector de subcategorías conectado con opción "Todas".
   - [`Assets/js/kardex.js`](../../Assets/js/kardex.js):
     - Carga dinámica de subcategorías dependiente de la categoría seleccionada usando `GET /api/categorias?modo=reporte`.
     - Buscador de productos utilizando `GET /api/productos?modo=reporte` para incluir productos inactivos con historial.
     - Poblado de las 5 tarjetas del resumen por producto (`#kardex-product-summary`).
     - Conservación de `currentConsultaRef` a través de los botones de paginación anterior/siguiente.
     - Limpieza de referencia al pulsar "Consultar", "Limpiar filtros" o modificar criterios de búsqueda para generar una nueva instantánea congelada.

3. **Suite de pruebas automatizadas de la Fase 6**:
   - [`tests/sprint2/fase6_kardex_test.php`](../../tests/sprint2/fase6_kardex_test.php): Ejecutada sobre `amazon_market_sprint2_test` con resultado **18 PASS / 0 FAIL**:
     - `F6-SALDO-01`: Cálculo canónico de saldo_inicial previo (50), entradas (5), salidas (13) y saldo_final histórico (42). Movimientos posteriores al período no alteran el cierre histórico.
     - `F6-PROD-ZERO-01`: Producto sin actividad en el período conserva saldo_inicial real (50), entradas=0, salidas=0, saldo_final=50 y `exportable = true`.
     - `F6-PROD-ZERO-02`: Producto nuevo sin movimientos previos ni en rango: saldo_inicial=0, saldo_final=0 y `exportable = true`.
     - `F6-INACT-01`: Producto inactivo con historial es consultable y expone `estado_producto = 'Inactivo'`.
     - `F6-GEN-01`: Consulta general: `resumen_producto.aplicable = false` sin sumar saldos entre productos y `exportable = true`.
     - `F6-GEN-ZERO-01`: Consulta general sin resultados: total=0 y `exportable = false`.
     - `F6-ORDER-01`: Movimientos con fecha y hora idénticas mantienen orden determinístico estricto por `id_kardex` y continuidad de saldos.
     - `F6-HIST-CAT-01`: Filtro por clasificación histórica respeta snapshot (Bebidas) aun si el catálogo actual del producto cambió a Abarrotes.
     - `F6-PAGE-01`: Paginación consistente mediante `referencia_consulta`: página 1 y página 2 sin saltos ni duplicados.
     - `F6-SNAP-IMMUN-01`: Instantánea congelada bajo `referencia_consulta` es inmune a transacciones concurrentes posteriores.
     - `F6-REF-EXPIRE-01`: Referencia de consulta vencida responde `410 QUERY_REFERENCE_EXPIRED`.
     - `F6-REF-FORBID-01`: Referencia de consulta ajena al usuario solicitante responde `403 QUERY_REFERENCE_FORBIDDEN`.
     - `F6-REF-MISMATCH-01`: Alterar filtros manteniendo la referencia activa rechaza `409 QUERY_FILTERS_MISMATCH`.
     - `F6-VAL-01`: Validación estricta: fechas invertidas, formatos no válidos y producto inexistente responden `422 VALIDATION_ERROR`.
     - `F6-READONLY-01`: La consulta de Kardex es estrictamente de solo lectura: existencias y movimientos permanecen 100% inalterados.
     - `F6-RBAC-01`: Seguridad RBAC: rol Administrador posee permiso `reportes.kardex`; rol Vendedor es rechazado.
     - `F6-CLEAN-01`: Limpieza final en base de pruebas: registros temporales eliminados y stock en 0.
     - `F6-ISO-01`: Aislamiento verificado: Base principal `amazon_market` no sufrió modificaciones (20 tablas, 106 productos con stock > 0, 98 ventas, 4 compras, 26 kardex).

4. **Verificación de regresión cruzada acumulada**:
   - Fase 2 (`fase2_infraestructura_test.php`): **24 PASS / 0 FAIL**.
   - Fase 3 (`fase3_productos_compras_test.php`): **13 PASS / 0 FAIL**.
   - Fase 4 (`fase4_ventas_kardex_test.php`): **14 PASS / 0 FAIL**.
   - Fase 5 (`fase5_bajas_ajustes_test.php`): **14 PASS / 0 FAIL**.
   - Fase 6 (`fase6_kardex_test.php`): **18 PASS / 0 FAIL**.
   - **Total acumulado de pruebas: 83 PASS / 0 FAIL**.

---

## 9. Evidencias Reales de Cierre de la Fase 7

1. **Modelo y Servicio Canónico de Variaciones**:
   - [`Models/KardexModel.php`](../../Models/KardexModel.php):
     - `consultarVariacionesCanonicas(array $filtros)`: Filtra estrictamente por `k.origen_operacion IN ('BAJA_DANO', 'BAJA_VENCIMIENTO', 'AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO')` dentro del rango cronológico inclusivo, con exclusión total de compras, ventas y sus anulaciones o correcciones. Aplica filtros opcionales de producto (`id_producto`), búsqueda textual en snapshot (`q`), categoría histórica (`id_categoria_snapshot`), subcategoría histórica (`id_subcategoria_snapshot`), `tipo_movimiento` y `sentido_movimiento` combinados por `AND`, ordenados determinísticamente por `fecha_movimiento ASC, id_kardex ASC`.
   - [`Services/ReporteService.php`](../../Services/ReporteService.php):
     - `reporteVariaciones` / `ejecutarReporteVariaciones`:
       - Normalización y validación estricta de fechas (`fecha_inicio <= fecha_fin`), rechazando fechas invertidas o inexistentes con `422 VALIDATION_ERROR`.
       - Normalización del selector de sentido (`TODOS`, `INCREMENTO`, `DISMINUCION`), mapeando valores visibles (`"Todas"`, `"Entradas"`, `"Salidas"`) y rechazando sentidos no válidos con `422 VALIDATION_ERROR`.
       - Cálculo canónico del resumen:
         - Vista multi-producto: `total_operaciones`, `productos_con_variaciones`, `es_producto_unico = false`, `resumen_producto_unico = null`, `resumen_por_producto` con desglose exacto de operaciones, incrementos y disminuciones por producto, y `totales_por_tipo` para cada una de las 4 variaciones (`baja_por_dano`, `baja_por_vencimiento`, `ajuste_positivo`, `ajuste_negativo`).
         - Vista de producto único: cuando el resultado corresponde a un solo producto, expone `es_producto_unico = true` y `resumen_producto_unico` con `operaciones`, `incrementos` (`+N`) y `disminuciones` (`−N`).
       - Regla de exportabilidad sin resultados: si no existen variaciones coincidentes, devuelve `total_operaciones = 0`, `items = []` y `exportable = false`.
       - Consistencia de consulta congelada (`referencia_consulta`): genera snapshot con `tipo_reporte = 'variaciones'`, comprobando expiración (`410 QUERY_REFERENCE_EXPIRED`), pertenencia del usuario (`403 QUERY_REFERENCE_FORBIDDEN`) y conflicto ante filtros alterados (`409 QUERY_FILTERS_MISMATCH`).
   - [`Controllers/API/ReporteApiController.php`](../../Controllers/API/ReporteApiController.php):
     - Reconocimiento real de `GET /api/reportes/variaciones` protegido con `requireAdminActivo('reportes.variaciones')`, delegando a `ReporteService::reporteVariaciones` y mapeando excepciones a respuestas HTTP JSON (403, 409, 410, 422).
   - [`Controllers/ReporteController.php`](../../Controllers/ReporteController.php):
     - Acción web `variaciones()` protegida con sesión activa y rol Administrador (`id_rol = 1`).

2. **Conexión de Frontend**:
   - [`Views/Reporte/variaciones.php`](../../Views/Reporte/variaciones.php):
     - `#variation-start-date` ajustado a fecha inicial dinámica del mes (`<?= date('Y-m-01') ?>`).
     - Conservado el selector de sentido `#variation-direction` (`""` -> Todos, `"Incremento"` -> Entradas, `"Disminución"` -> Salidas) y la grilla de filtros.
   - [`Assets/js/variaciones.js`](../../Assets/js/variaciones.js):
     - Eliminados los datos de demostración fijos; consumo en tiempo real de `GET /api/reportes/variaciones` y `GET /api/categorias?modo=reporte`.
     - Carga dinámica en cascada de subcategorías dependientes de la categoría seleccionada.
     - Separación estricta entre filtros en edición y filtros activos aplicados (`appliedFilters`): al paginar con `referencia_consulta`, se envían los filtros guardados en la consulta sin verse afectados por ediciones pendientes en los controles de formulario.
     - Renderizado de las 9 columnas de la tabla con insignias de tipo, entradas y salidas coloreadas y formateadas.
     - Conmutación automática entre `#variation-all-products-summary` (con tabla desglosada por producto `#variation-product-breakdown`) y `#variation-single-product-summary`.
     - Deshabilitación de botones de exportación cuando `exportable === false`.

3. **Suite de Pruebas Automatizadas de la Fase 7**:
   - [`tests/sprint2/fase7_variaciones_test.php`](../../tests/sprint2/fase7_variaciones_test.php): Ejecutada sobre `amazon_market_sprint2_test` con resultado **22 PASS / 0 FAIL**:
     - `F7-ORIGEN-01`: Reporte incluye exactamente los 4 orígenes válidos (`BAJA_DANO`, `BAJA_VENCIMIENTO`, `AJUSTE_POSITIVO`, `AJUSTE_NEGATIVO`).
     - `F7-EXCLUSION-01`: Exclusión estricta de compras y ventas.
     - `F7-SENTIDO-TODOS-01`: Sentido `TODOS` devuelve entradas y salidas.
     - `F7-SENTIDO-INC-01`: Sentido `INCREMENTO` devuelve exclusivamente entradas (ajustes positivos).
     - `F7-SENTIDO-DISM-01`: Sentido `DISMINUCION` devuelve exclusivamente salidas (bajas y ajustes negativos).
     - `F7-SENTIDO-INV-01`: Sentido no válido rechazado con `422 VALIDATION_ERROR`.
     - `F7-AND-COMB-01`: Combinación `AND` válida (`tipo_variacion` + `sentido`) devuelve resultados filtrados.
     - `F7-AND-EMPTY-01`: Combinación excluyente devuelve `total = 0`, `items = []` y `exportable = false` sin modificar filtros.
     - `F7-VAL-FECHAS-01`: Fechas invertidas o inexistentes rechazan `422 VALIDATION_ERROR`.
     - `F7-HIST-CAT-01`: Filtro evalúa exclusivamente clasificación histórica grabada en el snapshot.
     - `F7-INACT-01`: Producto inactivo con historial de variaciones es plenamente consultable.
     - `F7-RESUMEN-MULTI-01`: Resumen multi-producto exacto con desglose por producto y totales por tipo.
     - `F7-RESUMEN-SINGLE-01`: Resumen de producto único activa tarjeta individual con `+incrementos` y `−disminuciones`.
     - `F7-ZERO-EXPORT-01`: Consulta sin resultados marca `exportable = false`.
     - `F7-PAGE-CONSIST-01`: Paginación mediante `referencia_consulta` sin solapamiento ni saltos.
     - `F7-SNAP-IMMUN-01`: Instantánea congelada bajo `referencia_consulta` es inmune a transacciones concurrentes posteriores.
     - `F7-SNAP-EXPIRE-01`: Referencia de consulta vencida responde `410 QUERY_REFERENCE_EXPIRED`.
     - `F7-SNAP-FORBID-01`: Referencia de otro usuario responde `403 QUERY_REFERENCE_FORBIDDEN`.
     - `F7-SNAP-MISMATCH-01`: Alterar filtros manteniendo referencia activa responde `409 QUERY_FILTERS_MISMATCH`.
     - `F7-RBAC-01`: Permiso `reportes.variaciones` exclusivo de Administrador y denegado a Vendedor; controlador web protegido.
     - `F7-CLEAN-01`: Limpieza controlada en base de pruebas.
     - `F7-ISO-01`: Aislamiento verificado de la base principal `amazon_market` (20 tablas, 106 productos con stock > 0, 98 ventas, 4 compras, 26 kardex intactos).

4. **Verificación de Regresión Cruzada Acumulada del Sprint 2**:
   - Fase 2 (`fase2_infraestructura_test.php`): **24 PASS / 0 FAIL**.
   - Fase 3 (`fase3_productos_compras_test.php`): **13 PASS / 0 FAIL**.
   - Fase 4 (`fase4_ventas_kardex_test.php`): **14 PASS / 0 FAIL**.
   - Fase 5 (`fase5_bajas_ajustes_test.php`): **14 PASS / 0 FAIL**.
   - Fase 6 (`fase6_kardex_test.php`): **18 PASS / 0 FAIL**.
   - Fase 7 (`fase7_variaciones_test.php`): **22 PASS / 0 FAIL**.
   - **Total acumulado de pruebas: 105 PASS / 0 FAIL**.

---

## Correcciones posteriores de las fases 1 a 7 (25/10/2026)

Se corrigieron los saldos con filtros históricos, las validaciones de reportes, las referencias obligatorias y estables de compras/ventas y la paginación del Kardex. La prueba de restricción SQL de la fase 2 ahora verifica el error real de la restricción; antes podía aprobar por columnas inexistentes.

La verificación y los comandos del entorno web aislado están en [correcciones-fases1-7.md](correcciones-fases1-7.md). La base habitual no se migró. La implementación funcional de la Fase 8 está realizada y probada; su inspección visual final queda pendiente por indisponibilidad del renderizador local. Las fases 9 y 10 continúan pendientes.

## 10. Evidencias y pendiente visual de la Fase 8 (PDF)

- `ReporteApiController::get('exportar-pdf')` genera Kardex y Variaciones con autorización Admin y respuestas JSON para referencias/filtros inválidos.
- `ReporteKardexPDF` y `ReporteVariacionesPDF` reutilizan `ReportPDF`/FPDF, incluyen resumen ejecutivo, filtros, responsable, lotes y detalle de la instantánea.
- `Assets/js/kardex.js` y `Assets/js/variaciones.js` conectan los botones existentes; `Assets/js/api.js` descarga el binario y reporta los errores API.
- `tests/sprint2/fase8_pdf_test.php`: **21 PASS / 0 FAIL** sobre `amazon_market_sprint2_test`, incluyendo permiso, propiedad, vencimiento, filtros alterados, congelación, caso individual sin movimientos, bloqueos 422 para consultas generales vacías y compatibilidad de un PDF A4 vertical existente.
- `tests/sprint2/fase8_frontend_download_test.js`: **3 PASS / 0 FAIL**, comprobando la acción de ambos botones, el nombre/descarga del binario y el manejo de error JSON.
- El PDF toma fecha y hora de la base de datos para que el encabezado y nombre de archivo coincidan con el reloj local de los movimientos.
- Pendiente: renderizar y revisar visualmente ambos PDF. El entorno no tiene renderizador disponible y el canal nativo de Windows Computer Use falló al iniciarse; sí se validaron binario, cabeceras y extracción de texto.
