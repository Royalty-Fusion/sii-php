<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Xml\Builder;

use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\Util\Rut;
use RoyaltyFusion\Sii\Util\Text;
use RoyaltyFusion\Sii\Xml\XmlElement;
use RoyaltyFusion\Sii\Xml\XmlUtil;

/**
 * Sobre de envío EnvioDTE (EnvioDTE_v10.xsd) o EnvioBOLETA (EnvioBOLETA_v11.xsd), sin firmar.
 *
 * Los DTE ya firmados se insertan como texto para preservar sus bytes (y por tanto su firma).
 */
final class EnvelopeBuilder
{
    public const SET_ID = 'SetDoc';

    /**
     * @param list<string>    $signedDtes   DTE firmados (ISO-8859-1)
     * @param array<int, int> $subtotales   tipo DTE => cantidad
     */
    public function build(
        array $signedDtes,
        array $subtotales,
        string $rutEmisor,
        string $rutEnvia,
        Resolution $resolution,
        \DateTimeInterface $timestamp,
        bool $boleta = false,
        string $rutReceptor = Rut::SII,
    ): string {
        $root = $boleta ? 'EnvioBOLETA' : 'EnvioDTE';
        $schema = $boleta ? 'EnvioBOLETA_v11.xsd' : 'EnvioDTE_v10.xsd';

        ksort($subtotales);
        $caratula = XmlElement::create('Caratula', ['version' => '1.0'])
            ->leaf('RutEmisor', Rut::normalize($rutEmisor))
            ->leaf('RutEnvia', Rut::normalize($rutEnvia))
            ->leaf('RutReceptor', Rut::normalize($rutReceptor))
            ->leaf('FchResol', $resolution->fecha->format('Y-m-d'))
            ->leaf('NroResol', $resolution->numero)
            ->leaf('TmstFirmaEnv', $timestamp->format('Y-m-d\TH:i:s'));
        foreach ($subtotales as $tipo => $cantidad) {
            $caratula->child('SubTotDTE')->leaf('TpoDTE', $tipo)->leaf('NroDTE', $cantidad);
        }

        $dtes = '';
        foreach ($signedDtes as $dte) {
            $dtes .= XmlUtil::stripDeclaration($dte);
        }

        $head = XmlUtil::LATIN1_HEADER."\n"
            .'<'.$root.' xmlns="'.XmlUtil::NS_SII_DTE.'" xmlns:xsi="'.XmlUtil::NS_XSI.'" xsi:schemaLocation="'.XmlUtil::NS_SII_DTE.' '.$schema.'" version="1.0">'
            .'<SetDTE ID="'.self::SET_ID.'">'.Text::toLatin1($caratula->render());

        return $head.$dtes.'</SetDTE></'.$root.'>';
    }
}
