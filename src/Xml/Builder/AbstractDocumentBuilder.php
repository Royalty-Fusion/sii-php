<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Xml\Builder;

use RoyaltyFusion\Sii\Caf\Caf;
use RoyaltyFusion\Sii\Calculator\Calculation;
use RoyaltyFusion\Sii\Model\AbstractDocument;
use RoyaltyFusion\Sii\Util\Rut;
use RoyaltyFusion\Sii\Util\Text;
use RoyaltyFusion\Sii\Xml\RawXml;
use RoyaltyFusion\Sii\Xml\XmlElement;
use RoyaltyFusion\Sii\Xml\XmlUtil;

/** Base para generar el XML (sin firmar) de un DTE o boleta. */
abstract class AbstractDocumentBuilder
{
    public function __construct(protected readonly TedBuilder $tedBuilder = new TedBuilder())
    {
    }

    /**
     * Genera el XML del documento (ISO-8859-1, con declaración XML) con el TED y TmstFirma,
     * listo para ser firmado.
     */
    public function build(AbstractDocument $document, Calculation $calculation, Caf $caf, \DateTimeInterface $timestamp): string
    {
        $documento = $this->documento($document, $calculation);

        $receptor = $document->getReceptor();
        $primerItem = ($document->getDetalles()[0] ?? null)?->getNombre() ?? '';
        $ted = $this->tedBuilder->build(
            $caf,
            (string) $document->getEmisor()?->getRut(),
            $document->getTipo()->value,
            (int) $document->getFolio(),
            $document->getFechaEmision() ?? $timestamp,
            $receptor?->getRut() ?? Rut::CONSUMIDOR_FINAL,
            $this->razonSocialTimbre($document),
            $this->montoTimbre($calculation),
            $primerItem,
            $timestamp,
        );
        $documento->append(new RawXml($ted));
        $documento->leaf('TmstFirma', $timestamp->format('Y-m-d\TH:i:s'));

        $dte = XmlElement::create('DTE', ['xmlns' => XmlUtil::NS_SII_DTE, 'xmlns:xsi' => XmlUtil::NS_XSI, 'version' => '1.0'])
            ->append($documento);

        return XmlUtil::LATIN1_HEADER."\n".Text::toLatin1($dte->render());
    }

    abstract protected function documento(AbstractDocument $document, Calculation $calculation): XmlElement;

    protected function razonSocialTimbre(AbstractDocument $document): string
    {
        $razon = Text::sanitize($document->getReceptor()?->getRazonSocial());

        return $razon !== '' ? $razon : 'CONSUMIDOR FINAL';
    }

    protected function montoTimbre(Calculation $calculation): string
    {
        return Text::decimal($calculation->totales->montoTotal, $calculation->decimales);
    }

    protected static function txt(?string $value, int $max): ?string
    {
        $clean = Text::sanitize($value);

        return $clean === '' ? null : Text::truncate($clean, $max);
    }

    protected static function rut(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : Rut::normalize($value);
    }

    protected static function date(?\DateTimeInterface $date): ?string
    {
        return $date?->format('Y-m-d');
    }

    protected static function dec(float|int|null $value, int $decimals): ?string
    {
        return $value === null ? null : Text::decimal($value, $decimals);
    }

    /** Monto positivo o null (para campos MntImpType que no admiten 0). */
    protected static function positive(float|int|null $value): ?int
    {
        return $value === null || $value <= 0 ? null : (int) round((float) $value);
    }
}
