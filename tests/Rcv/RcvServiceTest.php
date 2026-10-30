<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Rcv;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Exception\InvalidArgumentException;
use RoyaltyFusion\Sii\Exception\WebServiceException;
use RoyaltyFusion\Sii\Rcv\RcvConciliacion;
use RoyaltyFusion\Sii\Rcv\RcvService;
use RoyaltyFusion\Sii\See;
use RoyaltyFusion\Sii\Tests\Fixtures;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Simula los servicios JSON del RCV (estructura respEstado/data del sitio del SII). */
final class RcvServiceTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @param list<MockResponse> $responses */
    private function service(array $responses): RcvService
    {
        $this->requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? self::fail('Solicitud inesperada '.$url);
        });

        return new RcvService(Fixtures::certificate(), $client);
    }

    private static function login(): MockResponse
    {
        return new MockResponse('', ['http_code' => 302, 'response_headers' => [
            'Location: https://www4.sii.cl/consdcvinternetui/',
            'Set-Cookie: TOKEN=RCVTOKEN1; path=/; domain=.sii.cl',
        ]]);
    }

    /** @param array<mixed> $data */
    private static function json(array $data, int $codigo = 0, ?string $mensaje = null): MockResponse
    {
        return new MockResponse((string) json_encode(['data' => $data, 'metaData' => null, 'respEstado' => ['codRespuesta' => $codigo, 'msgeRespuesta' => $mensaje, 'codError' => null]]));
    }

    private static function resumen(): MockResponse
    {
        return self::json([
            ['rsmnTipoDocInteger' => 33, 'dcvNombreTipoDoc' => 'Factura Electrónica', 'rsmnTotDoc' => 2, 'rsmnMntExe' => 0, 'rsmnMntNeto' => 150000, 'rsmnMntIVA' => 28500, 'rsmnMntTotal' => 178500],
            ['rsmnTipoDocInteger' => 61, 'dcvNombreTipoDoc' => 'Nota de Crédito Electrónica', 'rsmnTotDoc' => 1, 'rsmnMntExe' => 0, 'rsmnMntNeto' => 10000, 'rsmnMntIVA' => 1900, 'rsmnMntTotal' => 11900],
            ['rsmnTipoDocInteger' => 34, 'rsmnTotDoc' => 0],
        ]);
    }

    public function testComprasDelPeriodo(): void
    {
        $service = $this->service([
            self::login(),
            new MockResponse('<html>RCV</html>'),
            self::resumen(),
            self::json([
                ['detTipoDoc' => 33, 'detRutDoc' => 96790240, 'detDvDoc' => '3', 'detRznSoc' => 'PROVEEDOR UNO SPA', 'detNroDoc' => 1501, 'detFchDoc' => '05/10/2026', 'detFecRecepcion' => '05/10/2026 10:11:12', 'detMntExe' => 0, 'detMntNeto' => 100000, 'detMntIVA' => 19000, 'detMntTotal' => 119000, 'detEventoReceptorLeyenda' => 'Acepta Contenido del Documento'],
                ['detTipoDoc' => 33, 'detRutDoc' => 11111111, 'detDvDoc' => '1', 'detRznSoc' => 'PROVEEDOR DOS', 'detNroDoc' => 77, 'detFchDoc' => '20/10/2026', 'detMntNeto' => 50000, 'detMntIVA' => 9500, 'detMntTotal' => 59500],
            ]),
            self::json([
                ['detRutDoc' => 96790240, 'detDvDoc' => '3', 'detRznSoc' => 'PROVEEDOR UNO SPA', 'detNroDoc' => 300, 'detFchDoc' => '25/10/2026', 'detMntNeto' => 10000, 'detMntIVA' => 1900, 'detMntTotal' => 11900],
            ]),
        ]);

        $compras = $service->compras('76.192.083-9', '2026-10');
        self::assertCount(3, $compras);
        $f = $compras[0];
        self::assertSame(33, $f->tipoDte);
        self::assertSame(1501, $f->folio);
        self::assertSame('96790240-3', $f->rutContraparte);
        self::assertSame('PROVEEDOR UNO SPA', $f->razonSocial);
        self::assertSame('2026-10-05', $f->fechaEmision?->format('Y-m-d'));
        self::assertSame(119000, $f->montoTotal);
        self::assertSame('Acepta Contenido del Documento', $f->eventoReceptor);
        self::assertSame('96790240-3|33|1501', $f->getKey());
        self::assertSame(61, $compras[2]->tipoDte, 'El tipo se completa desde la consulta');

        // Autenticación con certificado y payload del servicio
        self::assertStringStartsWith('https://herculesr.sii.cl/cgi_AUT2000/CAutInicio.cgi?https://www4.sii.cl/consdcvinternetui/', $this->requests[0]['url']);
        self::assertSame('https://www4.sii.cl/consdcvinternetui/services/data/facadeService/getResumen', $this->requests[2]['url']);
        $resumen = json_decode((string) $this->requests[2]['options']['body'], true);
        self::assertSame('RCVTOKEN1', $resumen['metaData']['conversationId']);
        self::assertSame(['rutEmisor' => '76192083', 'dvEmisor' => '9', 'ptributario' => '202610', 'estadoContab' => 'REGISTRO', 'operacion' => 'COMPRA', 'busquedaInicial' => true], $resumen['data']);
        $detalle = json_decode((string) $this->requests[3]['options']['body'], true);
        self::assertStringEndsWith('FacadeService/getDetalleCompra', $detalle['metaData']['namespace']);
        self::assertSame(33, $detalle['data']['codTipoDoc']);
        self::assertStringContainsString('Cookie: TOKEN=RCVTOKEN1', implode("\n", $this->requests[3]['options']['headers']));
    }

    public function testVentasPorTipoYSinRegistros(): void
    {
        $service = $this->service([self::login(), new MockResponse('ok'), self::json([], 1, 'No existen registros para el periodo')]);
        self::assertSame([], $service->ventas('76192083-9', new \DateTimeImmutable('2026-09-15'), 39));
        self::assertStringEndsWith('getDetalleVenta', (string) $this->requests[2]['url']);
    }

    public function testErrorDelServicio(): void
    {
        $service = $this->service([self::login(), new MockResponse('ok'), self::json([], 3, 'Usuario sin permisos sobre el contribuyente')]);
        $this->expectException(WebServiceException::class);
        $this->expectExceptionMessage('Usuario sin permisos');
        $service->compras('76192083-9', '202610', 33);
    }

    public function testRespuestaNoJson(): void
    {
        $service = $this->service([self::login(), new MockResponse('ok'), new MockResponse('<html>Sitio en mantención</html>')]);
        $this->expectException(WebServiceException::class);
        $this->expectExceptionMessage('no respondió JSON');
        $service->compras('76192083-9', '202610', 33);
    }

    public function testPeriodoInvalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service([])->compras('76192083-9', '2026-13');
    }

    public function testDesactivadoPorDefecto(): void
    {
        $see = new See(Fixtures::certificate(), Environment::PRODUCCION);
        try {
            $see->getRcvService();
            self::fail('Debe estar desactivado');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('desactivado', $e->getMessage());
        }
        self::assertInstanceOf(RcvService::class, $see->enableRcvService()->getRcvService());
    }

    public function testConciliacionConElErp(): void
    {
        $service = $this->service([
            self::login(), new MockResponse('ok'),
            self::json([
                ['detTipoDoc' => 33, 'detRutDoc' => 96790240, 'detDvDoc' => '3', 'detNroDoc' => 1501, 'detMntTotal' => 119000],
                ['detTipoDoc' => 33, 'detRutDoc' => 96790240, 'detDvDoc' => '3', 'detNroDoc' => 1502, 'detMntTotal' => 50000],
                ['detTipoDoc' => 33, 'detRutDoc' => 11111111, 'detDvDoc' => '1', 'detNroDoc' => 77, 'detMntTotal' => 59500],
            ]),
        ]);
        $sii = $service->compras('76192083-9', '202610', 33);
        $erp = [
            ['rut' => '96.790.240-3', 'tipo' => 33, 'folio' => 1501, 'monto_total' => 119000],
            ['rut' => '11111111-1', 'tipo' => 33, 'folio' => 77, 'monto_total' => 59000],
            ['rut' => '76086428-5', 'tipo' => 33, 'folio' => 9, 'monto_total' => 1000],
        ];
        $r = (new RcvConciliacion())->conciliar($sii, $erp);

        self::assertFalse($r->isConciliado());
        self::assertCount(1, $r->coincidentes);
        self::assertSame(1502, $r->soloEnSii[0]->folio, 'Factura del proveedor que el ERP no registró');
        self::assertSame(9, $r->soloEnErp[0]['folio']);
        self::assertSame(-500, $r->diferencias[0]['diferencia']);
    }
}
