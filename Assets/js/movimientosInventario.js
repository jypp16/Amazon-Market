document.addEventListener('DOMContentLoaded', function () {
    var api = window.MovimientosInventarioApi;
    if (!api) return;

    var MENOS = '\u2212';
    var CAMPOS = { cantidad: 'Cantidad', tipo: 'Tipo', justificacion: 'Justificación', lote: 'Lote' };

    /* ---------- Utilidades ---------- */
    function esc(texto) {
        var el = document.createElement('span');
        el.textContent = texto == null ? '' : String(texto);
        return el.innerHTML;
    }
    function fecha(iso) { var p = iso.slice(0, 10).split('-'); return p[2] + '/' + p[1] + '/' + p[0]; }
    function fechaHora(iso) { return fecha(iso) + ' ' + iso.slice(11, 16); }
    function etiquetaTipo(tipo) { return api.tipos[tipo] ? api.tipos[tipo].etiqueta : tipo; }
    function efecto(tipo, cantidad) { return api.tipos[tipo].signo * cantidad; }
    function firmado(n, conMas) { return (n < 0 ? MENOS : (n > 0 && conMas ? '+' : '')) + Math.abs(n); }
    function unidades(n) { return firmado(n, false) + (Math.abs(n) === 1 ? ' unidad' : ' unidades'); }
    function claseSigno(n) { return n < 0 ? 'is-negative' : 'is-positive'; }
    function badgeTipo(tipo) { return '<span class="mov-badge mov-badge-' + esc(tipo) + '">' + esc(etiquetaTipo(tipo)) + '</span>'; }
    function valorCampo(campo, valor) {
        if (campo === 'tipo') return etiquetaTipo(valor);
        if (campo === 'cantidad') return String(valor);
        return valor ? String(valor) : 'Sin dato';
    }
    function qs(nombre) { return new URLSearchParams(window.location.search).get(nombre) || ''; }
    function urlListado(lq) { return BASE_URL + '/Inventario/movimientos' + (lq ? '?' + lq : ''); }
    function urlDetalle(id, lq, extra) {
        var partes = [];
        if (lq) partes.push('lq=' + encodeURIComponent(lq));
        if (extra) partes.push(extra);
        return BASE_URL + '/Inventario/movimiento/' + id + (partes.length ? '?' + partes.join('&') : '');
    }
    function urlEdicion(id, lq) {
        return BASE_URL + '/Inventario/editarMovimiento/' + id + (lq ? '?lq=' + encodeURIComponent(lq) : '');
    }
    function pegarEstado(contenedor, tipo, html) {
        contenedor.hidden = false;
        contenedor.className = 'mov-status is-' + tipo;
        contenedor.innerHTML = html;
    }

    /* ---------- LISTADO (HU-23) ---------- */
    function iniciarListado(raiz) {
        var form = document.getElementById('mov-filtros');
        var inputQ = document.getElementById('mov-q');
        var selectTipo = document.getElementById('mov-tipo');
        var inputDesde = document.getElementById('mov-desde');
        var inputHasta = document.getElementById('mov-hasta');
        var errorFecha = document.getElementById('mov-fecha-error');
        var tbody = document.getElementById('mov-tbody');
        var tabla = document.getElementById('mov-table-wrap');
        var estado = document.getElementById('mov-status');
        var resumen = document.getElementById('mov-resumen');
        var pager = document.getElementById('mov-pager');

        var filtros = { q: qs('q'), tipo: qs('tipo'), desde: qs('desde'), hasta: qs('hasta'), pagina: parseInt(qs('pagina'), 10) || 1 };
        var consultaSeq = 0;
        inputQ.value = filtros.q; selectTipo.value = filtros.tipo; inputDesde.value = filtros.desde; inputHasta.value = filtros.hasta;

        function consulta() {
            var p = new URLSearchParams();
            ['q', 'tipo', 'desde', 'hasta'].forEach(function (k) { if (filtros[k]) p.set(k, filtros[k]); });
            if (filtros.pagina > 1) p.set('pagina', filtros.pagina);
            return p.toString();
        }

        function cargando() {
            estado.hidden = true; tabla.hidden = false; pager.innerHTML = ''; resumen.textContent = 'Cargando movimientos...';
            tbody.innerHTML = '<tr><td colspan="7"><div class="mov-skeleton"><span></span><span></span><span></span></div></td></tr>';
        }

        function renderizar(res) {
            filtros.pagina = res.pagina;
            window.history.replaceState(null, '', window.location.pathname + (consulta() ? '?' + consulta() : ''));
            var lq = consulta();
            if (!res.items.length) {
                tabla.hidden = true; pager.innerHTML = ''; resumen.textContent = '0 movimientos';
                pegarEstado(estado, 'empty', '<span>No se encontraron movimientos con los filtros aplicados</span>' +
                    '<button type="button" class="mov-btn" id="mov-limpiar-vacio">Limpiar filtros</button>');
                document.getElementById('mov-limpiar-vacio').addEventListener('click', limpiar);
                return;
            }
            estado.hidden = true; tabla.hidden = false;
            tbody.innerHTML = res.items.map(function (m) {
                var e = efecto(m.tipo, m.cantidad);
                var codigoTxt = m.codigoBarras ? esc(m.codigoBarras) : 'Sin código';
                return '<tr>' +
                    '<td class="mov-num">#' + m.id + '</td>' +
                    '<td>' + esc(fechaHora(m.fechaHora)) + '</td>' +
                    '<td><strong>' + esc(m.producto) + '</strong><small>' + codigoTxt + '</small></td>' +
                    '<td>' + badgeTipo(m.tipo) + '</td>' +
                    '<td class="mov-qty ' + claseSigno(e) + '">' + firmado(e, true) + '</td>' +
                    '<td>' + (m.corregido ? '<span class="mov-badge mov-badge-corregido">Corregido</span>' : '<span class="mov-dash">—</span>') + '</td>' +
                    '<td><a class="mov-btn" href="' + esc(urlDetalle(m.id, lq)) + '">Ver detalle</a></td></tr>';
            }).join('');
            resumen.textContent = 'Mostrando ' + res.items.length + ' de ' + res.total + ' movimientos · del más reciente al más antiguo';
            var botones = '<button type="button" class="mov-btn" data-pag="' + (res.pagina - 1) + '"' + (res.pagina <= 1 ? ' disabled' : '') + '>Anterior</button>';
            for (var i = 1; i <= res.totalPaginas; i++) {
                botones += '<button type="button" class="mov-btn mov-page' + (i === res.pagina ? ' is-current' : '') + '" data-pag="' + i + '"' + (i === res.pagina ? ' aria-current="page"' : '') + '>' + i + '</button>';
            }
            botones += '<button type="button" class="mov-btn" data-pag="' + (res.pagina + 1) + '"' + (res.pagina >= res.totalPaginas ? ' disabled' : '') + '>Siguiente</button>';
            pager.innerHTML = botones;
        }

        async function cargar() {
            var miSeq = ++consultaSeq;
            cargando();
            try {
                var res = await api.listarMovimientos(filtros);
                if (miSeq !== consultaSeq) return;
                renderizar(res);
            } catch (e) {
                if (miSeq !== consultaSeq || (e && e.codigo === 'ABORTADO')) return;
                tabla.hidden = true; pager.innerHTML = ''; resumen.textContent = '';
                if (e && e.codigo === 'VALIDACION') {
                    errorFecha.textContent = e.message;
                    errorFecha.hidden = false;
                    inputDesde.classList.add('is-invalid');
                    inputHasta.classList.add('is-invalid');
                    pegarEstado(estado, 'error', '<span>' + esc(e.message) + '</span>' +
                        '<button type="button" class="mov-btn" id="mov-limpiar-val">Limpiar filtros</button>');
                    document.getElementById('mov-limpiar-val').addEventListener('click', limpiar);
                    return;
                }
                pegarEstado(estado, 'error', '<span>No se pudieron consultar los movimientos de inventario.</span>' +
                    '<button type="button" class="mov-btn" id="mov-reintentar"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i> Reintentar</button>');
                document.getElementById('mov-reintentar').addEventListener('click', cargar);
            }
        }

        function limpiar() {
            inputQ.value = ''; selectTipo.value = ''; inputDesde.value = ''; inputHasta.value = '';
            errorFecha.hidden = true; inputDesde.classList.remove('is-invalid'); inputHasta.classList.remove('is-invalid');
            filtros = { q: '', tipo: '', desde: '', hasta: '', pagina: 1 };
            cargar();
        }

        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            if (inputDesde.value && inputHasta.value && inputDesde.value > inputHasta.value) {
                errorFecha.textContent = 'La fecha inicial no puede ser posterior a la final. Corrige el rango y vuelve a buscar.';
                errorFecha.hidden = false; inputDesde.classList.add('is-invalid'); inputHasta.classList.add('is-invalid');
                return;
            }
            errorFecha.hidden = true; inputDesde.classList.remove('is-invalid'); inputHasta.classList.remove('is-invalid');
            filtros = { q: inputQ.value.trim(), tipo: selectTipo.value, desde: inputDesde.value, hasta: inputHasta.value, pagina: 1 };
            cargar();
        });
        document.getElementById('mov-limpiar').addEventListener('click', limpiar);
        pager.addEventListener('click', function (ev) {
            var boton = ev.target.closest('button[data-pag]');
            if (!boton || boton.disabled) return;
            filtros.pagina = parseInt(boton.dataset.pag, 10);
            cargar();
        });
        cargar();
    }

    /* ---------- DETALLE (HU-23) ---------- */
    function iniciarDetalle(raiz) {
        var id = raiz.dataset.id;
        var lq = qs('lq');
        var contenido = document.getElementById('mov-content');
        var estado = document.getElementById('mov-status');
        document.getElementById('mov-back').href = urlListado(lq);

        function historialHtml(m) {
            if (!m.correcciones.length) return '<p class="mov-empty-text">Este movimiento no tiene correcciones registradas</p>';
            var tipoActual = m.original.tipo;
            return '<ol class="mov-timeline">' + m.correcciones.map(function (c) {
                var cambioTipo = c.cambios.filter(function (x) { return x.campo === 'tipo'; })[0];
                var tipoAntes = tipoActual;
                var tipoDespues = cambioTipo ? cambioTipo.nuevo : tipoAntes;
                tipoActual = tipoDespues;
                var filas = c.cambios.map(function (x) {
                    var ant = x.campo === 'cantidad' ? firmado(efecto(tipoAntes, x.anterior), true) : valorCampo(x.campo, x.anterior);
                    var nue = x.campo === 'cantidad' ? firmado(efecto(tipoDespues, x.nuevo), true) : valorCampo(x.campo, x.nuevo);
                    return '<div class="mov-change"><span>' + esc(CAMPOS[x.campo] || x.campo) + '</span><span><span class="mov-old">' + esc(ant) +
                        '</span> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i> <strong>' + esc(nue) + '</strong></span></div>';
                }).join('');
                return '<li><span class="mov-step">' + c.id + '</span><p class="mov-meta">' + esc(fechaHora(c.fechaHora)) + ' · ' + esc(c.responsable) + '</p>' +
                    '<p class="mov-motivo">Motivo: ' + esc(c.motivo) + '</p><div class="mov-changes">' + filas + '</div></li>';
            }).join('') + '</ol>';
        }

        function renderizar(m) {
            var e = efecto(m.tipo, m.cantidad);
            var o = m.original;
            var n = m.correcciones.length;
            var codigoHtml = m.codigoBarras ? '<code>' + esc(m.codigoBarras) + '</code>' : '<span>Sin código</span>';
            var muestraLoteOriginal = Boolean(o.lote) || (api.tipos[o.tipo] && api.tipos[o.tipo].esBaja);
            var botonEditar = api.edicionHabilitada
                ? '<a class="mov-btn mov-btn-gold" href="' + esc(urlEdicion(m.id, lq)) + '"><i class="fa-solid fa-pencil" aria-hidden="true"></i> Editar movimiento</a>'
                : '<button type="button" class="mov-btn" id="mov-btn-editar-bloqueado" disabled aria-disabled="true" title="La edición de movimientos se habilitará en la HU-24"><i class="fa-solid fa-lock" aria-hidden="true"></i> Editar movimiento</button>';
            contenido.hidden = false;
            if (qs('corregido') === '1') pegarEstado(estado, 'ok', '<span>Corrección confirmada. El detalle, el saldo y el historial se actualizaron.</span>');
            else estado.hidden = true;

            contenido.innerHTML =
                '<div class="mov-detail-head"><div><h2>Movimiento N.º ' + m.id + '</h2>' + badgeTipo(m.tipo) +
                    (n ? ' <span class="mov-badge mov-badge-corregido">Corregido ' + n + (n === 1 ? ' vez' : ' veces') + '</span>' : '') + '</div>' +
                    botonEditar + '</div>' +
                '<div class="mov-detail-grid"><div>' +
                    '<section class="mov-card"><h3>Datos vigentes</h3><dl class="mov-dl">' +
                        '<div><dt>Producto</dt><dd>' + esc(m.producto) + ' · ' + codigoHtml + '</dd></div>' +
                        '<div><dt>Tipo</dt><dd>' + esc(etiquetaTipo(m.tipo)) + '</dd></div>' +
                        '<div><dt>Cantidad vigente</dt><dd class="mov-qty ' + claseSigno(e) + '">' + esc(unidades(e)) + '</dd></div>' +
                        '<div><dt>Justificación</dt><dd>' + esc(m.justificacion) + '</dd></div>' +
                        '<div><dt>Lote</dt><dd>' + esc(m.lote || 'Sin referencia de lote') + '</dd></div>' +
                        '<div><dt>Stock actual del producto</dt><dd>' + esc(unidades(m.stockActual)) + '</dd></div></dl></section>' +
                    '<section class="mov-card"><h3><i class="fa-solid fa-lock" aria-hidden="true"></i> Registro original</h3><p class="mov-sub">No se modifica ni se elimina.</p><dl class="mov-dl">' +
                        '<div><dt>Fecha y hora</dt><dd>' + esc(fechaHora(o.fechaHora || m.fechaHora)) + '</dd></div>' +
                        '<div><dt>Responsable</dt><dd>' + esc(o.responsable || m.responsable) + '</dd></div>' +
                        '<div><dt>Tipo y cantidad</dt><dd>' + esc(etiquetaTipo(o.tipo)) + ' · ' + esc(unidades(efecto(o.tipo, o.cantidad))) + '</dd></div>' +
                        '<div><dt>Justificación original</dt><dd>' + esc(o.justificacion || m.justificacion) + '</dd></div>' +
                        (muestraLoteOriginal ? '<div><dt>Lote original</dt><dd>' + esc(o.lote || 'Sin referencia de lote') + '</dd></div>' : '') +
                        '<div><dt>Saldo anterior</dt><dd>' + esc(unidades(o.saldoAnterior != null ? o.saldoAnterior : m.saldoAnterior)) + '</dd></div>' +
                        '<div><dt>Saldo resultante</dt><dd>' + esc(unidades(o.saldoResultante != null ? o.saldoResultante : m.saldoResultante)) + '</dd></div></dl></section>' +
                '</div><section class="mov-card"><h3>Historial de correcciones</h3>' + historialHtml(m) + '</section></div>';
        }

        async function cargar() {
            estado.hidden = true; contenido.hidden = true;
            pegarEstado(estado, 'loading', '<span>Cargando movimiento...</span>');
            try {
                var m = await api.obtenerMovimiento(id);
                renderizar(m);
            } catch (e) {
                var noExiste = e.codigo === 'NO_ENCONTRADO';
                pegarEstado(estado, 'error', '<span>' + (noExiste ? 'El movimiento solicitado no existe.' : 'No se pudo cargar el movimiento.') + '</span>' +
                    (noExiste ? '' : '<button type="button" class="mov-btn" id="mov-reintentar"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i> Reintentar</button>') +
                    '<a class="mov-btn" href="' + esc(urlListado(lq)) + '">Volver al listado</a>');
                var r = document.getElementById('mov-reintentar');
                if (r) r.addEventListener('click', cargar);
            }
        }
        cargar();
    }

    /* ---------- EDICIÓN (HU-24) ---------- */
    function iniciarEdicion(raiz) {
        var id = raiz.dataset.id;
        var lq = qs('lq');
        var $ = function (s) { return document.getElementById(s); };
        var estado = $('mov-status'), formulario = $('mov-form-wrap');
        var tipo = $('mov-e-tipo'), cantidad = $('mov-e-cantidad'), justificacion = $('mov-e-justificacion'),
            lote = $('mov-e-lote'), motivo = $('mov-e-motivo'), confirmar = $('mov-confirmar'), errorGeneral = $('mov-e-error');
        var movimiento = null, baseMovimiento = null, guardando = false, recargandoConflicto = false, recargaFallida = false;

        $('mov-back').href = urlDetalle(id, lq);
        $('mov-cancelar').href = urlDetalle(id, lq);
        $('mov-motivo-ayuda').textContent = 'Obligatorio. Máximo ' + api.motivoMax + ' caracteres.';
        motivo.maxLength = api.motivoMax + 50; // permite escribir de más para mostrar el error (CL-24.5)

        function error(campo, mensaje) {
            var caja = $('mov-err-' + campo);
            caja.textContent = mensaje || '';
            caja.hidden = !mensaje;
            $('mov-e-' + campo).classList.toggle('is-invalid', !!mensaje);
        }

        function valores() {
            var esBaja = api.tipos[tipo.value].esBaja;
            return { tipo: tipo.value, cantidad: Number(cantidad.value), cantidadTexto: cantidad.value.trim(),
                     justificacion: justificacion.value.trim(), lote: esBaja ? lote.value.trim() : '', motivo: motivo.value.trim() };
        }

        function hayCambios(v) {
            return v.tipo !== movimiento.tipo || v.cantidad !== movimiento.cantidad ||
                v.justificacion !== (movimiento.justificacion || '') || v.lote !== (movimiento.lote || '');
        }

        function actualizarVistaPrevia() {
            var v = valores();
            var esBaja = api.tipos[v.tipo].esBaja;
            $('mov-lote-wrap').classList.toggle('is-disabled', !esBaja);
            lote.disabled = !esBaja;
            var valida = /^[1-9]\d*$/.test(v.cantidadTexto);
            var anterior = efecto(movimiento.tipo, movimiento.cantidad);
            var nuevo = valida ? efecto(v.tipo, v.cantidad) : null;
            $('mov-p-actual').textContent = unidades(movimiento.stockActual);
            $('mov-p-anterior').textContent = unidades(anterior);
            var celdaNueva = $('mov-p-nuevo');
            celdaNueva.textContent = valida ? unidades(nuevo) : '—';
            celdaNueva.className = valida ? claseSigno(nuevo) : '';
            var saldo = valida ? movimiento.stockActual - anterior + nuevo : null;
            var celdaSaldo = $('mov-p-saldo');
            celdaSaldo.textContent = valida ? unidades(saldo) : '—';
            celdaSaldo.classList.toggle('is-negative', valida && saldo < 0);
            var orig = movimiento.original || { tipo: movimiento.tipo, cantidad: movimiento.cantidad, saldoAnterior: movimiento.saldoAnterior, saldoResultante: movimiento.saldoResultante };
            var efectoOrig = efecto(orig.tipo, orig.cantidad);
            $('mov-p-formula').textContent = valida
                ? movimiento.stockActual + ' ' + MENOS + ' (' + firmado(anterior) + ') + (' + firmado(nuevo) + ') = ' + firmado(saldo) +
                  '. Original: ' + etiquetaTipo(orig.tipo) + ' (' + firmado(efectoOrig, true) + ', saldo ' + orig.saldoAnterior + ' → ' + orig.saldoResultante + '); vigente: ' +
                  etiquetaTipo(movimiento.tipo) + ' (' + firmado(anterior, true) + ').'
                : 'Ingresa una cantidad válida para calcular el saldo resultante.';
            var aviso = $('mov-p-negativo');
            aviso.hidden = !(valida && saldo < 0);
            aviso.textContent = valida && saldo < 0 ? 'El saldo resultante sería ' + unidades(saldo) + '. La corrección no se puede guardar.' : '';
        }

        function validar() {
            var v = valores();
            var ok = true;
            error('cantidad', ''); error('justificacion', ''); error('motivo', ''); errorGeneral.hidden = true;
            if (!/^[1-9]\d*$/.test(v.cantidadTexto)) { error('cantidad', 'Ingresa un número entero mayor que cero.'); ok = false; }
            if (!v.justificacion) { error('justificacion', 'La justificación es obligatoria.'); ok = false; }
            if (!v.motivo) { error('motivo', 'Indica el motivo de la corrección.'); ok = false; }
            else if (v.motivo.length > api.motivoMax) { error('motivo', 'El motivo supera el máximo de ' + api.motivoMax + ' caracteres.'); ok = false; }
            if (!ok) return null;
            if (!hayCambios(v)) { mostrarError('No modificaste ningún dato. Realiza un cambio para guardar la corrección.'); return null; }
            var saldo = movimiento.stockActual - efecto(movimiento.tipo, movimiento.cantidad) + efecto(v.tipo, v.cantidad);
            if (saldo < 0) { mostrarError('El saldo resultante sería ' + unidades(saldo) + '. Ajusta la cantidad o el tipo.'); return null; }
            return v;
        }

        function snapshotBase(m) {
            return {
                tipo: m.tipo,
                cantidad: Number(m.cantidad),
                justificacion: m.justificacion || '',
                lote: m.lote || ''
            };
        }

        function mostrarErrorHtml(html) {
            errorGeneral.hidden = false;
            errorGeneral.innerHTML = html;
            errorGeneral.scrollIntoView({ block: 'nearest' });
        }

        function mostrarError(texto) {
            mostrarErrorHtml('<span>' + esc(texto) + '</span>');
        }

        function reconciliarTrasConflicto(editado, nuevoMov, mensajeServidor) {
            var prevBase = baseMovimiento || snapshotBase(movimiento);
            var srv = snapshotBase(nuevoMov);
            var cambioVersion = Number(nuevoMov.version) !== Number(movimiento.version);
            var cambioStock = Number(nuevoMov.stockActual) !== Number(movimiento.stockActual);

            var userChanged = {
                tipo: editado.tipo !== prevBase.tipo,
                cantidad: String(editado.cantidadTexto) !== String(prevBase.cantidad),
                justificacion: editado.justificacion !== prevBase.justificacion,
                lote: editado.lote !== prevBase.lote
            };
            var srvChanged = {
                tipo: srv.tipo !== prevBase.tipo,
                cantidad: srv.cantidad !== prevBase.cantidad,
                justificacion: srv.justificacion !== prevBase.justificacion,
                lote: srv.lote !== prevBase.lote
            };

            var actualizadosDesdeServidor = [];
            var conflictos = [];

            // 1. Tipo
            if (!userChanged.tipo) {
                tipo.value = srv.tipo;
                if (srvChanged.tipo) {
                    actualizadosDesdeServidor.push('Tipo: ' + etiquetaTipo(prevBase.tipo) + ' → ' + etiquetaTipo(srv.tipo));
                }
            } else if (srvChanged.tipo && editado.tipo !== srv.tipo) {
                tipo.value = srv.tipo;
                conflictos.push('Tipo (vigente: ' + etiquetaTipo(srv.tipo) + ', tu edición: ' + etiquetaTipo(editado.tipo) + ')');
            }

            // 2. Cantidad
            if (!userChanged.cantidad) {
                cantidad.value = srv.cantidad;
                if (srvChanged.cantidad) {
                    actualizadosDesdeServidor.push('Cantidad: ' + prevBase.cantidad + ' → ' + srv.cantidad);
                }
            } else if (srvChanged.cantidad && Number(editado.cantidad) !== srv.cantidad) {
                cantidad.value = srv.cantidad;
                conflictos.push('Cantidad (vigente: ' + srv.cantidad + ', tu edición: ' + editado.cantidadTexto + ')');
            }

            // 3. Justificación
            if (!userChanged.justificacion) {
                justificacion.value = srv.justificacion;
                if (srvChanged.justificacion) {
                    actualizadosDesdeServidor.push('Justificación actualizada desde el servidor');
                }
            } else if (srvChanged.justificacion && editado.justificacion !== srv.justificacion) {
                justificacion.value = srv.justificacion;
                conflictos.push('Justificación (vigente: «' + srv.justificacion + '», tu edición: «' + editado.justificacion + '»)');
            }

            // 4. Lote (incluyendo impacto si la reclasificación de tipo afecta al lote)
            var tipoFinalEsBaja = Boolean(api.tipos[tipo.value] && api.tipos[tipo.value].esBaja);
            var srvEsBaja = Boolean(api.tipos[srv.tipo] && api.tipos[srv.tipo].esBaja);
            if (!tipoFinalEsBaja) {
                lote.value = '';
                if (userChanged.lote && !srvEsBaja) {
                    conflictos.push('Lote (el movimiento vigente es ' + etiquetaTipo(srv.tipo) + ' y no admite lote; tu edición era «' + editado.lote + '»)');
                }
            } else if (!userChanged.lote) {
                lote.value = srv.lote;
                if (srvChanged.lote) {
                    actualizadosDesdeServidor.push('Lote: ' + (prevBase.lote || 'Sin lote') + ' → ' + (srv.lote || 'Sin lote'));
                }
            } else if (srvChanged.lote && editado.lote !== srv.lote) {
                lote.value = srv.lote;
                conflictos.push('Lote (vigente: ' + (srv.lote || 'Sin lote') + ', tu edición: ' + (editado.lote || 'Sin lote') + ')');
            }

            movimiento = nuevoMov;
            baseMovimiento = srv;
            recargaFallida = false;
            actualizarVistaPrevia();

            if (conflictos.length) {
                mostrarError('Otro usuario modificó el mismo campo: ' + conflictos.join('; ') + '. Se cargaron los valores vigentes para que revises y ajustes antes de volver a confirmar.');
            } else if (cambioVersion) {
                var detalle = actualizadosDesdeServidor.length ? ' (' + actualizadosDesdeServidor.join(', ') + ')' : '';
                mostrarError('El movimiento pasó a la versión ' + nuevoMov.version + detalle + '. Se actualizaron los campos que no editaste y se conservaron tus cambios; revisa la vista previa y vuelve a confirmar.');
            } else if (cambioStock) {
                mostrarError('El stock actual cambió a ' + unidades(nuevoMov.stockActual) + ' mientras revisabas. Se actualizó la vista previa y se conservaron tus cambios; revisa el saldo resultante y vuelve a confirmar.');
            } else {
                mostrarError(mensajeServidor || 'El movimiento o su saldo cambió mientras revisabas. Revisa la vista previa actualizada y confirma de nuevo.');
            }
        }

        async function recargarTrasConflicto(editado, mensajeServidor) {
            recargandoConflicto = true;
            confirmar.disabled = true;
            confirmar.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Actualizando datos...';
            try {
                var nuevoMov = await api.obtenerMovimiento(id);
                reconciliarTrasConflicto(editado, nuevoMov, mensajeServidor);
                confirmar.disabled = false;
                confirmar.innerHTML = '<i class="fa-solid fa-check" aria-hidden="true"></i> Confirmar corrección';
            } catch (x) {
                recargaFallida = true;
                confirmar.disabled = true;
                confirmar.innerHTML = '<i class="fa-solid fa-check" aria-hidden="true"></i> Confirmar corrección';
                mostrarErrorHtml('<span>No se pudieron recargar los datos vigentes tras el conflicto. Actualiza el formulario antes de reintentar.</span>' +
                    '<button type="button" class="mov-btn" id="mov-recargar-conflicto"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i> Recargar datos vigentes</button>');
                var btnRecargar = $('mov-recargar-conflicto');
                if (btnRecargar) {
                    btnRecargar.addEventListener('click', function () {
                        recargarTrasConflicto(editado, mensajeServidor);
                    });
                }
            } finally {
                recargandoConflicto = false;
            }
        }

        function llenar() {
            baseMovimiento = snapshotBase(movimiento);
            recargaFallida = false;
            $('mov-e-producto').textContent = movimiento.producto;
            $('mov-e-codigo').textContent = movimiento.codigoBarras || 'Sin código';
            $('mov-e-numero').textContent = movimiento.id;
            $('mov-e-fecha').textContent = fechaHora(movimiento.fechaHora);
            $('mov-e-responsable').textContent = movimiento.responsable;
            tipo.value = movimiento.tipo; cantidad.value = movimiento.cantidad;
            justificacion.value = movimiento.justificacion || ''; lote.value = movimiento.lote || ''; motivo.value = '';
            actualizarVistaPrevia();
            if (!api.edicionHabilitada) {
                [tipo, cantidad, justificacion, lote, motivo].forEach(function (el) { el.disabled = true; });
                confirmar.disabled = true;
                mostrarError('La edición de movimientos estará disponible en la HU-24. Esta pantalla opera en modo solo lectura.');
            }
        }

        async function cargar() {
            formulario.hidden = true;
            pegarEstado(estado, 'loading', '<span>Cargando movimiento...</span>');
            try {
                movimiento = await api.obtenerMovimiento(id);
                estado.hidden = true; formulario.hidden = false;
                llenar();
            } catch (e) {
                var noExiste = e.codigo === 'NO_ENCONTRADO';
                pegarEstado(estado, 'error', '<span>' + (noExiste ? 'El movimiento solicitado no existe.' : 'No se pudo cargar el movimiento.') + '</span>' +
                    (noExiste ? '' : '<button type="button" class="mov-btn" id="mov-reintentar"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i> Reintentar</button>') +
                    '<a class="mov-btn" href="' + esc(urlListado(lq)) + '">Volver al listado</a>');
                var r = $('mov-reintentar');
                if (r) r.addEventListener('click', cargar);
            }
        }

        async function guardar() {
            if (!api.edicionHabilitada) {
                mostrarError('La edición de movimientos aún no está habilitada (HU-24). No se guardó ningún cambio.');
                return;
            }
            if (guardando || recargandoConflicto || recargaFallida) return;
            var v = validar();
            if (!v) return;
            guardando = true; confirmar.disabled = true;
            confirmar.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Guardando...';
            try {
                await api.guardarCorreccion(id, {
                    tipo: v.tipo,
                    cantidad: v.cantidad,
                    justificacion: v.justificacion,
                    lote: v.lote,
                    motivo: v.motivo,
                    version: movimiento.version,
                    stockActualEsperado: movimiento.stockActual
                });
                window.location.href = urlDetalle(id, lq, 'corregido=1');
            } catch (e) {
                guardando = false;
                if (e.codigo === 'CONFLICTO') {
                    await recargarTrasConflicto(v, e.message);
                } else {
                    confirmar.disabled = false;
                    confirmar.innerHTML = '<i class="fa-solid fa-check" aria-hidden="true"></i> Confirmar corrección';
                    if (e.codigo === 'NO_ENCONTRADO') {
                        mostrarError('El movimiento ya no existe. Vuelve al listado.');
                    } else if (e.codigo === 'VALIDACION') {
                        mostrarError(e.message);
                    } else {
                        mostrarError('No se pudo guardar la corrección. No se aplicó ningún cambio; puedes reintentar.');
                    }
                }
            }
        }

        [tipo, cantidad, justificacion, lote, motivo].forEach(function (el) { el.addEventListener('input', actualizarVistaPrevia); });
        $('mov-form').addEventListener('submit', function (ev) { ev.preventDefault(); guardar(); });
        cargar();
    }

    var listado = document.getElementById('mov-list');
    var detalle = document.getElementById('mov-detail');
    var edicion = document.getElementById('mov-edit');
    if (listado) iniciarListado(listado);
    if (detalle) iniciarDetalle(detalle);
    if (edicion) iniciarEdicion(edicion);
});