# API de Proveedores

Este documento describe cómo interactuar con el módulo de proveedores desde el frontend (`Assets/js/proveedores.js`).

## Endpoint Base
`BASE_URL + '/api/proveedores'`

## 1. Listar Proveedores
**Método:** `GET`
**URL:** `/api/proveedores`
**Respuesta Exitosa:**
```json
{
  "status": true,
  "message": "Lista de proveedores",
  "data": [
    {
      "id_proveedor": 1,
      "razon_social": "Comercializadora del Sur",
      "id_tipo_documento": 2,
      "nombre_tipo_documento": "RUC",
      "numero_documento": "20546987512",
      "telefono": "01458965",
      "estado": 1
    }
  ]
}
```

### Filtros y paginación del listado (fase 1 de reportes)

`GET /api/proveedores?search=Costa&estado=activos&page=1&limit=10`

- `search`: texto opcional, sin espacios en los extremos; busca por nombre/razón social **o** número de documento. Una búsqueda `0` también se aplica.
- `estado`: `todos` (predeterminado), `activos` o `inactivos`. Es una selección única. Cualquier otro valor, incluido un arreglo, se rechaza con HTTP 400; no se cambia el estado almacenado.
- `page`: página desde 1. Si supera el número disponible, se devuelve la última página existente.
- `limit`: registros por página; predeterminado 10.
- El grupo `(nombre coincide OR documento coincide)` se combina con el estado mediante AND y parámetros enlazados. El conteo y los datos usan la misma consulta.
- Sin coincidencias: HTTP 200, `data: []`, `total: 0`, `paginas: 1`, `pagina_actual: 1`.
- Se conservan la sesión obligatoria y el permiso `proveedores.listar`.

La respuesta conserva los campos existentes y añade los filtros normalizados:

```json
{
  "status": true,
  "message": "Lista de proveedores",
  "data": [],
  "total": 0,
  "paginas": 1,
  "pagina_actual": 1,
  "por_pagina": 10,
  "filtros": { "search": "Costa", "estado": "activos" }
}
```

Consulta común para la exportación (implementada en fase 1):

- `ProveedorService::normalizarFiltros($busqueda = '', $estado = 'todos'): array` devuelve `search` y `estado` validados.
- `obtenerProveedores($busqueda = '', $estado = 'todos')` devuelve **todas** las filas coincidentes, sin paginación.
- `obtenerProveedoresPaginado($busqueda = '', int $pagina = 1, int $porPagina = 10, $estado = 'todos')` devuelve filas, totales, paginación y `filtros`.
- Ambas consultas comparten `ProveedorModel::consultaProveedores()` y el orden por ID descendente. Se mantienen compatibles las llamadas anteriores que omiten estado.
- El cliente conserva `data-filtros-aplicados` en `#tabla_proveedores` solo después de una respuesta exitosa, incluso vacía. Lo elimina mientras carga o ante un error. Cambiar búsqueda/estado consulta la primera página; paginar conserva ambos filtros. Una respuesta antigua no reemplaza la consulta más reciente.

El flujo existente de impresión/PDF conserva su comportamiento anterior y aún no incorpora el nuevo estado. Su integración queda fuera de esta fase, cuyo filtro se aplica al listado y prepara el futuro Excel.

## 2. Crear Proveedor
**Método:** `POST`
**URL:** `/api/proveedores`
**Body (JSON):**
```json
{
  "nombre": "Distribuidora ABC",
  "id_tipo_documento": 2,
  "nro_documento": "20123456789",
  "telefono": "987654321",
  "estado": 1
}
```
*Nota: El backend mapea automáticamente `nombre` a `razon_social` y `nro_documento` a `numero_documento` para ser compatible con tu HTML.*

## 3. Editar Proveedor
**Método:** `PUT`
**URL:** `/api/proveedores/{id}`
**Body (JSON):** *(Mismo formato que Crear)*

## 4. Desactivar Proveedor (Eliminado Lógico)
**Método:** `DELETE`
**URL:** `/api/proveedores/{id}`


## 5. Exportar Excel de proveedores (fase 2: servidor)

**Método:** GET. **URL:** `/api/reportes/exportar-excel?tipo=proveedores&search=Costa&estado=activos`.

- Requiere sesión, `reportes.ver`, `reportes.exportar` y `proveedores.listar`, siguiendo el control existente de reportes.
- `search` y `estado` usan exactamente la normalización y consulta compartidas de la fase 1. Sin estado se utiliza `todos`; otros valores se rechazan.
- Exporta todas las coincidencias, ordenadas por ID descendente. Ignora `page` y `limit`: estos parámetros no limitan el reporte.
- `ProveedorService::obtenerReporteProveedores(array $entrada, int $id_usuario): array` devuelve `metadatos`, `filtros`, `proveedores` y `total_registros`. El usuario se consulta por el ID autenticado, sin aceptar un nombre enviado por el navegador. La emisión utiliza America/Lima.
- `ExcelService::generarProveedores(array $reporte): string` devuelve los bytes XLSX. El controlador responde con MIME `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, Content-Disposition attachment, Content-Length y `Cache-Control: private, no-store`. Nombre `Reporte_proveedores_AAAA-MM-DD_HHMMSS.xlsx`.
- Hoja Proveedores: negocio, título, búsqueda (o Sin búsqueda), estado seleccionado, usuario y fecha/hora; tabla continua con tipo de documento, número de documento, nombre/razón social, teléfono y estado; total final numérico. No divide por grupos de diez (esa regla pertenece a la impresión del PDF).
- Fondo de encabezado `1E293B` y letras `D4AF37`; texto ajustado, alturas calculadas, cinco columnas, datos inactivos en gris, títulos de columnas inmovilizados. Documento/teléfono son StringCell con formato `@`; todos los nombres/textos usan StringCell para que `=...` se conserve literalmente sin ejecutar fórmulas. La fecha de emisión es una celda de fecha y el conteo es numérico.
- Generación en carpeta privada aleatoria bajo el directorio temporal del sistema. Se elimina en finally antes de responder, también ante excepciones, con limpieza adicional de cierre de petición. No conserva copias permanentes ni historial.

Errores JSON, sin generar un XLSX vacío:

| HTTP | Caso | Mensaje |
| --- | --- | --- |
| 400 | Búsqueda/estado inválidos | Validación común del servicio |
| 400 | Usuario autenticado no encontrado | No se encontró el usuario que genera el reporte. |
| 401 / 403 | Sin sesión / permiso | Respuesta existente de autenticación/autorización |
| 422 | Lista vacía sin filtros | No existen proveedores para imprimir |
| 422 | Filtro sin coincidencias | No hay resultados que coincidan con la búsqueda para generar el reporte |
| 500 | Error inesperado de generación | No se pudo generar el reporte. Reduce la búsqueda con los filtros e intenta nuevamente. |

La fase 2 entregó el servidor; la integración del botón se implementó en fase 3 y se describe a continuación. La impresión/PDF de proveedores no se modificó.


## 6. Descarga Excel desde proveedores (fase 3)

- `#proveedores_exportar_excel` se inicializa únicamente en la pantalla con `#tabla_proveedores`. No añade manejadores a los botones de otros módulos.
- Usa una copia de `data-filtros-aplicados` de la tabla; no envía page/limit. Si el listado está cargando, falló o los campos aún no coinciden con la última consulta aplicada (incluido el debounce), pide esperar y no solicita un reporte con filtros anteriores.
- Muestra el diálogo nativo `#proveedores_generando_excel`, con “Generando reporte…” y Cancelar, reutilizando los estilos de diálogo existentes. Bloquea exportaciones, registro, búsqueda, estado y paginación mientras espera; al terminar restaura el estado disabled previo de cada control y conserva la consulta/página.
- Fetch binario directo, con credenciales same-origin y Accept XLSX. No cambia el cliente Api que espera JSON.
- Comprueba HTTP, Content-Type y redirecciones; no descarga errores JSON/HTML ni una página de sesión. Comprueba firma ZIP y marcadores `[Content_Types].xml` y `xl/workbook.xml` antes de crear la URL del Blob.
- Descarga mediante enlace temporal, sin abrir otra pestaña. Acepta el nombre `Reporte_proveedores_...xlsx` del servidor; si el nombre no corresponde al patrón seguro, utiliza `Reporte_proveedores.xlsx`. Elimina el enlace y revoca la URL al cabo de 60 segundos para permitir que el navegador inicie el guardado.
- Cancelar o Escape abortan la espera sin alerta. Espera máxima de 120 segundos; al agotarse recomienda acotar con los filtros e intentar de nuevo. Errores de red, sesión, permiso, resultados vacíos y contenido incorrecto cierran el diálogo, restauran controles y se muestran mediante Modal.error, que inserta texto sin interpretar HTML.
- No modifica los mensajes de vacío definidos en el apartado 5 ni guarda archivos permanentes. La cancelación interrumpe la espera/descarga del cliente; la generación del servidor conserva la limpieza temporal propia de fase 2.

No se ejecutó la fase 4 de verificación integral en la sesión real del usuario.
