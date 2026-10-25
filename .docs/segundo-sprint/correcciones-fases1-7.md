# Correcciones de las fases 1 a 7

Alcance autorizado: corregir los hallazgos de la auditoría en `amazon_market_sprint2_test`. No ejecutar la fase 10 ni modificar `.env` o la base habitual `amazon_market`. Las fases 8 y 9 siguen pendientes.

## Trabajo y evidencia

- [x] Corregir la prueba de integridad de detalles de compra que podía aprobar por una columna inexistente.
- [x] Corregir los saldos del Kardex cuando un filtro histórico oculta movimientos del producto.
- [x] Validar las fechas obligatorias y la relación categoría/subcategoría de los reportes.
- [x] Conservar la referencia de compras y ventas al reintentar una solicitud; exigirla en la API.
- [x] Conservar los filtros aplicados del Kardex al paginar mientras se editan otros filtros.
- [x] Habilitar y verificar un servidor local separado contra la base de pruebas.
- [x] Ejecutar regresiones, revisar el conjunto de cambios y comprobar que la base habitual conserve su contenido.

## Decisiones de implementación

- Se trabaja en la rama existente `feature/inventario`, inicialmente limpia. La separación de datos se hace mediante la conexión de pruebas y un servidor local exclusivo; no se cambia el destino del Apache habitual.
- El resumen del Kardex corresponde al período completo del producto seleccionado. La tabla conserva los filtros históricos de categoría, subcategoría y búsqueda; estos no pueden convertir el saldo real en un saldo ficticio. El resumen indicará su alcance y la cantidad de filas filtradas por separado.
- Un filtro histórico no exige que el producto siga perteneciendo hoy a la categoría consultada. Sí se comprueba que los identificadores existan y que la subcategoría corresponda a la categoría elegida.
- La referencia de una operación se conserva frente a un fallo de transporte. Una respuesta de validación permite corregir y volver a enviar. Las API de compras y ventas rechazarán una escritura sin referencia.
- No se necesitan cambios de estructura SQL para estas correcciones. Cualquier cambio posterior de base de datos deberá quedar en `database/segundo_sprint/`.
- Cada consulta nueva lee movimientos, resumen y datos del producto en una transacción `REPEATABLE READ`, y guarda su referencia dentro de esa misma transacción. Una transacción externa conserva la responsabilidad de commit/rollback del llamador.
- Las referencias pendientes se conservan por ámbito y huella de datos. Volver de A a B y a A recupera la referencia original de A. Solo una confirmación explícita libera la intención correspondiente. Se guarda la huella y referencia en `sessionStorage`, sin datos del formulario. Si el navegador bloquea ese almacenamiento se conserva la protección en memoria mientras la página siga abierta.
- Las suites son exclusivamente CLI. El servidor aislado bloquea archivos internos y no contiene una ruta pública de acceso automático como administrador. Las pruebas HTTP usan sesiones temporales y pasan por la autenticación y el CSRF reales.
- La revisión adicional detectó referencias sobrescritas al volver de A a B y a A, y una limpieza demasiado amplia en la suite HTTP. Ambos se corrigieron. La limpieza usa referencias exactas y restaura solamente la serie utilizada, comparando el correlativo para no retroceder avances concurrentes.

## Resultados

Ejecución local del 25/10/2026:

| Verificación | Resultado |
| --- | --- |
| Suites existentes fases 2, 3, 4, 5, 6 y 7 | 24 + 13 + 14 + 14 + 18 + 22 = **105 PASS** |
| Regresiones de saldos y filtros (`regresion_reportes_test.php`) | **11 PASS**; antes de corregir, 8 fallaban |
| Referencias obligatorias (`regresion_referencias_test.php`) | **12 PASS** |
| Operaciones reales por HTTP (`regresion_http_test.php`) | **22 PASS**, incluyendo reintentos de creación, corrección y anulación de compras y ventas |
| Scripts JS reales con DOM y transporte controlados (`regresion_interfaz_test.cjs`) | **2 escenarios PASS**, incluyendo respuesta perdida, A→B→A y filtros aplicados al paginar |
| Esquema y todas las filas de las 20 tablas de `amazon_market` | Huellas SHA-256 anteriores y posteriores idénticas |
| Estado final de pruebas | 107 productos conservados, stock 0 y tablas de operaciones vacías |

Son pruebas automatizadas de integración, HTTP y comportamiento JS. No equivalen a una revisión visual manual de todas las pantallas. PDF y Excel nuevos no se implementaron ni se certificaron en este trabajo.

## Usar el entorno de pruebas

Desde la raíz del proyecto:

```powershell
powershell -NoProfile -File tests/sprint2/servidor_pruebas.ps1
```

Abrir `http://127.0.0.1:8092/` e iniciar sesión con una cuenta de la copia de pruebas. Productos: `http://127.0.0.1:8092/Producto`. El servidor usa `amazon_market_sprint2_test`, una carpeta de sesiones temporal separada y variables limitadas a ese proceso. Se detiene con Ctrl+C si se inició en una consola.

La dirección habitual `http://localhost/Amazon-Market` conserva su conexión anterior. El error de Productos allí procede de que todavía faltan las tablas/columnas del Sprint 2 en `amazon_market`, especialmente `subcategoria`. Este trabajo no adelanta su migración: corresponde a la fase 10. No aplicar scripts parciales sobre esa base para hacer desaparecer el mensaje.

Las suites deben ejecutarse una por una y sin operaciones manuales concurrentes en la copia de pruebas. Las suites originales preparan y limpian sus escenarios; no utilizarlas contra una base con operaciones que se desee conservar.

Comandos de regresión, después de iniciar el servidor:

```powershell
C:/xampp/php/php.exe tests/sprint2/regresion_reportes_test.php
C:/xampp/php/php.exe tests/sprint2/regresion_referencias_test.php
C:/xampp/php/php.exe tests/sprint2/regresion_http_test.php
node tests/sprint2/regresion_interfaz_test.cjs
```

Para un despliegue posterior: `crypto.subtle` y `crypto.randomUUID` necesitan un contexto seguro. El servidor local en `127.0.0.1` y el acceso por `localhost` lo cumplen; si se publica mediante una IP de red se debe configurar HTTPS. Esta revisión no certifica un despliegue por HTTP en LAN.
