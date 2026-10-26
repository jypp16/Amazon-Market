<?php

require_once __DIR__.'/ReporteInventarioExcel.php';

class ReporteVariacionesExcel extends ReporteInventarioExcel {
    public function generar(array $datos, string $responsable): string {
        $items=$datos['items']??[];
        $resumen=$datos['resumen']??[];
        return $this->generarLibro(function(\OpenSpout\Writer\XLSX\Writer $writer,array $estilos) use($datos,$responsable,$items,$resumen): void {
            $this->metadatos($writer,'REPORTE DE VARIACIONES DE INVENTARIO',$datos,$responsable,$estilos);
            $this->seccion($writer,'RESUMEN EJECUTIVO',5,$estilos);
            $direction=$datos['filtros_aplicados']['sentido']??'TODOS';
            $directionLabel=['TODOS'=>'Todas','INCREMENTO'=>'Entradas','DISMINUCION'=>'Salidas'][$direction]??$direction;
            foreach ([
                'Total de operaciones'=>(int)($resumen['total_operaciones']??count($items)),
                'Productos con variaciones'=>(int)($resumen['productos_con_variaciones']??count($this->productosResumen($items))),
                'Sentido consultado'=>$directionLabel,
            ] as $label=>$value) {
                $this->fila($writer,[$this->celdaTexto($label.':',$estilos['etiqueta']),is_int($value)?$this->celdaNumero($value,$estilos['entero']):$this->celdaTexto($value,$estilos['normal'])]);
            }

            $this->encabezado($writer,['Producto','Código de barras','Unidad','Operaciones','Entradas','Salidas'],$estilos);
            foreach ($this->productosResumen($items) as $producto) {
                $this->fila($writer,[
                    $this->celdaTexto($producto['producto'],$estilos['normal']),
                    $this->celdaTexto($producto['codigo'],$estilos['normal']),
                    $this->celdaTexto($producto['unidad'],$estilos['normal']),
                    $this->celdaNumero($producto['operaciones'],$estilos['entero']),
                    $this->celdaNumero($producto['entradas'],$estilos['numero']),
                    $this->celdaNumero($producto['salidas'],$estilos['numero']),
                ]);
            }
            $this->fila($writer,[$this->celdaTexto('Las cantidades se desglosan por producto y unidad. El resumen no estima pérdidas monetarias.',$estilos['nota'])]);
            $this->encabezado($writer,['Tipo de variación','Operaciones'],$estilos);
            $byType=[];
            foreach ($items as $item) {
                $type=(string)($item['tipo_variacion']??$item['tipo_movimiento']??'Sin tipo');
                $byType[$type]=($byType[$type]??0)+1;
            }
            foreach ($byType as $type=>$count) {
                $this->fila($writer,[$this->celdaTexto($type,$estilos['normal']),$this->celdaNumero($count,$estilos['entero'])]);
            }

            $this->hojaDetalle($writer);
            $this->encabezado($writer,['Fecha y hora','Código de barras','Producto','Categoría histórica','Subcategoría histórica','Tipo de variación','Sentido','Entrada','Salida','Unidad','Saldo anterior','Saldo final','Justificación','Responsable','Lote','Referencia de operación'],$estilos);
            foreach ($items as $item) {
                $this->fila($writer,[
                    $this->celdaTexto($this->fecha((string)($item['fecha_hora']??$item['fecha_movimiento']??'')),$estilos['normal']),
                    $this->celdaTexto((string)($item['codigo_barra']??''),$estilos['normal']),
                    $this->celdaTexto((string)($item['producto_nombre']??$item['producto']??''),$estilos['normal']),
                    $this->celdaTexto((string)($item['categoria_nombre']??'Sin categoría'),$estilos['normal']),
                    $this->celdaTexto((string)($item['subcategoria_nombre']??'General'),$estilos['normal']),
                    $this->celdaTexto((string)($item['tipo_variacion']??$item['tipo_movimiento']??''),$estilos['normal']),
                    $this->celdaTexto((string)($item['sentido']??$item['sentido_movimiento']??''),$estilos['normal']),
                    $item['entrada']===null?$this->celdaTexto('—',$estilos['normal']):$this->celdaNumero((int)$item['entrada'],$estilos['numero']),
                    $item['salida']===null?$this->celdaTexto('—',$estilos['normal']):$this->celdaNumero((int)$item['salida'],$estilos['numero']),
                    $this->celdaTexto((string)($item['unidad_abreviatura']??'UND'),$estilos['normal']),
                    $this->celdaNumero((int)($item['saldo_anterior']??0),$estilos['numero']),
                    $this->celdaNumero((int)($item['saldo_resultante']??0),$estilos['numero']),
                    $this->celdaTexto(trim((string)($item['justificacion']??''))?:'Sin justificación',$estilos['normal']),
                    $this->celdaTexto((string)($item['responsable']??'No disponible'),$estilos['normal']),
                    $this->celdaTexto((string)($item['lote']??'Sin lote'),$estilos['normal']),
                    $this->celdaTexto((string)($item['referencia_operacion']??'—'),$estilos['normal']),
                ]);
            }
        });
    }
}
