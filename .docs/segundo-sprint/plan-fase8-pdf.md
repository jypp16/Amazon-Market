# Fase 8: reportes de inventario en PDF

> **Ejecución:** `superpowers:executing-plans`, tarea solicitada directamente. Pasos comprobables, con prueba antes del cambio de producción.

**Objetivo:** Generar y descargar PDF de Kardex y Variaciones idénticos a la consulta congelada en `referencia_consulta`, útiles para la gerencia y respetando los colores y reglas acordadas.

**Diseño:** Mantener los endpoints existentes y agregar la rama para `tipo=kardex` y `tipo=variaciones`. Validar propiedad, vencimiento, filtros y exportabilidad con los servicios canónicos; recuperar todas las filas ordenadas por la instantánea; renderizar un resumen ejecutivo antes del detalle con la biblioteca institucional `ReportPDF`/FPDF. No reconstruir datos en vivo ni sumar existencias entre productos distintos.

**Especificación:** `.docs/segundo-sprint/acuerdos.md`, `.docs/segundo-sprint/contrato-api.md`, `.docs/segundo-sprint/plan-pruebas.md` (CP-HU09-06/07, CP-HU10-06, CP-CONS-01/02) y `.docs/segundo-sprint/estado-fases.md`.

## Restricciones

- Las escrituras de prueba solo pueden conectarse a `amazon_market_sprint2_test`; `amazon_market` no se modifica durante las fases 1 a 9.
- Las exportaciones son exclusivas de Administrador activo y `referencia_consulta` es obligatoria.
- Referencia expirada: 410; referencia ajena: 403; filtros alterados: 409; reporte no exportable: 422 `EMPTY_EXPORT_NOT_ALLOWED`.
- Kardex de un producto sin movimientos en el período sí se exporta con saldo inicial, entradas 0, salidas 0 y saldo final igual al inicial. Kardex general vacío y Variaciones vacías no se exportan.
- El resumen precede al detalle, incluye responsable y lote donde corresponda, y presenta las cifras por producto. No se inventan pérdidas monetarias ni un saldo global de productos con unidades distintas.
- Mantener la pantalla y sus colores institucionales; no implementar Excel (fase 9) ni migrar datos o estructura de la base habitual (fase 10).

## Archivos

- Modificar `Models/KardexModel.php` para recuperar el conjunto completo de una instantánea.
- Modificar `Services/ReporteService.php` para validar y preparar ambas exportaciones desde la instantánea.
- Modificar `Controllers/API/ReporteApiController.php` para autorización, errores JSON, tipos de reporte y descarga binaria.
- Crear `Libraries/PDF/ReporteKardexPDF.php` y `Libraries/PDF/ReporteVariacionesPDF.php` usando `ReportPDF` y FPDF actuales.
- Ajustar `Libraries/PDF/ReportPDF.php` solo para que las tablas aprovechen páginas apaisadas sin alterar documentos verticales.
- Conectar los botones PDF ya diseñados en `Assets/js/kardex.js` y `Assets/js/variaciones.js` al mecanismo de descarga de `Assets/js/api.js`, conservando la referencia y los filtros aplicados.
- Crear `tests/sprint2/fase8_pdf_test.php` con consultas, permisos, integridad del PDF, lectura textual y reglas de exportación sin resultados.
- Crear `tests/sprint2/fase8_frontend_download_test.js` para validar conexión de botones, descarga binaria y manejo de errores JSON.
- Actualizar el contrato, las pruebas de aceptación y el estado de fases.

## Tareas

1. [x] Escribir el test de integración rojo contra el servidor de pruebas y reproducir que la descarga genérica no entrega un reporte de Kardex/Variaciones.
2. [x] Añadir la carga completa de los ítems congelados y el método de servicio de exportación; validar propiedad, vencimiento, filtros y exportabilidad.
3. [x] Crear los dos generadores con encabezado institucional, período y filtros, hora de base de datos, resumen ejecutivo y tablas de detalle con auditoría.
4. [x] Conectar la descarga binaria, los errores JSON, el acceso Administrador y los botones PDF existentes en Kardex y Variaciones.
5. [x] Verificar estructura y texto; ejecutar las 21 comprobaciones PDF de Fase 8 en la base de pruebas; revisar sintaxis PHP/JavaScript.
6. [ ] Renderizar y revisar visualmente páginas de ambos reportes. El renderizador local y el canal de automatización de Windows no están disponibles en este entorno; la inspección visual sigue pendiente.

## Resultado de ejecución

- **Base usada:** `amazon_market_sprint2_test`. No se cambió la configuración `.env` ni se escribió en `amazon_market`.
- **Prueba de integración:** `tests/sprint2/fase8_pdf_test.php` — **21 PASS / 0 FAIL**.
- **Seguridad revisada:** Vendedor rechazado con 403; Administrador diferente rechazado con `QUERY_REFERENCE_FORBIDDEN`; la referencia vencida devuelve 410 y los filtros alterados 409.
- **Instantánea:** ambos PDF excluyen un movimiento de prueba insertado después de crear la referencia; el saldo y los detalles continúan correspondiendo a esa consulta.
- **Casos vacíos:** Kardex individual sin movimientos se exporta con saldos iguales; Kardex general y Variaciones vacíos devuelven 422 `EMPTY_EXPORT_NOT_ALLOWED`.
- **Compatibilidad:** un reporte PDF existente continúa generando un binario vertical A4.
- **Interfaz:** `tests/sprint2/fase8_frontend_download_test.js` — **3 PASS / 0 FAIL**. Los botones existentes invocan la descarga con la referencia y filtros aplicados; los errores JSON se muestran en el mensaje accesible de cada vista.
- **Revisión visual:** pendiente por falta de un renderizador PDF accesible y porque Windows Computer Use no pudo iniciar su canal nativo (`El sistema no puede encontrar el archivo especificado`). La extracción de texto se verificó con `pdftotext -enc UTF-8 -layout`.
