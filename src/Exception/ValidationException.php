<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Exception;

use RoyaltyFusion\Sii\Validator\ValidationResult;

/** El documento no pasó las validaciones previas al envío. */
class ValidationException extends \RuntimeException implements SiiException
{
    public function __construct(private readonly ValidationResult $result, string $context = 'El documento')
    {
        $errors = $result->getErrors();
        $lines = array_map(static fn ($v) => ' - '.$v, \array_slice($errors, 0, 20));
        if (\count($errors) > 20) {
            $lines[] = sprintf(' - ... y %d errores más', \count($errors) - 20);
        }
        parent::__construct(sprintf("%s tiene %d error(es) de validación:\n%s", $context, \count($errors), implode("\n", $lines)));
    }

    public function getResult(): ValidationResult
    {
        return $this->result;
    }
}
