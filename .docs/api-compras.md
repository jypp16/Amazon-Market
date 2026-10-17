# API de Compras

Esta documentación describe el uso de los endpoints relacionados con las compras (Fase 2).

## 1. Crear Compra

Registra una nueva compra (cabecera), sus detalles (productos y presentaciones), actualiza el stock, y registra el movimiento en el Kardex de forma atómica.

- **URL:** `/api/compraapi`
- **Método:** `POST`
- **Content-Type:** `application/json`

### Body (JSON)

```json
{
  "id_proveedor": 1,
  "id_tipo_comprobante": 1, 
  "serie": "F001",
  "numero": "00001234",
  "fecha_emision": "2026-10-09",
  "fecha_recepcion": "2026-10-09",
  "total_documento": 1500.50,
  "detalles": [
    {
      "id_producto": 15,
      "id_presentacion": 2, 
      "cantidad_presentaciones": 10,
      "equivalencia_aplicada": 12,
      "importe_final_linea": 500.00,
      "numero_lote": "LOTE-2026A",
      "fecha_vencimiento": "2027-12-31"
    },
    {
      "id_producto": 18,
      "id_presentacion": 3,
      "cantidad_presentaciones": 5,
      "equivalencia_aplicada": 24,
      "importe_final_linea": 1000.50,
      "numero_lote": null,
      "fecha_vencimiento": null
    }
  ]
}
```

### Notas de los campos:
* **`id_presentacion`**: ID de la presentación usada en la compra (Ej: 1 = Unidad, 2 = Caja x 12).
* **`cantidad_presentaciones`**: Cuántas cajas/paquetes se compraron.
* **`equivalencia_aplicada`**: Cuántas unidades base contiene esa presentación (Ej: 12, 24).
* El backend calculará automáticamente la `cantidad_base` (`cantidad_presentaciones * equivalencia_aplicada`) para actualizar el stock.
* **Duplicados**: Se considera duplicado (y se rechaza) si ya existe una compra con el mismo `id_proveedor`, `id_tipo_comprobante`, `serie` y `numero`.

### Respuestas

**Éxito (201 Created):**
```json
{
  "status": true,
  "message": "Compra registrada correctamente.",
  "id_compra": 45
}
```

**Error (400 Bad Request) - Ejemplo Validación:**
```json
{
  "status": false,
  "message": "Falta el campo requerido: serie"
}
```

**Error (400 Bad Request) - Ejemplo Duplicado:**
```json
{
  "status": false,
  "message": "El comprobante ya se encuentra registrado para este proveedor."
}
```

**Error (400 Bad Request) - Ejemplo Falla en Transacción:**
```json
{
  "status": false,
  "message": "Producto no encontrado ID: 999"
}
```

## 2. Historial de compras: filtros múltiples

- **URL:** `/api/compras`
- **Método:** `GET`
- **Permiso:** `compras.listar`, aplicado por el controlador en el servidor.

| Parámetro | Contrato |
| --- | --- |
| `page` | Página, desde 1. Predeterminado: 1. |
| `limit` | Registros por página, máximo 100. Predeterminado: 10. |
| `search` | Texto de búsqueda en serie, número o datos del proveedor. |
| `proveedor` | Identificador positivo de un proveedor existente. Ausente o vacío: todos. Se permiten referencias históricas de proveedores inactivos. |
| `fecha_desde` | Recepción mínima incluida, `AAAA-MM-DD`. Ausente o vacía: sin límite inferior. |
| `fecha_hasta` | Recepción máxima incluida, `AAAA-MM-DD`. Ausente o vacía: sin límite superior. |
| `estados[]` | Lista no vacía formada por Confirmada, Corregida y/o Anulada. Ausente: Confirmada y Corregida. |

Los estados se combinan mediante OR, y ese grupo se combina mediante AND con proveedor, fechas y búsqueda. Las fechas deben ser reales y la inicial no puede ser posterior a la final. Estados vacíos, desconocidos, anidados o con estructura distinta de lista se rechazan con HTTP 400 y `status: false`, al igual que proveedores y fechas inválidos.

Ejemplo:

```text
/api/compras?estados[]=Confirmada&estados[]=Corregida&proveedor=1&fecha_desde=2026-10-01&fecha_hasta=2026-10-16&page=1&limit=10
```

Compatibilidad: se conserva el parámetro anterior `estado` como cadena de un solo estado. Su valor vacío conserva el significado anterior de Todos. No se permite enviar `estado` y `estados` simultáneamente. La interfaz nueva utiliza únicamente `estados[]`.

### Respuesta

HTTP 200 con `status`, `message`, `data`, `resumen`, `filtros`, `total`, `paginas`, `pagina_actual` y `por_pagina`. `data` contiene las cabeceras de la página: `id_compra`, proveedor, comprobante, serie/número, fechas, estado real, `total_documento`, `razon_social`, `tipo_comprobante`, `nombre_usuario` y demás campos de cabecera existentes. Se agrega `total_centimos` sin transformar el importe decimal original.

`resumen` representa **todo el filtro**, no solo la página:

| Campo | Significado |
| --- | --- |
| `total_registros` | Cantidad total de compras seleccionadas. |
| `compras_validas`, `total_validas` | Cantidad e importe de Confirmadas y Corregidas. |
| `compras_anuladas`, `total_anuladas` | Cantidad e importe anulados, sin agregarlos a vigentes. |
| `unidades_ingresadas` | Suma histórica de `cantidad_base` de detalles vigentes seleccionados. |
| `productos_mas_comprados` | Lista de todos los empatados: `id_producto`, `producto`, `cantidad_base`. |
| `producto_mas_comprado` | Nombres de los empatados; sin compras vigentes: No aplica. |
| `cantidad_mas_comprada` | Cantidad máxima por producto; sin compras vigentes: 0. |
| `compras_con_diferencias` | Cabeceras con importe distinto de la suma de sus detalles o sin detalles. |

Los importes totales son cadenas decimales exactas, por ejemplo `"30.42"`. Cantidades y conteos se entregan como enteros. Las cantidades históricas no se recalculan usando la equivalencia actual del catálogo ni se suman versiones del historial de correcciones.

`filtros` devuelve la selección normalizada: estados en orden Confirmada/Corregida/Anulada, proveedor o null, nombre real del proveedor, y fechas o null.

### Contrato interno para las fases PDF y Excel

```php
CompraService::obtenerReporteCompras(array $filtros, int $id_usuario): array
```

Recibe los filtros de proveedor, recepción y estados con las reglas anteriores. El controlador futuro debe proporcionar el identificador autenticado del usuario y aplicar permisos antes de llamarlo; no debe obtener ese identificador de los filtros enviados por el cliente. No se creó un endpoint de reporte en esta fase.

Retorna:

- `metadatos`: `negocio`, `titulo`, `fecha_desde`, `fecha_hasta`, `proveedor`, `estados`, `usuario` y `fecha_emision` en America/Lima. Proveedor y usuario proceden de los registros del servidor; no se aceptan nombres suministrados por el cliente.
- `filtros`: selección normalizada.
- `resumen`: el mismo contrato de resumen del historial, sin paginación.
- `compras`: todas las cabeceras seleccionadas con `detalles`, `total_detalles` y `estado_reporte`. Conserva el estado real y utiliza Vigente para Confirmada/Corregida, o Anulada, en `estado_reporte`.

Cada detalle conserva los campos originales, producto y presentación; agrega `importe_centimos`, `costo_por_presentacion` como cadena decimal de ocho decimales, y `vencimiento` con la fecha o No aplica si el dato es null. El importe final guardado prevalece sobre cualquier multiplicación del costo derivado redondeado.

El reporte consulta cabeceras y detalles por lotes, sin una consulta individual por cada compra. Usa la misma lógica de filtro y resumen del historial. Si hay diferencias de importes o compras sin detalle, lanza `DomainException` y no repara registros automáticamente. Un resultado vacío devuelve compras y productos vacíos e importes `0.00`; las fases de exportación deberán impedir la generación de archivos vacíos y mostrar la alerta acordada.

## 3. Anular Compra (RC02 - Fase 3)

Anula una compra registrada. Esta acción resta el stock sumado originalmente e invalida el movimiento. El sistema validará que al anular no queden productos con stock negativo y exige obligatoriamente un motivo de anulación para auditoría.

- **URL:** `/api/compras/{id}`
- **Método:** `DELETE`
- **Content-Type:** `application/json`

### Body (JSON)
```json
{
  "motivo": "Comprobante duplicado por error del proveedor"
}
```

**Éxito (200 OK):**
```json
{
  "status": true,
  "message": "Compra anulada correctamente."
}
```

**Error por motivo faltante (400 Bad Request):**
```json
{
  "status": false,
  "message": "El motivo de anulación es obligatorio."
}
```

**Error por stock negativo (400 Bad Request):**
```json
{
  "status": false,
  "message": "No se puede anular la compra. El producto 'Leche Gloria' quedaría con stock negativo."
}
```

## 4. Corregir / Editar Compra (RC01 - Fase 3)

Edita una compra existente (cambiar detalles, cantidades, etc.). Revertirá internamente los ingresos previos y registrará los nuevos movimientos. Valida estrictamente para evitar stocks negativos y exige obligatoriamente un motivo de corrección.

- **URL:** `/api/compras/{id}`
- **Método:** `PUT`
- **Content-Type:** `application/json`

### Body (JSON)
*La estructura es la misma que al Crear Compra, incluyendo obligatoriamente el campo `"motivo"` en la cabecera.*

```json
{
  "motivo": "Corrección de cantidad y presentación por error de digitación",
  "id_proveedor": 1,
  "id_tipo_comprobante": 1,
  "serie": "F001",
  "numero": "00001234",
  "fecha_emision": "2026-10-09",
  "fecha_recepcion": "2026-10-09",
  "total_documento": 1200.00,
  "detalles": [
    {
      "id_producto": 15,
      "id_presentacion": 2,
      "cantidad_presentaciones": 8,
      "equivalencia_aplicada": 12,
      "importe_final_linea": 400.00,
      "numero_lote": "LOTE-2026A",
      "fecha_vencimiento": "2027-12-31"
    }
  ]
}
```

**Éxito (200 OK):**
```json
{
  "status": true,
  "message": "Compra corregida correctamente."
}
```

**Error por motivo faltante (400 Bad Request):**
```json
{
  "status": false,
  "message": "El motivo de corrección es obligatorio."
}
```

## 5. Alertas de Stock Bajo (Fase 3)

Obtiene los productos cuyo `stock_actual` está por debajo de su `stock_minimo`.

- **URL:** `/api/alertas/stock`
- **Método:** `GET`

**Éxito (200 OK):**
```json
{
  "status": true,
  "data": [
    {
      "id_producto": 5,
      "nombre": "Aceite 1L",
      "stock_actual": 2,
      "stock_minimo": 10
    }
  ]
}
```

## 6. Alertas de Vencimientos (Fase 3)

Obtiene los productos o lotes que vencen dentro de los próximos 15 días (o ya vencidos).

- **URL:** `/api/alertas/vencimientos`
- **Método:** `GET`

**Éxito (200 OK):**
```json
{
  "status": true,
  "data": [
    {
      "id_producto": 8,
      "nombre": "Yogurt de Fresa",
      "numero_lote": "LOTE-123",
      "fecha_vencimiento": "2026-10-15",
      "dias_restantes": 6
    }
  ]
}
```


## 7. PDF del historial de compras (fase 3 de reportes)

- `GET /api/reportes/exportar-pdf?tipo=compras&fecha_desde=2026-10-01&fecha_hasta=2026-10-16&estados[]=Confirmada&estados[]=Corregida`.
- Requiere sesión y permisos `reportes.ver`, `reportes.exportar` y `compras.listar`, comprobados en servidor. No admite identidad de usuario enviada por el cliente.
- Filtros y cálculos: contrato del apartado 2, por fecha de recepción. `proveedor` es opcional. Se exportan todas las compras y detalles del filtro, sin `page` ni `limit`.
- Éxito: HTTP 200, `Content-Type: application/pdf`, `Content-Disposition: inline; filename="Reporte_compras_YYYY-MM-DD_HHMMSS.pdf"`, `Cache-Control: private, no-store`. El archivo se compone en memoria; no se guarda en el servidor.
- Errores JSON: 400 para filtros inválidos; 401 sin sesión; 403 sin permisos; 422 para ausencia de resultados o inconsistencias de compras; 500 para un fallo de generación. Sin resultados: «No hay compras registradas en el periodo o filtro seleccionado para imprimir».
- La plantilla `ReporteComprasPDF` extiende `ReportPDF` sin cambiar la plantilla compartida por ventas. Confirmada/Corregida se representan como Vigente en las compras del documento; los estados almacenados y los filtros mantienen sus nombres reales.
- El botón usa una petición binaria independiente de `Api`, que conserva su contrato JSON. Exporta la última selección aplicada y cargada correctamente; mientras se carga un nuevo listado exige esperar. La selección actual vacía sigue bloqueando cualquier exportación.
- Se reserva una pestaña en el clic, se muestra el diálogo «Generando reporte…», se deshabilitan exportaciones/Filtrar y se permite cancelar (también con Escape). Espera máxima del cliente: 120 segundos. Red, timeout, contenido inesperado y sesión vencida cierran la pestaña provisional y restauran controles.
- Antes de abrir el archivo se comprueban HTTP, ausencia de redirección, tipo MIME y firma `%PDF-`. Una respuesta JSON malformada conserva un mensaje de error en español.
- La URL de Blob se mantiene para imprimir/guardar y se revoca al cerrar el visor. No hay cola, historial ni copias permanentes. El botón Excel se conserva para la fase 4.


## 8. Excel del historial de compras (fase 4 de reportes)

- `GET /api/reportes/exportar-excel?tipo=compras&fecha_desde=2026-10-01&fecha_hasta=2026-10-16&estados[]=Confirmada&estados[]=Corregida`.
- Misma sesión, permisos (`reportes.ver`, `reportes.exportar`, `compras.listar`), filtros, validación, cálculos y errores del PDF. No usa `page`/`limit`: contiene todas las compras coincidentes y su versión actual, sin exportar el historial de correcciones como nuevas compras.
- HTTP 200: `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, `Content-Disposition: attachment; filename="Reporte_compras_YYYY-MM-DD_HHMMSS.xlsx"`, `Cache-Control: private, no-store`. Sin resultados: HTTP 422 y el mismo mensaje acordado para imprimir, mediante JSON.
- `ExcelService::generarCompras()` devuelve el contenido binario. Usa OpenSpout existente, una carpeta privada `compras_excel_<aleatorio>` en el directorio temporal y limpieza en finally, además de una función de cierre registrada para el fin de la petición. El archivo temporal no se entrega como una ruta persistente al controlador.
- Hoja Compras: metadatos, resumen separado de vigentes/anuladas, todos los productos empatados y sus cantidades base, compras con detalles debajo, totales por compra y finales. Etiquetas Vigente/Anulada solo en el documento; estados internos intactos.
- Celdas de texto explícitas (`StringCell`) para nombres, series y números; no se convierten textos `=...` en fórmulas y se conservan los ceros. Fechas reales con formato, cantidades numéricas, importes numéricos con dos decimales y costos con hasta ocho. Los importes finales vienen del contrato común, sin derivarlos del costo redondeado. La precisión de conversión PHP se ajusta únicamente durante esta generación y se restaura en finally.
- El botón reutiliza modal, cancelación, espera de 120 segundos y bloqueo compartido con PDF. No abre pestaña. Antes de crear la descarga comprueba HTTP, redirecciones, MIME, firma ZIP y presencia de entradas OOXML del libro. JSON, HTML y contenido inválido se comunican como error, sin descargarlos.
- El Blob de descarga se revoca a los 60 segundos para permitir que el navegador inicie el guardado. El PDF mantiene su liberación al cerrar el visor. `Api` y los demás flujos de `reportes.js` conservan su contrato.
