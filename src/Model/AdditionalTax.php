<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

/**
 * Impuesto adicional o retención (ImptoReten) aplicable a los ítems que lo declaran en CodImpAdic.
 *
 * La base imponible es la suma de los MontoItem afectos que incluyen el código (ajustada por
 * descuentos/recargos globales afectos), y el monto es base × tasa / 100.
 */
class AdditionalTax
{
    /** Tasas vigentes conocidas (pueden sobreescribirse indicando la tasa). */
    public const DEFAULT_RATES = [
        '15' => 19.0,   // IVA retenido total
        '23' => 15.0,   // Art. 37 letras a, b, c (artículos de oro, joyas, pieles, etc.)
        '24' => 31.5,   // Licores, piscos, whisky, aguardientes, destilados
        '25' => 20.5,   // Vinos
        '26' => 20.5,   // Cervezas y bebidas alcohólicas
        '27' => 10.0,   // Bebidas analcohólicas y minerales
        '271' => 18.0,  // Bebidas analcohólicas con elevado contenido de azúcar
        '44' => 15.0,   // Art. 37 letras e, h, i, l (alfombras, casas rodantes, caviar, armas)
        '45' => 50.0,   // Pirotecnia
    ];

    /** Códigos que corresponden a IVA retenido (se restan del total). */
    public const RETENTION_CODES = [
        '15', '30', '31', '32', '33', '34', '36', '37', '38', '39', '41', '46', '47', '48', '49', '53', '60',
        '301', '321', '331', '341', '361', '371', '481',
    ];

    private readonly string $codigo;
    private readonly float $tasa;
    private readonly bool $retencion;

    public function __construct(string|int $codigo, float|int|null $tasa = null, ?bool $retencion = null)
    {
        $this->codigo = (string) $codigo;
        $rate = $tasa ?? (self::DEFAULT_RATES[$this->codigo] ?? null);
        if ($rate === null) {
            throw new \RoyaltyFusion\Sii\Exception\InvalidArgumentException(sprintf('Debe indicar la tasa del impuesto adicional código %s.', $this->codigo));
        }
        $this->tasa = (float) $rate;
        $this->retencion = $retencion ?? \in_array($this->codigo, self::RETENTION_CODES, true);
    }

    public function getCodigo(): string
    {
        return $this->codigo;
    }

    public function getTasa(): float
    {
        return $this->tasa;
    }

    public function isRetencion(): bool
    {
        return $this->retencion;
    }
}
