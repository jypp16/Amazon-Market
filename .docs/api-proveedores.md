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

