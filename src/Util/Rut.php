<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Util;

use RoyaltyFusion\Sii\Exception\InvalidArgumentException;

/**
 * Rol Único Tributario (RUT) chileno.
 *
 * Acepta formatos "76.123.456-7", "76123456-7", "761234567" y normaliza a
 * "76123456-7" (formato exigido por el SII: sin puntos, con guion y DV en mayúscula).
 */
final class Rut implements \Stringable
{
    /** RUT del Servicio de Impuestos Internos (receptor de los envíos). */
    public const SII = '60803000-K';

    /** RUT genérico para boletas a consumidor final sin identificar. */
    public const CONSUMIDOR_FINAL = '66666666-6';

    /** RUT genérico usado para receptores extranjeros en documentos de exportación. */
    public const EXTRANJERO = '55555555-5';

    private function __construct(
        public readonly int $number,
        public readonly string $dv,
    ) {
    }

    public static function parse(string $rut): self
    {
        $clean = self::clean($rut);
        if (!preg_match('/^(\d{1,8})([\dK])$/', $clean, $m)) {
            throw new InvalidArgumentException(sprintf('RUT "%s" con formato inválido.', $rut));
        }
        $number = (int) $m[1];
        $dv = $m[2];
        if ($number <= 0) {
            throw new InvalidArgumentException(sprintf('RUT "%s" inválido.', $rut));
        }
        if (self::computeDv($number) !== $dv) {
            throw new InvalidArgumentException(sprintf('RUT "%s" tiene dígito verificador incorrecto (esperado %s).', $rut, self::computeDv($number)));
        }

        return new self($number, $dv);
    }

    public static function isValid(?string $rut): bool
    {
        if ($rut === null || $rut === '') {
            return false;
        }
        try {
            self::parse($rut);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** Normaliza a "12345678-9"; lanza excepción si es inválido. */
    public static function normalize(string $rut): string
    {
        return (string) self::parse($rut);
    }

    public static function computeDv(int $number): string
    {
        $sum = 0;
        $factor = 2;
        for ($n = $number; $n > 0; $n = intdiv($n, 10)) {
            $sum += ($n % 10) * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }
        $dv = 11 - ($sum % 11);

        return match ($dv) {
            11 => '0',
            10 => 'K',
            default => (string) $dv,
        };
    }

    /** RUT genérico 66666666-6 (boletas sin receptor identificado). */
    public static function isConsumidorFinal(string $rut): bool
    {
        return self::isValid($rut) && self::normalize($rut) === self::CONSUMIDOR_FINAL;
    }

    public function format(bool $withDots = false): string
    {
        $num = $withDots ? number_format($this->number, 0, ',', '.') : (string) $this->number;

        return $num.'-'.$this->dv;
    }

    public function equals(self|string $other): bool
    {
        $other = \is_string($other) ? self::parse($other) : $other;

        return $other->number === $this->number && $other->dv === $this->dv;
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private static function clean(string $rut): string
    {
        return strtoupper((string) preg_replace('/[^0-9kK]/', '', $rut));
    }
}
