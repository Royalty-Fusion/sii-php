<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws;

use RoyaltyFusion\Sii\Exception\AuthenticationException;
use RoyaltyFusion\Sii\Exception\WebServiceException;
use RoyaltyFusion\Sii\Util\Rut;
use RoyaltyFusion\Sii\Ws\Auth\TokenProviderInterface;
use RoyaltyFusion\Sii\Ws\Result\DteStatus;
use RoyaltyFusion\Sii\Ws\Result\EnvioStatistic;
use RoyaltyFusion\Sii\Ws\Result\EnvioStatus;
use RoyaltyFusion\Sii\Ws\Result\SendResult;
use RoyaltyFusion\Sii\Xml\XmlUtil;

/**
 * Servicios del SII para DTE (facturas, guías, notas, exportación):
 *  - upload del EnvioDTE (cgi_dte/UPL/DTEUpload)
 *  - estado del envío por Track ID (QueryEstUp)
 *  - estado de un DTE (QueryEstDte) y validación avanzada con firma (QueryEstDteAv)
 */
final class DteService
{
    public function __construct(
        private readonly HttpTransport $http,
        private readonly SoapClient $soap,
        private readonly TokenProviderInterface $tokens,
        private readonly Endpoints $endpoints,
    ) {
    }

    /**
     * Sube un EnvioDTE firmado.
     *
     * @param string $rutEnvia   RUT de la persona que envía (titular del certificado)
     * @param string $rutEmisor  RUT de la empresa emisora
     */
    public function upload(string $xml, string $rutEnvia, string $rutEmisor, ?string $fileName = null, bool $retryOnAuthError = true): SendResult
    {
        $sender = Rut::parse($rutEnvia);
        $company = Rut::parse($rutEmisor);
        $fileName ??= sprintf('EnvioDTE_%d_%s.xml', $company->number, date('YmdHis'));
        [$contentType, $body] = Multipart::build([
            'rutSender' => (string) $sender->number,
            'dvSender' => $sender->dv,
            'rutCompany' => (string) $company->number,
            'dvCompany' => $company->dv,
        ], 'archivo', $fileName, $xml);

        $token = $this->tokens->getToken();
        $response = $this->http->request('POST', $this->endpoints->upload, [
            'headers' => [
                'Content-Type' => $contentType,
                'Cookie' => 'TOKEN='.$token,
                'Accept' => 'image/gif, image/x-xbitmap, image/jpeg, image/pjpeg, application/vnd.ms-powerpoint, application/ms-excel, application/msword, */*',
                'Accept-Language' => 'es-cl',
                'Referer' => $this->endpoints->upload,
            ],
            'body' => $body,
        ]);

        try {
            $doc = XmlUtil::load(trim($response->body));
        } catch (\Throwable $e) {
            throw new WebServiceException(sprintf('Respuesta inesperada del upload del SII (HTTP %d).', $response->status), null, $response->body, $response->status, $e);
        }
        $xpath = new \DOMXPath($doc);
        $status = trim($xpath->evaluate('string(//STATUS)'));
        $trackId = trim($xpath->evaluate('string(//TRACKID)'));
        $errors = [];
        foreach ($xpath->query('//DETAIL/ERROR') ?: [] as $error) {
            $errors[] = trim($error->textContent);
        }

        if ($status === '5' && $retryOnAuthError) {
            // Token vencido o inválido: se solicita uno nuevo y se reintenta una vez.
            $this->tokens->invalidate();

            return $this->upload($xml, $rutEnvia, $rutEmisor, $fileName, false);
        }

        return new SendResult(
            success: $status === '0' && $trackId !== '',
            trackId: $trackId !== '' ? $trackId : null,
            status: $status !== '' ? $status : null,
            message: SendResult::UPLOAD_STATUS[$status] ?? ('Error interno del SII (STATUS '.$status.')'),
            xml: $xml,
            fileName: $fileName,
            rawResponse: $response->body,
            receivedAt: trim($xpath->evaluate('string(//TIMESTAMP)')) ?: null,
            errors: $errors,
        );
    }

    /** Estado de un envío (QueryEstUp.getEstUp). */
    public function getEnvioStatus(string $trackId, string $rutEmisor): EnvioStatus
    {
        $rut = Rut::parse($rutEmisor);
        $raw = $this->callWithToken(fn (string $token) => $this->soap->callRpc($this->endpoints->queryEstUp, 'getEstUp', [
            'RutCompania' => (string) $rut->number,
            'DvCompania' => $rut->dv,
            'TrackId' => $trackId,
            'Token' => $token,
        ]));
        $response = SiiResponse::parse($raw);

        $stats = [];
        $current = [];
        foreach ($response->bodyChildren() as [$name, $value]) {
            if ($name === 'TIPO_DOCTO' && $current !== []) {
                $stats[] = self::stat($current);
                $current = [];
            }
            $current[$name] = $value;
        }
        if (isset($current['TIPO_DOCTO'])) {
            $stats[] = self::stat($current);
        }

        return new EnvioStatus(
            $trackId,
            $response->getEstado(),
            $response->getGlosa(),
            $stats,
            [],
            $response->header('NUM_ATENCION'),
            $raw,
        );
    }

    /**
     * Estado de un DTE emitido (QueryEstDte.getEstDte).
     *
     * @param string $rutConsultante RUT de quien consulta (titular del certificado)
     */
    public function getDteStatus(
        string $rutConsultante,
        string $rutEmisor,
        string $rutReceptor,
        int $tipoDte,
        int $folio,
        \DateTimeInterface $fechaEmision,
        int $montoTotal,
    ): DteStatus {
        $consultante = Rut::parse($rutConsultante);
        $emisor = Rut::parse($rutEmisor);
        $receptor = Rut::parse($rutReceptor);
        $raw = $this->callWithToken(fn (string $token) => $this->soap->callRpc($this->endpoints->queryEstDte, 'getEstDte', [
            'RutConsultante' => (string) $consultante->number,
            'DvConsultante' => $consultante->dv,
            'RutCompania' => (string) $emisor->number,
            'DvCompania' => $emisor->dv,
            'RutReceptor' => (string) $receptor->number,
            'DvReceptor' => $receptor->dv,
            'TipoDte' => (string) $tipoDte,
            'FolioDte' => (string) $folio,
            'FechaEmisionDte' => $fechaEmision->format('dmY'),
            'MontoDte' => (string) $montoTotal,
            'Token' => $token,
        ]));
        $response = SiiResponse::parse($raw);

        return new DteStatus(
            $response->getEstado(),
            $response->header('GLOSA_ESTADO') ?? $response->getGlosa(),
            $response->header('ERR_CODE'),
            $response->header('GLOSA_ERR'),
            $response->header('NUM_ATENCION'),
            $raw,
        );
    }

    /**
     * Validación avanzada: verifica además que la firma (SignatureValue) del DTE coincida con la recibida por el SII.
     */
    public function getDteStatusAvanzado(
        string $rutEmisor,
        string $rutReceptor,
        int $tipoDte,
        int $folio,
        \DateTimeInterface $fechaEmision,
        int $montoTotal,
        string $signatureValue,
    ): DteStatus {
        $emisor = Rut::parse($rutEmisor);
        $receptor = Rut::parse($rutReceptor);
        $raw = $this->callWithToken(fn (string $token) => $this->soap->callRpc($this->endpoints->queryEstDteAv, 'getEstDteAv', [
            'RutEmpresa' => (string) $emisor->number,
            'DvEmpresa' => $emisor->dv,
            'RutReceptor' => (string) $receptor->number,
            'DvReceptor' => $receptor->dv,
            'TipoDte' => (string) $tipoDte,
            'FolioDte' => (string) $folio,
            'FechaEmisionDte' => $fechaEmision->format('dmY'),
            'MontoDte' => (string) $montoTotal,
            'FirmaDte' => (string) preg_replace('/\s+/', '', $signatureValue),
            'Token' => $token,
        ]));
        $response = SiiResponse::parse($raw);

        return new DteStatus(
            $response->getEstado(),
            $response->header('GLOSA') ?? $response->header('GLOSA_ESTADO'),
            $response->header('ERR_CODE'),
            $response->header('GLOSA_ERR'),
            $response->header('NUMATENCION') ?? $response->header('NUM_ATENCION'),
            $raw,
        );
    }

    /**
     * @param callable(string): string $call
     */
    private function callWithToken(callable $call): string
    {
        $raw = $call($this->tokens->getToken());
        if (self::isTokenError($raw)) {
            // Token inexistente o vencido: se renueva y se reintenta una vez.
            $this->tokens->invalidate();
            $raw = $call($this->tokens->getToken(true));
            if (self::isTokenError($raw)) {
                $response = SiiResponse::parse($raw);
                throw new AuthenticationException(sprintf('El SII rechazó el token de autenticación: %s', $response->getGlosa() ?? $response->getEstado()), $response->getEstado(), $raw);
            }
        }

        return $raw;
    }

    private static function isTokenError(string $raw): bool
    {
        $response = SiiResponse::parse($raw);
        $glosa = (string) ($response->getGlosa() ?? $response->header('GLOSA_ERR'));

        return \in_array($response->getEstado(), ['001', '002', '003'], true) || preg_match('/token/i', $glosa) === 1 && !preg_match('/creado/i', $glosa);
    }

    /** @param array<string, string> $data */
    private static function stat(array $data): EnvioStatistic
    {
        return new EnvioStatistic(
            (int) ($data['TIPO_DOCTO'] ?? 0),
            (int) ($data['INFORMADOS'] ?? 0),
            (int) ($data['ACEPTADOS'] ?? 0),
            (int) ($data['RECHAZADOS'] ?? 0),
            (int) ($data['REPAROS'] ?? 0),
        );
    }
}
