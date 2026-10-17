# Avance de reportes de compras

## Fase 1: estado de las compras corregidas

Fecha: 16 de octubre de 2026.

### Alcance y causa

Se revisó el recorrido `Assets/js/compras.js` → `PUT /api/compras/{id}` → `CompraApiController::put()` → `CompraService::corregirCompra()` → `CompraModel::update()` → consultas de listado y detalle.

El formulario ya enviaba la corrección por PUT y las vistas ya representaban el estado recibido. El servicio actualizaba cabecera, motivo y fecha de corrección, pero omitía cambiar `estado`. Además, `CompraModel::existeComprobante()` solo reconocía compras Confirmadas y la corrección no comprobaba si el nuevo documento pertenecía a otra compra vigente.

### Implementación

- El servicio establece `Corregida` en la misma transacción que cabecera, detalles, stock, Kardex e historial. No acepta el estado enviado por el cliente.
- Una nueva corrección conserva `Corregida`. Se mantiene el rechazo de correcciones sobre compras Anuladas.
- La detección de comprobantes vigentes contempla `Confirmada` y `Corregida`.
- Antes de corregir se comprueba proveedor, tipo, serie y número, excluyendo la propia compra para permitir conservar su referencia.
- No se modificaron frontend, filtros, PDF ni Excel. No se reclasificaron compras históricas.

### Entorno y conservación de datos

Se comprobó por lectura la conexión configurada en `.env`: base `amazon_market`, MariaDB 10.4.32 de XAMPP. `compra.estado` es VARCHAR(20), por lo que admite `Corregida` sin modificar el esquema. Compra, detalle, producto, Kardex e historial usan InnoDB.

La prueba CLI genera tablas `TEMPORARY` vacías en la misma conexión MySQL usada por los modelos. Antes de insertar datos confirma su naturaleza temporal. Estas tablas ocultan únicamente para esa conexión las tablas persistentes homónimas y desaparecen al terminar. No se guardaron fixtures, no se modificaron registros reales y no se ejecutaron importaciones ni migraciones.

Las copias temporales conservan columnas, índices y motor del esquema inspeccionado, pero no claves foráneas, porque MariaDB no las admite en tablas temporales. No equivalen a una comprobación de todas las restricciones referenciales de las tablas persistentes.

### Verificación ejecutada

Comando desde la raíz del proyecto:

```powershell
& 'C:\xampp\php\php.exe' 'tests\ComprasCorreccionTest.php'
```

Se observó el fallo de las pruebas de regresión antes de modificar producción: 8 casos, 5 fallos. Después del cambio: **8 casos, 0 fallos**.

| Caso | Resultado |
| --- | --- |
| PUT sobre Confirmada, con intento de enviar estado Anulada | Guarda Corregida; stock e historial coherentes |
| Segunda corrección con el mismo comprobante propio | Conserva Corregida y ambas entradas de auditoría |
| Error de base de datos al insertar un nuevo detalle, después de actualizar cabecera | Revierte cabecera, estado, detalles, stock, Kardex e historial |
| Stock insuficiente para revertir el ingreso anterior | Rechazo sin cambios parciales |
| Intento de corregir una Anulada | Rechazo sin cambios |
| Nuevas consultas GET de detalle y listado | Recuperan Corregida |
| Registro con comprobante de otra compra Corregida | Rechazo sin nuevo ingreso |
| Corrección con comprobante de otra compra vigente | Rechazo sin cambios |

Las comprobaciones API se ejecutan en proceso por CLI usando el controlador real y capturando su respuesta; no son solicitudes HTTP ni una sesión de navegador.

Se comprobó sintaxis PHP de `Services/CompraService.php`, `Models/CompraModel.php` y `tests/ComprasCorreccionTest.php`, y se ejecutó `git diff --check`. No se encontró una suite automatizada previa ni un manifiesto de pruebas en este checkout.

### Límites y pendientes

- El usuario confirmó posteriormente en el chat que el estado ya cambia correctamente en pantalla. La lectura por controlador/modelos también quedó verificada en ejecución; el agente no realizó aquella demostración manual en la sesión real.
- No se probaron solicitudes simultáneas ni se añadió un mecanismo nuevo de exclusión concurrente de comprobantes.
- Las compras antiguas que ya habían sido corregidas pero quedaron Confirmadas no se actualizaron automáticamente.
- Al cerrar la fase 1 no se había implementado la fase 2; su avance se registra a continuación. Las exportaciones siguen pendientes de las fases 3 y 4.


## Fase 2: filtros por estados y datos comunes de reportes

Fecha: 16 de octubre de 2026. Ejecutada únicamente esta fase; no se generaron PDF ni Excel.

### Implementación y decisiones

- Checkboxes Confirmada y Corregida marcados inicialmente, Anulada desmarcada. No hay una casilla Vigente: es una agrupación del resumen y una etiqueta del reporte.
- Se mantiene Filtrar. Las páginas utilizan los filtros de la última consulta exitosa, no cambios del formulario aún no aplicados. Se conserva su query en `formulario.dataset.filtrosAplicados` para las próximas fases.
- Una selección actualmente vacía bloquea cualquier consulta, también la paginación, conforme al criterio de exigir al menos un estado. Un aviso junto al grupo y el foco de teclado permiten corregirla.
- Limpiar recarga los valores iniciales, incluyendo proveedor Todos y el periodo inicial existente. Se muestra el importe anulado separado en su tarjeta.
- Validación compartida en `CompraService::normalizarFiltrosCompras()`: estados, proveedor existente y fechas reales e incluyentes de recepción. Compatibilidad explícita con `estado` heredado; contrato nuevo `estados[]`.
- `CompraModel` comparte la construcción de consultas y el resumen. Cuenta compras una sola vez y suma sus detalles agrupados, sin inflar totales por múltiples lotes o vencimientos.
- Vigentes = Confirmada + Corregida. Anuladas quedan fuera de importe y unidades vigentes y del producto más comprado.
- El ranking suma `cantidad_base` histórica por producto, admite todos los empates y devuelve No aplica con cero unidades cuando no hay vigentes.
- `CompraService::obtenerReporteCompras()` entrega metadatos y todas las compras con sus detalles, sin límite de página. Los nombres de proveedor y usuario se obtienen del servidor.
- Importes originales y sumas usan DECIMAL de MySQL; las verificaciones por compra comparan céntimos enteros. El costo derivado por presentación conserva ocho decimales, sin sustituir el importe final guardado.
- El historial advierte diferencias cabecera/detalles; el conjunto de datos del reporte las rechaza. No se corrigieron datos históricos automáticamente.
- Se usaron transacciones de lectura para mantener coherencia entre consultas bajo el aislamiento habitual de MariaDB. No se alteraron aislamiento global ni esquema.
- Se corrigió el desbordamiento verificado en móvil con cambios CSS acotados al historial: ancho del contenedor, tabla desplazable y elementos ocultos correctamente contenidos. Los checkboxes conservan etiquetas y funcionamiento por teclado.

### Verificación ejecutada

Se observó la regresión antes de implementar: 18 casos nuevos, 17 fallos. Tras implementar: **18 casos nuevos aprobados** y **8 de la fase 1 aprobados**.

Los casos cubren las siete combinaciones no vacías de estados; valores predeterminados y contrato legado; entradas vacías, inválidas y anidadas; proveedor y fechas; ambas fechas límite; paginación; céntimos; empates históricos; múltiples vencimientos; solo anuladas; metadatos; resultados vacíos y diferencias de importes.

La base real `amazon_market` / MariaDB 10.4.32 se consultó en solo lectura con el servicio actualizado. La consulta fue correcta y no se detectaron diferencias de importes en su selección inicial. No se imprimieron datos sensibles ni credenciales.

Comandos desde la raíz:

```powershell
& 'C:\xampp\php\php.exe' 'tests\ComprasCorreccionTest.php'
& 'C:\xampp\php\php.exe' 'tests\ComprasFiltrosReporteTest.php'
$env:NODE_PATH = 'C:\Users\LOQ\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\node_modules'
node 'tests\ComprasFiltrosInterfaz.cjs'
```

La prueba de interfaz usa el Playwright ya disponible en el runtime y Chrome instalado, sin agregar dependencias a la aplicación. Puede indicarse otro PHP con `COMPRAS_PHP` u otro canal instalado con `COMPRAS_BROWSER`.

**7 comprobaciones de interfaz aprobadas**: carga inicial; selección vacía sin petición; solo anuladas; filtros aplicados al paginar y reinicio de página al filtrar; proveedor/fechas y errores sin totales obsoletos; Limpiar y reintento tras fallo de red; teclado y visualización móvil sin desbordamiento. Se inspeccionaron capturas de escritorio y móvil.

Las pruebas PHP y las respuestas del navegador usan fixtures en tablas TEMPORARY MySQL, compartiendo `tests/soporte/EntornoCompras.php` y `DatosFiltrosCompras.php`. No se insertaron compras reales ni fixtures permanentes. Se mantuvieron los límites de claves foráneas de la fase 1. El navegador renderiza la vista y los scripts reales; su transporte HTTP se intercepta para ejecutar el controlador PHP contra esos fixtures aislados. Esto no equivale a una prueba del inicio de sesión o del router Apache de la instalación real.

Se realizó una revisión independiente sin defectos nuevos concretos de este alcance. El contrato detallado está en `api-compras.md`, apartado 2.

### Pendiente de las siguientes fases

- Fase 3: conectar el PDF, modal de generación, apertura en navegador y comparación visual con el modelo.
- Fase 4: conectar Excel y su descarga.
- Fase 5: demostración integral y mediciones representativas de generación.
- El cliente de API sigue esperando JSON. La recepción de archivos binarios y la asociación accidental del botón PDF con proveedores se resolverán en fase 3; no se modificaron exportaciones en esta fase.


## Fase 3: PDF de compras y apertura en navegador

Fecha: 16 de octubre de 2026. Alcance: únicamente PDF; Excel permanece pendiente de la fase 4.

### Resultado

- Se leyeron la historia de usuario adjunta y las dos páginas de `C:\Users\LOQ\Downloads\Reporte_compras_v2.pdf`; se renderizaron e inspeccionaron visualmente, igual que las muestras generadas.
- Nueva plantilla A4 vertical `Libraries/PDF/ReporteComprasPDF.php`, basada en FPDF/ReportPDF: identidad dorada, metadatos y estados seleccionados, cuatro tarjetas de resumen, todos los productos empatados por cantidad base, cabecera y detalles completos, importes por presentación, vencimiento/No aplica, total por compra, totales finales y Página X/Y. Anuladas diferenciadas con importes tachados legibles.
- El contrato y las sumas siguen en `CompraService::obtenerReporteCompras()`. Confirmada/Corregida se muestran como Vigente en cada compra del reporte; no se cambian estados almacenados. Se conserva el orden del contrato común (ID descendente), aunque el ejemplo está ordenado ascendentemente.
- Alturas calculadas para nombres largos; compras que caben completas en una página nueva se mantienen juntas. Las extensas continúan por filas, repitiendo identificación y encabezados. El encabezado inicial reserva espacio para su primera compra y no queda solo. No existe un límite de dos páginas.
- Endpoint de compras en `ReporteApiController`: sesión y permisos de reportes/exportación/compras, PDF en memoria e inline, errores JSON. No se modificó `ReportPDF.php` ni las rutinas de ventas.
- `compras.js` recibe el binario con fetch sin alterar `Api`; usa los filtros aplicados, reserva la pestaña, muestra un diálogo nativo reutilizando los estilos existentes y evita duplicados. Timeout de 120 segundos, cancelación, errores de red/HTTP/JSON/HTML, comprobación MIME/firma y limpieza de pestaña/controles. Blob revocado al cerrar el visor, permitiendo imprimir/guardar mientras esté abierto.
- `proveedores.js` asocia imprimirProveedores únicamente cuando existe su tabla. Se mantiene su flujo anterior. No se agregó un botón Imprimir a compras.

### Archivos de esta fase

- `Libraries/PDF/ReporteComprasPDF.php`, `Controllers/API/ReporteApiController.php`.
- `Assets/js/compras.js`, `Assets/js/proveedores.js`, `Views/Compra/index.php`, `Assets/css/style.css`, `Views/Footer.php` (versión del script según fecha de modificación).
- `tests/soporte/EntornoPdfCompras.php`, `tests/ComprasPdfTest.php`, `tests/ComprasPdfInterfaz.cjs`, `tests/GenerarMuestrasPdfCompras.php`, `tests/VerificarMuestrasPdfCompras.py`.
- Esta bitácora y `.docs/api-compras.md`.

### Verificación y evidencias

- PHP: 6 pruebas del endpoint/generación aprobadas, incluidas sesión, tres permisos, filtros, inconsistencias, ausencia de resultados y generación de ventas. Se mantienen aprobadas las 8 pruebas de corrección y 18 de contrato/filtros de fases anteriores.
- Chrome/Playwright: 14 grupos aprobados de PDF: visor real, disponibilidad de Imprimir, guardado/descarga de un PDF válido, filtros aplicados, modal/duplicados, liberación del recurso, ausencia de resultados, HTML de sesión, error de servidor, JSON malformado, falso PDF, red, popup bloqueado, cancelación, timeout y reintento. Proveedores conserva listado, reporte e invocación de impresión. También se mantienen aprobadas las 7 comprobaciones de filtros de la fase 2.
- El navegador usa vistas/scripts reales. El transporte del entorno de pruebas se intercepta para ejecutar los controladores PHP reales contra fixtures MySQL TEMPORARY; no simula los cálculos del reporte. La prueba de proveedores usa respuestas de prueba en su transporte, conservando su JavaScript y vista reales.
- Consulta HTTP real sin sesión a `http://localhost/Amazon-Market/api/reportes/exportar-pdf?tipo=compras`: **401 application/json**. No se inició una sesión real por Apache ni se modificaron compras reales para la demostración.
- Seis muestras generadas con el contrato común en `%TEMP%\amazon-market-fase3`: `modelo.pdf` (2 páginas, 11 compras/23 detalles, S/ 11,012.40 vigentes y S/ 432.80 anuladas, Agua mineral 660 unidades); `una_pagina.pdf` (1); `compra_extensa.pdf` (7, 140 detalles); `nombres_largos.pdf` (1, nombres de 150 caracteres con marcadores finales preservados); `empates.pdf` (10, 70 productos empatados y 70 detalles); `solo_anuladas.pdf` (1).
- `VerificarMuestrasPdfCompras.py` comprueba A4, páginas, márgenes, ausencia de superposición, compras/cabeceras, cantidad de importes por detalle, nombres completos por columna, totales con Decimal, los 140 detalles y las 70 apariciones dobles ranking/detalle, y tachados de anuladas. Se renderizaron todas las páginas con Poppler y se inspeccionaron las composiciones.
- Evidencias de navegador en esa misma carpeta temporal: `modal-carga.png`, `visor-pdf.png`, `navegador.pdf`, `guardado-desde-visor.pdf`. Son artefactos explícitos de prueba; la aplicación no guarda esas copias.
- Sintaxis PHP/JS y `git diff --check` comprobados. Revisión independiente sin defectos pendientes confirmados.

Comandos adicionales desde la raíz:

```powershell
& 'C:\xampp\php\php.exe' tests/ComprasPdfTest.php
& 'C:\xampp\php\php.exe' tests/GenerarMuestrasPdfCompras.php
$env:NODE_PATH = 'C:\Users\LOQ\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\node_modules'
node tests/ComprasPdfInterfaz.cjs
& 'C:\Users\LOQ\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe' tests/VerificarMuestrasPdfCompras.py
```

Las herramientas de pruebas ya estaban disponibles; no se añadieron dependencias a la aplicación. El Python de verificación necesita pdfplumber. Las tablas temporales conservan columnas/índices/InnoDB, pero MariaDB no admite claves foráneas en ellas. Se confirmó el destino `amazon_market` y el aislamiento antes de insertar fixtures. No hubo cambios de esquema, migraciones ni scripts SQL persistentes; por eso no se agregó un script a `database/`.

### Límites y pendientes

- Pendiente una comprobación manual en la sesión real del usuario por Apache; las pruebas descritas distinguen explícitamente el transporte aislado del acceso real sin sesión.
- No se realizó impresión física ni se aseguró una duración máxima del servidor para cualquier volumen. Los 120 segundos corresponden a la espera del cliente.
- No se avanzó a fase 4 (Excel), colas, historial ni almacenamiento de reportes.


## Fase 4: exportación Excel de compras

Fecha: 16 de octubre de 2026. El usuario confirmó en el chat que el PDF funciona perfectamente antes de autorizar esta fase. Se implementó únicamente Excel; no se avanzó a fase 5.

### Implementación

- `Services/ExcelService.php`: nuevo `generarCompras()`, con OpenSpout ya incluido en el proyecto. Reutiliza colores de encabezados oscuros/dorados y crea una hoja Compras con resumen, ranking completo y bloques de cabecera/detalles/total para cada compra. Anchos, texto ajustado y alturas para nombres largos. Configuración de impresión A4 horizontal a una página de ancho.
- Contiene negocio, título, periodo de recepción, proveedor, estados seleccionados, usuario y emisión; cantidades/importes vigentes y anulados separados; todos los empates con cantidad base; comprobante, serie/número, recepción, proveedor y estado; producto, presentación/equivalencia histórica, cantidad, vencimiento/No aplica, costo por presentación e importe final; total por compra, registros y totales finales.
- Consume exactamente `CompraService::obtenerReporteCompras()`. No añade consultas de negocio ni vuelve a calcular sumas a partir de costos redondeados. Confirmada/Corregida se representan como Vigente sin actualizar estados ni exportar versiones del historial de correcciones.
- Cantidades, costos e importes son celdas numéricas; fechas con formato; serie/número como texto. Todos los textos propios usan StringCell para impedir su interpretación como fórmulas, incluidos nombres que comienzan con `=`. Anuladas con fondo rosado e importes visibles tachados.
- `ReporteApiController.php`: rama exclusiva de compras en exportar-excel, mismos permisos y sesión del PDF, errores JSON y binario attachment sin caché. Los demás tipos de Excel mantienen su generación anterior.
- `Assets/js/compras.js`: flujo compartido PDF/Excel para filtros aplicados, modal, bloqueo de duplicados, cancelación, timeout y errores. Excel comprueba HTTP/MIME/ZIP/entradas OOXML antes de descargar mediante un enlace Blob; no abre una pestaña. Revoca su Blob a los 60 segundos. PDF conserva la pestaña reservada y su limpieza al cerrar el visor.
- `Views/Compra/index.php`: identificador del botón Excel. Se mantiene el diálogo existente y ambos botones; no se añadió Imprimir. No se modificaron `Api`, `reportes.js`, dependencias, esquema ni datos reales.
- El archivo OpenSpout y sus intermediarios se generan en una carpeta privada temporal y se eliminan en finally. Se registró limpieza de cierre de petición. En éxito se devuelve el contenido en memoria y la carpeta ya no existe cuando el controlador responde. No hay archivos permanentes, colas ni historial.

### Verificación ejecutada

- **10 pruebas PHP nuevas aprobadas** en `tests/ComprasExcelTest.php`: exportación de todo el filtro pese a page/limit; siete combinaciones de estados; solo anuladas y ranking; empates, ceros y caracteres españoles; texto con apariencia de fórmula; costo preciso; vacío/filtros inválidos; inconsistencias; estilos; limpieza ante éxito y error provocado durante generación; sesión/tres permisos; regresión de Excel de ventas e inventario.
- Se observaron fallos antes de implementar el generador y el botón. La lectura nativa detectó pérdida de estilos por copiar el estilo predeterminado después de su registro: se corrigió copiándolo antes de abrir el escritor. La revisión independiente detectó la reducción de un costo válido de quince cifras por la precisión PHP predeterminada. Ambas regresiones se reprodujeron con pruebas rojas y quedaron corregidas: `3333333.32666667` conserva ocho decimales y la precisión PHP vuelve a su valor anterior. No se parcheó OpenSpout.
- **13 grupos nuevos de navegador aprobados** en `tests/ComprasExcelInterfaz.cjs`: selección vacía; descarga desde segunda página con filtros aplicados; modal y bloqueo compartido; recursos Blob; vacío; sesión; HTML; HTTP 500; JSON malformado; falso Excel/ZIP; red; cancelación; timeout y reintento. La descarga contiene las **15 compras vigentes y S/ 30.42**, aunque la página visible solo tenía cinco.
- Regresiones: **8** pruebas de corrección, **18** de filtros/contrato, **6** de PDF, **7** grupos de filtros en navegador y **14** de PDF aprobados. Total actual: **42 pruebas PHP** y **34 grupos de navegador**, más comprobaciones de archivos. PDF conserva su visor, impresión/guardado y proveedores su flujo.
- Navegador Chrome con vistas y scripts reales. El transporte de pruebas ejecuta los controladores PHP reales contra MySQL TEMPORARY; no equivale a un inicio de sesión real en Apache. Solicitud HTTP real sin sesión al endpoint Excel: **401 application/json**.
- Se abrieron **siete XLSX en Microsoft Excel instalado**, mediante una instancia propia oculta, con macros/enlaces externos deshabilitados y libros de solo lectura. Se verificaron formato XLSX, hoja de seis columnas, conteos, ausencia de fórmulas, series/números textuales, formatos de fecha/moneda y colores. Se exportaron vistas PDF para inspección visual, sin guardar cambios en los XLSX. Evidencia: `lectura-excel.json` y los archivos `*-excel.pdf`.
- `tests/VerificarMuestrasExcelCompras.py` lee los libros con openpyxl (solo lectura, no se usa para generarlos): comprueba cada cabecera y detalle contra el contrato, tipos/formatos, textos completos, ranking, importes exactos con Decimal y coincidencia con los PDF del mismo filtro.

| Muestra | Compras / detalles | Resultado |
| --- | --- | --- |
| modelo | 11 / 23 | Vigentes S/ 11,012.40; anuladas S/ 432.80; Agua mineral, 660 unidades base; coincide con PDF |
| una_pagina | 1 / 2 | Totales y tipos correctos |
| compra_extensa | 1 / 140 | Todos los detalles presentes |
| nombres_largos | 1 / 2 | Nombres de 150 caracteres completos, con marcadores finales preservados |
| empates | 1 / 70 | Los 70 productos aparecen en ranking y detalle |
| solo_anuladas | 2 / 2 | Vigentes cero, ranking No aplica, anuladas S/ 432.80 |
| texto_seguro | 1 / 2 | `=...` y `+...` literales, tildes/ñ y ceros conservados |

Los archivos se encuentran en `%TEMP%\amazon-market-fase4`. También están `navegador.xlsx`, `modal-excel.png` y `manifesto.json`. Son evidencias explícitas de prueba; la aplicación no conserva esas copias. Las seis muestras comparativas reutilizan exactamente los fixtures y cálculos comunes del PDF. No se modificaron compras reales: se confirmó `amazon_market` y se comprobaron tablas TEMPORARY antes de insertar datos de prueba. MariaDB no admite claves foráneas en ellas; se conservan columnas, índices e InnoDB.

### Archivos de esta fase y reproducción

- Producción: `Services/ExcelService.php`, `Controllers/API/ReporteApiController.php`, `Assets/js/compras.js`, `Views/Compra/index.php`.
- Pruebas: `tests/soporte/EntornoExcelCompras.php`, `tests/ComprasExcelTest.php`, `tests/ComprasExcelInterfaz.cjs`, `tests/GenerarMuestrasExcelCompras.php`, `tests/VerificarMuestrasExcelCompras.py`, `tests/RevisarExcelCompras.ps1`.
- Documentación: esta bitácora y `.docs/api-compras.md`, apartado 8. Se conservaron los cambios previos sin commit.

```powershell
& 'C:\xampp\php\php.exe' tests/ComprasExcelTest.php
& 'C:\xampp\php\php.exe' tests/GenerarMuestrasExcelCompras.php
$env:NODE_PATH = 'C:\Users\LOQ\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\node_modules'
node tests/ComprasExcelInterfaz.cjs
& 'C:\Users\LOQ\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe' tests/VerificarMuestrasExcelCompras.py
& ./tests/RevisarExcelCompras.ps1
```

La revisión nativa requiere Microsoft Excel y acceso de automatización a la sesión de Windows. No se agregaron bibliotecas a la aplicación. Sintaxis PHP/JS y `git diff --check` sin incidencias. La revisión independiente confirmó las dos correcciones y no dejó defectos pendientes.

### Límites y pendientes

- Pendiente una comprobación manual del botón Excel en la sesión real del usuario; se distingue del transporte aislado y del HTTP real sin sesión ya comprobado.
- En un diagnóstico adicional de costo extremo, el contrato `33333333.32666667` se recuperó numéricamente desde Microsoft Excel con ocho decimales. Su visualización estándar mostró menos decimales por la presentación numérica de Excel. Los importes finales de línea/compra se mantienen separados y no se derivan de esa visualización.
- No hubo modificaciones de esquema ni scripts SQL persistentes que agregar a `database/`. No se realizó impresión física ni se ejecutó la fase 5.
