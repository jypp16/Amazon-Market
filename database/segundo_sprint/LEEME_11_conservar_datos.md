# Actualizar al Sprint 2 conservando los datos propios

## Qué compartir y ejecutar

Comparte el proyecto actualizado, este documento y **`11_migracion_sprint2_sin_perdida_de_datos.sql`**. El SQL es autónomo: incluye las estructuras y permisos necesarios. Para esta alternativa **ejecuta solamente el 11; no ejecutes 00, 01, 02 ni 10**, ni antes ni después.

Esta alternativa está preparada para el esquema anterior al Sprint 2 y se probó en MariaDB 10.4.32 de XAMPP. No es un instalador para bases vacías ni una actualización de bases que ya pasaron por Fase 10. Rechaza bases ya migradas o con señales de una instalación parcial. Si un compañero tiene cambios propios de estructura, hay que revisar su compatibilidad primero.

El código actualizado también es necesario: incluye en `Services/VentaService.php` la protección que impide truncar cantidades al anular ventas antiguas fraccionarias. Copiar solamente el SQL no actualiza los controladores, servicios, vistas ni JavaScript.

## Qué conserva y qué cambia

- Productos, IDs, códigos, precios, existencias, mínimos, ventas, compras, sus detalles, historial de compras y correlativos se conservan. No se ponen existencias en cero ni se eliminan operaciones comerciales.
- Se agregan subcategorías (inicialmente General según la categoría), versionado de detalles de compra, operaciones de inventario, idempotencia, consultas de reportes y restricciones de integridad.
- Se configuran los permisos del Sprint 2 exclusivamente para Administrador; las asignaciones de permisos sí cambian.
- El Kardex anterior se conserva completo como **`kardex_legado_sprint2`**, protegido contra inserciones, cambios y borrados. Se retiran sus claves foráneas hacia las tablas activas para separar el historial conservado. Está disponible mediante SQL; esta entrega no añade una pantalla para ese legado.
- El nuevo Kardex comienza en la fecha de migración. Cada producto con stock positivo recibe una entrada explícita **Saldo inicial de migración**, igual a su stock existente. No simula una compra ni vuelve a sumar stock. Los productos con stock cero no reciben movimientos de cantidad cero.
- Las ventas y compras antiguas siguen en los reportes comerciales. El nuevo Kardex no reconstruye sus movimientos anteriores: ese historial era incompleto. Sus entradas incluyen el saldo de migración cuando la fecha seleccionada lo abarca; Variaciones excluye ese saldo e incluye las bajas y ajustes posteriores.
- Las cantidades fraccionarias de ventas antiguas se conservan sin redondear. El servicio de anulación las bloquea con `LEGACY_FRACTIONAL_QUANTITY` (excepción con estado 409), porque el inventario nuevo admite unidades enteras. Una venta antigua con cantidades enteras puede procesarse por ese servicio si cumple las demás validaciones. Esta entrega no agrega una pantalla ni un endpoint de anulación.

Si hay existencias o mínimos fraccionarios, negativos, fuera del rango admitido, precios no positivos o ciertas referencias incompatibles, el script se detiene antes de modificar la estructura y muestra el problema. No prepara ni corrige esos datos automáticamente.

## Preparación obligatoria

1. Confirmar el nombre de **la base propia del compañero** y la conexión de su aplicación. No importar la base de otra persona sobre ella.
2. Detener Apache y cualquier otro proceso que escriba en esa base. Mantener MySQL/MariaDB encendido. La variable de mantenimiento es una declaración del operador: el SQL no detiene aplicaciones ni bloquea todos los escritores.
3. Crear un respaldo completo propio fuera de `htdocs`. Verificar que terminó correctamente, calcular su SHA-256 y comprobar que puede restaurarse **en una base nueva y separada**. No restaurarlo sobre la base original. Conservarlo tras migrar.
4. Ensayar primero el SQL 11 sobre esa copia restaurada, con el nombre de la copia confirmado. Comprobar conteos, stock y los flujos usados por el equipo. Después aplicar exactamente el mismo SQL a la base propia, con respaldo reciente y la aplicación detenida.

El SQL valida que se le proporcione ruta y un hash con formato válido; **no puede comprobar que el archivo exista ni que sea restaurable**. Esa comprobación corresponde al operador. Los respaldos pueden contener credenciales y datos personales: no subirlos al repositorio ni dejarlos en el directorio público.

## Ejemplo en PowerShell y XAMPP

Ejecutar desde la carpeta del proyecto. Adaptar el nombre de la base y las rutas. Este ejemplo supone el usuario local `root` de XAMPP sin contraseña; si tiene contraseña, añadir `-p` a cada llamada para introducirla de forma interactiva. No escribir contraseñas dentro del SQL.

Primero crear el respaldo; Apache ya debe estar detenido:

```powershell
$baseSprint2 = 'nombre_de_la_base_del_companero'
if ($baseSprint2 -notmatch '^[A-Za-z0-9_]+$') { throw 'Nombre de base inválido' }
$carpetaRespaldo = 'C:\xampp\backups\sprint2_conservacion'
New-Item -ItemType Directory -Force -Path $carpetaRespaldo | Out-Null
$archivoRespaldo = Join-Path $carpetaRespaldo ($baseSprint2 + '_' + (Get-Date -Format 'yyyyMMdd_HHmmss') + '.sql')
if (Test-Path -LiteralPath $archivoRespaldo) { throw 'El respaldo ya existe; no sobrescribirlo' }
& 'C:\xampp\mysql\bin\mysqldump.exe' -u root --single-transaction --routines --triggers --events --hex-blob --default-character-set=utf8mb4 "--result-file=$archivoRespaldo" $baseSprint2
if ($LASTEXITCODE -ne 0) { throw 'Falló el respaldo; no migrar' }
if ((Get-Item -LiteralPath $archivoRespaldo).Length -eq 0) { throw 'Respaldo vacío; no migrar' }
$hashRespaldo = (Get-FileHash -LiteralPath $archivoRespaldo -Algorithm SHA256).Hash
Write-Output "Respaldo: $archivoRespaldo"
Write-Output "SHA-256: $hashRespaldo"
```

**Antes del bloque siguiente, verificar la restauración y realizar el ensayo en una base separada.** Para el ensayo, cambiar `$baseSprint2` al nombre de esa copia. Mantener `$archivoRespaldo` y `$hashRespaldo` apuntando al respaldo original propio. Al aplicar a la base usada por la aplicación, volver a confirmar su nombre y usar el respaldo reciente correspondiente.

```powershell
if ($baseSprint2 -notmatch '^[A-Za-z0-9_]+$') { throw 'Nombre de base inválido' }
if ((Get-FileHash -LiteralPath $archivoRespaldo -Algorithm SHA256).Hash -ne $hashRespaldo) { throw 'Cambió el respaldo; detenerse' }
$rutaSql = (Resolve-Path -LiteralPath '.\database\segundo_sprint\11_migracion_sprint2_sin_perdida_de_datos.sql').Path.Replace('\', '/')
$rutaRespaldoSql = $archivoRespaldo.Replace('\', '/').Replace("'", "''")
$inicioSesion = "SET @SPRINT2_11_BASE_CONFIRMADA='$baseSprint2', @SPRINT2_11_RESPALDO='$rutaRespaldoSql', @SPRINT2_11_SHA256='$hashRespaldo', @SPRINT2_11_MANTENIMIENTO='APLICACION_DETENIDA'"
& 'C:\xampp\mysql\bin\mysql.exe' -u root --default-character-set=utf8mb4 --abort-source-on-error "--init-command=$inicioSesion" "--database=$baseSprint2" -e "SOURCE $rutaSql"
if ($LASTEXITCODE -ne 0) { throw 'Migración detenida: conservar respaldo, mantener mantenimiento y revisar el mensaje; no repetir automáticamente' }
```

El administrador responsable se elige entre los usuarios activos del rol 1. Para elegir uno concreto, añadir `, @SPRINT2_11_ID_ADMIN=SU_ID` a `$inicioSesion`, sustituyendo `SU_ID` por su ID numérico real. No enviar este marcador literalmente.

Usar el cliente `mysql` con **`--abort-source-on-error` y sin `--force`**. Las variables deben declararse en la misma conexión que ejecuta el SQL. La guía no recomienda importar este archivo mediante phpMyAdmin porque se necesita garantizar la detención al primer error.

## Verificación y errores

Al terminar debe aparecer `COMPLETADA` en `sprint2_migracion11_control`, con la fecha de corte y los conteos originales. Revisar, antes de reabrir Apache:

```sql
SELECT estado, fecha_corte, conteos_originales FROM sprint2_migracion11_control;
SELECT COUNT(*) AS productos, SUM(stock_actual) AS stock_total FROM producto;
SELECT COUNT(*) AS ventas FROM venta;
SELECT COUNT(*) AS compras FROM compra;
SELECT COUNT(*) AS movimientos_anteriores FROM kardex_legado_sprint2;
SELECT COUNT(*) AS saldos_iniciales, SUM(cantidad) AS stock_inicial
FROM kardex WHERE origen_operacion='SALDO_INICIAL_MIGRACION';
```

Comparar con la copia anterior. El SQL comprueba conteos y existencias al finalizar; la prueba automatizada del proyecto compara además las columnas originales. Con el código actualizado y la conexión correcta, reabrir la aplicación y revisar productos, compras, ventas, Kardex, Variaciones y sus exportaciones. Una sesión antigua puede requerir cerrar sesión y volver a entrar para refrescar permisos.

Si falla antes de crear `sprint2_migracion11_control`, sus prevalidaciones no han cambiado datos ni estructura. Si falla después, **MariaDB puede haber confirmado parte del DDL**: el script no promete revertir esos cambios. Mantener la aplicación detenida, conservar el error y revisar el estado. No volver a ejecutarlo a ciegas, no borrar la marca de control y no intentar resolverlo con 00 o 10. Para volver al estado anterior, restaurar el respaldo validado en una base nueva y vacía y revisar la conexión antes de reabrir; importar sobre una base parcialmente migrada puede dejar tablas y triggers nuevos sobrantes.

## Evidencia de prueba de esta entrega

`tests/sprint2/migracion11_conservacion_test.php` restaura el respaldo anterior en bases temporales de nombre aleatorio y prueba conservación, guardas, inmutabilidad, ventas, compras, corrección de compra antigua, bajas, conciliación, filtros y generación PDF/Excel. No usa ni reemplaza la base habitual de pruebas. La validación de archivos comprueba su formato; no equivale a una revisión visual ni a una descarga autenticada desde el navegador de cada compañero.
