<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Certificacion;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Sii\Caf\InMemoryCafRepository;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Folio\FileFolioStore;
use RoyaltyFusion\Sii\Intercambio\EnvioDteReader;
use RoyaltyFusion\Sii\Model\Boleta;
use RoyaltyFusion\Sii\Model\Client;
use RoyaltyFusion\Sii\Model\Company;
use RoyaltyFusion\Sii\Model\Detail;
use RoyaltyFusion\Sii\Model\DocumentType;
use RoyaltyFusion\Sii\Model\Driver;
use RoyaltyFusion\Sii\Model\Dte;
use RoyaltyFusion\Sii\Model\Reference;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\Model\Transport;
use RoyaltyFusion\Sii\PreparedDocument;
use RoyaltyFusion\Sii\See;
use RoyaltyFusion\Sii\SeeConfig;
use RoyaltyFusion\Sii\SeeFactory;
use RoyaltyFusion\Sii\Util\Clock;
use RoyaltyFusion\Sii\Ws\Auth\BoletaTokenProvider;
use RoyaltyFusion\Sii\Ws\Auth\DteTokenProvider;
use RoyaltyFusion\Sii\Ws\Endpoints;
use RoyaltyFusion\Sii\Ws\HttpTransport;
use RoyaltyFusion\Sii\Ws\Result\EnvioStatus;
use RoyaltyFusion\Sii\Ws\Result\SendResult;
use RoyaltyFusion\Sii\Ws\SoapClient;

/**
 * Pruebas REALES contra el ambiente de certificación del SII (maullin.sii.cl, apicert.sii.cl, pangal.sii.cl).
 *
 * Sólo se ejecutan si están definidas las variables de entorno:
 *   SII_CERT_PFX, SII_CERT_PASSWORD, SII_RUT_EMISOR, SII_CAF_DIR, SII_RESOLUCION_FECHA
 * Opcionales:
 *   SII_RESOLUCION_NUMERO (0), SII_RUT_RECEPTOR (= emisor), SII_RAZON_SOCIAL, SII_GIRO, SII_ACTECO,
 *   SII_DIRECCION, SII_COMUNA, SII_FOLIO_STORE (archivo de folios usados; por defecto SII_CAF_DIR/.folios-certificacion.json),
 *   SII_INTERCAMBIO_XML (sobre EnvioDTE recibido de un tercero para probar la lectura),
 *   SII_ESPERA_SEGUNDOS (tiempo máximo de espera por estado final; 300),
 *   SII_RCV_PERIODO (AAAAMM) y SII_RCV_RUT para consultar el Registro de Compras y Ventas (producción, sólo lectura).
 *
 *   vendor/bin/phpunit --testsuite certificacion
 *
 * ATENCIÓN: consume folios reales de certificación. Los folios usados se registran en SII_FOLIO_STORE para no
 * repetirlos; si usó folios fuera de esta suite, ajuste ese archivo (clave "RUT|tipo" => último folio).
 */
#[Group('certificacion')]
final class CertificacionTest extends TestCase
{
    private static ?See $see = null;

    protected function setUp(): void
    {
        foreach (['SII_CERT_PFX', 'SII_CERT_PASSWORD', 'SII_RUT_EMISOR', 'SII_CAF_DIR', 'SII_RESOLUCION_FECHA'] as $var) {
            if (self::env($var) === null) {
                self::markTestSkipped(sprintf('Pruebas de certificación omitidas: falta la variable %s.', $var));
            }
        }
    }

    public function testTokenDte(): void
    {
        $env = Environment::CERTIFICACION;
        $provider = new DteTokenProvider(self::see()->getCertificate(), $env, new SoapClient(new HttpTransport()), Endpoints::for($env));
        $token = $provider->getToken(true);
        self::assertMatchesRegularExpression('/^[A-Z0-9]{8,}$/i', $token);
    }

    public function testTokenBoletas(): void
    {
        $env = Environment::CERTIFICACION;
        $provider = new BoletaTokenProvider(self::see()->getCertificate(), $env, new HttpTransport(), Endpoints::for($env));
        self::assertNotSame('', $provider->getToken(true));
    }

    /**
     * Factura 33 (dos) y guía 52 en un mismo sobre.
     *
     * @return array{0: PreparedDocument, 1: PreparedDocument}
     */
    public function testFacturaYGuia(): array
    {
        $see = self::see();
        self::requireCaf(33, 52);
        $facturaA = $see->prepare(self::factura('Servicio de prueba A', 3, 15990));
        $facturaB = $see->prepare(self::factura('Servicio de prueba B', 1, 49990));
        $guia = $see->prepare(self::guia());

        $result = $see->sendBatch([$facturaA, $facturaB, $guia]);
        self::assertTrue($result->isSuccess(), 'Upload rechazado: '.$result->message.' '.implode(' | ', $result->errors));
        self::assertNotNull($result->trackId);
        self::assertCount(3, $result->documents);

        $status = self::waitFinal($result);
        self::assertTrue($status->isAccepted() || $status->isAcceptedWithObjections(), self::describe($status));

        $doc = self::waitDocument($facturaA);
        self::assertTrue($doc->isAccepted(), sprintf('QueryEstDte: %s %s %s', $doc->estado, $doc->getDescripcion(), $doc->glosaError));

        return [$facturaA, $facturaB];
    }

    /**
     * Nota de crédito 61 con CodRef 3 (corrige montos) y CodRef 1 (anula).
     *
     * @param array{0: PreparedDocument, 1: PreparedDocument} $facturas
     */
    #[Depends('testFacturaYGuia')]
    public function testNotasDeCredito(array $facturas): void
    {
        $see = self::see();
        self::requireCaf(61);
        [$a, $b] = $facturas;
        $corrige = Dte::notaCredito()
            ->setFechaEmision(Clock::today())
            ->setReceptor(self::receptor())
            ->addDetalle(Detail::create('Devolución parcial servicio A', 1, 15990))
            ->addReferencia(Reference::create(DocumentType::FACTURA, (int) $a->document->getFolio(), $a->document->getFechaEmision() ?? Clock::today(), Reference::CORRIGE_MONTOS, 'Devolución parcial'));
        $anula = Dte::notaCredito()
            ->setFechaEmision(Clock::today())
            ->setReceptor(self::receptor())
            ->setDetalles($b->document->getDetalles())
            ->addReferencia(Reference::create(DocumentType::FACTURA, (int) $b->document->getFolio(), $b->document->getFechaEmision() ?? Clock::today(), Reference::ANULA, 'Anula factura'));

        $result = $see->sendBatch([$see->prepare($corrige), $see->prepare($anula)]);
        self::assertTrue($result->isSuccess(), $result->message);
        $status = self::waitFinal($result);
        self::assertTrue($status->isAccepted() || $status->isAcceptedWithObjections(), self::describe($status));

        // Reconciliación por tipo, folio, RUT, monto y fecha desde el estado persistido.
        $estados = $see->reconcile(json_decode((string) json_encode($result->toArray()), true));
        self::assertCount(2, $estados);
        foreach ($estados as $id => $estado) {
            self::assertTrue($estado->isReceived() || $estado->isPending(), $id.': '.$estado->estado.' '.$estado->getDescripcion());
        }
    }

    public function testBoleta(): void
    {
        $see = self::see();
        self::requireCaf(39);
        $boleta = $see->prepare(Boleta::afecta()
            ->setFechaEmision(Clock::today())
            ->addDetalle(Detail::create('Producto boleta de prueba', 2, 1990))
            ->addDetalle(Detail::create('Servicio exento de prueba', 1, 1000)->setExento()));

        $result = $see->send($boleta);
        self::assertTrue($result->isSuccess(), $result->message.' '.$result->rawResponse);
        self::assertTrue($result->boleta);

        $status = self::waitFinal($result);
        self::assertTrue($status->isAccepted() || $status->isAcceptedWithObjections(), self::describe($status));

        $doc = $see->getDocumentStatus($boleta);
        self::assertFalse($doc->isRejected(), (string) $doc->rawResponse);
    }

    public function testLecturaDeSobreDeIntercambio(): void
    {
        $see = self::see();
        $path = self::env('SII_INTERCAMBIO_XML');
        if ($path !== null) {
            $xml = (string) file_get_contents($path);
        } else {
            // Sin sobre de terceros: se lee un sobre propio dirigido al receptor (no se envía al SII).
            self::requireCaf(33);
            $xml = $see->getEnvelopeXml([self::factura('Intercambio de prueba', 1, 1000)], self::rutReceptor());
        }
        $envelope = (new EnvioDteReader())->read($xml);
        self::assertTrue($envelope->schemaValido, implode("\n", $envelope->erroresSchema));
        self::assertTrue($envelope->firmaValida, 'Firma del sobre inválida');
        self::assertNotEmpty($envelope->documentos);
        foreach ($envelope->documentos as $dte) {
            self::assertTrue($dte->firmaValida, $dte->getId().': firma inválida');
            self::assertTrue($dte->timbreValido, $dte->getId().': timbre inválido');
        }
    }

    /**
     * Registro de Compras y Ventas (sólo lectura, datos reales). Requiere SII_RCV_PERIODO (AAAAMM) y,
     * opcionalmente, SII_RCV_RUT (por defecto SII_RUT_EMISOR).
     */
    public function testRegistroDeComprasYVentas(): void
    {
        $periodo = self::env('SII_RCV_PERIODO');
        if ($periodo === null) {
            self::markTestSkipped('Defina SII_RCV_PERIODO (AAAAMM) para consultar el RCV.');
        }
        $rut = self::env('SII_RCV_RUT') ?? (string) self::env('SII_RUT_EMISOR');
        $rcv = self::see()->enableRcvService()->getRcvService();

        $resumen = $rcv->resumen($rut, $periodo);
        $compras = $rcv->compras($rut, $periodo);
        $esperadas = array_sum(array_map(static fn ($r) => $r->cantidad, $resumen));
        self::assertCount($esperadas, $compras, 'El detalle debe coincidir con el resumen del RCV');
        foreach ($compras as $c) {
            self::assertGreaterThan(0, $c->folio);
            self::assertNotSame('', $c->rutContraparte);
        }
        self::assertIsArray($rcv->ventas($rut, $periodo));
    }

    private static function see(): See
    {
        if (self::$see !== null) {
            return self::$see;
        }
        $cafs = (new InMemoryCafRepository())->addDirectory((string) self::env('SII_CAF_DIR'));
        $config = SeeConfig::fromPkcs12((string) file_get_contents((string) self::env('SII_CERT_PFX')), (string) self::env('SII_CERT_PASSWORD'))
            ->withEnvironment(Environment::CERTIFICACION)
            ->withResolution(new Resolution((string) self::env('SII_RESOLUCION_FECHA'), (int) (self::env('SII_RESOLUCION_NUMERO') ?? 0)))
            ->withCafRepository($cafs)
            ->withFolioStore(new FileFolioStore(self::env('SII_FOLIO_STORE') ?? rtrim((string) self::env('SII_CAF_DIR'), '/').'/.folios-certificacion.json'))
            ->withCompany((new Company())
                ->setRut((string) self::env('SII_RUT_EMISOR'))
                ->setRazonSocial(self::env('SII_RAZON_SOCIAL') ?? 'EMPRESA EN CERTIFICACION')
                ->setGiro(self::env('SII_GIRO') ?? 'Servicios informaticos')
                ->setActecos([(int) (self::env('SII_ACTECO') ?? 620200)])
                ->setDireccion(self::env('SII_DIRECCION') ?? 'Direccion de prueba 123')
                ->setComuna(self::env('SII_COMUNA') ?? 'Santiago')
                ->setCiudad('Santiago'));

        return self::$see = (new SeeFactory(Environment::CERTIFICACION))->create($config);
    }

    private static function factura(string $item, int $cantidad, int $precio): Dte
    {
        return Dte::factura()
            ->setFechaEmision(Clock::today())
            ->setFormaPago(1)
            ->setReceptor(self::receptor())
            ->addDetalle(Detail::create($item, $cantidad, $precio, 'UN'));
    }

    private static function guia(): Dte
    {
        return Dte::guiaDespacho(5)
            ->setFechaEmision(Clock::today())
            ->setReceptor(self::receptor())
            ->setTransporte((new Transport())
                ->setPatente('ABCD12')
                ->setChofer(new Driver('11111111-1', 'Chofer de prueba'))
                ->setDireccionDestino('Bodega de prueba 100')
                ->setComunaDestino('Santiago'))
            ->addDetalle(Detail::create('Traslado interno de prueba', 2, 0, 'UN'));
    }

    private static function receptor(): Client
    {
        return (new Client())
            ->setRut(self::rutReceptor())
            ->setRazonSocial('RECEPTOR DE PRUEBA')
            ->setGiro('Comercio')
            ->setDireccion('Calle de prueba 456')
            ->setComuna('Santiago');
    }

    private static function rutReceptor(): string
    {
        return self::env('SII_RUT_RECEPTOR') ?? (string) self::env('SII_RUT_EMISOR');
    }

    private static function requireCaf(int ...$tipos): void
    {
        $repo = self::see()->getCafRepository();
        foreach ($tipos as $tipo) {
            if ($repo->findAll((string) self::env('SII_RUT_EMISOR'), $tipo) === []) {
                self::markTestSkipped(sprintf('No hay CAF del tipo %d en SII_CAF_DIR.', $tipo));
            }
        }
    }

    private static function waitFinal(SendResult $result): EnvioStatus
    {
        $deadline = time() + (int) (self::env('SII_ESPERA_SEGUNDOS') ?? 300);
        do {
            sleep(10);
            $status = self::see()->resumeStatus(json_decode((string) json_encode($result->toArray()), true));
        } while (!$status->isFinal() && time() < $deadline);
        self::assertTrue($status->isFinal(), 'El SII no terminó de procesar el envío: '.self::describe($status));

        return $status;
    }

    private static function waitDocument(PreparedDocument $prepared): \RoyaltyFusion\Sii\Ws\Result\DteStatus
    {
        $deadline = time() + (int) (self::env('SII_ESPERA_SEGUNDOS') ?? 300);
        do {
            $status = self::see()->getDocumentStatus($prepared);
            if (!$status->isPending()) {
                return $status;
            }
            sleep(10);
        } while (time() < $deadline);

        return $status;
    }

    private static function describe(EnvioStatus $status): string
    {
        return sprintf('TrackID %s: %s (%s) informados=%d aceptados=%d rechazados=%d reparos=%d %s',
            $status->trackId, $status->estado, $status->getDescripcion(), $status->getTotal('informados'),
            $status->getTotal('aceptados'), $status->getTotal('rechazados'), $status->getTotal('reparos'),
            json_encode(array_map(static fn ($d) => [$d->tipoDte, $d->folio, $d->estado, $d->descripcion, $d->errores], $status->documentos), \JSON_UNESCAPED_UNICODE));
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? null : $value;
    }

}
