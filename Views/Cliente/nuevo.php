<?php require_once "Views/Header.php"; ?>

<div class="form-container-card">
    <div class="form-card-header">
        <h3><i class="fa-solid fa-user-plus"></i> Registrar Nuevo Cliente</h3>
        <a href="<?= BASE_URL ?>/Cliente" class="btn btn-secondary"><i class="fa-solid fa-arrow-left-long"></i> Volver</a>
    </div>
    
    <form action="<?= BASE_URL ?>/Cliente/guardar" method="POST" class="form-grid">
        <?php csrf_field(); ?>
        <div class="form-group col-6">
            <label for="id_tipo_documento">Tipo de Documento <span class="required">*</span></label>
            <select id="id_tipo_documento" name="id_tipo_documento" required>
                <?php foreach($data['tipos_documento'] as $td): ?>
                    <option value="<?= $td['id_tipo_documento'] ?>"><?= e($td['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group col-6">
            <label for="nro_documento">Número de Documento <span class="required">*</span></label>
            <input type="text" id="nro_documento" name="nro_documento" placeholder="Escriba el número" required>
        </div>
        
        <div class="form-group col-6">
            <label for="nombre">Nombre Completo / Razón Social <span class="required">*</span></label>
            <input type="text" id="nombre" name="nombre" placeholder="Nombre completo" required>
        </div>

        <div class="form-group col-6">
            <label for="telefono">Teléfono</label>
            <input type="text" id="telefono" name="telefono" placeholder="Número de celular">
        </div>

        <div class="form-group col-6">
            <label for="email">Correo Electrónico</label>
            <input type="email" id="email" name="email" placeholder="correo@ejemplo.com">
        </div>

        <div class="form-group col-6">
            <label for="direccion">Dirección</label>
            <input type="text" id="direccion" name="direccion" placeholder="Calle, Av, Jr...">
        </div>

        <div class="form-actions col-12">
            <button type="submit" class="btn btn-gold"><i class="fa-solid fa-floppy-disk"></i> Guardar Cliente</button>
            <a href="<?= BASE_URL ?>/Cliente" class="btn btn-cancel">Cancelar</a>
        </div>
    </form>
</div>

<?php require_once "Views/Footer.php"; ?>
