document.addEventListener('DOMContentLoaded', function () {

    function exportarPdf(tipo, params) {
        var query = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
        var url = API_URL + '/reportes/exportar-pdf?tipo=' + encodeURIComponent(tipo) + (query ? '&' + query : '');
        window.location.href = url;
    }

    function exportarExcel(tipo, params) {
        var query = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
        var url = API_URL + '/reportes/exportar-excel?tipo=' + encodeURIComponent(tipo) + (query ? '&' + query : '');
        window.location.href = url;
    }

    function enviarReporteEmail(tipo, params, nombreReporte) {
        var overlay = document.createElement('div');
        overlay.className = 'modal-overlay';

        var container = document.createElement('div');
        container.className = 'modal-container modal-form';
        container.style.maxWidth = '420px';

        var header = document.createElement('div');
        header.className = 'modal-form-header';
        var h3 = document.createElement('h3');
        h3.innerHTML = '<i class="fa-solid fa-envelope" style="color: #d4af37;"></i> Enviar Reporte por Email';
        header.appendChild(h3);
        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'modal-close-btn';
        closeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
        closeBtn.addEventListener('click', function () { closeModal(); });
        header.appendChild(closeBtn);

        var body = document.createElement('div');
        body.className = 'modal-form-body';
        body.style.padding = '20px';

        var desc = document.createElement('p');
        desc.style.cssText = 'color: #64748b; margin: 0 0 16px; font-size: 14px;';
        desc.textContent = 'Se enviará el reporte "' + nombreReporte + '" como archivo PDF adjunto.';
        body.appendChild(desc);

        var form = document.createElement('form');
        form.id = 'form_email_reporte';

        var group = document.createElement('div');
        group.className = 'form-group col-12';
        var label = document.createElement('label');
        label.setAttribute('for', 'email_reporte');
        label.innerHTML = 'Correo Destino <span class="required">*</span>';
        group.appendChild(label);
        var input = document.createElement('input');
        input.type = 'email';
        input.id = 'email_reporte';
        input.name = 'email';
        input.placeholder = 'correo@ejemplo.com';
        input.required = true;
        input.style.cssText = 'width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px;';
        group.appendChild(input);
        form.appendChild(group);

        var errorDiv = document.createElement('div');
        errorDiv.className = 'form-error-msg';
        errorDiv.style.display = 'none';
        form.appendChild(errorDiv);

        var actions = document.createElement('div');
        actions.className = 'form-actions col-12';
        actions.style.cssText = 'display: flex; gap: 10px; justify-content: flex-end; padding-top: 10px;';
        var submitBtn = document.createElement('button');
        submitBtn.type = 'submit';
        submitBtn.className = 'btn btn-gold';
        submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Enviar';
        actions.appendChild(submitBtn);
        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'btn btn-cancel';
        cancelBtn.textContent = 'Cancelar';
        cancelBtn.addEventListener('click', function () { closeModal(); });
        actions.appendChild(cancelBtn);
        form.appendChild(actions);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var email = input.value.trim();
            if (!email) return;

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando...';
            errorDiv.style.display = 'none';

            var bodyData = { tipo: tipo, email: email };
            var query = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
            if (query) {
                query.split('&').forEach(function (pair) {
                    var kv = pair.split('=');
                    if (kv[0]) bodyData[kv[0]] = decodeURIComponent(kv[1] || '');
                });
            }

            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

            fetch(API_URL + '/reportes/enviar-reporte', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify(bodyData)
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                closeModal();
                if (res.status) {
                    Modal.success('Enviado', res.message || 'Reporte enviado correctamente a ' + email + '.');
                } else {
                    Modal.error('Error', res.message || 'No se pudo enviar el correo.');
                }
            })
            .catch(function () {
                closeModal();
                Modal.error('Error', 'Error de conexion al enviar el correo.');
            });
        });

        body.appendChild(form);
        container.appendChild(header);
        container.appendChild(body);
        overlay.appendChild(container);
        document.body.appendChild(overlay);

        setTimeout(function () { overlay.classList.add('modal-active'); }, 10);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
        setTimeout(function () { input.focus(); }, 100);

        function closeModal() {
            overlay.classList.remove('modal-active');
            setTimeout(function () { overlay.remove(); }, 300);
        }
    }

    document.getElementById('btn_exportar_ventas')?.addEventListener('click', function () {
        exportarPdf('ventas', typeof exportParamsVentas !== 'undefined' ? exportParamsVentas : {});
    });

    document.getElementById('btn_excel_ventas')?.addEventListener('click', function () {
        exportarExcel('ventas', typeof exportParamsVentas !== 'undefined' ? exportParamsVentas : {});
    });

    document.getElementById('btn_email_ventas')?.addEventListener('click', function () {
        enviarReporteEmail('ventas', typeof exportParamsVentas !== 'undefined' ? exportParamsVentas : {}, 'Ventas por Periodo');
    });

    document.getElementById('btn_exportar_masVendidos')?.addEventListener('click', function () {
        exportarPdf('productos-mas-vendidos', typeof exportParamsMasVendidos !== 'undefined' ? exportParamsMasVendidos : {});
    });

    document.getElementById('btn_excel_masVendidos')?.addEventListener('click', function () {
        exportarExcel('productos-mas-vendidos', typeof exportParamsMasVendidos !== 'undefined' ? exportParamsMasVendidos : {});
    });

    document.getElementById('btn_email_masVendidos')?.addEventListener('click', function () {
        enviarReporteEmail('productos-mas-vendidos', typeof exportParamsMasVendidos !== 'undefined' ? exportParamsMasVendidos : {}, 'Productos Mas Vendidos');
    });

    document.getElementById('btn_exportar_menosVendidos')?.addEventListener('click', function () {
        exportarPdf('productos-menos-vendidos', typeof exportParamsMenosVendidos !== 'undefined' ? exportParamsMenosVendidos : {});
    });

    document.getElementById('btn_excel_menosVendidos')?.addEventListener('click', function () {
        exportarExcel('productos-menos-vendidos', typeof exportParamsMenosVendidos !== 'undefined' ? exportParamsMenosVendidos : {});
    });

    document.getElementById('btn_email_menosVendidos')?.addEventListener('click', function () {
        enviarReporteEmail('productos-menos-vendidos', typeof exportParamsMenosVendidos !== 'undefined' ? exportParamsMenosVendidos : {}, 'Productos Menos Vendidos');
    });

    document.getElementById('btn_exportar_inventario')?.addEventListener('click', function () {
        exportarPdf('inventario', typeof exportParamsInventario !== 'undefined' ? exportParamsInventario : {});
    });

    document.getElementById('btn_excel_inventario')?.addEventListener('click', function () {
        exportarExcel('inventario', typeof exportParamsInventario !== 'undefined' ? exportParamsInventario : {});
    });

    document.getElementById('btn_email_inventario')?.addEventListener('click', function () {
        enviarReporteEmail('inventario', typeof exportParamsInventario !== 'undefined' ? exportParamsInventario : {}, 'Inventario');
    });

    document.getElementById('btn_exportar_clientes')?.addEventListener('click', function () {
        exportarPdf('clientes', typeof exportParamsClientes !== 'undefined' ? exportParamsClientes : {});
    });

    document.getElementById('btn_excel_clientes')?.addEventListener('click', function () {
        exportarExcel('clientes', typeof exportParamsClientes !== 'undefined' ? exportParamsClientes : {});
    });

    document.getElementById('btn_email_clientes')?.addEventListener('click', function () {
        enviarReporteEmail('clientes', typeof exportParamsClientes !== 'undefined' ? exportParamsClientes : {}, 'Clientes');
    });

    document.getElementById('btn_exportar_vendedores')?.addEventListener('click', function () {
        exportarPdf('vendedores', typeof exportParamsVendedores !== 'undefined' ? exportParamsVendedores : {});
    });

    document.getElementById('btn_excel_vendedores')?.addEventListener('click', function () {
        exportarExcel('vendedores', typeof exportParamsVendedores !== 'undefined' ? exportParamsVendedores : {});
    });

    document.getElementById('btn_email_vendedores')?.addEventListener('click', function () {
        enviarReporteEmail('vendedores', typeof exportParamsVendedores !== 'undefined' ? exportParamsVendedores : {}, 'Vendedores');
    });

    document.getElementById('btn_exportar_categorias')?.addEventListener('click', function () {
        exportarPdf('categorias', typeof exportParamsCategorias !== 'undefined' ? exportParamsCategorias : {});
    });

    document.getElementById('btn_excel_categorias')?.addEventListener('click', function () {
        exportarExcel('categorias', typeof exportParamsCategorias !== 'undefined' ? exportParamsCategorias : {});
    });

    document.getElementById('btn_email_categorias')?.addEventListener('click', function () {
        enviarReporteEmail('categorias', typeof exportParamsCategorias !== 'undefined' ? exportParamsCategorias : {}, 'Categorias');
    });

    document.getElementById('btn_exportar_comprobantes')?.addEventListener('click', function () {
        exportarPdf('comprobantes', typeof exportParamsComprobantes !== 'undefined' ? exportParamsComprobantes : {});
    });

    document.getElementById('btn_excel_comprobantes')?.addEventListener('click', function () {
        exportarExcel('comprobantes', typeof exportParamsComprobantes !== 'undefined' ? exportParamsComprobantes : {});
    });

    document.getElementById('btn_email_comprobantes')?.addEventListener('click', function () {
        enviarReporteEmail('comprobantes', typeof exportParamsComprobantes !== 'undefined' ? exportParamsComprobantes : {}, 'Comprobantes');
    });

    document.getElementById('btn_exportar_metodos')?.addEventListener('click', function () {
        exportarPdf('metodos-pago', typeof exportParamsMetodos !== 'undefined' ? exportParamsMetodos : {});
    });

    document.getElementById('btn_excel_metodos')?.addEventListener('click', function () {
        exportarExcel('metodos-pago', typeof exportParamsMetodos !== 'undefined' ? exportParamsMetodos : {});
    });

    document.getElementById('btn_email_metodos')?.addEventListener('click', function () {
        enviarReporteEmail('metodos-pago', typeof exportParamsMetodos !== 'undefined' ? exportParamsMetodos : {}, 'Metodos de Pago');
    });

    document.getElementById('btn_exportar_resumen')?.addEventListener('click', function () {
        exportarPdf('resumen', typeof exportParamsResumen !== 'undefined' ? exportParamsResumen : {});
    });

    document.getElementById('btn_excel_resumen')?.addEventListener('click', function () {
        exportarExcel('resumen', typeof exportParamsResumen !== 'undefined' ? exportParamsResumen : {});
    });

    document.getElementById('btn_email_resumen')?.addEventListener('click', function () {
        enviarReporteEmail('resumen', typeof exportParamsResumen !== 'undefined' ? exportParamsResumen : {}, 'Resumen Ejecutivo');
    });

    function renderLineChart(containerId, data, labelX, labelY) {
        var container = document.getElementById(containerId);
        if (!container || !data || data.length === 0) return;

        var canvas = document.createElement('canvas');
        canvas.width = container.offsetWidth || 800;
        canvas.height = 300;
        canvas.style.width = '100%';
        canvas.style.height = '300px';
        container.appendChild(canvas);

        var ctx = canvas.getContext('2d');
        var padding = { top: 30, right: 30, bottom: 50, left: 80 };
        var w = canvas.width - padding.left - padding.right;
        var h = canvas.height - padding.top - padding.bottom;

        var values = data.map(function (d) { return parseFloat(d[labelY]) || 0; });
        var maxVal = Math.max.apply(null, values);
        if (maxVal === 0) maxVal = 1;
        maxVal = maxVal * 1.1;

        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        ctx.strokeStyle = '#e2e8f0';
        ctx.lineWidth = 1;
        for (var i = 0; i <= 5; i++) {
            var y = padding.top + h - (h * i / 5);
            ctx.beginPath();
            ctx.moveTo(padding.left, y);
            ctx.lineTo(padding.left + w, y);
            ctx.stroke();

            ctx.fillStyle = '#94a3b8';
            ctx.font = '11px Outfit, sans-serif';
            ctx.textAlign = 'right';
            ctx.fillText('S/. ' + (maxVal * i / 5).toFixed(0), padding.left - 8, y + 4);
        }

        var stepX = w / Math.max(data.length - 1, 1);

        ctx.beginPath();
        ctx.strokeStyle = '#d4af37';
        ctx.lineWidth = 2.5;
        ctx.lineJoin = 'round';

        data.forEach(function (d, idx) {
            var x = padding.left + idx * stepX;
            var val = parseFloat(d[labelY]) || 0;
            var y = padding.top + h - (val / maxVal) * h;
            if (idx === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.stroke();

        var gradient = ctx.createLinearGradient(0, padding.top, 0, padding.top + h);
        gradient.addColorStop(0, 'rgba(212, 175, 55, 0.25)');
        gradient.addColorStop(1, 'rgba(212, 175, 55, 0.02)');

        ctx.beginPath();
        data.forEach(function (d, idx) {
            var x = padding.left + idx * stepX;
            var val = parseFloat(d[labelY]) || 0;
            var y = padding.top + h - (val / maxVal) * h;
            if (idx === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.lineTo(padding.left + (data.length - 1) * stepX, padding.top + h);
        ctx.lineTo(padding.left, padding.top + h);
        ctx.closePath();
        ctx.fillStyle = gradient;
        ctx.fill();

        data.forEach(function (d, idx) {
            var x = padding.left + idx * stepX;
            var val = parseFloat(d[labelY]) || 0;
            var y = padding.top + h - (val / maxVal) * h;

            ctx.beginPath();
            ctx.arc(x, y, 4, 0, Math.PI * 2);
            ctx.fillStyle = '#d4af37';
            ctx.fill();
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 2;
            ctx.stroke();
        });

        data.forEach(function (d, idx) {
            var x = padding.left + idx * stepX;
            var dia = d['dia'] || '';
            if (dia.length > 5) dia = dia.substring(5);
            ctx.fillStyle = '#64748b';
            ctx.font = '10px Outfit, sans-serif';
            ctx.textAlign = 'center';
            ctx.save();
            ctx.translate(x, padding.top + h + 14);
            ctx.rotate(data.length > 10 ? -Math.PI / 4 : 0);
            ctx.fillText(dia, 0, 0);
            ctx.restore();
        });

        ctx.fillStyle = '#1e293b';
        ctx.font = 'bold 12px Outfit, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('S/. ' + values.reduce(function (a, b) { return a + b; }, 0).toFixed(2) + ' total', padding.left + w / 2, padding.top - 10);
    }

    function renderBarChartH(containerId, data, labelKey, labelValue) {
        var container = document.getElementById(containerId);
        if (!container || !data || data.length === 0) return;

        var canvas = document.createElement('canvas');
        canvas.width = container.offsetWidth || 800;
        canvas.height = Math.max(200, data.length * 45 + 60);
        canvas.style.width = '100%';
        canvas.style.height = canvas.height + 'px';
        container.appendChild(canvas);

        var ctx = canvas.getContext('2d');
        var padding = { top: 20, right: 30, bottom: 30, left: 150 };
        var w = canvas.width - padding.left - padding.right;
        var h = canvas.height - padding.top - padding.bottom;

        var values = data.map(function (d) { return parseFloat(d[labelValue]) || 0; });
        var maxVal = Math.max.apply(null, values);
        if (maxVal === 0) maxVal = 1;

        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        var barH = Math.min(30, (h / data.length) * 0.6);
        var gap = h / data.length;

        data.forEach(function (d, idx) {
            var val = parseFloat(d[labelValue]) || 0;
            var barW = (val / maxVal) * w;
            var y = padding.top + idx * gap + (gap - barH) / 2;

            ctx.fillStyle = '#64748b';
            ctx.font = '12px Outfit, sans-serif';
            ctx.textAlign = 'right';
            ctx.fillText(d[labelKey], padding.left - 10, y + barH / 2 + 4);

            ctx.fillStyle = '#dbeafe';
            ctx.beginPath();
            ctx.roundRect(padding.left, y, w, barH, 4);
            ctx.fill();

            ctx.fillStyle = '#d4af37';
            ctx.beginPath();
            ctx.roundRect(padding.left, y, Math.max(barW, 2), barH, 4);
            ctx.fill();

            ctx.fillStyle = '#1e293b';
            ctx.font = 'bold 11px Outfit, sans-serif';
            ctx.textAlign = 'left';
            ctx.fillText('S/. ' + val.toFixed(2), padding.left + barW + 8, y + barH / 2 + 4);
        });
    }

    if (typeof chartDataVentas !== 'undefined' && chartDataVentas.length > 0) {
        renderLineChart('chart_ventas_dia', chartDataVentas, 'dia', 'ingresos');
    }

    if (typeof chartDataCategorias !== 'undefined' && chartDataCategorias.length > 0) {
        renderBarChartH('chart_categorias_barras', chartDataCategorias, 'categoria', 'ingresos');
    }

});
