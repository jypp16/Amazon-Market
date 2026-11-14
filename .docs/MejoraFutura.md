# Actualizacion

Sí: ese es el comportamiento que podríamos implementar, pero actualmente no tenemos esa actualización automática entre laptops.

Por ejemplo:

1. El administrador consulta las ventas de hoy del vendedor Juan.
2. Juan registra una venta desde otra laptop.
3. El sistema detecta el cambio y vuelve a consultar con los mismos filtros.
4. Si la venta cumple esos filtros, aparece en la tabla y se actualizan sus totales.
   Si la operación corresponde a otro vendedor o fecha, no se agrega a esa consulta. El backend debe aplicar los filtros y permisos, no basta con insertar en pantalla cualquier venta recibida.

Para manejarlo bien, recomiendo dos comportamientos:
Pantalla Comportamiento propuesto
Ventas, movimientos y consulta de caja activa Actualización automática conservando los filtros.
Reportes con paginación y exportación PDF/Excel Detectar cambios y mostrar “Hay nuevos datos disponibles — Actualizar”. Al actualizar, generar una nueva consulta con los mismos filtros.

La diferencia se debe a que nuestros reportes utilizan snapshots, es decir, una versión fija de la consulta. Si cambiamos filas y totales mientras el administrador pasa de página, podríamos mostrar registros repetidos o generar un Excel que no coincida con lo consultado.

Si quieres que el reporte también cambie automáticamente, podemos añadir una opción “Actualización automática”: cuando haya cambios, reemplazar toda la consulta por un nuevo snapshot y volver a la primera página. No añadiríamos solamente una fila al snapshot anterior.

Para la otra laptop, la comprobación ligera cada diez segundos permitiría detectar novedades; únicamente entonces se volvería a consultar el reporte. Ese plazo sería aproximado, sujeto a conexión y tiempo de respuesta.

Así contemplamos lo que planteas: misma consulta, mismos filtros y nuevas operaciones visibles, con una regla explícita para conservar la coherencia entre tabla, totales y exportaciones.
