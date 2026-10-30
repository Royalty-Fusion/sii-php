<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RoyaltyFusion\Sii\Exception\WebServiceException;
use RoyaltyFusion\Sii\Util\RedactingLogger;
use RoyaltyFusion\Sii\Util\Redactor;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cliente HTTP con reintentos para los servidores del SII (que suelen responder con intermitencia).
 *
 * Reintenta ante errores de red y respuestas 5xx con espera exponencial.
 */
final class HttpTransport
{
    public const USER_AGENT = 'Mozilla/4.0 (compatible; PROG 1.0; Windows NT 5.0; YComp 5.0.2.4)';

    private readonly HttpClientInterface $client;
    private readonly LoggerInterface $logger;

    public function __construct(
        ?HttpClientInterface $client = null,
        ?LoggerInterface $logger = null,
        private readonly int $retries = 3,
        private readonly float $timeout = 60.0,
        private readonly int $retryDelayMs = 1000,
        ?Redactor $redactor = null,
    ) {
        $this->client = $client ?? HttpClient::create();
        // Todo lo que se registra pasa por el Redactor (token, semilla firmada, firmas, claves).
        $this->logger = $logger === null ? new NullLogger() : ($logger instanceof RedactingLogger ? $logger : new RedactingLogger($logger, $redactor ?? new Redactor()));
    }

    /**
     * @param array<string, mixed> $options opciones de Symfony HttpClient (headers, body, query)
     */
    public function request(string $method, string $url, array $options = [], bool $retry = true): HttpResponse
    {
        // Las operaciones no idempotentes (upload de sobres) no se reintentan: un reintento podría
        // duplicar el envío. Ante un error se informa y el llamador decide (ver UploadUncertainException).
        $retries = $retry ? $this->retries : 0;
        $options['timeout'] ??= $this->timeout;
        $options['headers'] = array_merge(['User-Agent' => self::USER_AGENT], $options['headers'] ?? []);
        $attempt = 0;
        while (true) {
            ++$attempt;
            try {
                $this->logger->debug('SII request {method} {url} (intento {attempt})', ['method' => $method, 'url' => $url, 'attempt' => $attempt]);
                $response = $this->client->request($method, $url, $options);
                $status = $response->getStatusCode();
                $body = $response->getContent(false);
                $headers = $response->getHeaders(false);
                $this->logger->debug('SII response {status} {url}', ['status' => $status, 'url' => $url, 'body' => $body]);
                if ($status >= 500 && $attempt <= $retries) {
                    $this->wait($attempt);
                    continue;
                }

                return new HttpResponse($status, $body, $headers);
            } catch (TransportExceptionInterface $e) {
                $this->logger->warning('Error de comunicación con el SII: {error}', ['error' => $e->getMessage(), 'url' => $url]);
                if ($attempt > $retries) {
                    throw new WebServiceException(sprintf('No fue posible comunicarse con el SII (%s): %s', $url, $e->getMessage()), null, null, null, $e);
                }
                $this->wait($attempt);
            }
        }
    }

    private function wait(int $attempt): void
    {
        if ($this->retryDelayMs > 0) {
            usleep($this->retryDelayMs * 1000 * (2 ** ($attempt - 1)));
        }
    }
}
