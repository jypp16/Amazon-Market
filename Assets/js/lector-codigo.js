function esCodigoDeLector(codigo) {
    return /^[0-9]{6,14}$/.test(codigo);
}

function escucharEscaneoSinFoco(campoBusqueda, recibirCodigo, pausar = () => false) {
    const pausaEntreTeclas = 120;
    const esperaEnter = 350;
    let codigo = '';
    let ultimaTecla = 0;
    let lecturaPausada = false;
    let temporizador;
    let ignorarEnterHasta = 0;

    function escaneoPausado() {
        return pausar() || !!document.querySelector('.modal-overlay, dialog[open]');
    }

    function limpiarLectura() {
        clearTimeout(temporizador);
        codigo = '';
        ultimaTecla = 0;
        lecturaPausada = false;
    }

    function limpiar() {
        limpiarLectura();
        ignorarEnterHasta = 0;
    }

    function finalizarLectura(conEnter) {
        const lectura = codigo;
        const descartar = lecturaPausada || escaneoPausado();
        const fechaUltimaTecla = ultimaTecla;
        limpiarLectura();
        if (!esCodigoDeLector(lectura)) return;

        // Si el lector sí añade Enter, puede llegar después de la pausa.
        ignorarEnterHasta = conEnter ? 0 : fechaUltimaTecla + esperaEnter;
        if (!descartar) recibirCodigo(lectura);
    }

    document.addEventListener('focusin', limpiar);
    document.addEventListener('pointerdown', limpiar);
    window.addEventListener('blur', limpiar);

    document.addEventListener('keydown', event => {
        if (event.isComposing || event.ctrlKey || event.altKey || event.metaKey || event.repeat) {
            limpiar();
            return;
        }

        const objetivo = event.target;
        const pausado = escaneoPausado();
        if (objetivo === campoBusqueda) {
            limpiar();
            if (pausado && event.key === 'Enter') {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
            return;
        }

        if (objetivo?.isContentEditable || objetivo?.closest?.('input, textarea, select, [role="textbox"]')) {
            limpiar();
            return;
        }

        if (/^[0-9]$/.test(event.key)) {
            if (codigo && event.timeStamp - ultimaTecla > pausaEntreTeclas) finalizarLectura(false);
            // Conservar un dígito adicional permite rechazar una lectura demasiado larga completa.
            if (codigo.length < 15) codigo += event.key;
            ultimaTecla = event.timeStamp;
            lecturaPausada ||= pausado;
            clearTimeout(temporizador);
            temporizador = setTimeout(() => finalizarLectura(false), pausaEntreTeclas);
            return;
        }

        const esEnter = event.key === 'Enter';
        const terminaEscaneo = esEnter && esCodigoDeLector(codigo) && event.timeStamp - ultimaTecla <= esperaEnter;
        const esEnterTardio = esEnter && ignorarEnterHasta > 0 && event.timeStamp <= ignorarEnterHasta;
        if (!terminaEscaneo && !esEnterTardio) {
            limpiar();
            return;
        }

        // El Enter del lector no debe accionar el botón o diálogo que tenga el foco.
        event.preventDefault();
        event.stopImmediatePropagation();
        if (terminaEscaneo) finalizarLectura(true);
        else ignorarEnterHasta = 0;
    }, true);
}
