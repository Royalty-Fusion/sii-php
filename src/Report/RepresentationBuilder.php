<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Report;

use RoyaltyFusion\Sii\Model\Boleta;
use RoyaltyFusion\Sii\Model\DocumentType;
use RoyaltyFusion\Sii\Model\Dte;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\PreparedDocument;
use RoyaltyFusion\Sii\Util\Rut;
use RoyaltyFusion\Sii\Util\Text;

/** Construye RepresentationData sin depender de librerías de PDF ni de códigos de barra. */
final class RepresentationBuilder
{
    /** Nivel de corrección de errores del PDF417 exigido por el SII. */
    public const PDF417_ECL = 5;
    public const PDF417_ASPECT_RATIO = 3;

    public function build(PreparedDocument $prepared, ?Resolution $resolution = null, ?string $oficinaSii = null): RepresentationData
    {
        $doc = $prepared->document;
        $calc = $prepared->calculation;
        $t = $calc->totales;
        $e = $doc->getEmisor();
        $r = $doc->getReceptor();
        $s = static fn (?string $v): ?string => $v === null || Text::sanitize($v) === '' ? null : Text::sanitize($v);
        $rut = static fn (?string $v): ?string => $v !== null && Rut::isValid($v) ? Rut::normalize($v) : $v;
        $fmt = static fn (?string $v): ?string => $v !== null && Rut::isValid($v) ? Rut::parse($v)->format(true) : $v;

        $ted = (string) $prepared->getTed();
        $documento = [
            'id' => $doc->getId(),
            'tipo' => $doc->getTipo()->value,
            'nombre' => $doc->getTipo()->label(),
            'folio' => $doc->getFolio(),
            'fecha_emision' => $doc->getFechaEmision()?->format('Y-m-d'),
            'fecha_vencimiento' => $doc->getFechaVencimiento()?->format('Y-m-d'),
            'decimales' => $calc->decimales,
            'boleta' => $doc instanceof Boleta,
            'exportacion' => $doc->getTipo()->isExportacion(),
        ];
        if ($doc instanceof Dte) {
            $documento += [
                'forma_pago' => $doc->getFormaPago(),
                'medio_pago' => $doc->getMedioPago(),
                'indicador_traslado' => $doc->getIndicadorTraslado(),
                'tipo_despacho' => $doc->getTipoDespacho(),
                'moneda' => $doc->getTipoMoneda(),
            ];
        }

        $detalle = [];
        foreach ($calc->lineas as $l) {
            $d = $l->detalle;
            $detalle[] = [
                'numero' => $l->numero,
                'codigo' => $d->getCodigos() !== [] ? $d->getCodigos()[0]->getValor() : null,
                'nombre' => $s($d->getNombre()),
                'descripcion' => $s($d->getDescripcion()),
                'cantidad' => $d->getCantidad(),
                'unidad' => $s($d->getUnidad()),
                'precio' => $d->getPrecio(),
                'descuento_porcentaje' => $d->getDescuentoPorcentaje(),
                'descuento' => $l->descuentoMonto,
                'recargo' => $l->recargoMonto,
                'monto' => $l->montoItem,
                'indicador_exencion' => $d->getIndicadorExencion(),
                'exento' => $d->isExento(),
            ];
        }

        $globales = [];
        foreach ($calc->descuentosGlobales as $g) {
            $globales[] = [
                'tipo' => $g->descuento->getTipo(),
                'tipo_valor' => $g->descuento->getTipoValor(),
                'valor' => $g->descuento->getValor(),
                'glosa' => $s($g->descuento->getGlosa()),
                'monto' => $g->monto,
            ];
        }

        $referencias = [];
        foreach ($doc->getReferencias() as $ref) {
            $referencias[] = [
                'tipo' => $ref->getTipoDocumento(),
                'folio' => $ref->getFolio(),
                'fecha' => $ref->getFecha()?->format('Y-m-d'),
                'codigo' => $ref->getCodigo() ?? $ref->getCodigoBoleta(),
                'razon' => $s($ref->getRazon()),
            ];
        }

        $otra = $calc->otraMoneda;

        return new RepresentationData(
            documento: $documento,
            emisor: [
                'rut' => $rut($e?->getRut()),
                'rut_formateado' => $fmt($e?->getRut()),
                'razon_social' => $s($e?->getRazonSocial()),
                'giro' => $s($e?->getGiro()),
                'actecos' => $e?->getActecos() ?? [],
                'direccion' => $s($e?->getDireccion()),
                'comuna' => $s($e?->getComuna()),
                'ciudad' => $s($e?->getCiudad()),
                'telefonos' => $e?->getTelefonos() ?? [],
                'email' => $s($e?->getEmail()),
                'sucursal' => $s($e?->getSucursal()),
                'codigo_sucursal' => $e?->getCodigoSucursal(),
            ],
            receptor: $r === null ? null : [
                'rut' => $rut($r->getRut()),
                'rut_formateado' => $fmt($r->getRut()),
                'razon_social' => $s($r->getRazonSocial()),
                'giro' => $s($r->getGiro()),
                'direccion' => $s($r->getDireccion()),
                'comuna' => $s($r->getComuna()),
                'ciudad' => $s($r->getCiudad()),
                'contacto' => $s($r->getContacto()),
                'email' => $s($r->getEmail()),
                'consumidor_final' => $r->getRut() !== null && Rut::isConsumidorFinal($r->getRut()),
            ],
            detalle: $detalle,
            descuentosGlobales: $globales,
            totales: [
                'monto_neto' => $t->montoNeto,
                'monto_exento' => $t->montoExento,
                'tasa_iva' => $t->tasaIva,
                'iva' => $t->iva,
                'impuestos' => array_map(static fn ($i) => ['codigo' => $i->codigo, 'tasa' => $i->tasa, 'monto' => $i->monto, 'retencion' => $i->retencion], $t->impuestos),
                'garantia_deposito' => $t->garantiaDeposito,
                'monto_no_facturable' => $t->montoNoFacturable,
                'monto_total' => $t->montoTotal,
                'moneda' => $t->tipoMoneda,
                'otra_moneda' => $otra === null ? null : [
                    'moneda' => $otra->tipoMoneda,
                    'tipo_cambio' => $otra->tipoCambio,
                    'monto_neto' => $otra->montoNeto,
                    'monto_exento' => $otra->montoExento,
                    'iva' => $otra->iva,
                    'monto_total' => $otra->montoTotal,
                ],
            ],
            referencias: $referencias,
            resolucion: $resolution === null ? null : [
                'numero' => $resolution->numero,
                'fecha' => $resolution->fecha->format('Y-m-d'),
                'texto' => sprintf('Res. %d de %s', $resolution->numero, $resolution->fecha->format('Y')),
            ],
            oficinaSii: $oficinaSii === null ? null : mb_strtoupper(Text::sanitize($oficinaSii), 'UTF-8'),
            ted: $ted,
            tedLatin1: Text::toLatin1($ted),
            pdf417: [
                'nivel_correccion' => self::PDF417_ECL,
                'proporcion' => self::PDF417_ASPECT_RATIO,
                'tcpdf_type' => sprintf('PDF417,%d,%d', self::PDF417_ASPECT_RATIO, self::PDF417_ECL),
                // Tamaño sugerido para el área del timbre; ajústelo al formato de su documento.
                'ancho_mm' => 70,
                'alto_mm' => 25,
            ],
            leyendaTimbre: 'Timbre Electrónico SII'.($resolution !== null ? sprintf(' - Res. %d de %s', $resolution->numero, $resolution->fecha->format('Y')) : '').' - Verifique documento: www.sii.cl',
            admiteCedible: \in_array($doc->getTipo(), [DocumentType::FACTURA, DocumentType::FACTURA_EXENTA, DocumentType::GUIA_DESPACHO], true),
        );
    }
}
