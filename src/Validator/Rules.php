<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Validator;

use RoyaltyFusion\Sii\Util\Rut;
use RoyaltyFusion\Sii\Util\Text;

/**
 * Reglas atómicas reutilizables (longitudes, RUT, decimales, fechas) basadas en los tipos de SiiTypes_v10.xsd.
 */
final class Rules
{
    public function __construct(private readonly ValidationResult $result)
    {
    }

    public function result(): ValidationResult
    {
        return $this->result;
    }

    public function error(string $path, string $message, string $code): void
    {
        $this->result->error($path, $message, $code);
    }

    public function warning(string $path, string $message, string $code): void
    {
        $this->result->warning($path, $message, $code);
    }

    /** Texto: obligatorio (si $required) y largo máximo después de sanear. */
    public function text(string $path, ?string $value, int $max, bool $required = false, int $min = 1): void
    {
        $clean = Text::sanitize($value);
        if ($clean === '') {
            if ($required) {
                $this->error($path, 'Campo obligatorio.', 'REQUERIDO');
            } elseif ($value !== null && $value !== '') {
                $this->error($path, 'El texto queda vacío luego de eliminar caracteres no válidos.', 'TEXTO_VACIO');
            }

            return;
        }
        $len = Text::length($clean);
        if ($len > $max) {
            $this->error($path, sprintf('Largo máximo %d caracteres (tiene %d).', $max, $len), 'LARGO_MAXIMO');
        }
        if ($len < $min) {
            $this->error($path, sprintf('Largo mínimo %d caracteres.', $min), 'LARGO_MINIMO');
        }
    }

    public function rut(string $path, ?string $value, bool $required = false): void
    {
        if ($value === null || trim($value) === '') {
            if ($required) {
                $this->error($path, 'RUT obligatorio.', 'REQUERIDO');
            }

            return;
        }
        if (!Rut::isValid($value)) {
            $this->error($path, sprintf('RUT "%s" inválido (formato o dígito verificador).', $value), 'RUT_INVALIDO');
        }
    }

    public function email(string $path, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $this->text($path, $value, 80);
        if (filter_var(trim($value), \FILTER_VALIDATE_EMAIL) === false) {
            $this->error($path, sprintf('Correo "%s" inválido.', $value), 'EMAIL_INVALIDO');
        }
    }

    /** Entero dentro de rango. */
    public function int(string $path, ?int $value, int $min, int $max, bool $required = false): void
    {
        if ($value === null) {
            if ($required) {
                $this->error($path, 'Campo obligatorio.', 'REQUERIDO');
            }

            return;
        }
        if ($value < $min || $value > $max) {
            $this->error($path, sprintf('Valor %d fuera de rango (%d a %d).', $value, $min, $max), 'RANGO');
        }
    }

    /** @param list<int|string> $allowed */
    public function in(string $path, int|string|null $value, array $allowed, bool $required = false): void
    {
        if ($value === null) {
            if ($required) {
                $this->error($path, 'Campo obligatorio.', 'REQUERIDO');
            }

            return;
        }
        if (!\in_array($value, $allowed, false)) {
            $this->error($path, sprintf('Valor "%s" no permitido (valores válidos: %s).', $value, implode(', ', $allowed)), 'VALOR_NO_PERMITIDO');
        }
    }

    /**
     * Decimal con máximo de dígitos enteros/fraccionarios (Dec12_6Type, PctType, etc.).
     */
    public function decimal(string $path, float|int|null $value, int $intDigits, int $fracDigits, float $min, bool $required = false): void
    {
        if ($value === null) {
            if ($required) {
                $this->error($path, 'Campo obligatorio.', 'REQUERIDO');
            }

            return;
        }
        if (!is_finite((float) $value)) {
            $this->error($path, 'Número inválido.', 'NUMERO_INVALIDO');

            return;
        }
        if ($value < $min) {
            $this->error($path, sprintf('Debe ser mayor o igual a %s.', Text::decimal($min, $fracDigits)), 'RANGO');
        }
        $max = 10 ** $intDigits - 10 ** -$fracDigits;
        if (abs((float) $value) > $max) {
            $this->error($path, sprintf('Máximo %d dígitos enteros.', $intDigits), 'RANGO');
        }
        if (abs(round((float) $value, $fracDigits) - (float) $value) > 1e-9) {
            $this->warning($path, sprintf('Tiene más de %d decimales; se redondeará a %s.', $fracDigits, Text::decimal($value, $fracDigits)), 'DECIMALES');
        }
    }

    public function date(string $path, ?\DateTimeInterface $value, bool $required = false, string $min = '2000-01-01'): void
    {
        if ($value === null) {
            if ($required) {
                $this->error($path, 'Fecha obligatoria.', 'REQUERIDO');
            }

            return;
        }
        $d = $value->format('Y-m-d');
        if ($d < $min || $d > '2050-12-31') {
            $this->error($path, sprintf('Fecha %s fuera del rango permitido (%s a 2050-12-31).', $d, $min), 'FECHA_RANGO');
        }
    }

    /** @param array<mixed> $list */
    public function maxCount(string $path, array $list, int $max): void
    {
        if (\count($list) > $max) {
            $this->error($path, sprintf('Máximo %d elementos (tiene %d).', $max, \count($list)), 'MAXIMO_ELEMENTOS');
        }
    }
}
