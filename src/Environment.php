<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii;

/**
 * Ambientes del SII.
 *
 * - CERTIFICACION: maullin.sii.cl (DTE) / apicert.sii.cl + pangal.sii.cl (boletas)
 * - PRODUCCION:    palena.sii.cl  (DTE) / api.sii.cl + rahue.sii.cl (boletas)
 */
enum Environment: string
{
    case CERTIFICACION = 'certificacion';
    case PRODUCCION = 'produccion';

    public static function fromString(string $value): self
    {
        return match (strtolower(trim($value))) {
            'certificacion', 'certificación', 'cert', 'test', 'maullin', 'dev' => self::CERTIFICACION,
            'produccion', 'producción', 'prod', 'palena', 'production' => self::PRODUCCION,
            default => throw new Exception\InvalidArgumentException(sprintf('Ambiente "%s" desconocido (use "certificacion" o "produccion").', $value)),
        };
    }

    public function isProduccion(): bool
    {
        return $this === self::PRODUCCION;
    }

    /** Servidor de los web services SOAP y de upload de DTE. */
    public function dteHost(): string
    {
        return $this === self::PRODUCCION ? 'palena.sii.cl' : 'maullin.sii.cl';
    }

    /** Servidor de la API REST de boletas (semilla, token y consultas). */
    public function boletaApiHost(): string
    {
        return $this === self::PRODUCCION ? 'api.sii.cl' : 'apicert.sii.cl';
    }

    /** Servidor de recepción de envíos de boletas. */
    public function boletaUploadHost(): string
    {
        return $this === self::PRODUCCION ? 'rahue.sii.cl' : 'pangal.sii.cl';
    }

    /** Web service de registro de aceptación/reclamo de DTE (Ley 20.956). */
    public function registroReclamoUrl(): string
    {
        return $this === self::PRODUCCION
            ? 'https://ws1.sii.cl/WSREGISTRORECLAMODTE/registroreclamodteservice'
            : 'https://ws2.sii.cl/WSREGISTRORECLAMODTECERT/registroreclamodteservice';
    }
}
