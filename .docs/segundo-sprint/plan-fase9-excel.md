# Fase 9 — Exportación Excel de Kardex y Variaciones

## Objetivo

Conectar los botones Excel ya presentes en Reportes → Kardex y Reportes → Variaciones. Cada archivo debe representar la consulta que el Administrador ya ejecutó, servir para analizar el inventario y mantener la presentación institucional y las cifras de la Fase 8 PDF.

## Alcance aprobado

- Usar `GET /api/reportes/exportar-excel?tipo=kardex` y `tipo=variaciones`, con la misma `referencia_consulta` y los filtros aplicados en pantalla.
- Generar un libro XLSX con una hoja de resumen ejecutivo primero y una hoja de detalle después.
- En Kardex, admitir producto individual sin movimientos dentro del período cuando la consulta tenga saldo inicial válido; mostrar saldo inicial, entradas, salidas y saldo de cierre.
- En consultas generales, desglosar cantidades por producto y unidad; no presentar una suma de stock entre unidades diferentes.
- En Variaciones, respetar `tipo_variacion`, `sentido` (`TODOS`, `INCREMENTO`, `DISMINUCION`), fechas, búsqueda y clasificación histórica; resumir operaciones por producto/tipo sin convertir conteos en pérdidas monetarias.
- Mantener autorización de Administrador activo y permisos `reportes.kardex` / `reportes.variaciones` más `reportes.exportar`.
- Rechazar referencia faltante, expirada, ajena, alterada y consultas vacías generales con los códigos del contrato API.
- Generar temporales privados y eliminarlos; responder XLSX con descarga privada y `no-store`.

## Fuera de alcance y restricciones

- No modificar esquema ni datos de `amazon_market`; el trabajo y las pruebas apuntan a `amazon_market_sprint2_test`.
- Esta fase no sincroniza registros ni ejecuta la Fase 10. No requiere script SQL porque no cambia la base.
- No alterar botones, filtros, diseño ni contratos de PDF existentes salvo correcciones necesarias para la nueva descarga.
- Conservar exportaciones Excel existentes de compras, proveedores y los demás reportes.

## Interfaz entre componentes

- `ReporteApiController` valida el usuario y tipo, quita `tipo` del filtro de reporte, invoca el método Excel de `ReporteService` y devuelve los bytes XLSX.
- `ReporteService` reutiliza la validación de referencia y lectura íntegra de la instantánea usada por PDF.
- Un generador XLSX recibe los datos ya normalizados y no vuelve a consultar tablas de movimientos.
- `Assets/js/api.js` valida MIME y estructura ZIP básica; `kardex.js` y `variaciones.js` envían los filtros aplicados y la referencia actual.

## Verificación para cierre

- Pruebas HTTP/XLSX en la base aislada: archivos válidos; resumen y detalle coincidentes con la referencia; cero movimientos individuales; errores 403/409/410/422; movimiento posterior no incluido.
- Pruebas frontend: descarga binaria, filtros y referencia, errores JSON, y botón deshabilitado al quedar obsoleta la referencia.
- Lint PHP/JavaScript, regresiones de Fases 2–8 y `git diff --check`.
- Registrar resultados en `estado-fases.md`, `plan-pruebas.md` y `contrato-api.md`.

## Resultado ejecutado (25/10/2026)

- Fase 9 implementada. Pruebas HTTP/XLSX: **20 PASS / 0 FAIL**; frontend: **4 PASS / 0 FAIL**.
- Regresiones Fases 2–8 y suites cruzadas: todas aprobadas; detalles en [estado-fases.md](estado-fases.md) y [plan-pruebas.md](plan-pruebas.md).
- `amazon_market` no se modificó; la copia de pruebas quedó en el escenario limpio. No hubo cambios de esquema ni script SQL.
- La inspección de estructura, contenido y estilos se realizó sobre el OOXML generado; la revisión visual en Excel de escritorio queda pendiente.
- En el momento de cierre de la Fase 9 restaba la Fase 10; se completó después y sus resultados están en [plan-fase10-verificacion-integral.md](plan-fase10-verificacion-integral.md). La inspección visual pendiente de PDF y Excel permanece documentada.
