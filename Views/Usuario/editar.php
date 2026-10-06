<?php require_once "Views/Header.php"; ?>

<div class="form-container-card">
    <div class="form-card-header">
        <h3><i class="fa-solid fa-user-pen"></i> Editar Usuario</h3>
        <a href="<?= BASE_URL ?>/Usuario" class="btn btn-secondary"><i class="fa-solid fa-arrow-left-long"></i> Volver</a>
    </div>
    
    <form action="<?= BASE_URL ?>/Usuario/actualizar/<?= $data['usuario']['id_usuario'] ?>" method="POST" class="form-grid">
        <?php csrf_field(); ?>
        <div class="form-group col-6">
            <label for="id_rol">Rol <span class="required">*</span></label>
            <select id="id_rol" name="id_rol" required>
                <?php foreach($data['roles'] as $rol): ?>
                    <option value="<?= $rol['id_rol'] ?>" <?= ($rol['id_rol'] == $data['usuario']['id_rol']) ? 'selected' : '' ?>><?= e($rol['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group col-6">
            <label for="username">Username <span class="required">*</span></label>
            <input type="text" id="username" name="username" value="<?= e($data['usuario']['username']) ?>" required maxlength="10">
        </div>
        
        <div class="form-group col-6">
            <label for="password">Contraseña <span class="text-muted">(dejar vacío para no cambiar)</span></label>
            <input type="password" id="password" name="password" placeholder="Mínimo 6 caracteres" minlength="6">
        </div>

        <div class="form-group col-6">
            <label for="nombre">Nombre Completo <span class="required">*</span></label>
            <input type="text" id="nombre" name="nombre" value="<?= e($data['usuario']['nombre']) ?>" required>
        </div>

        <div class="form-group col-6">
            <label for="dni">DNI <span class="required">*</span></label>
            <input type="text" id="dni" name="dni" value="<?= e($data['usuario']['dni']) ?>" required maxlength="8" pattern="[0-9]{8}">
        </div>

        <div class="form-group col-6">
            <label for="telefono">Teléfono</label>
            <input type="text" id="telefono" name="telefono" value="<?= e($data['usuario']['telefono']) ?>" maxlength="9">
        </div>

        <div class="form-group col-6">
            <label for="email">Correo Electrónico</label>
            <input type="email" id="email" name="email" value="<?= e($data['usuario']['email']) ?>">
        </div>

        <div class="form-group col-6">
            <label for="direccion">Dirección</label>
            <input type="text" id="direccion" name="direccion" value="<?= e($data['usuario']['direccion']) ?>">
        </div>

        <div class="form-actions col-12">
            <button type="submit" class="btn btn-gold"><i class="fa-solid fa-floppy-disk"></i> Guardar Cambios</button>
            <a href="<?= BASE_URL ?>/Usuario" class="btn btn-cancel">Cancelar</a>
        </div>
    </form>
</div>

<?php require_once "Views/Footer.php"; ?>
