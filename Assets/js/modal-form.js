const ModalForm = {
    _overlay: null,

    open(config) {
        this._close();
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';

        const container = document.createElement('div');
        container.className = 'modal-container modal-form';
        container.style.maxWidth = config.width || '600px';

        const header = document.createElement('div');
        header.className = 'modal-form-header';
        const h3 = document.createElement('h3');
        h3.textContent = config.titulo;
        header.appendChild(h3);

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'modal-close-btn';
        closeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
        closeBtn.addEventListener('click', () => this._close());
        header.appendChild(closeBtn);

        const body = document.createElement('div');
        body.className = 'modal-form-body';

        const form = document.createElement('form');
        form.id = 'modal_form';
        form.className = 'form-grid';

        config.campos.forEach(campo => {
            const group = document.createElement('div');
            group.className = 'form-group ' + (campo.col || 'col-6');

            const label = document.createElement('label');
            label.setAttribute('for', 'mf_' + campo.name);
            label.textContent = campo.label;
            if (campo.required) {
                const span = document.createElement('span');
                span.className = 'required';
                span.textContent = ' *';
                label.appendChild(span);
            }
            group.appendChild(label);

            let input;
            if (campo.type === 'select') {
                input = document.createElement('select');
                input.id = 'mf_' + campo.name;
                input.name = campo.name;
                if (campo.required) input.required = true;
                if (campo.options) {
                    campo.options.forEach(opt => {
                        const option = document.createElement('option');
                        option.value = opt.value;
                        option.textContent = opt.label;
                        input.appendChild(option);
                    });
                }
            } else if (campo.type === 'textarea') {
                input = document.createElement('textarea');
                input.id = 'mf_' + campo.name;
                input.name = campo.name;
                input.rows = campo.rows || 3;
                input.placeholder = campo.placeholder || '';
            } else {
                input = document.createElement('input');
                input.type = campo.type || 'text';
                input.id = 'mf_' + campo.name;
                input.name = campo.name;
                input.placeholder = campo.placeholder || '';
                if (campo.required) input.required = true;
                if (campo.min !== undefined) input.min = campo.min;
                if (campo.max !== undefined) input.max = campo.max;
                if (campo.step) input.step = campo.step;
                if (campo.maxlength) input.maxLength = campo.maxlength;
                if (campo.pattern) input.pattern = campo.pattern;
            }

            if (campo.value !== undefined && campo.value !== null) {
                input.value = campo.value;
            }

            group.appendChild(input);
            form.appendChild(group);
        });

        const errorDiv = document.createElement('div');
        errorDiv.id = 'modal_form_error';
        errorDiv.className = 'form-error-msg';
        errorDiv.style.display = 'none';
        form.appendChild(errorDiv);

        const actions = document.createElement('div');
        actions.className = 'form-actions col-12';

        const submitBtn = document.createElement('button');
        submitBtn.type = 'submit';
        submitBtn.className = 'btn btn-gold';
        submitBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> ' + (config.btnText || 'Guardar');
        actions.appendChild(submitBtn);

        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'btn btn-cancel';
        cancelBtn.textContent = 'Cancelar';
        cancelBtn.addEventListener('click', () => this._close());
        actions.appendChild(cancelBtn);

        form.appendChild(actions);

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Guardando...';
            errorDiv.style.display = 'none';

            try {
                const formData = new FormData(form);
                const hasFiles = form.querySelector('input[type="file"]');

                const method = config.method || (config.id ? 'PUT' : 'POST');
                const endpoint = config.id ? config.endpoint + '/' + config.id : config.endpoint;

                let result;
                if (hasFiles) {
                    formData.append('_method', method);
                    result = await Api.request(endpoint, {
                        method: 'POST',
                        body: formData
                    });
                } else {
                    const data = {};
                    formData.forEach((val, key) => { data[key] = val; });
                    result = await Api.request(endpoint, {
                        method: method,
                        body: data
                    });
                }

                if (result && result.ok) {
                    this._close();
                    await Modal.success('Éxito', result.data.message || 'Operación completada.');
                    if (config.onSuccess) config.onSuccess();
                } else {
                    let msg = result ? result.data.message : 'Error desconocido.';
                    if (result && result.data.errors) {
                        const errs = Object.values(result.data.errors).flat();
                        msg = errs.join('\n');
                    }
                    errorDiv.textContent = msg;
                    errorDiv.style.display = 'block';
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> ' + (config.btnText || 'Guardar');
                }
            } catch (err) {
                errorDiv.textContent = 'Error de conexión: ' + err.message;
                errorDiv.style.display = 'block';
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> ' + (config.btnText || 'Guardar');
            }
        });

        body.appendChild(form);
        container.appendChild(header);
        container.appendChild(body);
        overlay.appendChild(container);
        document.body.appendChild(overlay);
        this._overlay = overlay;

        setTimeout(() => overlay.classList.add('modal-active'), 10);

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) this._close();
        });

        const firstInput = form.querySelector('input, select, textarea');
        if (firstInput) setTimeout(() => firstInput.focus(), 100);

        if (config.onMount) setTimeout(() => config.onMount(form), 50);
    },

    _close() {
        if (this._overlay) {
            const el = this._overlay;
            this._overlay = null;
            el.classList.remove('modal-active');
            setTimeout(() => el.remove(), 300);
        }
    }
};
