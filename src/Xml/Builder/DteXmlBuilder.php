<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Xml\Builder;

use RoyaltyFusion\Sii\Calculator\Calculation;
use RoyaltyFusion\Sii\Model\AbstractDocument;
use RoyaltyFusion\Sii\Model\Customs;
use RoyaltyFusion\Sii\Model\Dte;
use RoyaltyFusion\Sii\Xml\XmlElement;

/**
 * Genera el nodo Documento / Exportaciones de DTE_v10.xsd respetando el orden exacto de los elementos.
 */
final class DteXmlBuilder extends AbstractDocumentBuilder
{
    protected function documento(AbstractDocument $document, Calculation $calculation): XmlElement
    {
        \assert($document instanceof Dte);
        $export = $document->getTipo()->isExportacion();
        $doc = XmlElement::create($document->getTipo()->xmlNode(), ['ID' => $document->getId()]);

        $encabezado = $doc->child('Encabezado');
        $this->idDoc($encabezado, $document, $export);
        $this->emisor($encabezado, $document, $export);
        if (!$export) {
            $encabezado->leaf('RUTMandante', self::rut($document->getRutMandante()));
        }
        $this->receptor($encabezado, $document, $export);
        if (!$export) {
            $encabezado->leaf('RUTSolicita', self::rut($document->getRutSolicita()));
        }
        $this->transporte($encabezado, $document, $export);
        $this->totales($encabezado, $document, $calculation, $export);
        $this->otraMoneda($encabezado, $calculation, $export);

        $dec = $calculation->decimales;
        foreach ($calculation->lineas as $linea) {
            $d = $linea->detalle;
            $det = $doc->child('Detalle');
            $det->leaf('NroLinDet', $linea->numero);
            foreach ($d->getCodigos() as $codigo) {
                $det->child('CdgItem')->leaf('TpoCodigo', self::txt($codigo->getTipo(), 10))->leaf('VlrCodigo', self::txt($codigo->getValor(), 35));
            }
            $det->leaf('IndExe', $d->getIndicadorExencion())
                ->leaf('NmbItem', self::txt($d->getNombre(), 80))
                ->leaf('DscItem', self::txt($d->getDescripcion(), 1000))
                ->leaf('QtyRef', self::dec($d->getCantidadReferencia(), 6))
                ->leaf('UnmdRef', self::txt($d->getUnidadReferencia(), 4))
                ->leaf('PrcRef', self::dec($d->getPrecioReferencia(), 6))
                ->leaf('QtyItem', $d->getPrecio() !== null ? self::dec($d->getCantidad() ?? 1, 6) : self::dec($d->getCantidad(), 6))
                ->leaf('FchElabor', self::date($d->getFechaElaboracion()))
                ->leaf('FchVencim', self::date($d->getFechaVencimiento()))
                ->leaf('UnmdItem', self::txt($d->getUnidad(), 4))
                ->leaf('PrcItem', $d->getPrecio() !== null && $d->getPrecio() > 0 ? self::dec($d->getPrecio(), 6) : null)
                ->leaf('DescuentoPct', $linea->descuentoMonto > 0 ? self::dec($d->getDescuentoPorcentaje(), 2) : null)
                ->leaf('DescuentoMonto', self::positive($linea->descuentoMonto))
                ->leaf('RecargoPct', $linea->recargoMonto > 0 ? self::dec($d->getRecargoPorcentaje(), 2) : null)
                ->leaf('RecargoMonto', self::positive($linea->recargoMonto));
            if (!$export) {
                foreach (array_values(array_unique($d->getImpuestosAdicionales())) as $codigo) {
                    $det->leaf('CodImpAdic', $codigo);
                }
            }
            $det->leaf('MontoItem', self::dec($linea->montoItem, $dec));
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
            $r = $doc->child('Referencia')
                ->leaf('NroLinRef', $i + 1)
                ->leaf('TpoDocRef', self::txt($ref->getTipoDocumento(), 3))
                ->leaf('IndGlobal', $ref->isGlobal() ? 1 : null)
                ->leaf('FolioRef', self::txt($ref->getFolio(), 18))
                ->leaf('RUTOtr', self::rut($ref->getRutOtro()));
            if ($export) {
                $r->leaf('IdAdicOtr', self::txt($ref->getIdAdicionalOtro(), 20));
            }
            $r->leaf('FchRef', self::date($ref->getFecha()))
                ->leaf('CodRef', $ref->getCodigo())
                ->leaf('RazonRef', self::txt($ref->getRazon(), 90));
        }

        return $doc;
    }

    private function idDoc(XmlElement $encabezado, Dte $dte, bool $export): void
    {
        $id = $encabezado->child('IdDoc')
            ->leaf('TipoDTE', $dte->getTipo()->value)
            ->leaf('Folio', $dte->getFolio())
            ->leaf('FchEmis', self::date($dte->getFechaEmision()));
        if (!$export) {
            $id->leaf('IndNoRebaja', $dte->isIndicadorNoRebaja() ? 1 : null);
        }
        $id->leaf('TipoDespacho', $dte->getTipoDespacho());
        if (!$export) {
            $id->leaf('IndTraslado', $dte->getIndicadorTraslado())
                ->leaf('TpoImpresion', $dte->getTipoImpresion());
        }
        $id->leaf('IndServicio', $dte->getIndicadorServicio());
        if (!$export) {
            $id->leaf('MntBruto', $dte->isMontoBruto() ? 1 : null)
                ->leaf('TpoTranCompra', $dte->getTipoTransaccionCompra())
                ->leaf('TpoTranVenta', $dte->getTipoTransaccionVenta());
        }
        $id->leaf('FmaPago', $dte->getFormaPago())
            ->leaf('FmaPagExp', $export ? $dte->getFormaPagoExportacion() : null)
            ->leaf('FchCancel', self::date($dte->getFechaCancelacion()))
            ->leaf('MntCancel', $dte->getMontoCancelado())
            ->leaf('SaldoInsol', $dte->getSaldoInsoluto());
        foreach ($dte->getPagos() as $pago) {
            $id->child('MntPagos')
                ->leaf('FchPago', self::date($pago->getFecha()))
                ->leaf('MntPago', $pago->getMonto())
                ->leaf('GlosaPagos', self::txt($pago->getGlosa(), 40));
        }
        $id->leaf('PeriodoDesde', self::date($dte->getPeriodoDesde()))
            ->leaf('PeriodoHasta', self::date($dte->getPeriodoHasta()))
            ->leaf('MedioPago', $dte->getMedioPago())
            ->leaf('TpoCtaPago', $dte->getTipoCuentaPago())
            ->leaf('NumCtaPago', self::txt($dte->getNumeroCuentaPago(), 20))
            ->leaf('BcoPago', self::txt($dte->getBancoPago(), 40))
            ->leaf('TermPagoCdg', self::txt($dte->getTerminoPagoCodigo(), 4))
            ->leaf('TermPagoGlosa', self::txt($dte->getTerminoPagoGlosa(), 100))
            ->leaf('TermPagoDias', $dte->getTerminoPagoDias())
            ->leaf('FchVenc', self::date($dte->getFechaVencimiento()));
    }

    private function emisor(XmlElement $encabezado, Dte $dte, bool $export): void
    {
        $e = $dte->getEmisor();
        \assert($e !== null);
        $em = $encabezado->child('Emisor')
            ->leaf('RUTEmisor', self::rut($e->getRut()))
            ->leaf('RznSoc', self::txt($e->getRazonSocial(), 100))
            ->leaf('GiroEmis', self::txt($e->getGiro(), 80));
        foreach (\array_slice($e->getTelefonos(), 0, 2) as $telefono) {
            $em->leaf('Telefono', self::txt($telefono, 20));
        }
        $em->leaf('CorreoEmisor', self::txt($e->getEmail(), 80));
        foreach (\array_slice($e->getActecos(), 0, 4) as $acteco) {
            $em->leaf('Acteco', $acteco);
        }
        $em->leaf('Sucursal', self::txt($e->getSucursal(), 20))
            ->leaf('CdgSIISucur', $e->getCodigoSucursal())
            ->leaf('DirOrigen', self::txt($e->getDireccion(), 70))
            ->leaf('CmnaOrigen', self::txt($e->getComuna(), 20))
            ->leaf('CiudadOrigen', self::txt($e->getCiudad(), 20))
            ->leaf('CdgVendedor', self::txt($e->getCodigoVendedor(), 60))
            ->leaf('IdAdicEmisor', self::txt($e->getIdAdicional(), 20));
    }

    private function receptor(XmlElement $encabezado, Dte $dte, bool $export): void
    {
        $r = $dte->getReceptor();
        \assert($r !== null);
        $rec = $encabezado->child('Receptor')
            ->leaf('RUTRecep', self::rut($r->getRut()))
            ->leaf('CdgIntRecep', self::txt($r->getCodigoInterno(), 20))
            ->leaf('RznSocRecep', self::txt($r->getRazonSocial(), 100));
        if (($ext = $r->getExtranjero()) !== null) {
            $x = $rec->child('Extranjero')
                ->leaf('NumId', self::txt($ext->getNumeroId(), 20))
                ->leaf('Nacionalidad', self::txt($ext->getNacionalidad(), 3));
            $export ? $x->leaf('IdAdicRecep', self::txt($ext->getIdAdicional(), 20)) : $x->leaf('TipoDocID', $ext->getTipoDocumentoId());
        }
        $rec->leaf('GiroRecep', self::txt($r->getGiro(), 40))
            ->leaf('Contacto', self::txt($r->getContacto(), 80))
            ->leaf('CorreoRecep', self::txt($r->getEmail(), 80))
            ->leaf('DirRecep', self::txt($r->getDireccion(), 70))
            ->leaf('CmnaRecep', self::txt($r->getComuna(), 20))
            ->leaf('CiudadRecep', self::txt($r->getCiudad(), 20))
            ->leaf('DirPostal', self::txt($r->getDireccionPostal(), 70))
            ->leaf('CmnaPostal', self::txt($r->getComunaPostal(), 20))
            ->leaf('CiudadPostal', self::txt($r->getCiudadPostal(), 20));
        $rec->prune();
    }

    private function transporte(XmlElement $encabezado, Dte $dte, bool $export): void
    {
        $t = $dte->getTransporte();
        if ($t === null) {
            return;
        }
        $tr = $encabezado->child('Transporte')->leaf('Patente', self::txt($t->getPatente(), 8));
        if (!$export) {
            $tr->leaf('PatenteCarro', self::txt($t->getPatenteCarro(), 8));
        }
        $tr->leaf('RUTTrans', self::rut($t->getRutTransportista()));
        if (($chofer = $t->getChofer()) !== null) {
            $tr->child('Chofer')->leaf('RUTChofer', self::rut($chofer->getRut()))->leaf('NombreChofer', self::txt($chofer->getNombre(), 30));
        }
        $tr->leaf('DirDest', self::txt($t->getDireccionDestino(), 70))
            ->leaf('CmnaDest', self::txt($t->getComunaDestino(), 20))
            ->leaf('CiudadDest', self::txt($t->getCiudadDestino(), 20));
        if (($aduana = $t->getAduana()) !== null) {
            $this->aduana($tr->child('Aduana'), $aduana);
        }
        $tr->prune();
        if ($tr->isEmpty()) {
            $encabezado->prune();
        }
    }

    private function aduana(XmlElement $a, Customs $c): void
    {
        $a->leaf('CodModVenta', $c->getCodigoModalidadVenta())
            ->leaf('CodClauVenta', $c->getCodigoClausulaVenta())
            ->leaf('TotClauVenta', self::dec($c->getTotalClausulaVenta(), 2))
            ->leaf('CodViaTransp', $c->getCodigoViaTransporte())
            ->leaf('NombreTransp', self::txt($c->getNombreTransporte(), 40))
            ->leaf('RUTCiaTransp', self::rut($c->getRutCompaniaTransporte()))
            ->leaf('NomCiaTransp', self::txt($c->getNombreCompaniaTransporte(), 40))
            ->leaf('IdAdicTransp', self::txt($c->getIdAdicionalTransporte(), 20))
            ->leaf('Booking', self::txt($c->getBooking(), 20))
            ->leaf('Operador', self::txt($c->getOperador(), 20))
            ->leaf('CodPtoEmbarque', $c->getCodigoPuertoEmbarque())
            ->leaf('IdAdicPtoEmb', self::txt($c->getIdAdicionalPuertoEmbarque(), 20))
            ->leaf('CodPtoDesemb', $c->getCodigoPuertoDesembarque())
            ->leaf('IdAdicPtoDesemb', self::txt($c->getIdAdicionalPuertoDesembarque(), 20))
            ->leaf('Tara', $c->getTara())
            ->leaf('CodUnidMedTara', $c->getCodigoUnidadTara())
            ->leaf('PesoBruto', self::dec($c->getPesoBruto(), 2))
            ->leaf('CodUnidPesoBruto', $c->getCodigoUnidadPesoBruto())
            ->leaf('PesoNeto', self::dec($c->getPesoNeto(), 2))
            ->leaf('CodUnidPesoNeto', $c->getCodigoUnidadPesoNeto())
            ->leaf('TotItems', $c->getTotalItems())
            ->leaf('TotBultos', $c->getTotalBultos());
        foreach ($c->getBultos() as $b) {
            $a->child('TipoBultos')
                ->leaf('CodTpoBultos', $b->getCodigoTipo())
                ->leaf('CantBultos', $b->getCantidad())
                ->leaf('Marcas', self::txt($b->getMarcas(), 255))
                ->leaf('IdContainer', self::txt($b->getIdContainer(), 25))
                ->leaf('Sello', self::txt($b->getSello(), 20))
                ->leaf('EmisorSello', self::txt($b->getEmisorSello(), 70));
        }
        $a->leaf('MntFlete', self::dec($c->getMontoFlete(), 4))
            ->leaf('MntSeguro', self::dec($c->getMontoSeguro(), 4))
            ->leaf('CodPaisRecep', $c->getCodigoPaisReceptor())
            ->leaf('CodPaisDestin', $c->getCodigoPaisDestino());
    }

    private function totales(XmlElement $encabezado, Dte $dte, Calculation $calculation, bool $export): void
    {
        $t = $calculation->totales;
        $tot = $encabezado->child('Totales');
        if ($export) {
            $tot->leaf('TpoMoneda', $dte->getTipoMoneda())
                ->leaf('MntExe', self::dec($t->montoExento ?? 0, $calculation->decimales))
                ->leaf('MntTotal', self::dec($t->montoTotal, $calculation->decimales));

            return;
        }
        $tot->leaf('MntNeto', $t->montoNeto)
            ->leaf('MntExe', $t->montoExento)
            ->leaf('TasaIVA', self::dec($t->tasaIva, 2))
            ->leaf('IVA', $t->iva);
        foreach ($t->impuestos as $imp) {
            $tot->child('ImptoReten')
                ->leaf('TipoImp', $imp->codigo)
                ->leaf('TasaImp', self::dec($imp->tasa, 2))
                ->leaf('MontoImp', $imp->monto);
        }
        $tot->leaf('GrntDep', $t->garantiaDeposito)
            ->leaf('MntTotal', (int) $t->montoTotal)
            ->leaf('MontoNF', $t->montoNoFacturable);
    }

    private function otraMoneda(XmlElement $encabezado, Calculation $calculation, bool $export): void
    {
        $o = $calculation->otraMoneda;
        if ($o === null) {
            return;
        }
        $om = $encabezado->child('OtraMoneda')
            ->leaf('TpoMoneda', $o->tipoMoneda)
            ->leaf('TpoCambio', self::dec($o->tipoCambio, 4));
        if (!$export) {
            $om->leaf('MntNetoOtrMnda', self::positiveDec($o->montoNeto));
        }
        $om->leaf('MntExeOtrMnda', $export ? self::dec($o->montoExento, 4) : self::positiveDec($o->montoExento));
        if (!$export) {
            $om->leaf('IVAOtrMnda', self::positiveDec($o->iva));
        }
        $om->leaf('MntTotOtrMnda', self::dec($o->montoTotal, 4));
    }

    /** Dec14_4Type no admite 0. */
    private static function positiveDec(float|int|null $value): ?string
    {
        return $value === null || $value <= 0 ? null : self::dec($value, 4);
    }
}
