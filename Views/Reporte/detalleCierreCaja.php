<?php require_once 'Views/Header.php'; ?>

<section class="close-detail-page">
    <a class="close-back-link" href="<?= BASE_URL ?>/Reporte/cierresCaja">
        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Volver al reporte
    </a>

    <?php if (empty($data['cierre'])): ?>
    <section class="close-not-found" role="status">
        <h1>Cierre no encontrado</h1>
        <p>El detalle solicitado no está disponible en esta vista de diseño.</p>
    </section>
    <?php else: ?>
    <?php
    $cierre = $data['cierre'];
    $diferencia = (float)$cierre['closing_difference'];
    ?>
    <header class="close-detail-heading">
        <h1>Cierre del <?= e($cierre['closed_at']) ?> · <?= e($cierre['seller']) ?> · Turno <?= e(strtolower($cierre['shift'])) ?></h1>
        <p>Detalle del cierre. Solo lectura.</p>
    </header>

    <div class="close-detail-grid">
        <section class="close-detail-card close-cash-summary" aria-labelledby="close_cash_summary_title">
            <h2 id="close_cash_summary_title">Resumen de efectivo</h2>
            <dl>
                <div><dt>Fondo inicial</dt><dd><?= e('S/ ' . number_format((float)$cierre['opening_fund'], 2, ',', '')) ?></dd></div>
                <div><dt>+ Ventas en efectivo</dt><dd><?= e('S/ ' . number_format((float)$cierre['cash_sales'], 2, ',', '')) ?></dd></div>
                <div><dt>+ Ingresos e inyecciones</dt><dd><?= e('S/ ' . number_format((float)$cierre['income'], 2, ',', '')) ?></dd></div>
                <div><dt>− Egresos</dt><dd><?= e('S/ ' . number_format((float)$cierre['expenses'], 2, ',', '')) ?></dd></div>
                <div class="is-emphasized"><dt>Efectivo esperado</dt><dd><?= e('S/ ' . number_format((float)$cierre['expected'], 2, ',', '')) ?></dd></div>
                <div><dt>Efectivo contado</dt><dd><?= e('S/ ' . number_format((float)$cierre['counted'], 2, ',', '')) ?></dd></div>
                <div class="close-detail-difference">
                    <dt>Diferencia de cierre</dt>
                    <dd class="<?= $diferencia < 0 ? 'is-negative' : ($diferencia > 0 ? 'is-positive' : '') ?>">
                        <?= e(($diferencia < 0 ? '−' : ($diferencia > 0 ? '+' : '')) . 'S/ ' . number_format(abs($diferencia), 2, ',', '')) ?>
                        · <?= $diferencia < 0 ? 'Faltante' : ($diferencia > 0 ? 'Sobrante' : 'Sin diferencia') ?>
                    </dd>
                </div>
            </dl>
            <p class="close-observation"><strong>Observación del arqueo:</strong> <?= e($cierre['observation']) ?></p>
            <h3>Ventas por medio de pago</h3>
            <dl>
                <div><dt>Efectivo</dt><dd><?= e('S/ ' . number_format((float)$cierre['cash_sales'], 2, ',', '')) ?></dd></div>
                <div><dt>Tarjeta</dt><dd><?= e('S/ ' . number_format((float)$cierre['card_sales'], 2, ',', '')) ?></dd></div>
                <div><dt>Yape/Plin</dt><dd><?= e('S/ ' . number_format((float)$cierre['digital_sales'], 2, ',', '')) ?></dd></div>
            </dl>
            <small class="close-payment-note">Tarjeta y Yape/Plin se muestran aparte y no suman al efectivo esperado.</small>
        </section>

        <section class="close-detail-card close-session-data" aria-labelledby="close_session_data_title">
            <h2 id="close_session_data_title">Datos del cierre</h2>
            <dl>
                <div><dt>Vendedor</dt><dd><?= e($cierre['seller']) ?></dd></div>
                <div><dt>Turno</dt><dd><?= e($cierre['shift_schedule']) ?></dd></div>
                <div><dt>Apertura real</dt><dd><?= e($cierre['opening_at']) ?></dd></div>
                <div><dt>Cierre real</dt><dd><?= e($cierre['closing_at']) ?></dd></div>
                <div><dt>Arqueo usado</dt><dd><?= e($cierre['arqueo_at']) ?> · vigente</dd></div>
                <div><dt>Autorización de horario</dt><dd><?= e($cierre['authorization']) ?></dd></div>
            </dl>
            <div class="close-opening-difference">
                <span>Diferencia de apertura (se muestra aparte)</span>
                <strong><?= e(($cierre['opening_difference'] < 0 ? '−' : ($cierre['opening_difference'] > 0 ? '+' : '')) . 'S/ ' . number_format(abs((float)$cierre['opening_difference']), 2, ',', '')) ?></strong>
            </div>
        </section>
    </div>

    <section class="close-detail-card close-session-movements" aria-labelledby="close_movements_title">
        <h2 id="close_movements_title">Movimientos de la sesión</h2>
        <div class="close-table-scroll">
            <table class="close-table">
                <thead>
                    <tr>
                        <th scope="col">Fecha y hora</th>
                        <th scope="col">Tipo</th>
                        <th scope="col">Motivo o referencia</th>
                        <th scope="col">Importe</th>
                        <th scope="col">Vendedor</th>
                        <th scope="col">Administrador</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cierre['movements'] as $movimiento): ?>
                    <?php
                    $esRetiroOmitido = $movimiento['type'] === 'Retiro omitido';
                    $claseTipo = $movimiento['type'] === 'Inyección'
                        ? 'close-type-income'
                        : (in_array($movimiento['type'], ['Retiro', 'Salida de caja'], true)
                            ? 'close-type-expense'
                            : ($esRetiroOmitido ? 'close-type-omitted' : 'close-type-sale'));
                    ?>
                    <tr>
                        <td><?= e(($movimiento['date'] ?? substr($cierre['closed_at'], 0, 10)) . ' ' . $movimiento['time']) ?></td>
                        <td><span class="close-movement-type <?= e($claseTipo) ?>"><?= e($movimiento['type']) ?></span></td>
                        <td>
                            <?= e($movimiento['reference']) ?>
                            <?php if ($movimiento['type'] === 'Retiro omitido'): ?>
                            <small class="close-omitted-help">Cierre original conservado; sin efecto en el saldo, ya reflejado en la apertura.</small>
                            <?php endif; ?>
                        </td>
                        <td class="<?= $esRetiroOmitido ? 'is-neutral' : ($movimiento['amount'] < 0 ? 'is-negative' : '') ?>">
                            <?= e(($esRetiroOmitido ? '' : ($movimiento['amount'] < 0 ? '−' : '+')) . 'S/ ' . number_format(abs((float)$movimiento['amount']), 2, ',', '')) ?>
                        </td>
                        <td><?= e($movimiento['seller']) ?></td>
                        <td><?= e($movimiento['administrator']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($cierre['movements'])): ?>
                    <tr><td colspan="6" class="close-empty-movements">No hay movimientos de caja registrados en esta sesión de demostración.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</section>

<?php require_once 'Views/Footer.php'; ?>
