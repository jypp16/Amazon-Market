<?php

require_once __DIR__.'/ReporteInventarioExcel.php';

class ReporteKardexExcel extends ReporteInventarioExcel {
    public function generar(array $datos, string $responsable): string {
        $items=$datos['items']??[];
        $resumen=$datos['resumen_producto']??['aplicable'=>false];
        return $this->generarLibro(function(\OpenSpout\Writer\XLSX\Writer $writer,array $estilos) use($datos,$responsable,$items,$resumen): void {
            $this->metadatos($writer,'REPORTE DE KARDEX DE INVENTARIO',$datos,$responsable,$estilos);
            $this->seccion($writer,'RESUMEN EJECUTIVO',6,$estilos);
            if (!empty($resumen['aplicable'])) {
                $this->meta($writer,'Producto:',trim((string)($resumen['producto']??'Producto')).' · '.(string)($resumen['codigo_barra']??''),$estilos);
                $this->meta($writer,'Estado del producto:',(string)($resumen['estado_producto']??'No disponible'),$estilos);
                $this->meta($writer,'Unidad de inventario:',(string)($resumen['unidad_inventario']??'UND'),$estilos);
                foreach ([
                    'Saldo inicial'=>(int)($resumen['saldo_inicial']??0),
                    'Entradas'=>(int)($resumen['total_entradas']??0),
                    'Salidas'=>(int)($resumen['total_salidas']??0),
                    'Saldo final'=>(int)($resumen['saldo_final']??0),
                    'Movimientos del período'=>(int)($resumen['total_movimientos']??0),
                    'Movimientos en el detalle'=>(int)($resumen['movimientos_filtrados']??count($items)),
                ] as $label=>$value) {
                    $this->fila($writer,[$this->celdaTexto($label.':',$estilos['etiqueta']),$this->celdaNumero($value,$estilos['entero'])]);
                }
                if (!$items) {
                    $this->fila($writer,[$this->celdaTexto('Sin movimientos en el rango. El saldo de cierre se conserva igual al saldo inicial.',$estilos['nota'])]);
                }
            } else {
                $productos=$this->productosResumen($items);
                $entradas=count(array_filter($items,fn($item)=>($item['sentido']??$item['sentido_movimiento']??'')==='INCREMENTO'));
                $salidas=count($items)-$entradas;
                foreach ([
                    'Movimientos'=>(int)count($items),
                    'Productos con movimientos'=>(int)count($productos),
                    'Operaciones de entrada'=>$entradas,
                    'Operaciones de salida'=>$salidas,
                ] as $label=>$value) {
                    $this->fila($writer,[$this->celdaTexto($label.':',$estilos['etiqueta']),$this->celdaNumero($value,$estilos['entero'])]);
                }
                $this->fila($writer,[$this->celdaTexto('Las cantidades se muestran por producto y unidad; no se suman existencias de unidades diferentes.',$estilos['nota'])]);
                $this->encabezado($writer,['Producto','Código de barras','Unidad','Operaciones','Entradas','Salidas'],$estilos);
                foreach ($productos as $producto) {
                    $this->fila($writer,[
                        $this->celdaTexto($producto['producto'],$estilos['normal']),
                        $this->celdaTexto($producto['codigo'],$estilos['normal']),
                        $this->celdaTexto($producto['unidad'],$estilos['normal']),
                        $this->celdaNumero($producto['operaciones'],$estilos['entero']),
                        $this->celdaNumero($producto['entradas'],$estilos['numero']),
                        $this->celdaNumero($producto['salidas'],$estilos['numero']),
                    ]);
                }
            }

            $this->hojaDetalle($writer);
            $this->encabezado($writer,['Fecha y hora','Código de barras','Producto','Categoría histórica','Subcategoría histórica','Origen','Entrada','Unidad','Salida','Saldo anterior','Saldo final','Responsable','Lote','Justificación','Referencia de operación'],$estilos);
            if (!$items) {
                $saldo=(int)($resumen['saldo_inicial']??0);
                $unidad=(string)($resumen['unidad_inventario']??'UND');
                $this->fila($writer,[$this->celdaTexto('No hubo movimientos en el período. Saldo inicial y saldo de cierre: '.$saldo.' '.$unidad.'.',$estilos['nota'])]);
            } else {
                foreach ($items as $item) {
                    $this->fila($writer,[
                        $this->celdaTexto($this->fecha((string)($item['fecha_hora']??$item['fecha_movimiento']??'')),$estilos['normal']),
                        $this->celdaTexto((string)($item['codigo_barra']??''),$estilos['normal']),
                        $this->celdaTexto((string)($item['producto_nombre']??$item['producto']??''),$estilos['normal']),
                        $this->celdaTexto((string)($item['categoria_nombre']??'Sin categoría'),$estilos['normal']),
                        $this->celdaTexto((string)($item['subcategoria_nombre']??'General'),$estilos['normal']),
                        $this->celdaTexto((string)($item['tipo_movimiento']??$item['origen']??''),$estilos['normal']),
                        $item['entrada']===null?$this->celdaTexto('—',$estilos['normal']):$this->celdaNumero((int)$item['entrada'],$estilos['numero']),
                        $this->celdaTexto((string)($item['unidad_abreviatura']??'UND'),$estilos['normal']),
                        $item['salida']===null?$this->celdaTexto('—',$estilos['normal']):$this->celdaNumero((int)$item['salida'],$estilos['numero']),
                        $this->celdaNumero((int)($item['saldo_anterior']??0),$estilos['numero']),
                        $this->celdaNumero((int)($item['saldo_resultante']??0),$estilos['numero']),
                        $this->celdaTexto((string)($item['responsable']??'No disponible'),$estilos['normal']),
                        $this->celdaTexto((string)($item['lote']??'Sin lote'),$estilos['normal']),
                        $this->celdaTexto((string)($item['justificacion']??''),$estilos['normal']),
                        $this->celdaTexto((string)($item['referencia_operacion']??'—'),$estilos['normal']),
                    ]);
                }
            }
        });
    }
}
