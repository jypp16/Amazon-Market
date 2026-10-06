document.addEventListener('DOMContentLoaded', function() {
    const unidadSelect = document.getElementById('id_unidad');
    const stockActual = document.getElementById('stock_actual');
    const stockMinimo = document.getElementById('stock_minimo');

    if (!unidadSelect || !stockActual || !stockMinimo) return;

    function actualizarStepStock() {
        const unidad = unidadSelect.options[unidadSelect.selectedIndex].text.toLowerCase();
        const unidadesDecimales = ['kg', 'lt', 'lb', 'gal', 'm', 'cm', 'ml', 'g', 'oz'];
        let esDecimal = false;
        for (let ud of unidadesDecimales) {
            if (unidad.includes(ud)) {
                esDecimal = true;
                break;
            }
        }

        if (esDecimal) {
            stockActual.step = "0.01";
            stockActual.placeholder = "0.00";
            stockMinimo.step = "0.01";
            stockMinimo.placeholder = "0.00";
        } else {
            stockActual.step = "1";
            stockActual.placeholder = "0";
            stockMinimo.step = "1";
            stockMinimo.placeholder = "1";
        }
    }

    unidadSelect.addEventListener('change', actualizarStepStock);
    actualizarStepStock();
});
