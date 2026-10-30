<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Intercambio;

use RoyaltyFusion\Sii\Certificate\Certificate;
use RoyaltyFusion\Sii\Exception\ValidationException;
use RoyaltyFusion\Sii\Util\Rut;
use RoyaltyFusion\Sii\Util\Text;
use RoyaltyFusion\Sii\Validator\XsdValidator;
use RoyaltyFusion\Sii\Xml\XmlElement;
use RoyaltyFusion\Sii\Xml\XmlUtil;
use RoyaltyFusion\Sii\Xmldsig\XmlSigner;

/**
 * Respuestas de intercambio entre contribuyentes (requeridas también en la certificación del SII):
 *
 *  - RespuestaDTE / RecepcionEnvio: acuse de recibo técnico del sobre recibido.
 *  - RespuestaDTE / ResultadoDTE: aprobación o rechazo comercial de cada DTE.
 *  - EnvioRecibos: recibo de mercaderías o servicios (Ley 19.983).
 */
final class ResponseBuilder
{
    /** EstadoRecepEnv */
    public const ENVIO_OK = 0;
    public const ENVIO_ERROR_SCHEMA = 1;
    public const ENVIO_ERROR_FIRMA = 2;
    public const ENVIO_RUT_RECEPTOR = 3;
    public const ENVIO_REPETIDO = 90;
    public const ENVIO_ILEGIBLE = 91;
    public const ENVIO_OTROS = 99;

    /** EstadoRecepDTE */
    public const DTE_RECIBIDO_OK = 0;
    public const DTE_ERROR_FIRMA = 1;
    public const DTE_ERROR_RUT_EMISOR = 2;
    public const DTE_ERROR_RUT_RECEPTOR = 3;
    public const DTE_REPETIDO = 4;
    public const DTE_OTROS = 99;

    /** EstadoDTE (resultado comercial) */
    public const ACEPTADO = 0;
    public const ACEPTADO_CON_DISCREPANCIAS = 1;
    public const RECHAZADO = 2;

    public const DECLARACION_LEY_19983 = 'El acuse de recibo que se declara en este acto, de acuerdo a lo dispuesto en la letra b) del Art. 4, y la letra c) del Art. 5 de la Ley 19.983, acredita que la entrega de mercaderias o servicio(s) prestado(s) ha(n) sido recibido(s).';

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    public function __construct(
        private readonly Certificate $certificate,
        private readonly XsdValidator $xsd = new XsdValidator(),
        ?\Closure $clock = null,
        private readonly ?string $nombreContacto = null,
        private readonly ?string $fonoContacto = null,
        private readonly ?string $mailContacto = null,
    ) {
        $this->clock = \RoyaltyFusion\Sii\Util\Clock::wrap($clock);
    }

    /**
     * Acuse de recibo del envío (RecepcionEnvio) con el estado de recepción de cada DTE.
     * Si no se indica, el estado se determina a partir de las validaciones del lector.
     *
     * @param string $rutReceptor RUT de la empresa que responde (receptor de los DTE)
     */
    public function recepcionEnvio(ReceivedEnvelope $envelope, string $nombreArchivo, string $rutReceptor, int $idRespuesta, int $codigoEnvio = 1, ?int $estadoEnvio = null, ?string $glosa = null): string
    {
        $estadoEnvio ??= match (true) {
            !$envelope->schemaValido => self::ENVIO_ERROR_SCHEMA,
            !$envelope->firmaValida => self::ENVIO_ERROR_FIRMA,
            !Rut::isValid($envelope->rutReceptor) || !Rut::parse($envelope->rutReceptor)->equals($rutReceptor) => self::ENVIO_RUT_RECEPTOR,
            default => self::ENVIO_OK,
        };
        $glosa ??= match ($estadoEnvio) {
            self::ENVIO_OK => 'Envio Recibido Conforme',
            self::ENVIO_ERROR_SCHEMA => 'Envio Rechazado - Error de Schema',
            self::ENVIO_ERROR_FIRMA => 'Envio Rechazado - Error de Firma',
            self::ENVIO_RUT_RECEPTOR => 'Envio Rechazado - RUT Receptor No Corresponde',
            self::ENVIO_REPETIDO => 'Archivo Repetido',
            self::ENVIO_ILEGIBLE => 'Archivo Ilegible',
            default => 'Envio Rechazado - Otros',
        };

        $recepcion = XmlElement::create('RecepcionEnvio')
            ->leaf('NmbEnvio', Text::truncate(Text::sanitize($nombreArchivo), 80))
            ->leaf('FchRecep', ($this->clock)()->format('Y-m-d\TH:i:s'))
            ->leaf('CodEnvio', $codigoEnvio)
            ->leaf('EnvioDTEID', Text::truncate($envelope->setId, 80))
            ->leaf('Digest', $envelope->digest)
            ->leaf('RutEmisor', self::rut($envelope->rutEmisor))
            ->leaf('RutReceptor', self::rut($envelope->rutReceptor))
            ->leaf('EstadoRecepEnv', $estadoEnvio)
            ->leaf('RecepEnvGlosa', Text::truncate($glosa, 256));

        if ($estadoEnvio === self::ENVIO_OK) {
            $recepcion->leaf('NroDTE', \count($envelope->documentos));
            foreach ($envelope->documentos as $dte) {
                $estado = match (true) {
                    !$dte->firmaValida => self::DTE_ERROR_FIRMA,
                    !Rut::isValid($dte->rutEmisor) || !Rut::parse($dte->rutEmisor)->equals($envelope->rutEmisor) => self::DTE_ERROR_RUT_EMISOR,
                    !Rut::isValid($dte->rutReceptor) || !Rut::parse($dte->rutReceptor)->equals($rutReceptor) => self::DTE_ERROR_RUT_RECEPTOR,
                    !$dte->timbreValido => self::DTE_OTROS,
                    default => self::DTE_RECIBIDO_OK,
                };
                $recepcion->child('RecepcionDTE')
                    ->leaf('TipoDTE', $dte->tipoDte)
                    ->leaf('Folio', $dte->folio)
                    ->leaf('FchEmis', $dte->fechaEmision->format('Y-m-d'))
                    ->leaf('RUTEmisor', self::rut($dte->rutEmisor))
                    ->leaf('RUTRecep', self::rut($dte->rutReceptor))
                    ->leaf('MntTotal', $dte->montoTotal)
                    ->leaf('EstadoRecepDTE', $estado)
                    ->leaf('RecepDTEGlosa', match ($estado) {
                        self::DTE_RECIBIDO_OK => 'DTE Recibido OK',
                        self::DTE_ERROR_FIRMA => 'DTE No Recibido - Error de Firma',
                        self::DTE_ERROR_RUT_EMISOR => 'DTE No Recibido - Error en RUT Emisor',
                        self::DTE_ERROR_RUT_RECEPTOR => 'DTE No Recibido - Error en RUT Receptor',
                        default => 'DTE No Recibido - Timbre Electronico Invalido',
                    });
            }
        }

        return $this->respuesta($envelope->rutEmisor, $rutReceptor, $idRespuesta, max(1, \count($envelope->documentos)), $recepcion);
    }

    /**
     * Resultado comercial (aprobación/rechazo) de los DTE recibidos.
     *
     * @param list<array{dte: ReceivedDte, estado: int, glosa?: string, codigoRechazo?: int}> $resultados
     */
    public function resultadoDte(array $resultados, string $rutReceptor, int $idRespuesta, int $codigoEnvio = 1): string
    {
        if ($resultados === []) {
            throw new \RoyaltyFusion\Sii\Exception\InvalidArgumentException('Debe indicar al menos un resultado.');
        }
        $nodes = [];
        foreach ($resultados as $i => $r) {
            $dte = $r['dte'];
            $glosa = $r['glosa'] ?? match ($r['estado']) {
                self::ACEPTADO => 'DTE Aceptado OK',
                self::ACEPTADO_CON_DISCREPANCIAS => 'DTE Aceptado con Discrepancias',
                default => 'DTE Rechazado',
            };
            $nodes[] = XmlElement::create('ResultadoDTE')
                ->leaf('TipoDTE', $dte->tipoDte)
                ->leaf('Folio', $dte->folio)
                ->leaf('FchEmis', $dte->fechaEmision->format('Y-m-d'))
                ->leaf('RUTEmisor', self::rut($dte->rutEmisor))
                ->leaf('RUTRecep', self::rut($dte->rutReceptor))
                ->leaf('MntTotal', $dte->montoTotal)
                ->leaf('CodEnvio', $codigoEnvio + $i)
                ->leaf('EstadoDTE', $r['estado'])
                ->leaf('EstadoDTEGlosa', Text::truncate(Text::sanitize($glosa), 256))
                ->leaf('CodRchDsc', $r['estado'] === self::ACEPTADO ? null : ($r['codigoRechazo'] ?? -1));
        }

        return $this->respuesta($resultados[0]['dte']->rutEmisor, $rutReceptor, $idRespuesta, \count($nodes), ...$nodes);
    }

    /**
     * Recibo de mercaderías o servicios (EnvioRecibos, Ley 19.983).
     *
     * @param list<ReceivedDte> $dtes
     * @param string            $rutFirma RUT de quien firma el recibo (por defecto, el titular del certificado)
     */
    public function envioRecibos(array $dtes, string $rutReceptor, string $recinto, ?string $rutFirma = null): string
    {
        if ($dtes === []) {
            throw new \RoyaltyFusion\Sii\Exception\InvalidArgumentException('Debe indicar al menos un documento.');
        }
        $signer = new XmlSigner($this->certificate);
        $now = ($this->clock)()->format('Y-m-d\TH:i:s');
        $rutFirma ??= (string) $this->certificate->getRut();
        $recibos = '';
        foreach ($dtes as $dte) {
            $id = sprintf('R%dT%dF%d', Rut::parse($dte->rutEmisor)->number, $dte->tipoDte, $dte->folio);
            $doc = XmlElement::create('DocumentoRecibo', ['ID' => $id])
                ->leaf('TipoDoc', $dte->tipoDte)
                ->leaf('Folio', $dte->folio)
                ->leaf('FchEmis', $dte->fechaEmision->format('Y-m-d'))
                ->leaf('RUTEmisor', self::rut($dte->rutEmisor))
                ->leaf('RUTRecep', self::rut($dte->rutReceptor))
                ->leaf('MntTotal', $dte->montoTotal)
                ->leaf('Recinto', Text::truncate(Text::sanitize($recinto), 80))
                ->leaf('RutFirma', self::rut($rutFirma))
                ->leaf('Declaracion', self::DECLARACION_LEY_19983)
                ->leaf('TmstFirmaRecibo', $now);
            $recibo = XmlElement::create('Recibo', ['xmlns' => XmlUtil::NS_SII_DTE, 'xmlns:xsi' => XmlUtil::NS_XSI, 'version' => '1.0'])->append($doc);
            $signed = $signer->signById(XmlUtil::LATIN1_HEADER."\n".Text::toLatin1($recibo->render()), $id);
            $recibos .= XmlUtil::stripDeclaration($signed);
        }

        $caratula = XmlElement::create('Caratula', ['version' => '1.0'])
            ->leaf('RutResponde', self::rut($rutReceptor))
            ->leaf('RutRecibe', self::rut($dtes[0]->rutEmisor))
            ->leaf('NmbContacto', $this->nombreContacto !== null ? Text::truncate(Text::sanitize($this->nombreContacto), 40) : null)
            ->leaf('FonoContacto', $this->fonoContacto !== null ? Text::truncate(Text::sanitize($this->fonoContacto), 40) : null)
            ->leaf('MailContacto', $this->mailContacto)
            ->leaf('TmstFirmaEnv', $now);
        $xml = XmlUtil::LATIN1_HEADER."\n"
            .'<EnvioRecibos xmlns="'.XmlUtil::NS_SII_DTE.'" xmlns:xsi="'.XmlUtil::NS_XSI.'" xsi:schemaLocation="'.XmlUtil::NS_SII_DTE.' EnvioRecibos_v10.xsd" version="1.0">'
            .'<SetRecibos ID="SetDteRecibidos">'.Text::toLatin1($caratula->render()).$recibos.'</SetRecibos></EnvioRecibos>';

        return $this->finish($signer->signById($xml, 'SetDteRecibidos'));
    }

    private function respuesta(string $rutRecibe, string $rutResponde, int $idRespuesta, int $nroDetalles, XmlElement ...$nodes): string
    {
        $caratula = XmlElement::create('Caratula', ['version' => '1.0'])
            ->leaf('RutResponde', self::rut($rutResponde))
            ->leaf('RutRecibe', self::rut($rutRecibe))
            ->leaf('IdRespuesta', $idRespuesta)
            ->leaf('NroDetalles', $nroDetalles)
            ->leaf('NmbContacto', $this->nombreContacto !== null ? Text::truncate(Text::sanitize($this->nombreContacto), 40) : null)
            ->leaf('FonoContacto', $this->fonoContacto !== null ? Text::truncate(Text::sanitize($this->fonoContacto), 40) : null)
            ->leaf('MailContacto', $this->mailContacto)
            ->leaf('TmstFirmaResp', ($this->clock)()->format('Y-m-d\TH:i:s'));
        $resultado = XmlElement::create('Resultado', ['ID' => 'ResultadoEnvio'])->append($caratula);
        foreach ($nodes as $node) {
            $resultado->append($node);
        }
        $xml = XmlUtil::LATIN1_HEADER."\n"
            .'<RespuestaDTE xmlns="'.XmlUtil::NS_SII_DTE.'" xmlns:xsi="'.XmlUtil::NS_XSI.'" xsi:schemaLocation="'.XmlUtil::NS_SII_DTE.' RespuestaEnvioDTE_v10.xsd" version="1.0">'
            .Text::toLatin1($resultado->render()).'</RespuestaDTE>';

        return $this->finish((new XmlSigner($this->certificate))->signById($xml, 'ResultadoEnvio'));
    }

    private function finish(string $signed): string
    {
        $result = $this->xsd->validate($signed);
        if (!$result->isValid()) {
            throw new ValidationException($result, 'La respuesta de intercambio');
        }

        return $signed;
    }

    private static function rut(string $rut): string
    {
        return Rut::isValid($rut) ? Rut::normalize($rut) : $rut;
    }
}
