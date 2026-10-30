<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Validator;

/** Conjunto de errores y advertencias de una validación. */
final class ValidationResult implements \Countable, \IteratorAggregate
{
    /** @var list<Violation> */
    private array $violations = [];

    /** @param list<Violation> $violations */
    public function __construct(array $violations = [])
    {
        foreach ($violations as $violation) {
            $this->add($violation);
        }
    }

    public function add(Violation $violation): self
    {
        $this->violations[] = $violation;

        return $this;
    }

    public function error(string $path, string $message, string $code): self
    {
        return $this->add(new Violation($path, $message, $code, Severity::ERROR));
    }

    public function warning(string $path, string $message, string $code): self
    {
        return $this->add(new Violation($path, $message, $code, Severity::WARNING));
    }

    public function merge(self $other, string $prefix = ''): self
    {
        foreach ($other->violations as $v) {
            $this->add($prefix === '' ? $v : new Violation($prefix.$v->path, $v->message, $v->code, $v->severity));
        }

        return $this;
    }

    public function isValid(): bool
    {
        return $this->getErrors() === [];
    }

    public function hasWarnings(): bool
    {
        return $this->getWarnings() !== [];
    }

    /** @return list<Violation> */
    public function all(): array
    {
        return $this->violations;
    }

    /** @return list<Violation> */
    public function getErrors(): array
    {
        return array_values(array_filter($this->violations, static fn (Violation $v) => $v->isError()));
    }

    /** @return list<Violation> */
    public function getWarnings(): array
    {
        return array_values(array_filter($this->violations, static fn (Violation $v) => !$v->isError()));
    }

    public function count(): int
    {
        return \count($this->violations);
    }

    /** @return \ArrayIterator<int, Violation> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->violations);
    }

    public function __toString(): string
    {
        return implode("\n", array_map('strval', $this->violations));
    }

    /** @return list<array{path: string, message: string, code: string, severity: string}> */
    public function toArray(): array
    {
        return array_map(static fn (Violation $v) => $v->toArray(), $this->violations);
    }
}
