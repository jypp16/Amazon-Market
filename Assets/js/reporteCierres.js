document.addEventListener('DOMContentLoaded', () => {
    const config = window.CAJA_REPORTES_CONFIG || {};
    const ApiClient = window.Api || (typeof Api !== 'undefined' ? Api : null);
    if (!ApiClient) {
        return;
    }

    function unwrapApi(response, fallbackMsg) {
        if (!response) {
            return null;
        }
        const body = response.data && typeof response.data === 'object' ? response.data : {};
        if (!response.ok || body.status === false) {
            const err = new Error(body.message || fallbackMsg || 'Error al consultar el servicio.');
            err.status = response.status || 0;
            err.code = body.code || '';
            throw err;
        }
        return body.data ?? null;
    }

    const baseUrl = String(config.baseUrl || '').replace(/\/$/, '');

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatCurrency(value) {
        const num = Number(value);
        if (!Number.isFinite(num)) {
            return 'S/ 0.00';
        }
        return `S/ ${num.toFixed(2)}`;
    }

    function formatSignedCurrency(value) {
        const num = Number(value);
        if (!Number.isFinite(num)) {
            return 'S/ 0.00';
        }
        const sign = num > 0 ? '+' : (num < 0 ? '-' : '');
        return `${sign}S/ ${Math.abs(num).toFixed(2)}`;
    }

    function formatDateTime(iso) {
        if (!iso) {
            return '—';
        }
        const d = new Date(iso);
        if (Number.isNaN(d.getTime())) {
            return String(iso);
        }
        return d.toLocaleString('es-PE', {
            timeZone: 'America/Lima',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        });
    }

    function showFeedback(el, type, message) {
        if (!el) {
            return;
        }
        if (!message) {
            el.style.display = 'none';
            el.textContent = '';
            el.className = 'close-alert';
            return;
        }
        el.className = `close-alert close-alert-${type || 'info'}`;
        el.textContent = message;
        el.style.display = 'block';
    }

    function buildQueryString(params) {
        const qs = new URLSearchParams();
        Object.entries(params).forEach(([key, val]) => {
            if (val !== undefined && val !== null && String(val).trim() !== '') {
                qs.set(key, String(val).trim());
            }
        });
        return qs.toString();
    }

    if (config.mode === 'list') {
        initListMode();
    } else if (config.mode === 'detail') {
        initDetailMode();
    }

    async function initListMode() {
        const feedbackEl = document.getElementById('closures_feedback');
        const formEl = document.getElementById('closures_filter_form');
        const fromEl = document.getElementById('closures_from');
        const toEl = document.getElementById('closures_to');
        const turnEl = document.getElementById('closures_turn');
        const sellerEl = document.getElementById('closures_seller');
        const resetBtn = document.getElementById('closures_reset_btn');
        const tbodyEl = document.getElementById('closures_tbody');
        const totalClosuresEl = document.getElementById('closures_total_closures');
        const totalExpectedEl = document.getElementById('closures_total_expected');
        const totalCountedEl = document.getElementById('closures_total_counted');
        const totalDiffEl = document.getElementById('closures_total_diff');
        const paginationInfoEl = document.getElementById('closures_pagination_info');
        const prevBtn = document.getElementById('closures_prev_btn');
        const nextBtn = document.getElementById('closures_next_btn');
        const exportPdfBtn = document.getElementById('closures_export_pdf');
        const exportExcelBtn = document.getElementById('closures_export_excel');

        const state = {
            page: 1,
            pageSize: 20,
            snapshotId: null,
            totalPages: 1,
            appliedFilters: null,
            ready: false,
            downloading: false,
        };

        await loadFilterOptions();
        state.appliedFilters = captureFilters();
        await loadClosures(true);

        if (formEl) {
            formEl.addEventListener('submit', async (e) => {
                e.preventDefault();
                state.page = 1;
                state.snapshotId = null;
                state.appliedFilters = captureFilters();
                state.ready = false;
                updateExportButtons();
                await loadClosures(false);
            });
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', async () => {
                if (fromEl) fromEl.value = config.defaultFrom || '';
                if (toEl) toEl.value = config.defaultTo || '';
                if (turnEl) turnEl.value = '';
                if (sellerEl) sellerEl.value = '';
                state.page = 1;
                state.snapshotId = null;
                state.appliedFilters = captureFilters();
                state.ready = false;
                updateExportButtons();
                await loadClosures(false);
            });
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', async () => {
                if (state.page > 1) {
                    state.page -= 1;
                    await loadClosures(false);
                }
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', async () => {
                if (state.page < state.totalPages) {
                    state.page += 1;
                    await loadClosures(false);
                }
            });
        }

        if (exportPdfBtn) exportPdfBtn.addEventListener('click', () => runExport('pdf'));
        if (exportExcelBtn) exportExcelBtn.addEventListener('click', () => runExport('excel'));

        function captureFilters() {
            return {
                desde: fromEl ? fromEl.value : '',
                hasta: toEl ? toEl.value : '',
                turno_id: turnEl ? turnEl.value : '',
                vendedor_id: sellerEl ? sellerEl.value : '',
            };
        }

        function updateExportButtons() {
            const disabled = !state.ready || !state.snapshotId || state.downloading;
            if (exportPdfBtn) exportPdfBtn.disabled = disabled;
            if (exportExcelBtn) exportExcelBtn.disabled = disabled;
        }

        async function runExport(format) {
            if (state.downloading || !state.ready || !state.snapshotId) return;
            state.downloading = true;
            updateExportButtons();
            showFeedback(feedbackEl, 'info', `Generando archivo ${format.toUpperCase()}…`);
            const query = buildQueryString({ ...state.appliedFilters, snapshot_id: state.snapshotId });
            try {
                const result = format === 'pdf'
                    ? await ApiClient.download(`reportes/exportar-pdf?tipo=cierres-caja&${query}`, 'Reporte_Cierres_Caja.pdf')
                    : await ApiClient.downloadExcel(`reportes/exportar-excel?tipo=cierres-caja&${query}`, 'Reporte_Cierres_Caja.xlsx');
                if (!result || !result.ok) {
                    const body = result && result.data ? result.data : {};
                    if (body.code === 'SNAPSHOT_NOT_FOUND') {
                        state.snapshotId = null;
                        state.ready = false;
                        showFeedback(feedbackEl, 'info', 'El snapshot venció o no incluye el detalle requerido. Conservé los filtros; consulta de nuevo para generar un archivo actualizado.');
                    } else {
                        showFeedback(feedbackEl, 'error', body.message || `No se pudo generar el archivo ${format.toUpperCase()}. Puedes reintentar.`);
                    }
                    return;
                }
                showFeedback(feedbackEl, 'success', `${format.toUpperCase()} recibido y validado: ${result.filename}`);
            } catch (error) {
                showFeedback(feedbackEl, 'error', (error && error.message) || `No se pudo descargar el archivo ${format.toUpperCase()}. Puedes reintentar.`);
            } finally {
                state.downloading = false;
                updateExportButtons();
            }
        }

        async function loadFilterOptions() {
            try {
                const [turnsResp, sellersResp] = await Promise.all([
                    ApiClient.get('turnos').catch(() => null),
                    ApiClient.get('caja/consulta/vendedores').catch(() => null),
                ]);

                const turnsPayload = turnsResp && turnsResp.ok && turnsResp.data ? turnsResp.data.data : null;
                const shifts = Array.isArray(turnsPayload)
                    ? turnsPayload
                    : (turnsPayload && (turnsPayload.shifts || turnsPayload.turnos) ? (turnsPayload.shifts || turnsPayload.turnos) : []);

                if (turnEl && Array.isArray(shifts)) {
                    const current = turnEl.value;
                    turnEl.innerHTML = '<option value="">Todos los turnos</option>';
                    shifts.forEach((shift) => {
                        const opt = document.createElement('option');
                        opt.value = String(shift.id);
                        opt.textContent = shift.nombre || shift.name || `Turno #${shift.id}`;
                        turnEl.appendChild(opt);
                    });
                    turnEl.value = current;
                }

                const sellersPayload = sellersResp && sellersResp.ok && sellersResp.data ? sellersResp.data.data : null;
                const sellers = Array.isArray(sellersPayload)
                    ? sellersPayload
                    : (sellersPayload && Array.isArray(sellersPayload.sellers) ? sellersPayload.sellers : []);

                if (sellerEl && Array.isArray(sellers)) {
                    const current = sellerEl.value;
                    sellerEl.innerHTML = '<option value="">Todos los vendedores</option>';
                    sellers.forEach((seller) => {
                        const opt = document.createElement('option');
                        opt.value = String(seller.id);
                        opt.textContent = seller.name || seller.nombre || seller.email || `Vendedor #${seller.id}`;
                        sellerEl.appendChild(opt);
                    });
                    sellerEl.value = current;
                }
            } catch (_e) {
                // Los filtros siguen funcionando aunque falle una lista auxiliar
            }
        }

        async function loadClosures(isInitial) {
            if (!isInitial) {
                showFeedback(feedbackEl, '', '');
            }
            if (tbodyEl) {
                tbodyEl.innerHTML = '<tr><td colspan="9" class="close-empty">Cargando cierres de caja...</td></tr>';
            }

            const params = {
                ...(state.appliedFilters || captureFilters()),
                page: state.page,
                page_size: state.pageSize,
            };
            if (state.snapshotId) {
                params.snapshot_id = state.snapshotId;
            }

            const qs = buildQueryString(params);
            try {
                const response = await ApiClient.get(`reportes/cierres-caja${qs ? `?${qs}` : ''}`);
                const data = unwrapApi(response, 'No se pudo consultar el reporte de cierres de caja.');
                if (!data) {
                    return;
                }
                state.snapshotId = data.snapshot_id || state.snapshotId;
                state.ready = Boolean(state.snapshotId);
                updateExportButtons();

                renderTotals(data.totals || {});
                renderRows(Array.isArray(data.closures) ? data.closures : []);
                renderPagination(data.pagination || {});
            } catch (error) {
                if (error && error.code === 'SNAPSHOT_NOT_FOUND') {
                    state.snapshotId = null;
                    state.ready = false;
                    updateExportButtons();
                    showFeedback(
                        feedbackEl,
                        'info',
                        'La instantánea del reporte expiró. Conservé los filtros; consulta de nuevo para obtener una captura actualizada.'
                    );
                    return;
                }

                showFeedback(
                    feedbackEl,
                    'error',
                    (error && error.message) || 'No se pudo consultar el reporte de cierres de caja.'
                );
                if (tbodyEl) {
                    tbodyEl.innerHTML = '<tr><td colspan="9" class="close-empty">No se pudieron cargar los cierres.</td></tr>';
                }
            }
        }

        function renderTotals(totals) {
            if (totalClosuresEl) {
                totalClosuresEl.textContent = String(totals.total_closures ?? 0);
            }
            if (totalExpectedEl) {
                totalExpectedEl.textContent = formatCurrency(totals.expected_cash_total ?? totals.expected_total ?? 0);
            }
            if (totalCountedEl) {
                totalCountedEl.textContent = formatCurrency(totals.counted_cash_total ?? totals.counted_total ?? 0);
            }
            if (totalDiffEl) {
                totalDiffEl.textContent = formatSignedCurrency(totals.closing_difference_net ?? 0);
            }
        }

        function renderRows(closures) {
            if (!tbodyEl) {
                return;
            }
            if (!closures.length) {
                tbodyEl.innerHTML = '<tr><td colspan="9" class="close-empty">No se encontraron cierres para los filtros seleccionados.</td></tr>';
                return;
            }

            tbodyEl.innerHTML = closures.map((c) => {
                const sessionId = c.session_id || c.id;
                const detailHref = `${baseUrl}/Reporte/detalleCierreCaja/${encodeURIComponent(String(sessionId))}`;
                const closedText = c.closed_at ? formatDateTime(c.closed_at) : `${escapeHtml(c.closing_date || '')} ${escapeHtml(c.closing_time || '')}`.trim();
                const openDiffText = c.opening_difference === null || c.opening_difference === undefined
                    ? 'No aplica'
                    : formatSignedCurrency(c.opening_difference);
                const badges = [];
                if (c.has_omitted_withdrawals) {
                    badges.push('<span class="close-tag">Retiro omitido</span>');
                }
                if (c.has_early_close_authorization) {
                    badges.push('<span class="close-tag">Cierre autorizado</span>');
                }

                return `
                    <tr>
                        <td>${escapeHtml(closedText)}</td>
                        <td>${escapeHtml(c.seller || '—')}</td>
                        <td>${escapeHtml(c.turn || '—')} ${badges.join(' ')}</td>
                        <td>${escapeHtml(formatCurrency(c.opening_fund ?? 0))}</td>
                        <td>${escapeHtml(openDiffText)}</td>
                        <td>${escapeHtml(formatCurrency(c.expected ?? 0))}</td>
                        <td>${escapeHtml(formatCurrency(c.counted ?? 0))}</td>
                        <td>${escapeHtml(formatSignedCurrency(c.closing_difference ?? 0))}</td>
                        <td><a class="close-button close-button-secondary" href="${detailHref}">Ver detalle</a></td>
                    </tr>
                `;
            }).join('');
        }

        function renderPagination(pagination) {
            const page = Number(pagination.page || 1);
            const totalPages = Math.max(1, Number(pagination.total_pages || 1));
            const totalClosures = Number(pagination.total_closures || 0);
            state.page = page;
            state.totalPages = totalPages;

            if (paginationInfoEl) {
                paginationInfoEl.textContent = `Página ${page} de ${totalPages} · ${totalClosures} cierre(s)`;
            }
            if (prevBtn) {
                prevBtn.disabled = page <= 1;
            }
            if (nextBtn) {
                nextBtn.disabled = page >= totalPages;
            }
        }
    }

    async function initDetailMode() {
        const cierreId = Number(config.cierreId || 0);
        const feedbackEl = document.getElementById('closure_detail_feedback');
        const contentEl = document.getElementById('closure_detail_content');
        const titleEl = document.getElementById('closure_detail_title');
        const subtitleEl = document.getElementById('closure_detail_subtitle');
        const prevBtn = document.getElementById('detail_movs_prev_btn');
        const nextBtn = document.getElementById('detail_movs_next_btn');
        const exportPdfBtn = document.getElementById('closure_export_pdf');
        const exportExcelBtn = document.getElementById('closure_export_excel');

        const state = {
            page: 1,
            pageSize: 20,
            totalPages: 1,
            ready: false,
            downloading: false,
        };

        if (!cierreId || cierreId <= 0) {
            showFeedback(feedbackEl, 'error', 'Identificador de cierre inválido.');
            return;
        }

        if (exportPdfBtn) exportPdfBtn.addEventListener('click', () => runDetailExport('pdf'));
        if (exportExcelBtn) exportExcelBtn.addEventListener('click', () => runDetailExport('excel'));

        function updateDetailExportButtons() {
            const disabled = !state.ready || state.downloading;
            if (exportPdfBtn) exportPdfBtn.disabled = disabled;
            if (exportExcelBtn) exportExcelBtn.disabled = disabled;
        }

        async function runDetailExport(format) {
            if (state.downloading || !state.ready) return;
            state.downloading = true;
            updateDetailExportButtons();
            showFeedback(feedbackEl, 'info', `Generando archivo ${format.toUpperCase()}…`);
            const query = buildQueryString({ session_id: cierreId });
            try {
                const result = format === 'pdf'
                    ? await ApiClient.download(`reportes/exportar-pdf?tipo=cierres-caja&${query}`, 'Reporte_Cierre_Caja.pdf')
                    : await ApiClient.downloadExcel(`reportes/exportar-excel?tipo=cierres-caja&${query}`, 'Reporte_Cierre_Caja.xlsx');
                if (!result || !result.ok) {
                    const body = result && result.data ? result.data : {};
                    showFeedback(feedbackEl, 'error', body.message || `No se pudo generar el archivo ${format.toUpperCase()}. Puedes reintentar.`);
                    return;
                }
                showFeedback(feedbackEl, 'success', `${format.toUpperCase()} recibido y validado: ${result.filename}`);
            } catch (error) {
                showFeedback(feedbackEl, 'error', (error && error.message) || `No se pudo descargar el archivo ${format.toUpperCase()}. Puedes reintentar.`);
            } finally {
                state.downloading = false;
                updateDetailExportButtons();
            }
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', async () => {
                if (state.page > 1) {
                    state.page -= 1;
                    await loadDetail();
                }
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', async () => {
                if (state.page < state.totalPages) {
                    state.page += 1;
                    await loadDetail();
                }
            });
        }

        await loadDetail();

        async function loadDetail() {
            showFeedback(feedbackEl, '', '');
            try {
                const qs = buildQueryString({
                    page: state.page,
                    page_size: state.pageSize,
                });
                const response = await ApiClient.get(`reportes/cierres-caja/${encodeURIComponent(String(cierreId))}?${qs}`);
                const data = unwrapApi(response, 'No se pudo cargar el detalle del cierre de caja.');
                if (!data || !data.closure) {
                    state.ready = false;
                    updateDetailExportButtons();
                    showFeedback(feedbackEl, 'error', 'No se encontró información para este cierre.');
                    return;
                }
                state.ready = true;
                updateDetailExportButtons();

                const c = data.closure;

                if (contentEl) {
                    contentEl.style.display = 'block';
                }
                if (titleEl) {
                    titleEl.textContent = `Cierre ${c.code || `#${c.id}`}`;
                }
                if (subtitleEl) {
                    subtitleEl.textContent = `${c.seller || 'Vendedor'} · ${c.turn || 'Turno'} · Cerrado el ${formatDateTime(c.closed_at)}`;
                }

                setText('detail_expected', formatCurrency(c.expected ?? 0));
                setText('detail_counted', formatCurrency(c.counted ?? 0));
                setText('detail_closing_diff', formatSignedCurrency(c.closing_difference ?? 0));
                setText(
                    'detail_opening_diff',
                    c.opening_difference === null || c.opening_difference === undefined
                        ? 'No aplica'
                        : formatSignedCurrency(c.opening_difference)
                );

                setText('detail_code', c.code || `#${c.id}`);
                setText('detail_seller', c.seller || '—');
                const sched = c.reference_schedule
                    ? `${c.turn || 'Turno'} (${c.reference_schedule.start_time || '--:--'} – ${c.reference_schedule.end_time || '--:--'})`
                    : (c.turn || '—');
                setText('detail_schedule', sched);
                setText('detail_opened_at', formatDateTime(c.opened_at));
                setText('detail_closed_at', formatDateTime(c.closed_at));
                setText('detail_opening_fund', formatCurrency(c.opening_fund ?? 0));
                setText(
                    'detail_reference_amount',
                    c.has_previous_close && c.reference_amount !== null && c.reference_amount !== undefined
                        ? formatCurrency(c.reference_amount)
                        : 'Sin cierre anterior'
                );

                const auth = data.authorization;
                setText(
                    'detail_authorization',
                    auth
                        ? `${auth.admin_name || auth.admin_email || 'Administrador'}${auth.reason ? ` (${auth.reason})` : ''}`
                        : 'Sin excepción'
                );
                setText('detail_cash_sales', formatCurrency(c.cash_sales ?? 0));
                setText(
                    'detail_income_expenses',
                    `${formatCurrency(c.income ?? 0)} / ${formatCurrency(c.expenses ?? 0)}`
                );
                setText(
                    'detail_digital_sales',
                    `${formatCurrency(c.card_sales ?? 0)} / ${formatCurrency(c.yape_plin_sales ?? 0)}`
                );
                setText('detail_observation', c.observation || 'Sin observación.');

                renderArqueos(Array.isArray(data.arqueos) ? data.arqueos : []);
                renderMovements(Array.isArray(data.movements) ? data.movements : []);
                renderMovementPagination(data.pagination || {});
            } catch (error) {
                state.ready = false;
                updateDetailExportButtons();
                showFeedback(
                    feedbackEl,
                    'error',
                    (error && error.message) || 'No se pudo cargar el detalle del cierre de caja.'
                );
            }
        }

        function setText(id, value) {
            const el = document.getElementById(id);
            if (el) {
                el.textContent = String(value ?? '—');
            }
        }

        function renderArqueos(arqueos) {
            const tbody = document.getElementById('detail_arqueos_tbody');
            if (!tbody) {
                return;
            }
            if (!arqueos.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="close-empty">Sin arqueos registrados.</td></tr>';
                return;
            }
            tbody.innerHTML = arqueos.map((a) => `
                <tr>
                    <td>${escapeHtml(formatDateTime(a.recorded_at))}</td>
                    <td>${escapeHtml(formatCurrency(a.expected ?? 0))}</td>
                    <td>${escapeHtml(formatCurrency(a.counted ?? 0))}</td>
                    <td>${escapeHtml(formatSignedCurrency(a.difference ?? 0))}</td>
                    <td>${escapeHtml(a.status || '—')}</td>
                    <td>${escapeHtml(a.observation || '—')}</td>
                </tr>
            `).join('');
        }

        function renderMovements(movements) {
            const tbody = document.getElementById('detail_movements_tbody');
            if (!tbody) {
                return;
            }
            if (!movements.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="close-empty">Sin movimientos registrados.</td></tr>';
                return;
            }
            tbody.innerHTML = movements.map((m) => {
                const when = m.recorded_at ? formatDateTime(m.recorded_at) : `${m.date || ''} ${m.time || ''}`.trim();
                const typeLabel = m.type_label || m.type || '—';
                const reason = m.reason || m.reference || '—';
                const admin = m.admin_name || m.administrator || '—';
                return `
                    <tr>
                        <td>${escapeHtml(when || '—')}</td>
                        <td>${escapeHtml(typeLabel)}</td>
                        <td>${escapeHtml(reason)}</td>
                        <td>${escapeHtml(formatSignedCurrency(m.amount ?? 0))}</td>
                        <td>${escapeHtml(m.seller || '—')}</td>
                        <td>${escapeHtml(admin)}</td>
                    </tr>
                `;
            }).join('');
        }

        function renderMovementPagination(pagination) {
            const page = Number(pagination.page || 1);
            const totalPages = Math.max(1, Number(pagination.total_pages || 1));
            const totalMovs = Number(pagination.total_movements || 0);
            state.page = page;
            state.totalPages = totalPages;

            const infoEl = document.getElementById('detail_movs_pagination_info');
            if (infoEl) {
                infoEl.textContent = `Página ${page} de ${totalPages} · ${totalMovs} movimiento(s)`;
            }
            if (prevBtn) {
                prevBtn.disabled = page <= 1;
            }
            if (nextBtn) {
                nextBtn.disabled = page >= totalPages;
            }
        }
    }
});
