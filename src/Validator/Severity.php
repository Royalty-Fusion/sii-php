<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Validator;

enum Severity: string
{
    /** Bloquea la emisión: el SII rechazaría el documento. */
    case ERROR = 'error';
    /** No bloquea, pero conviene revisarlo (posible reparo del SII). */
    case WARNING = 'warning';
}
