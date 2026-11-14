# Despliegue local del Sprint 3

Estado al 13/11/2026: ACTIVADO en http://localhost/Amazon-Market, base amazon_market. Este procedimiento es para XAMPP local; no despliega servidores remotos. No volver a aplicar la migración sobre esta base ya completada.

## Paquete y orden

Los scripts 01..09 y sus ejecutores históricos conservan sus protecciones para ensayos. El paquete autorizado está fijado por SHA-256 en DESPLIEGUE_LOCAL_PAQUETE.json (versión 3).

El ejecutor local ejecuta **11_contexto_despliegue_local_sprint3.sql ANTES de 10_despliegue_local_sprint3.sql**, en una misma conexión. El 11 normaliza exclusivamente el valor predeterminado de la base a utf8mb4_unicode_ci; no convierte tablas existentes. El 10 incorpora 01..09 con su autorización de destino. No ejecutar estos SQL manualmente ni cambiar sus hashes para sortear validaciones.

## Procedimiento reproducible de preparación

1. Verificar destino, código y .env; conservar las credenciales actuales. Los comandos se ejecutan desde C:/xampp/htdocs/Amazon-Market.
2. Para una base original todavía sin Sprint 3, preparar un respaldo externo con PHP: tools/preparar_respaldo_despliegue_sprint3.php --confirm-source=amazon_market --backup-dir=C:/xampp/backups/Amazon-Market/Sprint3/Paso15. Este preparador exige las 32 tablas originales.
3. Usar tools/ejecutar_despliegue_local_sprint3.php con --manifest=RUTA_MANIFEST y los modos rehearse, simulate-failure y restore, cada uno con --target=BASE_NUEVA. Los nombres deben comenzar con amazon_market_sprint3_deploy_. Nunca reutiliza ni elimina bases existentes. Para las suites HTTP de ensayo usar amazon_market_sprint3_deploy_rehearsal_ seguido de dígitos.
4. Los tres informes deben corresponder al mismo respaldo y paquete. Ejecutar las regresiones contra copias aisladas. Un fallo controlado esperado no sustituye una regresión funcional.
5. Modos maintenance y apply: añadir --target=amazon_market --confirm-target=amazon_market, --rehearsal-report=RUTA, --failure-report=RUTA y --recovery-report=RUTA. El respaldo debe tener menos de dos horas. maintenance devuelve 503 antes de abrir sesiones/conexiones. apply exige cinco segundos continuos sin conexiones previas a la base; no mata sesiones.
6. Ejecutar tests/sprint3/paso15_validacion_local.php --phase=ready --manifest=RUTA_MANIFEST antes de activar. Esta fase comprueba el estado inicial sin datos operativos de ensayo.
7. Ejecutar el modo activate con --target=amazon_market --confirm-target=amazon_market --manifest=RUTA_MANIFEST, solo después de apply VERIFIED. Verificar luego --phase=deployed. Esa comprobación está ligada al conjunto histórico de este despliegue; no es un monitor permanente después de nuevas ventas.
8. Guardar checkpoint posterior mediante tools/crear_checkpoint_despliegue_sprint3.php --confirm-source=amazon_market --backup-dir=C:/xampp/backups/Amazon-Market/Sprint3/Paso15. Crea y restaura otra copia, sin reemplazar la fuente.

Todas las rutas tools/ anteriores son relativas a database/tercerSprint/. Ejecución PHP: C:/xampp/php/php.exe.

## Recuperación

DDL puede confirmar cambios implícitamente: un error no garantiza rollback de estructura. El ejecutor deja mantenimiento activo si apply falla. Conservar dump, manifest, logs e informe parcial; no reejecutar ni eliminar tablas a ciegas.

La recuperación general ensayada restaura el respaldo en una **base nueva**, compara filas/triggers y conserva tanto la base parcial como la restaurada. No hace un reemplazo automático de amazon_market. Antes de un cambio de destino deben revisarse también las operaciones posteriores al respaldo; no se deben perder.

Existe una recuperación específica tools/recuperar_preflight_despliegue_sprint3.php: solamente admite el fallo inicial con 33 tablas, las 32 originales intactas y el único control en 00_INICIANDO. Exige manifest, --target y --confirm-target; respalda el parcial y mueve el control nuevo a una base de archivo mediante RENAME TABLE. Rechaza estados más avanzados; no es un rollback general.

## Operación y roles

Administrador: configurar turnos y programación, consultar historial, reportes y exportaciones según sus permisos. Vendedor: apertura propia, movimientos, correcciones vinculadas, arqueo y cierre; requiere su permiso respectivo. Credenciales administrativas solo para cierre antes de salida menos 15 minutos; desde el umbral y después de salir no se exigen. No se crea ticket de cierre. Una caja física, múltiples turnos por día con cierre y reapertura entre ellos; suplencias usan el horario de referencia seleccionado. Primera apertura sin cierre previo conserva diferencia no aplicable. No se cargaron usuarios, dinero ni operaciones ficticias en la fuente.

El despliegue conserva .env y las cuentas existentes. Backups y logs sensibles permanecen fuera del directorio público. Apache devuelve 403 para rutas internas, .env, dumps y configuración. El mantenimiento no se desactiva borrando archivos sin revisar el informe.
