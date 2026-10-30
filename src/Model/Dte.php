<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

use RoyaltyFusion\Sii\Exception\InvalidArgumentException;

/**
 * Documento Tributario Electrónico (DTE_v10.xsd): facturas (33, 34, 46), guías de despacho (52),
 * notas de débito/crédito (56, 61) y documentos de exportación (110, 111, 112).
 *
 * Uso típico:
 *   $factura = Dte::factura()->setFolio(1)->setFechaEmision(new DateTimeImmutable())
 *       ->setEmisor($empresa)->setReceptor($cliente)->addDetalle(Detail::create('Producto', 2, 1000));
 */
class Dte extends AbstractDocument
{
    private bool $indicadorNoRebaja = false;
    private ?int $tipoDespacho = null;
    private ?int $indicadorTraslado = null;
    private ?string $tipoImpresion = null;
    private ?int $indicadorServicio = null;
    private bool $montoBruto = false;
    private ?int $tipoTransaccionCompra = null;
    private ?int $tipoTransaccionVenta = null;
    private ?int $formaPago = null;
    private ?int $formaPagoExportacion = null;
    private ?\DateTimeImmutable $fechaCancelacion = null;
    private ?int $montoCancelado = null;
    private ?int $saldoInsoluto = null;
    private ?string $medioPago = null;
    private ?string $tipoCuentaPago = null;
    private ?string $numeroCuentaPago = null;
    private ?string $bancoPago = null;
    private ?string $terminoPagoCodigo = null;
    private ?string $terminoPagoGlosa = null;
    private ?int $terminoPagoDias = null;
    private ?string $rutMandante = null;
    private ?string $rutSolicita = null;
    private ?Transport $transporte = null;
    private float $tasaIva = 19.0;
    private ?string $tipoMoneda = null;
    private ?OtherCurrency $otraMoneda = null;
    /** @var list<Payment> */
    private array $pagos = [];
    /** @var array<string, AdditionalTax> */
    private array $impuestosAdicionales = [];

    public function __construct(DocumentType $tipo)
    {
        if ($tipo->isBoleta()) {
            throw new InvalidArgumentException('Para boletas (39, 41) use la clase Boleta.');
        }
        parent::__construct($tipo);
    }

    public static function factura(): self
    {
        return new self(DocumentType::FACTURA);
    }

    public static function facturaExenta(): self
    {
        return new self(DocumentType::FACTURA_EXENTA);
    }

    public static function facturaCompra(): self
    {
        return new self(DocumentType::FACTURA_COMPRA);
    }

    public static function guiaDespacho(int $indicadorTraslado = 1): self
    {
        return (new self(DocumentType::GUIA_DESPACHO))->setIndicadorTraslado($indicadorTraslado);
    }

    public static function notaDebito(): self
    {
        return new self(DocumentType::NOTA_DEBITO);
    }

    public static function notaCredito(): self
    {
        return new self(DocumentType::NOTA_CREDITO);
    }

    public static function facturaExportacion(string $tipoMoneda = 'DOLAR USA'): self
    {
        return (new self(DocumentType::FACTURA_EXPORTACION))->setTipoMoneda($tipoMoneda);
    }

    public static function notaDebitoExportacion(string $tipoMoneda = 'DOLAR USA'): self
    {
        return (new self(DocumentType::NOTA_DEBITO_EXPORTACION))->setTipoMoneda($tipoMoneda);
    }

    public static function notaCreditoExportacion(string $tipoMoneda = 'DOLAR USA'): self
    {
        return (new self(DocumentType::NOTA_CREDITO_EXPORTACION))->setTipoMoneda($tipoMoneda);
    }

    /** IndNoRebaja = 1: nota de crédito sin derecho a descontar débito. */
    public function isIndicadorNoRebaja(): bool
    {
        return $this->indicadorNoRebaja;
    }

    public function setIndicadorNoRebaja(bool $indicadorNoRebaja): static
    {
        $this->indicadorNoRebaja = $indicadorNoRebaja;

        return $this;
    }

    /** TipoDespacho (guías/facturas): 1 por cuenta del receptor, 2 emisor a instalaciones del cliente, 3 emisor a otras instalaciones. */
    public function getTipoDespacho(): ?int
    {
        return $this->tipoDespacho;
    }

    public function setTipoDespacho(?int $tipoDespacho): static
    {
        $this->tipoDespacho = $tipoDespacho;

        return $this;
    }

    /** IndTraslado (obligatorio en guías): 1 venta, 2 venta por efectuar, 3 consignación, 4 entrega gratuita, 5 traslado interno, 6 otro traslado no venta, 7 guía de devolución, 8 traslado para exportación, 9 venta para exportación. */
    public function getIndicadorTraslado(): ?int
    {
        return $this->indicadorTraslado;
    }

    public function setIndicadorTraslado(?int $indicadorTraslado): static
    {
        $this->indicadorTraslado = $indicadorTraslado;

        return $this;
    }

    /** TpoImpresion: N normal, T ticket. */
    public function getTipoImpresion(): ?string
    {
        return $this->tipoImpresion;
    }

    public function setTipoImpresion(?string $tipoImpresion): static
    {
        $this->tipoImpresion = $tipoImpresion;

        return $this;
    }

    /** IndServicio: 1 servicios periódicos, 2 periódicos domiciliarios, 3 facturación de servicios (exportación: 3, 4 o 5). */
    public function getIndicadorServicio(): ?int
    {
        return $this->indicadorServicio;
    }

    public function setIndicadorServicio(?int $indicadorServicio): static
    {
        $this->indicadorServicio = $indicadorServicio;

        return $this;
    }

    /** MntBruto = 1: los precios del detalle incluyen IVA; el SDK desglosa neto e IVA. */
    public function isMontoBruto(): bool
    {
        return $this->montoBruto;
    }

    public function setMontoBruto(bool $montoBruto): static
    {
        $this->montoBruto = $montoBruto;

        return $this;
    }

    /** TpoTranCompra (1 a 7). */
    public function getTipoTransaccionCompra(): ?int
    {
        return $this->tipoTransaccionCompra;
    }

    public function setTipoTransaccionCompra(?int $tipoTransaccionCompra): static
    {
        $this->tipoTransaccionCompra = $tipoTransaccionCompra;

        return $this;
    }

    /** TpoTranVenta (1 a 4). */
    public function getTipoTransaccionVenta(): ?int
    {
        return $this->tipoTransaccionVenta;
    }

    public function setTipoTransaccionVenta(?int $tipoTransaccionVenta): static
    {
        $this->tipoTransaccionVenta = $tipoTransaccionVenta;

        return $this;
    }

    /** FmaPago: 1 contado, 2 crédito, 3 sin costo (entrega gratuita). */
    public function getFormaPago(): ?int
    {
        return $this->formaPago;
    }

    public function setFormaPago(?int $formaPago): static
    {
        $this->formaPago = $formaPago;

        return $this;
    }

    /** FmaPagExp según tabla de Aduanas (sólo exportación). */
    public function getFormaPagoExportacion(): ?int
    {
        return $this->formaPagoExportacion;
    }

    public function setFormaPagoExportacion(?int $formaPagoExportacion): static
    {
        $this->formaPagoExportacion = $formaPagoExportacion;

        return $this;
    }

    /** FchCancel */
    public function getFechaCancelacion(): ?\DateTimeImmutable
    {
        return $this->fechaCancelacion;
    }

    public function setFechaCancelacion(?\DateTimeInterface $fechaCancelacion): static
    {
        $this->fechaCancelacion = $fechaCancelacion === null ? null : \DateTimeImmutable::createFromInterface($fechaCancelacion);

        return $this;
    }

    /** MntCancel */
    public function getMontoCancelado(): ?int
    {
        return $this->montoCancelado;
    }

    public function setMontoCancelado(?int $montoCancelado): static
    {
        $this->montoCancelado = $montoCancelado;

        return $this;
    }

    /** SaldoInsol */
    public function getSaldoInsoluto(): ?int
    {
        return $this->saldoInsoluto;
    }

    public function setSaldoInsoluto(?int $saldoInsoluto): static
    {
        $this->saldoInsoluto = $saldoInsoluto;

        return $this;
    }

    /** MedioPago: CH cheque, CF cheque a fecha, LT letra, EF efectivo, PE pago a cta. cte., TC tarjeta, OT otro. */
    public function getMedioPago(): ?string
    {
        return $this->medioPago;
    }

    public function setMedioPago(?string $medioPago): static
    {
        $this->medioPago = $medioPago;

        return $this;
    }

    /** TpoCtaPago: AHORRO, CORRIENTE, VISTA. */
    public function getTipoCuentaPago(): ?string
    {
        return $this->tipoCuentaPago;
    }

    public function setTipoCuentaPago(?string $tipoCuentaPago): static
    {
        $this->tipoCuentaPago = $tipoCuentaPago;

        return $this;
    }

    /** NumCtaPago */
    public function getNumeroCuentaPago(): ?string
    {
        return $this->numeroCuentaPago;
    }

    public function setNumeroCuentaPago(?string $numeroCuentaPago): static
    {
        $this->numeroCuentaPago = $numeroCuentaPago;

        return $this;
    }

    /** BcoPago */
    public function getBancoPago(): ?string
    {
        return $this->bancoPago;
    }

    public function setBancoPago(?string $bancoPago): static
    {
        $this->bancoPago = $bancoPago;

        return $this;
    }

    /** TermPagoCdg */
    public function getTerminoPagoCodigo(): ?string
    {
        return $this->terminoPagoCodigo;
    }

    public function setTerminoPagoCodigo(?string $terminoPagoCodigo): static
    {
        $this->terminoPagoCodigo = $terminoPagoCodigo;

        return $this;
    }

    /** TermPagoGlosa */
    public function getTerminoPagoGlosa(): ?string
    {
        return $this->terminoPagoGlosa;
    }

    public function setTerminoPagoGlosa(?string $terminoPagoGlosa): static
    {
        $this->terminoPagoGlosa = $terminoPagoGlosa;

        return $this;
    }

    /** TermPagoDias */
    public function getTerminoPagoDias(): ?int
    {
        return $this->terminoPagoDias;
    }

    public function setTerminoPagoDias(?int $terminoPagoDias): static
    {
        $this->terminoPagoDias = $terminoPagoDias;

        return $this;
    }

    /** RUTMandante */
    public function getRutMandante(): ?string
    {
        return $this->rutMandante;
    }

    public function setRutMandante(?string $rutMandante): static
    {
        $this->rutMandante = $rutMandante;

        return $this;
    }

    /** RUTSolicita */
    public function getRutSolicita(): ?string
    {
        return $this->rutSolicita;
    }

    public function setRutSolicita(?string $rutSolicita): static
    {
        $this->rutSolicita = $rutSolicita;

        return $this;
    }

    /** Transporte (guías de despacho y exportación). */
    public function getTransporte(): ?Transport
    {
        return $this->transporte;
    }

    public function setTransporte(?Transport $transporte): static
    {
        $this->transporte = $transporte;

        return $this;
    }

    /** Tasa de IVA (TasaIVA). Por defecto 19%. */
    public function getTasaIva(): float
    {
        return $this->tasaIva;
    }

    public function setTasaIva(float $tasaIva): static
    {
        $this->tasaIva = $tasaIva;

        return $this;
    }

    /** TpoMoneda de los totales (obligatorio en exportación, ej. "DOLAR USA"). */
    public function getTipoMoneda(): ?string
    {
        return $this->tipoMoneda;
    }

    public function setTipoMoneda(?string $tipoMoneda): static
    {
        $this->tipoMoneda = $tipoMoneda;

        return $this;
    }

    /** OtraMoneda */
    public function getOtraMoneda(): ?OtherCurrency
    {
        return $this->otraMoneda;
    }

    public function setOtraMoneda(?OtherCurrency $otraMoneda): static
    {
        $this->otraMoneda = $otraMoneda;

        return $this;
    }

    /** @return list<Payment> */
    public function getPagos(): array
    {
        return $this->pagos;
    }

    /** @param list<Payment> $pagos máximo 30 */
    public function setPagos(array $pagos): static
    {
        $this->pagos = array_values($pagos);

        return $this;
    }

    public function addPago(Payment $pago): static
    {
        $this->pagos[] = $pago;

        return $this;
    }

    /** @return array<string, AdditionalTax> */
    public function getImpuestosAdicionales(): array
    {
        return $this->impuestosAdicionales;
    }

    /**
     * Define la tasa de un impuesto adicional o retención usado en CodImpAdic de los ítems.
     * Si no se define y el código tiene tasa conocida, se usa la tasa por defecto.
     */
    public function addImpuestoAdicional(AdditionalTax $impuesto): static
    {
        $this->impuestosAdicionales[$impuesto->getCodigo()] = $impuesto;

        return $this;
    }
}
