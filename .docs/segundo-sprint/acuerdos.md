# Acuerdos Obligatorios y Definición de Alcance — Sprint 2

**Proyecto:** Amazon-Market (`C:\xampp\htdocs\Amazon-Market`)  
**Rama de trabajo:** `feature/inventario`  
**Fase actual:** Fase 10 — Verificación integral y actualización controlada completada

---

## 1. Arquitectura y Restricciones Técnicas

1. **Stack y arquitectura:** El proyecto conserva su arquitectura propia en PHP 8.2+ (MVC con `Controllers`, `Controllers/API`, `Services`, `Models`, `Libraries/Core`), JavaScript nativo en `Assets/js/` y MariaDB 10.4.
2. **Sin dependencias externas nuevas:** Se mantienen las bibliotecas existentes (`Libraries/PDF/fpdf.php`, `ReportPDF.php`, `Services/ExcelService.php`). No se introducen frameworks ni reestructuraciones generales.
3. **Jerarquía documental:** Los acuerdos registrados en este documento prevalecen sobre las diferencias de:
   - `.docs/Análisis de Requerimiento - Sprint 2.docx`
   - `10 Historias de Usuario (1).pdf` (HU-07, HU-08, HU-09, HU-10)
   - `.docs/acuerdos-modulo-compras.md` y documentos de avance previos.
   Toda diferencia queda expresamente registrada en [`matriz-diferencias.md`](./matriz-diferencias.md).

---

## 2. Plan Oficial de Fases del Sprint 2

El desarrollo y verificación del Sprint 2 se organiza en **10 fases secuenciales**:

| Fase | Nombre Oficial de la Fase | Entorno de Base de Datos |
|---|---|---|
| **Fase 1** | Preparación y contratos | `amazon_market_sprint2_test` (`amazon_market` intacta) |
| **Fase 2** | Datos, seguridad y base común de movimientos | `amazon_market_sprint2_test` |
| **Fase 3** | Productos y Compras | `amazon_market_sprint2_test` |
| **Fase 4** | Ventas | `amazon_market_sprint2_test` |
| **Fase 5** | Bajas y ajustes | `amazon_market_sprint2_test` |
| **Fase 6** | Kardex | `amazon_market_sprint2_test` |
| **Fase 7** | Variaciones y nuevo selector de sentido | `amazon_market_sprint2_test` |
| **Fase 8** | PDF | `amazon_market_sprint2_test` |
| **Fase 9** | Excel | `amazon_market_sprint2_test` |
| **Fase 10** | Verificación integral y actualización controlada | `amazon_market_sprint2_test` y actualización final en `amazon_market` |

La ejecución de la Fase 10 está completada; los resultados, el respaldo y la limitación del servidor HTTP de prueba están en [estado-fases.md](estado-fases.md) y [plan-fase10-verificacion-integral.md](plan-fase10-verificacion-integral.md).

---

## 3. Alcance del Sprint 2 vs. Diferidos al Sprint 4

### 3.1 Incluido en el Sprint 2
- **HU-07 — Registro de Bajas de Inventario (Fase 5):** Bajas definitivas de stock por `Baja por daño` o `Baja por vencimiento`, con **justificación obligatoria en ambos motivos**, descuento inmediato sobre el stock general del producto y trazabilidad en Kardex y Reporte de Variaciones.
- **HU-08 — Registro de Ajustes por Conteo Físico (Fase 5):** Ajustes manuales positivos (`Ajuste positivo`, incremento) y negativos (`Ajuste negativo`, disminución) por diferencias reales de conteo físico, con justificación/motivo obligatorio.
- **HU-09 — Consulta y Exportación del Kardex (Fases 6, 8 y 9):** Historial completo y auditable de todos los orígenes que afectan existencias (`Compra`, `Anulación de compra`, `Corrección de compra`, `Venta`, `Anulación de venta`, `Baja por daño`, `Baja por vencimiento`, `Ajuste positivo`, `Ajuste negativo`), con cálculo cronológico de saldo inicial, entradas, salidas y saldo final por producto, consistencia mediante `referencia_consulta` administrada por el servidor, paginación y exportación en **PDF** y **Excel**.
- **HU-10 — Reporte de Variaciones de Inventario (Fases 7, 8 y 9):** Consulta, resumen y exportación en **PDF** y **Excel** que incluye **únicamente** las bajas (`Baja por daño`, `Baja por vencimiento`) y los ajustes por conteo (`Ajuste positivo`, `Ajuste negativo`), excluyendo compras, ventas y sus correcciones o anulaciones. Incluye la incorporación autorizada del nuevo selector independiente **“Sentido del movimiento”** (`Todas`, `Entradas`, `Salidas`).
- **Correcciones transversales de integridad de inventario (Fases 2, 3 y 4):**
  - **Productos (Fase 3):** Stock inicial cero al crear producto; prohibición de editar `stock_actual` desde formularios o endpoints de catálogo; validación entera de `stock_minimo`.
  - **Compras (Fase 3):** Conservación de las presentaciones y equivalencias existentes de Compras (por ejemplo, una compra de 2 cajas de 24 ingresa 48 unidades individuales al stock general) y ajuste de registro, corrección y anulación para sostener integridad transaccional, bloqueo de fila, idempotencia y ausencia de movimientos ficticios en `kardex` cuando una corrección solo cambia costos o datos informativos sin alterar las unidades.
  - **Ventas (Fase 4):** Registro atómico de la salida en `kardex` junto con el descuento de `stock_actual` en unidades enteras individuales (`equivalencia = 1`), bloqueo de fila e idempotencia.

### 3.2 Diferido expresamente al Sprint 4
1. **Venta por cajas y packs y su configuración comercial:** Queda diferida al Sprint 4 únicamente la **venta** por presentaciones múltiples y su configuración comercial. En el Sprint 2 las ventas descuentan envases individuales (`equivalencia = 1`), mientras que **Compras conserva íntegramente sus presentaciones y equivalencias actuales**.
2. **Función para rectificar bajas o ajustes ya confirmados:** Una vez confirmada una baja o un ajuste de inventario, queda en firme. **Nota:** No se confunde esta función diferida con las correcciones y anulaciones de Compras que ya existen en el sistema y deben funcionar correctamente en el Sprint 2.

---

## 4. Diseño del Frontend y Modificación Visual Autorizada

1. **Conservación del diseño actual:** Se conservan las vistas, columnas y controles actuales de `Views/Inventario/ajustes.php`, `Views/Reporte/kardex.php`, `Views/Reporte/variaciones.php` y `Views/Producto/*`, conectándolos a datos reales, corrigiendo su comportamiento e incorporando mensajes de validación.
2. **Única adición visual expresamente autorizada (Fase 7):**
   - En `Views/Reporte/variaciones.php` (Reportes → Variaciones) se autoriza agregar un selector independiente del selector actual “Tipo de variación”:
     - **Etiqueta:** `Sentido del movimiento`
     - **Opciones visibles:** `Todas`, `Entradas`, `Salidas` (valor inicial y al limpiar filtros: `Todas`).
     - **Correspondencia con el parámetro `sentido` del contrato:** `Todas` → `TODOS`, `Entradas` → `INCREMENTO`, `Salidas` → `DISMINUCION`.
     - **Combinación lógica:** No reemplaza al filtro “Tipo de variación” (`tipo_variacion`). Ambos filtros se combinan mediante `AND` junto con producto, búsqueda, categoría histórica, subcategoría histórica y fechas. Si una combinación válida no tiene coincidencias (por ejemplo, `tipo_variacion = "Baja por vencimiento"` y `sentido = "INCREMENTO"`), devuelve un resultado vacío sin alterar automáticamente ningún filtro.
3. **Prohibición de adiciones no autorizadas:** Fuera del selector “Sentido del movimiento” en Variaciones, no se agregan pantallas, columnas, filtros ni acciones visuales por iniciativa propia.

---

## 5. Reglas de Negocio y Operación de Inventario

### 5.1 Unidad de Control, Presentaciones de Compra y Lote Opcional (Acuerdo 6)
- **Inventario en unidades enteras de envases individuales:** `producto.stock_actual`, `producto.stock_minimo` y las cantidades en `kardex` se controlan siempre en números enteros de envases individuales ($\ge 0$ en saldos, $\ge 1$ en movimientos). No se admiten decimales.
- **Unidades de medida descriptivas:** El catálogo `unidad_medida` (`UND`, `KG`, `LTR`, `PQT`) es descriptivo del envase individual y no habilita decimales en existencias.
- **Presentaciones en Compras vs. Ventas:**
  - En **Compras**, se conservan las presentaciones (`presentacion`) y su equivalencia en unidades individuales (`factor_conversion` / `equivalencia` entera $\ge 1$): comprar `N` presentaciones de factor `F` ingresa `N × F` unidades enteras individuales a `producto.stock_actual` y a `kardex` (ej.: 2 cajas de 24 ingresan 48 unidades individuales).
  - En **Ventas**, **Bajas** y **Ajustes**, la operación se registra directamente en unidades enteras de envases individuales (`equivalencia = 1`), dado que la venta por cajas y packs está diferida al Sprint 4.
- **Lote opcional e informativo:**
  - En bajas de inventario, el campo de lote (`#writeoff-lot`) es opcional; si falta o no se lee, se guarda `lote = NULL` y se expone como `"Sin lote"` (o `"Sin referencia de lote"` en reportes).
  - El lote no tiene saldo propio ni se asigna automáticamente desde otras operaciones.

### 5.2 Justificación Obligatoria en Bajas y Ajustes
- **Bajas de inventario (HU-07):** La **justificación** (`justificacion`, campo `#writeoff-justification` en `Views/Inventario/ajustes.php`) es **obligatoria tanto en `Baja por daño` como en `Baja por vencimiento`**. No puede omitirse ni enviarse solo con espacios en blanco en ninguno de los dos motivos.
- **Ajustes por conteo (HU-08):** La **justificación / motivo** (`justificacion`, campo `#stock-adjustment-justification` en `Views/Inventario/ajustes.php`) es **obligatoria tanto en `Ajuste positivo` como en `Ajuste negativo`**.
- **Sin longitudes arbitrarias inventadas:** La validación exige que `justificacion` sea un texto no vacío tras `trim()` dentro de la capacidad de almacenamiento del campo, sin imponer longitudes mínimas artificiales ajenas a las historias de usuario.

### 5.3 Permisos de Administrador y Compatibilidad con el Vendedor / POS (Acuerdo 7)
- **Exclusividad de rol Administrador activo en funciones del Sprint 2:** Solo usuarios autenticados con estado activo en base de datos (`usuario.estado = 1`) y rol **Administrador** (`usuario.id_rol = 1`) pueden acceder y ejecutar:
  - Vistas y endpoints de Bajas y Ajustes (`/inventario/ajustes`, `/api/inventario/bajas`, `/api/inventario/ajustes`).
  - Vistas, consultas y exportaciones PDF/Excel de Kardex y Variaciones (`/reporte/kardex`, `/reporte/variaciones`, `/api/reportes/*`).
  - Modo de búsqueda de reportes en catálogos (`GET /api/productos?modo=reporte`, `GET /api/categorias?modo=reporte`).
- **Conservación de catálogos compartidos para Vendedor y POS:**
  - Los endpoints generales `GET /api/productos` y `GET /api/categorias` (sin `modo=reporte`) **mantienen su permiso actual (`productos.listar`) y su estructura de respuesta actual** para no interrumpir la operación del rol Vendedor (`id_rol = 2`) ni del módulo de Ventas / POS.

### 5.4 Catálogo de Productos y Existencias (Acuerdo 8)
- Todo producto nuevo se registra con `stock_actual = 0`.
- No se permite modificar `stock_actual` desde formularios ni endpoints de Productos (`ProductoController`, `ProductoApiController`, `ProductoService`).
- Las existencias se explican exclusivamente mediante operaciones transaccionales y sus movimientos en `kardex`.

### 5.5 Atomicidad, Concurrencia e Idempotencia de Solicitudes (Acuerdo 9)
- **Transacción única y bloqueo pesimista:** Cada operación que modifica existencias ejecuta en una única transacción (`START TRANSACTION ... COMMIT`) el bloqueo de las filas de `producto` (`SELECT ... FOR UPDATE` en orden ascendente de `id_producto`), la validación de stock no negativo, el registro de la operación, la actualización de `producto.stock_actual` y la inserción del movimiento en `kardex`. Ante cualquier fallo intermedio se ejecuta `ROLLBACK` total.
- **Validación de saldo base en ajustes:** Si `producto.stock_actual` cambió respecto al `saldo_anterior_esperado` que revisó el administrador en pantalla, se rechaza con `409 Conflict` (`STOCK_CHANGED_CONFLICT`), informando el nuevo saldo para revisión expresa.
- **Manejo diferenciado de `referencia_solicitud`:**
  - Formato unificado en todo el contrato: cadena de 16 a 64 caracteres (`^[A-Za-z0-9\-_]{16,64}$`), con alcance único por `(id_usuario, tipo_operacion, referencia_solicitud)`.
  - **Solicitud ya confirmada con los mismos datos:** Devuelve `200 OK` recuperando el resultado original ya registrado (`idempotent_replay: true`), sin volver a aplicar la operación ni tocar el stock.
  - **Misma referencia con datos diferentes:** Rechaza con `409 Conflict` (`IDEMPOTENCY_PAYLOAD_MISMATCH`).
  - **Solicitud todavía en procesamiento:** Informa su estado con `409 Conflict` (`REQUEST_IN_PROGRESS`) sin duplicar la operación.

### 5.6 Conservación Histórica y Clasificación en Reportes (Acuerdo 10)
- Cada movimiento en `kardex` almacena la instantánea (*snapshot*) de código, nombre, categoría, subcategoría, unidad de medida y usuario responsable vigentes en el momento exacto del movimiento.
- **Filtro exclusivo por clasificación histórica:** En Kardex y Variaciones, los filtros de categoría y subcategoría evalúan **exclusivamente la clasificación histórica guardada en el movimiento**, de modo que cambios posteriores en el catálogo nunca reescriben ni alteran los reportes históricos.
- Los productos inactivos (`producto.estado = 0`) conservan todo su historial consultable y exportable en Kardex y Variaciones.

### 5.7 Reglas de Kardex, Variaciones, Consistencia de Consulta y Exportaciones (Acuerdos 14 y 15)
- **Orígenes:** Kardex incluye todos los orígenes que afectan existencias; Variaciones incluye únicamente `Baja por daño`, `Baja por vencimiento`, `Ajuste positivo` y `Ajuste negativo`.
- **Resúmenes alineados con las pantallas reales:**
  - En **Kardex**, el resumen por producto (`Unidad de inventario`, `Saldo inicial`, `Entradas`, `Salidas`, `Saldo final`) se calcula solo cuando se selecciona un producto específico. Jamás se suman existencias de productos distintos como un saldo global.
  - En **Variaciones**, el resumen proporciona los datos exactos para los dos modos de `Views/Reporte/variaciones.php`: vista general (`Total de operaciones`, `Productos con variaciones` y tabla desglosada por producto con `Operaciones`, `Incrementos` y `Disminuciones`) y vista de un solo producto (`Total de operaciones`, `Incrementos`, `Disminuciones`).
- **Consistencia de consulta administrada por el servidor (`referencia_consulta`):**
  - El servidor persiste una instantánea de consulta (`referencia_consulta`) asociada al usuario administrador, los filtros aplicados (`hash_filtros`), el resumen calculado y la lista ordenada de movimientos (`id_kardex`) que integran el resultado.
  - La paginación y las exportaciones PDF/Excel leen esa `referencia_consulta`, garantizando igualdad exacta entre páginas, resumen, PDF y Excel incluso si otras transacciones se confirman después, sin mantener transacciones abiertas entre peticiones HTTP.
  - Si la referencia venció se informa su expiración (`410 Gone`), si pertenece a otro usuario se rechaza (`403 Forbidden`) y si se intenta alterar algún filtro conservando la misma referencia se rechaza el conflicto (`409 Conflict`).
- **Regla de exportación sin movimientos:**
  - **Kardex de un producto específico (`id_producto`) sin movimientos en el período:** **Permite exportar** PDF y Excel mostrando el resumen con el saldo inicial real, entradas `0`, salidas `0` y saldo de cierre igual al saldo inicial.
  - **Kardex general (sin `id_producto`) sin resultados:** **No permite exportación**.
  - **Variaciones sin resultados:** **No permite exportación**.

---

## 6. Estrategia de Base de Datos y Entorno de Pruebas (Acuerdos 11, 12 y 13)

1. **Scripts SQL numerados en `database/segundo_sprint/`:** Separados estrictamente entre preparación de escenario limpio (`00`), estructura (`01`), configuración (`02`) y actualización controlada final de Fase 10 (`10`).
2. **Aislamiento verificado (Fases 1 a 9):**
   - Base habitual: `amazon_market` (**intacta durante las Fases 1 a 9**).
   - Base aislada de pruebas: `amazon_market_sprint2_test` (con escenario limpio ya aplicado en Fase 1: catálogo íntegro de 107 productos con `stock_actual = 0` y sin operaciones antiguas de prueba).
3. **Actualización controlada en la base actual (Fase 10):** Se ejecutó exclusivamente en la Fase 10 después de completar las verificaciones de las fases 1 a 9; el respaldo, archivado, sincronización y resultados quedaron registrados en [plan-fase10-verificacion-integral.md](plan-fase10-verificacion-integral.md).
