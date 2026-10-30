<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Rcv;

use Psr\Log\LoggerInterface;
use RoyaltyFusion\Sii\Certificate\Certificate;
use RoyaltyFusion\Sii\Exception\InvalidArgumentException;
use RoyaltyFusion\Sii\Exception\WebServiceException;
use RoyaltyFusion\Sii\Util\Rut;
use RoyaltyFusion\Sii\Ws\Portal\PortalSession;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Consulta del Registro de Compras y Ventas (RCV) del SII, para conciliar compras y ventas del ERP.
 *
 * EXPERIMENTAL: el SII no publica un web service oficial del RCV. Esta clase usa los servicios JSON del
 * sitio "Registro de Compras y Ventas" (www4.sii.cl/consdcvinternetui) con una sesión autenticada por
 * certificado digital. El certificado debe pertenecer a un usuario con acceso al RCV de la empresa.
 * El RCV refleja datos reales (producción). Si el SII cambia el servicio, se lanza una excepción con
 * la respuesta recibida; nunca se retornan datos inventados.
 */
final class RcvService
{
    public const COMPRA = 'COMPRA';
    public const VENTA = 'VENTA';

    /** Estados del registro de compras. */
    public const REGISTRO = 'REGISTRO';
    public const PENDIENTE = 'PENDIENTE';
    public const NO_INCLUIR = 'NO_INCLUIR';
    public const RECLAMADO = 'RECLAMADO';

    private const NAMESPACE = 'cl.sii.sdi.lob.diii.consdcv.data.api.interfaces.FacadeService/';

    private readonly PortalSession $session;

    public function __construct(
        Certificate $certificate,
        ?HttpClientInterface $client = null,
        ?LoggerInterface $logger = null,
        ?PortalSession $session = null,
        private readonly string $baseUrl = 'https://www4.sii.cl/consdcvinternetui',
    ) {
        $this->session = $session ?? new PortalSession($certificate, $client, $logger);
    }

    /**
     * Resumen por tipo de documento de un período.
     *
     * @param string|\DateTimeInterface $periodo "AAAAMM", "AAAA-MM" o una fecha del mes
     *
     * @return list<RcvResumen>
     */
    public function resumen(string $rutEmpresa, string|\DateTimeInterface $periodo, string $operacion = self::COMPRA, string $estado = self::REGISTRO): array
    {
        $rows = $this->call('getResumen', $this->baseData($rutEmpresa, $periodo, $operacion, $estado) + ['busquedaInicial' => true]);

        return array_map(static fn (array $row) => RcvResumen::fromSii($row), $rows);
    }

    /**
     * Compras registradas (documentos recibidos de proveedores) en el período.
     *
     * @param int|null $tipoDte null = todos los tipos del período
     *
     * @return list<RcvDocument>
     */
    public function compras(string $rutEmpresa, string|\DateTimeInterface $periodo, ?int $tipoDte = null, string $estado = self::REGISTRO): array
    {
        return $this->detalle('getDetalleCompra', self::COMPRA, $rutEmpresa, $periodo, $tipoDte, $estado);
    }

    /**
     * Ventas registradas en el período.
     *
     * @return list<RcvDocument>
     */
    public function ventas(string $rutEmpresa, string|\DateTimeInterface $periodo, ?int $tipoDte = null): array
    {
        return $this->detalle('getDetalleVenta', self::VENTA, $rutEmpresa, $periodo, $tipoDte, self::REGISTRO);
    }

    /** @return list<RcvDocument> */
    private function detalle(string $method, string $operacion, string $rutEmpresa, string|\DateTimeInterface $periodo, ?int $tipoDte, string $estado): array
    {
        $tipos = $tipoDte !== null ? [$tipoDte] : array_values(array_unique(array_map(
            static fn (RcvResumen $r) => $r->tipoDte,
            array_filter($this->resumen($rutEmpresa, $periodo, $operacion, $estado), static fn (RcvResumen $r) => $r->cantidad > 0 && $r->tipoDte > 0),
        )));
        $out = [];
        foreach ($tipos as $tipo) {
            $rows = $this->call($method, $this->baseData($rutEmpresa, $periodo, $operacion, $estado) + ['codTipoDoc' => $tipo]);
            foreach ($rows as $row) {
                $row['detTipoDoc'] ??= $tipo;
                $out[] = RcvDocument::fromSii($row, $operacion, $estado);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function baseData(string $rutEmpresa, string|\DateTimeInterface $periodo, string $operacion, string $estado): array
    {
        if (!\in_array($operacion, [self::COMPRA, self::VENTA], true)) {
            throw new InvalidArgumentException('La operación debe ser COMPRA o VENTA.');
        }
        if (!\in_array($estado, [self::REGISTRO, self::PENDIENTE, self::NO_INCLUIR, self::RECLAMADO], true)) {
            throw new InvalidArgumentException('Estado RCV inválido (REGISTRO, PENDIENTE, NO_INCLUIR o RECLAMADO).');
        }
        $rut = Rut::parse($rutEmpresa);

        return [
            'rutEmisor' => (string) $rut->number,
            'dvEmisor' => $rut->dv,
            'ptributario' => self::periodo($periodo),
            'estadoContab' => $estado,
            'operacion' => $operacion,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function call(string $method, array $data): array
    {
        $this->session->login($this->baseUrl.'/');
        $token = $this->session->getCookie('TOKEN');
        if ($token === null) {
            throw new WebServiceException('No hay sesión del SII para consultar el RCV.');
        }
        $response = $this->session->postJson($this->baseUrl.'/services/data/facadeService/'.$method, [
            'metaData' => [
                'namespace' => self::NAMESPACE.$method,
                'conversationId' => $token,
                'transactionId' => bin2hex(random_bytes(16)),
                'page' => null,
            ],
            'data' => $data,
        ]);

        $estado = \is_array($response['respEstado'] ?? null) ? $response['respEstado'] : [];
        $codigo = (int) ($estado['codRespuesta'] ?? 0);
        $rows = $response['data'] ?? [];
        if ($codigo !== 0 && $rows === []) {
            $mensaje = (string) ($estado['msgeRespuesta'] ?? $estado['codError'] ?? 'sin mensaje');
            if (preg_match('/no (existen|hay|se encontraron)/i', $mensaje) === 1) {
                return [];
            }

            throw new WebServiceException(sprintf('El RCV del SII respondió error %d: %s', $codigo, $mensaje), (string) $codigo, (string) json_encode($response));
        }
        if ($rows === null || $rows === []) {
            return [];
        }
        if (!\is_array($rows) || !array_is_list($rows)) {
            throw new WebServiceException('Respuesta del RCV con formato inesperado (el SII pudo cambiar el servicio).', null, (string) json_encode($response));
        }

        $out = [];
        foreach ($rows as $row) {
            if (\is_array($row)) {
                $clean = [];
                foreach ($row as $key => $value) {
                    $clean[(string) $key] = $value;
                }
                $out[] = $clean;
            }
        }

        return $out;
    }

    private static function periodo(string|\DateTimeInterface $periodo): string
    {
        if ($periodo instanceof \DateTimeInterface) {
            return $periodo->format('Ym');
        }
        $p = str_replace(['-', '/'], '', trim($periodo));
        if (preg_match('/^(\d{4})(0[1-9]|1[0-2])$/', $p) !== 1) {
            throw new InvalidArgumentException(sprintf('Período "%s" inválido (use AAAAMM).', $periodo));
        }

        return $p;
    }
}
