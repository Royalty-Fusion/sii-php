<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws\Portal;

/** Formulario HTML parseado desde una página del sitio del SII. */
final class HtmlForm
{
    /**
     * @param array<string, string>               $fields  campos (hidden, text, select, radio/checkbox marcados)
     * @param list<array{name: string, value: string}> $buttons botones submit con nombre
     * @param array<string, list<string>>         $options opciones de cada select
     */
    public function __construct(
        public readonly string $action,
        public readonly string $method,
        public array $fields,
        public readonly array $buttons = [],
        public readonly array $options = [],
    ) {
    }

    public function has(string $field): bool
    {
        return \array_key_exists($field, $this->fields) || \array_key_exists($field, $this->options);
    }

    public function set(string $field, string|int $value): self
    {
        $this->fields[$field] = (string) $value;

        return $this;
    }

    public function get(string $field): ?string
    {
        return $this->fields[$field] ?? null;
    }

    /**
     * Botón a usar para avanzar en el flujo (evita "Volver", "Cancelar", etc.).
     *
     * @return array{name: string, value: string}|null
     */
    public function primaryButton(): ?array
    {
        foreach ($this->buttons as $button) {
            if (preg_match('/volver|cancel|salir|anterior|limpiar/i', $button['value'].' '.$button['name']) !== 1) {
                return $button;
            }
        }

        return null;
    }

    /**
     * Extrae todos los formularios de una página (UTF-8).
     *
     * @return list<self>
     */
    public static function parseAll(string $html, string $baseUrl): array
    {
        if (trim($html) === '') {
            return [];
        }
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, \LIBXML_NONET | \LIBXML_NOWARNING | \LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($doc);

        $forms = [];
        foreach ($xpath->query('//form') ?: [] as $form) {
            if (!$form instanceof \DOMElement) {
                continue;
            }
            $fields = [];
            $buttons = [];
            $options = [];
            foreach ($xpath->query('.//input|.//select|.//textarea', $form) ?: [] as $input) {
                if (!$input instanceof \DOMElement || ($name = $input->getAttribute('name')) === '') {
                    continue;
                }
                $tag = strtolower($input->tagName);
                $type = strtolower($input->getAttribute('type') ?: 'text');
                if ($tag === 'select') {
                    $values = [];
                    $selected = null;
                    foreach ($xpath->query('.//option', $input) ?: [] as $option) {
                        if ($option instanceof \DOMElement) {
                            $value = $option->hasAttribute('value') ? $option->getAttribute('value') : trim($option->textContent);
                            $values[] = $value;
                            if ($option->hasAttribute('selected')) {
                                $selected = $value;
                            }
                        }
                    }
                    $options[$name] = $values;
                    $fields[$name] = $selected ?? ($values[0] ?? '');
                } elseif ($tag === 'textarea') {
                    $fields[$name] = $input->textContent;
                } elseif (\in_array($type, ['submit', 'image'], true)) {
                    $buttons[] = ['name' => $name, 'value' => $input->getAttribute('value')];
                } elseif (\in_array($type, ['radio', 'checkbox'], true)) {
                    if ($input->hasAttribute('checked')) {
                        $fields[$name] = $input->getAttribute('value') ?: 'on';
                    }
                } elseif ($type !== 'button' && $type !== 'reset') {
                    $fields[$name] = $input->getAttribute('value');
                }
            }
            $forms[] = new self(
                self::resolve($baseUrl, $form->getAttribute('action')),
                strtoupper($form->getAttribute('method') ?: 'GET'),
                $fields,
                $buttons,
                $options,
            );
        }

        return $forms;
    }

    /** Texto visible de una página (para mensajes de error). */
    public static function text(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html);

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));
    }

    public static function resolve(string $base, string $action): string
    {
        $action = trim($action);
        if ($action === '') {
            return $base;
        }
        if (preg_match('#^https?://#i', $action)) {
            return $action;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (str_starts_with($action, '/')) {
            return $origin.$action;
        }
        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin.$dir.$action;
    }
}
