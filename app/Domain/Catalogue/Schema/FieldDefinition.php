<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Schema;

use JsonSerializable;

/**
 * One field a storefront expects on a product.
 *
 * The whole point of the schema-driver design: the product form, the listing
 * screen and the sync code deal only in these, never in a platform's own field
 * names. Adding a platform is adding a driver — nothing in the form, the
 * database or the UI changes, because none of them ever knew what a Shopify
 * product looked like.
 */
final readonly class FieldDefinition implements JsonSerializable
{
    public function __construct(
        /** The platform's own key — what goes on the wire. */
        public string $key,
        public string $label,
        /** text|textarea|number|money|boolean|select|multiselect|date|html|image */
        public string $type = 'text',
        public bool $required = false,
        /** @var list<array{value: string, label: string}> */
        public array $options = [],
        public mixed $default = null,
        public ?string $hint = null,
        public ?string $group = null,
        /**
         * The Prism field this maps to, where there is an obvious one — 'name',
         * 'description', 'price', 'sku'. Set means the value is taken from the
         * product rather than typed again per storefront, which is the
         * difference between a listing screen with four fields and one with
         * forty.
         */
        public ?string $mapsTo = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            key: $data['key'],
            label: $data['label'] ?? ucfirst(str_replace(['_', '.'], ' ', $data['key'])),
            type: $data['type'] ?? 'text',
            required: (bool) ($data['required'] ?? false),
            options: $data['options'] ?? [],
            default: $data['default'] ?? null,
            hint: $data['hint'] ?? null,
            group: $data['group'] ?? null,
            mapsTo: $data['maps_to'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'options' => $this->options,
            'default' => $this->default,
            'hint' => $this->hint,
            'group' => $this->group,
            'maps_to' => $this->mapsTo,
        ];
    }
}
