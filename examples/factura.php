<?php

declare(strict_types=1);

use RoyaltyFusion\Sii\Exception\ValidationException;
use RoyaltyFusion\Sii\Model\Client;
use RoyaltyFusion\Sii\Model\Detail;
use RoyaltyFusion\Sii\Model\Dte;
use RoyaltyFusion\Sii\Model\GlobalDiscount;
use RoyaltyFusion\Sii\Model\Reference;
use RoyaltyFusion\Sii\See;

/** @var See $see */
$see = require __DIR__.'/bootstrap.php';

$cliente = (new Client())
    ->setRut('96.790.240-3')
    ->setRazonSocial('Comercial Cliente Ltda.')
    ->setGiro('Comercio')
    ->setDireccion('Calle Falsa 123')
    ->setComuna('Ñuñoa');

$factura = Dte::factura()
    ->setFolio(1)
    ->setFechaEmision(new DateTimeImmutable())
    ->setFormaPago(1)
    ->setReceptor($cliente)
    ->addDetalle(Detail::create('Notebook', 2, 450000, 'UN')->setDescuentoPorcentaje(5))
    ->addDetalle(Detail::create('Instalación', 1, 35000)->setExento())
    ->addDescuentoGlobal(GlobalDiscount::descuentoPorcentaje(2.5, 'Descuento por volumen'))
    ->addReferencia(Reference::create('801', 'OC-4512', new DateTimeImmutable('-3 days'), null, 'Orden de compra'))
    ->setMontoTotalEsperado(null); // opcional: total calculado por el ERP para verificar

// 1) Validar antes de emitir (no lanza excepción)
$validacion = $see->validate($factura);
foreach ($validacion as $v) {
    echo $v, PHP_EOL;
}

try {
    // 2) XML firmado (para guardar / enviar al cliente) y totales calculados
    $prepared = $see->prepare($factura);
    file_put_contents(__DIR__.'/factura-1.xml', $prepared->xml);
    echo 'Total: ', $prepared->getMontoTotal(), PHP_EOL;

    // 3) Envío al SII (descomente para enviar realmente al ambiente de certificación)
    // $result = $see->send($prepared);
    // echo $result->isSuccess() ? 'Track ID: '.$result->trackId : 'Error: '.$result->message, PHP_EOL;
    // $estado = $see->getStatus($result->trackId);
    // echo $estado->estado, ' ', $estado->getDescripcion(), PHP_EOL;
} catch (ValidationException $e) {
    echo $e->getMessage(), PHP_EOL;
    foreach ($e->getResult()->getErrors() as $error) {
        echo ' - ', $error->path, ': ', $error->message, PHP_EOL;
    }
}
