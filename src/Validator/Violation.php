<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Validator;

/** Problema detectado al validar un documento. */
final class Violation implements \Stringable
{
    public function __construct(
        /** Ruta del campo con el problema, ej. "Encabezado.Receptor.RUTRecep" o "Detalle[2].NmbItem". */
        public readonly string $path,
        public readonly string $message,
        public readonly string $code,
        public readonly Severity $severity = Severity::ERROR,
    ) {
    }

    public function isError(): bool
    {
        return $this->severity === Severity::ERROR;
    }

    public function __toString(): string
    {
        return sprintf('[%s] %s: %s', $this->code, $this->path, $this->message);
    }

    /** @return array{path: string, message: string, code: string, severity: string} */
    public function toArray(): array
    {
        return ['path' => $this->path, 'message' => $this->message, 'code' => $this->code, 'severity' => $this->severity->value];
    }
}
