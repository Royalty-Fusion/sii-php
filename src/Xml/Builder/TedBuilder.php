<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Xml\Builder;

use RoyaltyFusion\Sii\Caf\Caf;
use RoyaltyFusion\Sii\Util\Rut;
use RoyaltyFusion\Sii\Util\Text;
use RoyaltyFusion\Sii\Xml\RawXml;
use RoyaltyFusion\Sii\Xml\XmlElement;

/**
 * Timbre Electrónico DTE (TED).
 *
 * El nodo DD se serializa sin espacios entre elementos, se codifica en ISO-8859-1 y se firma con
 * SHA1withRSA usando la clave privada del CAF (RSASK). El resultado va en FRMT.
 */
final class TedBuilder
{
    public function build(
        Caf $caf,
        string $rutEmisor,
        int $tipoDte,
        int $folio,
        \DateTimeInterface $fechaEmision,
        string $rutReceptor,
        string $razonSocialReceptor,
        string $montoTotal,
        string $primerItem,
        \DateTimeInterface $timestamp,
    ): string {
        $dd = XmlElement::create('DD')
            ->leaf('RE', Rut::normalize($rutEmisor))
            ->leaf('TD', $tipoDte)
            ->leaf('F', $folio)
            ->leaf('FE', $fechaEmision->format('Y-m-d'))
            ->leaf('RR', Rut::normalize($rutReceptor))
            ->leaf('RSR', Text::truncate(Text::sanitize($razonSocialReceptor), 40))
            ->leaf('MNT', $montoTotal)
            ->leaf('IT1', Text::truncate(Text::sanitize($primerItem), 40))
            ->append(new RawXml($caf->getCafXml()))
            ->leaf('TSTED', $timestamp->format('Y-m-d\TH:i:s'));
        $ddXml = $dd->render();
        $frmt = base64_encode($caf->sign(Text::toLatin1($ddXml)));

        return '<TED version="1.0">'.$ddXml.'<FRMT algoritmo="SHA1withRSA">'.$frmt.'</FRMT></TED>';
    }

    /**
     * Verifica la firma FRMT de un TED (útil para validar DTE recibidos o el PDF417 impreso).
     */
    public static function verify(string $tedXml, Caf $caf): bool
    {
        if (!preg_match('#<DD>.*</DD>#s', $tedXml, $dd) || !preg_match('#<FRMT[^>]*>([^<]+)</FRMT>#', $tedXml, $frmt)) {
            return false;
        }
        $signature = base64_decode(trim($frmt[1]), true);
        $data = mb_check_encoding($dd[0], 'UTF-8') ? Text::toLatin1($dd[0]) : $dd[0];

        return $signature !== false && $caf->verify($data, $signature);
    }
}
