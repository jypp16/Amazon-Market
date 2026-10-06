<?php require_once "Views/Header.php"; ?>

<div class="form-container-card">
    <div class="form-card-header">
        <h3><i class="fa-solid fa-user-pen"></i> Editar Cliente</h3>
        <a href="<?= BASE_URL ?>/Cliente" class="btn btn-secondary"><i class="fa-solid fa-arrow-left-long"></i> Volver</a>
    </div>
    
    <form action="<?= BASE_URL ?>/Cliente/actualizar/<?= $data['cliente']['id_cliente'] ?>" method="POST" class="form-grid">
        <?php csrf_field(); ?>
        <div class="form-group col-6">
            <label for="id_tipo_documento">Tipo de Documento <span class="required">*</span></label>
            <select id="id_tipo_documento" name="id_tipo_documento" required>
                <?php foreach($data['tipos_documento'] as $td): ?>
                    <option value="<?= $td['id_tipo_documento'] ?>" <?= ($td['id_tipo_documento'] == $data['cliente']['id_tipo_documento']) ? 'selected' : '' ?>><?= e($td['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group col-6">
            <label for="nro_documento">Número de Documento <span class="required">*</span></label>
            <input type="text" id="nro_documento" name="nro_documento" value="<?= e($data['cliente']['nro_documento']) ?>" required>
        </div>
        
        <div class="form-group col-6">
            <label for="nombre">Nombre Completo / Razón Social <span class="required">*</span></label>
            <input type="text" id="nombre" name="nombre" value="<?= e($data['cliente']['nombre']) ?>" required>
        </div>

        <div class="form-group col-6">
            <label for="telefono">Teléfono</label>
            <input type="text" id="telefono" name="telefono" value="<?= e($data['cliente']['telefono']) ?>">
        </div>

        <div class="form-group col-6">
            <label for="email">Correo Electrónico</label>
            <input type="email" id="email" name="email" value="<?= e($data['cliente']['email']) ?>">
        </div>

        <div class="form-group col-6">
            <label for="direccion">Dirección</label>
            <input type="text" id="direccion" name="direccion" value="<?= e($data['cliente']['direccion']) ?>">
        </div>

        <div class="form-actions col-12">
            <button type="submit" class="btn btn-gold"><i class="fa-solid fa-floppy-disk"></i> Guardar Cambios</button>
            <a href="<?= BASE_URL ?>/Cliente" class="btn btn-cancel">Cancelar</a>
        </div>
    </form>
</div>

<?php require_once "Views/Footer.php"; ?>
