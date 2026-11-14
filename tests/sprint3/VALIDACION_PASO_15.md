# Validación del Paso 15 — Sprint 3

Fecha: 13/11/2026, America/Lima. Destino confirmado por el usuario: XAMPP local, amazon_market. Resultado: ACTIVATED, sitio disponible en http://localhost/Amazon-Market. Sin commits y sin modificar .env.

## Evidencia ejecutada

| Comprobación | Resultado |
|---|---|
| Respaldo previo y restauración en base nueva | VERIFIED, 32 tablas y 5 triggers originales |
| Migración de ensayo con colación original general_ci | VERIFIED, 43 tablas y 34 triggers |
| Fallo deliberado después de fase 02 | EXPECTED_FAILURE_VERIFIED; filas originales conservadas |
| Recuperación a otra base desde respaldo | VERIFIED; originales conservados |
| Paso 6 + HU18 | 116/116, salida 0; repetido después de activar |
| Paso 9 ventas/caja | 107/107, salida 0 |
| Paso 11 cierre y concurrencia | 159/159, salida 0; concurrencia 52/52 |
| Paso 12 consultas/reportes | 190/190, salida 0; repetido en clon recuperado |
| Paso 14 exportaciones HTTP PDF/Excel | 88/88, salida 0; repetido en clon recuperado |
| Fuente en mantenimiento después de migrar | 24 comprobaciones, estado ready |
| Fuente justo después de activar | 32 comprobaciones HTTP/esquema/datos |
| Fuente con actividad posterior | 24 comprobaciones, salida 0; conserva nuevas altas legítimas |
| Checkpoint posterior restaurado | 43 tablas y 34 triggers preservados |

Las suites con escrituras utilizaron exclusivamente clones; el smoke de Apache usa GET sin sesión y consultas SQL de lectura. Los logs de las ejecuciones están en evidencia_paso15/ y el resumen en RESULTADOS_PASO_15.json. La cuenta 159 del Paso 11 corresponde a las ramas ejecutadas, todas satisfactorias; no se presenta el número de otra ejecución como resultado propio. No se afirma haber repetido individualmente todas las suites antiguas: la selección de este paso cubre programación, ventas, cierre concurrente, consultas y exportación sobre el paquete final. Las regresiones completas anteriores pertenecen a sus respectivos informes.

## Conservación y respaldos

Comparación de multiconjuntos SHA-256 por fila en las columnas originales, dentro de snapshot consistente; conserva las filas de las 32 tablas y los cinco triggers previos. Se añadieron las tablas y permisos del Sprint 3. Conteos comerciales: venta 250, compra 80, producto 108, stock 2497, kardex 470. Las 250 ventas anteriores conservan id_sesion_caja NULL.

Carpeta externa: C:/xampp/backups/Amazon-Market/Sprint3/Paso15.

- Manifest previo: amazon_market_pre_sprint3_paso15_20261114_030958_7ceb4052.manifest.json.
- SHA-256 del dump previo: 3604a8b2745fcdc34cfbeb45ef5ab0e0a6c8e4134c4ce8eb254dcf81a5333820.
- Informe de aplicación: amazon_market.apply.json, VERIFIED. Informe de activación: amazon_market.activate.json, ACTIVATED.
- Dump posterior: amazon_market_post_sprint3_20261113_214635_ab76863a.sql.
- SHA-256 posterior: 4ba166e8134f9c63e50e93ffa0d5ace441069cad6c5e6d2adb352738ff992968.
- Restauración posterior: am_s3_checkpoint_20261113_214635_ab76863a; informe adyacente .sql.json con POST_DEPLOY_RESTORE_VERIFIED.

El nombre del respaldo previo se generó antes de normalizar la zona del helper; el instante se conserva en created_epoch del manifest. No confundir el día del nombre UTC con pérdida de un respaldo.

## Incidencia real y corrección verificada

La primera aplicación se detuvo por ERROR 1267: la base tenía utf8mb4_general_ci y una variable de procedimiento se comparaba con el control unicode_ci. Solo se había creado sprint3_migracion_control, en 00_INICIANDO: 33 tablas y ninguna operación comercial alterada. El sitio permaneció en 503.

Se reprodujo en un clon con la colación real. Se respaldó el parcial y archivó exclusivamente esa tabla nueva, sin DROP ni eliminación de filas, en am_s3_preflight_archive_20261113_213826_b4b08fa1. Informe: amazon_market.preflight_recovery_20261113_213826_b4b08fa1.json. El fallo inicial se conserva en fallo_preflight_20261113_212557/.

Se añadió el contexto SQL11, que solo cambia el predeterminado de la base y se ejecuta antes del paquete SQL10; no convierte las tablas existentes. El paquete versión 3 se ensayó nuevamente con respaldo, fallo controlado y restauración. Después se aplicó correctamente, se validaron las filas y se reactivó Apache. Durante los ensayos también se corrigieron un nombre de archivo de base demasiado largo, el uso no soportado de PREPARE ALTER DATABASE en MariaDB 10.4 y la captura separada de stderr en Windows. Los fallos históricos se conservan; no se contabilizan como pruebas satisfactorias.

## Actividad posterior y límites

Después de activar se observaron nuevas solicitudes TURNO_CREAR, TURNO_BAJA y PROGRAMACION_HORARIO, y snapshots de cierres; se conservaron. Por ello la comprobación desplegada permite crecimiento en las dos tablas técnicas originales de idempotencia y snapshots, pero continúa verificando todas las filas previas. La fase ready sí verificó ausencia de fixtures antes de permitir uso real. No se borran esas altas para recuperar un conteo anterior.

El checkpoint posterior refleja su instante, no operaciones futuras. La restauración ensayada es en otra base, no un reemplazo destructivo de la fuente. El rendimiento observado en pruebas locales no equivale a una prueba de carga de producción. No se realizó una nueva prueba visual manual completa en navegador en este paso; la integración funcional se verificó por HTTP y persistencia, y los diseños se revisaron en el Paso 14.

Procedimiento de uso y recuperación: [DESPLIEGUE_LOCAL.md](../../database/tercerSprint/DESPLIEGUE_LOCAL.md).
