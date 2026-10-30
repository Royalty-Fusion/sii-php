<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws;

use RoyaltyFusion\Sii\Exception\InvalidArgumentException;
use RoyaltyFusion\Sii\Util\Rut;
use RoyaltyFusion\Sii\Ws\Auth\TokenProviderInterface;
use RoyaltyFusion\Sii\Ws\Result\ReclamoResult;
use RoyaltyFusion\Sii\Xml\XmlUtil;

/**
 * Registro de aceptación o reclamo de un DTE recibido (Ley 20.956), web service WsRegistroReclamoDte.
 *
 * Acciones: ACD (acepta contenido), RCD (reclamo al contenido), ERM (otorga recibo de mercaderías
 * o servicios, Ley 19.983), RFP (reclamo por falta parcial de mercaderías), RFT (reclamo por falta total).
 */
final class RegistroReclamoService
{
    public const NAMESPACE = 'http://ws.registroreclamodte.diii.sdi.sii.cl';

    public const ACEPTA_CONTENIDO = 'ACD';
    public const RECLAMO_CONTENIDO = 'RCD';
    public const RECIBO_MERCADERIAS = 'ERM';
    public const RECLAMO_FALTA_PARCIAL = 'RFP';
    public const RECLAMO_FALTA_TOTAL = 'RFT';

    public function __construct(
        private readonly SoapClient $soap,
        private readonly TokenProviderInterface $tokens,
        private readonly Endpoints $endpoints,
    ) {
    }

    public function registrar(string $rutEmisor, int $tipoDte, int $folio, string $accion): ReclamoResult
    {
        if (!\in_array($accion, [self::ACEPTA_CONTENIDO, self::RECLAMO_CONTENIDO, self::RECIBO_MERCADERIAS, self::RECLAMO_FALTA_PARCIAL, self::RECLAMO_FALTA_TOTAL], true)) {
            throw new InvalidArgumentException(sprintf('Acción "%s" inválida (ACD, RCD, ERM, RFP o RFT).', $accion));
        }

        return $this->call('ingresarAceptacionReclamoDoc', $rutEmisor, $tipoDte, $folio, ['accionDoc' => $accion]);
    }

    /** Historial de eventos (aceptaciones, reclamos, cesiones) registrados para el documento. */
    public function listarEventos(string $rutEmisor, int $tipoDte, int $folio): ReclamoResult
    {
        return $this->call('listarEventosHistDoc', $rutEmisor, $tipoDte, $folio);
    }

    /** Consulta si el documento puede ser cedido (factoring). */
    public function consultarCedible(string $rutEmisor, int $tipoDte, int $folio): ReclamoResult
    {
        return $this->call('consultarDocDteCedible', $rutEmisor, $tipoDte, $folio);
    }

    /** Fecha y hora de recepción del documento en el SII (inicio del plazo de 8 días para reclamar). */
    public function consultarFechaRecepcion(string $rutEmisor, int $tipoDte, int $folio): ?string
    {
        $rut = Rut::parse($rutEmisor);
        $response = $this->soap->callDocument($this->endpoints->registroReclamo, self::NAMESPACE, 'consultarFechaRecepcionSii', [
            'rutEmisor' => (string) $rut->number,
            'dvEmisor' => $rut->dv,
            'tipoDoc' => (string) $tipoDte,
            'folio' => (string) $folio,
        ], $this->tokens->getToken());
        $value = trim($response->textContent);

        return $value === '' ? null : $value;
    }

    /** @param array<string, string> $extra */
    private function call(string $method, string $rutEmisor, int $tipoDte, int $folio, array $extra = []): ReclamoResult
    {
        $rut = Rut::parse($rutEmisor);
        $response = $this->soap->callDocument($this->endpoints->registroReclamo, self::NAMESPACE, $method, [
            'rutEmisor' => (string) $rut->number,
            'dvEmisor' => $rut->dv,
            'tipoDoc' => (string) $tipoDte,
            'folio' => (string) $folio,
        ] + $extra, $this->tokens->getToken());

        $xpath = new \DOMXPath($response->ownerDocument ?? new \DOMDocument());
        $codigo = (int) trim((string) $xpath->evaluate('string(.//*[local-name()="codResp"])', $response));
        $descripcion = trim((string) $xpath->evaluate('string(.//*[local-name()="descResp"])', $response));
        $eventos = [];
        foreach (XmlUtil::elements($xpath, './/*[local-name()="listaEventosDoc"]', $response) as $evento) {
            $get = static fn (string $n): string => trim((string) $xpath->evaluate(sprintf('string(./*[local-name()="%s"])', $n), $evento));
            $eventos[] = [
                'codigo' => $get('codEvento'),
                'descripcion' => $get('descEvento'),
                'rutResponsable' => $get('rutResponsable').($get('dvResponsable') !== '' ? '-'.$get('dvResponsable') : ''),
                'fecha' => $get('fechaEvento'),
            ];
        }

        return new ReclamoResult($codigo, $descripcion, $eventos, (string) $response->ownerDocument?->saveXML($response));
    }
}
