<?php

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options as XLSXOptions;
use OpenSpout\Writer\XLSX\Writer as XLSXWriter;

/** Utilidades visuales y de archivos temporales para Excel de inventario. */
abstract class ReporteInventarioExcel {
    protected function estilos(): array {
        $normal=(new Style())->setFontName('Calibri')->setFontSize(10)->setShouldWrapText()->setCellVerticalAlignment('top');
        return [
            'normal'=>$normal,
            'titulo'=>(clone $normal)->setFontSize(16)->setFontBold()->setFontColor('001F3F'),
            'seccion'=>(clone $normal)->setFontSize(12)->setFontBold()->setFontColor('D4AF37')->setBackgroundColor('001F3F'),
            'encabezado'=>(clone $normal)->setFontBold()->setFontColor('D4AF37')->setBackgroundColor('001F3F'),
            'etiqueta'=>(clone $normal)->setFontBold()->setFontColor('64748B'),
            'numero'=>(clone $normal)->setFormat('#,##0.##')->setCellAlignment('right'),
            'entero'=>(clone $normal)->setFormat('#,##0')->setCellAlignment('right'),
            'nota'=>(clone $normal)->setFontItalic()->setFontColor('64748B'),
            'total'=>(clone $normal)->setFontBold()->setBackgroundColor('F3F4F6'),
        ];
    }

    /** Crea un libro privado en una carpeta temporal única y devuelve sus bytes. */
    protected function generarLibro(callable $construir): string {
        $carpeta=sys_get_temp_dir().DIRECTORY_SEPARATOR.'inventario_excel_'.bin2hex(random_bytes(12));
        if (!mkdir($carpeta,0700)) throw new RuntimeException('No se pudo preparar el archivo Excel temporal.');
        $writer=null;
        try {
            $opciones=new XLSXOptions();
            $opciones->setTempFolder($carpeta);
            $opciones->setPageSetup(new \OpenSpout\Writer\XLSX\Options\PageSetup(
                \OpenSpout\Writer\XLSX\Options\PageOrientation::LANDSCAPE,
                \OpenSpout\Writer\XLSX\Options\PaperSize::A4,
                0,
                1
            ));
            foreach ([34,18,34,20,20,16,16,34,34,24,24,24,24,24,24,24] as $i=>$width) {
                $opciones->setColumnWidth($width,$i+1);
            }
            $opciones->DEFAULT_ROW_STYLE=(new Style())->setFontName('Calibri')->setFontSize(10)->setShouldWrapText()->setCellVerticalAlignment('top');
            $writer=new XLSXWriter($opciones);
            $writer->openToFile($carpeta.DIRECTORY_SEPARATOR.'reporte.xlsx');
            $writer->getCurrentSheet()->setName('Resumen ejecutivo')->setSheetView(
                (new \OpenSpout\Writer\XLSX\Entity\SheetView())->setShowGridLines(false)
            );
            $construir($writer,$this->estilos());
            $writer->close();
            $writer=null;
            $contenido=file_get_contents($carpeta.DIRECTORY_SEPARATOR.'reporte.xlsx');
            if (!is_string($contenido)||!str_starts_with($contenido,"PK\x03\x04")) {
                throw new RuntimeException('No se pudo generar el libro Excel.');
            }
            return $contenido;
        } finally {
            try { $writer?->close(); }
            catch (Throwable $error) { error_log('Cierre de Excel de inventario: '.$error->getMessage()); }
            finally {
                if (is_dir($carpeta)) {
                    (new \OpenSpout\Common\Helper\FileSystemHelper(sys_get_temp_dir()))->deleteFolderRecursively($carpeta);
                }
            }
        }
    }

    protected function celdaTexto(string $valor, ?Style $estilo=null): StringCell {
        // StringCell mantiene códigos y contenido del usuario como texto literal, nunca como fórmula.
        return new StringCell($valor,$estilo);
    }

    protected function celdaNumero(int|float $valor, ?Style $estilo=null): Cell {
        return Cell::fromValue($valor,$estilo);
    }

    protected function fila(XLSXWriter $writer, array $celdas): void {
        $writer->addRow(new Row($celdas));
    }

    protected function titulo(XLSXWriter $writer, string $texto, array $estilos): void {
        $this->fila($writer,[$this->celdaTexto($texto,$estilos['titulo'])]);
    }

    protected function meta(XLSXWriter $writer, string $etiqueta, string $valor, array $estilos): void {
        $this->fila($writer,[
            $this->celdaTexto($etiqueta,$estilos['etiqueta']),
            $this->celdaTexto($valor,$estilos['normal']),
        ]);
    }

    protected function seccion(XLSXWriter $writer, string $texto, int $columnas, array $estilos): void {
        $celdas=[$this->celdaTexto($texto,$estilos['seccion'])];
        for ($i=1;$i<$columnas;$i++) $celdas[]=$this->celdaTexto('',$estilos['seccion']);
        $this->fila($writer,$celdas);
    }

    protected function encabezado(XLSXWriter $writer, array $textos, array $estilos): void {
        $this->fila($writer,array_map(fn($texto)=>$this->celdaTexto((string)$texto,$estilos['encabezado']),$textos));
    }

    protected function metadatos(XLSXWriter $writer, string $titulo, array $datos, string $responsable, array $estilos): void {
        $this->titulo($writer,$titulo,$estilos);
        $filtros=$datos['filtros_aplicados']??[];
        $this->meta($writer,'Período:',($filtros['fecha_inicio']??'—').' al '.($filtros['fecha_fin']??'—'),$estilos);
        $this->meta($writer,'Generado por:',$responsable!==''?$responsable:'Administrador',$estilos);
        $this->meta($writer,'Generado el:',(string)($datos['generado_en']??''),$estilos);
        $this->meta($writer,'Referencia de consulta:',(string)($datos['referencia_consulta']??''),$estilos);
        $labels=['q'=>'Búsqueda','id_producto'=>'Producto ID','id_categoria'=>'Categoría ID','id_subcategoria'=>'Subcategoría ID','tipo_variacion'=>'Tipo de variación'];
        foreach ($labels as $key=>$label) {
            $value=$filtros[$key]??null;
            if ($value!==null&&$value!=='') $this->meta($writer,$label.':',(string)$value,$estilos);
        }
        if (isset($filtros['sentido'])) {
            $sentidos=['TODOS'=>'Todas','INCREMENTO'=>'Entradas','DISMINUCION'=>'Salidas'];
            $this->meta($writer,'Sentido:',(string)($sentidos[$filtros['sentido']]??$filtros['sentido']),$estilos);
        }
    }

    protected function fecha(string $valor): string {
        $timestamp=strtotime($valor);
        return $timestamp===false?$valor:date('d/m/Y H:i',$timestamp);
    }

    protected function productosResumen(array $items): array {
        $grupos=[];
        foreach ($items as $item) {
            $id=(int)($item['id_producto']??0);
            if (!isset($grupos[$id])) {
                $grupos[$id]=[
                    'producto'=>trim((string)($item['producto_nombre']??$item['producto']??'Producto')),
                    'codigo'=>(string)($item['codigo_barra']??''),
                    'unidad'=>(string)($item['unidad_abreviatura']??'UND'),
                    'operaciones'=>0,'entradas'=>0,'salidas'=>0,
                ];
            }
            $grupos[$id]['operaciones']++;
            if (($item['sentido']??$item['sentido_movimiento']??'')==='INCREMENTO') {
                $grupos[$id]['entradas']+=(int)($item['entrada']??$item['cantidad']??0);
            } else {
                $grupos[$id]['salidas']+=(int)($item['salida']??$item['cantidad']??0);
            }
        }
        return array_values($grupos);
    }

    protected function hojaDetalle(XLSXWriter $writer): void {
        $writer->addNewSheetAndMakeItCurrent()->setName('Detalle')->setSheetView(
            (new \OpenSpout\Writer\XLSX\Entity\SheetView())->setShowGridLines(false)->setFreezeRow(1)
        );
    }
}
