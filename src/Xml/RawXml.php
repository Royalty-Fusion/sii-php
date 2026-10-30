<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Xml;

/** Fragmento XML (UTF-8) que se inserta tal cual, sin escapar. */
final class RawXml
{
    public function __construct(public readonly string $xml)
    {
    }
}
