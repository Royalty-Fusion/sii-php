<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Report;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\Report\HtmlReport;
use RoyaltyFusion\Sii\Report\TimbreGenerator;
use RoyaltyFusion\Sii\Tests\Documents;
use RoyaltyFusion\Sii\Tests\Xml\EmissionTest;

final class HtmlReportTest extends TestCase
{
    public function testRepresentacionImpresa(): void
    {
        $prepared = EmissionTest::emitter()->prepare(Documents::factura());
        $html = (new HtmlReport())->render($prepared, [
            'resolucion' => new Resolution('2026-10-01', 0),
            'unidad_sii' => 'Santiago Oriente',
            'cedible' => true,
        ]);
        self::assertStringContainsString('R.U.T.: 76.192.083-9', $html);
        self::assertStringContainsString('FACTURA ELECTRÓNICA', $html);
        self::assertStringContainsString('N° 1', $html);
        self::assertStringContainsString('S.I.I. - SANTIAGO ORIENTE', $html);
        self::assertStringContainsString('$ 1.072.231', $html);
        self::assertStringContainsString('Res. 0 de 2026', $html);
        self::assertStringContainsString('data:image/png;base64,', $html);
        self::assertStringContainsString('CEDIBLE', $html);
    }

    public function testTimbrePdf417(): void
    {
        $prepared = EmissionTest::emitter()->prepare(Documents::boleta());
        $png = (new TimbreGenerator())->png((string) $prepared->getTed());
        self::assertStringStartsWith("\x89PNG", $png);
        $info = getimagesizefromstring($png);
        self::assertNotFalse($info);
        self::assertGreaterThan($info[1], $info[0], 'El PDF417 debe ser más ancho que alto');
    }
}
