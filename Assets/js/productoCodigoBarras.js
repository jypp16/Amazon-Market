/*
 * HU-20: asociar, cambiar o quitar el código de barras al editar un producto.
 * Se usa desde productos.js (abrirModalEditar) mediante window.ProductoCodigoBarras.
 */
window.ProductoCodigoBarras = (function () {
    // TODO backend: alinear formato y longitud con la validación del servidor (hoy 3–50 caracteres).
    var FORMATO = /^\d{8,14}$/;
    var MSG_FORMATO = 'Ingresa o escanea un código válido: solo números, de 8 a 14 dígitos.';

    function crear(tag, clase, texto) {
        var nodo = document.createElement(tag);
        if (clase) nodo.className = clase;
        if (texto != null) nodo.textContent = texto;
        return nodo;
    }

    function icono(clases) {
        var i = crear('i', clases);
        i.setAttribute('aria-hidden', 'true');
        return i;
    }

    function esActivo(prod) {
        var e = prod.estado;
        return e === undefined || e === null || e === 1 || e === '1' || e === true || String(e).toLowerCase() === 'activo';
    }

    /* ---------- Montaje en el formulario ---------- */
    function montar(form, prod) {
        var input = form.querySelector('#mf_codigo_barra');
        var labelCodigo = form.querySelector('label[for="mf_codigo_barra"]');
        var labelSub = form.querySelector('label[for="mf_id_subcategoria"]');
        if (!input || !labelCodigo) return;

        // Cabecera: identifica el producto que se edita (RF-20.1)
        var chip = crear('div', 'pcb-chip col-12');
        var cuadro = crear('span', 'pcb-chip-icon');
        cuadro.appendChild(icono('fa-solid fa-cube'));
        var datos = crear('div', 'pcb-chip-data');
        datos.appendChild(crear('small', null, 'Estás editando'));
        datos.appendChild(crear('strong', null, prod.nombre));
        var estado = crear('span', 'pcb-status ' + (esActivo(prod) ? 'is-active' : 'is-inactive'), esActivo(prod) ? 'Activo' : 'Inactivo');
        var actual = crear('div', 'pcb-chip-code');
        actual.appendChild(crear('small', null, 'Código actual'));
        actual.appendChild(crear('code', null, prod.codigo_barra ? String(prod.codigo_barra) : 'Sin código'));
        chip.appendChild(cuadro); chip.appendChild(datos); chip.appendChild(estado); chip.appendChild(actual);
        form.insertBefore(chip, form.firstChild);

        var cuerpo = form.closest('.modal-form-body');
         if (cuerpo) cuerpo.style.maxHeight = 'calc(100vh - 140px)';

        // Etiqueta "(opcional)" y botón Quitar
        var fila = crear('div', 'pcb-label-row');
        labelCodigo.parentNode.insertBefore(fila, labelCodigo);
        fila.appendChild(labelCodigo);
        labelCodigo.appendChild(crear('span', 'pcb-optional', ' (opcional)'));
        var quitar = crear('button', 'pcb-quitar');
        quitar.type = 'button';
        quitar.appendChild(icono('fa-regular fa-trash-can'));
        quitar.appendChild(document.createTextNode(' Quitar'));
        fila.appendChild(quitar);
        if (labelSub) labelSub.appendChild(crear('span', 'pcb-optional', ' (opcional)'));

        // Mensaje de error del campo
        var error = crear('p', 'pcb-error');
        error.id = 'pcb_error';
        error.setAttribute('role', 'alert');
        error.hidden = true;
        
        var grupoCodigo = input.closest('.form-group');
        var grupoSiguiente = grupoCodigo ? grupoCodigo.nextElementSibling : null;
        (grupoSiguiente || grupoCodigo).insertAdjacentElement('afterend', error);
        input.setAttribute('aria-describedby', 'pcb_error');

        input.setAttribute('autocomplete', 'off');
        input.setAttribute('inputmode', 'numeric');
        input.placeholder = 'Escanea o escribe el código';

        function sincronizar() {
            quitar.disabled = input.value.trim() === '';
            limpiarError(form);
        }
        input.addEventListener('input', sincronizar);
        quitar.addEventListener('click', function () {
            input.value = '';
            sincronizar();
            input.focus();
        });
        // Un lector de códigos envía Enter al terminar: no debe guardar el formulario (CA-20.2).
        input.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Enter') return;
            ev.preventDefault();
            var siguiente = form.querySelector('#mf_nombre');
            if (siguiente) siguiente.focus();
        });
        sincronizar();
    }

    /* ---------- Errores del campo ---------- */
    function mostrarError(form, mensaje) {
        var error = form.querySelector('#pcb_error');
        var input = form.querySelector('#mf_codigo_barra');
        if (!error || !input) return;
        error.textContent = '';
        error.appendChild(icono('fa-solid fa-triangle-exclamation'));
        error.appendChild(document.createTextNode(' ' + mensaje));
        error.hidden = false;
        input.classList.add('is-invalid');
        input.setAttribute('aria-invalid', 'true');
        input.focus();
    }

    function limpiarError(form) {
        var error = form.querySelector('#pcb_error');
        var input = form.querySelector('#mf_codigo_barra');
        if (error) error.hidden = true;
        if (input) { input.classList.remove('is-invalid'); input.removeAttribute('aria-invalid'); }
    }

    /* ---------- Unicidad (el servidor sigue siendo la validación final) ---------- */
    async function buscarDuplicado(codigo, idActual) {
        try {
            var res = await Api.get('productos?q=' + encodeURIComponent(codigo) + '&limit=10');
            if (!res || !res.ok) return null;
            var lista = (res.data && res.data.data) || [];
            return lista.find(function (p) {
                return String(p.codigo_barra).trim() === codigo && Number(p.id_producto) !== Number(idActual);
            }) || null;
        } catch (e) {
            return null; // si la consulta falla, decide el servidor al guardar
        }
    }

    /* ---------- Confirmación antes de cambiar o quitar ---------- */
    function dialogo(prod, anterior, nuevo) {
        return new Promise(function (resolve) {
            var quitando = nuevo === '';
            var dlg = crear('dialog', 'pcb-dialog');
            dlg.setAttribute('aria-labelledby', 'pcb_dlg_titulo');

            var circulo = crear('span', 'pcb-dialog-icon');
            circulo.appendChild(icono('fa-solid fa-triangle-exclamation'));
            var titulo = crear('h3', null, quitando ? '¿Quitar el código de barras?' : '¿Cambiar el código de barras?');
            titulo.id = 'pcb_dlg_titulo';

            var texto = crear('p');
            texto.appendChild(document.createTextNode('El código de '));
            texto.appendChild(crear('strong', null, prod.nombre));
            if (quitando) {
                texto.appendChild(document.createTextNode(' ('));
                texto.appendChild(crear('code', null, anterior));
                texto.appendChild(document.createTextNode(') se quitará. El producto seguirá funcionando con el registro manual y podrás asignarle un código después.'));
            } else {
                texto.appendChild(document.createTextNode(' pasará de '));
                texto.appendChild(crear('code', null, anterior));
                texto.appendChild(document.createTextNode(' a '));
                texto.appendChild(crear('code', null, nuevo));
                texto.appendChild(document.createTextNode('. El producto seguirá funcionando y podrás volver a cambiarlo.'));
            }

            var acciones = crear('div', 'pcb-dialog-actions');
            var cancelar = crear('button', 'pcb-btn', 'Cancelar');
            cancelar.type = 'button';
            var aceptar = crear('button', 'pcb-btn pcb-btn-primary');
            aceptar.type = 'button';
            aceptar.appendChild(icono('fa-solid fa-check'));
            aceptar.appendChild(document.createTextNode(quitando ? ' Sí, quitar código' : ' Sí, cambiar código'));
            acciones.appendChild(cancelar); acciones.appendChild(aceptar);

            dlg.appendChild(circulo); dlg.appendChild(titulo); dlg.appendChild(texto); dlg.appendChild(acciones);
            document.body.appendChild(dlg);

            var respuesta = false;
            cancelar.addEventListener('click', function () { dlg.close(); });
            aceptar.addEventListener('click', function () { respuesta = true; dlg.close(); });
            dlg.addEventListener('close', function () { dlg.remove(); resolve(respuesta); });
            dlg.showModal();
            cancelar.focus();
        });
    }

    /* Gancho beforeSubmit de ModalForm: devuelve true si se puede continuar. */
    async function confirmarCambio(form, prod) {
        var input = form.querySelector('#mf_codigo_barra');
        if (!input) return true;
        var anterior = String(prod.codigo_barra || '').trim();
        var nuevo = input.value.trim();
        input.value = nuevo;
        limpiarError(form);

        if (nuevo === anterior) return true;

        if (nuevo !== '') {
            if (!FORMATO.test(nuevo)) { mostrarError(form, MSG_FORMATO); return false; }
            var duplicado = await buscarDuplicado(nuevo, prod.id_producto);
            if (duplicado) {
                mostrarError(form, 'Este código ya está asociado a otro producto (' + duplicado.nombre + '). Usa un código distinto.');
                return false;
            }
        }
        if (anterior === '') return true; // asociar un código por primera vez no necesita confirmación
        var boton = form.querySelector('button[type="submit"]');
        if (boton) {
            boton.disabled = false;
            boton.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Guardar Cambios';
        }
        var confirmado = await dialogo(prod, anterior, nuevo);
        if (confirmado && boton) {
            boton.disabled = true;
            boton.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Guardando...';
        }
        return confirmado;
    }

    /* Gancho onError de ModalForm: muestra bajo el campo los errores del código. */
    function manejarErrorServidor(resultado, form) {
        var errores = resultado && resultado.data && resultado.data.errors;
        if (!errores || !errores.codigo_barra) return false;
        var mensajes = [].concat(errores.codigo_barra);
        mostrarError(form, mensajes.join(' '));
        var otros = Object.keys(errores).filter(function (k) { return k !== 'codigo_barra'; });
        if (!otros.length) return true;
        var resto = {};
        otros.forEach(function (k) { resto[k] = errores[k]; });
        resultado.data.errors = resto; // el resto lo muestra ModalForm arriba de los botones
        return false;
    }

    function montarCrear(form) {
        var input = form.querySelector('#mf_codigo_barra');
        var labelCodigo = form.querySelector('label[for="mf_codigo_barra"]');
        if (!input || !labelCodigo) return;

        labelCodigo.appendChild(crear('span', 'pcb-optional', ' (opcional)'));

        var error = crear('p', 'pcb-error');
        error.id = 'pcb_error';
        error.setAttribute('role', 'alert');
        error.hidden = true;

        var grupoCodigo = input.closest('.form-group');
        var grupoSiguiente = grupoCodigo ? grupoCodigo.nextElementSibling : null;
        (grupoSiguiente || grupoCodigo).insertAdjacentElement('afterend', error);
        input.setAttribute('aria-describedby', 'pcb_error');
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('inputmode', 'numeric');

        input.addEventListener('input', function () { limpiarError(form); });
        input.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Enter') return;
            ev.preventDefault();
            var siguiente = form.querySelector('#mf_nombre');
            if (siguiente) siguiente.focus();
        });
    }

    async function validarCrear(form) {
        var input = form.querySelector('#mf_codigo_barra');
        if (!input) return true;
        var nuevo = input.value.trim();
        input.value = nuevo;
        limpiarError(form);

        if (nuevo === '') return true;
        if (!FORMATO.test(nuevo)) {
            mostrarError(form, MSG_FORMATO);
            return false;
        }
        return true;
    }

    return {
        montar: montar,
        montarCrear: montarCrear,
        validarCrear: validarCrear,
        confirmarCambio: confirmarCambio,
        manejarErrorServidor: manejarErrorServidor,
        dialogo: dialogo
    };
})();