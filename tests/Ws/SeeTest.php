<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Ws;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Exception\AuthenticationException;
use RoyaltyFusion\Sii\Exception\ValidationException;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\See;
use RoyaltyFusion\Sii\Tests\Documents;
use RoyaltyFusion\Sii\Tests\Fixtures;
use RoyaltyFusion\Sii\Util\Text;
use RoyaltyFusion\Sii\Ws\HttpTransport;
use RoyaltyFusion\Sii\Ws\RegistroReclamoService;
use RoyaltyFusion\Sii\Xmldsig\XmlSignatureVerifier;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SeeTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /**
     * @param list<MockResponse|callable> $responses
     */
    private function see(array $responses): See
    {
        $this->requests = [];
        $queue = $responses;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$queue): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $next = array_shift($queue);
            if ($next === null) {
                self::fail('Solicitud HTTP inesperada: '.$method.' '.$url);
            }

            return $next instanceof MockResponse ? $next : $next($method, $url, $options);
        });

        return (new See(
            Fixtures::certificate(),
            Environment::CERTIFICACION,
            new Resolution('2026-10-01', 0),
            Fixtures::cafs(),
            transport: new HttpTransport($client, retries: 1, retryDelayMs: 0),
            clock: static fn () => new \DateTimeImmutable('2026-11-02 10:30:00', new \DateTimeZone('America/Santiago')),
        ))->setCompany(Fixtures::emisor());
    }

    public function testEnviaFacturaAlSii(): void
    {
        $see = $this->see([
            new MockResponse(SiiResponses::seed()),
            new MockResponse(SiiResponses::token()),
            new MockResponse(SiiResponses::upload()),
        ]);
        $result = $see->send(Documents::factura());

        self::assertTrue($result->isSuccess());
        self::assertSame('0123456789', $result->getTrackId());
        self::assertSame('0', $result->status);
        self::assertCount(3, $this->requests);

        self::assertSame('https://maullin.sii.cl/DTEWS/CrSeed.jws', $this->requests[0]['url']);
        self::assertSame('https://maullin.sii.cl/DTEWS/GetTokenFromSeed.jws', $this->requests[1]['url']);
        $tokenRequest = (string) $this->requests[1]['options']['body'];
        self::assertStringContainsString('<ns1:getToken', $tokenRequest);
        // El XML de la semilla firmado viaja escapado dentro de pszXml y su firma es válida.
        preg_match('#<pszXml xsi:type="xsd:string">(.*)</pszXml>#s', $tokenRequest, $m);
        $signedSeed = htmlspecialchars_decode($m[1], \ENT_XML1 | \ENT_QUOTES);
        self::assertStringContainsString('<Semilla>005938471932</Semilla>', $signedSeed);
        self::assertTrue((new XmlSignatureVerifier())->isValid($signedSeed));

        $upload = $this->requests[2];
        self::assertSame('https://maullin.sii.cl/cgi_dte/UPL/DTEUpload', $upload['url']);
        $headers = implode("\n", $upload['options']['headers']);
        self::assertStringContainsString('Cookie: TOKEN=ABCDEFGHIJKL1', $headers);
        self::assertStringContainsString('User-Agent: Mozilla/4.0 (compatible; PROG 1.0; Windows NT 5.0; YComp 5.0.2.4)', $headers);
        $body = (string) $upload['options']['body'];
        foreach (['name="rutSender"'."\r\n\r\n11111111", 'name="dvSender"'."\r\n\r\n1", 'name="rutCompany"'."\r\n\r\n76192083", 'name="dvCompany"'."\r\n\r\n9", 'name="archivo"; filename="EnvioDTE_76192083_'] as $part) {
            self::assertStringContainsString($part, $body);
        }
        self::assertStringContainsString('<EnvioDTE xmlns="http://www.sii.cl/SiiDte"', $body);
        self::assertTrue((new XmlSignatureVerifier())->isValid($result->getXml()));
    }

    public function testReutilizaTokenYRenuevaSiExpira(): void
    {
        $see = $this->see([
            new MockResponse(SiiResponses::seed()),
            new MockResponse(SiiResponses::token('T1')),
            new MockResponse(SiiResponses::upload('5')),     // token rechazado
            new MockResponse(SiiResponses::seed()),
            new MockResponse(SiiResponses::token('T2')),
            new MockResponse(SiiResponses::upload('0', '42')),
            new MockResponse(SiiResponses::upload('0', '43')), // segundo envío reutiliza T2
        ]);
        self::assertSame('42', $see->send(Documents::factura(1))->trackId);
        self::assertSame('43', $see->send(Documents::factura(2))->trackId);
        self::assertStringContainsString('TOKEN=T2', implode("\n", $this->requests[6]['options']['headers']));
    }

    public function testErrorDeUpload(): void
    {
        $see = $this->see([
            new MockResponse(SiiResponses::seed()),
            new MockResponse(SiiResponses::token()),
            new MockResponse(SiiResponses::upload('7')),
        ]);
        $result = $see->send(Documents::factura());
        self::assertFalse($result->isSuccess());
        self::assertSame('Esquema inválido', $result->message);
        self::assertSame(['Error de prueba'], $result->errors);
    }

    public function testTokenRechazado(): void
    {
        $see = $this->see([
            new MockResponse(SiiResponses::seed()),
            new MockResponse(SiiResponses::tokenError()),
        ]);
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Certificado Revocado');
        $see->send(Documents::factura());
    }

    public function testNoEnviaSiHayErrores(): void
    {
        $see = $this->see([]);
        $doc = Documents::factura();
        $doc->getReceptor()?->setRut('1-1');
        try {
            $see->send($doc);
            self::fail('Debió lanzar ValidationException');
        } catch (ValidationException $e) {
            self::assertFalse($e->getResult()->isValid());
            self::assertStringContainsString('RUT_INVALIDO', $e->getMessage());
        }
        self::assertSame([], $this->requests, 'No debe haber comunicación con el SII si el documento es inválido');
    }

    public function testEstadoDelEnvio(): void
    {
        $see = $this->see([
            new MockResponse(SiiResponses::seed()),
            new MockResponse(SiiResponses::token()),
            new MockResponse(SiiResponses::estUp()),
        ]);
        $status = $see->getStatus('0123456789');
        self::assertSame('EPR', $status->estado);
        self::assertTrue($status->isFinal());
        self::assertFalse($status->isAccepted());
        self::assertCount(2, $status->estadisticas);
        self::assertSame(1, $status->getTotal('rechazados'));
        self::assertSame(3, $status->getTotal('informados'));
        $body = (string) $this->requests[2]['options']['body'];
        self::assertStringContainsString('<RutCompania xsi:type="xsd:string">76192083</RutCompania><DvCompania xsi:type="xsd:string">9</DvCompania><TrackId xsi:type="xsd:string">0123456789</TrackId><Token xsi:type="xsd:string">ABCDEFGHIJKL1</Token>', $body);
    }

    public function testEstadoDelDocumento(): void
    {
        $see = $this->see([
            new MockResponse(SiiResponses::seed()),
            new MockResponse(SiiResponses::token()),
            new MockResponse(SiiResponses::estDte()),
        ]);
        $status = $see->getDocumentStatus(Documents::factura());
        self::assertTrue($status->isOk());
        $body = (string) $this->requests[2]['options']['body'];
        self::assertStringContainsString('<FechaEmisionDte xsi:type="xsd:string">02112026</FechaEmisionDte><MontoDte xsi:type="xsd:string">1072231</MontoDte>', $body);
    }

    public function testEnviaBoletaPorApiRest(): void
    {
        $see = $this->see([
            new MockResponse(SiiResponses::boletaSeed()),
            new MockResponse(SiiResponses::boletaToken()),
            new MockResponse('{"rut_emisor":"76192083-9","rut_envia":"11111111-1","trackid":15338,"fecha_recepcion":"2026-11-02 10:31:00","estado":"REC","file":"EnvioBOLETA.xml"}'),
            new MockResponse('{"rut_emisor":"76192083-9","trackid":15338,"estado":"EPR","estadistica":[{"tipo":39,"informados":2,"aceptados":1,"rechazados":1,"reparos":0}],"detalle_rep_rech":[{"tipo":39,"folio":2,"estado":"RCH","descripcion":"Rechazado","error":[{"seccion":"DTE","descripcion":"Monto mal calculado"}]}]}'),
        ]);
        $result = $see->sendBatch([Documents::boleta(1), Documents::boleta(2)]);
        self::assertTrue($result->isSuccess());
        self::assertSame('15338', $result->trackId);
        self::assertSame('https://apicert.sii.cl/recursos/v1/boleta.electronica.semilla', $this->requests[0]['url']);
        self::assertSame('https://apicert.sii.cl/recursos/v1/boleta.electronica.token', $this->requests[1]['url']);
        self::assertSame('https://pangal.sii.cl/recursos/v1/boleta.electronica.envio', $this->requests[2]['url']);
        self::assertStringContainsString('<EnvioBOLETA', Text::toUtf8($result->getXml()));
        self::assertTrue((new XmlSignatureVerifier())->isValid((string) $this->requests[1]['options']['body']));

        $status = $see->getStatus('15338', null, true);
        self::assertSame('https://apicert.sii.cl/recursos/v1/boleta.electronica.envio/76192083-9-15338', $this->requests[3]['url']);
        self::assertSame('EPR', $status->estado);
        self::assertFalse($status->isAccepted());
        self::assertSame(2, $status->documentos[0]->folio);
        self::assertStringContainsString('TOKEN=BOLETATOKEN1', implode("\n", $this->requests[3]['options']['headers']));
    }

    public function testRegistroReclamo(): void
    {
        $see = $this->see([
            new MockResponse(SiiResponses::seed()),
            new MockResponse(SiiResponses::token()),
            new MockResponse(SiiResponses::reclamo()),
            new MockResponse(SiiResponses::eventos()),
        ]);
        $result = $see->getRegistroReclamo()->registrar('96790240-3', 33, 1234, RegistroReclamoService::ACEPTA_CONTENIDO);
        self::assertTrue($result->isOk());
        self::assertSame('https://ws2.sii.cl/WSREGISTRORECLAMODTECERT/registroreclamodteservice', $this->requests[2]['url']);
        self::assertStringContainsString('<ws:ingresarAceptacionReclamoDoc><rutEmisor>96790240</rutEmisor><dvEmisor>3</dvEmisor><tipoDoc>33</tipoDoc><folio>1234</folio><accionDoc>ACD</accionDoc></ws:ingresarAceptacionReclamoDoc>', (string) $this->requests[2]['options']['body']);

        $eventos = $see->getRegistroReclamo()->listarEventos('96790240-3', 33, 1234);
        self::assertSame('ACD', $eventos->eventos[0]['codigo']);
        self::assertSame('96790240-3', $eventos->eventos[0]['rutResponsable']);
    }

    public function testProduccionUsaServidoresDeProduccion(): void
    {
        $see = new See(Fixtures::certificate(), Environment::PRODUCCION);
        self::assertSame(Environment::PRODUCCION, $see->getEnvironment());
        $endpoints = \RoyaltyFusion\Sii\Ws\Endpoints::for(Environment::PRODUCCION);
        self::assertSame('https://palena.sii.cl/cgi_dte/UPL/DTEUpload', $endpoints->upload);
        self::assertSame('https://rahue.sii.cl/recursos/v1/boleta.electronica.envio', $endpoints->boletaUpload);
        self::assertSame('https://api.sii.cl/recursos/v1/boleta.electronica.token', $endpoints->boletaToken);
    }
}
