<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Rcv;

use RoyaltyFusion\Sii\Util\Clock;
use RoyaltyFusion\Sii\Util\Rut;

/**
 * Documento del Registro de Compras y Ventas (RCV) del SII.
 *
 * En COMPRAS la contraparte es el proveedor (emisor del documento); en VENTAS, el cliente.
 */
final class RcvDocument
{
    /** @param array<string, mixed> $raw fila original entregada por el SII */
    public function __construct(
        public readonly string $operacion,
        public readonly string $estado,
        public readonly int $tipoDte,
        public readonly int $folio,
        /** RUT de la contraparte (proveedor en compras, cliente en ventas). */
        public readonly string $rutContraparte,
        public readonly ?string $razonSocial,
        public readonly ?\DateTimeImmutable $fechaEmision,
        public readonly ?string $fechaRecepcion,
        public readonly int $montoExento,
        public readonly int $montoNeto,
        public readonly int $montoIva,
        public readonly int $ivaNoRecuperable,
        public readonly int $montoTotal,
        /** Evento del receptor (aceptado, reclamado, etc.), si el SII lo informa. */
        public readonly ?string $eventoReceptor,
        public readonly array $raw = [],
    ) {
    }

    /** Clave única "RUT|tipo|folio" para cruzar con el ERP. */
    public function getKey(): string
    {
        return self::key($this->rutContraparte, $this->tipoDte, $this->folio);
    }

    public static function key(string $rut, int $tipo, int $folio): string
    {
        return (Rut::isValid($rut) ? Rut::normalize($rut) : strtoupper($rut)).'|'.$tipo.'|'.$folio;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromSii(array $row, string $operacion, string $estado): self
    {
        $int = static fn (string ...$keys): int => (int) round((float) (self::pick($row, ...$keys) ?? 0));
        $rut = (string) (self::pick($row, 'detRutDoc', 'rutDoc', 'rut') ?? '');
        $dv = (string) (self::pick($row, 'detDvDoc', 'dvDoc', 'dv') ?? '');
        $rutCompleto = $dv !== '' && !str_contains($rut, '-') ? $rut.'-'.$dv : $rut;
        $evento = self::pick($row, 'detEventoReceptorLeyenda', 'detEventoReceptor');

        return new self(
            $operacion,
            $estado,
            $int('detTipoDoc', 'tipoDoc', 'codTipoDoc'),
            $int('detNroDoc', 'folio', 'nroDoc'),
            Rut::isValid($rutCompleto) ? Rut::normalize($rutCompleto) : $rutCompleto,
            ($rs = self::pick($row, 'detRznSoc', 'razonSocial')) === null ? null : trim((string) $rs),
            self::fecha(self::pick($row, 'detFchDoc', 'fechaDoc')),
            ($fr = self::pick($row, 'detFecRecepcion', 'fechaRecepcion')) === null ? null : (string) $fr,
            $int('detMntExe', 'montoExento'),
            $int('detMntNeto', 'montoNeto'),
            $int('detMntIVA', 'montoIva'),
            $int('detMntIVANoRec', 'ivaNoRecuperable'),
            $int('detMntTotal', 'montoTotal'),
            $evento === null || $evento === '' ? null : (string) $evento,
            $row,
        );
    }

    /** @param array<string, mixed> $row */
    private static function pick(array $row, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (\array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return null;
    }

    private static function fecha(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }
        foreach (['!d/m/Y', '!Y-m-d', '!d-m-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, substr($value, 0, 10), Clock::timezone());
            if ($date !== false) {
                return $date;
            }
        }

        return null;
    }
}
