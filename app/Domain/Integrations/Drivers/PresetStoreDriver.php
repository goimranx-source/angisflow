<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Drivers;

use App\Domain\Integrations\PlatformPresets;
use App\Domain\Integrations\Support\Capabilities;
use App\Domain\Integrations\Support\ConfigField;

/**
 * A platform that is known about, spoken to by the generic client.
 *
 * ── Why this is not thirty-two more driver classes ───────────────────────────
 *
 * Because almost all of what a driver does is the same everywhere: send a
 * request, read a page of records, write one back. What differs between one
 * shop platform and the next is a handful of facts — what it is called, what
 * credentials it wants, where its orders live, what its JSON looks like — and
 * those are data, not behaviour.
 *
 * Writing a class per platform would mean thirty-two near-identical files, and
 * in practice it would mean most of them never being written at all: a business
 * running a platform nobody here has heard of would be told its shop does not
 * exist. A preset costs an entry in a table.
 *
 * ── What it does not pretend ─────────────────────────────────────────────────
 *
 * A preset is a well-informed starting point, not a tested integration. It
 * carries the right credential form and a sensible guess at the paths, and it
 * says so: every platform declares how well it is supported and the form shows
 * it. Nothing here claims an API has been called.
 *
 * When one of these earns real use it gets a driver of its own and keeps its
 * key, so nothing a business configured has to be set up again.
 */
class PresetStoreDriver extends GenericRestDriver
{
    public function __construct(
        private readonly string $platform,
        private readonly string $name,
        private readonly string $auth,
        private readonly ?string $hint = null,
    ) {}

    public function key(): string
    {
        return $this->platform;
    }

    public function label(): string
    {
        return $this->name;
    }

    public function capabilities(): Capabilities
    {
        // The generic client's capabilities, because that is what is actually
        // doing the talking. Claiming more here would be claiming it on the
        // platform's behalf.
        return parent::capabilities();
    }

    /**
     * The credentials this platform asks for, and nothing it does not.
     *
     * ── Why the generic form is not simply reused ────────────────────────────
     *
     * Because it asks fifteen questions, most of which have one right answer
     * for a given platform. Presented with all of them somebody has to know
     * which auth header Magento wants and what its orders path is — which is
     * the knowledge the preset exists to supply.
     *
     * The paths stay available underneath as advanced fields: a shop on a
     * non-standard install still needs to be able to correct them, and hiding
     * them would trade one dead end for another.
     *
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        $fields = [
            new ConfigField(
                key: 'base_url',
                label: 'Shop address',
                type: 'url',
                help: $this->hint ?? 'The address of your '.$this->name.' shop.',
                placeholder: 'https://shop.example.com',
            ),
        ];

        foreach ($this->credentialFields() as $field) {
            $fields[] = $field;
        }

        /*
         * Kept from the generic driver, minus what has already been asked.
         *
         * A preset knows the shape of a standard install and not of somebody's
         * particular one, so the paths remain editable — they simply start
         * filled in rather than blank.
         */
        foreach (parent::configSchema() as $field) {
            if (in_array($field->key, ['base_url', 'auth_type', 'auth_token', 'auth_username', 'auth_header'], true)) {
                continue;
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * The one or two things that differ between platforms.
     *
     * @return list<ConfigField>
     */
    private function credentialFields(): array
    {
        return match ($this->auth) {
            'basic' => [
                new ConfigField(
                    key: 'auth_username',
                    label: 'Key or username',
                    help: 'Some platforms want the API key here and nothing in the password.',
                ),
                new ConfigField(
                    key: 'auth_token',
                    label: 'Secret or password',
                    type: 'password',
                    required: false,
                    secret: true,
                ),
            ],

            'oauth_client' => [
                new ConfigField(
                    key: 'auth_username',
                    label: 'Client ID',
                ),
                new ConfigField(
                    key: 'auth_token',
                    label: 'Client secret',
                    type: 'password',
                    secret: true,
                ),
                new ConfigField(
                    key: 'auth_header',
                    label: 'Access token',
                    type: 'password',
                    required: false,
                    secret: true,
                    help: 'Paste a current token. Refreshing it automatically is not built for this platform yet.',
                ),
            ],

            // The common case: one long secret in an Authorization header.
            default => [
                new ConfigField(
                    key: 'auth_token',
                    label: 'API token',
                    type: 'password',
                    secret: true,
                    help: 'Created in your '.$this->name.' admin, under API or integrations.',
                ),
            ],
        };
    }

    /** How well this platform is actually supported, for the form to show. */
    public function support(): string
    {
        return PlatformPresets::find($this->platform)['support'] ?? 'manual';
    }
}
