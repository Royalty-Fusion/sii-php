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
        return $this->locked(static function (array $data) use ($rutEmisor, $tipoDte, $desde, $hasta): array {
            $key = $rutEmisor.'|'.$tipoDte;
            $next = max($desde, ($data[$key] ?? 0) + 1);
            if ($next > $hasta) {
                return [null, $data];
            }
            $data[$key] = $next;

            return [$next, $data];
        });
    }

    public function getLast(string $rutEmisor, int $tipoDte): ?int
    {
        return $this->locked(static fn (array $data): array => [$data[$rutEmisor.'|'.$tipoDte] ?? null, $data]);
    }

    public function release(string $rutEmisor, int $tipoDte, int $folio): bool
    {
        return $this->locked(static function (array $data) use ($rutEmisor, $tipoDte, $folio): array {
            $key = $rutEmisor.'|'.$tipoDte;
            if (($data[$key] ?? 0) !== $folio) {
                return [false, $data];
            }
            $data[$key] = $folio - 1;

            return [true, $data];
        });
    }

    public function setLast(string $rutEmisor, int $tipoDte, int $folio): void
    {
        $this->locked(static function (array $data) use ($rutEmisor, $tipoDte, $folio): array {
            $data[$rutEmisor.'|'.$tipoDte] = $folio;

            return [null, $data];
        });
    }

    /**
     * Ejecuta $callback con el contenido bloqueado; el callback retorna [resultado, datos nuevos].
     *
     * @template T
     *
     * @param callable(array<string, int>): array{0: T, 1: array<string, int>} $callback
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
            $decoded = $content !== false && $content !== '' ? json_decode($content, true) : [];
            $data = [];
            foreach (\is_array($decoded) ? $decoded : [] as $key => $value) {
                $data[(string) $key] = (int) $value;
            }
            [$result, $new] = $callback($data);
            if ($new !== $data) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, (string) json_encode($new, \JSON_PRETTY_PRINT));
                fflush($handle);
            }

            return $result;
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }
}
