<?php

declare(strict_types=1);

use RoyaltyFusion\Sii\Model\Boleta;
use RoyaltyFusion\Sii\Model\Detail;
use RoyaltyFusion\Sii\Report\HtmlReport;
use RoyaltyFusion\Sii\See;

/** @var See $see */
$see = require __DIR__.'/bootstrap.php';

// En boletas los precios incluyen IVA; el SDK desglosa neto e IVA.
$boleta = Boleta::afecta()
    ->setFolio(1)
    ->setFechaEmision(\RoyaltyFusion\Sii\Util\Clock::today())
    ->addDetalle(Detail::create('Café americano', 2, 1990))
    ->addDetalle(Detail::create('Croissant', 1, 2500));

$prepared = $see->prepare($boleta);
file_put_contents(__DIR__.'/boleta-1.html', (new HtmlReport())->render($prepared));
echo 'Neto: ', $prepared->calculation->totales->montoNeto, ' IVA: ', $prepared->calculation->totales->iva, ' Total: ', $prepared->getMontoTotal(), PHP_EOL;

// Envío por la API REST de boletas (pangal/rahue):
// $result = $see->send($prepared);
// $estado = $see->getStatus($result->trackId, null, true);
