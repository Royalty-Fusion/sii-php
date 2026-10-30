<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Intercambio;

use RoyaltyFusion\Sii\Exception\XmlException;
use RoyaltyFusion\Sii\Model\AdditionalTax;
use RoyaltyFusion\Sii\Model\Client;
use RoyaltyFusion\Sii\Model\Company;
use RoyaltyFusion\Sii\Model\Customs;
use RoyaltyFusion\Sii\Model\Detail;
use RoyaltyFusion\Sii\Model\DocumentType;
use RoyaltyFusion\Sii\Model\Driver;
use RoyaltyFusion\Sii\Model\Dte;
use RoyaltyFusion\Sii\Model\Foreigner;
use RoyaltyFusion\Sii\Model\GlobalDiscount;
use RoyaltyFusion\Sii\Model\OtherCurrency;
use RoyaltyFusion\Sii\Model\Package;
use RoyaltyFusion\Sii\Model\Payment;
use RoyaltyFusion\Sii\Model\Reference;
use RoyaltyFusion\Sii\Model\TaxTotal;
use RoyaltyFusion\Sii\Model\Totals;
use RoyaltyFusion\Sii\Model\Transport;
use RoyaltyFusion\Sii\Util\Clock;
use RoyaltyFusion\Sii\Xml\XmlUtil;

/**
 * Convierte el XML de un DTE (Documento o Exportaciones) en el modelo del SDK (Dte) y extrae los
 * totales tal como los informó el emisor. Sirve para registrar compras recibidas de proveedores
 * (líneas, referencias a OC/guías, impuestos adicionales) o para releer documentos propios guardados.
 */
final class DteXmlParser
{
    /** Parsea un XML que contiene un único DTE (suelto o el primero de un sobre). */
    public function parse(string $xml): ParsedDte
    {
        $doc = XmlUtil::load(mb_check_encoding($xml, 'UTF-8') ? XmlUtil::stripDeclaration($xml) : $xml);
        $xpath = XmlUtil::xpath($doc);
        $node = XmlUtil::first($xpath, '//sii:DTE/sii:Documento|//sii:DTE/sii:Exportaciones|//sii:DTE/sii:Liquidacion');
        if (!$node instanceof \DOMElement) {
            throw new XmlException('El XML no contiene un DTE (Documento o Exportaciones).');
        }

        return $this->parseNode($xpath, $node);
    }

    public function parseNode(\DOMXPath $xpath, \DOMElement $node): ParsedDte
    {
        $s = static function (string $path, ?\DOMNode $ctx = null) use ($xpath, $node): ?string {
            $value = trim((string) $xpath->evaluate('string('.$path.')', $ctx ?? $node));

            return $value === '' ? null : $value;
        };
        $i = static fn (string $path, ?\DOMNode $ctx = null): ?int => ($v = $s($path, $ctx)) === null ? null : (int) $v;
        $n = static fn (string $path, ?\DOMNode $ctx = null): int|float|null => ($v = $s($path, $ctx)) === null ? null : (str_contains($v, '.') ? (float) $v : (int) $v);
        $d = static function (string $path, ?\DOMNode $ctx = null) use ($s): ?\DateTimeImmutable {
            $v = $s($path, $ctx);
            try {
                return $v === null ? null : Clock::date(substr($v, 0, 10));
            } catch (\Throwable) {
                return null;
            }
        };

        $tipoCodigo = (int) $s('sii:Encabezado/sii:IdDoc/sii:TipoDTE');
        $tipo = DocumentType::tryFrom($tipoCodigo);
        $totales = $this->totales($xpath, $node, $s, $n);

        if ($tipo === null || $tipo->isBoleta()) {
            // Tipo no modelado (ej. 43 Liquidación-Factura): se entregan sólo los datos generales.
            return new ParsedDte($tipoCodigo, (int) $s('sii:Encabezado/sii:IdDoc/sii:Folio'), null, $totales);
        }

        $dte = (new Dte($tipo))
            ->setFolio($i('sii:Encabezado/sii:IdDoc/sii:Folio'))
            ->setFechaEmision($d('sii:Encabezado/sii:IdDoc/sii:FchEmis'))
            ->setIndicadorNoRebaja($s('sii:Encabezado/sii:IdDoc/sii:IndNoRebaja') === '1')
            ->setTipoDespacho($i('sii:Encabezado/sii:IdDoc/sii:TipoDespacho'))
            ->setIndicadorTraslado($i('sii:Encabezado/sii:IdDoc/sii:IndTraslado'))
            ->setTipoImpresion($s('sii:Encabezado/sii:IdDoc/sii:TpoImpresion'))
            ->setIndicadorServicio($i('sii:Encabezado/sii:IdDoc/sii:IndServicio'))
            ->setMontoBruto($s('sii:Encabezado/sii:IdDoc/sii:MntBruto') === '1')
            ->setTipoTransaccionCompra($i('sii:Encabezado/sii:IdDoc/sii:TpoTranCompra'))
            ->setTipoTransaccionVenta($i('sii:Encabezado/sii:IdDoc/sii:TpoTranVenta'))
            ->setFormaPago($i('sii:Encabezado/sii:IdDoc/sii:FmaPago'))
            ->setFormaPagoExportacion($i('sii:Encabezado/sii:IdDoc/sii:FmaPagExp'))
            ->setFechaCancelacion($d('sii:Encabezado/sii:IdDoc/sii:FchCancel'))
            ->setMontoCancelado($i('sii:Encabezado/sii:IdDoc/sii:MntCancel'))
            ->setSaldoInsoluto($i('sii:Encabezado/sii:IdDoc/sii:SaldoInsol'))
            ->setPeriodoDesde($d('sii:Encabezado/sii:IdDoc/sii:PeriodoDesde'))
            ->setPeriodoHasta($d('sii:Encabezado/sii:IdDoc/sii:PeriodoHasta'))
            ->setMedioPago($s('sii:Encabezado/sii:IdDoc/sii:MedioPago'))
            ->setTipoCuentaPago($s('sii:Encabezado/sii:IdDoc/sii:TpoCtaPago'))
            ->setNumeroCuentaPago($s('sii:Encabezado/sii:IdDoc/sii:NumCtaPago'))
            ->setBancoPago($s('sii:Encabezado/sii:IdDoc/sii:BcoPago'))
            ->setTerminoPagoCodigo($s('sii:Encabezado/sii:IdDoc/sii:TermPagoCdg'))
            ->setTerminoPagoGlosa($s('sii:Encabezado/sii:IdDoc/sii:TermPagoGlosa'))
            ->setTerminoPagoDias($i('sii:Encabezado/sii:IdDoc/sii:TermPagoDias'))
            ->setFechaVencimiento($d('sii:Encabezado/sii:IdDoc/sii:FchVenc'))
            ->setRutMandante($s('sii:Encabezado/sii:RUTMandante'))
            ->setRutSolicita($s('sii:Encabezado/sii:RUTSolicita'))
            ->setTipoMoneda($tipo->isExportacion() ? $s('sii:Encabezado/sii:Totales/sii:TpoMoneda') : null)
            ->setMontoTotalEsperado($totales->montoTotal);

        foreach (XmlUtil::elements($xpath, 'sii:Encabezado/sii:IdDoc/sii:MntPagos', $node) as $p) {
            $fecha = $d('sii:FchPago', $p);
            if ($fecha !== null) {
                $dte->addPago(new Payment($fecha, (int) $i('sii:MntPago', $p), $s('sii:GlosaPagos', $p)));
            }
        }

        $em = XmlUtil::first($xpath, 'sii:Encabezado/sii:Emisor', $node);
        $emisor = (new Company())
            ->setRut($s('sii:RUTEmisor', $em))
            ->setRazonSocial($s('sii:RznSoc', $em))
            ->setGiro($s('sii:GiroEmis', $em))
            ->setTelefonos(array_map(static fn (\DOMElement $e) => trim($e->textContent), XmlUtil::elements($xpath, 'sii:Telefono', $em)))
            ->setEmail($s('sii:CorreoEmisor', $em))
            ->setActecos(array_map(static fn (\DOMElement $e) => (int) trim($e->textContent), XmlUtil::elements($xpath, 'sii:Acteco', $em)))
            ->setSucursal($s('sii:Sucursal', $em))
            ->setCodigoSucursal($i('sii:CdgSIISucur', $em))
            ->setDireccion($s('sii:DirOrigen', $em))
            ->setComuna($s('sii:CmnaOrigen', $em))
            ->setCiudad($s('sii:CiudadOrigen', $em))
            ->setCodigoVendedor($s('sii:CdgVendedor', $em))
            ->setIdAdicional($s('sii:IdAdicEmisor', $em));
        $dte->setEmisor($emisor);

        $re = XmlUtil::first($xpath, 'sii:Encabezado/sii:Receptor', $node);
        $receptor = (new Client())
            ->setRut($s('sii:RUTRecep', $re))
            ->setCodigoInterno($s('sii:CdgIntRecep', $re))
            ->setRazonSocial($s('sii:RznSocRecep', $re))
            ->setGiro($s('sii:GiroRecep', $re))
            ->setContacto($s('sii:Contacto', $re))
            ->setEmail($s('sii:CorreoRecep', $re))
            ->setDireccion($s('sii:DirRecep', $re))
            ->setComuna($s('sii:CmnaRecep', $re))
            ->setCiudad($s('sii:CiudadRecep', $re))
            ->setDireccionPostal($s('sii:DirPostal', $re))
            ->setComunaPostal($s('sii:CmnaPostal', $re))
            ->setCiudadPostal($s('sii:CiudadPostal', $re));
        if (($ex = XmlUtil::first($xpath, 'sii:Extranjero', $re)) !== null) {
            $receptor->setExtranjero(new Foreigner($s('sii:NumId', $ex), $s('sii:Nacionalidad', $ex), $i('sii:TipoDocID', $ex), $s('sii:IdAdicRecep', $ex)));
        }
        $dte->setReceptor($receptor);

        if (($tr = XmlUtil::first($xpath, 'sii:Encabezado/sii:Transporte', $node)) !== null) {
            $transporte = (new Transport())
                ->setPatente($s('sii:Patente', $tr))
                ->setPatenteCarro($s('sii:PatenteCarro', $tr))
                ->setRutTransportista($s('sii:RUTTrans', $tr))
                ->setDireccionDestino($s('sii:DirDest', $tr))
                ->setComunaDestino($s('sii:CmnaDest', $tr))
                ->setCiudadDestino($s('sii:CiudadDest', $tr));
            if (($ch = XmlUtil::first($xpath, 'sii:Chofer', $tr)) !== null) {
                $transporte->setChofer(new Driver((string) $s('sii:RUTChofer', $ch), (string) $s('sii:NombreChofer', $ch)));
            }
            if (($ad = XmlUtil::first($xpath, 'sii:Aduana', $tr)) instanceof \DOMElement) {
                $transporte->setAduana($this->aduana($xpath, $ad, $s, $i, $n));
            }
            $dte->setTransporte($transporte);
        }

        if (($om = XmlUtil::first($xpath, 'sii:Encabezado/sii:OtraMoneda', $node)) !== null) {
            $dte->setOtraMoneda(new OtherCurrency(
                (string) $s('sii:TpoMoneda', $om),
                $n('sii:TpoCambio', $om),
                $n('sii:MntNetoOtrMnda', $om),
                $n('sii:MntExeOtrMnda', $om),
                $n('sii:IVAOtrMnda', $om),
                $n('sii:MntTotOtrMnda', $om),
            ));
        }

        foreach ($totales->impuestos as $impuesto) {
            $dte->addImpuestoAdicional(new AdditionalTax($impuesto->codigo, $impuesto->tasa, $impuesto->retencion));
        }
        if ($totales->tasaIva !== null) {
            $dte->setTasaIva($totales->tasaIva);
        }

        foreach (XmlUtil::elements($xpath, 'sii:Detalle', $node) as $det) {
            $detalle = (new Detail())
                ->setNombre($s('sii:NmbItem', $det))
                ->setDescripcion($s('sii:DscItem', $det))
                ->setIndicadorExencion($i('sii:IndExe', $det))
                ->setCantidadReferencia($n('sii:QtyRef', $det))
                ->setUnidadReferencia($s('sii:UnmdRef', $det))
                ->setPrecioReferencia($n('sii:PrcRef', $det))
                ->setCantidad($n('sii:QtyItem', $det))
                ->setFechaElaboracion($d('sii:FchElabor', $det))
                ->setFechaVencimiento($d('sii:FchVencim', $det))
                ->setUnidad($s('sii:UnmdItem', $det))
                ->setPrecio($n('sii:PrcItem', $det))
                ->setDescuentoPorcentaje($n('sii:DescuentoPct', $det))
                ->setDescuentoMonto($n('sii:DescuentoMonto', $det))
                ->setRecargoPorcentaje($n('sii:RecargoPct', $det))
                ->setRecargoMonto($n('sii:RecargoMonto', $det))
                ->setMontoItem($n('sii:MontoItem', $det));
            foreach (XmlUtil::elements($xpath, 'sii:CdgItem', $det) as $cod) {
                $detalle->addCodigo((string) $s('sii:TpoCodigo', $cod), (string) $s('sii:VlrCodigo', $cod));
            }
            foreach (XmlUtil::elements($xpath, 'sii:CodImpAdic', $det) as $imp) {
                $detalle->addImpuestoAdicional(trim($imp->textContent));
            }
            $dte->addDetalle($detalle);
        }

        foreach (XmlUtil::elements($xpath, 'sii:DscRcgGlobal', $node) as $g) {
            $dte->addDescuentoGlobal(new GlobalDiscount(
                (string) $s('sii:TpoMov', $g),
                (string) $s('sii:TpoValor', $g),
                $n('sii:ValorDR', $g) ?? 0,
                $s('sii:GlosaDR', $g),
                $i('sii:IndExeDR', $g),
            ));
        }

        foreach (XmlUtil::elements($xpath, 'sii:Referencia', $node) as $r) {
            $dte->addReferencia((new Reference())
                ->setTipoDocumento($s('sii:TpoDocRef', $r))
                ->setGlobal($s('sii:IndGlobal', $r) === '1')
                ->setFolio($s('sii:FolioRef', $r))
                ->setRutOtro($s('sii:RUTOtr', $r))
                ->setIdAdicionalOtro($s('sii:IdAdicOtr', $r))
                ->setFecha($d('sii:FchRef', $r))
                ->setCodigo($i('sii:CodRef', $r))
                ->setRazon($s('sii:RazonRef', $r)));
        }

        return new ParsedDte($tipoCodigo, (int) $dte->getFolio(), $dte, $totales);
    }

    /**
     * Totales tal como los informó el emisor.
     *
     * @param \Closure(string, ?\DOMNode=): ?string        $s
     * @param \Closure(string, ?\DOMNode=): (int|float|null) $n
     */
    private function totales(\DOMXPath $xpath, \DOMElement $node, \Closure $s, \Closure $n): Totals
    {
        $t = XmlUtil::first($xpath, 'sii:Encabezado/sii:Totales', $node);
        $impuestos = [];
        foreach (XmlUtil::elements($xpath, 'sii:ImptoReten', $t) as $imp) {
            $codigo = (string) $s('sii:TipoImp', $imp);
            $tasa = (float) ($n('sii:TasaImp', $imp) ?? 0);
            $impuestos[] = new TaxTotal($codigo, $tasa, (int) $n('sii:MontoImp', $imp), \in_array($codigo, AdditionalTax::RETENTION_CODES, true));
        }
        $int = static fn (int|float|null $v): ?int => $v === null ? null : (int) $v;

        return new Totals(
            montoNeto: $n('sii:MntNeto', $t),
            montoExento: $n('sii:MntExe', $t),
            tasaIva: ($tasa = $n('sii:TasaIVA', $t)) === null ? null : (float) $tasa,
            iva: $int($n('sii:IVA', $t)),
            impuestos: $impuestos,
            garantiaDeposito: $int($n('sii:GrntDep', $t)),
            montoTotal: $n('sii:MntTotal', $t) ?? 0,
            montoNoFacturable: $int($n('sii:MontoNF', $t)),
            montoPeriodo: $int($n('sii:MontoPeriodo', $t)),
            saldoAnterior: $int($n('sii:SaldoAnterior', $t)),
            valorPagar: $int($n('sii:VlrPagar', $t)),
            tipoMoneda: $s('sii:TpoMoneda', $t),
        );
    }

    /**
     * @param \Closure(string, ?\DOMNode=): ?string          $s
     * @param \Closure(string, ?\DOMNode=): ?int             $i
     * @param \Closure(string, ?\DOMNode=): (int|float|null) $n
     */
    private function aduana(\DOMXPath $xpath, \DOMElement $ad, \Closure $s, \Closure $i, \Closure $n): Customs
    {
        $c = (new Customs())
            ->setCodigoModalidadVenta($i('sii:CodModVenta', $ad))
            ->setCodigoClausulaVenta($i('sii:CodClauVenta', $ad))
            ->setTotalClausulaVenta($n('sii:TotClauVenta', $ad))
            ->setCodigoViaTransporte($i('sii:CodViaTransp', $ad))
            ->setNombreTransporte($s('sii:NombreTransp', $ad))
            ->setRutCompaniaTransporte($s('sii:RUTCiaTransp', $ad))
            ->setNombreCompaniaTransporte($s('sii:NomCiaTransp', $ad))
            ->setIdAdicionalTransporte($s('sii:IdAdicTransp', $ad))
            ->setBooking($s('sii:Booking', $ad))
            ->setOperador($s('sii:Operador', $ad))
            ->setCodigoPuertoEmbarque($i('sii:CodPtoEmbarque', $ad))
            ->setIdAdicionalPuertoEmbarque($s('sii:IdAdicPtoEmb', $ad))
            ->setCodigoPuertoDesembarque($i('sii:CodPtoDesemb', $ad))
            ->setIdAdicionalPuertoDesembarque($s('sii:IdAdicPtoDesemb', $ad))
            ->setTara($i('sii:Tara', $ad))
            ->setCodigoUnidadTara($i('sii:CodUnidMedTara', $ad))
            ->setPesoBruto($n('sii:PesoBruto', $ad))
            ->setCodigoUnidadPesoBruto($i('sii:CodUnidPesoBruto', $ad))
            ->setPesoNeto($n('sii:PesoNeto', $ad))
            ->setCodigoUnidadPesoNeto($i('sii:CodUnidPesoNeto', $ad))
            ->setTotalItems($i('sii:TotItems', $ad))
            ->setTotalBultos($i('sii:TotBultos', $ad))
            ->setMontoFlete($n('sii:MntFlete', $ad))
            ->setMontoSeguro($n('sii:MntSeguro', $ad))
            ->setCodigoPaisReceptor($i('sii:CodPaisRecep', $ad))
            ->setCodigoPaisDestino($i('sii:CodPaisDestin', $ad));
        foreach (XmlUtil::elements($xpath, 'sii:TipoBultos', $ad) as $b) {
            $c->addBulto(new Package($i('sii:CodTpoBultos', $b), $i('sii:CantBultos', $b), $s('sii:Marcas', $b), $s('sii:IdContainer', $b), $s('sii:Sello', $b), $s('sii:EmisorSello', $b)));
        }

        return $c;
    }
}
