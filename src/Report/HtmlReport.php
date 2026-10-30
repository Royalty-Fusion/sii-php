<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Report;

use RoyaltyFusion\Sii\Model\Boleta;
use RoyaltyFusion\Sii\Model\Dte;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\PreparedDocument;
use RoyaltyFusion\Sii\Util\Rut;
use RoyaltyFusion\Sii\Util\Text;

/**
 * Representación impresa (HTML) de un DTE con su timbre PDF417.
 *
 * El HTML puede convertirse a PDF con Dompdf, wkhtmltopdf, Chrome headless, etc.
 */
final class HtmlReport
{
    public function __construct(private readonly TimbreGenerator $timbre = new TimbreGenerator())
    {
    }

    /**
     * @param array{
     *     resolucion?: Resolution,
     *     unidad_sii?: string,
     *     logo?: string,
     *     cedible?: bool,
     *     pie?: string
     * } $options unidad_sii: oficina del SII (ej. "SANTIAGO CENTRO"); logo: URL o data URI; cedible: copia cedible
     */
    public function render(PreparedDocument $prepared, array $options = []): string
    {
        $doc = $prepared->document;
        $calc = $prepared->calculation;
        $t = $calc->totales;
        $e = $doc->getEmisor();
        $r = $doc->getReceptor();
        $dec = $calc->decimales;
        $money = static fn (float|int|null $v): string => $v === null ? '' : number_format((float) $v, $dec, ',', '.');
        $qty = static fn (float|int|null $v): string => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 6, ',', '.'), '0'), ',');
        $h = static fn (?string $v): string => htmlspecialchars(Text::sanitize($v), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $rut = static fn (?string $v): string => $v !== null && Rut::isValid($v) ? Rut::parse($v)->format(true) : (string) $v;

        $timbre = '';
        $ted = $prepared->getTed();
        if ($ted !== null && TimbreGenerator::isAvailable()) {
            $timbre = '<img class="timbre" alt="Timbre Electrónico SII" src="data:image/png;base64,'.base64_encode($this->timbre->png($ted, -3, -3)).'">';
        }
        $resolucion = $options['resolucion'] ?? null;
        $moneda = $doc instanceof Dte && $doc->getTipo()->isExportacion() ? ' '.$h($doc->getTipoMoneda()) : '';

        $rows = '';
        foreach ($calc->lineas as $l) {
            $d = $l->detalle;
            $desc = $d->getDescripcion() ? '<div class="dsc">'.nl2br($h($d->getDescripcion())).'</div>' : '';
            $codigo = $d->getCodigos() !== [] ? $h($d->getCodigos()[0]->getValor()) : '';
            $rows .= '<tr><td>'.$codigo.'</td><td>'.$h($d->getNombre()).($d->isExento() ? ' <small>(exento)</small>' : '').$desc.'</td>'
                .'<td class="n">'.$qty($d->getCantidad()).' '.$h($d->getUnidad()).'</td>'
                .'<td class="n">'.($d->getPrecio() !== null ? $qty($d->getPrecio()) : '').'</td>'
                .'<td class="n">'.($l->descuentoMonto > 0 ? $money($l->descuentoMonto) : '').'</td>'
                .'<td class="n">'.$money($l->montoItem).'</td></tr>';
        }
        foreach ($calc->descuentosGlobales as $g) {
            $rows .= '<tr class="global"><td></td><td colspan="4">'.($g->descuento->isDescuento() ? 'Descuento' : 'Recargo').' global '
                .($g->descuento->isPorcentaje() ? $qty($g->descuento->getValor()).'% ' : '').$h($g->descuento->getGlosa()).'</td><td class="n">'.$money($g->monto).'</td></tr>';
        }

        $refs = '';
        foreach ($doc->getReferencias() as $ref) {
            $refs .= '<li>'.$h($ref->getTipoDocumento()).' N° '.$h($ref->getFolio()).($ref->getFecha() ? ' del '.$ref->getFecha()->format('d-m-Y') : '').($ref->getRazon() ? ': '.$h($ref->getRazon()) : '').'</li>';
        }

        $totales = '';
        $add = static function (string $label, float|int|null $value) use (&$totales, $money): void {
            if ($value !== null) {
                $totales .= '<tr><th>'.$label.'</th><td class="n">$ '.$money($value).'</td></tr>';
            }
        };
        $add('Monto neto', $t->montoNeto);
        $add('Monto exento', $t->montoExento);
        if ($t->iva !== null) {
            $add($doc instanceof Boleta ? 'IVA incluido' : sprintf('IVA (%s%%)', $qty($t->tasaIva ?? 19)), $t->iva);
        }
        foreach ($t->impuestos as $imp) {
            $add(($imp->retencion ? 'Retención ' : 'Impuesto ').$imp->codigo.' ('.$qty($imp->tasa).'%)', $imp->retencion ? -$imp->monto : $imp->monto);
        }
        $totales .= '<tr class="total"><th>TOTAL'.$moneda.'</th><td class="n">$ '.$money($t->montoTotal).'</td></tr>';

        $receptor = $r === null ? '' : '<table class="rec">'
            .'<tr><th>Señor(es)</th><td>'.$h($r->getRazonSocial()).'</td><th>R.U.T.</th><td>'.$rut($r->getRut()).'</td></tr>'
            .'<tr><th>Giro</th><td>'.$h($r->getGiro()).'</td><th>Fecha</th><td>'.$doc->getFechaEmision()?->format('d-m-Y').'</td></tr>'
            .'<tr><th>Dirección</th><td>'.$h($r->getDireccion()).'</td><th>Comuna</th><td>'.$h($r->getComuna()).'</td></tr>'
            .'</table>';
        if ($r === null) {
            $receptor = '<p>Fecha de emisión: '.$doc->getFechaEmision()?->format('d-m-Y').'</p>';
        }

        $title = mb_strtoupper($doc->getTipo()->label(), 'UTF-8');
        $logo = isset($options['logo']) ? '<img class="logo" src="'.htmlspecialchars($options['logo'], \ENT_QUOTES).'" alt="">' : '';
        $cedible = !empty($options['cedible']) ? '<div class="cedible"><p>Nombre: ____________________ R.U.T.: ____________ Fecha: ________ Recinto: ____________ Firma: ____________</p><p>El acuse de recibo que se declara en este acto, de acuerdo a lo dispuesto en la letra b) del Art. 4°, y la letra c) del Art. 5° de la Ley 19.983, acredita que la entrega de mercaderías o servicio(s) prestado(s) ha(n) sido recibido(s).</p><strong>CEDIBLE</strong></div>' : '';
        $res = $resolucion !== null ? sprintf('Res. %d de %s', $resolucion->numero, $resolucion->fecha->format('Y')) : '';

        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>'.$h($title).' N° '.$doc->getFolio().'</title><style>'
            .'body{font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#111;margin:24px}'
            .'.head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}.logo{max-height:70px}'
            .'.emisor h1{font-size:15px;margin:0 0 4px}.box{border:3px solid #c00;color:#c00;text-align:center;padding:8px 16px;min-width:230px;font-weight:bold}'
            .'.box p{margin:4px 0;font-size:13px}.sii{text-align:center;color:#c00;font-weight:bold;margin-top:4px}'
            .'table{border-collapse:collapse;width:100%}.rec{margin:14px 0}.rec th{text-align:left;width:80px}.rec td,.rec th{padding:2px 4px}'
            .'.det th{background:#eee;border:1px solid #999;padding:4px}.det td{border:1px solid #ccc;padding:3px 4px;vertical-align:top}'
            .'.n{text-align:right;white-space:nowrap}.dsc{color:#555;font-size:10px}.global td{font-style:italic}'
            .'.foot{display:flex;justify-content:space-between;margin-top:14px;gap:16px}.tot{width:300px}.tot th{text-align:left;padding:2px 4px}'
            .'.tot td{padding:2px 4px}.tot .total{font-size:13px;border-top:2px solid #111}.timbre{width:300px;display:block}'
            .'.leyenda{text-align:center;font-size:10px;width:300px}.cedible{border:1px solid #111;margin-top:12px;padding:6px;font-size:10px}'
            .'.cedible strong{display:block;text-align:right;font-size:14px}</style></head><body>'
            .'<div class="head"><div class="emisor">'.$logo.'<h1>'.$h($e?->getRazonSocial()).'</h1><div>'.$h($e?->getGiro()).'</div>'
            .'<div>'.$h($e?->getDireccion()).', '.$h($e?->getComuna()).($e?->getCiudad() ? ', '.$h($e->getCiudad()) : '').'</div>'
            .($e?->getTelefonos() ? '<div>Tel: '.$h(implode(' / ', $e->getTelefonos())).'</div>' : '')
            .($e?->getEmail() ? '<div>'.$h($e->getEmail()).'</div>' : '').'</div>'
            .'<div><div class="box"><p>R.U.T.: '.$rut($e?->getRut()).'</p><p>'.$h($title).'</p><p>N° '.$doc->getFolio().'</p></div>'
            .(isset($options['unidad_sii']) ? '<div class="sii">S.I.I. - '.$h(mb_strtoupper($options['unidad_sii'], 'UTF-8')).'</div>' : '').'</div></div>'
            .$receptor
            .'<table class="det"><thead><tr><th>Código</th><th>Descripción</th><th>Cantidad</th><th>Precio</th><th>Desc.</th><th>Valor</th></tr></thead><tbody>'.$rows.'</tbody></table>'
            .($refs !== '' ? '<p><strong>Referencias:</strong></p><ul>'.$refs.'</ul>' : '')
            .'<div class="foot"><div>'.$timbre.'<div class="leyenda">Timbre Electrónico SII<br>'.$res.' - Verifique documento: www.sii.cl</div></div>'
            .'<table class="tot">'.$totales.'</table></div>'
            .$cedible
            .(isset($options['pie']) ? '<p>'.$h($options['pie']).'</p>' : '')
            .'</body></html>';
    }
}
