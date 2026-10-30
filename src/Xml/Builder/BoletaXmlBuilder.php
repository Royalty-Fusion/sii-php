<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Xml\Builder;

use RoyaltyFusion\Sii\Calculator\Calculation;
use RoyaltyFusion\Sii\Model\AbstractDocument;
use RoyaltyFusion\Sii\Model\Boleta;
use RoyaltyFusion\Sii\Util\Rut;
use RoyaltyFusion\Sii\Xml\XmlElement;

/** Genera el nodo Documento de una boleta según BOLETADefType (EnvioBOLETA_v11.xsd). */
final class BoletaXmlBuilder extends AbstractDocumentBuilder
{
    protected function documento(AbstractDocument $document, Calculation $calculation): XmlElement
    {
        \assert($document instanceof Boleta);
        $doc = XmlElement::create('Documento', ['ID' => $document->getId()]);
        $enc = $doc->child('Encabezado');

        $enc->child('IdDoc')
            ->leaf('TipoDTE', $document->getTipo()->value)
            ->leaf('Folio', $document->getFolio())
            ->leaf('FchEmis', self::date($document->getFechaEmision()))
            ->leaf('IndServicio', $document->getIndicadorServicio())
            ->leaf('IndMntNeto', $document->isMontosNetos() ? 2 : null)
            ->leaf('PeriodoDesde', self::date($document->getPeriodoDesde()))
            ->leaf('PeriodoHasta', self::date($document->getPeriodoHasta()))
            ->leaf('FchVenc', self::date($document->getFechaVencimiento()))
            ->leaf('MedioPago', $document->getMedioPago());

        $e = $document->getEmisor();
        \assert($e !== null);
        $enc->child('Emisor')
            ->leaf('RUTEmisor', self::rut($e->getRut()))
            ->leaf('RznSocEmisor', self::txt($e->getRazonSocial(), 100))
            ->leaf('GiroEmisor', self::txt($e->getGiro(), 80))
            ->leaf('CdgSIISucur', $e->getCodigoSucursal())
            ->leaf('DirOrigen', self::txt($e->getDireccion(), 70))
            ->leaf('CmnaOrigen', self::txt($e->getComuna(), 20))
            ->leaf('CiudadOrigen', self::txt($e->getCiudad(), 20));

        $r = $document->getReceptor();
        $enc->child('Receptor')
            ->leaf('RUTRecep', self::rut($r?->getRut()) ?? Rut::CONSUMIDOR_FINAL)
            ->leaf('CdgIntRecep', self::txt($r?->getCodigoInterno(), 20))
            ->leaf('RznSocRecep', self::txt($r?->getRazonSocial(), 100))
            ->leaf('Contacto', self::txt($r?->getContacto(), 80))
            ->leaf('CorreoRecep', self::txt($r?->getEmail(), 80))
            ->leaf('TelefonoRecep', self::txt($r?->getTelefono(), 20))
            ->leaf('DirRecep', self::txt($r?->getDireccion(), 70))
            ->leaf('CmnaRecep', self::txt($r?->getComuna(), 20))
            ->leaf('CiudadRecep', self::txt($r?->getCiudad(), 20))
            ->leaf('DirPostal', self::txt($r?->getDireccionPostal(), 70))
            ->leaf('CmnaPostal', self::txt($r?->getComunaPostal(), 20))
            ->leaf('CiudadPostal', self::txt($r?->getCiudadPostal(), 20));

        $enc->leaf('RUTProvSW', self::rut($document->getRutProveedorSoftware()));

        $t = $calculation->totales;
        $enc->child('Totales')
            ->leaf('MntNeto', $t->montoNeto)
            ->leaf('MntExe', $t->montoExento)
            ->leaf('IVA', $t->iva)
            ->leaf('MntTotal', (int) $t->montoTotal)
            ->leaf('MontoNF', $t->montoNoFacturable)
            ->leaf('SaldoAnterior', $t->saldoAnterior)
            ->leaf('VlrPagar', $t->valorPagar);

        foreach ($calculation->lineas as $linea) {
            $d = $linea->detalle;
            $det = $doc->child('Detalle')->leaf('NroLinDet', $linea->numero);
            foreach ($d->getCodigos() as $codigo) {
                $det->child('CdgItem')->leaf('TpoCodigo', self::txt($codigo->getTipo(), 10))->leaf('VlrCodigo', self::txt($codigo->getValor(), 35));
            }
            $det->leaf('IndExe', $d->getIndicadorExencion())
                ->leaf('ItemEspectaculo', $d->getItemEspectaculo() === null ? null : sprintf('%02d', $d->getItemEspectaculo()))
                ->leaf('RUTMandante', self::rut($d->getRutMandante()))
                ->leaf('NmbItem', self::txt($d->getNombre(), 80))
                ->leaf('DscItem', self::txt($d->getDescripcion(), 1000))
                ->leaf('QtyItem', $d->getPrecio() !== null ? self::dec($d->getCantidad() ?? 1, 6) : self::dec($d->getCantidad(), 6))
                ->leaf('UnmdItem', self::txt($d->getUnidad(), 4))
                ->leaf('PrcItem', $d->getPrecio() !== null && $d->getPrecio() > 0 ? self::dec($d->getPrecio(), 6) : null)
                ->leaf('DescuentoPct', $linea->descuentoMonto > 0 ? self::dec($d->getDescuentoPorcentaje(), 2) : null)
                ->leaf('DescuentoMonto', self::positive($linea->descuentoMonto))
                ->leaf('RecargoPct', $linea->recargoMonto > 0 ? self::dec($d->getRecargoPorcentaje(), 2) : null)
                ->leaf('RecargoMonto', self::positive($linea->recargoMonto))
                ->leaf('MontoItem', (int) $linea->montoItem);
        }

        foreach ($calculation->descuentosGlobales as $global) {
            $g = $global->descuento;
            $doc->child('DscRcgGlobal')
                ->leaf('NroLinDR', $global->numero)
                ->leaf('TpoMov', $g->getTipo())
                ->leaf('GlosaDR', self::txt($g->getGlosa(), 45))
                ->leaf('TpoValor', $g->getTipoValor())
                ->leaf('ValorDR', self::dec($g->getValor(), 2))
                ->leaf('IndExeDR', $g->getIndicadorExencion());
        }

        foreach ($document->getReferencias() as $i => $ref) {
            $doc->child('Referencia')
                ->leaf('NroLinRef', $i + 1)
                ->leaf('TpoDocRef', self::txt($ref->getTipoDocumento(), 3))
                ->leaf('FolioRef', self::txt($ref->getFolio(), 18))
                ->leaf('CodRef', self::txt($ref->getCodigoBoleta() ?? ($ref->getCodigo() !== null ? (string) $ref->getCodigo() : null), 18))
                ->leaf('RazonRef', self::txt($ref->getRazon(), 90))
                ->leaf('CodVndor', self::txt($ref->getCodigoVendedor(), 8))
                ->leaf('CodCaja', self::txt($ref->getCodigoCaja(), 8));
        }

        return $doc;
    }
}
