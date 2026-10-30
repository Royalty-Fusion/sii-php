<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws\Auth;

/** Almacén de tokens del SII (evita pedir un token nuevo en cada operación). */
interface TokenStorageInterface
{
    public function get(string $key): ?string;

    public function set(string $key, string $token, int $ttlSeconds): void;

    public function delete(string $key): void;
}
