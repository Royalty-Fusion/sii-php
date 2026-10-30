<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii;

use RoyaltyFusion\Sii\Caf\CafRepositoryInterface;
use RoyaltyFusion\Sii\Calculator\TotalsCalculator;
use RoyaltyFusion\Sii\Certificate\Certificate;
use RoyaltyFusion\Sii\Exception\SignatureException;
use RoyaltyFusion\Sii\Exception\ValidationException;
use RoyaltyFusion\Sii\Model\AbstractDocument;
use RoyaltyFusion\Sii\Model\Boleta;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\Util\Rut;
use RoyaltyFusion\Sii\Validator\DocumentValidator;
use RoyaltyFusion\Sii\Validator\ValidationResult;
use RoyaltyFusion\Sii\Validator\XsdValidator;
use RoyaltyFusion\Sii\Xml\Builder\BoletaXmlBuilder;
use RoyaltyFusion\Sii\Xml\Builder\DteXmlBuilder;
use RoyaltyFusion\Sii\Xml\Builder\EnvelopeBuilder;
use RoyaltyFusion\Sii\Xmldsig\XmlSignatureVerifier;
use RoyaltyFusion\Sii\Xmldsig\XmlSigner;

/**
 * Motor de emisión: valida, calcula, timbra (TED), firma (XMLDSig) y arma los sobres de envío.
 *
 * Cada paso se verifica antes de continuar:
 *  1. validaciones de negocio + CAF + certificado
 *  2. generación del XML y timbre
 *  3. firma del DTE y validación contra el XSD oficial
 *  4. verificación de la firma generada (digest y SignatureValue)
 */
final class Emitter
{
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    public function __construct(
        private readonly Certificate $certificate,
        private readonly CafRepositoryInterface $cafs,
        private readonly Environment $environment,
        private readonly TotalsCalculator $calculator = new TotalsCalculator(),
        private readonly DocumentValidator $validator = new DocumentValidator(),
        private readonly XsdValidator $xsd = new XsdValidator(),
        private readonly DteXmlBuilder $dteBuilder = new DteXmlBuilder(),
        private readonly BoletaXmlBuilder $boletaBuilder = new BoletaXmlBuilder(),
        private readonly EnvelopeBuilder $envelopeBuilder = new EnvelopeBuilder(),
        ?\Closure $clock = null,
    ) {
        $this->clock = Util\Clock::wrap($clock);
    }

    public function getCertificate(): Certificate
    {
        return $this->certificate;
    }

    public function getEnvironment(): Environment
    {
        return $this->environment;
    }

    /** Ejecuta sólo las validaciones (negocio, CAF y certificado) sin generar XML. */
    public function validate(AbstractDocument $document): ValidationResult
    {
        if ($document->getFechaEmision() === null) {
            // Sin fecha de emisión se usa la fecha actual de Chile (no la zona horaria del servidor).
            $document->setFechaEmision(Util\Clock::today(($this->clock)()));
        }
        $caf = $this->findCaf($document);
        $result = $this->validator->validate($document, $caf, $this->environment, true, ($this->clock)());
        $result->merge($this->validateCertificate());

        return $result;
    }

    /**
     * Valida, timbra y firma un documento.
     *
     * @throws ValidationException si el documento no cumple las reglas del SII o el XSD
     */
    public function prepare(AbstractDocument $document): PreparedDocument
    {
        $validation = $this->validate($document);
        if (!$validation->isValid()) {
            throw new ValidationException($validation, sprintf('El documento %s', $document->getId()));
        }
        $caf = $this->findCaf($document);
        \assert($caf !== null);

        $timestamp = ($this->clock)();
        $calculation = $this->calculator->calculate($document);
        $builder = $document instanceof Boleta ? $this->boletaBuilder : $this->dteBuilder;
        $unsigned = $builder->build($document, $calculation, $caf, $timestamp);
        $signed = (new XmlSigner($this->certificate))->signById($unsigned, $document->getId());

        $xsd = $document instanceof Boleta ? $this->validateBoletaXsd($signed, $document) : $this->xsd->validate($signed, 'DTE_v10.xsd');
        if (!$xsd->isValid()) {
            throw new ValidationException($xsd, sprintf('El XML del documento %s', $document->getId()));
        }
        $this->assertSignatures($signed, $document->getId());

        return new PreparedDocument($document, $calculation, $signed, $validation, $timestamp);
    }

    /**
     * Arma y firma el sobre EnvioDTE o EnvioBOLETA con los documentos preparados.
     *
     * @param list<PreparedDocument> $documents
     */
    public function envelope(array $documents, Resolution $resolution, ?string $rutEnvia = null, string $rutReceptor = Rut::SII): string
    {
        $result = $this->validateEnvelope($documents, $resolution, $rutEnvia, $rutReceptor);
        if (!$result->isValid()) {
            throw new ValidationException($result, 'El sobre de envío');
        }
        $boleta = $documents[0]->document instanceof Boleta;
        $subtotales = [];
        foreach ($documents as $prepared) {
            $tipo = $prepared->document->getTipo()->value;
            $subtotales[$tipo] = ($subtotales[$tipo] ?? 0) + 1;
        }

        $xml = $this->envelopeBuilder->build(
            array_map(static fn (PreparedDocument $p) => $p->xml, $documents),
            $subtotales,
            (string) $documents[0]->document->getEmisor()?->getRut(),
            (string) ($rutEnvia ?? $this->certificate->getRut()),
            $resolution,
            ($this->clock)(),
            $boleta,
            $rutReceptor,
        );
        $signed = (new XmlSigner($this->certificate))->signById($xml, EnvelopeBuilder::SET_ID);

        $xsd = $this->xsd->validate($signed);
        if (!$xsd->isValid()) {
            throw new ValidationException($xsd, 'El XML del sobre de envío');
        }
        $this->assertSignatures($signed, 'sobre de envío');

        return $signed;
    }

    /**
     * @param list<PreparedDocument> $documents
     */
    public function validateEnvelope(array $documents, Resolution $resolution, ?string $rutEnvia = null, string $rutReceptor = Rut::SII): ValidationResult
    {
        $result = new ValidationResult();
        if ($documents === []) {
            return $result->error('SetDTE', 'El sobre debe contener al menos un documento.', 'SOBRE_VACIO');
        }
        $boleta = $documents[0]->document instanceof Boleta;
        $max = $boleta ? 500 : 2000;
        if (\count($documents) > $max) {
            $result->error('SetDTE', sprintf('Un sobre admite como máximo %d documentos.', $max), 'SOBRE_MAXIMO');
        }
        $rutEmisor = $documents[0]->document->getEmisor()?->getRut();
        $vistos = [];
        $tipos = [];
        foreach ($documents as $i => $p) {
            $doc = $p->document;
            if (($doc instanceof Boleta) !== $boleta) {
                $result->error(sprintf('SetDTE.DTE[%d]', $i + 1), 'No se pueden mezclar boletas con otros DTE en un mismo sobre.', 'SOBRE_MIXTO');
            }
            $rut = $doc->getEmisor()?->getRut();
            if ($rut === null || $rutEmisor === null || !Rut::isValid($rut) || !Rut::parse($rut)->equals($rutEmisor)) {
                $result->error(sprintf('SetDTE.DTE[%d]', $i + 1), 'Todos los documentos del sobre deben ser del mismo emisor.', 'SOBRE_EMISOR');
            }
            $key = $doc->getId();
            if (isset($vistos[$key])) {
                $result->error(sprintf('SetDTE.DTE[%d]', $i + 1), sprintf('Documento %s duplicado en el sobre.', $key), 'SOBRE_DUPLICADO');
            }
            $vistos[$key] = true;
            $tipos[$doc->getTipo()->value] = true;
        }
        if (\count($tipos) > ($boleta ? 2 : 20)) {
            $result->error('Caratula.SubTotDTE', 'Demasiados tipos de documento distintos en el sobre.', 'SOBRE_TIPOS');
        }
        $rutEnvia ??= $this->certificate->getRut();
        if ($rutEnvia === null || !Rut::isValid($rutEnvia)) {
            $result->error('Caratula.RutEnvia', 'No se pudo determinar el RUT del firmante (indíquelo explícitamente).', 'RUT_ENVIA');
        }
        if (!Rut::isValid($rutReceptor)) {
            $result->error('Caratula.RutReceptor', 'RUT receptor del envío inválido.', 'RUT_INVALIDO');
        }
        if ($resolution->numero < 0 || $resolution->numero > 999_999) {
            $result->error('Caratula.NroResol', 'Número de resolución inválido.', 'RANGO');
        }
        if ($this->environment->isProduccion() && $resolution->numero === 0) {
            $result->error('Caratula.NroResol', 'En producción el número de resolución no puede ser 0.', 'RESOLUCION_PRODUCCION');
        } elseif (!$this->environment->isProduccion() && $resolution->numero !== 0) {
            $result->warning('Caratula.NroResol', 'En certificación el número de resolución normalmente es 0.', 'RESOLUCION_CERTIFICACION');
        }

        return $result->merge($this->validateCertificate());
    }

    public function findCafFor(AbstractDocument $document): ?Caf\Caf
    {
        return $this->findCaf($document);
    }

    private function findCaf(AbstractDocument $document): ?Caf\Caf
    {
        $rut = $document->getEmisor()?->getRut();
        if ($rut === null || $document->getFolio() === null) {
            return null;
        }

        return $this->cafs->find($rut, $document->getTipo()->value, $document->getFolio());
    }

    private function validateCertificate(): ValidationResult
    {
        $result = new ValidationResult();
        $now = ($this->clock)();
        if (!$this->certificate->isValidAt($now)) {
            $result->error('Certificado', sprintf('El certificado digital no está vigente (válido desde %s hasta %s).', $this->certificate->getValidFrom()->format('Y-m-d'), $this->certificate->getValidTo()->format('Y-m-d')), 'CERTIFICADO_VENCIDO');
        } elseif ($this->certificate->getDaysToExpire($now) <= 30) {
            $result->warning('Certificado', sprintf('El certificado digital vence en %d días.', $this->certificate->getDaysToExpire($now)), 'CERTIFICADO_POR_VENCER');
        }

        return $result;
    }

    /**
     * Las boletas no tienen un esquema propio para el DTE suelto: se validan dentro de un EnvioBOLETA temporal.
     */
    private function validateBoletaXsd(string $signed, Boleta $boleta): ValidationResult
    {
        $rutEnvia = $this->certificate->getRut() ?? (string) $boleta->getEmisor()?->getRut();
        $xml = $this->envelopeBuilder->build([$signed], [$boleta->getTipo()->value => 1], (string) $boleta->getEmisor()?->getRut(), $rutEnvia, new Resolution('2000-01-01', 0), ($this->clock)(), true);

        return $this->xsd->validate((new XmlSigner($this->certificate))->signById($xml, EnvelopeBuilder::SET_ID), 'EnvioBOLETA_v11.xsd');
    }

    private function assertSignatures(string $xml, string $context): void
    {
        foreach ((new XmlSignatureVerifier())->verifyAll($xml) as $check) {
            if (!$check->isValid()) {
                throw new SignatureException(sprintf('La firma generada para %s no es válida (%s): %s', $context, $check->referenceUri, $check->error));
            }
        }
    }
}
