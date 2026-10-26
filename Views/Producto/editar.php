<?php require_once "Views/Header.php"; ?>

<div class="form-container-card">
    <div class="form-card-header">
        <h3><i class="fa-solid fa-pen-to-square"></i> Editar Producto</h3>
        <a href="<?= BASE_URL ?>/Producto" class="btn btn-secondary"><i class="fa-solid fa-arrow-left-long"></i> Volver</a>
    </div>
    
    <form action="<?= BASE_URL ?>/Producto/actualizar/<?= $data['producto']['id_producto'] ?>" method="POST" class="form-grid" id="form_producto_editar">
        <?php csrf_field(); ?>
        <div class="form-group col-6">
            <label for="codigo_barra">Código de Barra <span class="required">*</span></label>
            <input type="text" id="codigo_barra" name="codigo_barra" value="<?= e($data['producto']['codigo_barra']) ?>" required>
        </div>
        
        <div class="form-group col-6">
            <label for="nombre">Nombre del Producto <span class="required">*</span></label>
            <input type="text" id="nombre" name="nombre" value="<?= e($data['producto']['nombre']) ?>" required>
        </div>

        <div class="form-group col-12">
            <label for="descripcion">Descripción</label>
            <textarea id="descripcion" name="descripcion" rows="3"><?= e($data['producto']['descripcion']) ?></textarea>
        </div>

        <div class="form-group col-6">
            <label for="id_categoria">Categoría <span class="required">*</span></label>
            <select id="id_categoria" name="id_categoria" required>
                <?php foreach($data['categorias'] as $cat): ?>
                    <option value="<?= $cat['id_categoria'] ?>" <?= ($cat['id_categoria'] == $data['producto']['id_categoria']) ? 'selected' : '' ?>><?= e($cat['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group col-6">
            <label for="id_subcategoria">Subcategoría <span class="required">*</span></label>
            <select id="id_subcategoria" name="id_subcategoria" required>
                <?php foreach($data['subcategorias'] as $subcat): ?>
                    <option value="<?= $subcat['id_subcategoria'] ?>" <?= ($subcat['id_subcategoria'] == $data['producto']['id_subcategoria']) ? 'selected' : '' ?>><?= e($subcat['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group col-6">
            <label for="id_unidad">Unidad de Medida <span class="required">*</span></label>
            <select id="id_unidad" name="id_unidad" required>
                <?php foreach($data['unidades'] as $un): ?>
                    <option value="<?= $un['id_unidad'] ?>" <?= ($un['id_unidad'] == $data['producto']['id_unidad']) ? 'selected' : '' ?>><?= e($un['nombre']) ?> (<?= e($un['abreviatura']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group col-6">
            <label for="precio_venta">Precio de Venta (S/.) <span class="required">*</span></label>
            <input type="number" id="precio_venta" name="precio_venta" value="<?= $data['producto']['precio_venta'] ?>" step="0.01" min="0" required>
        </div>

        <div class="form-group col-6">
            <label for="stock_actual_display">Stock Actual</label>
            <input type="text" id="stock_actual_display" value="<?= e($data['producto']['stock_actual']) ?> unidades" readonly disabled class="form-control-disabled">
            <small style="color: #666;">El stock se explica por operaciones (Compras, Ventas, Bajas y Ajustes) y no es editable directamente.</small>
        </div>

        <div class="form-group col-6">
            <label for="stock_minimo">Stock Mínimo <span class="required">*</span></label>
            <input type="number" id="stock_minimo" name="stock_minimo" value="<?= $data['producto']['stock_minimo'] ?>" step="1" min="0" required>
        </div>

        <div class="form-actions col-12">
            <button type="submit" class="btn btn-gold"><i class="fa-solid fa-floppy-disk"></i> Guardar Cambios</button>
            <a href="<?= BASE_URL ?>/Producto" class="btn btn-cancel">Cancelar</a>
        </div>
    </form>
</div>

<script>
window.SUBCATEGORIAS_DATA = <?= json_encode($data['todas_subcategorias'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
window.PRODUCTO_SUBCATEGORIA_ACTUAL = <?= (int)($data['producto']['id_subcategoria'] ?? 0) ?>;
</script>
<script src="<?= BASE_URL ?>/Assets/js/producto.js"></script>

<?php require_once "Views/Footer.php"; ?>
