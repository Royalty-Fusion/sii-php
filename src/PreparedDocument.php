<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii;

use RoyaltyFusion\Sii\Calculator\Calculation;
use RoyaltyFusion\Sii\Model\AbstractDocument;
use RoyaltyFusion\Sii\Validator\ValidationResult;

/** Documento validado, timbrado y firmado, listo para incluirse en un sobre de envío. */
final class PreparedDocument
{
    public function __construct(
        public readonly AbstractDocument $document,
        public readonly Calculation $calculation,
        /** XML firmado del DTE (ISO-8859-1). */
        public readonly string $xml,
        public readonly ValidationResult $validation,
        public readonly \DateTimeImmutable $timestamp,
    ) {
    }

    public function getXml(): string
    {
        return $this->xml;
    }

    /** XML firmado convertido a UTF-8 (para almacenar en el ERP o mostrar). */
    public function getXmlUtf8(): string
    {
        return Util\Text::toUtf8($this->xml);
    }

    /** Nodo TED (para generar el PDF417 de la representación impresa). */
    public function getTed(): ?string
    {
        $utf8 = $this->getXmlUtf8();

        return preg_match('#<TED version="1.0">.*?</TED>#s', $utf8, $m) ? $m[0] : null;
    }

    public function getMontoTotal(): float|int
    {
        return $this->calculation->totales->montoTotal;
    }
}
