<?php

declare(strict_types=1);

/*
 * Configuración común de los ejemplos. Reemplace por sus datos reales:
 *  - certificado digital (.pfx/.p12) del usuario autorizado en el SII
 *  - CAF descargados desde https://maullin.sii.cl (certificación) o https://palena.sii.cl (producción)
 *  - fecha y número de resolución (en certificación el número es 0)
 */

require __DIR__.'/../vendor/autoload.php';


use RoyaltyFusion\Sii\Certificate\Certificate;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Model\Company;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\See;

// No hace falta cambiar la zona horaria global: el SDK genera fechas y timestamps en hora de Chile.
$see = new See(
    Certificate::fromFile(getenv('SII_CERT_PATH') ?: __DIR__.'/../tests/Fixtures/certificado.pfx', getenv('SII_CERT_PASSWORD') ?: 'prueba123'),
    Environment::CERTIFICACION,
    new Resolution('2026-10-01', 0),
);

foreach (glob(getenv('SII_CAF_DIR') ?: __DIR__.'/../tests/Fixtures/caf/*.xml') ?: [] as $caf) {
    $see->addCaf($caf);
}

$see->setCompany((new Company())
    ->setRut('76192083-9')
    ->setRazonSocial('EMPRESA DE PRUEBA SPA')
    ->setGiro('Desarrollo de software')
    ->setActecos([620200])
    ->setDireccion('Av. Providencia 1234')
    ->setComuna('Providencia')
    ->setCiudad('Santiago'));

return $see;
