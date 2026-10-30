<?php

declare(strict_types=1);

use RoyaltyFusion\Sii\Intercambio\EnvioDteReader;
use RoyaltyFusion\Sii\Intercambio\ResponseBuilder;
use RoyaltyFusion\Sii\See;
use RoyaltyFusion\Sii\Ws\RegistroReclamoService;

/** @var See $see */
$see = require __DIR__.'/bootstrap.php';

// XML recibido por correo desde un proveedor
$xml = file_get_contents($argv[1] ?? 'php://stdin');
$envio = (new EnvioDteReader())->read((string) $xml);
$miRut = '96790240-3';

$builder = new ResponseBuilder($see->getCertificate(), nombreContacto: 'Contabilidad', mailContacto: 'dte@miempresa.cl');

// 1) Acuse de recibo técnico del sobre
file_put_contents('respuesta-recepcion.xml', $builder->recepcionEnvio($envio, 'EnvioDTE.xml', $miRut, 1));

// 2) Aprobación comercial
$resultados = array_map(static fn ($dte) => ['dte' => $dte, 'estado' => ResponseBuilder::ACEPTADO], $envio->documentos);
file_put_contents('respuesta-resultado.xml', $builder->resultadoDte($resultados, $miRut, 2));

// 3) Recibo de mercaderías (Ley 19.983)
file_put_contents('envio-recibos.xml', $builder->envioRecibos($envio->documentos, $miRut, 'Bodega central'));

// 4) Registro de aceptación en el SII (Ley 20.956)
// foreach ($envio->documentos as $dte) {
//     $see->getRegistroReclamo()->registrar($dte->rutEmisor, $dte->tipoDte, $dte->folio, RegistroReclamoService::ACEPTA_CONTENIDO);
// }
