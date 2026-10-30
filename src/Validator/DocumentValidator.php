<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Validator;

use RoyaltyFusion\Sii\Caf\Caf;
use RoyaltyFusion\Sii\Calculator\Calculation;
use RoyaltyFusion\Sii\Calculator\LineGroup;
use RoyaltyFusion\Sii\Calculator\TotalsCalculator;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Model\AbstractDocument;
use RoyaltyFusion\Sii\Model\AdditionalTax;
use RoyaltyFusion\Sii\Model\Boleta;
use RoyaltyFusion\Sii\Model\Client;
use RoyaltyFusion\Sii\Model\Customs;
use RoyaltyFusion\Sii\Model\Detail;
use RoyaltyFusion\Sii\Model\DocumentType;
use RoyaltyFusion\Sii\Model\Dte;
use RoyaltyFusion\Sii\Model\GlobalDiscount;
use RoyaltyFusion\Sii\Model\Transport;
use RoyaltyFusion\Sii\Util\Rut;

/**
 * Validaciones de negocio previas a la emisión, basadas en el "Formato de Documentos Tributarios
 * Electrónicos" del SII y en los esquemas XSD oficiales. Complementa (no reemplaza) la validación XSD
 * que se ejecuta sobre el XML generado.
 */
final class DocumentValidator
{
    /** Receptores con RUT genérico a los que no se les exige giro ni dirección. */
    private const RUTS_GENERICOS = [Rut::CONSUMIDOR_FINAL, Rut::EXTRANJERO];

    public function __construct(private readonly TotalsCalculator $calculator = new TotalsCalculator())
    {
    }

    public function validate(AbstractDocument $document, ?Caf $caf = null, ?Environment $environment = null, bool $checkCaf = false, ?\DateTimeInterface $now = null): ValidationResult
    {
        $now = \DateTimeImmutable::createFromInterface($now ?? new \DateTimeImmutable('now', new \DateTimeZone('America/Santiago')));
        $result = new ValidationResult();
        $r = new Rules($result);
        $tipo = $document->getTipo();
        $boleta = $document instanceof Boleta;

        $r->int('Encabezado.IdDoc.Folio', $document->getFolio(), 1, 9_999_999_999, true);
        $r->date('Encabezado.IdDoc.FchEmis', $document->getFechaEmision(), true);
        $hoy = $now->setTimezone(new \DateTimeZone('America/Santiago'));
        if ($document->getFechaEmision() !== null && $document->getFechaEmision()->format('Y-m-d') > $hoy->format('Y-m-d')) {
            $result->warning('Encabezado.IdDoc.FchEmis', 'La fecha de emisión es posterior a la fecha actual.', 'FECHA_FUTURA');
        }
        $r->date('Encabezado.IdDoc.PeriodoDesde', $document->getPeriodoDesde());
        $r->date('Encabezado.IdDoc.PeriodoHasta', $document->getPeriodoHasta());
        if ($document->getPeriodoDesde() && $document->getPeriodoHasta() && $document->getPeriodoDesde() > $document->getPeriodoHasta()) {
            $result->error('Encabezado.IdDoc.PeriodoHasta', 'PeriodoHasta debe ser posterior o igual a PeriodoDesde.', 'PERIODO_INVALIDO');
        }
        $r->date('Encabezado.IdDoc.FchVenc', $document->getFechaVencimiento());
        if ($document->getFechaVencimiento() && $document->getFechaEmision() && $document->getFechaVencimiento() < $document->getFechaEmision()) {
            $result->warning('Encabezado.IdDoc.FchVenc', 'La fecha de vencimiento es anterior a la fecha de emisión.', 'VENCIMIENTO_ANTERIOR');
        }

        $this->validateEmisor($r, $document);
        $this->validateReceptor($r, $document);
        if ($document instanceof Dte) {
            $this->validateIdDoc($r, $document);
            $this->validateTransporte($r, $document);
        } else {
            \assert($document instanceof Boleta);
            $r->in('Encabezado.IdDoc.IndServicio', $document->getIndicadorServicio(), [1, 2, 3, 4], true);
            $r->in('Encabezado.IdDoc.MedioPago', $document->getMedioPago(), [1, 2, 3, 4, 5]);
            $r->rut('Encabezado.RUTProvSW', $document->getRutProveedorSoftware());
            if ($tipo === DocumentType::BOLETA_EXENTA && $document->isMontosNetos()) {
                $result->error('Encabezado.IdDoc.IndMntNeto', 'La boleta exenta no admite montos netos.', 'NO_PERMITIDO');
            }
        }

        $calculation = $this->calculator->calculate($document);
        $this->validateDetalle($r, $document, $calculation);
        $this->validateDescuentosGlobales($r, $document, $calculation);
        $this->validateReferencias($r, $document);
        $this->validateTotales($r, $document, $calculation);

        if ($checkCaf) {
            $result->merge($this->validateCaf($document, $caf, $environment, $now));
        }

        return $result;
    }

    public function validateCaf(AbstractDocument $document, ?Caf $caf, ?Environment $environment = null, ?\DateTimeInterface $now = null): ValidationResult
    {
        $result = new ValidationResult();
        $tipo = $document->getTipo()->value;
        $folio = $document->getFolio();
        if ($caf === null) {
            return $result->error('CAF', sprintf('No hay un CAF cargado para el tipo %d que contenga el folio %s.', $tipo, $folio ?? '?'), 'CAF_NO_ENCONTRADO');
        }
        if ($caf->getTipoDte() !== $tipo) {
            $result->error('CAF.TD', sprintf('El CAF es para el tipo %d y el documento es tipo %d.', $caf->getTipoDte(), $tipo), 'CAF_TIPO');
        }
        $rut = $document->getEmisor()?->getRut();
        if ($rut !== null && Rut::isValid($rut) && !Rut::parse($rut)->equals($caf->getRutEmisor())) {
            $result->error('CAF.RE', sprintf('El CAF pertenece al RUT %s y el emisor es %s.', $caf->getRutEmisor(), $rut), 'CAF_RUT');
        }
        if ($folio !== null && !$caf->contains($folio)) {
            $result->error('CAF.RNG', sprintf('El folio %d no está en el rango autorizado del CAF (%d a %d).', $folio, $caf->getFolioDesde(), $caf->getFolioHasta()), 'CAF_RANGO');
        }
        $fecha = $document->getFechaEmision();
        if ($fecha !== null && $fecha->format('Y-m-d') < $caf->getFechaAutorizacion()->format('Y-m-d')) {
            $result->error('CAF.FA', sprintf('La fecha de emisión (%s) es anterior a la autorización de los folios (%s).', $fecha->format('Y-m-d'), $caf->getFechaAutorizacion()->format('Y-m-d')), 'CAF_FECHA');
        }
        if ($caf->isVencido($now)) {
            $result->error('CAF.FA', sprintf('Los folios del CAF vencieron el %s (6 meses desde su autorización).', $caf->getFechaVencimiento()?->format('Y-m-d')), 'CAF_VENCIDO');
        }
        if ($environment !== null) {
            if ($environment->isProduccion() && $caf->isCertificacion()) {
                $result->error('CAF.IDK', 'Se está usando un CAF de certificación (IDK 100) en producción.', 'CAF_AMBIENTE');
            } elseif (!$environment->isProduccion() && !$caf->isCertificacion()) {
                $result->error('CAF.IDK', 'Se está usando un CAF de producción en el ambiente de certificación.', 'CAF_AMBIENTE');
            }
        }

        return $result;
    }

    private function validateEmisor(Rules $r, AbstractDocument $document): void
    {
        $emisor = $document->getEmisor();
        $p = 'Encabezado.Emisor.';
        if ($emisor === null) {
            $r->error('Encabezado.Emisor', 'El documento debe tener emisor.', 'REQUERIDO');

            return;
        }
        $boleta = $document instanceof Boleta;
        $r->rut($p.'RUTEmisor', $emisor->getRut(), true);
        $r->text($p.($boleta ? 'RznSocEmisor' : 'RznSoc'), $emisor->getRazonSocial(), 100, true);
        $r->text($p.($boleta ? 'GiroEmisor' : 'GiroEmis'), $emisor->getGiro(), 80, true);
        $r->text($p.'DirOrigen', $emisor->getDireccion(), 70, true);
        $r->text($p.'CmnaOrigen', $emisor->getComuna(), 20, true);
        $r->text($p.'CiudadOrigen', $emisor->getCiudad(), 20);
        $r->int($p.'CdgSIISucur', $emisor->getCodigoSucursal(), 1, 999_999_999);
        if ($boleta) {
            return;
        }
        $actecos = $emisor->getActecos();
        if ($actecos === []) {
            $r->error($p.'Acteco', 'Debe informar al menos un código de actividad económica.', 'REQUERIDO');
        }
        $r->maxCount($p.'Acteco', $actecos, 4);
        foreach ($actecos as $i => $acteco) {
            $r->int(sprintf('%sActeco[%d]', $p, $i + 1), $acteco, 1, 999_999);
        }
        $r->maxCount($p.'Telefono', $emisor->getTelefonos(), 2);
        foreach ($emisor->getTelefonos() as $i => $telefono) {
            $r->text(sprintf('%sTelefono[%d]', $p, $i + 1), $telefono, 20);
        }
        $r->email($p.'CorreoEmisor', $emisor->getEmail());
        $r->text($p.'Sucursal', $emisor->getSucursal(), 20);
        $r->text($p.'CdgVendedor', $emisor->getCodigoVendedor(), 60);
        $r->text($p.'IdAdicEmisor', $emisor->getIdAdicional(), 20);
    }

    private function validateReceptor(Rules $r, AbstractDocument $document): void
    {
        $receptor = $document->getReceptor();
        $p = 'Encabezado.Receptor.';
        $tipo = $document->getTipo();
        if ($receptor === null) {
            if (!$document instanceof Boleta) {
                $r->error('Encabezado.Receptor', 'El documento debe tener receptor.', 'REQUERIDO');
            }

            return;
        }
        $r->rut($p.'RUTRecep', $receptor->getRut(), true);
        $generico = $this->isRutGenerico($receptor);
        if ($document instanceof Boleta) {
            $r->text($p.'RznSocRecep', $receptor->getRazonSocial(), 100);
            $r->text($p.'TelefonoRecep', $receptor->getTelefono(), 20);
        } else {
            $r->text($p.'RznSocRecep', $receptor->getRazonSocial(), 100, true);
            $nacional = !$tipo->isExportacion() && !$generico;
            $r->text($p.'GiroRecep', $receptor->getGiro(), 40, $nacional);
            $r->text($p.'DirRecep', $receptor->getDireccion(), 70, $nacional);
            $r->text($p.'CmnaRecep', $receptor->getComuna(), 20, $nacional);
            if ($tipo->isExportacion() && $receptor->getRut() !== null && Rut::isValid($receptor->getRut()) && !Rut::parse($receptor->getRut())->equals(Rut::EXTRANJERO) && $receptor->getExtranjero() === null) {
                $r->warning($p.'RUTRecep', 'En exportación el receptor extranjero se identifica con RUT 55555555-5 y el nodo Extranjero.', 'EXPORTACION_RECEPTOR');
            }
        }
        $r->text($p.'CdgIntRecep', $receptor->getCodigoInterno(), 20);
        $r->text($p.'Contacto', $receptor->getContacto(), 80);
        $r->email($p.'CorreoRecep', $receptor->getEmail());
        $r->text($p.'DirRecep', $receptor->getDireccion(), 70);
        $r->text($p.'CmnaRecep', $receptor->getComuna(), 20);
        $r->text($p.'CiudadRecep', $receptor->getCiudad(), 20);
        $r->text($p.'DirPostal', $receptor->getDireccionPostal(), 70);
        $r->text($p.'CmnaPostal', $receptor->getComunaPostal(), 20);
        $r->text($p.'CiudadPostal', $receptor->getCiudadPostal(), 20);

        $extranjero = $receptor->getExtranjero();
        if ($extranjero !== null) {
            if ($document instanceof Boleta) {
                $r->error($p.'Extranjero', 'La boleta no admite datos de receptor extranjero.', 'NO_PERMITIDO');
            }
            $r->text($p.'Extranjero.NumId', $extranjero->getNumeroId(), 20);
            $r->text($p.'Extranjero.Nacionalidad', $extranjero->getNacionalidad(), 3);
            if ($tipo->isExportacion()) {
                $r->text($p.'Extranjero.IdAdicRecep', $extranjero->getIdAdicional(), 20);
                if ($extranjero->getTipoDocumentoId() !== null) {
                    $r->error($p.'Extranjero.TipoDocID', 'TipoDocID no existe en documentos de exportación.', 'NO_PERMITIDO');
                }
            } else {
                $r->in($p.'Extranjero.TipoDocID', $extranjero->getTipoDocumentoId(), [1, 2]);
                if ($extranjero->getIdAdicional() !== null) {
                    $r->error($p.'Extranjero.IdAdicRecep', 'IdAdicRecep sólo existe en documentos de exportación.', 'NO_PERMITIDO');
                }
            }
        } elseif ($tipo->isExportacion()) {
            $r->warning($p.'Extranjero', 'Se recomienda informar Nacionalidad del receptor extranjero.', 'EXPORTACION_NACIONALIDAD');
        }
    }

    private function validateIdDoc(Rules $r, Dte $dte): void
    {
        $p = 'Encabezado.IdDoc.';
        $tipo = $dte->getTipo();
        $export = $tipo->isExportacion();

        if ($dte->isIndicadorNoRebaja() && !\in_array($tipo, [DocumentType::NOTA_CREDITO], true)) {
            $r->error($p.'IndNoRebaja', 'IndNoRebaja sólo aplica a notas de crédito.', 'NO_PERMITIDO');
        }
        $r->in($p.'TipoDespacho', $dte->getTipoDespacho(), [1, 2, 3]);
        if ($tipo === DocumentType::GUIA_DESPACHO) {
            $r->in($p.'IndTraslado', $dte->getIndicadorTraslado(), [1, 2, 3, 4, 5, 6, 7, 8, 9], true);
        } elseif ($dte->getIndicadorTraslado() !== null) {
            $r->error($p.'IndTraslado', 'IndTraslado sólo aplica a guías de despacho.', 'NO_PERMITIDO');
        }
        $r->in($p.'TpoImpresion', $dte->getTipoImpresion(), ['N', 'T']);
        $r->in($p.'IndServicio', $dte->getIndicadorServicio(), $export ? [3, 4, 5, 6] : [1, 2, 3]);
        if ($dte->isMontoBruto() && $tipo->isExento() && !$export) {
            $r->error($p.'MntBruto', 'MntBruto no aplica a documentos exentos.', 'NO_PERMITIDO');
        }
        $r->int($p.'TpoTranCompra', $dte->getTipoTransaccionCompra(), 1, 7);
        $r->int($p.'TpoTranVenta', $dte->getTipoTransaccionVenta(), 1, 4);
        $r->in($p.'FmaPago', $dte->getFormaPago(), [1, 2, 3]);
        if ($dte->getFormaPagoExportacion() !== null) {
            if (!$export) {
                $r->error($p.'FmaPagExp', 'FmaPagExp sólo aplica a documentos de exportación.', 'NO_PERMITIDO');
            }
            $r->int($p.'FmaPagExp', $dte->getFormaPagoExportacion(), 1, 99);
        }
        $r->date($p.'FchCancel', $dte->getFechaCancelacion());
        $r->int($p.'MntCancel', $dte->getMontoCancelado(), 0, \PHP_INT_MAX);
        $r->int($p.'SaldoInsol', $dte->getSaldoInsoluto(), 0, \PHP_INT_MAX);
        $r->maxCount($p.'MntPagos', $dte->getPagos(), 30);
        foreach ($dte->getPagos() as $i => $pago) {
            $pp = sprintf('%sMntPagos[%d].', $p, $i + 1);
            $r->date($pp.'FchPago', $pago->getFecha(), true);
            $r->int($pp.'MntPago', $pago->getMonto(), 0, \PHP_INT_MAX);
            $r->text($pp.'GlosaPagos', $pago->getGlosa(), 40);
        }
        $r->in($p.'MedioPago', $dte->getMedioPago(), Catalog::MEDIOS_PAGO);
        $r->in($p.'TpoCtaPago', $dte->getTipoCuentaPago(), Catalog::TIPOS_CUENTA);
        $r->text($p.'NumCtaPago', $dte->getNumeroCuentaPago(), 20);
        $r->text($p.'BcoPago', $dte->getBancoPago(), 40);
        $r->text($p.'TermPagoCdg', $dte->getTerminoPagoCodigo(), 4);
        $r->text($p.'TermPagoGlosa', $dte->getTerminoPagoGlosa(), 100);
        $r->int($p.'TermPagoDias', $dte->getTerminoPagoDias(), 1, 999);

        if ($export) {
            foreach (['TpoTranCompra' => $dte->getTipoTransaccionCompra(), 'TpoTranVenta' => $dte->getTipoTransaccionVenta(), 'TpoImpresion' => $dte->getTipoImpresion()] as $campo => $valor) {
                if ($valor !== null) {
                    $r->error($p.$campo, sprintf('%s no existe en documentos de exportación.', $campo), 'NO_PERMITIDO');
                }
            }
            if ($dte->isMontoBruto()) {
                $r->error($p.'MntBruto', 'MntBruto no existe en documentos de exportación.', 'NO_PERMITIDO');
            }
            if ($dte->getRutMandante() !== null || $dte->getRutSolicita() !== null) {
                $r->error('Encabezado', 'RUTMandante/RUTSolicita no existen en documentos de exportación.', 'NO_PERMITIDO');
            }
            if ($dte->getTipoMoneda() === null || $dte->getTipoMoneda() === '') {
                $r->error('Encabezado.Totales.TpoMoneda', 'La moneda de la transacción es obligatoria en exportación.', 'REQUERIDO');
            } else {
                $r->in('Encabezado.Totales.TpoMoneda', $dte->getTipoMoneda(), Catalog::MONEDAS);
            }
        } elseif ($dte->getTipoMoneda() !== null) {
            $r->error('Encabezado.Totales.TpoMoneda', 'TpoMoneda en Totales sólo aplica a exportación; use OtraMoneda.', 'NO_PERMITIDO');
        }

        $r->rut('Encabezado.RUTMandante', $dte->getRutMandante());
        $r->rut('Encabezado.RUTSolicita', $dte->getRutSolicita());
        $r->decimal('Encabezado.Totales.TasaIVA', $dte->getTasaIva(), 3, 2, 0.01);
        if (abs($dte->getTasaIva() - 19.0) > 0.0001 && !$tipo->isExento()) {
            $r->warning('Encabezado.Totales.TasaIVA', sprintf('La tasa de IVA vigente es 19%% (se informó %s%%).', $dte->getTasaIva()), 'TASA_IVA');
        }

        $otra = $dte->getOtraMoneda();
        if ($otra !== null) {
            $r->in('Encabezado.OtraMoneda.TpoMoneda', $otra->getTipoMoneda(), Catalog::MONEDAS, true);
            $r->decimal('Encabezado.OtraMoneda.TpoCambio', $otra->getTipoCambio(), 6, 4, 0.0001);
            if ($otra->getTipoCambio() === null && $otra->getMontoTotal() === null) {
                $r->error('Encabezado.OtraMoneda', 'Indique el tipo de cambio o el monto total en la otra moneda.', 'REQUERIDO');
            }
            if ($export && $otra->getTipoMoneda() === $dte->getTipoMoneda()) {
                $r->error('Encabezado.OtraMoneda.TpoMoneda', 'OtraMoneda debe ser distinta a la moneda de la transacción.', 'MONEDA_DUPLICADA');
            }
        }

        foreach ($dte->getImpuestosAdicionales() as $codigo => $impuesto) {
            $r->in(sprintf('Encabezado.Totales.ImptoReten[%s].TipoImp', $codigo), (string) $codigo, Catalog::IMPUESTOS_ADICIONALES);
            $r->decimal(sprintf('Encabezado.Totales.ImptoReten[%s].TasaImp', $codigo), $impuesto->getTasa(), 3, 2, 0.01);
            if ($impuesto->getTasa() > 100) {
                $r->error(sprintf('Encabezado.Totales.ImptoReten[%s].TasaImp', $codigo), 'La tasa no puede superar 100%.', 'RANGO');
            }
        }
    }

    private function validateTransporte(Rules $r, Dte $dte): void
    {
        $t = $dte->getTransporte();
        if ($t === null) {
            if ($dte->getTipo() === DocumentType::FACTURA_EXPORTACION && $dte->getIndicadorServicio() === null) {
                $r->error('Encabezado.Transporte.Aduana', 'La factura de exportación de mercaderías requiere la información aduanera (Transporte/Aduana).', 'REQUERIDO');
            }

            return;
        }
        $p = 'Encabezado.Transporte.';
        $export = $dte->getTipo()->isExportacion();
        $r->text($p.'Patente', $t->getPatente(), 8);
        $r->text($p.'PatenteCarro', $t->getPatenteCarro(), 8);
        if ($export && $t->getPatenteCarro() !== null) {
            $r->error($p.'PatenteCarro', 'PatenteCarro no existe en documentos de exportación.', 'NO_PERMITIDO');
        }
        $r->rut($p.'RUTTrans', $t->getRutTransportista());
        if (($chofer = $t->getChofer()) !== null) {
            $r->rut($p.'Chofer.RUTChofer', $chofer->getRut(), true);
            $r->text($p.'Chofer.NombreChofer', $chofer->getNombre(), 30, true);
        }
        $r->text($p.'DirDest', $t->getDireccionDestino(), 70);
        $r->text($p.'CmnaDest', $t->getComunaDestino(), 20);
        $r->text($p.'CiudadDest', $t->getCiudadDestino(), 20);
        if (($aduana = $t->getAduana()) !== null) {
            $this->validateAduana($r, $dte, $aduana);
        } elseif ($dte->getTipo() === DocumentType::FACTURA_EXPORTACION && $dte->getIndicadorServicio() === null) {
            $r->error($p.'Aduana', 'La factura de exportación de mercaderías requiere la información aduanera (Transporte/Aduana).', 'REQUERIDO');
        }
    }

    private function validateAduana(Rules $r, Dte $dte, Customs $a): void
    {
        $p = 'Encabezado.Transporte.Aduana.';
        $r->int($p.'CodModVenta', $a->getCodigoModalidadVenta(), 1, 99);
        $r->int($p.'CodClauVenta', $a->getCodigoClausulaVenta(), 1, 99);
        $r->decimal($p.'TotClauVenta', $a->getTotalClausulaVenta(), 16, 2, 0.01);
        $r->int($p.'CodViaTransp', $a->getCodigoViaTransporte(), 1, 99);
        $r->text($p.'NombreTransp', $a->getNombreTransporte(), 40);
        $r->rut($p.'RUTCiaTransp', $a->getRutCompaniaTransporte());
        $r->text($p.'NomCiaTransp', $a->getNombreCompaniaTransporte(), 40);
        $r->text($p.'IdAdicTransp', $a->getIdAdicionalTransporte(), 20);
        $r->text($p.'Booking', $a->getBooking(), 20);
        $r->text($p.'Operador', $a->getOperador(), 20);
        $r->int($p.'CodPtoEmbarque', $a->getCodigoPuertoEmbarque(), 1, 9999);
        $r->text($p.'IdAdicPtoEmb', $a->getIdAdicionalPuertoEmbarque(), 20);
        $r->int($p.'CodPtoDesemb', $a->getCodigoPuertoDesembarque(), 1, 9999);
        $r->text($p.'IdAdicPtoDesemb', $a->getIdAdicionalPuertoDesembarque(), 20);
        $r->int($p.'Tara', $a->getTara(), 1, 9_999_999);
        $r->int($p.'CodUnidMedTara', $a->getCodigoUnidadTara(), 1, 99);
        $r->decimal($p.'PesoBruto', $a->getPesoBruto(), 8, 2, 0);
        $r->int($p.'CodUnidPesoBruto', $a->getCodigoUnidadPesoBruto(), 1, 99);
        $r->decimal($p.'PesoNeto', $a->getPesoNeto(), 8, 2, 0);
        $r->int($p.'CodUnidPesoNeto', $a->getCodigoUnidadPesoNeto(), 1, 99);
        $r->int($p.'TotItems', $a->getTotalItems(), 1, \PHP_INT_MAX);
        $r->int($p.'TotBultos', $a->getTotalBultos(), 1, \PHP_INT_MAX);
        $r->maxCount($p.'TipoBultos', $a->getBultos(), 10);
        foreach ($a->getBultos() as $i => $b) {
            $pb = sprintf('%sTipoBultos[%d].', $p, $i + 1);
            $r->int($pb.'CodTpoBultos', $b->getCodigoTipo(), 1, 999);
            $r->int($pb.'CantBultos', $b->getCantidad(), 1, 9_999_999_999);
            $r->text($pb.'Marcas', $b->getMarcas(), 255);
            $r->text($pb.'IdContainer', $b->getIdContainer(), 25);
            $r->text($pb.'Sello', $b->getSello(), 20);
            $r->text($pb.'EmisorSello', $b->getEmisorSello(), 70);
        }
        $r->decimal($p.'MntFlete', $a->getMontoFlete(), 14, 4, 0.0001);
        $r->decimal($p.'MntSeguro', $a->getMontoSeguro(), 14, 4, 0.0001);
        $r->int($p.'CodPaisRecep', $a->getCodigoPaisReceptor(), 1, 999);
        $r->int($p.'CodPaisDestin', $a->getCodigoPaisDestino(), 1, 999);

        if ($dte->getTipo() === DocumentType::FACTURA_EXPORTACION && $dte->getIndicadorServicio() === null) {
            foreach (['CodModVenta' => $a->getCodigoModalidadVenta(), 'CodPaisDestin' => $a->getCodigoPaisDestino()] as $campo => $valor) {
                if ($valor === null) {
                    $r->error($p.$campo, 'Obligatorio en la factura de exportación.', 'REQUERIDO');
                }
            }
            foreach (['CodClauVenta' => $a->getCodigoClausulaVenta(), 'TotClauVenta' => $a->getTotalClausulaVenta(), 'CodViaTransp' => $a->getCodigoViaTransporte(), 'CodPtoEmbarque' => $a->getCodigoPuertoEmbarque(), 'CodPtoDesemb' => $a->getCodigoPuertoDesembarque(), 'CodPaisRecep' => $a->getCodigoPaisReceptor()] as $campo => $valor) {
                if ($valor === null) {
                    $r->warning($p.$campo, 'Normalmente requerido por Aduanas en exportaciones de mercaderías.', 'EXPORTACION_ADUANA');
                }
            }
        }
    }

    private function validateDetalle(Rules $r, AbstractDocument $document, Calculation $calculation): void
    {
        $boleta = $document instanceof Boleta;
        $tipo = $document->getTipo();
        $detalles = $document->getDetalles();
        if ($detalles === []) {
            $r->error('Detalle', 'El documento debe tener al menos una línea de detalle.', 'REQUERIDO');
        }
        $r->maxCount('Detalle', $detalles, $boleta ? 1000 : 60);
        $definidos = $document instanceof Dte ? $document->getImpuestosAdicionales() : [];

        foreach ($calculation->lineas as $linea) {
            $d = $linea->detalle;
            $p = sprintf('Detalle[%d].', $linea->numero);
            $r->text($p.'NmbItem', $d->getNombre(), 80, true);
            $r->text($p.'DscItem', $d->getDescripcion(), 1000);
            $r->maxCount($p.'CdgItem', $d->getCodigos(), 5);
            foreach ($d->getCodigos() as $i => $codigo) {
                $r->text(sprintf('%sCdgItem[%d].TpoCodigo', $p, $i + 1), $codigo->getTipo(), 10, true);
                $r->text(sprintf('%sCdgItem[%d].VlrCodigo', $p, $i + 1), $codigo->getValor(), 35, true);
            }
            $r->in($p.'IndExe', $d->getIndicadorExencion(), $boleta ? [1, 2, 6] : [1, 2, 3, 4, 5, 6]);
            if (\in_array($d->getIndicadorExencion(), [Detail::NO_VENTA, Detail::A_REBAJAR], true) && $tipo !== DocumentType::GUIA_DESPACHO) {
                $r->error($p.'IndExe', 'Los indicadores 4 (no venta) y 5 (a rebajar) sólo aplican a guías de despacho.', 'NO_PERMITIDO');
            }
            if ($d->getCantidad() !== null) {
                $r->decimal($p.'QtyItem', $d->getCantidad(), 12, 6, 0.000001);
            }
            if ($d->getPrecio() !== null) {
                if ($d->getPrecio() < 0) {
                    $r->error($p.'PrcItem', 'El precio no puede ser negativo.', 'RANGO');
                } elseif ($d->getPrecio() > 0) {
                    $r->decimal($p.'PrcItem', $d->getPrecio(), 12, 6, 0.000001);
                }
                if ($d->getCantidad() === null) {
                    $r->warning($p.'QtyItem', 'Se informó precio sin cantidad; se asume cantidad 1.', 'CANTIDAD_ASUMIDA');
                }
            } elseif ($d->getCantidad() !== null) {
                $r->warning($p.'PrcItem', 'Se informó cantidad sin precio unitario.', 'PRECIO_FALTANTE');
            }
            $r->text($p.'UnmdItem', $d->getUnidad(), 4);
            $r->text($p.'UnmdRef', $d->getUnidadReferencia(), 4);
            if ($d->getCantidadReferencia() !== null) {
                $r->decimal($p.'QtyRef', $d->getCantidadReferencia(), 12, 6, 0.000001);
            }
            if ($d->getPrecioReferencia() !== null) {
                $r->decimal($p.'PrcRef', $d->getPrecioReferencia(), 12, 6, 0.000001);
            }
            $r->date($p.'FchElabor', $d->getFechaElaboracion());
            $r->date($p.'FchVencim', $d->getFechaVencimiento());
            $maxPct = $boleta ? 100 : 999.99;
            foreach (['DescuentoPct' => $d->getDescuentoPorcentaje(), 'RecargoPct' => $d->getRecargoPorcentaje()] as $campo => $pct) {
                if ($pct !== null) {
                    $r->decimal($p.$campo, $pct, 3, 2, 0.01);
                    if ($pct > $maxPct) {
                        $r->error($p.$campo, sprintf('Máximo %s%%.', $maxPct), 'RANGO');
                    }
                }
            }
            if ($d->getDescuentoPorcentaje() !== null && $d->getDescuentoPorcentaje() > 100) {
                $r->error($p.'DescuentoPct', 'El descuento no puede superar el 100%.', 'RANGO');
            }
            foreach (['DescuentoMonto' => $d->getDescuentoMonto(), 'RecargoMonto' => $d->getRecargoMonto()] as $campo => $monto) {
                if ($monto !== null && ($monto < 0 || abs(round((float) $monto) - (float) $monto) > 1e-9)) {
                    $r->error($p.$campo, 'Debe ser un monto entero positivo (pesos).', 'MONTO_INVALIDO');
                }
            }
            if ($linea->montoItem < 0) {
                $r->error($p.'MontoItem', 'El monto del ítem no puede ser negativo (revise descuentos).', 'MONTO_NEGATIVO');
            }
            if ($linea->descuentoMonto > 0 && $d->getPrecio() !== null && $linea->descuentoMonto > (float) ($d->getCantidad() ?? 1) * (float) $d->getPrecio() + 0.0001) {
                $r->error($p.'DescuentoMonto', 'El descuento supera el valor del ítem.', 'DESCUENTO_EXCESIVO');
            }

            $codigos = $d->getImpuestosAdicionales();
            if ($codigos !== []) {
                if ($boleta || $tipo->isExportacion()) {
                    $r->error($p.'CodImpAdic', 'Este tipo de documento no admite impuestos adicionales por ítem.', 'NO_PERMITIDO');
                } elseif ($linea->grupo !== LineGroup::AFECTO) {
                    $r->error($p.'CodImpAdic', 'Sólo los ítems afectos pueden tener impuestos adicionales.', 'NO_PERMITIDO');
                }
                $r->maxCount($p.'CodImpAdic', $codigos, 2);
                foreach ($codigos as $codigo) {
                    $r->in($p.'CodImpAdic', $codigo, Catalog::IMPUESTOS_ADICIONALES);
                    if (!isset($definidos[$codigo]) && !isset(AdditionalTax::DEFAULT_RATES[$codigo])) {
                        $r->error($p.'CodImpAdic', sprintf('Defina la tasa del impuesto %s con Dte::addImpuestoAdicional().', $codigo), 'IMPUESTO_SIN_TASA');
                    }
                }
            }
            if ($boleta) {
                $r->in($p.'ItemEspectaculo', $d->getItemEspectaculo(), [1, 2]);
                $r->rut($p.'RUTMandante', $d->getRutMandante());
            } elseif ($d->getItemEspectaculo() !== null || $d->getRutMandante() !== null) {
                $r->error($p.'ItemEspectaculo', 'ItemEspectaculo y RUTMandante por ítem sólo existen en boletas.', 'NO_PERMITIDO');
            }
        }

        if ($tipo === DocumentType::FACTURA && $calculation->totales->montoNeto === null && $detalles !== []) {
            $r->warning('Detalle', 'La factura afecta no tiene ítems afectos; evalúe emitir una factura exenta (34).', 'SIN_AFECTOS');
        }
        if ($tipo === DocumentType::BOLETA && $calculation->totales->montoNeto === null && $detalles !== []) {
            $r->warning('Detalle', 'La boleta afecta no tiene ítems afectos; evalúe emitir una boleta exenta (41).', 'SIN_AFECTOS');
        }
    }

    private function validateDescuentosGlobales(Rules $r, AbstractDocument $document, Calculation $calculation): void
    {
        $r->maxCount('DscRcgGlobal', $document->getDescuentosGlobales(), 20);
        $grupos = array_map(static fn ($l) => $l->grupo, $calculation->lineas);
        foreach ($calculation->descuentosGlobales as $global) {
            $g = $global->descuento;
            $p = sprintf('DscRcgGlobal[%d].', $global->numero);
            $r->in($p.'TpoMov', $g->getTipo(), [GlobalDiscount::DESCUENTO, GlobalDiscount::RECARGO], true);
            $r->in($p.'TpoValor', $g->getTipoValor(), [GlobalDiscount::PORCENTAJE, GlobalDiscount::MONTO], true);
            $r->decimal($p.'ValorDR', $g->getValor(), 16, 2, 0.01, true);
            if ($g->isPorcentaje() && $g->isDescuento() && $g->getValor() > 100) {
                $r->error($p.'ValorDR', 'Un descuento porcentual no puede superar el 100%.', 'RANGO');
            }
            $r->text($p.'GlosaDR', $g->getGlosa(), 45);
            $r->in($p.'IndExeDR', $g->getIndicadorExencion(), [1, 2]);
            if (!\in_array($global->grupo, $grupos, true) && !($global->grupo === LineGroup::NO_FACTURABLE && \in_array(LineGroup::NO_FACTURABLE_NEGATIVO, $grupos, true))) {
                $r->error($p.'IndExeDR', sprintf('El descuento/recargo global aplica a ítems %s, pero el documento no tiene ítems de ese tipo.', str_replace('_', ' ', $global->grupo->value)), 'DESCUENTO_SIN_BASE');
            }
        }
    }

    private function validateReferencias(Rules $r, AbstractDocument $document): void
    {
        $tipo = $document->getTipo();
        $boleta = $document instanceof Boleta;
        $referencias = $document->getReferencias();
        $r->maxCount('Referencia', $referencias, 40);
        $conCodigo = false;
        foreach ($referencias as $i => $ref) {
            $p = sprintf('Referencia[%d].', $i + 1);
            if ($boleta) {
                $r->text($p.'TpoDocRef', $ref->getTipoDocumento(), 3);
                $r->text($p.'FolioRef', $ref->getFolio(), 18);
                $r->text($p.'CodRef', $ref->getCodigoBoleta() ?? ($ref->getCodigo() !== null ? (string) $ref->getCodigo() : null), 18);
                $r->text($p.'RazonRef', $ref->getRazon(), 90);
                $r->text($p.'CodVndor', $ref->getCodigoVendedor(), 8);
                $r->text($p.'CodCaja', $ref->getCodigoCaja(), 8);
                continue;
            }
            $r->text($p.'TpoDocRef', $ref->getTipoDocumento(), 3, true);
            $r->text($p.'FolioRef', $ref->getFolio(), 18, true);
            $r->date($p.'FchRef', $ref->getFecha(), true, $tipo->isExportacion() ? '1950-01-01' : '2000-01-01');
            $r->in($p.'CodRef', $ref->getCodigo(), [1, 2, 3]);
            $r->text($p.'RazonRef', $ref->getRazon(), 90);
            $r->rut($p.'RUTOtr', $ref->getRutOtro());
            if ($ref->getIdAdicionalOtro() !== null) {
                if (!$tipo->isExportacion()) {
                    $r->error($p.'IdAdicOtr', 'IdAdicOtr sólo existe en documentos de exportación.', 'NO_PERMITIDO');
                }
                $r->text($p.'IdAdicOtr', $ref->getIdAdicionalOtro(), 20);
            }
            if ($ref->getCodigoBoleta() !== null || $ref->getCodigoVendedor() !== null || $ref->getCodigoCaja() !== null) {
                $r->error($p.'CodRef', 'CodRef alfanumérico, CodVndor y CodCaja sólo existen en boletas.', 'NO_PERMITIDO');
            }
            if ($ref->getCodigo() !== null) {
                $conCodigo = true;
                if ($ref->getRazon() === null || trim($ref->getRazon()) === '') {
                    $r->warning($p.'RazonRef', 'Se recomienda indicar la razón de la referencia.', 'RAZON_REFERENCIA');
                }
            }
            if ($document->getFechaEmision() && $ref->getFecha() && $ref->getFecha()->format('Y-m-d') > $document->getFechaEmision()->format('Y-m-d')) {
                $r->error($p.'FchRef', 'La fecha del documento referenciado no puede ser posterior a la fecha de emisión.', 'FECHA_REFERENCIA');
            }
        }
        if ($tipo->isNota() && !$conCodigo) {
            $r->error('Referencia', 'Las notas de crédito/débito deben referenciar el documento que modifican con CodRef (1 anula, 2 corrige texto, 3 corrige montos).', 'NOTA_SIN_REFERENCIA');
        }
    }

    private function validateTotales(Rules $r, AbstractDocument $document, Calculation $calculation): void
    {
        foreach ($calculation->diferencias as $diferencia) {
            $r->error('Totales', $diferencia, 'TOTALES_DIFERENCIA');
        }
        $t = $calculation->totales;
        foreach (['MntNeto' => $t->montoNeto, 'MntExe' => $t->montoExento, 'IVA' => $t->iva] as $campo => $valor) {
            if ($valor !== null && $valor < 0) {
                $r->error('Encabezado.Totales.'.$campo, 'El monto resultó negativo (revise descuentos globales).', 'MONTO_NEGATIVO');
            }
        }
        $suma = (float) ($t->montoNeto ?? 0) + (float) ($t->montoExento ?? 0) + (float) ($t->iva ?? 0);
        foreach ($t->impuestos as $imp) {
            $suma += $imp->retencion ? -$imp->monto : $imp->monto;
        }
        if ($suma + (float) ($t->garantiaDeposito ?? 0) < -0.0001) {
            $r->error('Encabezado.Totales.MntTotal', 'El monto total resultó negativo.', 'MONTO_NEGATIVO');
        }
        $maxTotal = $calculation->decimales > 0 ? 99_999_999_999_999.99 : 999_999_999_999_999_999;
        if ((float) $t->montoTotal > $maxTotal) {
            $r->error('Encabezado.Totales.MntTotal', 'Monto total excede el máximo permitido.', 'RANGO');
        }
        if ($document instanceof Dte && $document->getTipo()->isExportacion() && $calculation->totales->montoTotal == 0 && !$document->getTipo()->isNota()) {
            $r->warning('Encabezado.Totales.MntTotal', 'La factura de exportación tiene monto total 0.', 'TOTAL_CERO');
        }
    }

    private function isRutGenerico(Client $receptor): bool
    {
        $rut = $receptor->getRut();
        if ($rut === null || !Rut::isValid($rut)) {
            return false;
        }

        return \in_array(Rut::normalize($rut), self::RUTS_GENERICOS, true);
    }
}
