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

## 2. Historial de Compras (Fase 3)

Lista todas las compras de forma paginada para la vista de historial.

- **URL:** `/api/compras`
- **Método:** `GET`
- **Parámetros Query (Opcionales):** `page`, `per_page`, `search`

**Éxito (200 OK):**
```json
{
  "status": true,
  "data": [
    {
      "id_compra": 1,
      "fecha_recepcion": "2026-10-09",
      "proveedor": "Distribuidora XYZ",
      "tipo_comprobante": "Factura",
      "serie_numero": "F001-00001234",
      "total_documento": 1500.50,
      "estado_compra": "Registrado"
    }
  ],
  "pagination": {
    "total": 1,
    "page": 1,
    "per_page": 10,
    "total_pages": 1
  }
}
```

## 3. Anular Compra (RC02 - Fase 3)

Anula una compra registrada. Esta acción resta el stock sumado originalmente e invalida el movimiento. El sistema validará que al anular no queden productos con stock negativo.

- **URL:** `/api/compras/{id}`
- **Método:** `DELETE`

**Éxito (200 OK):**
```json
{
  "status": true,
  "message": "Compra anulada exitosamente."
}
```

**Error por stock negativo (400 Bad Request):**
```json
{
  "status": false,
  "message": "No se puede revertir el detalle porque el producto XYZ quedaría con stock negativo."
}
```

## 4. Corregir / Editar Compra (RC01 - Fase 3)

Edita una compra existente (cambiar detalles, cantidades, etc.). Revertirá internamente los ingresos previos y registrará los nuevos movimientos. Valida estrictamente para evitar stocks negativos.

- **URL:** `/api/compras/{id}`
- **Método:** `PUT`
- **Content-Type:** `application/json`

### Body (JSON)
*La estructura es la misma que al Crear Compra.*

**Éxito (200 OK):**
```json
{
  "status": true,
  "message": "Compra corregida exitosamente."
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
