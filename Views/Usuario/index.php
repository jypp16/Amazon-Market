<?php require_once "Views/Header.php"; ?>
<?php $puedeProgramarHorarios = can('usuarios.editar') && can('usuarios.crear'); ?>
<link rel="stylesheet" href="<?= BASE_URL ?>/Assets/css/usuarios-horarios.css?v=<?= filemtime(__DIR__ . '/../../Assets/css/usuarios-horarios.css') ?>">

<div class="table-container-header">
    <h3>Directorio de Usuarios</h3>
    <div class="toolbar-actions">
        <div class="search-wrapper">
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <input type="text" id="busqueda_usuario" placeholder="Buscar usuario..." class="search-input">
        </div>
        <?php if ($puedeProgramarHorarios): ?>
        <button type="button" id="btn_turnos_atencion" class="btn btn-secondary">
            <i class="fa-regular fa-clock" aria-hidden="true"></i> Turnos
        </button>
        <?php endif; ?>
        <?php if (can('usuarios.crear')): ?>
        <button type="button" id="btn_crear_usuario" class="btn btn-gold"><i class="fa-solid fa-user-plus"></i> Registrar Usuario</button>
        <?php endif; ?>
    </div>
</div>

<div class="table-responsive users-schedule-table">
    <table class="table">
        <thead>
            <tr>
                <th scope="col">Rol</th>
                <th scope="col">Username</th>
                <th scope="col">Nombre</th>
                <th scope="col">DNI</th>
                <th scope="col">Teléfono</th>
                <th scope="col">Email</th>
                <th scope="col">Dirección</th>
                <th scope="col">Horario de atención</th>
                <th scope="col">Acciones</th>
            </tr>
        </thead>
        <tbody id="tabla_usuarios">
            <tr>
                <td colspan="9" class="text-center">Cargando usuarios...</td>
            </tr>
        </tbody>
    </table>
</div>

<?php if ($puedeProgramarHorarios): ?>
<div class="user-schedule-toast" id="user_schedule_toast" role="status" aria-live="polite" hidden></div>

<div class="user-schedule-overlay" id="program_schedule_modal" hidden>
    <section class="user-schedule-dialog" role="dialog" aria-modal="true" aria-labelledby="program_schedule_title">
        <header class="user-schedule-dialog-header">
            <div>
                <h2 id="program_schedule_title">Programar atención</h2>
                <p id="program_schedule_user"></p>
            </div>
            <button type="button" class="user-schedule-icon-button" data-close-schedule aria-label="Cerrar programación">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </header>

        <div class="user-schedule-week-block">
            <span class="user-schedule-label">Semana</span>
            <div class="user-schedule-week-nav">
                <button type="button" class="user-schedule-icon-button" id="program_week_previous" aria-label="Semana anterior">
                    <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                </button>
                <strong id="program_week_range" aria-live="polite"></strong>
                <button type="button" class="user-schedule-icon-button" id="program_week_next" aria-label="Semana siguiente">
                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <section class="user-schedule-previous" aria-labelledby="program_previous_title">
            <div>
                <h3 id="program_previous_title">Semana anterior</h3>
                <p id="program_previous_summary">Cargando programación…</p>
            </div>
            <button type="button" class="user-schedule-button user-schedule-button-outline" id="program_copy_previous" hidden>
                <i class="fa-regular fa-copy" aria-hidden="true"></i> Copiar a esta semana
            </button>
        </section>

        <fieldset class="user-schedule-fieldset">
            <legend>Turnos y días de atención</legend>
            <p class="user-schedule-multi-shift-note">
                Cada turno tiene sus propios días. Puedes asignar Mañana y Tarde al mismo día.
            </p>
            <div class="user-schedule-shifts" id="program_shift_options" role="group" aria-label="Turnos y días de atención"></div>
            <p class="user-schedule-error" id="program_shift_error" aria-live="polite"></p>
        </fieldset>

        <fieldset class="user-schedule-apply" id="program_apply_scope" hidden>
            <legend>Aplicar el cambio</legend>
            <label><input type="radio" name="program_scope" value="semana" checked> Solo esta semana</label>
            <label><input type="radio" name="program_scope" value="desde"> Desde esta semana en adelante</label>
        </fieldset>

        <p class="user-schedule-base-hint" id="program_base_hint" hidden>
            Esta será la programación base y seguirá vigente en las semanas siguientes, salvo que se programe un cambio.
        </p>
        <p class="user-schedule-summary" id="program_schedule_summary" aria-live="polite"></p>
        <footer class="user-schedule-dialog-actions">
            <button type="button" class="user-schedule-button user-schedule-button-secondary" data-close-schedule>Cancelar</button>
            <button type="button" class="user-schedule-button user-schedule-button-gold" id="program_save">
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Guardar programación
            </button>
        </footer>
    </section>
</div>

<div class="user-schedule-overlay" id="manage_shifts_modal" hidden>
    <section class="user-schedule-dialog user-shifts-dialog" role="dialog" aria-modal="true" aria-labelledby="manage_shifts_title">
        <header class="user-schedule-dialog-header">
            <div>
                <h2 id="manage_shifts_title">Turnos de atención</h2>
                <p>Las horas que usa la programación semanal de los vendedores.</p>
            </div>
            <button type="button" class="user-schedule-icon-button" data-close-shifts aria-label="Cerrar turnos">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </header>

        <div class="user-shifts-edit" id="shift_edit_panel" hidden>
            <div class="user-shifts-edit-fields">
                <div class="user-shifts-field">
                    <label for="shift_name">Nombre</label>
                    <input type="text" id="shift_name" maxlength="60" autocomplete="off">
                    <p class="user-schedule-error" id="shift_name_error" aria-live="polite"></p>
                </div>
                <div class="user-shifts-field">
                    <label for="shift_start">Inicio</label>
                    <input type="time" id="shift_start" step="60">
                    <p class="user-schedule-error" id="shift_start_error" aria-live="polite"></p>
                </div>
                <div class="user-shifts-field">
                    <label for="shift_end">Fin</label>
                    <input type="time" id="shift_end" step="60">
                    <p class="user-schedule-error" id="shift_end_error" aria-live="polite"></p>
                </div>
            </div>
            <p class="user-shifts-format-note">Formato de 24 horas. El fin debe ser posterior al inicio.</p>
            <div class="user-schedule-dialog-actions">
                <button type="button" class="user-schedule-button user-schedule-button-secondary" id="shift_cancel_edit">Cancelar</button>
                <button type="button" class="user-schedule-button user-schedule-button-gold" id="shift_save">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Guardar turno
                </button>
            </div>
        </div>

        <div class="user-shifts-list" id="shifts_list"></div>
        <button type="button" class="user-shifts-new" id="shift_new">
            <i class="fa-solid fa-plus" aria-hidden="true"></i> Nuevo turno
        </button>
        <p class="user-shifts-warning">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            El cambio de horas aplica desde hoy. Las cajas ya cerradas conservan el horario con el que se abrieron. Un turno con vendedores asignados no se puede eliminar.
        </p>
        <footer class="user-schedule-dialog-actions">
            <button type="button" class="user-schedule-button user-schedule-button-secondary" data-close-shifts>Cerrar</button>
        </footer>
    </section>
</div>
<?php endif; ?>

<script>
const canEdit = <?= can('usuarios.editar') ? 'true' : 'false' ?>;
const canDelete = <?= can('usuarios.eliminar') ? 'true' : 'false' ?>;
const canProgramSchedules = <?= $puedeProgramarHorarios ? 'true' : 'false' ?>;
</script>

<?php if ($puedeProgramarHorarios): ?>
<script src="<?= BASE_URL ?>/Assets/js/usuarios-horarios-mock.js?v=<?= filemtime(__DIR__ . '/../../Assets/js/usuarios-horarios-mock.js') ?>"></script>
<?php endif; ?>
<?php require_once "Views/Footer.php"; ?>
