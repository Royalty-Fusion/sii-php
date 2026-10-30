<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Util;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\See;
use RoyaltyFusion\Sii\Tests\Documents;
use RoyaltyFusion\Sii\Tests\Fixtures;
use RoyaltyFusion\Sii\Tests\Ws\SiiResponses;
use RoyaltyFusion\Sii\Util\Redactor;
use RoyaltyFusion\Sii\Ws\HttpTransport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RedactorTest extends TestCase
{
    public function testOcultaSecretos(): void
    {
        $redactor = new Redactor(['clave-del-pfx']);
        $caf = (string) file_get_contents(Fixtures::DIR.'/caf/caf_33.xml');
        $text = SiiResponses::token('TOKENSECRETO123')."\nCookie: TOKEN=TOKENSECRETO123; CSESSIONID=abc\n"
            .'<SignatureValue>FIRMA==</SignatureValue><X509Certificate>CERT==</X509Certificate>'
            .'<getToken><item><Semilla>123</Semilla></item><Signature/></getToken>'
            .$caf.(string) file_get_contents(Fixtures::DIR.'/certificado.pem')
            .' password=clave-del-pfx {"token":"T999"}';
        $out = $redactor->redact($text);

        self::assertStringNotContainsString('TOKENSECRETO123', $out);
        self::assertStringNotContainsString('FIRMA==', $out);
        self::assertStringNotContainsString('CERT==', $out);
        self::assertStringNotContainsString('<Semilla>123</Semilla>', $out);
        self::assertStringNotContainsString('BEGIN RSA PRIVATE KEY-----'."\n", $out);
        self::assertStringNotContainsString('MIIBPAIBAAJBAL', $out); // contenido de RSASK
        self::assertStringNotContainsString('clave-del-pfx', $out);
        self::assertStringNotContainsString('T999', $out);
        self::assertStringNotContainsString('-----BEGIN PRIVATE KEY-----'."\nMII", $out);
        // Lo que no es secreto se conserva
        self::assertStringContainsString('<RE>76192083-9</RE>', $out);
        self::assertStringContainsString('<ESTADO>00</ESTADO>', html_entity_decode($out));
    }

    public function testLosLogsDelSdkNoContienenElToken(): void
    {
        $logger = new class extends AbstractLogger {
            public string $output = '';

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->output .= $message.' '.json_encode($context)."\n";
            }
        };
        $client = new MockHttpClient([
            new MockResponse(SiiResponses::seed()),
            new MockResponse(SiiResponses::token('TOKENSECRETO123')),
            new MockResponse(SiiResponses::upload()),
        ]);
        $see = (new See(Fixtures::certificate(), Environment::CERTIFICACION, new Resolution('2026-10-01', 0), Fixtures::cafs(),
            transport: new HttpTransport($client, $logger, 0, retryDelayMs: 0),
            clock: static fn () => new \DateTimeImmutable('2026-11-02 10:30:00', new \DateTimeZone('America/Santiago'))))
            ->setCompany(Fixtures::emisor());
        self::assertTrue($see->send(Documents::factura())->isSuccess());

        self::assertNotSame('', $logger->output);
        self::assertStringNotContainsString('TOKENSECRETO123', $logger->output);
        self::assertStringContainsString('SEMILLA', $logger->output);
    }
}
