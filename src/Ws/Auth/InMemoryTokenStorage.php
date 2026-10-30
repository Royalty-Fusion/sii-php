<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws\Auth;

final class InMemoryTokenStorage implements TokenStorageInterface
{
    /** @var array<string, array{0: string, 1: int}> */
    private array $tokens = [];

    public function get(string $key): ?string
    {
        if (!isset($this->tokens[$key])) {
            return null;
        }
        [$token, $expires] = $this->tokens[$key];
        if ($expires <= time()) {
            unset($this->tokens[$key]);

            return null;
        }

        return $token;
    }

    public function set(string $key, string $token, int $ttlSeconds): void
    {
        $this->tokens[$key] = [$token, time() + $ttlSeconds];
    }

    public function delete(string $key): void
    {
        unset($this->tokens[$key]);
    }
}
