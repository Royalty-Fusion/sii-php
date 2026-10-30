<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws\Portal;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RoyaltyFusion\Sii\Certificate\Certificate;
use RoyaltyFusion\Sii\Exception\AuthenticationException;
use RoyaltyFusion\Sii\Exception\WebServiceException;
use RoyaltyFusion\Sii\Util\Text;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sesión autenticada con certificado digital en el sitio web del SII (cookies de sesión),
 * para automatizar formularios que no tienen web service (timbraje de folios).
 */
final class PortalSession
{
    public const AUTH_URL = 'https://herculesr.sii.cl/cgi_AUT2000/CAutInicio.cgi';
    private const USER_AGENT = 'Mozilla/5.0 (compatible; RoyaltyFusion-SII-PHP)';

    private readonly HttpClientInterface $client;
    private readonly LoggerInterface $logger;
    /** @var array<string, string> */
    private array $cookies = [];
    private bool $authenticated = false;
    /** @var list<string> */
    private array $tempFiles = [];

    public function __construct(
        private readonly Certificate $certificate,
        ?HttpClientInterface $client = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->client = $client ?? HttpClient::create();
        $this->logger = $logger ?? new NullLogger();
    }

    public function __destruct()
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    /** Autentica con el certificado (TLS con certificado de cliente) y guarda las cookies de sesión. */
    public function login(string $destination): void
    {
        if ($this->authenticated) {
            return;
        }
        $this->request('POST', self::AUTH_URL.'?'.$destination, ['referencia' => $destination], true);
        if (!isset($this->cookies['TOKEN'])) {
            throw new AuthenticationException('El SII no autenticó el certificado digital en el sitio web (no entregó la cookie de sesión).');
        }
        $this->authenticated = true;
    }

    /**
     * @param array<string, string>|null $fields campos del formulario (POST) o null (GET)
     *
     * @return array{0: string, 1: string, 2: string} [cuerpo (UTF-8 si es HTML), URL final, content-type]
     */
    public function request(string $method, string $url, ?array $fields = null, bool $withClientCertificate = false): array
    {
        for ($redirects = 0; $redirects < 10; ++$redirects) {
            $options = [
                'headers' => ['User-Agent' => self::USER_AGENT, 'Accept-Language' => 'es-CL'],
                'max_redirects' => 0,
                'timeout' => 60,
            ];
            if ($this->cookies !== []) {
                $options['headers']['Cookie'] = implode('; ', array_map(static fn ($k, $v) => $k.'='.$v, array_keys($this->cookies), $this->cookies));
            }
            if ($withClientCertificate) {
                [$cert, $key] = $this->certificateFiles();
                $options['local_cert'] = $cert;
                $options['local_pk'] = $key;
            }
            if ($fields !== null && $method === 'POST') {
                $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
                $options['body'] = http_build_query(array_map(static fn ($v) => Text::toLatin1((string) $v), $fields));
            }
            $this->logger->debug('SII portal {method} {url}', ['method' => $method, 'url' => $url]);
            try {
                $response = $this->client->request($method, $url, $options);
                $status = $response->getStatusCode();
                $headers = $response->getHeaders(false);
                $body = $response->getContent(false);
            } catch (\Throwable $e) {
                throw new WebServiceException(sprintf('No fue posible comunicarse con el sitio del SII (%s): %s', $url, $e->getMessage()), null, null, null, $e);
            }
            foreach ($headers['set-cookie'] ?? [] as $cookie) {
                if (preg_match('/^\s*([^=;\s]+)=([^;]*)/', $cookie, $m)) {
                    $this->cookies[$m[1]] = $m[2];
                }
            }
            if ($status >= 300 && $status < 400 && isset($headers['location'][0])) {
                $url = HtmlForm::resolve($url, $headers['location'][0]);
                $method = 'GET';
                $fields = null;
                continue;
            }
            if ($status >= 400) {
                throw new WebServiceException(sprintf('El sitio del SII respondió HTTP %d en %s.', $status, $url), null, $body, $status);
            }
            $contentType = $headers['content-type'][0] ?? '';
            if (!mb_check_encoding($body, 'UTF-8')) {
                $body = Text::toUtf8($body);
            }

            return [$body, $url, $contentType];
        }

        throw new WebServiceException('Demasiadas redirecciones en el sitio del SII.');
    }

    /** @return array<string, string> */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /** @return array{0: string, 1: string} */
    private function certificateFiles(): array
    {
        if ($this->tempFiles === []) {
            foreach ([$this->certificate->getCertificatePem(), $this->privateKeyPem()] as $content) {
                $file = tempnam(sys_get_temp_dir(), 'sii_pem_');
                if ($file === false) {
                    throw new WebServiceException('No se pudo crear un archivo temporal para el certificado.');
                }
                chmod($file, 0600);
                file_put_contents($file, $content);
                $this->tempFiles[] = $file;
            }
        }

        return [$this->tempFiles[0], $this->tempFiles[1]];
    }

    private function privateKeyPem(): string
    {
        $pem = '';
        if (!openssl_pkey_export($this->certificate->getPrivateKey(), $pem)) {
            throw new WebServiceException('No se pudo exportar la clave privada del certificado.');
        }

        return $pem;
    }
}
