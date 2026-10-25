# Estado de Fases — Sprint 2

**Proyecto:** Amazon-Market (`C:\xampp\htdocs\Amazon-Market`)  
**Rama activa:** `feature/inventario`  
**Última actualización:** 2026-10-25 (Revisión y cierre de Fase 1)

---

## 1. Plan Oficial y Estado de Fases del Sprint 2

| Fase | Nombre Oficial de la Fase | Alcance Principal | Base de Datos Utilizada | Estado |
|---|---|---|---|---|
| **Fase 1** | **Preparación y contratos** | Respaldo verificado, copia aislada de pruebas con escenario limpio, acuerdos, matriz de diferencias, contrato API (incluyendo selector de sentido, `referencia_solicitud` y `referencia_consulta`) y plan de pruebas. | `amazon_market_sprint2_test` (`amazon_market` intacta) | **Completada** |
| **Fase 2** | **Datos, seguridad y base común de movimientos** | Migraciones de estructura (`subcategoria`, instantáneas históricas en `kardex`, control de idempotencia `referencia_solicitud`, almacenamiento de `referencia_consulta`), validación de Administrador activo y compatibilidad de catálogos con POS. | `amazon_market_sprint2_test` | Pendiente |
| **Fase 3** | **Productos y Compras** | Productos nuevos con `stock_actual = 0`, bloqueo de edición de stock desde catálogo, validación entera de `stock_minimo`, conservación de presentaciones y equivalencias de Compras (ej. 2 cajas de 24 = 48 unidades individuales) y corrección/anulación sin movimientos ficticios en `kardex`. | `amazon_market_sprint2_test` | Pendiente |
| **Fase 4** | **Ventas** | Registro atómico de salidas de Venta en `kardex` junto con el descuento de `stock_actual` en unidades enteras individuales (`equivalencia = 1`), bloqueo `FOR UPDATE` e idempotencia. | `amazon_market_sprint2_test` | Pendiente |
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
