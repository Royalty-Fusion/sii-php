<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Rcv;

/** Totales del RCV por tipo de documento para un período. */
final class RcvResumen
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly int $tipoDte,
        public readonly ?string $nombre,
        public readonly int $cantidad,
        public readonly int $montoExento,
        public readonly int $montoNeto,
        public readonly int $montoIva,
        public readonly int $montoTotal,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromSii(array $row): self
    {
        $v = static fn (string $k): int => (int) round((float) ($row[$k] ?? 0));

        return new self(
            $v('rsmnTipoDocInteger') ?: $v('rsmnTipoDoc'),
            isset($row['dcvNombreTipoDoc']) ? (string) $row['dcvNombreTipoDoc'] : null,
            $v('rsmnTotDoc'),
            $v('rsmnMntExe'),
            $v('rsmnMntNeto'),
            $v('rsmnMntIVA'),
            $v('rsmnMntTotal'),
            $row,
        );
    }
}
