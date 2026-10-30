<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Folio;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Exception\AuthenticationException;
use RoyaltyFusion\Sii\Exception\CafException;
use RoyaltyFusion\Sii\Exception\WebServiceException;
use RoyaltyFusion\Sii\Folio\SiiFolioService;
use RoyaltyFusion\Sii\Tests\Fixtures;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Simula el flujo de formularios del timbraje electrónico para probar la navegación,
 * el manejo de cookies y la validación del CAF descargado.
 */
final class SiiFolioServiceTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @param list<MockResponse> $responses */
    private function service(array $responses): SiiFolioService
    {
        $this->requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? self::fail('Solicitud inesperada '.$url);
        });

        return new SiiFolioService(Fixtures::certificate(), Environment::CERTIFICACION, $client);
    }

    private static function login(): MockResponse
    {
        return new MockResponse('', ['http_code' => 302, 'response_headers' => [
            'Location: https://maullin.sii.cl/cvc_cgi/dte/of_solicita_folios',
            'Set-Cookie: TOKEN=ABC123; path=/; domain=.sii.cl',
            'Set-Cookie: CSESSIONID=XYZ; path=/',
        ]]);
    }

    private static function page(string $body): MockResponse
    {
        return new MockResponse(mb_convert_encoding('<html><body>'.$body.'</body></html>', 'ISO-8859-1', 'UTF-8'), ['response_headers' => ['Content-Type: text/html; charset=ISO-8859-1']]);
    }

    private static function solicitudPages(string $max = '100'): array
    {
        return [
            self::page('<form method="post" action="of_solicita_folios_dcto">RUT Empresa <input name="RUT_EMP"> - <input name="DV_EMP"><input type="submit" name="ACEPTAR" value="Continuar"></form>'),
            self::page('<form method="post" action="of_confirma_folio"><select name="COD_DOCTO"><option value="33">Factura</option><option value="61">NC</option></select>'
                .'<input type="hidden" name="RUT_EMP" value="76192083"><input type="hidden" name="DV_EMP" value="9"><input type="hidden" name="MAX_AUTOR" value="'.$max.'">'
                .'Cantidad <input name="CANT_DOCTOS"><input type="submit" name="VOLVER" value="Volver"><input type="submit" name="ACEPTAR" value="Solicitar Numeración"></form>'),
        ];
    }

    public function testSolicitaFolios(): void
    {
        $caf = (string) file_get_contents(Fixtures::DIR.'/caf/caf_33.xml');
        $service = $this->service([
            self::login(),
            self::page(''), // destino tras el login
            ...self::solicitudPages(),
            self::page('<p>Se autorizarán los folios 1 al 100</p><form method="post" action="of_genera_folio"><input type="hidden" name="FOLIO_INI" value="1"><input type="hidden" name="FOLIO_FIN" value="100"><input type="submit" name="ACEPTAR" value="Obtener Folios"></form>'),
            self::page('<form method="post" action="of_genera_archivo"><input type="hidden" name="FOLIO_INI" value="1"><input type="submit" name="ACEPTAR" value="AQUI"></form>'),
            new MockResponse($caf, ['response_headers' => ['Content-Type: text/xml']]),
        ]);

        $result = $service->solicitar(Fixtures::RUT_EMISOR, 33, 100);
        self::assertSame(33, $result->getTipoDte());
        self::assertSame(100, $result->getFolioHasta());

        // Autenticación con certificado de cliente
        self::assertStringStartsWith('https://herculesr.sii.cl/cgi_AUT2000/CAutInicio.cgi?https://maullin.sii.cl/cvc_cgi/dte/of_solicita_folios', $this->requests[0]['url']);
        // Cookies de sesión reenviadas
        self::assertStringContainsString('Cookie: TOKEN=ABC123; CSESSIONID=XYZ', implode("\n", $this->requests[2]['options']['headers']));
        // Formulario de RUT
        self::assertSame('https://maullin.sii.cl/cvc_cgi/dte/of_solicita_folios_dcto', $this->requests[3]['url']);
        self::assertStringContainsString('RUT_EMP=76192083&DV_EMP=9&ACEPTAR=Continuar', (string) $this->requests[3]['options']['body']);
        // Tipo y cantidad; se usa "Solicitar" y no "Volver"
        $body = (string) $this->requests[4]['options']['body'];
        self::assertStringContainsString('COD_DOCTO=33', $body);
        self::assertStringContainsString('CANT_DOCTOS=100', $body);
        self::assertStringContainsString('ACEPTAR=Solicitar', $body);
        self::assertStringNotContainsString('VOLVER', $body);
    }

    public function testRechazaCantidadMayorAlMaximo(): void
    {
        $service = $this->service([self::login(), self::page(''), ...self::solicitudPages('50')]);
        $this->expectException(CafException::class);
        $this->expectExceptionMessage('como máximo 50 folios');
        $service->solicitar(Fixtures::RUT_EMISOR, 33, 100);
    }

    public function testTipoNoAutorizado(): void
    {
        $service = $this->service([self::login(), self::page(''), ...self::solicitudPages()]);
        $this->expectException(CafException::class);
        $this->expectExceptionMessage('no ofrece el valor "52"');
        $service->solicitar(Fixtures::RUT_EMISOR, 52, 10);
    }

    public function testConsultaSinSolicitar(): void
    {
        $service = $this->service([self::login(), self::page(''), ...self::solicitudPages('250')]);
        $info = $service->consultar(Fixtures::RUT_EMISOR, 33);
        self::assertSame(250, $info->maximoAutorizado);
        self::assertCount(4, $this->requests, 'No debe enviar la solicitud final');
    }

    public function testRechazaCafDeOtroTipo(): void
    {
        $service = $this->service([
            self::login(), self::page(''), ...self::solicitudPages(),
            new MockResponse((string) file_get_contents(Fixtures::DIR.'/caf/caf_61.xml')),
        ]);
        $this->expectException(CafException::class);
        $this->expectExceptionMessage('no del solicitado');
        $service->solicitar(Fixtures::RUT_EMISOR, 33, 10);
    }

    public function testErrorDelSitio(): void
    {
        $service = $this->service([self::login(), self::page(''), self::page('<p>Usuario no autorizado para timbrar documentos</p>')]);
        $this->expectException(WebServiceException::class);
        $this->expectExceptionMessage('Usuario no autorizado para timbrar documentos');
        $service->solicitar(Fixtures::RUT_EMISOR, 33, 10);
    }

    public function testLoginFallido(): void
    {
        $service = $this->service([self::page('Certificado no reconocido')]);
        $this->expectException(AuthenticationException::class);
        $service->solicitar(Fixtures::RUT_EMISOR, 33, 10);
    }
}
