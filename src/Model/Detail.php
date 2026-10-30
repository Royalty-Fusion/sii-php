<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

/** Línea de detalle del documento (nodo Detalle). */
class Detail
{
    /** IndExe: producto o servicio no afecto o exento de IVA. */
    public const EXENTO = 1;
    /** IndExe: producto o servicio no facturable. */
    public const NO_FACTURABLE = 2;
    /** IndExe: garantía de depósito por envases. */
    public const GARANTIA_ENVASES = 3;
    /** IndExe: ítem no venta (guías de despacho). */
    public const NO_VENTA = 4;
    /** IndExe: ítem a rebajar (guías de despacho). */
    public const A_REBAJAR = 5;
    /** IndExe: producto o servicio no facturable negativo. */
    public const NO_FACTURABLE_NEGATIVO = 6;

    private ?string $nombre = null;
    private ?string $descripcion = null;
    private float|int|null $cantidad = null;
    private ?string $unidad = null;
    private float|int|null $precio = null;
    /** @var list<ItemCode> */
    private array $codigos = [];
    private ?int $indicadorExencion = null;
    private float|int|null $descuentoPorcentaje = null;
    private float|int|null $descuentoMonto = null;
    private float|int|null $recargoPorcentaje = null;
    private float|int|null $recargoMonto = null;
    /** @var list<string> */
    private array $impuestosAdicionales = [];
    private ?\DateTimeInterface $fechaElaboracion = null;
    private ?\DateTimeInterface $fechaVencimiento = null;
    private float|int|null $cantidadReferencia = null;
    private ?string $unidadReferencia = null;
    private float|int|null $precioReferencia = null;
    private ?int $itemEspectaculo = null;
    private ?string $rutMandante = null;
    private float|int|null $montoItem = null;

    public static function create(string $nombre, float|int $cantidad, float|int $precio, ?string $unidad = null): static
    {
        return (new static())->setNombre($nombre)->setCantidad($cantidad)->setPrecio($precio)->setUnidad($unidad);
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setNombre(?string $nombre): static
    {
        $this->nombre = $nombre;

        return $this;
    }

    public function getDescripcion(): ?string
    {
        return $this->descripcion;
    }

    public function setDescripcion(?string $descripcion): static
    {
        $this->descripcion = $descripcion;

        return $this;
    }

    public function getCantidad(): float|int|null
    {
        return $this->cantidad;
    }

    public function setCantidad(float|int|null $cantidad): static
    {
        $this->cantidad = $cantidad;

        return $this;
    }

    public function getUnidad(): ?string
    {
        return $this->unidad;
    }

    public function setUnidad(?string $unidad): static
    {
        $this->unidad = $unidad;

        return $this;
    }

    /**
     * Precio unitario. En facturas es neto (salvo MntBruto); en boletas incluye IVA (salvo IndMntNeto).
     */
    public function getPrecio(): float|int|null
    {
        return $this->precio;
    }

    public function setPrecio(float|int|null $precio): static
    {
        $this->precio = $precio;

        return $this;
    }

    /** @return list<ItemCode> */
    public function getCodigos(): array
    {
        return $this->codigos;
    }

    /** @param list<ItemCode> $codigos */
    public function setCodigos(array $codigos): static
    {
        $this->codigos = array_values($codigos);

        return $this;
    }

    public function addCodigo(string $tipo, string $valor): static
    {
        $this->codigos[] = new ItemCode($tipo, $valor);

        return $this;
    }

    /** IndExe (ver constantes EXENTO, NO_FACTURABLE, ...). Null = afecto. */
    public function getIndicadorExencion(): ?int
    {
        return $this->indicadorExencion;
    }

    public function setIndicadorExencion(?int $indicadorExencion): static
    {
        $this->indicadorExencion = $indicadorExencion;

        return $this;
    }

    public function setExento(bool $exento = true): static
    {
        $this->indicadorExencion = $exento ? self::EXENTO : null;

        return $this;
    }

    public function isExento(): bool
    {
        return $this->indicadorExencion === self::EXENTO;
    }

    public function getDescuentoPorcentaje(): float|int|null
    {
        return $this->descuentoPorcentaje;
    }

    public function setDescuentoPorcentaje(float|int|null $descuentoPorcentaje): static
    {
        $this->descuentoPorcentaje = $descuentoPorcentaje;

        return $this;
    }

    public function getDescuentoMonto(): float|int|null
    {
        return $this->descuentoMonto;
    }

    public function setDescuentoMonto(float|int|null $descuentoMonto): static
    {
        $this->descuentoMonto = $descuentoMonto;

        return $this;
    }

    public function getRecargoPorcentaje(): float|int|null
    {
        return $this->recargoPorcentaje;
    }

    public function setRecargoPorcentaje(float|int|null $recargoPorcentaje): static
    {
        $this->recargoPorcentaje = $recargoPorcentaje;

        return $this;
    }

    public function getRecargoMonto(): float|int|null
    {
        return $this->recargoMonto;
    }

    public function setRecargoMonto(float|int|null $recargoMonto): static
    {
        $this->recargoMonto = $recargoMonto;

        return $this;
    }

    /** @return list<string> */
    public function getImpuestosAdicionales(): array
    {
        return $this->impuestosAdicionales;
    }

    /**
     * Códigos de impuesto adicional o retención que afectan al ítem (CodImpAdic, máximo 2).
     *
     * @param list<string|int> $codigos
     */
    public function setImpuestosAdicionales(array $codigos): static
    {
        $this->impuestosAdicionales = array_values(array_map('strval', $codigos));

        return $this;
    }

    public function addImpuestoAdicional(string|int $codigo): static
    {
        $this->impuestosAdicionales[] = (string) $codigo;

        return $this;
    }

    public function getFechaElaboracion(): ?\DateTimeInterface
    {
        return $this->fechaElaboracion;
    }

    public function setFechaElaboracion(?\DateTimeInterface $fechaElaboracion): static
    {
        $this->fechaElaboracion = $fechaElaboracion;

        return $this;
    }

    public function getFechaVencimiento(): ?\DateTimeInterface
    {
        return $this->fechaVencimiento;
    }

    public function setFechaVencimiento(?\DateTimeInterface $fechaVencimiento): static
    {
        $this->fechaVencimiento = $fechaVencimiento;

        return $this;
    }

    public function getCantidadReferencia(): float|int|null
    {
        return $this->cantidadReferencia;
    }

    public function setCantidadReferencia(float|int|null $cantidadReferencia): static
    {
        $this->cantidadReferencia = $cantidadReferencia;

        return $this;
    }

    public function getUnidadReferencia(): ?string
    {
        return $this->unidadReferencia;
    }

    public function setUnidadReferencia(?string $unidadReferencia): static
    {
        $this->unidadReferencia = $unidadReferencia;

        return $this;
    }

    public function getPrecioReferencia(): float|int|null
    {
        return $this->precioReferencia;
    }

    public function setPrecioReferencia(float|int|null $precioReferencia): static
    {
        $this->precioReferencia = $precioReferencia;

        return $this;
    }

    /** Sólo boletas: 1 = espectáculo, 2 = cobro por cuenta de terceros. */
    public function getItemEspectaculo(): ?int
    {
        return $this->itemEspectaculo;
    }

    public function setItemEspectaculo(?int $itemEspectaculo): static
    {
        $this->itemEspectaculo = $itemEspectaculo;

        return $this;
    }

    /** Sólo boletas: RUT del mandante cuando se vende por cuenta de terceros. */
    public function getRutMandante(): ?string
    {
        return $this->rutMandante;
    }

    public function setRutMandante(?string $rutMandante): static
    {
        $this->rutMandante = $rutMandante;

        return $this;
    }

    /**
     * Monto del ítem informado por el ERP. Si es null el SDK lo calcula
     * (cantidad × precio − descuento + recargo). Si se informa, se valida contra el cálculo.
     */
    public function getMontoItem(): float|int|null
    {
        return $this->montoItem;
    }

    public function setMontoItem(float|int|null $montoItem): static
    {
        $this->montoItem = $montoItem;

        return $this;
    }
}
