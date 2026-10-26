<?php

require_once __DIR__ . '/ReporteInventarioPDF.php';

class ReporteVariacionesPDF extends ReporteInventarioPDF {
    public function generar(array $datos, string $responsable): string {
        $this->iniciar('REPORTE DE VARIACIONES DE INVENTARIO',$datos,$responsable);
        $items=$datos['items']??[]; $summary=$datos['resumen']??[];
        $this->seccion('RESUMEN EJECUTIVO');
        $direction=$datos['filtros_aplicados']['sentido']??'TODOS';
        $directionLabel=['TODOS'=>'Todas','INCREMENTO'=>'Entradas','DISMINUCION'=>'Salidas'][$direction]??$direction;
        $this->writeKPIRow([
            ['label'=>'Total de operaciones','value'=>$this->formatNumber($summary['total_operaciones']??count($items))],
            ['label'=>'Productos con variaciones','value'=>$this->formatNumber($summary['productos_con_variaciones']??0)],
            ['label'=>'Sentido consultado','value'=>$directionLabel,'color'=>'gold'],
        ]);
        $products=$this->obtenerFilasPorProducto($items);
        $productRows=array_map(fn($p)=>[$p['nombre'],$p['unidad'],$this->formatNumber($p['operaciones']),$this->formatNumber($p['entradas']),$this->formatNumber($p['salidas'])],$products);
        $this->tablaCompleta(['Producto / código','Unidad','Operaciones','Entradas','Salidas'],$productRows,[100,30,38,49,50],['L','C','R','R','R']);

        $byType=[];
        foreach($items as $item){$type=(string)($item['tipo_variacion']??$item['tipo_movimiento']??'Sin tipo');$byType[$type]=($byType[$type]??0)+1;}
        $this->writeMeta('Lectura:', 'Las operaciones se cuentan por tipo. Las cantidades se desglosan por producto y su unidad de inventario.');
        $typeRows=[]; foreach($byType as $type=>$count)$typeRows[]=[$type,$this->formatNumber($count)];
        $this->tablaCompleta(['Tipo de variación','Operaciones'],$typeRows,[190,77],['L','R']);

        $this->seccion('DETALLE DE VARIACIONES');
        $headers=['Fecha y hora','Producto / clasificación','Tipo','Entrada / salida','Saldo ant. / final','Justificación','Responsable','Lote'];
        $rows=[];
        foreach($items as $item)$rows[]=[
            $this->formatDateFull($item['fecha_hora']??$item['fecha_movimiento']),
            trim(($item['codigo_barra']??'')."\n".($item['producto_nombre']??'')."\n".($item['categoria_nombre']??'Sin categoría').' / '.($item['subcategoria_nombre']??'General')),
            $item['tipo_variacion']??$item['tipo_movimiento']??'',
            ($item['entrada']===null?'--':$this->formatNumber($item['entrada'])).' / '.($item['salida']===null?'--':$this->formatNumber($item['salida'])).' '.($item['unidad_abreviatura']??'UND'),
            $this->formatNumber($item['saldo_anterior']).' / '.$this->formatNumber($item['saldo_resultante']),
            trim((string)($item['justificacion']??''))?:'Sin justificación',
            $item['responsable']??'No disponible',$item['lote']??'Sin lote',
        ];
        $this->tablaCompleta($headers,$rows,[27,50,35,33,30,42,25,25],['L','L','L','C','C','L','L','L']);
        return $this->Output('S');
    }
}
