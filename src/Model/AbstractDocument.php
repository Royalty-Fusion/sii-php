<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

/** Datos comunes a todos los documentos tributarios electrónicos (DTE y boletas). */
abstract class AbstractDocument
{
    protected ?int $folio = null;
    protected ?\DateTimeImmutable $fechaEmision = null;
    protected ?Company $emisor = null;
    protected ?Client $receptor = null;
    /** @var list<Detail> */
    protected array $detalles = [];
    /** @var list<GlobalDiscount> */
    protected array $descuentosGlobales = [];
    /** @var list<Reference> */
    protected array $referencias = [];
    protected ?\DateTimeImmutable $periodoDesde = null;
    protected ?\DateTimeImmutable $periodoHasta = null;
    protected ?\DateTimeImmutable $fechaVencimiento = null;
    protected float|int|null $montoTotalEsperado = null;

    public function __construct(protected DocumentType $tipo)
    {
    }

    public function getTipo(): DocumentType
    {
        return $this->tipo;
    }

    /** Identificador del nodo Documento usado como referencia de la firma (ej. "T33F125"). */
    public function getId(): string
    {
        return sprintf('T%dF%d', $this->tipo->value, $this->folio ?? 0);
    }

    public function getFolio(): ?int
    {
        return $this->folio;
    }

    public function setFolio(?int $folio): static
    {
        $this->folio = $folio;

        return $this;
    }

    public function getFechaEmision(): ?\DateTimeImmutable
    {
        return $this->fechaEmision;
    }

    public function setFechaEmision(?\DateTimeInterface $fechaEmision): static
    {
        $this->fechaEmision = $fechaEmision === null ? null : \DateTimeImmutable::createFromInterface($fechaEmision);

        return $this;
    }

    public function getEmisor(): ?Company
    {
        return $this->emisor;
    }

    public function setEmisor(?Company $emisor): static
    {
        $this->emisor = $emisor;

        return $this;
    }

    public function getReceptor(): ?Client
    {
        return $this->receptor;
    }

    public function setReceptor(?Client $receptor): static
    {
        $this->receptor = $receptor;

        return $this;
    }

    /** @return list<Detail> */
    public function getDetalles(): array
    {
        return $this->detalles;
    }

    /** @param list<Detail> $detalles */
    public function setDetalles(array $detalles): static
    {
        $this->detalles = array_values($detalles);

        return $this;
    }

    public function addDetalle(Detail $detalle): static
    {
        $this->detalles[] = $detalle;

        return $this;
    }

    /** @return list<GlobalDiscount> */
    public function getDescuentosGlobales(): array
    {
        return $this->descuentosGlobales;
    }

    /** @param list<GlobalDiscount> $descuentos */
    public function setDescuentosGlobales(array $descuentos): static
    {
        $this->descuentosGlobales = array_values($descuentos);

        return $this;
    }

    public function addDescuentoGlobal(GlobalDiscount $descuento): static
    {
        $this->descuentosGlobales[] = $descuento;

        return $this;
    }

    /** @return list<Reference> */
    public function getReferencias(): array
    {
        return $this->referencias;
    }

    /** @param list<Reference> $referencias */
    public function setReferencias(array $referencias): static
    {
        $this->referencias = array_values($referencias);

        return $this;
    }

    public function addReferencia(Reference $referencia): static
    {
        $this->referencias[] = $referencia;

        return $this;
    }

    public function getPeriodoDesde(): ?\DateTimeImmutable
    {
        return $this->periodoDesde;
    }

    public function setPeriodoDesde(?\DateTimeInterface $periodoDesde): static
    {
        $this->periodoDesde = $periodoDesde === null ? null : \DateTimeImmutable::createFromInterface($periodoDesde);

        return $this;
    }

    public function getPeriodoHasta(): ?\DateTimeImmutable
    {
        return $this->periodoHasta;
    }

    public function setPeriodoHasta(?\DateTimeInterface $periodoHasta): static
    {
        $this->periodoHasta = $periodoHasta === null ? null : \DateTimeImmutable::createFromInterface($periodoHasta);

        return $this;
    }

    public function getFechaVencimiento(): ?\DateTimeImmutable
    {
        return $this->fechaVencimiento;
    }

    public function setFechaVencimiento(?\DateTimeInterface $fechaVencimiento): static
    {
        $this->fechaVencimiento = $fechaVencimiento === null ? null : \DateTimeImmutable::createFromInterface($fechaVencimiento);

        return $this;
    }

    /**
     * Monto total calculado por el ERP. Si se informa, el SDK verifica que coincida con su propio
     * cálculo antes de emitir (evita emitir un documento con un total distinto al registrado).
     */
    public function getMontoTotalEsperado(): float|int|null
    {
        return $this->montoTotalEsperado;
    }

    public function setMontoTotalEsperado(float|int|null $montoTotalEsperado): static
    {
        $this->montoTotalEsperado = $montoTotalEsperado;

        return $this;
    }
}
