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
use RoyaltyFusion\Sii\Folio\FolioManager;
use RoyaltyFusion\Sii\Folio\FolioStoreInterface;
use RoyaltyFusion\Sii\Folio\InMemoryFolioStore;
use RoyaltyFusion\Sii\Folio\SiiFolioService;
use RoyaltyFusion\Sii\Model\AbstractDocument;
use RoyaltyFusion\Sii\Model\Boleta;
use RoyaltyFusion\Sii\Model\Company;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\Util\Rut;
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
    private ?DteService $dteService = null;
    private ?BoletaService $boletaService = null;
    private ?RegistroReclamoService $reclamoService = null;
    private ?FolioManager $folioManager = null;
    private ?SiiFolioService $folioService = null;
    private ?\Closure $clock;
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
        $this->clock = $clock;
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
     * Si el documento no tiene folio, se asigna el siguiente disponible (ver getFolioManager());
     * si la emisión falla, el folio se libera.
     */
    public function prepare(AbstractDocument $document): PreparedDocument
    {
        $document = $this->withCompany($document);
        if ($document->getFolio() !== null) {
            return $this->emitter->prepare($document);
        }
        $rut = $document->getEmisor()?->getRut() ?? throw new InvalidArgumentException('El documento no tiene emisor para asignar el folio.');
        $folio = $this->getFolioManager()->next($rut, $document->getTipo());
        $document->setFolio($folio);
        try {
            return $this->emitter->prepare($document);
        } catch (\Throwable $e) {
            $this->getFolioManager()->release($rut, $document->getTipo(), $folio);
            $document->setFolio(null);

            throw $e;
        }
    }

    /**
     * Registro de folios usados. Por defecto en memoria: en producción use FileFolioStore o
     * una implementación sobre la base de datos del ERP.
     */
    public function setFolioStore(FolioStoreInterface $store): self
    {
        $this->folioManager = new FolioManager($this->cafs, $store, $this->environment, $this->clock);

        return $this;
    }

    /** Asignación y consulta de folios disponibles en los CAF cargados. */
    public function getFolioManager(): FolioManager
    {
        return $this->folioManager ??= new FolioManager($this->cafs, new InMemoryFolioStore(), $this->environment, $this->clock);
    }

    /**
     * Timbraje electrónico en el sitio del SII (solicitar, consultar y reobtener CAF).
     *
     * EXPERIMENTAL: el SII no ofrece web service para esto; se automatizan los formularios web.
     */
    public function getFolioService(): SiiFolioService
    {
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

    /** Envía un sobre ya firmado (por ejemplo, para reenviar uno almacenado). */
    public function sendXml(string $envelopeXml, string $rutEmisor, ?bool $boleta = null): SendResult
    {
        $boleta ??= str_contains($envelopeXml, '<EnvioBOLETA');
        $rutEnvia = $this->rutEnvia ?? $this->certificate->getRut();
        if ($rutEnvia === null) {
            throw new InvalidArgumentException('No se pudo obtener el RUT del titular del certificado; indíquelo con setRutEnvia().');
        }

        return $boleta
            ? $this->getBoletaService()->upload($envelopeXml, $rutEnvia, $rutEmisor)
            : $this->getDteService()->upload($envelopeXml, $rutEnvia, $rutEmisor);
    }

    /** Estado de un envío por Track ID. */
    public function getStatus(string $trackId, ?string $rutEmisor = null, bool $boleta = false): EnvioStatus
    {
        $rutEmisor ??= $this->company?->getRut() ?? throw new InvalidArgumentException('Indique el RUT del emisor.');

        return $boleta
            ? $this->getBoletaService()->getEnvioStatus($trackId, $rutEmisor)
            : $this->getDteService()->getEnvioStatus($trackId, $rutEmisor);
    }

    /** Estado de un documento emitido (si fue recibido por el SII y sus datos coinciden). */
    public function getDocumentStatus(AbstractDocument|PreparedDocument $document): DteStatus
    {
        $doc = $document instanceof PreparedDocument ? $document->document : $this->withCompany($document);
        $total = $document instanceof PreparedDocument ? $document->getMontoTotal() : (new TotalsCalculator())->calculate($doc)->totales->montoTotal;
        $rutEmisor = (string) $doc->getEmisor()?->getRut();
        $rutReceptor = $doc->getReceptor()?->getRut() ?? Rut::CONSUMIDOR_FINAL;
        $fecha = $doc->getFechaEmision() ?? throw new InvalidArgumentException('El documento no tiene fecha de emisión.');

        if ($doc instanceof Boleta) {
            return $this->getBoletaService()->getBoletaStatus($rutEmisor, $doc->getTipo()->value, (int) $doc->getFolio(), $rutReceptor, (int) round((float) $total), $fecha);
        }

        return $this->getDteService()->getDteStatus(
            (string) ($this->rutEnvia ?? $this->certificate->getRut()),
            $rutEmisor,
            $rutReceptor,
            $doc->getTipo()->value,
            (int) $doc->getFolio(),
            $fecha,
            (int) round((float) $total),
        );
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
