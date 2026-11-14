# Caja del administrador sin horario

Especificación aprobada el 14/11/2026: administrador activo puede operar su propia sesión sin turno ni salida programada; vendedor conserva su turno y autorización anterior a salida menos 15 minutos. Una sola caja física, arqueo vigente obligatorio, movimientos/correcciones propios, sin asumir cierre ajeno. Administrador mantiene Inicio y consultas históricas, con acceso separado a su sesión. Ventas de ambos roles requieren caja propia. Reportes incluyen responsables de ambos roles y modalidad sin horario. No inventar turno ni borrar datos.

Implementación inline con habilidades writing-plans, executing-plans y test-driven-development. Sin commits ni cambios de .env. Pruebas con escrituras solamente en copias aisladas. SQL incremental en database/tercerSprint.

- [x] Capturar estructura real, respaldo externo/restauración y prueba HTTP que reproduzca el rechazo administrativo.
- [x] Migración 12: modalidad inmutable CON_TURNO/SIN_HORARIO, horario nullable condicionado, adaptar triggers conservando controles de integridad, permisos explícitos.
- [x] API/servicios/modelos: operador rol 1/2, apertura según rol, proyecciones null, cierre administrativo sin autorización, propiedad y arqueo vigentes.
- [x] Interfaz: menú/POS propios, apertura sin selector para administrador, consulta propia separada del histórico, filtros/reportes responsables y Sin horario.
- [x] Probar ciclo administrativo, propiedad, vendedor, reportes/exportaciones, reintentos y exclusión; revisión independiente final.
- [x] Aplicar SQL probado sobre XAMPP con mantenimiento y respaldo, comparar filas anteriores, activar y documentar.

Decisión: conservar nombres físicos históricos id_usuario_vendedor y aliases seller para compatibilidad; su significado se amplía a responsable. Añadir modalidad persistida para no deducir historia de cambios futuros del rol. No alterar sesiones previas.

Revisión independiente: corregidos drenaje que contaba PDO propio, permisos efectivos en ruta legacy y consulta propia, y menú sin bypass administrativo. Pruebas HTTP, reloj, concurrencia y navegador ejecutadas. El navegador integrado no inició por error de sandbox y playwright-cli no estaba disponible; se verificó con Edge headless mediante CDP, sin instalar dependencias. Sin commits.
