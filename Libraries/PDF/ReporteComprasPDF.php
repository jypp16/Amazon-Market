<?php

require_once __DIR__ . '/ReportPDF.php';

class ReporteComprasPDF extends ReportPDF {
    private array $reporte = [];
    private array $dorado = [212, 175, 55];
    private array $oscuro = [30, 41, 59];
    private array $gris = [100, 116, 139];
    private array $suave = [148, 163, 184];
    private float $limiteContenido = 270;
    private array $anchosCompra = [25, 18, 24, 25, 60, 26];
    private array $anchosDetalle = [59, 27, 13, 26, 28, 22];

    public function __construct() {
        parent::__construct('P', 'mm', 'A4');
        $this->SetMargins(16, 16, 16);
        $this->cMargin = 0;
        $this->SetAutoPageBreak(false);
        $this->AliasNbPages();
    }

    public function GetStringWidth($texto) {
        $texto = (string)$texto;
        if (mb_check_encoding($texto, 'UTF-8')) $texto = mb_convert_encoding($texto, 'ISO-8859-1', 'UTF-8');
        return parent::GetStringWidth($texto);
    }

    private function lineas(string $texto, float $ancho, float $tamano = 7, string $estilo = ''): array {
        $this->SetFont('Helvetica', $estilo, $tamano);
        $resultado = [];
        foreach (explode("\n", str_replace("\r", '', $texto)) as $parrafo) {
            $linea = '';
            foreach (preg_split('/[\t ]+/u', trim($parrafo)) as $palabra) {
                if ($linea !== '' && $this->GetStringWidth($linea . ' ' . $palabra) <= $ancho) {
                    $linea .= ' ' . $palabra;
                    continue;
                }
                if ($linea !== '') $resultado[] = $linea;
                $linea = '';
                foreach (mb_str_split($palabra) as $letra) {
                    if ($linea !== '' && $this->GetStringWidth($linea . $letra) > $ancho) {
                        $resultado[] = $linea;
                        $linea = '';
                    }
                    $linea .= $letra;
                }
            }
            $resultado[] = $linea;
        }
        return $resultado ?: [''];
    }

    private function texto(float $x, float $y, float $ancho, array $lineas, float $tamano = 7, string $estilo = '', string $alineacion = 'L', array $color = [30, 41, 59], float $interlineado = 3.3, bool $tachado = false): void {
        $this->SetFont('Helvetica', $estilo, $tamano);
        $this->SetTextColor(...$color);
        foreach ($lineas as $indice => $linea) {
            $altoY = $y + $indice * $interlineado;
            $this->SetXY($x, $altoY);
            $this->Cell($ancho, $interlineado, $linea, 0, 0, $alineacion);
            if ($tachado && $linea !== '') {
                $largo = $this->GetStringWidth($linea);
                $inicio = $alineacion === 'R' ? $x + $ancho - $this->cMargin - $largo : $x + $this->cMargin;
                $this->SetDrawColor(...$color);
                $this->SetLineWidth(.2);
                $this->Line($inicio, $altoY + $interlineado / 2, $inicio + $largo, $altoY + $interlineado / 2);
            }
        }
    }

    private function numero(string $numero): string {
        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $numero);
    }

    private function moneda($valor, bool $preciso = false): string {
        [$entero, $decimales] = array_pad(explode('.', (string)$valor, 2), 2, '00');
        $decimales = $preciso ? str_pad(rtrim($decimales, '0'), 2, '0') : str_pad(substr($decimales, 0, 2), 2, '0');
        return 'S/ ' . $this->numero($entero) . '.' . $decimales;
    }

    private function fecha(?string $valor): string {
        return $valor ? substr($valor, 8, 2) . '/' . substr($valor, 5, 2) . '/' . substr($valor, 0, 4) : 'No aplica';
    }

    private function periodo(): string {
        $meta = $this->reporte['metadatos'];
        if (!$meta['fecha_desde'] && !$meta['fecha_hasta']) return 'Todas las fechas de recepción';
        if (!$meta['fecha_desde']) return 'Hasta ' . $this->fecha($meta['fecha_hasta']);
        if (!$meta['fecha_hasta']) return 'Desde ' . $this->fecha($meta['fecha_desde']);
        return $this->fecha($meta['fecha_desde']) . ' al ' . $this->fecha($meta['fecha_hasta']);
    }

    private function contexto(): string {
        $meta = $this->reporte['metadatos'];
        return 'Continuación del reporte | Periodo: ' . $this->periodo() . ' | ' . $meta['proveedor'] . ' | Estados: ' . implode(', ', $meta['estados']);
    }

    private function inicioContinuacion(): float {
        return 36.5 + count($this->lineas($this->contexto(), 176, 7.3)) * 3.4;
    }

    public function Header(): void {
        $meta = $this->reporte['metadatos'];
        $primera = $this->PageNo() === 1;
        $this->SetFillColor(...$this->dorado);
        $this->Rect(0, 0, 210, $primera ? 33.5 : 15.5, 'F');
        $this->texto(16, $primera ? 12 : 5.3, 125, [$meta['negocio']], $primera ? 18 : 12, 'B', 'L', $this->oscuro, 6);
        $emision = $this->fecha($meta['fecha_emision']) . ' ' . substr($meta['fecha_emision'], 11, 5);
        $this->texto($primera ? 16 : 145, $primera ? 22 : 6.3, $primera ? 100 : 49, [$emision], 8.5, '', $primera ? 'L' : 'R', [255,255,255], 4);
        $y = $primera ? 42.5 : 22.5;
        $this->texto(16, $y, 178, [$meta['titulo']], 15, 'B', 'L', $this->oscuro, 7);
        $this->SetDrawColor(...$this->dorado); $this->SetLineWidth(.7);
        $this->Line(16, $y + 7.8, 16 + $this->GetStringWidth($meta['titulo']), $y + 7.8);
        if (!$primera) $this->texto(16, 31.5, 178, $this->lineas($this->contexto(),176,7.3), 7.3, '', 'L', $this->gris, 3.4);
        $this->SetY($primera ? 55.8 : $this->inicioContinuacion());
    }

    public function Footer(): void {
        $this->SetDrawColor(...$this->dorado); $this->SetLineWidth(.35);
        $this->Line(16, 280, 194, 280);
        $meta = $this->reporte['metadatos'];
        $this->texto(16, 283, 152, [$meta['negocio'] . ' | Reporte generado el ' . $this->fecha($meta['fecha_emision']) . ' ' . substr($meta['fecha_emision'],11)], 7, 'I', 'L', $this->gris, 4);
        $this->texto(168, 283, 26, ['Página ' . $this->PageNo() . '/{nb}'], 7, 'I', 'R', $this->gris, 4);
    }

    private function siguientePagina(bool $listado): void {
        $this->texto(16, 273, 178, ['Continúa en la página siguiente'], 7, 'I', 'R', $this->gris);
        $this->AddPage();
        if ($listado) $this->encabezadoListado(true);
    }

    private function espacio(float $alto, bool $listado = false): void {
        if ($this->GetY() + $alto > $this->limiteContenido) $this->siguientePagina($listado);
    }

    private function metadatos(): void {
        $meta = $this->reporte['metadatos'];
        $filas = [
            ['Periodo:', $this->periodo(), 'Generado por:', $meta['usuario']],
            ['Proveedor:', $meta['proveedor'], 'Fecha de emisión:', $this->fecha($meta['fecha_emision']) . ' ' . substr($meta['fecha_emision'],11,5)]
        ];
        foreach ($filas as [$etiqueta, $valor, $otraEtiqueta, $otroValor]) {
            $y = $this->GetY();
            $izquierda = $this->lineas($valor, 57, 7.5); $derecha = $this->lineas($otroValor, 49, 7.5);
            $this->texto(16,$y,32,[$etiqueta],7.5,'B','L',$this->gris);
            $this->texto(49,$y,59,$izquierda,7.5);
            $this->texto(111,$y,33,[$otraEtiqueta],7.5,'B','L',$this->gris);
            $this->texto(145,$y,49,$derecha,7.5);
            $this->SetY($y + max(count($izquierda),count($derecha)) * 3.3 + 2);
        }
        $y = $this->GetY(); $estados = $this->lineas(implode(', ', $meta['estados']),143,7.5);
        $this->texto(16,$y,32,['Estados:'],7.5,'B','L',$this->gris);
        $this->texto(49,$y,145,$estados,7.5);
        $this->SetY($y + count($estados)*3.3 + 3);
        $this->SetDrawColor(226,232,240); $this->SetLineWidth(.2);
        $this->Line(16,$this->GetY(),194,$this->GetY()); $this->SetY($this->GetY()+6);
    }

    private function resumen(): void {
        $r = $this->reporte['resumen']; $y = $this->GetY();
        $this->texto(16,$y,178,['RESUMEN'],8.5,'B'); $y += 6.5;
        $tarjetas = [
            ['Compras vigentes',(string)$r['compras_validas'],'',false],
            ['Compras anuladas',(string)$r['compras_anuladas'],'',false],
            ['Importe total comprado',$this->moneda($r['total_validas']),'Solo compras vigentes',true],
            ['Importe de compras anuladas',$this->moneda($r['total_anuladas']),'No suma en el total',false]
        ];
        $alto = 18;
        foreach ($tarjetas as [$titulo,$valor]) $alto = max($alto,9 + count($this->lineas($valor,36.25,12.5,'B'))*4.6 + 3);
        foreach ($tarjetas as $indice=>[$titulo,$valor,$nota,$oro]) {
            $x = 16 + $indice*45.25;
            $this->SetFillColor(238,243,246); $this->Rect($x,$y,42.25,$alto,'F');
            $this->texto($x+2,$y+3,38.25,[$titulo],6.6,'','L',$this->gris,3);
            $this->texto($x+2,$y+8,38.25,$this->lineas($valor,36.25,12.5,'B'),12.5,'B','L',$oro ? $this->dorado : $this->oscuro,4.6);
            if ($nota) $this->texto($x+2,$y+$alto-3.5,38.25,[$nota],5.8,'I','L',$this->suave,3);
        }
        $this->SetY($y+$alto+6);
        $productos = $r['productos_mas_comprados'] ?: [['producto'=>'No aplica','cantidad_base'=>0]];
        foreach ($productos as $indice=>$producto) {
            $nombres = $this->lineas($producto['producto'],82,12.5,'B');
            $cantidades = $this->lineas($this->numero((string)$producto['cantidad_base']) . ' unidades',55,12.5,'B');
            $alto = max(17,9 + max(count($nombres),count($cantidades))*4.5 + 3);
            if ($alto > $this->limiteContenido - $this->inicioContinuacion() - 8) throw new DomainException('El nombre de un producto excede el espacio disponible del reporte.');
            $this->espacio($alto+8);
            $y = $this->GetY();
            if ($indice === 0 || $y === $this->inicioContinuacion()) {
                $titulo = 'PRODUCTO MÁS COMPRADO' . ($indice ? ' (CONTINUACIÓN)' : '');
                $this->texto(16,$y,130,[$titulo],8.5,'B');
                $inicioNota = 19 + $this->GetStringWidth($titulo);
                $this->texto($inicioNota,$y,194-$inicioNota,['(solo compras vigentes)'],7,'I','L',$this->suave);
                $y += 6.5;
            }
            $this->SetFillColor(238,243,246); $this->Rect(16,$y,88,$alto,'F'); $this->Rect(107,$y,60,$alto,'F');
            $this->texto(18,$y+3,84,['Producto'],6.8,'','L',$this->gris);
            $this->texto(109,$y+3,56,['Cantidad acumulada'],6.8,'','L',$this->gris);
            $this->texto(18,$y+8,84,$nombres,12.5,'B','L',$this->oscuro,4.5);
            $this->texto(109,$y+8,56,$cantidades,12.5,'B','L',$this->dorado,4.5);
            $this->SetY($y+$alto+3);
        }
        $this->SetY($this->GetY()+2.5);
    }

    private function encabezadoListado(bool $continuacion = false): void {
        $y=$this->GetY();
        $this->texto(16,$y,178,['LISTADO DE COMPRAS CON DETALLE' . ($continuacion ? ' (CONTINUACIÓN)' : '')],8.5,'B');
        $y+=8;
        $this->SetFillColor(238,243,246); $this->Rect(16,$y,178,7,'F');
        $x=16;
        foreach (['COMPROBANTE','SERIE','NÚMERO','FECHA RECEPCIÓN','PROVEEDOR','ESTADO'] as $i=>$titulo) {
            $this->texto($x+1,$y+2,$this->anchosCompra[$i]-2,[$titulo],6.5,'B','L',$this->gris,3);
            $x+=$this->anchosCompra[$i];
        }
        $this->SetY($y+7);
    }

    private function prepararCompra(array $compra): array {
        $cabecera=[$compra['tipo_comprobante'],$compra['serie'],$compra['numero'],$this->fecha($compra['fecha_recepcion']),$compra['razon_social']];
        $lineas=[];
        foreach ($cabecera as $i=>$valor) $lineas[]=$this->lineas((string)$valor,$this->anchosCompra[$i]-4,8,$i<3?'B':'');
        $altoCabecera=max(7,max(array_map('count',$lineas))*3.3+2);
        $detalles=[];
        foreach ($compra['detalles'] as $detalle) {
            $presentacion=$detalle['presentacion'] ?? 'Sin presentación';
            $equivalencia=(int)$detalle['equivalencia_aplicada'];
            if ($equivalencia>1 && !preg_match('/x\s*'. $equivalencia .'\b/i',$presentacion)) $presentacion.=' x'.$equivalencia;
            $valores=[$detalle['producto'],$presentacion,$this->numero((string)$detalle['cantidad_presentaciones']),$this->fecha($detalle['fecha_vencimiento']),$this->moneda($detalle['costo_por_presentacion'],true),$this->moneda($detalle['importe_final_linea'])];
            $celdas=[];
            foreach ($valores as $i=>$valor) $celdas[]=$this->lineas((string)$valor,$this->anchosDetalle[$i]-3,7,$i===3 && !$detalle['fecha_vencimiento']?'I':'');
            $detalles[]=['celdas'=>$celdas,'alto'=>max(array_map('count',$celdas))*3.3+.9,'sin_vencimiento'=>!$detalle['fecha_vencimiento']];
        }
        return ['cabecera'=>$lineas,'alto_cabecera'=>$altoCabecera,'detalles'=>$detalles,'alto'=>$altoCabecera+4+array_sum(array_column($detalles,'alto'))+8];
    }

    private function cabeceraCompra(array $compra,array $preparada,bool $continuacion=false): void {
        $y=$this->GetY(); $anulada=$compra['estado']==='Anulada';
        if ($anulada) { $this->SetFillColor(253,237,237); $this->Rect(16,$y,178,$preparada['alto_cabecera'],'F'); }
        $x=16;
        foreach ($preparada['cabecera'] as $i=>$lineas) {
            $this->texto($x+1,$y+1.5,$this->anchosCompra[$i]-2,$lineas,8,$i<3?'B':''); $x+=$this->anchosCompra[$i];
        }
        $this->SetFillColor(...($anulada?[248,207,203]:[213,240,234]));
        $this->Rect(169,$y+($preparada['alto_cabecera']-4)/2,20,4,'F');
        $this->texto(169,$y+($preparada['alto_cabecera']-3.3)/2,20,[$anulada?'ANULADA':'VIGENTE'],6.2,'B','C',$anulada?[180,35,24]:[15,118,110]);
        $y+=$preparada['alto_cabecera'];
        if ($continuacion) { $this->texto(20,$y,172,['Detalle de la compra (continuación)'],6.5,'I','L',$this->gris); $y+=4; }
        $this->SetFillColor(248,250,252); $this->Rect(19,$y,175,4,'F');
        $x=19;
        foreach (['PRODUCTO','PRESENTACIÓN','CANT.','VENCIMIENTO','COSTO X PRES.','IMPORTE'] as $i=>$titulo) {
            $this->texto($x+1,$y+1,$this->anchosDetalle[$i]-2,[$titulo],5.6,'B',$i===2||$i>=4?'R':'L',$this->suave,3);
            $x+=$this->anchosDetalle[$i];
        }
        $this->SetY($y+4);
    }

    private function detalleCompra(array $detalle,bool $anulada): void {
        $y=$this->GetY(); $x=19;
        $this->SetFillColor(248,250,252); $this->Rect(19,$y,175,$detalle['alto'],'F');
        $this->SetDrawColor(...($anulada?[240,184,178]:[230,209,138])); $this->SetLineWidth(.7);
        $this->Line(19,$y,19,$y+$detalle['alto']);
        foreach ($detalle['celdas'] as $i=>$lineas) {
            $color=$anulada?$this->suave:($i===1||$i===3||$i===4?$this->gris:$this->oscuro);
            $this->texto($x+1,$y+.3,$this->anchosDetalle[$i]-2,$lineas,7,$i===3&&$detalle['sin_vencimiento']?'I':'',$i===2||$i>=4?'R':'L',$color,3.3,$anulada&&$i>=4);
            $x+=$this->anchosDetalle[$i];
        }
        $this->SetY($y+$detalle['alto']);
    }

    private function compra(array $compra): void {
        $preparada=$this->prepararCompra($compra);
        $capacidad=$this->limiteContenido-$this->inicioContinuacion()-15;
        if ($preparada['alto']<=$capacidad) $this->espacio($preparada['alto'],true);
        else $this->espacio($preparada['alto_cabecera']+4+$preparada['detalles'][0]['alto']+8,true);
        $this->cabeceraCompra($compra,$preparada);
        $ultimo=count($preparada['detalles'])-1;
        foreach ($preparada['detalles'] as $indice=>$detalle) {
            $reserva=$detalle['alto']+($indice===$ultimo?8:0);
            if ($this->GetY()+$reserva>$this->limiteContenido) {
                $this->siguientePagina(true); $this->cabeceraCompra($compra,$preparada,true);
            }
            if ($this->GetY()+$reserva>$this->limiteContenido) throw new DomainException('Un detalle excede el espacio disponible de una página.');
            $this->detalleCompra($detalle,$compra['estado']==='Anulada');
        }
        $y=$this->GetY(); $anulada=$compra['estado']==='Anulada'; $color=$anulada?$this->suave:$this->oscuro;
        $this->SetFillColor(248,250,252); $this->Rect(19,$y,175,6,'F');
        $this->SetDrawColor(226,232,240); $this->SetLineWidth(.2); $this->Line(125,$y,191,$y);
        $this->texto(110,$y+1,57,['Importe total de la compra'],7,'B','R',$color);
        $this->texto(167,$y+1,26,[$this->moneda($compra['total_documento'])],7.5,'B','R',$color,3.3,$anulada);
        $this->Line(16,$y+7,194,$y+7); $this->SetY($y+8);
    }

    public function generar(array $reporte): string {
        if (empty($reporte['compras'])) throw new DomainException('No hay compras registradas en el periodo o filtro seleccionado para imprimir');
        $this->reporte=$reporte;
        $this->SetTitle($reporte['metadatos']['titulo']); $this->SetAuthor($reporte['metadatos']['negocio']);
        $this->AddPage(); $this->metadatos(); $this->resumen();
        $primera = $this->prepararCompra($reporte['compras'][0]);
        $capacidad = $this->limiteContenido - $this->inicioContinuacion() - 15;
        $minimo = $primera['alto'] <= $capacidad ? $primera['alto'] : $primera['alto_cabecera'] + 4 + $primera['detalles'][0]['alto'] + 8;
        $this->espacio(15 + $minimo); $this->encabezadoListado();
        foreach ($reporte['compras'] as $compra) $this->compra($compra);
        $this->espacio(23);
        $y=$this->GetY()+3; $this->SetDrawColor(...$this->dorado); $this->SetLineWidth(.35); $this->Line(16,$y,194,$y);
        $r=$reporte['resumen'];
        foreach (['Total registros:'=>$this->numero((string)$r['total_registros']),'Importe total comprado:'=>$this->moneda($r['total_validas']),'Importe de compras anuladas:'=>$this->moneda($r['total_anuladas'])] as $titulo=>$valor) {
            $y+=5; $this->texto(108,$y,58,[$titulo],8,'B','R'); $this->texto(166,$y,28,[$valor],8,'B','R',$this->dorado);
        }
        return $this->Output('S');
    }
}