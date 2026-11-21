document.addEventListener('DOMContentLoaded', function () {
    var tbody = document.getElementById('expiration-alerts-tbody');
    var tableState = document.getElementById('expiration-alerts-table-state');
    var status = document.getElementById('expiration-alerts-status');
    var count = document.getElementById('expiration-alerts-count');
    var dialog = document.getElementById('expiration-review-dialog');
    var closeButton = document.getElementById('expiration-review-close');
    var question = document.getElementById('expiration-review-question');
    var yesButton = document.getElementById('expiration-review-yes');
    var noButton = document.getElementById('expiration-review-no');
    var retryButton = document.getElementById('expiration-review-retry');
    var errorBox = document.getElementById('expiration-review-error');
    var avisos = [];
    var avisoSeleccionado = null;
    var respuestaPendiente = null;
    var activadorModal = null;
    var guardando = false;

    function escaparHtml(texto) {
        var elemento = document.createElement('span');
        elemento.textContent = texto == null ? '' : String(texto);
        return elemento.innerHTML;
    }

    function formatearFecha(fecha) {
        var partes = fecha.split('-');
        return partes[2] + '/' + partes[1] + '/' + partes[0];
    }

    function textoCondicion(condicion) {
        if (condicion === 'fecha_pasada') return 'Fecha pasada';
        if (condicion === 'vence_hoy') return 'Vence hoy';
        return 'Próximo a vencer';
    }

    function actualizarContador() {
        var cantidad = avisos.length;
        count.textContent = cantidad === 1 ? '1 aviso activo' : cantidad + ' avisos activos';
    }

    function mostrarCargando() {
        status.className = 'expiration-alerts-status';
        status.hidden = true;
        tableState.hidden = false;
        tbody.innerHTML =
            '<tr><td colspan="6"><div class="expiration-alerts-skeleton" aria-label="Cargando avisos"><span></span><span></span><span></span><span></span></div></td></tr>';
        count.textContent = 'Cargando avisos...';
    }

    function mostrarErrorCarga() {
        tableState.hidden = true;
        status.hidden = false;
        status.className = 'expiration-alerts-status is-error';
        status.innerHTML =
            '<span>No se pudieron consultar los avisos de vencimiento.</span>' +
            '<button type="button" class="expiration-alerts-retry"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i> Reintentar</button>';
        count.textContent = 'No fue posible cargar los avisos';
        status.querySelector('.expiration-alerts-retry').addEventListener('click', cargarAvisos);
    }

    function mostrarVacio() {
        tableState.hidden = true;
        status.hidden = false;
        status.className = 'expiration-alerts-status is-empty';
        status.textContent = 'No hay avisos de vencimiento pendientes';
        count.textContent = '0 avisos activos';
    }

    function renderizarAvisos() {
        actualizarContador();
        if (!avisos.length) {
            mostrarVacio();
            return;
        }

        status.hidden = true;
        tableState.hidden = false;
        tbody.innerHTML = avisos.map(function (aviso) {
            return '' +
                '<tr>' +
                    '<td>' + escaparHtml(aviso.categoria) + ' / ' + escaparHtml(aviso.subcategoria) + '</td>' +
                    '<td>' + escaparHtml(aviso.producto) + '</td>' +
                    '<td>' + escaparHtml(formatearFecha(aviso.fechaVencimiento)) + '</td>' +
                    '<td><span class="expiration-condition expiration-condition-' + escaparHtml(aviso.condicion) + '">' +
                        escaparHtml(textoCondicion(aviso.condicion)) +
                    '</span></td>' +
                    '<td>' + escaparHtml(aviso.lote || 'Sin lote') + '</td>' +
                    '<td><button type="button" class="expiration-confirm-button" data-aviso-id="' + escaparHtml(aviso.id) + '">Confirmar existencias</button></td>' +
                '</tr>';
        }).join('');

        tbody.querySelectorAll('.expiration-confirm-button').forEach(function (boton) {
            boton.addEventListener('click', function () {
                var id = boton.dataset.avisoId;
                abrirModal(avisos.find(function (aviso) { return aviso.id === id; }), boton);
            });
        });
    }

    async function cargarAvisos() {
        mostrarCargando();
        try {
            avisos = await window.AvisosVencimientoApi.obtenerAvisos();
            renderizarAvisos();
        } catch (error) {
            mostrarErrorCarga();
        }
    }

    function abrirModal(aviso, activador) {
        if (!aviso) return;
        avisoSeleccionado = aviso;
        respuestaPendiente = null;
        activadorModal = activador;
        errorBox.hidden = true;
        retryButton.hidden = true;
        yesButton.disabled = false;
        noButton.disabled = false;
        closeButton.disabled = false;
        yesButton.textContent = 'Sí, quedan';
        noButton.textContent = 'No quedan';

        question.innerHTML =
            '¿Todavía quedan unidades de <strong>' + escaparHtml(aviso.producto) +
            '</strong> con vencimiento el <strong>' + escaparHtml(formatearFecha(aviso.fechaVencimiento)) + '</strong>?';
        dialog.showModal();
        yesButton.focus();
    }

    function cerrarModal() {
        if (guardando) return;
        if (dialog.open) dialog.close();
        avisoSeleccionado = null;
        respuestaPendiente = null;
        if (activadorModal) activadorModal.focus();
    }

    function establecerGuardando(activo) {
        guardando = activo;
        yesButton.disabled = activo;
        noButton.disabled = activo;
        closeButton.disabled = activo;
        retryButton.disabled = activo;
    }

    async function guardarRespuesta(respuesta) {
        if (guardando || !avisoSeleccionado) return;
        respuestaPendiente = respuesta;
        establecerGuardando(true);
        errorBox.hidden = true;
        retryButton.hidden = true;
        yesButton.innerHTML = respuesta === 'si'
            ? '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Guardando...'
            : 'Sí, quedan';
        noButton.innerHTML = respuesta === 'no'
            ? '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Guardando...'
            : 'No quedan';

        try {
            var resultado = await window.AvisosVencimientoApi.registrarRevision(avisoSeleccionado.id, respuesta);
            establecerGuardando(false);

            if (resultado.estado === 'cerrado') {
                avisos = avisos.filter(function (aviso) {
                    return aviso.id !== avisoSeleccionado.id;
                });
            }

            cerrarModal();
            renderizarAvisos();
        } catch (error) {
            establecerGuardando(false);
            yesButton.textContent = 'Sí, quedan';
            noButton.textContent = 'No quedan';
            errorBox.textContent = 'No se pudo guardar la respuesta. El aviso sigue pendiente.';
            errorBox.hidden = false;
            retryButton.hidden = false;
            retryButton.focus();
        }
    }

    yesButton.addEventListener('click', function () {
        guardarRespuesta('si');
    });

    noButton.addEventListener('click', function () {
        guardarRespuesta('no');
    });

    retryButton.addEventListener('click', function () {
        if (respuestaPendiente) guardarRespuesta(respuestaPendiente);
    });

    closeButton.addEventListener('click', cerrarModal);
    dialog.addEventListener('cancel', function (evento) {
        evento.preventDefault();
        cerrarModal();
    });

    cargarAvisos();
});