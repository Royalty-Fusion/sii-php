<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

/** Referencia a otro documento (nodo Referencia). */
class Reference
{
    /** CodRef: anula documento de referencia. */
    public const ANULA = 1;
    /** CodRef: corrige texto del documento de referencia. */
    public const CORRIGE_TEXTO = 2;
    /** CodRef: corrige montos. */
    public const CORRIGE_MONTOS = 3;

    private ?string $tipoDocumento = null;
    private ?string $folio = null;
    private ?\DateTimeInterface $fecha = null;
    private ?int $codigo = null;
    private ?string $razon = null;
    private ?string $rutOtro = null;
    private bool $global = false;
    private ?string $idAdicionalOtro = null;
    /** Sólo boletas: CodRef alfanumérico, código de vendedor y de caja. */
    private ?string $codigoBoleta = null;
    private ?string $codigoVendedor = null;
    private ?string $codigoCaja = null;

    /** Constructor final: permite los constructores estáticos con new static() de forma segura. */
    final public function __construct()
    {
    }

    /**
     * @param string|int|DocumentType $tipoDocumento tipo de documento referenciado (33, 39, 801 = orden de compra, SET, HES, etc.)
     */
    public static function create(string|int|DocumentType $tipoDocumento, string|int $folio, \DateTimeInterface $fecha, ?int $codigo = null, ?string $razon = null): static
    {
        return (new static())
            ->setTipoDocumento($tipoDocumento instanceof DocumentType ? (string) $tipoDocumento->value : (string) $tipoDocumento)
            ->setFolio((string) $folio)
            ->setFecha($fecha)
            ->setCodigo($codigo)
            ->setRazon($razon);
    }

    public function getTipoDocumento(): ?string
    {
        return $this->tipoDocumento;
    }

    public function setTipoDocumento(string|int|DocumentType|null $tipoDocumento): static
    {
        $this->tipoDocumento = $tipoDocumento instanceof DocumentType ? (string) $tipoDocumento->value : ($tipoDocumento === null ? null : (string) $tipoDocumento);

        return $this;
    }

    public function getFolio(): ?string
    {
        return $this->folio;
    }

    public function setFolio(string|int|null $folio): static
    {
        $this->folio = $folio === null ? null : (string) $folio;

        return $this;
    }

    public function getFecha(): ?\DateTimeInterface
    {
        return $this->fecha;
    }

    public function setFecha(?\DateTimeInterface $fecha): static
    {
        $this->fecha = $fecha;

        return $this;
    }

    /** CodRef: 1 anula, 2 corrige texto, 3 corrige montos. */
    public function getCodigo(): ?int
    {
        return $this->codigo;
    }

    public function setCodigo(?int $codigo): static
    {
        $this->codigo = $codigo;

        return $this;
    }

    public function getRazon(): ?string
    {
        return $this->razon;
    }

    public function setRazon(?string $razon): static
    {
        $this->razon = $razon;

        return $this;
    }

    public function getRutOtro(): ?string
    {
        return $this->rutOtro;
    }

    public function setRutOtro(?string $rutOtro): static
    {
        $this->rutOtro = $rutOtro;

        return $this;
    }

    /** IndGlobal = 1: la referencia corresponde a un conjunto de documentos. */
    public function isGlobal(): bool
    {
        return $this->global;
    }

    public function setGlobal(bool $global): static
    {
        $this->global = $global;

        return $this;
    }

    public function getIdAdicionalOtro(): ?string
    {
        return $this->idAdicionalOtro;
    }

    public function setIdAdicionalOtro(?string $idAdicionalOtro): static
    {
        $this->idAdicionalOtro = $idAdicionalOtro;

        return $this;
    }

    public function getCodigoBoleta(): ?string
    {
        return $this->codigoBoleta;
    }

    /** Sólo boletas: CodRef (ej. "SET" en certificación). */
    public function setCodigoBoleta(?string $codigoBoleta): static
    {
        $this->codigoBoleta = $codigoBoleta;

        return $this;
    }

    public function getCodigoVendedor(): ?string
    {
        return $this->codigoVendedor;
    }

    public function setCodigoVendedor(?string $codigoVendedor): static
    {
        $this->codigoVendedor = $codigoVendedor;

        return $this;
    }

    public function getCodigoCaja(): ?string
    {
        return $this->codigoCaja;
    }

    public function setCodigoCaja(?string $codigoCaja): static
    {
        $this->codigoCaja = $codigoCaja;

        return $this;
    }
}
