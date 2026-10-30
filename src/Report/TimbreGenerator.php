<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Report;

use Com\Tecnick\Barcode\Barcode;
use RoyaltyFusion\Sii\Exception\InvalidArgumentException;
use RoyaltyFusion\Sii\Util\Text;

/**
 * Genera el código de barras PDF417 del Timbre Electrónico (TED), con nivel de corrección de errores 5
 * según lo exigido por el SII para la representación impresa. Requiere tecnickcom/tc-lib-barcode.
 */
final class TimbreGenerator
{
    public function __construct(
        private readonly int $errorCorrectionLevel = 5,
        private readonly float $aspectRatio = 3.0,
    ) {
    }

    public static function isAvailable(): bool
    {
        return class_exists(Barcode::class);
    }

    /** SVG del PDF417. $ted es el nodo <TED> (UTF-8 o ISO-8859-1). */
    public function svg(string $ted, int $width = -2, int $height = -2): string
    {
        return $this->barcode($ted, $width, $height)->getSvgCode();
    }

    /** PNG (binario) del PDF417. */
    public function png(string $ted, int $width = -2, int $height = -2): string
    {
        return $this->barcode($ted, $width, $height)->getPngData(false);
    }

    private function barcode(string $ted, int $width, int $height): \Com\Tecnick\Barcode\Model
    {
        if (!self::isAvailable()) {
            throw new InvalidArgumentException('Para generar el timbre PDF417 instale tecnickcom/tc-lib-barcode (composer require tecnickcom/tc-lib-barcode).');
        }
        $data = mb_check_encoding($ted, 'UTF-8') ? Text::toLatin1($ted) : $ted;

        return (new Barcode())->getBarcodeObj(
            sprintf('PDF417,%s,%d', $this->aspectRatio, $this->errorCorrectionLevel),
            $data,
            $width,
            $height,
            'black',
            [0, 0, 0, 0],
        );
    }
}
