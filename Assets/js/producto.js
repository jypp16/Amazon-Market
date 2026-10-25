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
});
