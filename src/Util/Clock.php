<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Util;

/**
 * Hora oficial de Chile para todo lo que el SDK genera (TmstFirma, TSTED, TmstFirmaEnv, vencimiento
 * de CAF, fecha de emisión por defecto), independiente de date_default_timezone_get().
 *
 * Un reloj inyectado puede devolver la hora en cualquier zona: se convierte siempre a America/Santiago.
 */
final class Clock
{
    public const TIMEZONE = 'America/Santiago';

    public static function timezone(): \DateTimeZone
    {
        static $tz = null;

        return $tz ??= new \DateTimeZone(self::TIMEZONE);
    }

    /** Hora actual en Chile. */
    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::timezone());
    }

    /** Convierte un instante a la hora de Chile (mismo instante, otra zona). */
    public static function toChile(\DateTimeInterface $instant): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($instant)->setTimezone(self::timezone());
    }

    /** Fecha calendario de Chile (a las 00:00 en America/Santiago). */
    public static function today(?\DateTimeInterface $instant = null): \DateTimeImmutable
    {
        return ($instant === null ? self::now() : self::toChile($instant))->setTime(0, 0);
    }

    /**
     * Normaliza un reloj inyectable: el resultado siempre está en hora de Chile.
     *
     * @param (\Closure(): \DateTimeInterface)|null $clock
     *
     * @return \Closure(): \DateTimeImmutable
     */
    public static function wrap(?\Closure $clock): \Closure
    {
        if ($clock === null) {
            return static fn (): \DateTimeImmutable => self::now();
        }

        return static fn (): \DateTimeImmutable => self::toChile($clock());
    }

    /**
     * Interpreta una fecha calendario "AAAA-MM-DD" en Chile.
     */
    public static function date(string $ymd): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, self::timezone());
        if ($date === false) {
            throw new \RoyaltyFusion\Sii\Exception\InvalidArgumentException(sprintf('Fecha "%s" inválida (formato AAAA-MM-DD).', $ymd));
        }

        return $date;
    }
}
