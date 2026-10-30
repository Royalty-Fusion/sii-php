<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Folio;

/** Registro de folios en memoria (sólo para pruebas o procesos únicos). */
final class InMemoryFolioStore implements FolioStoreInterface
{
    /** @var array<string, int> */
    private array $last = [];

    public function reserve(string $rutEmisor, int $tipoDte, int $desde, int $hasta): ?int
    {
        $key = $rutEmisor.'|'.$tipoDte;
        $next = max($desde, ($this->last[$key] ?? 0) + 1);
        if ($next > $hasta) {
            return null;
        }

        return $this->last[$key] = $next;
    }

    public function getLast(string $rutEmisor, int $tipoDte): ?int
    {
        return $this->last[$rutEmisor.'|'.$tipoDte] ?? null;
    }

    public function release(string $rutEmisor, int $tipoDte, int $folio): bool
    {
        $key = $rutEmisor.'|'.$tipoDte;
        if (($this->last[$key] ?? null) !== $folio) {
            return false;
        }
        $this->last[$key] = $folio - 1;

        return true;
    }

    public function setLast(string $rutEmisor, int $tipoDte, int $folio): void
    {
        $this->last[$rutEmisor.'|'.$tipoDte] = $folio;
    }
}
