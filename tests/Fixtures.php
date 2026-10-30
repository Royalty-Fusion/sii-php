<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests;

use RoyaltyFusion\Sii\Caf\Caf;
use RoyaltyFusion\Sii\Caf\InMemoryCafRepository;
use RoyaltyFusion\Sii\Certificate\Certificate;
use RoyaltyFusion\Sii\Model\Client;
use RoyaltyFusion\Sii\Model\Company;

/** Datos de prueba: certificado y CAF ficticios (firmados por una clave "SII" falsa). */
final class Fixtures
{
    public const DIR = __DIR__.'/Fixtures';
    public const PASSWORD = 'prueba123';
    public const RUT_EMISOR = '76192083-9';
    public const RUT_FIRMANTE = '11111111-1';

    public static function certificate(): Certificate
    {
        static $cert = null;

        return $cert ??= Certificate::fromFile(self::DIR.'/certificado.pfx', self::PASSWORD);
    }

    public static function caf(int $tipo): Caf
    {
        return Caf::fromFile(self::DIR.'/caf/caf_'.$tipo.'.xml');
    }

    public static function cafs(): InMemoryCafRepository
    {
        return (new InMemoryCafRepository())->addDirectory(self::DIR.'/caf');
    }

    public static function emisor(): Company
    {
        return (new Company())
            ->setRut(self::RUT_EMISOR)
            ->setRazonSocial('EMPRESA DE PRUEBA SPA')
            ->setGiro('Desarrollo de software y venta de equipos')
            ->setActecos([620200, 465100])
            ->setTelefonos(['+56 2 2345 6789'])
            ->setEmail('facturacion@empresa.cl')
            ->setDireccion('Av. Providencia 1234, of. 56')
            ->setComuna('Providencia')
            ->setCiudad('Santiago');
    }

    public static function receptor(): Client
    {
        return (new Client())
            ->setRut('96.790.240-3')
            ->setRazonSocial("Comercial O'Higgins & Cía. Ltda.")
            ->setGiro('Comercio al por mayor')
            ->setDireccion('Calle Ñuble 45')
            ->setComuna('Ñuñoa')
            ->setCiudad('Santiago')
            ->setEmail('dte@cliente.cl');
    }

    public static function fecha(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-02', new \DateTimeZone('America/Santiago'));
    }
}
