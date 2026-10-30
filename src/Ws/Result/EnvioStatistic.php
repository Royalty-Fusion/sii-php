<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws\Result;

final class EnvioStatistic
{
    public function __construct(
        public readonly int $tipoDte,
        public readonly int $informados,
        public readonly int $aceptados,
        public readonly int $rechazados,
        public readonly int $reparos,
    ) {
    }
}
