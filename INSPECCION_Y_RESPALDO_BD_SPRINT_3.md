# Inspección de la Base de Datos Real, Línea Base y Respaldo Verificado — Sprint 3

**Proyecto:** Amazon Market (`C:\xampp\htdocs\Amazon-Market`)  
**Etapa del plan:** Paso 3 — Inspeccionar la base y preparar su conservación  
**Referencias normativas:**
- [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](file:///c:/xampp/htdocs/Amazon-Market/ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md)
- [MATRIZ_PERMISOS_SPRINT_3.md](file:///c:/xampp/htdocs/Amazon-Market/MATRIZ_PERMISOS_SPRINT_3.md)

---

## 1. Fecha, Rama y Commit Inspeccionados

- **Fecha y hora de inspección (`America/Lima` / `UTC-05:00`):** `2026-11-12 01:18:23` a `2026-11-12 01:25:36`.
- **Carpeta de trabajo:** `C:\xampp\htdocs\Amazon-Market`.
- **Archivo `AGENTS.md`:** No existe en el repositorio (`Test-Path AGENTS.md = False`).
- **Rama activa:** `feature/caja`.
- **Commit actual (`HEAD`):** `c29be5a39799379e1af12d2d49ed7128bab25e1c`.
- **Estado de Git conservado:** Árbol de código limpio; únicamente los documentos de planificación del Sprint 3 sin cambios en archivos PHP, JS, CSS, Word, `.env` ni SQL.

---

## 2. Identificación del Entorno y Base Original (sin Secretos)

### 2.1. Mecanismo real de conexión de la aplicación

La aplicación obtiene sus parámetros de conexión mediante el siguiente flujo:
1. [index.php](file:///c:/xampp/htdocs/Amazon-Market/index.php#L8) carga [Config/Config.php](file:///c:/xampp/htdocs/Amazon-Market/Config/Config.php#L3-L17).
2. [Config/Config.php](file:///c:/xampp/htdocs/Amazon-Market/Config/Config.php#L3-L5) incluye [Libraries/Core/Dotenv.php](file:///c:/xampp/htdocs/Amazon-Market/Libraries/Core/Dotenv.php#L5-L46) y ejecuta `Dotenv::load(__DIR__ . '/../.env')`, poblando `$_ENV` y `putenv()`.
3. [Config/Config.php](file:///c:/xampp/htdocs/Amazon-Market/Config/Config.php#L13-L17) define las constantes:
   - `DB_HOST` (`getenv('DB_HOST') ?: 'localhost'`)
   - `DB_NAME` (`getenv('DB_NAME') ?: 'amazon_market'`)
   - `DB_USER` (`getenv('DB_USER') ?: 'root'`)
   - `DB_PASS` (`getenv('DB_PASS') ?: ''`)
   - `DB_CHARSET` (`getenv('DB_CHARSET') ?: 'utf8'`)
4. [Libraries/Core/Conexion.php](file:///c:/xampp/htdocs/Amazon-Market/Libraries/Core/Conexion.php#L12-L23) es el **único punto de conexión PDO** de todo el proyecto (`new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS)`). No existen conexiones secundarias ni `mysqli` en `Libraries/`, `Models/`, `Services/` ni `Controllers/`.

### 2.2. Parámetros efectivos resueltos (sin exponer secretos)

| Parámetro | Origen | Valor efectivo |
| :--- | :--- | :--- |
| Archivo `.env` | Raíz del proyecto | Presente (`ENV_FILE_EXISTS = YES`) |
| `DB_HOST` | `.env` / `Config.php` | `localhost` |
| Puerto del servidor | `@@port` en MariaDB | `3306` |
| Hostname del servidor | `@@hostname` | `LAPTOP-D18VAELK` |
| `DB_NAME` (`DATABASE()`) | `.env` / `Config.php` | **`amazon_market`** |
| `DB_USER` (`USER()` / `CURRENT_USER()`) | `.env` / `Config.php` | `root@localhost` |
| `DB_PASS` | `.env` / `Config.php` | Vacía en entorno local XAMPP (`<EMPTY>`) |
| `DB_CHARSET` en conexión PDO | `.env` / `Config.php` | `utf8` |

---

## 3. Versiones, Motores y Características Encontradas

### 3.1. Ejecutables y versiones reales

| Herramienta | Ruta del ejecutable | Versión real verificada |
| :--- | :--- | :--- |
| **PHP CLI** | `C:\xampp\php\php.exe` | `PHP 8.2.12` |
| **Cliente MySQL / MariaDB** | `C:\xampp\mysql\bin\mysql.exe` | `Ver 15.1 Distrib 10.4.32-MariaDB, for Win64 (AMD64), source revision c4143f909528e3fab0677a28631d10389354c491` |
| **Volcado (`mysqldump`)** | `C:\xampp\mysql\bin\mysqldump.exe` | `Ver 10.19 Distrib 10.4.32-MariaDB, for Win64 (AMD64)` |
| **Motor de Base de Datos** | `SELECT VERSION(), @@version_comment` | `10.4.32-MariaDB` (`mariadb.org binary distribution`) |

### 3.2. Motores de almacenamiento, codificación, colaciones y zona horaria

| Propiedad | Valor en servidor / base `amazon_market` | Observaciones técnicas |
| :--- | :--- | :--- |
| **Motor de almacenamiento (`ENGINE`)** | **`InnoDB` en las 32 tablas** | **100 % transaccional.** No existe ninguna tabla `MyISAM`, `Aria` ni `MEMORY`. |
| `@@character_set_server` / `@@collation_server` | `utf8mb4` / `utf8mb4_general_ci` | Configuración global del servidor. |
| `@@character_set_database` / `@@collation_database` | `utf8mb4` / `utf8mb4_general_ci` | Configuración por defecto del esquema `amazon_market`. |
| Colaciones de las 32 tablas | 29 tablas en `utf8mb4_unicode_ci`<br>2 tablas (`permiso`, `rol_permiso`) en `utf8_general_ci`<br>1 tabla (`presentacion`) en `utf8mb4_general_ci` | Diferencia menor heredada de migraciones previas; todas compatibles con volcado en `utf8mb4`. |
| `@@sql_mode` | `NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION` | Modo estándar de XAMPP MariaDB 10.4. |
| `@@tx_isolation` | `REPEATABLE-READ` | Nivel de aislamiento por defecto de InnoDB. |
| `@@event_scheduler` | `OFF` | El planificador de eventos está desactivado en el servidor. |
| **Zona horaria de MariaDB** | `@@global.time_zone = SYSTEM`<br>`@@session.time_zone = SYSTEM`<br>`@@system_time_zone = America/Bogota`<br>`TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) = -300` | El motor de BD opera en **`UTC-05:00`**, que coincide exactamente con el huso horario permanente de **`America/Lima` (`UTC-05:00`, sin horario de verano)**. `NOW()` devuelve la hora local peruana (`2026-11-12 01:18:23` frente a `UTC 06:18:23`). |
| **Zona horaria de PHP (`date_default_timezone_get()`)** | **`Europe/Berlin`** | **Hallazgo técnico importante:** El `php.ini` de XAMPP tiene `date.timezone = Europe/Berlin` y ni [Config/Config.php](file:///c:/xampp/htdocs/Amazon-Market/Config/Config.php) ni [index.php](file:///c:/xampp/htdocs/Amazon-Market/index.php) llaman aún a `date_default_timezone_set('America/Lima')`. Debe fijarse en los pasos de implementación del Sprint 3 para que PHP y MariaDB evalúen la ventana de 15 minutos y los cierres en `America/Lima`. |

---

## 4. Inventario de Estructuras y Objetos Existentes en `amazon_market`

### 4.1. Resumen de objetos en el esquema `amazon_market`

- **Tablas base (`BASE TABLE`):** `32` (todas `InnoDB`).
- **Vistas (`VIEW`):** `0`.
- **Triggers (`TRIGGER`):** `5`.
- **Rutinas (`PROCEDURE` / `FUNCTION`):** `0`.
- **Eventos (`EVENT`):** `0`.

### 4.2. Estado de estructuras del Sprint 2 en la base real

La base `amazon_market` **ya tiene aplicada y completada la Fase 10 del Sprint 2** (`10_archivado_y_preparacion_produccion.sql`), ejecutada el `2026-10-25 20:16:20`–`20:16:21`, y posteriormente poblada con operaciones válidas del Sprint 2 el `2026-10-27`:

- Registro en `sprint2_fase10_control`:
  - `id_fase = 10`, `estado = 'COMPLETADA'`, `iniciada_en = '2026-10-25 20:16:20'`, `completada_en = '2026-10-25 20:16:21'`.
  - Respaldo previo registrado: `C:/xampp/backups/amazon_market/amazon_market_pre_sprint2_fase10_apply.sql` (`SHA-256: 26F5A03125B1B1057A48367B6FBD68E9523A03299AB88519B41ADF6468B19BCD`).
- Estructuras activas del Sprint 2 presentes:
  - `subcategoria` (con FK compuesta desde `producto (id_subcategoria, id_categoria)` y `chk_producto_stock_entero_no_negativo`).
  - `detalle_compra` con columnas de versionado (`version_detalle`, `es_vigente`, `id_detalle_origen`, `reemplazado_en`) y `chk_detalle_compra_integridad`.
  - `operacion_inventario` con `chk_op_inv_integridad`.
  - `kardex` ampliado con `origen_operacion`, `sentido_movimiento`, `id_detalle_venta`, `id_operacion_inventario`, `referencia_operacion`, `justificacion`, `numero_lote`, `condicion_lote`, 10 columnas `*_snapshot` y `chk_kardex_integridad`.
  - `control_solicitud_idempotencia` con `tipo_operacion ENUM('BAJA','AJUSTE','COMPRA','CORRECCION_COMPRA','ANULACION_COMPRA','VENTA','ANULACION_VENTA')`.
  - `reporte_consulta_snapshot` (con `tipo_reporte ENUM('kardex','variaciones')`) y `reporte_consulta_snapshot_item`.
- Tablas históricas archivadas del Sprint 2 presentes:
  - `sprint2_archivo_compra`, `sprint2_archivo_compra_historial`, `sprint2_archivo_detalle_compra`, `sprint2_archivo_detalle_venta`, `sprint2_archivo_kardex`, `sprint2_archivo_venta`.

### 4.3. Triggers existentes en `amazon_market`

| Trigger | Evento y Momento | Tabla | `DEFINER` | Referencias a nombre de BD en el cuerpo |
| :--- | :--- | :--- | :--- | :--- |
| `trg_categoria_after_insert_subcategoria_general` | `AFTER INSERT` | `categoria` | `root@localhost` | Ninguna (`NO`) |
| `trg_kardex_before_insert_integridad` | `BEFORE INSERT` | `kardex` | `root@localhost` | Ninguna (`NO`) |
| `trg_kardex_before_update_inmutable` | `BEFORE UPDATE` | `kardex` | `root@localhost` | Ninguna (`NO`) |
| `trg_producto_before_insert_subcategoria` | `BEFORE INSERT` | `producto` | `root@localhost` | Ninguna (`NO`) |
| `trg_producto_before_update_subcategoria` | `BEFORE UPDATE` | `producto` | `root@localhost` | Ninguna (`NO`) |

### 4.4. Verificación de estructuras previas de turnos o caja

Se consultó `information_schema.COLUMNS` buscando tablas o columnas cuyos nombres contengan `turno`, `caja`, `sesion`, `arqueo`, `horario`, `programacion`, `cierre` o `autoriza`:
- **Resultado:** **Ninguna tabla ni columna de turnos, programación, sesiones de caja, movimientos de caja, arqueos o autorizaciones existe todavía en `amazon_market`.**
- La tabla `venta` tiene actualmente 10 columnas (`id_venta`, `id_cliente`, `id_usuario`, `id_tipo_comprobante`, `serie`, `numero`, `fecha_venta`, `total`, `id_metodo_pago`, `estado`) y aún no posee `id_sesion_caja`.

---

## 5. Línea Base de Datos y Existencias (Previo al Respaldo)

### 5.1. Conteos exactos (`SELECT COUNT(*)`), `AUTO_INCREMENT` y `CHECKSUM TABLE` de las 32 tablas

| # | Tabla | Motor | Colación | Filas (`COUNT(*)`) | `AUTO_INCREMENT` | `CHECKSUM TABLE EXTENDED` |
| :-: | :--- | :--- | :--- | --: | --: | --: |
| 1 | `categoria` | InnoDB | `utf8mb4_unicode_ci` | `6` | `7` | `3177129530` |
| 2 | `cliente` | InnoDB | `utf8mb4_unicode_ci` | `14` | `17` | `1808869450` |
| 3 | `compra` | InnoDB | `utf8mb4_unicode_ci` | `80` | `86` | `2863598311` |
| 4 | `compra_historial` | InnoDB | `utf8mb4_unicode_ci` | `0` | `4` | `0` |
| 5 | `control_solicitud_idempotencia` | InnoDB | `utf8mb4_unicode_ci` | `405` | `406` | `222073421` |
| 6 | `detalle_compra` | InnoDB | `utf8mb4_unicode_ci` | `100` | `116` | `1408780884` |
| 7 | `detalle_venta` | InnoDB | `utf8mb4_unicode_ci` | `295` | `1036` | `1179342763` |
| 8 | `kardex` | InnoDB | `utf8mb4_unicode_ci` | `470` | `498` | `2535760088` |
| 9 | `metodo_pago` | InnoDB | `utf8mb4_unicode_ci` | `3` | `4` | `3125332202` |
| 10 | `operacion_inventario` | InnoDB | `utf8mb4_unicode_ci` | `75` | `76` | `107647376` |
| 11 | `permiso` | InnoDB | `utf8_general_ci` | `31` | `32` | `2804841890` |
| 12 | `presentacion` | InnoDB | `utf8mb4_general_ci` | `3` | `4` | `101987507` |
| 13 | `producto` | InnoDB | `utf8mb4_unicode_ci` | `108` | `109` | `2567928720` |
| 14 | `proveedor` | InnoDB | `utf8mb4_unicode_ci` | `3` | `4` | `2360316501` |
| 15 | `reporte_consulta_snapshot` | InnoDB | `utf8mb4_unicode_ci` | `2` | `30` | `730557223` |
| 16 | `reporte_consulta_snapshot_item` | InnoDB | `utf8mb4_unicode_ci` | `150` | `NULL` | `3827838501` |
| 17 | `rol` | InnoDB | `utf8mb4_unicode_ci` | `2` | `4` | `2393843187` |
| 18 | `rol_permiso` | InnoDB | `utf8_general_ci` | `30` | `NULL` | `3627112123` |
| 19 | `serie_comprobante` | InnoDB | `utf8mb4_unicode_ci` | `2` | `4` | `3941749175` |
| 20 | `sprint2_archivo_compra` | InnoDB | `utf8mb4_unicode_ci` | `4` | `6` | `3743211542` |
| 21 | `sprint2_archivo_compra_historial` | InnoDB | `utf8mb4_unicode_ci` | `3` | `4` | `4187396716` |
| 22 | `sprint2_archivo_detalle_compra` | InnoDB | `utf8mb4_unicode_ci` | `4` | `16` | `3428466409` |
| 23 | `sprint2_archivo_detalle_venta` | InnoDB | `utf8mb4_unicode_ci` | `377` | `741` | `1097668984` |
| 24 | `sprint2_archivo_kardex` | InnoDB | `utf8mb4_unicode_ci` | `26` | `28` | `4178226246` |
| 25 | `sprint2_archivo_venta` | InnoDB | `utf8mb4_unicode_ci` | `98` | `124` | `4022598553` |
| 26 | `sprint2_fase10_control` | InnoDB | `utf8mb4_unicode_ci` | `1` | `NULL` | `1545232442` |
| 27 | `subcategoria` | InnoDB | `utf8mb4_unicode_ci` | `6` | `7` | `2869915229` |
| 28 | `tipo_comprobante` | InnoDB | `utf8mb4_unicode_ci` | `2` | `3` | `1294789679` |
| 29 | `tipo_documento` | InnoDB | `utf8mb4_unicode_ci` | `4` | `5` | `247368920` |
| 30 | `unidad_medida` | InnoDB | `utf8mb4_unicode_ci` | `4` | `5` | `3064936218` |
| 31 | `usuario` | InnoDB | `utf8mb4_unicode_ci` | `6` | `10` | `1537687628` |
| 32 | `venta` | InnoDB | `utf8mb4_unicode_ci` | `250` | `374` | `1012757053` |

### 5.2. Totales de negocio y métricas de integridad

#### A. Productos, categorías, subcategorías, unidades y presentaciones
- **Total de productos (`producto`):** `108` (`108` con `estado = 1`, `0` inactivos).
- **Existencias totales (`SUM(stock_actual)`):** **`2497` unidades** (todas enteras $\ge 0$).
- **Suma de `stock_minimo`:** `690`.
- **Rango de `stock_actual`:** mínimo `0`, máximo `74`.
- **Distribución de stock:** `55` productos con `stock_actual > 0` y `53` productos con `stock_actual = 0` (`0` con stock negativo).
- **Suma de `precio_venta`:** `S/ 929.98` | **Valorizado total a precio de venta (`SUM(stock_actual * precio_venta)`):** `S/ 17,591.02`.
- **Conciliación `producto.stock_actual` vs. último `stock_resultante` en `kardex`:** **`0` descuadres** en los 108 productos.
- **Huella SHA-256 canónica de los 108 productos y sus existencias:**
  `f8e40b07f0058942b28bd8de339593b81256bdfaef48c653ba23c8d4180af8cd`
- **Categorías (`6`) y Subcategorías (`6`):** `1: Abarrotes` (`estado=1`), `2: Bebidas` (`estado=1`), `3: Limpieza` (`estado=1`), `4: Lacteos` (`estado=1`), `5: Cuidado Personal` (`estado=1`), `6: Test Updated` (`estado=0`), cada una con su subcategoría `General` (`id_subcategoria` `1..6`).
- **Unidades de medida (`4`):** `1: UND`, `2: KG`, `3: LTR`, `4: PQT`.
- **Presentaciones (`3`):** `1: Unidad (eq=1)`, `2: Caja x 12 (eq=12)`, `3: Caja x 24 (eq=24)`.

##### Detalle de los 55 productos con `stock_actual > 0` (suman `2497` unidades):

| `id_producto` | `codigo_barra` | Producto | `stock_actual` | `stock_minimo` | `precio_venta` |
| --: | :--- | :--- | --: | --: | --: |
| 1 | `7750182003049` | Agua San Luis 500 ml | 20 | 20 | 1.50 |
| 2 | `7750236173896` | Inca Kola 500 ml Menos Calorias | 15 | 15 | 2.80 |
| 3 | `7750182006088` | Inka Kola 600 ml | 12 | 12 | 3.00 |
| 4 | `7750236000246` | Inca Kola 2.25 L | 8 | 8 | 8.50 |
| 5 | `7750182001243` | Inka Kola Retornable 3 L | 5 | 5 | 11.50 |
| 6 | `7751271034081` | Leche Evaporada Gloria 390 g | 8 | 15 | 4.80 |
| 7 | `7751271021975` | Leche Evaporada Gloria 400 g | 5 | 10 | 4.90 |
| 8 | `7778606000061` | Leche Evaporada Nestle 400 g | 5 | 10 | 4.80 |
| 9 | `8445290227027` | Leche Condensada Nestle 393 g | 3 | 6 | 6.80 |
| 10 | `8445290401182` | Ideal Cremosita Nestle 390 g | 3 | 6 | 5.50 |
| 11 | `7751271276733` | Yomost | 5 | 10 | 3.50 |
| 12 | `7751271017367` | Yogurt Griego Gloria | 3 | 6 | 4.50 |
| 13 | `7751271037266` | Pro Power Dark Chocolate Gloria | 3 | 5 | 4.20 |
| 14 | `7751271037280` | Pro Day Mora Gloria | 3 | 5 | 4.20 |
| 15 | `7751271037259` | Pro Day Vainilla Gloria 320 ml | 3 | 5 | 4.20 |
| 16 | `7751271032117` | Gloria 320 ml | 68 | 5 | 3.80 |
| 17 | `7751271011457` | Gloria Tableta para Taza Chocolate 90 g | 54 | 8 | 2.80 |
| 18 | `7751271025287` | Batidito Gloria 80 g | 73 | 8 | 1.80 |
| 19 | `7613036450133` | Cubo Carne Maggi 75.2 g | 60 | 12 | 2.50 |
| 20 | `7622201717544` | Club Social Original 24 g | 67 | 15 | 1.00 |
| 21 | `7622210854209` | Club Social Multicereales 216 g | 47 | 6 | 6.50 |
| 22 | `7622201717759` | Club Social x 6 Un 144 g | 42 | 6 | 5.20 |
| 23 | `7590011205158` | Club Social Original 26 g | 46 | 15 | 1.10 |
| 24 | `7750885001458` | Frac Clasica Costa 132 g | 51 | 12 | 2.20 |
| 25 | `7750885346665` | Chocman Costa 180 g | 52 | 6 | 4.80 |
| 26 | `7750885021203` | Frac Sabor Menta Costa 41 g | 52 | 10 | 1.80 |
| 27 | `7750885020879` | Choco Donuts Crunch Costa | 45 | 6 | 2.80 |
| 28 | `7750885021333` | Doblon Costa 12.5 g | 52 | 20 | 0.80 |
| 29 | `7750885015356` | Agua Line Costa 210 g | 53 | 6 | 3.20 |
| 30 | `7750885016421` | Cereal Bar Costa | 38 | 8 | 1.80 |
| 31 | `7750885019897` | Wafer Chocolate Tira Nik Costa 72 g | 61 | 8 | 2.20 |
| 32 | `7750885008068` | Costa 35 g | 50 | 8 | 1.50 |
| 33 | `7750885024303` | Galleta Costa Wenazas 50.4 g | 54 | 8 | 2.00 |
| 34 | `7750243048873` | Casino Black Victoria 43.5 g | 57 | 8 | 1.80 |
| 35 | `7750670012218` | Bebida Energizante VOLT Ginseng Botella 300ml | 54 | 5 | 2.50 |
| 36 | `7750243059381` | Aceite Vegetal PRIMOR Clásico Botella 1.8L | 59 | 5 | 15.50 |
| 37 | `7751158010443` | Trozos de Atún FLORIDA en Aceite Vegetal Lata 140g | 58 | 5 | 5.80 |
| 38 | `2000000000001` | Fideos Cabello de Ángel MOLITALIA Bolsa 250g | 54 | 5 | 1.70 |
| 39 | `7750885176125` | Fideos Canuto MOLITALIA Bolsa 250g | 57 | 5 | 1.70 |
| 40 | `2000000000002` | Aceite de Oliva EL OLIVAR Extra Virgen Botella 500ml | 53 | 5 | 33.90 |
| 46 | `2200201985511` | Azúcar Blanca BELL'S Bolsa 1Kg | 71 | 5 | 4.60 |
| 47 | `2200201985528` | Azúcar Rubia BELL'S Bolsa 1Kg | 69 | 5 | 3.80 |
| 48 | `7750727005965` | Galletas de Maíz SALMAS Horneadas Caja 216g | 69 | 5 | 11.50 |
| 49 | `7754725781616` | Arroz Extra Añejo VALLENORTE Gran Reserva Bolsa 5Kg | 67 | 5 | 22.90 |
| 50 | `2000000000005` | Arroz Extra BELL'S Bolsa 750g | 74 | 5 | 3.50 |
| 51 | `7755139161759` | Arroz Extra COSTEÑO Bolsa 750g | 73 | 5 | 4.50 |
| 52 | `2200201985481` | Azúcar Rubia BELL'S Bolsa 2Kg | 71 | 5 | 6.90 |
| 53 | `7755139246890` | Arroz Extra COSTEÑO Bolsa 5Kg | 64 | 5 | 23.90 |
| 54 | `2000000000006` | Azúcar Rubia CARTAVIO Bolsa 5Kg | 68 | 5 | 14.90 |
| 55 | `7794640170720` | Pasta Dental SENSODYNE Blanqueador Repara y Protege Tubo 100g | 68 | 5 | 24.90 |
| 56 | `2000000000007` | Gaseosa COCA COLA sin Azúcar Botella 500ml | 68 | 5 | 4.99 |
| 57 | `7750182155663` | Gaseosa COCA COLA Sabor Original Botella 1.5L | 73 | 5 | 7.30 |
| 58 | `2000000000008` | Gaseosa INCA KOLA Sabor Original Botella 3L | 65 | 5 | 12.20 |
| 59 | `7750182003919` | Gaseosa COCA COLA sin Azúcar Botella 2.25L | 65 | 5 | 8.50 |
| 60 | `2000000000009` | Gaseosa INCA KOLA Botella 2.25L | 74 | 5 | 9.90 |

*(Los restantes 53 productos —`id_producto` `41..45` y `61..108`— tienen `stock_actual = 0`).*

#### B. Clientes y proveedores
- **Clientes (`cliente`):** `14` filas (`12` activos, `2` inactivos; `id_cliente` de `1` a `16`).
- **Proveedores (`proveedor`):** `3` filas (`2` activos, `1` inactivo; `id_proveedor` de `1` a `3`).
- **Tipos de documento (`tipo_documento`):** `4` (`1: DNI`, `2: RUC`, `3: Pasaporte`, `4: Carne de Extranjeria`).

#### C. Ventas y detalles de venta (`venta`, `detalle_venta`)
- **Ventas activas en `venta`:** `250` filas (`250` con `estado = 1`, `0` anuladas; `id_venta` de `124` a `373`; rango `2026-10-27 11:33:52` a `2026-10-27 11:33:53`).
- **Importe total en `venta` (`SUM(total)`):** **`S/ 4,094.43`**.
- **Desglose por método de pago (`id_metodo_pago`):**
  - `1 (Efectivo)`: `84` ventas por `S/ 1,473.31`.
  - `2 (Tarjeta)`: `83` ventas por `S/ 1,086.92`.
  - `3 (Yape / Plin)`: `83` ventas por `S/ 1,534.20`.
- **Detalles de venta (`detalle_venta`):** `295` filas (`id_detalle_venta` de `741` a `1035`), `SUM(cantidad) = 595.00`, `SUM(precio_unitario) = 2041.78`, `SUM(cantidad * precio_unitario) = 4094.4300`.

#### D. Compras, detalles e historial (`compra`, `detalle_compra`, `compra_historial`)
- **Compras en `compra`:** `80` filas (todas en `estado = 'Confirmada'`; `id_compra` de `6` a `85`; fechas de emisión `2026-08-09` a `2026-10-27`).
- **Importe total en `compra` (`SUM(total_documento)`):** **`S/ 22,488.15`**.
- **Detalles de compra (`detalle_compra`):** `100` filas (`100` vigentes con `es_vigente = 1`, `0` no vigentes; `id_detalle_compra` de `16` a `115`), `SUM(cantidad_presentaciones) = 4278`, `SUM(cantidad_base) = 4278`, `SUM(importe_final_linea) = 22,488.15`.
- **Historial de compras (`compra_historial`):** `0` filas en la tabla activa (`AUTO_INCREMENT = 4`).

#### E. Métodos de pago, tipos y series de comprobantes
- **Métodos de pago (`metodo_pago`):** `1: Efectivo` (`estado=1`), `2: Tarjeta` (`estado=1`), `3: Yape / Plin` (`estado=1`).
- **Tipos de comprobante (`tipo_comprobante`):** `1: Boleta` (`estado=1`), `2: Factura` (`estado=1`).
- **Series y correlativos (`serie_comprobante`):**
  - `id_serie = 1`: `id_tipo_comprobante = 1` (Boleta), `serie = 'B001'`, **`correlativo_actual = 318`**, `estado = 1`.
  - `id_serie = 2`: `id_tipo_comprobante = 2` (Factura), `serie = 'F001'`, **`correlativo_actual = 30`**, `estado = 1`.

#### F. Kardex actual, operaciones de inventario y tablas archivadas del Sprint 2
- **Kardex activo (`kardex`):** `470` filas (`id_kardex` de `28` a `497`; `SUM(cantidad) = 6289`):
  - `COMPRA` (`INCREMENTO`): `100` filas, suma `4278`.
  - `VENTA` (`DISMINUCION`): `295` filas, suma `595`.
  - `BAJA_DANO` (`DISMINUCION`): `15` filas, suma `34`.
  - `BAJA_VENCIMIENTO` (`DISMINUCION`): `15` filas, suma `26`.
  - `AJUSTE_POSITIVO` (`INCREMENTO`): `20` filas, suma `115`.
  - `AJUSTE_NEGATIVO` (`DISMINUCION`): `25` filas, suma `1241`.
  - Balance exacto: $(4278 + 115) - (595 + 34 + 26 + 1241) = 4393 - 1896 = 2497 = \sum \text{stock\_actual}$.
- **Operaciones de inventario (`operacion_inventario`):** `75` filas (`id_operacion` de `1` a `75`): `15` `BAJA_DANO` (`34` u.), `15` `BAJA_VENCIMIENTO` (`26` u.), `20` `AJUSTE_POSITIVO` (`115` u.), `25` `AJUSTE_NEGATIVO` (`1241` u.).
- **Tablas archivadas (`sprint2_archivo_*`):**
  - `sprint2_archivo_venta`: `98` filas (`SUM(total) = 5885.54`, `id_venta` `8..123`).
  - `sprint2_archivo_detalle_venta`: `377` filas (`SUM(cantidad) = 1709.89`, `id_detalle_venta` `9..740`).
  - `sprint2_archivo_compra`: `4` filas (`SUM(total_documento) = 2000.00`, `id_compra` `2..5`).
  - `sprint2_archivo_detalle_compra`: `4` filas (`SUM(cantidad_base) = 15`, `SUM(importe_final_linea) = 2000.00`).
  - `sprint2_archivo_compra_historial`: `3` filas (`id_historial` `1..3`).
  - `sprint2_archivo_kardex`: `26` filas (`SUM(cantidad) = 285`, `id_kardex` `1..27`).

#### G. Control de solicitudes idempotentes y snapshots de reportes
- **`control_solicitud_idempotencia`:** `405` filas, todas en `estado_solicitud = 'CONFIRMADA'` (`30` `BAJA`, `45` `AJUSTE`, `80` `COMPRA`, `250` `VENTA`).
- **`reporte_consulta_snapshot` y `reporte_consulta_snapshot_item`:** `2` cabeceras (`tipo_reporte = 'variaciones'`) y `150` ítems.

---

## 6. Estado Real de Roles, Permisos y Correos Administrativos

### 6.1. Roles en la base real (`rol`)
| `id_rol` | `nombre` | `estado` |
| --: | :--- | --: |
| `1` | `Administrador` | `1` |
| `2` | `Vendedor` | `1` |

### 6.2. Permisos en la base real (`permiso`) y diferencias frente a `amazon_market.sql`
Mientras que el archivo [amazon_market.sql](file:///c:/xampp/htdocs/Amazon-Market/amazon_market.sql#L517-L538) del repositorio solo contiene 19 permisos (`id_permiso` `1..19`), la base de datos real `amazon_market` contiene **31 permisos activos** (`id_permiso` `1..31`):

- `1..16`: Permisos base de Dashboard, Productos, Clientes, Usuarios, Ventas y `reportes.ver`.
- `17`: `turnos.acceder` (`Acceder Turnos`, grupo `general`, `estado = 1`).
- `18`: `turnos.abrir` (`Abrir Turno`, grupo `general`, `estado = 1`).
- `19`: `turnos.cerrar` (`Cerrar Turno`, grupo `general`, `estado = 1`).
- `20..23`: `proveedores.listar`, `proveedores.crear`, `proveedores.editar`, `proveedores.eliminar`.
- `24..27`: `compras.listar`, `compras.crear`, `compras.editar`, `compras.eliminar`.
- `28`: `inventario.ajustes` (`Ajustes y Bajas de Inventario`).
- `29`: `reportes.kardex` (`Consultar Kardex de Inventario`).
- `30`: `reportes.variaciones` (`Consultar Reporte de Variaciones`).
- `31`: `reportes.exportar` (`Exportar Reportes PDF y Excel`).

### 6.3. Asignaciones en `rol_permiso` (30 filas en la base real)
- **Administrador (`id_rol = 1`):** Tiene 20 filas en `rol_permiso`: `id_permiso` `1..16` y `28..31` (nota: los permisos `17..19` de turnos y `20..27` de proveedores/compras no tienen fila en `rol_permiso` para `id_rol = 1`, aunque `RBACMiddleware::tienePermiso(1, $slug)` devuelve `true` por regla general).
- **Vendedor (`id_rol = 2`):** Tiene 10 filas en `rol_permiso`: `id_permiso` `1, 2, 6, 7, 8, 14, 15, 17 (turnos.acceder), 18 (turnos.abrir), 19 (turnos.cerrar)`. A diferencia de `amazon_market.sql`, en la base real el Vendedor **ya no tiene** `id_permiso = 16` (`reportes.ver`), pues fue retirado por `02_configuracion_permisos_sprint2.sql`.

### 6.4. Usuarios y correos electrónicos administrativos (sin exponer datos sensibles ni hashes)

| `id_usuario` | `username` | `id_rol` | Rol | `estado` | Estado de `email` | Correo (enmascarado) | Longitud `password_hash` |
| --: | :--- | --: | :--- | --: | :--- | :--- | --: |
| `1` | `admin` | `1` | Administrador | `1` (Activo) | **`NULL`** | *(Sin correo)* | 60 (bcrypt) |
| `4` | `alex` | `2` | Vendedor | `1` (Activo) | `DEFINIDO` | `al***@mail.com` | 60 (bcrypt) |
| `6` | `maria` | `2` | Vendedor | `1` (Activo) | `DEFINIDO` | `ma***@mail.com` | 60 (bcrypt) |
| `7` | `carlos` | `2` | Vendedor | `1` (Activo) | `DEFINIDO` | `ca***@mail.com` | 60 (bcrypt) |
| `8` | `ana` | `2` | Vendedor | `1` (Activo) | `DEFINIDO` | `an***@mail.com` | 60 (bcrypt) |
| `9` | `Superuser` | `1` | Administrador | `1` (Activo) | `DEFINIDO` | `su***@mail.com` | 60 (bcrypt) |

- **Total de administradores activos (`id_rol = 1 AND estado = 1`):** `2` (`id_usuario = 1` e `id_usuario = 9`).
- **Administradores activos sin correo (`email IS NULL` o vacío):** **`1`** (`id_usuario = 1`, `username = 'admin'`).
- **Correos vacíos (`''`) en `usuario`:** `0`.
- **Correos duplicados en `usuario` (`GROUP BY LOWER(TRIM(email)) HAVING COUNT(*) > 1`):** `0`.
- **Índice de unicidad en `usuario.email`:** Actualmente `usuario.email` (`varchar(50) NULL`) **no tiene índice `UNIQUE`** (solo `username` tiene índice `UNIQUE`).
- **Impacto para los pasos siguientes:** Conforme a las instrucciones de este Paso 3, **no se modificó ningún usuario ni correo**. En la sección 13 se documenta el tratamiento requerido antes de habilitar la autorización por correo en el Paso 5 / Paso 11.

---

## 7. Procedimiento Utilizado para Garantizar Consistencia

1. **Verificación de motores transaccionales:** Se comprobó en `information_schema.TABLES` que las **32 tablas** de `amazon_market` utilizan exclusivamente el motor transaccional **`InnoDB`**. No existen tablas `MyISAM` ni `Aria`.
2. **Verificación de actividad concurrente (`SHOW FULL PROCESSLIST`):** Se confirmó que no existía ninguna otra sesión de usuario ni petición HTTP ejecutando escrituras sobre `amazon_market`.
3. **Captura transaccional (`--single-transaction`):** Al ser todas las tablas `InnoDB`, `mysqldump --single-transaction` inicia una transacción con aislamiento `REPEATABLE READ` que obtiene una instantánea consistente de todas las tablas en un único punto en el tiempo.
4. **Opciones de preservación completa:**
   - `--routines --triggers --events`: incluye los 5 triggers del esquema (y rutinas/eventos si existieran).
   - `--hex-blob`: codifica en hexadecimal cualquier dato binario para evitar corrupciones de flujo.
   - `--default-character-set=utf8mb4`: preserva íntegramente tildes, `ñ` y caracteres Unicode.
   - `--result-file=...`: escribe el archivo directamente desde el binario `mysqldump.exe` sin pasar por tuberías de PowerShell que pudieran recodificar saltos de línea o caracteres.
   - Sin `--databases` ni `--all-databases`: evita que el archivo resultante incluya sentencias `CREATE DATABASE` o `USE amazon_market;` que pudieran redirigir una restauración hacia la base original.
5. **Doble verificación de inmutabilidad (antes y después del volcado):** Los conteos `COUNT(*)`, valores `AUTO_INCREMENT` y `CHECKSUM TABLE EXTENDED` de las 32 tablas de `amazon_market` se midieron antes y después de ejecutar `mysqldump`, confirmando que son 100 % idénticos.

---

## 8. Ruta, Tamaño y SHA-256 del Respaldo

| Propiedad | Valor verificado |
| :--- | :--- |
| **Carpeta externa (fuera de `htdocs` y del repositorio Git)** | `C:\xampp\backups\Amazon-Market\Sprint3` |
| **Ruta completa del archivo de respaldo** | `C:\xampp\backups\Amazon-Market\Sprint3\amazon_market_sprint3_pre_step3_20261112_012312.sql` |
| **Fecha y hora de creación del archivo** | `2026-11-12 01:23:12` |
| **Código de salida de `mysqldump.exe`** | `0` (sin errores en `stderr`) |
| **Tamaño exacto en bytes** | `467,692` bytes (`1,306` líneas; termina con `-- Dump completed on 2026-11-12  1:23:12`) |
| **Hash `SHA-256`** | `7F646633481EFCA28A7F83903C1B7C4A40B665F71F3D9666D6D725D6B38DB39D` |
| **Instrucciones `USE` o `CREATE DATABASE` dentro del volcado** | `0` (verificado por inspección de expresiones regulares) |
| **Sentencias `CREATE TABLE` en el volcado** | `32` |
| **Sentencias `CREATE TRIGGER` en el volcado** | `5` |

### Comando ejecutado (sin credenciales expuestas)
```powershell
& 'C:\xampp\mysql\bin\mysqldump.exe' -u root --single-transaction --routines --triggers --events --hex-blob --default-character-set=utf8mb4 "--result-file=C:\xampp\backups\Amazon-Market\Sprint3\amazon_market_sprint3_pre_step3_20261112_012312.sql" amazon_market
```

---

## 9. Nombre de la Base de Restauración

- **Base de verificación restaurada:** **`amazon_market_sprint3_verify_20261112_012430`**
  - *Nota de trazabilidad:* En un primer intento de invocación mediante `Start-Process`, PowerShell creó el esquema vacío `amazon_market_sprint3_verify_20261112_012312` pero `Start-Process` desagrupó las comillas del argumento `-e "SOURCE ..."`, deteniéndose con `ERROR 1102` antes de ejecutar ninguna sentencia. Cumpliendo estrictamente la regla 3 (*"Si ya existe, elige otro nombre; no la borres para reutilizarlo"*), no se eliminó ni reutilizó ese esquema vacío y se creó la base nueva `amazon_market_sprint3_verify_20261112_012430` invocando directamente `& 'C:\xampp\mysql\bin\mysql.exe'`.
- **Comprobaciones previas a la restauración sobre `amazon_market_sprint3_verify_20261112_012430`:**
  1. Nombre distinto de `amazon_market`: Verificado (`amazon_market_sprint3_verify_20261112_012430 <> amazon_market`).
  2. Inexistencia previa del esquema: Verificado con `SHOW DATABASES LIKE 'amazon_market_sprint3_verify_20261112_012430'` (devolvió vacío).
  3. Ausencia de `USE` / `CREATE DATABASE` en el volcado: Verificado (`0` coincidencias).
  4. Ausencia de referencias a `amazon_market` en los 5 triggers: Verificado (`0` coincidencias).
  5. Aislamiento de eventos: `0` eventos en el volcado y `@@event_scheduler = OFF`.

### Comando de restauración ejecutado
```powershell
& 'C:\xampp\mysql\bin\mysql.exe' -u root --default-character-set=utf8mb4 --abort-source-on-error "--database=amazon_market_sprint3_verify_20261112_012430" -e "SOURCE C:/xampp/backups/Amazon-Market/Sprint3/amazon_market_sprint3_pre_step3_20261112_012312.sql"
```
- **Código de salida:** `0` (sin errores ni advertencias).

---

## 10. Resultado de la Restauración y Comparaciones

### 10.1. Comparación tabla por tabla: `amazon_market` (origen) vs. `amazon_market_sprint3_verify_20261112_012430` (copia)

| Tabla | Filas Origen | Filas Copia | `AUTO_INCREMENT` Origen | `AUTO_INCREMENT` Copia | `CHECKSUM` Origen | `CHECKSUM` Copia | Resultado |
| :--- | --: | --: | --: | --: | --: | --: | :-: |
| `categoria` | 6 | 6 | 7 | 7 | `3177129530` | `3177129530` | **OK** |
| `cliente` | 14 | 14 | 17 | 17 | `1808869450` | `1808869450` | **OK** |
| `compra` | 80 | 80 | 86 | 86 | `2863598311` | `2863598311` | **OK** |
| `compra_historial` | 0 | 0 | 4 | 4 | `0` | `0` | **OK** |
| `control_solicitud_idempotencia` | 405 | 405 | 406 | 406 | `222073421` | `222073421` | **OK** |
| `detalle_compra` | 100 | 100 | 116 | 116 | `1408780884` | `1408780884` | **OK** |
| `detalle_venta` | 295 | 295 | 1036 | 1036 | `1179342763` | `1179342763` | **OK** |
| `kardex` | 470 | 470 | 498 | 498 | `2535760088` | `2535760088` | **OK** |
| `metodo_pago` | 3 | 3 | 4 | 4 | `3125332202` | `3125332202` | **OK** |
| `operacion_inventario` | 75 | 75 | 76 | 76 | `107647376` | `107647376` | **OK** |
| `permiso` | 31 | 31 | 32 | 32 | `2804841890` | `2804841890` | **OK** |
| `presentacion` | 3 | 3 | 4 | 4 | `101987507` | `101987507` | **OK** |
| `producto` | 108 | 108 | 109 | 109 | `2567928720` | `2567928720` | **OK** |
| `proveedor` | 3 | 3 | 4 | 4 | `2360316501` | `2360316501` | **OK** |
| `reporte_consulta_snapshot` | 2 | 2 | 30 | 30 | `730557223` | `730557223` | **OK** |
| `reporte_consulta_snapshot_item` | 150 | 150 | `NULL` | `NULL` | `3827838501` | `3827838501` | **OK** |
| `rol` | 2 | 2 | 4 | 4 | `2393843187` | `2393843187` | **OK** |
| `rol_permiso` | 30 | 30 | `NULL` | `NULL` | `3627112123` | `3627112123` | **OK** |
| `serie_comprobante` | 2 | 2 | 4 | 4 | `3941749175` | `3941749175` | **OK** |
| `sprint2_archivo_compra` | 4 | 4 | 6 | 6 | `3743211542` | `3743211542` | **OK** |
| `sprint2_archivo_compra_historial` | 3 | 3 | 4 | 4 | `4187396716` | `4187396716` | **OK** |
| `sprint2_archivo_detalle_compra` | 4 | 4 | 16 | 16 | `3428466409` | `3428466409` | **OK** |
| `sprint2_archivo_detalle_venta` | 377 | 377 | 741 | 741 | `1097668984` | `1097668984` | **OK** |
| `sprint2_archivo_kardex` | 26 | 26 | 28 | 28 | `4178226246` | `4178226246` | **OK** |
| `sprint2_archivo_venta` | 98 | 98 | 124 | 124 | `4022598553` | `4022598553` | **OK** |
| `sprint2_fase10_control` | 1 | 1 | `NULL` | `NULL` | `1545232442` | `1545232442` | **OK** |
| `subcategoria` | 6 | 6 | 7 | 7 | `2869915229` | `2869915229` | **OK** |
| `tipo_comprobante` | 2 | 2 | 3 | 3 | `1294789679` | `1294789679` | **OK** |
| `tipo_documento` | 4 | 4 | 5 | 5 | `247368920` | `247368920` | **OK** |
| `unidad_medida` | 4 | 4 | 5 | 5 | `3064936218` | `3064936218` | **OK** |
| `usuario` | 6 | 6 | 10 | 10 | `1537687628` | `1537687628` | **OK** |
| `venta` | 250 | 250 | 374 | 374 | `1012757053` | `1012757053` | **OK** |

### 10.2. Comparación de huellas SHA-256 de contenido determinista y objetos

| Conjunto verificado | SHA-256 en `amazon_market` (Origen) | SHA-256 en `amazon_market_sprint3_verify_20261112_012430` (Copia) | Coincidencia |
| :--- | :--- | :--- | :-: |
| `producto` y existencias (`108` filas, `2497` u.) | `f8e40b07f0058942b28bd8de339593b81256bdfaef48c653ba23c8d4180af8cd` | `f8e40b07f0058942b28bd8de339593b81256bdfaef48c653ba23c8d4180af8cd` | **IDÉNTICO** |
| `venta` (`250` filas, `S/ 4,094.43`) | `d669d259b574416bc32a99bfba1b15dba788833a1caa21c34eb7af112b6c7e32` | `d669d259b574416bc32a99bfba1b15dba788833a1caa21c34eb7af112b6c7e32` | **IDÉNTICO** |
| `detalle_venta` (`295` filas, `595.00` u.) | `bdc2d7bcefcfab9b75952f8e79dfa7fb25e3d6f300f2719a2dc35e4258cffd4b` | `bdc2d7bcefcfab9b75952f8e79dfa7fb25e3d6f300f2719a2dc35e4258cffd4b` | **IDÉNTICO** |
| `compra` (`80` filas, `S/ 22,488.15`) | `d50daefdbeeefb3959dfe6db9eb239af20c1062a63565b93a6936ba9a916e03b` | `d50daefdbeeefb3959dfe6db9eb239af20c1062a63565b93a6936ba9a916e03b` | **IDÉNTICO** |
| `detalle_compra` (`100` filas, `4278` u.) | `2bdc3690e904e295942abe31ebf752ef22d65a010352b559091ee7162668c700` | `2bdc3690e904e295942abe31ebf752ef22d65a010352b559091ee7162668c700` | **IDÉNTICO** |
| `kardex` (`470` filas, `6289` u.) | `2d7f9eb023654072407796139aa89ccc8594c4787eabdb009e08dc6c876ea26e` | `2d7f9eb023654072407796139aa89ccc8594c4787eabdb009e08dc6c876ea26e` | **IDÉNTICO** |
| `operacion_inventario` (`75` filas) | `e8925b3bd39874a66857996d02a4ed5dcc666c2c90030aa07770fde956aa26f3` | `e8925b3bd39874a66857996d02a4ed5dcc666c2c90030aa07770fde956aa26f3` | **IDÉNTICO** |
| `usuario` (`6` filas) | `34b84a0a75928a990be258615f10b8b750da150de61f0e5b386497090d1258d0` | `34b84a0a75928a990be258615f10b8b750da150de61f0e5b386497090d1258d0` | **IDÉNTICO** |
| `rol` (`2` filas) | `2212e8bf761511f6a179e5bebeb8b7b2f79d60ac36c42bac504dc530578fa1dc` | `2212e8bf761511f6a179e5bebeb8b7b2f79d60ac36c42bac504dc530578fa1dc` | **IDÉNTICO** |
| `permiso` (`31` filas) | `64409a6ebfc6ec5ec1c7366616d78147b5b0bae4b7bdc1e9cde936488fd683d5` | `64409a6ebfc6ec5ec1c7366616d78147b5b0bae4b7bdc1e9cde936488fd683d5` | **IDÉNTICO** |
| `rol_permiso` (`30` filas) | `e0727f9842743c586c412b25dffc8c73ba21d2a8429c28a29fde31a53cb0518e` | `e0727f9842743c586c412b25dffc8c73ba21d2a8429c28a29fde31a53cb0518e` | **IDÉNTICO** |
| `serie_comprobante` (`B001=318`, `F001=30`) | `34a7e9e8efa82d8b30532caef2c9f6125569f432ef25c1cd5dfea7dc3a3dd42d` | `34a7e9e8efa82d8b30532caef2c9f6125569f432ef25c1cd5dfea7dc3a3dd42d` | **IDÉNTICO** |
| `control_solicitud_idempotencia` (`405` filas) | `4d9924439f92c4376a050a08ecbf6cf5abeda36b299a118db998fef49aa8eb1a` | `4d9924439f92c4376a050a08ecbf6cf5abeda36b299a118db998fef49aa8eb1a` | **IDÉNTICO** |
| `sprint2_archivo_venta` (`98` filas) | `0b9ff2380bb0e6a7a14352097afb46046c32086cd07b6ad6048f6765fc8aac21` | `0b9ff2380bb0e6a7a14352097afb46046c32086cd07b6ad6048f6765fc8aac21` | **IDÉNTICO** |
| `sprint2_archivo_detalle_venta` (`377` filas) | `c81568c78ff933331107425a112d2bc23e937330b7a2bbbf2a6ef0fc8cc5d12a` | `c81568c78ff933331107425a112d2bc23e937330b7a2bbbf2a6ef0fc8cc5d12a` | **IDÉNTICO** |
| `sprint2_archivo_compra` (`4` filas) | `06707204a17c8199343cce3488c1c0b5a52028720faf57bc8afc4a5d56ca8064` | `06707204a17c8199343cce3488c1c0b5a52028720faf57bc8afc4a5d56ca8064` | **IDÉNTICO** |
| `sprint2_archivo_kardex` (`26` filas) | `cf13d9d9901173e64bc138073d903fba1b24cf024b330d4aff9e6b649fe38391` | `cf13d9d9901173e64bc138073d903fba1b24cf024b330d4aff9e6b649fe38391` | **IDÉNTICO** |
| 5 Triggers (`information_schema.TRIGGERS`) | 5 triggers activos | 5 triggers idénticos | **IDÉNTICO** |

---

## 11. Errores, Incompatibilidades o Diferencias Encontradas

1. **Desfase del volcado antiguo `amazon_market.sql` frente a la base real `amazon_market`:**
   - `amazon_market.sql` en la raíz del repositorio corresponde al estado del Sprint 1 (20 tablas, 19 permisos, `stock_actual` decimal).
   - La base real `amazon_market` tiene 32 tablas, 31 permisos, 5 triggers, `stock_actual` entero (`INT(11)`) con `2,497` unidades en 108 productos, 250 ventas activas, 80 compras activas, 470 movimientos de Kardex, 75 operaciones de inventario, 405 registros de idempotencia y las 6 tablas de archivo `sprint2_archivo_*`.
2. **Administrador activo sin correo electrónico (`id_usuario = 1`, `username = 'admin'`):**
   - De los 2 administradores activos en la base real (`id_usuario = 1` y `id_usuario = 9`), el usuario `id_usuario = 1` (`admin`) tiene **`email = NULL`**, mientras que `id_usuario = 9` (`Superuser`) sí tiene correo definido (`su***@mail.com`).
   - Dado que [ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md](file:///c:/xampp/htdocs/Amazon-Market/ACUERDOS_Y_PLAN_BACKEND_SPRINT_3.md#L148) exige validar **correo, contraseña y motivo del administrador** para autorizar un cierre anticipado, `id_usuario = 1` no podría autorizar cierres anticipados por correo mientras su `email` sea `NULL`.
3. **Zona horaria de PHP (`Europe/Berlin`) vs. MariaDB (`America/Bogota` / `UTC-05:00`):**
   - Mientras que MariaDB ya opera en `UTC-05:00` (mismo huso que `America/Lima`), PHP tiene configurado `Europe/Berlin` por defecto en XAMPP (`+6` horas de diferencia). Es indispensable configurar `date_default_timezone_set('America/Lima')` en la inicialización de la aplicación durante los pasos de implementación.
4. **Enumeración cerrada en `control_solicitud_idempotencia.tipo_operacion` y `reporte_consulta_snapshot.tipo_reporte`:**
   - `control_solicitud_idempotencia.tipo_operacion` es un `ENUM('BAJA','AJUSTE','COMPRA','CORRECCION_COMPRA','ANULACION_COMPRA','VENTA','ANULACION_VENTA')`. Para reutilizar `ControlSolicitudService` en las operaciones del Sprint 3 (apertura, ingresos, egresos, retiro omitido, corrección, arqueo, cierre) será necesario ampliar ese `ENUM` en las migraciones del Paso 5.
   - Del mismo modo, si `reporte_consulta_snapshot` se reutiliza para el reporte de cierres de caja, su columna `tipo_reporte ENUM('kardex','variaciones')` deberá ampliarse o gestionarse en tabla dedicada.

---

## 12. Conclusión Verificable sobre la Conservación

- La base de datos real de la aplicación (`amazon_market` en `localhost:3306`, MariaDB `10.4.32`) fue inspeccionada exclusivamente mediante lecturas sin alterar ni una sola fila, columna, permiso, usuario ni configuración.
- Se generó un respaldo completo y consistente en `C:\xampp\backups\Amazon-Market\Sprint3\amazon_market_sprint3_pre_step3_20261112_012312.sql` (`467,692` bytes, `SHA-256: 7F646633481EFCA28A7F83903C1B7C4A40B665F71F3D9666D6D725D6B38DB39D`).
- El respaldo se restauró sin errores (`exit code = 0`) en la base de verificación independiente `amazon_market_sprint3_verify_20261112_012430`.
- Las 32 tablas, los 5 triggers, todos los conteos, los valores de `AUTO_INCREMENT`, los 32 `CHECKSUM TABLE EXTENDED` y las 17 huellas SHA-256 de contenido entre `amazon_market` y `amazon_market_sprint3_verify_20261112_012430` resultaron **100 % idénticos (0 discrepancias)**.
- Tanto el archivo de respaldo externo como la base restaurada `amazon_market_sprint3_verify_20261112_012430` se conservan intactos, y la aplicación sigue apuntando a `amazon_market`.

---

## 13. Condiciones Relevantes para los Pasos 4 y 5

1. **Conservación de las 250 ventas activas (`id_venta` `124..373`) sin sesión de caja inventada:**
   - Al añadir la columna `id_sesion_caja INT(11) NULL` a la tabla `venta` en el Paso 5 (`database/tercerSprint`), las 250 ventas existentes conservarán `id_sesion_caja = NULL` conforme al acuerdo de la Sección 1 (*"Conservar el historial comercial anterior sin atribuirle aperturas o sesiones de caja que no quedaron registradas originalmente"*).
2. **Primera apertura de caja en el sistema:**
   - Como no existe ninguna sesión de caja previa en `amazon_market`, la primera apertura que se realice cuando entre en vigor el Sprint 3 activará el escenario real de **Primera apertura sin cierre anterior** (`has_previous_close = false`, `reference_amount = null`, sin diferencia de apertura).
3. **Ampliación incremental de permisos a partir de `id_permiso = 31`:**
   - Los nuevos permisos del Sprint 3 (`turnos.movimientos`, `turnos.arqueo`, `turnos.programar`, `turnos.consultar_historico`, `reportes.cierres_caja`) se insertarán en el Paso 5 verificando `WHERE NOT EXISTS (SELECT 1 FROM permiso WHERE slug = ...)`, coexistiendo con los 31 permisos actuales (`1..31`, donde `reportes.exportar` es `id_permiso = 31`).
4. **Ampliación del `ENUM` de `control_solicitud_idempotencia.tipo_operacion`:**
   - En el Paso 5 se deberá ampliar el `ENUM` de `tipo_operacion` en `control_solicitud_idempotencia` conservando todos los valores actuales (`'BAJA','AJUSTE','COMPRA','CORRECCION_COMPRA','ANULACION_COMPRA','VENTA','ANULACION_VENTA'`) y las 405 filas confirmadas existentes.
5. **Consulta pendiente para el usuario respecto al administrador `id_usuario = 1` (`admin`) sin `email`:**
   - Actualmente `id_usuario = 9` (`Superuser`) tiene correo definido, pero `id_usuario = 1` (`admin`) tiene `email = NULL`. Antes de ejecutar las migraciones del Paso 5, conviene confirmar con el propietario si desea asignar un correo específico al usuario `admin` (`id_usuario = 1`) o si dicho correo debe configurarse desde el módulo de Usuarios para que también pueda autorizar cierres anticipados.
6. **Base de ensayo lista para el Paso 5:**
   - La copia verificada `amazon_market_sprint3_verify_20261112_012430` queda lista para ensayar en el Paso 5 las migraciones incrementales de `database/tercerSprint` antes de tocar la base real `amazon_market`.
