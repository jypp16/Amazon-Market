document.addEventListener('DOMContentLoaded', function () {
    var card = document.getElementById('expiration-dashboard-card');
    if (!card) return;

    var link = document.getElementById('expiration-dashboard-link');
    var state = document.getElementById('expiration-dashboard-state');
    var retry = document.getElementById('expiration-dashboard-retry');

    function mostrarCargando() {
        retry.hidden = true;
        link.hidden = false;
        state.innerHTML =
            '<span class="dash-kpi-loading" aria-label="Cargando vencimientos"></span>' +
            '<span class="dash-kpi-label">Vencimientos por verificar</span>';
        link.setAttribute('aria-label', 'Vencimientos por verificar: cargando avisos, ver avisos');
    }

    function mostrarError() {
        link.hidden = true;
        retry.hidden = false;
        state.innerHTML = '<span class="dash-kpi-error">No se pudo consultar</span>';
        card.setAttribute('aria-label', 'Vencimientos por verificar: no se pudo consultar');
    }

    function mostrarResumen(resumen) {
        var cantidad = Number(resumen && resumen.avisosActivos);
        if (!Number.isFinite(cantidad) || cantidad < 0) {
            mostrarError();
            return;
        }

        retry.hidden = true;
        link.hidden = false;
        card.removeAttribute('aria-label');
        state.innerHTML =
            '<span class="dash-kpi-number">' + cantidad + '</span>' +
            '<span class="dash-kpi-label">Vencimientos por<br>verificar</span>';

        var descripcion = cantidad === 1
            ? 'Vencimientos por verificar: 1 aviso activo, ver avisos'
            : 'Vencimientos por verificar: ' + cantidad + ' avisos activos, ver avisos';
        link.setAttribute('aria-label', descripcion);
    }

    async function consultarResumen() {
        mostrarCargando();
        try {
            var resumen = await window.AvisosVencimientoApi.obtenerResumenAvisos();
            mostrarResumen(resumen);
        } catch (error) {
            mostrarError();
        }
    }

    retry.addEventListener('click', consultarResumen);
    consultarResumen();
});