<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Caf;

use RoyaltyFusion\Sii\Util\Rut;

/** Repositorio de CAF en memoria. */
final class InMemoryCafRepository implements CafRepositoryInterface, \Countable
{
    /** @var list<Caf> */
    private array $cafs = [];

    /** @param iterable<Caf> $cafs */
    public function __construct(iterable $cafs = [])
    {
        foreach ($cafs as $caf) {
            $this->add($caf);
        }
    }

    public function add(Caf $caf): self
    {
        $this->cafs[] = $caf;

        return $this;
    }

    public function addFile(string $path): self
    {
        return $this->add(Caf::fromFile($path));
    }

    /** Carga todos los archivos *.xml de un directorio que sean CAF válidos. */
    public function addDirectory(string $directory): self
    {
        foreach (glob(rtrim($directory, '/').'/*.xml') ?: [] as $file) {
            $this->addFile($file);
        }

        return $this;
    }

    public function find(string $rutEmisor, int $tipoDte, int $folio): ?Caf
    {
        $rut = Rut::isValid($rutEmisor) ? Rut::normalize($rutEmisor) : $rutEmisor;
        foreach ($this->cafs as $caf) {
            if ($caf->getTipoDte() === $tipoDte && $caf->getRutEmisor() === $rut && $caf->contains($folio)) {
                return $caf;
            }
        }

        return null;
    }

    /** @return list<Caf> */
    public function all(): array
    {
        return $this->cafs;
    }

    public function count(): int
    {
        return \count($this->cafs);
    }
}
