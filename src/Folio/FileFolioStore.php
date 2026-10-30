<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Folio;

use RoyaltyFusion\Sii\Exception\InvalidArgumentException;

/**
 * Registro de folios en un archivo JSON con bloqueo exclusivo (flock), seguro entre procesos
 * del mismo servidor. Para varios servidores use una implementación sobre la base de datos.
 */
final class FileFolioStore implements FolioStoreInterface
{
    public function __construct(private readonly string $path)
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new InvalidArgumentException(sprintf('No se pudo crear el directorio "%s".', $dir));
        }
    }

    public function reserve(string $rutEmisor, int $tipoDte, int $desde, int $hasta): ?int
    {
        return $this->locked(static function (array &$data) use ($rutEmisor, $tipoDte, $desde, $hasta): ?int {
            $key = $rutEmisor.'|'.$tipoDte;
            $next = max($desde, ((int) ($data[$key] ?? 0)) + 1);
            if ($next > $hasta) {
                return null;
            }
            $data[$key] = $next;

            return $next;
        });
    }

    public function getLast(string $rutEmisor, int $tipoDte): ?int
    {
        return $this->locked(static fn (array &$data): ?int => isset($data[$rutEmisor.'|'.$tipoDte]) ? (int) $data[$rutEmisor.'|'.$tipoDte] : null);
    }

    public function release(string $rutEmisor, int $tipoDte, int $folio): bool
    {
        return $this->locked(static function (array &$data) use ($rutEmisor, $tipoDte, $folio): bool {
            $key = $rutEmisor.'|'.$tipoDte;
            if ((int) ($data[$key] ?? 0) !== $folio) {
                return false;
            }
            $data[$key] = $folio - 1;

            return true;
        });
    }

    public function setLast(string $rutEmisor, int $tipoDte, int $folio): void
    {
        $this->locked(static function (array &$data) use ($rutEmisor, $tipoDte, $folio): null {
            $data[$rutEmisor.'|'.$tipoDte] = $folio;

            return null;
        });
    }

    /**
     * @template T
     *
     * @param callable(array<string, int>): T $callback
     *
     * @return T
     */
    private function locked(callable $callback): mixed
    {
        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new InvalidArgumentException(sprintf('No se pudo abrir el registro de folios "%s".', $this->path));
        }
        try {
            if (!flock($handle, \LOCK_EX)) {
                throw new InvalidArgumentException('No se pudo bloquear el registro de folios.');
            }
            $content = stream_get_contents($handle);
            $data = $content !== false && $content !== '' ? json_decode($content, true) : [];
            $data = \is_array($data) ? $data : [];
            $before = $data;
            $result = $callback($data);
            if ($data !== $before) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, (string) json_encode($data, \JSON_PRETTY_PRINT));
                fflush($handle);
            }

            return $result;
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }
}
