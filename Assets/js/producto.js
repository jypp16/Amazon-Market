document.addEventListener('DOMContentLoaded', function() {
    const categoriaSelect = document.getElementById('id_categoria');
    const subcategoriaSelect = document.getElementById('id_subcategoria');
    const stockActual = document.getElementById('stock_actual');
    const stockMinimo = document.getElementById('stock_minimo');

    function actualizarSubcategorias(idSubcategoriaSeleccionada = null) {
        if (!categoriaSelect || !subcategoriaSelect) return;
        const idCat = parseInt(categoriaSelect.value, 10);
        const subcategoriasData = window.SUBCATEGORIAS_DATA || {};

        // Limpiar opciones
        subcategoriaSelect.innerHTML = '';

        const opcionDefecto = document.createElement('option');
        opcionDefecto.value = '';
        opcionDefecto.textContent = '-- Seleccionar Subcategoría --';
        subcategoriaSelect.appendChild(opcionDefecto);

        if (!idCat) return;

        let lista = [];
        if (Array.isArray(subcategoriasData)) {
            lista = subcategoriasData.filter(s => parseInt(s.id_categoria, 10) === idCat);
        } else if (subcategoriasData[idCat]) {
            lista = subcategoriasData[idCat];
        }

        let seleccionada = false;
        lista.forEach(sub => {
            const opt = document.createElement('option');
            opt.value = sub.id_subcategoria;
            opt.textContent = sub.nombre;
            if (idSubcategoriaSeleccionada && parseInt(idSubcategoriaSeleccionada, 10) === parseInt(sub.id_subcategoria, 10)) {
                opt.selected = true;
                seleccionada = true;
            }
            subcategoriaSelect.appendChild(opt);
        });

        // Si no se seleccionó ninguna y no se pasó id explícito, intentar seleccionar "General"
        if (!seleccionada && !idSubcategoriaSeleccionada && lista.length > 0) {
            const general = lista.find(s => (s.nombre || '').toLowerCase() === 'general');
            if (general) {
                subcategoriaSelect.value = general.id_subcategoria;
            }
        }
    }

    if (categoriaSelect && subcategoriaSelect) {
        const idInicial = window.PRODUCTO_SUBCATEGORIA_ACTUAL || subcategoriaSelect.value || null;
        actualizarSubcategorias(idInicial);
        categoriaSelect.addEventListener('change', function() {
            actualizarSubcategorias();
        });
    }

    // Regla de inventario Sprint 2: unidades enteras para envases individuales
    if (stockMinimo) {
        stockMinimo.step = "1";
        stockMinimo.min = "0";
    }
    if (stockActual) {
        stockActual.step = "1";
        stockActual.min = "0";
    }

    // HU-20: manejo de código de barras opcional y confirmación en formularios web clásicos
    const formNuevo = document.getElementById('form_producto_nuevo');
    const formEditar = document.getElementById('form_producto_editar');
    const formActivo = formEditar || formNuevo;
    const codigoInput = document.getElementById('codigo_barra');
    const btnQuitar = document.getElementById('btn_quitar_codigo_barra');
    const errCodigo = document.getElementById('codigo_barra_error');
    const FORMATO_CODIGO = /^\d{8,14}$/;
    const MSG_FORMATO = 'Ingresa o escanea un código válido: solo números, de 8 a 14 dígitos.';

    if (codigoInput && formActivo) {
        function mostrarErrorCodigo(msg) {
            if (errCodigo) {
                errCodigo.textContent = msg || '';
                errCodigo.style.display = msg ? 'block' : 'none';
            }
            codigoInput.classList.toggle('is-invalid', !!msg);
        }

        function syncQuitar() {
            if (btnQuitar) btnQuitar.disabled = codigoInput.value.trim() === '';
            mostrarErrorCodigo('');
        }

        codigoInput.addEventListener('input', syncQuitar);
        codigoInput.addEventListener('keydown', function(ev) {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                const nombreInput = document.getElementById('nombre');
                if (nombreInput) nombreInput.focus();
            }
        });

        if (btnQuitar) {
            btnQuitar.addEventListener('click', function() {
                codigoInput.value = '';
                syncQuitar();
                codigoInput.focus();
            });
        }
        syncQuitar();

        let confirmandoEnvio = false;
        formActivo.addEventListener('submit', async function(ev) {
            if (confirmandoEnvio) return;
            const nuevo = codigoInput.value.trim();
            codigoInput.value = nuevo;
            mostrarErrorCodigo('');

            const anterior = formEditar ? (formEditar.dataset.codigoOriginal || '').trim() : '';
            const sinCambio = formEditar && nuevo === anterior;

            if (!sinCambio && nuevo !== '' && !FORMATO_CODIGO.test(nuevo)) {
                ev.preventDefault();
                mostrarErrorCodigo(MSG_FORMATO);
                codigoInput.focus();
                return;
            }

            if (formEditar && anterior !== '' && nuevo !== anterior) {
                ev.preventDefault();
                const nombreProd = formEditar.dataset.productoNombre || (document.getElementById('nombre') ? document.getElementById('nombre').value : 'este producto');
                let confirmado = false;
                if (window.ProductoCodigoBarras && window.ProductoCodigoBarras.dialogo) {
                    confirmado = await window.ProductoCodigoBarras.dialogo({ nombre: nombreProd }, anterior, nuevo);
                } else {
                    const texto = nuevo === ''
                        ? '¿Quitar el código de barras ' + anterior + ' de ' + nombreProd + '?'
                        : '¿Cambiar el código de barras de ' + nombreProd + ' de ' + anterior + ' a ' + nuevo + '?';
                    confirmado = window.confirm(texto);
                }
                if (confirmado) {
                    confirmandoEnvio = true;
                    formActivo.submit();
                }
            }
        });
    }
});
