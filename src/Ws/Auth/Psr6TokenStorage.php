<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws\Auth;

use Psr\Cache\CacheItemPoolInterface;

/** Almacena los tokens en un pool PSR-6 (ej. cache.app de Symfony, Redis), compartido entre procesos. */
final class Psr6TokenStorage implements TokenStorageInterface
{
    public function __construct(private readonly CacheItemPoolInterface $pool)
    {
    }

    public function get(string $key): ?string
    {
        $item = $this->pool->getItem($key);
        $value = $item->isHit() ? $item->get() : null;

        return \is_string($value) ? $value : null;
    }

    public function set(string $key, string $token, int $ttlSeconds): void
    {
        $item = $this->pool->getItem($key);
        $item->set($token);
        $item->expiresAfter($ttlSeconds);
        $this->pool->save($item);
    }

    public function delete(string $key): void
    {
        $this->pool->deleteItem($key);
    }
}
