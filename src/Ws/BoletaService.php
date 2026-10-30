<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws;

use RoyaltyFusion\Sii\Exception\WebServiceException;
use RoyaltyFusion\Sii\Util\Rut;
use RoyaltyFusion\Sii\Ws\Auth\TokenProviderInterface;
use RoyaltyFusion\Sii\Ws\Result\DocumentResult;
use RoyaltyFusion\Sii\Ws\Result\DteStatus;
use RoyaltyFusion\Sii\Ws\Result\EnvioStatistic;
use RoyaltyFusion\Sii\Ws\Result\EnvioStatus;
use RoyaltyFusion\Sii\Ws\Result\SendResult;

/**
 * API REST de Boleta Electrónica del SII:
 *  - envío: POST {pangal|rahue}.sii.cl/recursos/v1/boleta.electronica.envio
 *  - estado del envío: GET {apicert|api}.sii.cl/recursos/v1/boleta.electronica.envio/{rut}-{dv}-{trackid}
 *  - estado de una boleta: GET …/boleta.electronica/{rut}-{dv}-{tipo}-{folio}/estado
 */
final class BoletaService
{
    public function __construct(
        private readonly HttpTransport $http,
        private readonly TokenProviderInterface $tokens,
        private readonly Endpoints $endpoints,
    ) {
    }

    public function upload(string $xml, string $rutEnvia, string $rutEmisor, ?string $fileName = null, bool $retryOnAuthError = true): SendResult
    {
        $sender = Rut::parse($rutEnvia);
        $company = Rut::parse($rutEmisor);
        $fileName ??= sprintf('EnvioBOLETA_%d_%s.xml', $company->number, date('YmdHis'));
        [$contentType, $body] = Multipart::build([
            'rutSender' => (string) $sender->number,
            'dvSender' => $sender->dv,
            'rutCompany' => (string) $company->number,
            'dvCompany' => $company->dv,
        ], 'archivo', $fileName, $xml, 'text/xml');

        $response = $this->http->request('POST', $this->endpoints->boletaUpload, [
            'headers' => [
                'Content-Type' => $contentType,
                'Cookie' => 'TOKEN='.$this->tokens->getToken(),
                'Accept' => 'application/json',
            ],
            'body' => $body,
        ]);
        if (\in_array($response->status, [401, 403], true) && $retryOnAuthError) {
            $this->tokens->invalidate();

            return $this->upload($xml, $rutEnvia, $rutEmisor, $fileName, false);
        }
        $data = json_decode($response->body, true);
        if (!\is_array($data)) {
            return new SendResult(false, null, (string) $response->status, sprintf('Respuesta inesperada del SII (HTTP %d).', $response->status), $xml, $fileName, $response->body);
        }
        $trackId = isset($data['trackid']) ? (string) $data['trackid'] : null;
        $estado = isset($data['estado']) ? (string) $data['estado'] : null;

        return new SendResult(
            success: $response->isSuccessful() && $trackId !== null && $trackId !== '' && $trackId !== '0',
            trackId: $trackId,
            status: $estado,
            message: $estado === 'REC' ? 'Envío recibido' : (string) ($data['descripcion'] ?? $data['message'] ?? $estado ?? 'Error en el envío'),
            xml: $xml,
            fileName: $fileName,
            rawResponse: $response->body,
            receivedAt: isset($data['fecha_recepcion']) ? (string) $data['fecha_recepcion'] : null,
        );
    }

    public function getEnvioStatus(string $trackId, string $rutEmisor): EnvioStatus
    {
        $rut = Rut::parse($rutEmisor);
        $url = sprintf('%s/boleta.electronica.envio/%d-%s-%s', $this->endpoints->boletaApi, $rut->number, $rut->dv, rawurlencode($trackId));
        $data = $this->getJson($url);

        $stats = [];
        foreach ((array) ($data['estadistica'] ?? []) as $e) {
            $stats[] = new EnvioStatistic((int) ($e['tipo'] ?? 0), (int) ($e['informados'] ?? 0), (int) ($e['aceptados'] ?? 0), (int) ($e['rechazados'] ?? 0), (int) ($e['reparos'] ?? 0));
        }
        $docs = [];
        foreach ((array) ($data['detalle_rep_rech'] ?? []) as $d) {
            $docs[] = new DocumentResult((int) ($d['tipo'] ?? 0), (int) ($d['folio'] ?? 0), (string) ($d['estado'] ?? ''), isset($d['descripcion']) ? (string) $d['descripcion'] : null, array_values((array) ($d['error'] ?? [])));
        }

        return new EnvioStatus(
            $trackId,
            isset($data['estado']) ? (string) $data['estado'] : null,
            isset($data['glosa']) ? (string) $data['glosa'] : null,
            $stats,
            $docs,
            null,
            (string) json_encode($data, \JSON_UNESCAPED_UNICODE),
        );
    }

    public function getBoletaStatus(string $rutEmisor, int $tipo, int $folio, string $rutReceptor, int $montoTotal, \DateTimeInterface $fechaEmision): DteStatus
    {
        $emisor = Rut::parse($rutEmisor);
        $receptor = Rut::parse($rutReceptor);
        $url = sprintf('%s/boleta.electronica/%d-%s-%d-%d/estado', $this->endpoints->boletaApi, $emisor->number, $emisor->dv, $tipo, $folio);
        $data = $this->getJson($url, [
            'rut_receptor' => (string) $receptor->number,
            'dv_receptor' => $receptor->dv,
            'monto' => (string) $montoTotal,
            'fechaEmision' => $fechaEmision->format('d-m-Y'),
        ]);

        return new DteStatus(
            isset($data['estado']) ? (string) $data['estado'] : (isset($data['codigo']) ? (string) $data['codigo'] : null),
            isset($data['descripcion']) ? (string) $data['descripcion'] : (isset($data['glosa']) ? (string) $data['glosa'] : null),
            null,
            null,
            null,
            (string) json_encode($data, \JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function getJson(string $url, array $query = [], bool $retry = true): array
    {
        $response = $this->http->request('GET', $url, [
            'headers' => ['Cookie' => 'TOKEN='.$this->tokens->getToken(), 'Accept' => 'application/json'],
            'query' => $query,
        ]);
        if (\in_array($response->status, [401, 403], true) && $retry) {
            $this->tokens->invalidate();

            return $this->getJson($url, $query, false);
        }
        $data = json_decode($response->body, true);
        if (!$response->isSuccessful() || !\is_array($data)) {
            throw new WebServiceException(sprintf('Error en la consulta a la API de boletas (HTTP %d).', $response->status), null, $response->body, $response->status);
        }

        return $data;
    }
}
