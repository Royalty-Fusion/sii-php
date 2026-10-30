<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws\Result;

use RoyaltyFusion\Sii\Util\Clock;

/**
 * Datos mínimos de un documento enviado: lo necesario para consultar su estado (QueryEstDte o API de
 * boletas) desde otro proceso, sin rearmar el documento.
 */
final class DocumentRef
{
    public function __construct(
        public readonly int $tipoDte,
        public readonly int $folio,
        /** Fecha de emisión AAAA-MM-DD. */
        public readonly string $fechaEmision,
        public readonly string $rutReceptor,
        public readonly int|float $montoTotal,
    ) {
    }

    public function getId(): string
    {
        return sprintf('T%dF%d', $this->tipoDte, $this->folio);
    }

    public function getFechaEmision(): \DateTimeImmutable
    {
        return Clock::date($this->fechaEmision);
    }

    /** @return array{tipo: int, folio: int, fecha_emision: string, rut_receptor: string, monto_total: int|float} */
    public function toArray(): array
    {
        return [
            'tipo' => $this->tipoDte,
            'folio' => $this->folio,
            'fecha_emision' => $this->fechaEmision,
            'rut_receptor' => $this->rutReceptor,
            'monto_total' => $this->montoTotal,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['tipo'],
            (int) $data['folio'],
            (string) $data['fecha_emision'],
            (string) $data['rut_receptor'],
            \is_float($data['monto_total']) ? $data['monto_total'] : (int) $data['monto_total'],
        );
    }
}
