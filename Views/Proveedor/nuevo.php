<form class="form-grid" id="form_nuevo_proveedor">
    <div class="form-group col-6">
        <label for="proveedor_tipo_documento">Tipo de documento <span class="required">*</span></label>
        <select id="proveedor_tipo_documento" name="id_tipo_documento" required data-custom-select>
            <option value="1">DNI</option>
            <option value="2" selected>RUC</option>
        </select>
    </div>

    <div class="form-group col-6">
        <label for="proveedor_nro_documento">N° de documento <span class="required">*</span></label>
        <input type="text" id="proveedor_nro_documento" name="nro_documento" placeholder="11 dígitos" required maxlength="11" pattern="[0-9]{11}" autocomplete="off" inputmode="numeric" aria-describedby="error_proveedor_documento">
        <small class="field-error" id="error_proveedor_documento" aria-live="polite"></small>
    </div>

    <div class="form-group col-12">
        <label for="proveedor_nombre">Nombre / Razón social <span class="required">*</span></label>
        <input type="text" id="proveedor_nombre" name="nombre" placeholder="Ej. Comercial Valle Fresco S.A.C" required minlength="2" maxlength="150" autocomplete="organization" aria-describedby="error_proveedor_nombre">
        <small class="field-error" id="error_proveedor_nombre" aria-live="polite"></small>
    </div>

    <div class="form-group col-6">
        <label for="proveedor_estado">Estado <span class="required">*</span></label>
        <select id="proveedor_estado" name="estado" required data-custom-select>
            <option value="1" selected>Activo</option>
            <option value="0">Inactivo</option>
        </select>
    </div>

    <div class="form-group col-6">
        <label for="proveedor_telefono">Teléfono <span class="required">*</span></label>
        <input type="tel" id="proveedor_telefono" name="telefono" placeholder="Ej. 01559 8877" required minlength="7" maxlength="20" autocomplete="tel" inputmode="tel" aria-describedby="error_proveedor_telefono">
        <small class="field-error" id="error_proveedor_telefono" aria-live="polite"></small>
    </div>

    <div class="form-actions col-12">
        <button type="button" class="btn btn-gold" disabled>
            <i class="fa-solid fa-floppy-disk"></i> Guardar Proveedor
        </button>
        <button type="button" class="btn btn-cancel" data-modal-close>Cancelar</button>
    </div>
</form>
