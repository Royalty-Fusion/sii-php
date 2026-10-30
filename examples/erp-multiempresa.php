<?php

declare(strict_types=1);

/*
 * ERP multiempresa: certificado, CAF y folios guardados en la base de datos, sin estado global
 * y sin cambiar la zona horaria del servidor (todo lo del SII se genera en hora de Chile).
 *
 * Este ejemplo usa SQLite en memoria; en producción use el PDO de MariaDB del ERP y las tablas
 * de resources/sql/mariadb.sql.
 */

require __DIR__.'/../vendor/autoload.php';

use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Model\Client;
use RoyaltyFusion\Sii\Model\Company;
use RoyaltyFusion\Sii\Model\Detail;
use RoyaltyFusion\Sii\Model\Dte;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\Caf\Caf;
use RoyaltyFusion\Sii\Pdo\PdoCafRepository;
use RoyaltyFusion\Sii\Pdo\PdoFolioStore;
use RoyaltyFusion\Sii\SeeConfig;
use RoyaltyFusion\Sii\SeeFactory;
use RoyaltyFusion\Sii\Util\Clock;

$pdo = new PDO('sqlite::memory:');
$pdo->exec((string) file_get_contents(__DIR__.'/../resources/sql/sqlite.sql'));
// Tabla propia del ERP con los datos de cada empresa. El .pfx y su clave deberían ir cifrados
// (por ejemplo con sodium_crypto_secretbox y una clave fuera de la base de datos).
$pdo->exec('CREATE TABLE empresas (tenant TEXT PRIMARY KEY, rut TEXT, razon_social TEXT, giro TEXT, acteco INTEGER,
    direccion TEXT, comuna TEXT, pfx BLOB, pfx_clave TEXT, ambiente TEXT, resolucion_fecha TEXT, resolucion_numero INTEGER)');

// --- Alta de una empresa (una vez) ---------------------------------------------------------------
$tenant = 'tenant42:casa-matriz';
$stmt = $pdo->prepare('INSERT INTO empresas VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$stmt->bindValue(1, $tenant);
$stmt->bindValue(2, '76192083-9');
$stmt->bindValue(3, 'EMPRESA DE PRUEBA SPA');
$stmt->bindValue(4, 'Desarrollo de software');
$stmt->bindValue(5, 620200);
$stmt->bindValue(6, 'Av. Providencia 1234');
$stmt->bindValue(7, 'Providencia');
$stmt->bindValue(8, (string) file_get_contents(__DIR__.'/../tests/Fixtures/certificado.pfx'), PDO::PARAM_LOB);
$stmt->bindValue(9, 'prueba123');
$stmt->bindValue(10, 'certificacion');
$stmt->bindValue(11, '2026-10-01');
$stmt->bindValue(12, 0);
$stmt->execute();

// CAF subidos por el usuario desde el ERP (contienen la clave privada de timbraje: no los registre en logs).
$cafs = new PdoCafRepository($pdo, $tenant);
foreach (glob(__DIR__.'/../tests/Fixtures/caf/caf_*.xml') ?: [] as $file) {
    $cafs->save(Caf::fromFile($file));
}

// --- En cada request / worker -------------------------------------------------------------------
$factory = new SeeFactory();   // compartida: cliente HTTP, caché de tokens y logger

$row = $pdo->query("SELECT * FROM empresas WHERE tenant = '{$tenant}'")->fetch(PDO::FETCH_ASSOC);
$config = SeeConfig::fromPkcs12($row['pfx'], $row['pfx_clave'])
    ->withEnvironment(Environment::fromString($row['ambiente']))
    ->withResolution(new Resolution($row['resolucion_fecha'], (int) $row['resolucion_numero']))
    ->withCafRepository(new PdoCafRepository($pdo, $tenant))
    ->withFolioStore(new PdoFolioStore($pdo, $tenant))        // folios propios de esta empresa/sucursal
    ->withCompany((new Company())
        ->setRut($row['rut'])
        ->setRazonSocial($row['razon_social'])
        ->setGiro($row['giro'])
        ->setActecos([(int) $row['acteco']])
        ->setDireccion($row['direccion'])
        ->setComuna($row['comuna']))
    ->withOficinaSii('Santiago Oriente');
$see = $factory->create($config);

// Sin folio ni fecha: el SDK asigna el siguiente folio (de forma atómica) y la fecha actual de Chile.
$factura = Dte::factura()
    ->setReceptor((new Client())->setRut('96.790.240-3')->setRazonSocial('Cliente S.A.')->setGiro('Comercio')->setDireccion('Calle 1')->setComuna('Ñuñoa'))
    ->addDetalle(Detail::create('Servicio mensual', 1, 100000));
$prepared = $see->prepare($factura);

printf("Folio %d, fecha %s (hora Chile %s), total %d\n",
    $prepared->document->getFolio(), $prepared->document->getFechaEmision()?->format('Y-m-d'),
    $prepared->timestamp->format('Y-m-d H:i:s'), $prepared->getMontoTotal());
printf("Folios disponibles para facturas: %d\n", $see->getFolioManager()->disponibles($row['rut'], 33));

// Datos para imprimir con TCPDF (sin dependencias extra):
$data = $see->getRepresentationData($prepared);
// $pdf->write2DBarcode($data->tedLatin1, $data->pdf417['tcpdf_type'], 15, 240, $data->pdf417['ancho_mm'], $data->pdf417['alto_mm']);
printf("Timbre: %d bytes, %s\n", strlen($data->tedLatin1), $data->leyendaTimbre);

// Envío y persistencia del estado para consultarlo desde un cron:
// $result = $see->send($prepared);
// $pdo->prepare('INSERT INTO envios_sii (tenant, estado) VALUES (?, ?)')->execute([$tenant, json_encode($result->toArray())]);
// ... en el cron:
// $estado = $see->resumeStatus(json_decode($fila['estado'], true));
echo 'Hora Chile: ', Clock::now()->format('c'), PHP_EOL;
