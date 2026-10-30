<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii;

use Psr\Log\LoggerInterface;
use RoyaltyFusion\Sii\Caf\CafRepositoryInterface;
use RoyaltyFusion\Sii\Certificate\Certificate;
use RoyaltyFusion\Sii\Folio\FolioStoreInterface;
use RoyaltyFusion\Sii\Model\Company;
use RoyaltyFusion\Sii\Model\Resolution;

/**
 * Configuración de una empresa (tenant) para SeeFactory::create(). Inmutable: cada with*() retorna una
 * copia. No usa estado global: el certificado puede venir de la base de datos (contenido PKCS#12 o PEM).
 *
 *   $config = SeeConfig::fromPkcs12($pfxDesdeBd, $clave)
 *       ->withEnvironment(Environment::PRODUCCION)
 *       ->withResolution(new Resolution('2023-03-01', 80))
 *       ->withCafRepository(new PdoCafRepository($pdo, 'tenant42'))
 *       ->withFolioStore(new PdoFolioStore($pdo, 'tenant42'));
 *   $see = $factory->create($config);
 */
final class SeeConfig
{
    private ?Environment $environment = null;
    private ?Resolution $resolution = null;
    private ?FolioStoreInterface $folioStore = null;
    private ?CafRepositoryInterface $cafRepository = null;
    /** @var (\Closure(): \DateTimeInterface)|null */
    private ?\Closure $clock = null;
    private ?LoggerInterface $logger = null;
    private ?Company $company = null;
    private ?string $rutEnvia = null;
    private ?string $oficinaSii = null;
    private bool $folioService = false;
    /** @var list<string> */
    private array $secrets = [];

    private function __construct(private readonly Certificate $certificate)
    {
    }

    public static function create(Certificate $certificate): self
    {
        return new self($certificate);
    }

    /** Certificado desde el contenido binario de un .pfx/.p12 (por ejemplo, leído de la base de datos). */
    public static function fromPkcs12(string $content, string $password): self
    {
        $config = new self(Certificate::fromPkcs12($content, $password));
        $config->secrets[] = $password;

        return $config;
    }

    /** Certificado desde PEM (certificado y clave privada, que pueden venir en el mismo texto). */
    public static function fromPem(string $certificatePem, string $privateKeyPem, ?string $passphrase = null): self
    {
        $config = new self(Certificate::fromPem($certificatePem, $privateKeyPem, $passphrase));
        if ($passphrase !== null) {
            $config->secrets[] = $passphrase;
        }

        return $config;
    }

    public static function fromFile(string $path, string $password): self
    {
        $config = new self(Certificate::fromFile($path, $password));
        $config->secrets[] = $password;

        return $config;
    }

    public function withEnvironment(Environment $environment): self
    {
        $c = clone $this;
        $c->environment = $environment;

        return $c;
    }

    public function withResolution(Resolution $resolution): self
    {
        $c = clone $this;
        $c->resolution = $resolution;

        return $c;
    }

    /** Registro de folios de ESTA empresa (no se comparte entre empresas). */
    public function withFolioStore(FolioStoreInterface $folioStore): self
    {
        $c = clone $this;
        $c->folioStore = $folioStore;

        return $c;
    }

    public function withCafRepository(CafRepositoryInterface $cafRepository): self
    {
        $c = clone $this;
        $c->cafRepository = $cafRepository;

        return $c;
    }

    /**
     * Reloj inyectable (la hora que retorne se convierte siempre a hora de Chile).
     *
     * @param \Closure(): \DateTimeInterface $clock
     */
    public function withClock(\Closure $clock): self
    {
        $c = clone $this;
        $c->clock = $clock;

        return $c;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $c = clone $this;
        $c->logger = $logger;

        return $c;
    }

    /** Emisor por defecto de los documentos. */
    public function withCompany(Company $company): self
    {
        $c = clone $this;
        $c->company = $company;

        return $c;
    }

    public function withRutEnvia(string $rutEnvia): self
    {
        $c = clone $this;
        $c->rutEnvia = $rutEnvia;

        return $c;
    }

    public function withOficinaSii(string $oficinaSii): self
    {
        $c = clone $this;
        $c->oficinaSii = $oficinaSii;

        return $c;
    }

    /** Habilita SiiFolioService (timbraje automatizando el formulario web del SII). Desactivado por defecto. */
    public function withFolioService(bool $enabled = true): self
    {
        $c = clone $this;
        $c->folioService = $enabled;

        return $c;
    }

    public function getCertificate(): Certificate
    {
        return $this->certificate;
    }

    public function getEnvironment(): ?Environment
    {
        return $this->environment;
    }

    public function getResolution(): ?Resolution
    {
        return $this->resolution;
    }

    public function getFolioStore(): ?FolioStoreInterface
    {
        return $this->folioStore;
    }

    public function getCafRepository(): ?CafRepositoryInterface
    {
        return $this->cafRepository;
    }

    /** @return (\Closure(): \DateTimeInterface)|null */
    public function getClock(): ?\Closure
    {
        return $this->clock;
    }

    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function getRutEnvia(): ?string
    {
        return $this->rutEnvia;
    }

    public function getOficinaSii(): ?string
    {
        return $this->oficinaSii;
    }

    public function isFolioServiceEnabled(): bool
    {
        return $this->folioService;
    }

    /**
     * Valores que nunca deben aparecer en logs (contraseña del certificado).
     *
     * @return list<string>
     */
    public function getSecrets(): array
    {
        return $this->secrets;
    }
}
