<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Xml;

use RoyaltyFusion\Sii\Util\Text;

/**
 * Nodo XML mínimo que se serializa de forma compacta y determinista.
 *
 * Se usa en lugar de DOMDocument::saveXML() para controlar exactamente los bytes que se firman
 * (TED y XMLDSig): el escape de caracteres, el orden de los nodos y la ausencia de espacios.
 */
final class XmlElement
{
    /** @var list<XmlElement|RawXml|string> */
    private array $children = [];

    /**
     * @param array<string, string|int|float|null> $attributes
     */
    public function __construct(
        public readonly string $name,
        private array $attributes = [],
    ) {
    }

    /**
     * @param array<string, string|int|float|null> $attributes
     */
    public static function create(string $name, array $attributes = []): self
    {
        return new self($name, $attributes);
    }

    public function attr(string $name, string|int|float|null $value): self
    {
        $this->attributes[$name] = $value;

        return $this;
    }

    /** Agrega un hijo con contenido de texto. Si $value es null o '' no se agrega nada. */
    public function leaf(string $name, string|int|float|null $value): self
    {
        if ($value === null || $value === '') {
            return $this;
        }
        $child = new self($name);
        $child->children[] = \is_string($value) ? $value : (string) $value;
        $this->children[] = $child;

        return $this;
    }

    /**
     * Agrega un hijo y lo retorna para seguir construyendo dentro de él.
     *
     * @param array<string, string|int|float|null> $attributes
     */
    public function child(string $name, array $attributes = []): self
    {
        $child = new self($name, $attributes);
        $this->children[] = $child;

        return $child;
    }

    public function append(self|RawXml $node): self
    {
        $this->children[] = $node;

        return $this;
    }

    public function text(string $value): self
    {
        $this->children[] = $value;

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->children === [];
    }

    /** Elimina los hijos vacíos (sin texto ni elementos). */
    public function prune(): self
    {
        $this->children = array_values(array_filter(
            $this->children,
            static fn ($c) => !$c instanceof self || !$c->prune()->isEmpty() || $c->attributes !== [],
        ));

        return $this;
    }

    /** Serializa en UTF-8 sin espacios entre nodos. */
    public function render(): string
    {
        $xml = '<'.$this->name;
        foreach ($this->attributes as $name => $value) {
            if ($value === null) {
                continue;
            }
            $xml .= ' '.$name.'="'.Text::escape((string) $value).'"';
        }
        if ($this->children === []) {
            return $xml.'/>';
        }
        $xml .= '>';
        foreach ($this->children as $child) {
            $xml .= match (true) {
                $child instanceof self => $child->render(),
                $child instanceof RawXml => $child->xml,
                default => Text::escape($child),
            };
        }

        return $xml.'</'.$this->name.'>';
    }
}
