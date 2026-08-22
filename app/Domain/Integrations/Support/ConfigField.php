<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

/**
 * One thing a platform needs to know before it can be connected.
 *
 * ── Why a driver describes its form instead of drawing it ────────────────────
 *
 * WooCommerce wants a site URL, a consumer key and a secret. Shopify wants a
 * shop domain and an access token. The generic driver — the one that makes a
 * bespoke site connectable at all — wants a dozen fields including the paths to
 * its own endpoints.
 *
 * If each drew its own form, the settings screen would be a switch statement
 * over platforms and adding one would mean touching the interface. Described as
 * data, the screen renders whatever it is handed and never learns the platform
 * names at all.
 *
 * The other half is validation: a driver knows which of its fields are required
 * and which are secret, and saying so once here beats repeating it in a
 * validator that drifts.
 */
final readonly class ConfigField
{
    /**
     * @param  string  $key  where the value lands in configuration
     * @param  string  $label  what to call it on screen
     * @param  string  $type  'text' | 'url' | 'password' | 'select' | 'boolean'
     * @param  string|null  $help  the sentence that saves a support conversation
     * @param  list<array{value: string, label: string}>  $options  for 'select'
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'text',
        public bool $required = true,
        public ?string $help = null,
        public ?string $placeholder = null,
        public array $options = [],
        /**
         * A secret, and treated as one everywhere.
         *
         * Encrypted at rest, never returned to the client once saved, and shown
         * as a rotate control rather than a filled box. A screen that renders
         * somebody's API secret back to them has put it in a page cache, a
         * screenshot and a support ticket.
         */
        public bool $secret = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'help' => $this->help,
            'placeholder' => $this->placeholder,
            'options' => $this->options,
            'secret' => $this->secret,
        ];
    }

    /** The Laravel validation rule this field implies. */
    public function rule(): string
    {
        $rules = [$this->required ? 'required' : 'nullable'];

        $rules[] = match ($this->type) {
            'url' => 'url',
            'boolean' => 'boolean',
            default => 'string',
        };

        return implode('|', $rules);
    }
}
