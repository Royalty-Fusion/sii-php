<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Util;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/** Logger PSR-3 que pasa cada mensaje y su contexto por el Redactor antes de delegar. */
final class RedactingLogger extends AbstractLogger
{
    public function __construct(
        private readonly LoggerInterface $inner,
        private readonly Redactor $redactor = new Redactor(),
        /** Largo máximo de cada valor del contexto (se trunca DESPUÉS de redactar). */
        private readonly int $maxLength = 4000,
    ) {
    }

    public function getRedactor(): Redactor
    {
        return $this->redactor;
    }

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $context = $this->redactor->redactContext($context);
        foreach ($context as $key => $value) {
            if (\is_string($value) && mb_strlen($value) > $this->maxLength) {
                $context[$key] = mb_substr($value, 0, $this->maxLength).'…';
            }
        }
        $this->inner->log($level, $this->redactor->redact((string) $message), $context);
    }
}
