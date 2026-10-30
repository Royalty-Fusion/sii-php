<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws\Auth;

interface TokenProviderInterface
{
    /** Retorna un token vigente (desde el almacén o solicitando uno nuevo al SII). */
    public function getToken(bool $forceRefresh = false): string;

    /** Descarta el token almacenado (por ejemplo, si el SII lo rechazó). */
    public function invalidate(): void;
}
