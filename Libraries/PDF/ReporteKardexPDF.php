<?php

require_once __DIR__ . '/ReporteInventarioPDF.php';

class ReporteKardexPDF extends ReporteInventarioPDF {
    public function generar(array $datos, string $responsable): string {
        $this->iniciar('REPORTE DE KARDEX', $datos, $responsable);
        $items=$datos['items']??[];
        $summary=$datos['resumen_producto']??['aplicable'=>false];
        $this->seccion('RESUMEN EJECUTIVO');
        if (!empty($summary['aplicable'])) {
            $unit=$summary['unidad_inventario']??'UND';
            $this->metaEnvuelta('Producto:',trim((string)($summary['producto']??'Producto')) . ' · ' . (string)($summary['codigo_barra']??''));
            $this->writeKPIRow([
                ['label'=>'Unidad','value'=>$unit],
                ['label'=>'Saldo inicial','value'=>$this->formatNumber($summary['saldo_inicial'])],
                ['label'=>'Entradas','value'=>$this->formatNumber($summary['total_entradas']),'color'=>'success'],
                ['label'=>'Salidas','value'=>$this->formatNumber($summary['total_salidas']),'color'=>'danger'],
                ['label'=>'Saldo final','value'=>$this->formatNumber($summary['saldo_final']),'color'=>'gold'],
            ]);
            if (count($items)!==(int)($summary['movimientos_filtrados']??count($items))) $this->writeMeta('Filas en detalle:', $this->formatNumber(count($items)).' según los filtros; el saldo resume el período completo del producto.');
        } else {
            $products=$this->obtenerFilasPorProducto($items);
            $entries=count(array_filter($items,fn($item)=>($item['sentido']??'')==='INCREMENTO'));
            $exits=count($items)-$entries;
            $this->writeKPIRow([
                ['label'=>'Movimientos','value'=>$this->formatNumber(count($items))],
                ['label'=>'Productos','value'=>$this->formatNumber(count($products))],
                ['label'=>'Operaciones de entrada','value'=>$this->formatNumber($entries),'color'=>'success'],
                ['label'=>'Operaciones de salida','value'=>$this->formatNumber($exits),'color'=>'danger'],
            ]);
            $this->SetFont('Helvetica','I',8); $this->SetTextColor(100,116,139);
            $this->MultiCell(267,5,'Las cantidades se desglosan por producto y unidad. No se suman como un saldo global.',0,'L');
            $rows=array_map(fn($p)=>[$p['nombre'],$p['unidad'],$this->formatNumber($p['operaciones']),$this->formatNumber($p['entradas']),$this->formatNumber($p['salidas'])],$products);
            $this->tablaCompleta(['Producto / código','Unidad','Operaciones','Entradas','Salidas'],$rows,[100,30,38,49,50],['L','C','R','R','R']);
        }
        $this->seccion('DETALLE DE MOVIMIENTOS');
        if (!$items && !empty($summary['aplicable'])) {
            $this->SetFillColor(241,245,249); $this->SetTextColor(30,41,59); $this->SetFont('Helvetica','',10);
            $this->MultiCell(267,8,'No hubo movimientos en el período consultado. El saldo de cierre se conserva igual al saldo inicial: '.$this->formatNumber($summary['saldo_inicial']).' '.($summary['unidad_inventario']??'UND').'.',1,'L',true);
        } else {
            $headers=['Fecha y hora','Producto / clasificación','Origen','Entrada','Salida','Saldo ant. / final','Responsable','Lote'];
            $rows=[];
            foreach($items as $item) $rows[]=[
                $this->formatDateFull($item['fecha_hora']??$item['fecha_movimiento']),
                trim(($item['codigo_barra']??'')."\n".($item['producto_nombre']??'')."\n".($item['categoria_nombre']??'Sin categoría').' / '.($item['subcategoria_nombre']??'General')),
                $item['tipo_movimiento']??$item['origen']??'',
                $item['entrada']===null?'--':$this->formatNumber($item['entrada']).' '.($item['unidad_abreviatura']??'UND'),
                $item['salida']===null?'--':$this->formatNumber($item['salida']).' '.($item['unidad_abreviatura']??'UND'),
                $this->formatNumber($item['saldo_anterior']).' / '.$this->formatNumber($item['saldo_resultante']),
                $item['responsable']??'No disponible',$item['lote']??'Sin lote',
            ];
            $this->tablaCompleta($headers,$rows,[28,55,39,22,22,34,31,36],['L','L','L','R','R','C','L','L']);
        }
        return $this->Output('S');
    }
}
