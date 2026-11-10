<?php

namespace Controllers;

use Libraries\Core\Controller;

class CajaController extends Controller {
    public function abrir() {
        $fechaAyer = (new \DateTimeImmutable('yesterday', new \DateTimeZone('America/Lima')))->format('d/m/Y');
        $referenciaAnterior = 200.00;
        $mockEnabled = filter_var(getenv('CAJA_MOCK_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
        $mockScenario = getenv('CAJA_MOCK_SCENARIO') ?: 'authorization_required';

        // Valores de ejemplo hasta integrar la apertura con los turnos reales.
        $this->views->render($this, 'abrir', [
            'referencia_anterior' => $referenciaAnterior,
            'fecha_cierre_anterior' => $fechaAyer,
            'turno_anterior' => 'Tarde',
            'turno_actual' => 'Mañana · 08:00–14:00',
            'efectivo_contado' => 190.00,
            'caja_mock_enabled' => $mockEnabled,
            'caja_mock_scenario' => $mockScenario,
            'caja_mock_reference' => $referenciaAnterior,
            'page_title' => 'Abrir caja - Amazon Market'
        ]);
    }

    public function movimientos() {
        $expenseTypes = getenv('CAJA_MOVIMIENTOS_EXPENSE_TYPES');
        $expenseTypes = $expenseTypes
            ? array_map('trim', explode('|', $expenseTypes))
            : ['Salida de caja', 'Retiro del administrador', 'Retiro omitido'];

        $this->views->render($this, 'movimientos', [
            'mock_enabled' => filter_var(getenv('CAJA_MOVIMIENTOS_MOCK_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
            'mock_scenario' => getenv('CAJA_MOVIMIENTOS_MOCK_SCENARIO') ?: 'success',
            'mock_latency_ms' => max(0, (int)(getenv('CAJA_MOVIMIENTOS_MOCK_LATENCY_MS') ?: 250)),
            'expense_types' => $expenseTypes,
            'page_title' => 'Movimientos de caja - Amazon Market'
        ]);
    }

    public function arqueo() {
        $this->views->render($this, 'arqueo', [
            'mock_enabled' => filter_var(getenv('CAJA_ARQUEO_MOCK_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
            'mock_scenario' => getenv('CAJA_ARQUEO_MOCK_SCENARIO') ?: 'success',
            'mock_latency_ms' => max(0, (int)(getenv('CAJA_ARQUEO_MOCK_LATENCY_MS') ?: 250)),
            'page_title' => 'Arqueo y cierre - Amazon Market'
        ]);
    }

    public function consultar() {
        $role = (int)($_SESSION['rol'] ?? 0);
        $isAdministrator = $role === 1;
        $pageSize = max(1, (int)(getenv('CAJA_CONSULTA_PAGE_SIZE') ?: 10));
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('America/Lima')))->format('Y-m-d');
        $configuredDate = getenv('CAJA_CONSULTA_DEFAULT_DATE') ?: '';
        $defaultDate = $today;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $configuredDate)) {
            $dateParts = explode('-', $configuredDate);
            if (checkdate((int)$dateParts[1], (int)$dateParts[2], (int)$dateParts[0])) {
                $defaultDate = $configuredDate;
            }
        }

        $this->views->render($this, 'consultar', [
            'is_administrator' => $isAdministrator,
            'mock_enabled' => filter_var(getenv('CAJA_CONSULTA_MOCK_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
            'mock_scenario' => getenv('CAJA_CONSULTA_MOCK_SCENARIO') ?: ($isAdministrator ? 'admin_data' : 'seller_active'),
            'mock_latency_ms' => max(0, (int)(getenv('CAJA_CONSULTA_MOCK_LATENCY_MS') ?: 250)),
            // TODO: Confirmar el tamaño de página con producto/backend.
            'page_size' => $pageSize,
            // TODO: Confirmar si el filtro de fecha debe iniciar en hoy.
            'default_date' => $defaultDate,
            'page_title' => 'Consultar caja - Amazon Market'
        ]);
    }
}
