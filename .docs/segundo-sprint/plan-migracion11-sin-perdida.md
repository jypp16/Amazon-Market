# Migración 11: conservación de datos anteriores

Solicitud autorizada: entregar a los compañeros una actualización del Sprint 2 que conserve sus datos de prueba. La base habitual de este equipo ya pasó por Fase 10 y no debe volver a migrarse ni restaurarse.

## Diseño

- Un SQL autónomo en `database/segundo_sprint/11_migracion_sprint2_sin_perdida_de_datos.sql`, sin llamar a 00 ni 10 ni requerir rutas SOURCE internas.
- Ventas, detalles, compras, historial, catálogo, existencias y correlativos anteriores permanecen activos con los mismos valores e IDs. Se añaden las estructuras y permisos de 01/02.
- El Kardex anterior se conserva íntegro como `kardex_legado_sprint2`. No contiene todas las ventas históricas: reconstruirlo inventaría información. El Kardex canónico nuevo registra un `SALDO_INICIAL_MIGRACION` por producto con stock positivo, exactamente igual al stock existente y sin modificarlo. Fecha y clasificación corresponden a la migración. Cero existencias no genera movimientos de cantidad cero.
- Los reportes comerciales conservan las operaciones antiguas. El Kardex canónico comienza en la fecha de corte; Variaciones incluye únicamente nuevas bajas y ajustes. El Kardex legado queda disponible en SQL, no se agrega una pantalla nueva.
- Las cantidades fraccionarias históricas de ventas se conservan. Se impide anularlas mediante truncamiento a entero. Las existencias/minimos fraccionarios, negativos o fuera de INT, precios inválidos y referencias incompatibles detienen el procedimiento antes del DDL; no se corrigen automáticamente.
- Se exige base confirmada, aplicación detenida y respaldo externo identificado por ruta/hash. Los datos de respaldo los verifica el operador fuera del SQL. No se admiten bases ya migradas, repeticiones ni instalaciones parcialmente ejecutadas sin revisión.
- DDL tiene commits implícitos en MariaDB. Se usa el cliente mysql con `--abort-source-on-error`, sin `--force`. En fallo intermedio se detiene el despliegue y se conserva el respaldo; no se promete rollback DDL.

## Alternativas consideradas

El arranque limpio de 10 retira operaciones activas y no satisface esta solicitud. Volver a calcular todo el historial a partir de ventas fraccionarias y Kardex incompleto tampoco lo satisface. Se elige conservación comercial más saldo de migración explícito, sin simular compras ni bajas.

## Ejecución y verificación

1. Escribir y ejecutar primero una prueba de integración aislada restaurando el respaldo anterior, con comparación de todas las columnas originales, stock, IDs y correlativos.
2. Implementar el SQL autónomo con prevalidación, tabla de control, conservación del Kardex, estructuras de 01/02, saldo identificado y comprobaciones finales.
3. Corregir la anulación de ventas fraccionarias antiguas sin alterar las anulaciones de cantidades enteras.
4. Comprobar conservación, bloqueos antes del DDL, repetición, inmutabilidad, nuevas compras/ventas/bajas, reportes y cuatro exportaciones. Las bases temporales tendrán prefijo exclusivo y se retirarán al terminar.
5. Documentar qué archivo ejecutar, variables, respaldo propio, compatibilidad MariaDB y límites del historial. Revisar diff y sintaxis. No aplicar 11 en amazon_market ni reemplazar la base de pruebas habitual.

## Resultado verificado (25/10/2026)

- SQL 11 autónomo y guía `database/segundo_sprint/LEEME_11_conservar_datos.md` implementados. Scripts originales 00/01/02/10 sin cambios.
- Prueba de integración: **35 PASS / 0 FAIL** en MariaDB 10.4.32, restaurando el respaldo anterior exclusivamente en bases temporales de nombre aleatorio. Ese escenario contiene 107 productos, 98 ventas, 377 detalles de venta, 4 compras, 4 detalles de compra, 3 filas de historial de compra y 26 movimientos anteriores.
- Se compararon todas las columnas originales de las tablas anteriores salvo permisos, cuya configuración cambia intencionalmente; las existencias y mínimos se compararon por su valor numérico al pasar de DECIMAL a INT. Se conservaron datos y correlativos.
- Verificados bloqueos antes de DDL, repetición, inmutabilidad, saldo inicial, nuevas ventas/compras/bajas, anulación entera, rechazo de anulación fraccionaria y corrección versionada de compra anterior. La revisión estática adicional detectó estados de categoría incompatibles; se añadió guarda y se comprobó su rechazo antes de DDL.
- Verificados conciliación de Kardex, exclusión de saldos iniciales en Variaciones y generación de cuatro archivos PDF/XLSX con comprobaciones de formato. No se realizó revisión visual de documentos ni descarga autenticada desde navegador en esta entrega.
- Huella de datos de negocio de `amazon_market` idéntica antes y después de las pruebas. No se aplicó 11 a esa base ni se reemplazó `amazon_market_sprint2_test`.
- Sintaxis PHP comprobada. La compatibilidad de cambios propios de cada compañero todavía requiere ensayo en una copia de su propia base. La migración no reconstruye movimientos históricos faltantes.
