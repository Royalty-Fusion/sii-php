<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii;

use Psr\Log\LoggerInterface;
use RoyaltyFusion\Sii\Caf\Caf;
use RoyaltyFusion\Sii\Caf\CafRepositoryInterface;
use RoyaltyFusion\Sii\Caf\InMemoryCafRepository;
use RoyaltyFusion\Sii\Calculator\TotalsCalculator;
use RoyaltyFusion\Sii\Certificate\Certificate;
use RoyaltyFusion\Sii\Exception\InvalidArgumentException;
use RoyaltyFusion\Sii\Exception\UploadUncertainException;
use RoyaltyFusion\Sii\Folio\FolioManager;
use RoyaltyFusion\Sii\Folio\FolioStoreInterface;
use RoyaltyFusion\Sii\Folio\SiiFolioService;
use RoyaltyFusion\Sii\Model\AbstractDocument;
use RoyaltyFusion\Sii\Model\Boleta;
use RoyaltyFusion\Sii\Model\Company;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\Rcv\RcvService;
use RoyaltyFusion\Sii\Report\RepresentationBuilder;
use RoyaltyFusion\Sii\Report\RepresentationData;
use RoyaltyFusion\Sii\Util\Rut;
use RoyaltyFusion\Sii\Xml\XmlUtil;
use RoyaltyFusion\Sii\Validator\ValidationResult;
use RoyaltyFusion\Sii\Ws\Auth\BoletaTokenProvider;
use RoyaltyFusion\Sii\Ws\Auth\DteTokenProvider;
use RoyaltyFusion\Sii\Ws\Auth\InMemoryTokenStorage;
use RoyaltyFusion\Sii\Ws\Auth\TokenStorageInterface;
use RoyaltyFusion\Sii\Ws\BoletaService;
use RoyaltyFusion\Sii\Ws\DteService;
use RoyaltyFusion\Sii\Ws\Endpoints;
use RoyaltyFusion\Sii\Ws\HttpTransport;
use RoyaltyFusion\Sii\Ws\RegistroReclamoService;
use RoyaltyFusion\Sii\Ws\Result\DocumentRef;
use RoyaltyFusion\Sii\Ws\Result\DteStatus;
use RoyaltyFusion\Sii\Ws\Result\EnvioStatus;
use RoyaltyFusion\Sii\Ws\Result\SendResult;
use RoyaltyFusion\Sii\Ws\SoapClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fachada principal del SDK (al estilo de Greenter\See).
 *
 *   $see = new See(Certificate::fromFile('firma.pfx', 'clave'), Environment::CERTIFICACION, new Resolution('2024-01-15', 0));
 *   $see->addCaf(Caf::fromFile('caf33.xml'));
 *   $result = $see->send($factura);       // valida, timbra, firma, arma el sobre y lo sube al SII
 *   $estado = $see->getStatus($result->trackId, '76123456-7');
 */
final class See
{
    private readonly Endpoints $endpoints;
    private readonly HttpTransport $http;
    private readonly SoapClient $soap;
    private readonly Emitter $emitter;
    private readonly CafRepositoryInterface $cafs;
    private readonly TokenStorageInterface $tokenStorage;
    private ?Company $company = null;
    private ?string $rutEnvia = null;
    private ?string $oficinaSii = null;
    private ?DteService $dteService = null;
    private ?BoletaService $boletaService = null;
    private ?RegistroReclamoService $reclamoService = null;
    private ?FolioManager $folioManager = null;
    private ?SiiFolioService $folioService = null;
    private bool $folioServiceEnabled = false;
    private ?RcvService $rcvService = null;
    private bool $rcvEnabled = false;
    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;
    private ?HttpClientInterface $httpClient;
    private ?LoggerInterface $logger;

    public function __construct(
        private readonly Certificate $certificate,
        private readonly Environment $environment = Environment::CERTIFICACION,
        private ?Resolution $resolution = null,
        ?CafRepositoryInterface $cafs = null,
        ?HttpClientInterface $httpClient = null,
        ?TokenStorageInterface $tokenStorage = null,
        ?LoggerInterface $logger = null,
        ?Endpoints $endpoints = null,
        ?HttpTransport $transport = null,
        private readonly int $tokenTtl = 1800,
        ?\Closure $clock = null,
    ) {
        $this->cafs = $cafs ?? new InMemoryCafRepository();
        $this->endpoints = $endpoints ?? Endpoints::for($environment);
        $this->http = $transport ?? new HttpTransport($httpClient, $logger);
        $this->soap = new SoapClient($this->http);
        $this->tokenStorage = $tokenStorage ?? new InMemoryTokenStorage();
        $this->emitter = new Emitter($certificate, $this->cafs, $environment, clock: $clock);
        $this->clock = Util\Clock::wrap($clock);
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    public function getEnvironment(): Environment
    {
        return $this->environment;
    }

    public function getCertificate(): Certificate
    {
        return $this->certificate;
    }

    /** Emisor por defecto para los documentos que no lo informan. */
    public function setCompany(Company $company): self
    {
        $this->company = $company;

        return $this;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    /**
     * RUT de quien envía (RutEnvia). Por defecto se obtiene del certificado; indíquelo si el
     * certificado no trae el RUT en el subjectAltName.
     */
    public function setRutEnvia(?string $rutEnvia): self
    {
        $this->rutEnvia = $rutEnvia;

        return $this;
    }

    /** Oficina (unidad) del SII del emisor, impresa bajo el recuadro del documento (ej. "Santiago Oriente"). */
    public function setOficinaSii(?string $oficinaSii): self
    {
        $this->oficinaSii = $oficinaSii;

        return $this;
    }

    /**
     * Datos para la representación impresa (emisor, receptor, detalle, totales, referencias, resolución,
     * oficina del SII y el TED con los parámetros del PDF417), sin depender de librerías de PDF.
     */
    public function getRepresentationData(PreparedDocument $prepared, ?string $oficinaSii = null): RepresentationData
    {
        return (new RepresentationBuilder())->build($prepared, $this->resolution, $oficinaSii ?? $this->oficinaSii);
    }

    public function setResolution(Resolution $resolution): self
    {
        $this->resolution = $resolution;

        return $this;
    }

    /** Agrega un CAF (objeto, ruta de archivo o contenido XML). Requiere el repositorio en memoria por defecto. */
    public function addCaf(Caf|string $caf): self
    {
        if (!$this->cafs instanceof InMemoryCafRepository) {
            throw new InvalidArgumentException('addCaf() sólo está disponible con el repositorio de CAF en memoria; agregue los CAF en su repositorio.');
        }
        if (\is_string($caf)) {
            $caf = str_contains($caf, '<AUTORIZACION') ? Caf::fromXml($caf) : Caf::fromFile($caf);
        }
        $this->cafs->add($caf);

        return $this;
    }

    public function getCafRepository(): CafRepositoryInterface
    {
        return $this->cafs;
    }

    /** Valida el documento (reglas del SII, CAF y certificado) sin generar XML. */
    public function validate(AbstractDocument $document): ValidationResult
    {
        return $this->emitter->validate($this->withCompany($document));
    }

    /**
     * Valida, timbra y firma el documento.
     *
     * Si el documento no tiene folio y hay un FolioStore configurado, se asigna el siguiente disponible
     * (y se libera si la emisión falla).
     */
    public function prepare(AbstractDocument $document): PreparedDocument
    {
        $document = $this->withCompany($document);
        if ($document->getFolio() !== null) {
            return $this->emitter->prepare($document);
        }
        $rut = $document->getEmisor()?->getRut() ?? throw new InvalidArgumentException('El documento no tiene emisor para asignar el folio.');
        $manager = $this->getFolioManager();
        $folio = $manager->next($rut, $document->getTipo());
        $document->setFolio($folio);
        try {
            return $this->emitter->prepare($document);
        } catch (\Throwable $e) {
            $manager->release($rut, $document->getTipo(), $folio);
            $document->setFolio(null);

            throw $e;
        }
    }

    /**
     * Registro de folios usados de esta empresa: FileFolioStore, PdoFolioStore o una implementación propia.
     * Sin él no se asignan folios automáticamente (un registro en memoria duplicaría folios entre procesos).
     */
    public function setFolioStore(FolioStoreInterface $store): self
    {
        $this->folioManager = new FolioManager($this->cafs, $store, $this->environment, $this->clock);

        return $this;
    }

    /** Asignación y consulta de folios disponibles. Requiere setFolioStore(). */
    public function getFolioManager(): FolioManager
    {
        return $this->folioManager ?? throw new InvalidArgumentException('Configure un registro de folios con setFolioStore() o SeeConfig::withFolioStore() (FileFolioStore, PdoFolioStore o propio) para asignar y consultar folios.');
    }

    /** Habilita la consulta del Registro de Compras y Ventas (RcvService). */
    public function enableRcvService(bool $enabled = true): self
    {
        $this->rcvEnabled = $enabled;

        return $this;
    }

    /**
     * Registro de Compras y Ventas del SII (compras recibidas de proveedores y ventas), para conciliar el ERP.
     *
     * EXPERIMENTAL y desactivado por defecto: el SII no publica un web service oficial del RCV; se usan los
     * servicios de su sitio web con sesión por certificado. Actívelo con enableRcvService().
     */
    public function getRcvService(): RcvService
    {
        if (!$this->rcvEnabled) {
            throw new InvalidArgumentException('RcvService está desactivado: usa los servicios internos del sitio web del SII (no hay web service oficial del RCV) y puede dejar de funcionar si el SII los cambia. Actívelo explícitamente con enableRcvService() o SeeConfig::withRcvService().');
        }

        return $this->rcvService ??= new RcvService($this->certificate, $this->httpClient, $this->logger);
    }

    /** Habilita el timbraje automatizado en el sitio web del SII (SiiFolioService). */
    public function enableFolioService(bool $enabled = true): self
    {
        $this->folioServiceEnabled = $enabled;

        return $this;
    }

    /**
     * Timbraje electrónico en el sitio del SII (solicitar, consultar y reobtener CAF).
     *
     * EXPERIMENTAL y desactivado por defecto: el SII no ofrece web service para esto; se automatizan
     * los formularios web, que pueden cambiar sin aviso. Actívelo con enableFolioService().
     */
    public function getFolioService(): SiiFolioService
    {
        if (!$this->folioServiceEnabled) {
            throw new InvalidArgumentException('SiiFolioService está desactivado: automatiza el formulario web de timbraje del SII (no hay web service oficial) y puede dejar de funcionar si el SII cambia el sitio. Actívelo explícitamente con enableFolioService() o SeeConfig::withFolioService().');
        }

        return $this->folioService ??= new SiiFolioService($this->certificate, $this->environment, $this->httpClient, $this->logger);
    }

    /** XML del DTE firmado (ISO-8859-1), para almacenar o enviar al receptor. */
    public function getXmlSigned(AbstractDocument $document): string
    {
        return $this->prepare($document)->xml;
    }

    /**
     * Sobre de envío firmado (EnvioDTE o EnvioBOLETA).
     *
     * @param list<AbstractDocument|PreparedDocument> $documents
     * @param string                                  $rutReceptor RUT receptor del sobre: el SII (por defecto) o el receptor del DTE (intercambio)
     */
    public function getEnvelopeXml(array $documents, string $rutReceptor = Rut::SII): string
    {
        return $this->emitter->envelope($this->prepareAll($documents), $this->requireResolution(), $this->rutEnvia, $rutReceptor);
    }

    /** Emite y envía un documento al SII. */
    public function send(AbstractDocument|PreparedDocument $document): SendResult
    {
        return $this->sendBatch([$document]);
    }

    /**
     * Emite y envía varios documentos en un mismo sobre (todos boletas o todos DTE).
     *
     * @param list<AbstractDocument|PreparedDocument> $documents
     */
    public function sendBatch(array $documents): SendResult
    {
        $prepared = $this->prepareAll($documents);
        $xml = $this->emitter->envelope($prepared, $this->requireResolution(), $this->rutEnvia);
        $rutEmisor = (string) $prepared[0]->document->getEmisor()?->getRut();

        return $this->sendXml($xml, $rutEmisor, $prepared[0]->document instanceof Boleta);
    }

    /**
     * Envía un sobre ya firmado (por ejemplo, para reenviar uno almacenado tras un FAU).
     *
     * @throws UploadUncertainException si se cortó la comunicación durante la subida (ver getState())
     */
    public function sendXml(string $envelopeXml, string $rutEmisor, ?bool $boleta = null): SendResult
    {
        $boleta ??= str_contains($envelopeXml, '<EnvioBOLETA');
        $rutEnvia = $this->rutEnvia ?? $this->certificate->getRut();
        if ($rutEnvia === null) {
            throw new InvalidArgumentException('No se pudo obtener el RUT del titular del certificado; indíquelo con setRutEnvia().');
        }
        $documents = self::documentRefs($envelopeXml);

        try {
            $result = $boleta
                ? $this->getBoletaService()->upload($envelopeXml, $rutEnvia, $rutEmisor)
                : $this->getDteService()->upload($envelopeXml, $rutEnvia, $rutEmisor);
        } catch (UploadUncertainException $e) {
            $pending = new SendResult(false, null, null, $e->getMessage(), $envelopeXml, '', (string) $e->getResponseBody(),
                rutEmisor: Rut::normalize($rutEmisor), rutEnvia: Rut::normalize($rutEnvia), boleta: $boleta, documents: $documents,
                environment: $this->environment->value, sentAt: ($this->clock)());
            throw $e->withState(['incierto' => true] + $pending->toArray());
        }

        return $result->withContext($documents, $this->environment->value, ($this->clock)());
    }

    /** Estado de un envío por Track ID. */
    public function getStatus(string $trackId, ?string $rutEmisor = null, bool $boleta = false): EnvioStatus
    {
        $rutEmisor ??= $this->company?->getRut() ?? throw new InvalidArgumentException('Indique el RUT del emisor.');

        return $boleta
            ? $this->getBoletaService()->getEnvioStatus($trackId, $rutEmisor)
            : $this->getDteService()->getEnvioStatus($trackId, $rutEmisor);
    }

    /**
     * Consulta el estado de un envío guardado con SendResult::toArray() (por ejemplo, desde un cron),
     * sin rearmar ni volver a firmar los documentos.
     *
     * @param array<string, mixed> $estado
     */
    public function resumeStatus(array $estado): EnvioStatus
    {
        $result = SendResult::fromArray($estado);
        $this->assertSameEnvironment($result);
        if ($result->trackId === null) {
            throw new InvalidArgumentException('El envío no tiene TrackID (la subida falló o quedó incierta): use reconcile() para consultar cada documento.');
        }

        return $this->getStatus($result->trackId, (string) $result->rutEmisor, $result->boleta);
    }

    /**
     * Consulta cada documento de un envío guardado por tipo, folio, RUT, monto y fecha. Sirve para
     * reconciliar una subida incierta (UploadUncertainException::getState()) sin reutilizar folios:
     *  - isReceived(): el SII tiene el documento (el folio está usado);
     *  - isPending() (FAU): el SII no lo recibió; se puede reenviar el mismo sobre con sendXml().
     *
     * @param array<string, mixed> $estado
     *
     * @return array<string, DteStatus> indexado por ID ("T33F125")
     */
    public function reconcile(array $estado): array
    {
        $result = SendResult::fromArray($estado);
        $this->assertSameEnvironment($result);
        $out = [];
        foreach ($result->documents as $ref) {
            $out[$ref->getId()] = $this->getDocumentStatusFor($ref, (string) $result->rutEmisor, $result->boleta);
        }

        return $out;
    }

    /** Estado de un documento emitido (si fue recibido por el SII y sus datos coinciden). */
    public function getDocumentStatus(AbstractDocument|PreparedDocument $document): DteStatus
    {
        $doc = $document instanceof PreparedDocument ? $document->document : $this->withCompany($document);
        $total = $document instanceof PreparedDocument ? $document->getMontoTotal() : (new TotalsCalculator())->calculate($doc)->totales->montoTotal;
        $fecha = $doc->getFechaEmision() ?? throw new InvalidArgumentException('El documento no tiene fecha de emisión.');
        $ref = new DocumentRef($doc->getTipo()->value, (int) $doc->getFolio(), $fecha->format('Y-m-d'), $doc->getReceptor()?->getRut() ?? Rut::CONSUMIDOR_FINAL, $total);

        return $this->getDocumentStatusFor($ref, (string) $doc->getEmisor()?->getRut(), $doc instanceof Boleta);
    }

    /** Estado de un documento a partir de sus datos (tipo, folio, fecha, RUT receptor y monto). */
    public function getDocumentStatusFor(DocumentRef $ref, string $rutEmisor, ?bool $boleta = null): DteStatus
    {
        $boleta ??= \in_array($ref->tipoDte, [39, 41], true);
        $monto = (int) round((float) $ref->montoTotal);
        if ($boleta) {
            return $this->getBoletaService()->getBoletaStatus($rutEmisor, $ref->tipoDte, $ref->folio, $ref->rutReceptor, $monto, $ref->getFechaEmision());
        }

        return $this->getDteService()->getDteStatus(
            (string) ($this->rutEnvia ?? $this->certificate->getRut()),
            $rutEmisor,
            $ref->rutReceptor,
            $ref->tipoDte,
            $ref->folio,
            $ref->getFechaEmision(),
            $monto,
        );
    }

    private function assertSameEnvironment(SendResult $result): void
    {
        if ($result->environment !== null && $result->environment !== $this->environment->value) {
            throw new InvalidArgumentException(sprintf('El envío se hizo en el ambiente "%s" y esta instancia está en "%s".', $result->environment, $this->environment->value));
        }
    }

    /**
     * Documentos contenidos en un sobre firmado.
     *
     * @return list<DocumentRef>
     */
    private static function documentRefs(string $envelopeXml): array
    {
        $doc = XmlUtil::load($envelopeXml);
        $xpath = XmlUtil::xpath($doc);
        $refs = [];
        foreach (XmlUtil::elements($xpath, '//sii:SetDTE/sii:DTE/*[@ID]') as $node) {
            $v = static fn (string $path): string => trim((string) $xpath->evaluate('string('.$path.')', $node));
            $monto = $v('sii:Encabezado/sii:Totales/sii:MntTotal');
            $refs[] = new DocumentRef(
                (int) $v('sii:Encabezado/sii:IdDoc/sii:TipoDTE'),
                (int) $v('sii:Encabezado/sii:IdDoc/sii:Folio'),
                $v('sii:Encabezado/sii:IdDoc/sii:FchEmis'),
                $v('sii:Encabezado/sii:Receptor/sii:RUTRecep'),
                str_contains($monto, '.') ? (float) $monto : (int) $monto,
            );
        }

        return $refs;
    }

    public function getEmitter(): Emitter
    {
        return $this->emitter;
    }

    public function getDteService(): DteService
    {
        return $this->dteService ??= new DteService($this->http, $this->soap, $this->dteTokenProvider(), $this->endpoints);
    }

    public function getBoletaService(): BoletaService
    {
        return $this->boletaService ??= new BoletaService(
            $this->http,
            new BoletaTokenProvider($this->certificate, $this->environment, $this->http, $this->endpoints, $this->tokenStorage, $this->tokenTtl),
            $this->endpoints,
        );
    }

    /** Registro de aceptación/reclamo de documentos recibidos de proveedores. */
    public function getRegistroReclamo(): RegistroReclamoService
    {
        return $this->reclamoService ??= new RegistroReclamoService($this->soap, $this->dteTokenProvider(), $this->endpoints);
    }

    private function dteTokenProvider(): DteTokenProvider
    {
        return new DteTokenProvider($this->certificate, $this->environment, $this->soap, $this->endpoints, $this->tokenStorage, $this->tokenTtl);
    }

    /**
     * @param list<AbstractDocument|PreparedDocument> $documents
     *
     * @return list<PreparedDocument>
     */
    private function prepareAll(array $documents): array
    {
        if ($documents === []) {
            throw new InvalidArgumentException('Debe indicar al menos un documento.');
        }

        return array_values(array_map(fn ($d) => $d instanceof PreparedDocument ? $d : $this->prepare($d), $documents));
    }

    private function withCompany(AbstractDocument $document): AbstractDocument
    {
        if ($document->getEmisor() === null && $this->company !== null) {
            $document->setEmisor($this->company);
        }

        return $document;
    }

    private function requireResolution(): Resolution
    {
        return $this->resolution ?? throw new InvalidArgumentException('Debe configurar la resolución del SII (fecha y número) con setResolution().');
    }
}
