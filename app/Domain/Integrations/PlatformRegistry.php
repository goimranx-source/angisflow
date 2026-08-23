<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Integrations\Contracts\PlatformDriver;
use App\Domain\Integrations\Drivers\GenericCourierDriver;
use App\Domain\Integrations\Drivers\InboundFeedDriver;
use App\Domain\Integrations\Drivers\PresetStoreDriver;
use App\Domain\Integrations\Drivers\GenericRestDriver;
use App\Domain\Integrations\Drivers\ShopifyDriver;
use App\Domain\Integrations\Drivers\WebflowDriver;
use App\Domain\Integrations\Drivers\WooCommerceDriver;
use App\Domain\Integrations\Models\Integration;
use RuntimeException;

/**
 * The one place that knows which platforms exist.
 *
 * Everything above this line — the sync services, the webhook endpoint, the
 * settings screen — asks the registry for a driver and then talks to an
 * interface. Nothing else in the application names a platform, which is the
 * property that makes adding a fifth one a single-line change here rather than
 * a search for every `match ($platform)` that was ever written.
 *
 * Resolved through the container rather than constructed with `new`, so a
 * driver that later needs something injected can simply declare it, and so
 * tests can bind a fake in place of a real one.
 */
class PlatformRegistry
{
    /**
     * Provider key → driver class.
     *
     * The keys are what live in api_integrations.provider and in URLs, so they
     * are permanent: renaming one orphans every connection already saved under
     * the old name.
     *
     * @var array<string, class-string<PlatformDriver>>
     */
    private const DRIVERS = [
        'woocommerce' => WooCommerceDriver::class,
        'shopify' => ShopifyDriver::class,
        'webflow' => WebflowDriver::class,
        'generic_rest' => GenericRestDriver::class,
        'inbound_feed' => InboundFeedDriver::class,
        // Courier drivers
        'pathao' => GenericCourierDriver::class,
        'steadfast' => GenericCourierDriver::class,
        'redx' => GenericCourierDriver::class,
        'paperfly' => GenericCourierDriver::class,
        'ecourier' => GenericCourierDriver::class,
        'sundarban' => GenericCourierDriver::class,
        'generic_courier' => GenericCourierDriver::class,
        'test_courier' => GenericCourierDriver::class,
    ];

    /**
     * What each driver is for.
     *
     * ── An integration is not only a shop ────────────────────────────────────
     *
     * A business connects whatever it runs on: the site it sells through, the
     * courier that delivers, and in time whatever else has an API. Presenting
     * every driver in one flat list makes somebody looking for their courier
     * read past three shopping platforms first, and it makes the form ask for a
     * storefront on a connection that has nothing to do with one.
     *
     * So the kind is picked first, and everything after it follows from that
     * choice.
     *
     * ── Why the generic driver appears under all of them ─────────────────────
     *
     * Because it genuinely is all of them. "Any REST API" is how a courier
     * nobody here has heard of gets connected, and refusing to offer it under
     * that heading would mean the answer exists but cannot be found.
     *
     * @var array<string, list<string>>
     */
    private const KINDS = [
        'woocommerce' => ['store'],
        'shopify' => ['store'],
        'webflow' => ['store'],
        'generic_rest' => ['store', 'courier', 'other'],
        'inbound_feed' => ['store'],
        // Courier-specific providers
        'pathao' => ['courier'],
        'steadfast' => ['courier'],
        'redx' => ['courier'],
        'paperfly' => ['courier'],
        'ecourier' => ['courier'],
        'sundarban' => ['courier'],
        'generic_courier' => ['courier'],
        'test_courier' => ['courier'],
    ];

    /** @var array<string, array{label: string, help: string}> */
    private const KIND_LABELS = [
        'store' => ['label' => 'Online store', 'help' => 'Bring in orders, products and customers.'],
        'courier' => ['label' => 'Courier', 'help' => 'Track parcels and delivery status.'],
        'other' => ['label' => 'Something else', 'help' => 'Any service with an API.'],
    ];

    /** @var array<string, PlatformDriver> */
    private array $resolved = [];

    /**
     * The kinds of thing that can be connected.
     *
     * @return list<array{value: string, label: string, help: string}>
     */
    public function kinds(): array
    {
        return array_map(
            fn (string $key): array => ['value' => $key] + self::KIND_LABELS[$key],
            array_keys(self::KIND_LABELS),
        );
    }

    /** What a saved connection is for, judged by its driver. */
    public function kindOf(string $provider): string
    {
        return self::KINDS[strtolower(trim($provider))][0] ?? 'other';
    }

    /**
     * What one saved connection is for, tolerating what is already stored.
     *
     * `type` predates the kinds and holds 'ecommerce' on every row written
     * before them. Translated rather than migrated: the column is free text
     * used by nothing else, and rewriting live rows to fix a label is a
     * migration that can only go wrong.
     *
     * Here rather than on an endpoint because two screens ask the question, and
     * the first time they answered it differently one of them displayed the raw
     * 'ecommerce' to somebody.
     */
    public function kindFor(Integration $integration): string
    {
        $stored = mb_strtolower(trim((string) $integration->type));

        return match ($stored) {
            'store', 'courier', 'other' => $stored,
            'ecommerce', 'shop' => 'store',
            default => $this->kindOf((string) $integration->provider),
        };
    }

    /** What to call a kind on screen. */
    public function kindLabel(string $kind): string
    {
        return self::KIND_LABELS[$kind]['label'] ?? ucfirst($kind);
    }

    /**
     * The driver for a provider key.
     *
     * Throws rather than returning null, because every caller that reaches this
     * point already holds a saved connection: an unknown key there means a row
     * whose provider no longer exists in the code, which is a deployment fault
     * and not something a caller can sensibly recover from. Use `supports()`
     * where the key came from user input and might legitimately be wrong.
     */
    public function driver(string $provider): PlatformDriver
    {
        $key = strtolower(trim($provider));

        /*
         * A platform from the catalogue, spoken to by the generic client.
         *
         * Checked before the hand-written drivers are consulted for a missing
         * key, so a preset that later earns a driver of its own is picked up by
         * DRIVERS and this branch stops applying — without the entry, or
         * anything a business configured against it, having to change.
         */
        if (! isset(self::DRIVERS[$key]) && ($preset = PlatformPresets::find($key)) !== null) {
            return $this->resolved[$key] ??= new PresetStoreDriver(
                $key,
                $preset['label'],
                $preset['auth'] ?? 'token',
                $preset['help'] ?? null,
            );
        }

        if (! isset(self::DRIVERS[$key])) {
            throw new RuntimeException("No integration driver is registered for '{$provider}'.");
        }

        // Cached per key: drivers are stateless, and testConnection on a page
        // listing eight connections should not build eight identical objects.
        if (! isset($this->resolved[$key])) {
            $class = self::DRIVERS[$key];

            // GenericCourierDriver needs label and provider parameters
            if ($class === GenericCourierDriver::class) {
                $label = match ($key) {
                    'pathao' => 'Pathao',
                    'steadfast' => 'Steadfast',
                    'redx' => 'RedX',
                    'paperfly' => 'Paperfly',
                    'ecourier' => 'eCourier',
                    'sundarban' => 'Sundarban',
                    'generic_courier' => 'Custom courier',
                    'test_courier' => 'Test Courier (Sandbox)',
                    default => ucfirst($key),
                };
                $this->resolved[$key] = new GenericCourierDriver($label, $key);
            } else {
                $this->resolved[$key] = app($class);
            }
        }

        return $this->resolved[$key];
    }

    /** The driver behind a saved connection. */
    public function for(Integration $integration): PlatformDriver
    {
        return $this->driver((string) $integration->provider);
    }

    public function supports(string $provider): bool
    {
        return isset(self::DRIVERS[strtolower(trim($provider))]);
    }

    /** @return list<PlatformDriver> */
    public function all(): array
    {
        /*
         * Every hand-written driver, then every catalogued platform that does
         * not have one. Keyed union rather than a concatenation, so a platform
         * appearing in both places is listed once — by its real driver.
         */
        $keys = array_keys(self::DRIVERS + PlatformPresets::all());

        return array_map(fn (string $key): PlatformDriver => $this->driver($key), $keys);
    }

    /**
     * Everything the "connect a shop" screen needs, in one payload.
     *
     * Built from the drivers themselves rather than repeated in the frontend.
     * A duplicated list is a list that goes stale — a field added to a driver
     * and not to the form is a connection that saves without the credential it
     * needs, and fails at the first sync with an error nobody can trace back to
     * a missing input.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(): array
    {
        return array_map(fn (PlatformDriver $driver): array => [
            'key' => $driver->key(),
            'label' => $driver->label(),
            // Every kind this driver can serve, so the form can filter by the
            // choice made in step one.
            'kinds' => self::KINDS[$driver->key()] ?? PlatformPresets::find($driver->key())['kinds'] ?? ['other'],

            /*
             * Where it sits in the list, and how far it can be trusted.
             *
             * Without the second of these a platform nobody has tested looks
             * identical to one that has been in use for a year, and somebody
             * chooses the first on the strength of the second.
             */
            'group' => PlatformPresets::find($driver->key())['group'] ?? 'Anything else',
            'support' => PlatformPresets::find($driver->key())['support'] ?? 'built',
            'capabilities' => $driver->capabilities()->toArray(),
            'fields' => array_map(
                fn ($field): array => $field->toArray(),
                $driver->configSchema(),
            ),
        ], $this->all());
    }

    /**
     * The config keys a given platform treats as secret.
     *
     * Read from the schema rather than kept as a second list, so masking cannot
     * drift from the form: a new password field is secret the moment it is
     * declared, without anyone remembering to add it here too.
     *
     * @return list<string>
     */
    public function secretKeys(string $provider): array
    {
        $keys = [];

        foreach ($this->driver($provider)->configSchema() as $field) {
            if ($field->secret) {
                $keys[] = $field->key;
            }
        }

        return $keys;
    }

    /**
     * Validation rules for a platform's configuration.
     *
     * @return array<string, string>
     */
    public function rules(string $provider): array
    {
        $rules = [];

        foreach ($this->driver($provider)->configSchema() as $field) {
            $rules['configuration.'.$field->key] = $field->rule();
        }

        return $rules;
    }
}
