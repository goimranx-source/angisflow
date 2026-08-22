<?php

declare(strict_types=1);

namespace App\Domain\Settings;

/**
 * Every setting that exists, in one list.
 *
 * ── Why this is code and not a table ─────────────────────────────────────────
 *
 * The rows in `settings` are a subscriber's *answers*. The questions are part of
 * the product: a new setting means writing the feature that reads it, so it
 * ships with a deploy either way. Putting the questions in the database as well
 * would mean two things to keep in step and no compiler to notice when they
 * drift.
 *
 * It also gives the key/value table the schema it is usually accused of
 * lacking. Nothing can be written that is not declared here, values are cast on
 * the way out, and the API validates against these rules rather than each
 * endpoint inventing its own — so "what settings are there" is answered by
 * reading one file.
 *
 * ── Adding one ───────────────────────────────────────────────────────────────
 *
 * Add a line. The API accepts it, the registry casts it, the cache invalidates
 * it and the settings screen renders a control for it, all without another
 * change anywhere.
 */
final class SettingsRegistry
{
    public const TYPE_STRING = 'string';

    public const TYPE_BOOL = 'bool';

    public const TYPE_INT = 'int';

    /** A media item's public id, resolved to a URL when the group is read. */
    public const TYPE_MEDIA = 'media';

    /**
     * The tabs the settings screen carries, in the order they appear.
     *
     * `capability` is what the server checks and what the client uses to decide
     * whether to draw the tab at all — one answer, two readers.
     */
    /**
     * The tabs Settings offers.
     *
     * 'appearance' and 'media' are deliberately absent. Their keys still exist
     * and are still read — the logo, favicon and brand name are what the boot
     * payload white-labels the shell with, so deleting them would strip the
     * product of its own name — they simply no longer get a tab of their own.
     * See HIDDEN_GROUPS.
     */
    public const GROUPS = [
        'appearance' => [
            'label' => 'Appearance',
            'icon' => 'paint-brush',
            'blurb' => 'What the tool calls itself, and the marks it wears.',
            'capability' => 'settings.edit',
        ],
        'currency' => [
            'label' => 'Currency',
            'icon' => 'currency-circle-dollar',
            'blurb' => 'What every total is counted in, and how foreign money is converted.',
            'capability' => 'settings.edit',
        ],
        'media' => [
            'label' => 'Media library',
            'icon' => 'images',
            'blurb' => 'Upload once, use anywhere.',
            'capability' => 'settings.view',
        ],
        'integrations' => [
            'label' => 'Integrations',
            'icon' => 'plug',
            'blurb' => 'The shops you sell through, and how their fields map to yours.',
            'capability' => 'stores.edit',
        ],
    ];

    /**
     * key => [group, type, default, rules, label, help]
     *
     * The key's prefix is its group, so a key says where it belongs rather than
     * needing a column to carry it.
     */
    public const KEYS = [
        // ── Appearance ───────────────────────────────────────────────────
        'appearance.brand_name' => [
            'type' => self::TYPE_STRING,
            'default' => '',
            'rules' => ['nullable', 'string', 'max:60'],
            'label' => 'Name',
            'help' => 'Shown in the sidebar and on the browser tab. Left blank, the platform name is used.',
        ],
        'appearance.tagline' => [
            'type' => self::TYPE_STRING,
            'default' => '',
            'rules' => ['nullable', 'string', 'max:120'],
            'label' => 'Tagline',
            'help' => 'Optional. Appears under the name on the sign-in screen.',
        ],
        'appearance.logo' => [
            'type' => self::TYPE_MEDIA,
            'default' => '',
            'rules' => ['nullable', 'string', 'size:26'],
            'label' => 'Sidebar logo',
            'help' => 'Shown at the top of the sidebar. A wide mark works best.',
        ],
        'appearance.logo_mark' => [
            'type' => self::TYPE_MEDIA,
            'default' => '',
            'rules' => ['nullable', 'string', 'size:26'],
            'label' => 'Collapsed mark',
            'help' => 'The square badge shown when the sidebar is narrowed to icons.',
        ],
        'appearance.favicon' => [
            'type' => self::TYPE_MEDIA,
            'default' => '',
            'rules' => ['nullable', 'string', 'size:26'],
            'label' => 'Favicon',
            'help' => 'The icon on the browser tab. Square, 32×32 or larger.',
        ],
        'appearance.show_logo' => [
            'type' => self::TYPE_BOOL,
            'default' => true,
            'rules' => ['boolean'],
            'label' => 'Show the logo instead of the name',
            'help' => 'Turn off and the sidebar shows the name as text.',
        ],

        // ── Currency ─────────────────────────────────────────────────────
        //
        // The base currency lives on the account row, not here: it is read on
        // every request that formats money and it is a fact about the business
        // rather than a preference. These are the settings *around* it.
        'currency.mode' => [
            'type' => self::TYPE_STRING,
            'default' => 'manual',
            'rules' => ['required', 'in:manual,auto'],
            'label' => 'Where rates come from',
            'help' => 'On automatic the service owns every rate and they cannot be edited by hand.',
        ],
        'currency.provider' => [
            'type' => self::TYPE_STRING,
            'default' => 'erapi',
            'rules' => ['nullable', 'string', 'max:30'],
            'label' => 'Rate service',
            'help' => 'Only used when rates are fetched automatically.',
        ],
        'currency.provider_key' => [
            'type' => self::TYPE_STRING,
            'default' => '',
            'rules' => ['nullable', 'string', 'max:200'],
            'label' => 'API key',
            'help' => 'Only needed by services that require one. The free ones do not.',
        ],
        'currency.rebuilt_at' => [
            'type' => self::TYPE_STRING,
            'default' => '',
            'rules' => ['nullable', 'string', 'max:40'],
            'label' => 'Last recalculated',
            // Written by the system, never by the form.
            'readonly' => true,
        ],
    ];

    /** @return array<string, mixed> every declared key at its default */
    public static function defaults(): array
    {
        $out = [];

        foreach (self::KEYS as $key => $meta) {
            $out[$key] = $meta['default'];
        }

        return $out;
    }

    public static function exists(string $key): bool
    {
        return isset(self::KEYS[$key]);
    }

    public static function groupOf(string $key): string
    {
        return explode('.', $key, 2)[0];
    }

    /**
     * The part after the dot — what the API speaks.
     *
     * ── Why the wire uses short keys ─────────────────────────────────────────
     *
     * Laravel reads a dot in a validation key as a *path* into a nested array,
     * so a rule named "appearance.brand_name" means "the brand_name key inside
     * the appearance array". Sent as a flat field it matches nothing: the rules
     * pass vacuously, validated() comes back empty, and the save reports
     * success having written nothing at all.
     *
     * Escaping the dot is possible and is the wrong fix — it leaves every
     * client sending keys that only work because of an escape nobody can see.
     * The group is already in the URL, so the body says `brand_name` and the
     * server puts the prefix back.
     */
    public static function shortKey(string $key): string
    {
        return explode('.', $key, 2)[1] ?? $key;
    }

    /** The storage key for a group and the short name the API sent. */
    public static function fullKey(string $group, string $short): string
    {
        return $group.'.'.$short;
    }

    /** @return list<string> the keys belonging to one tab */
    public static function keysIn(string $group): array
    {
        return array_values(array_filter(
            array_keys(self::KEYS),
            fn (string $key) => self::groupOf($key) === $group,
        ));
    }

    /** Whether a key is the system's to write rather than the form's. */
    public static function isReadonly(string $key): bool
    {
        return (bool) (self::KEYS[$key]['readonly'] ?? false);
    }

    /**
     * The validation rules for one group's writable keys, keyed as the request
     * sends them.
     *
     * @return array<string, list<string>>
     */
    public static function rulesFor(string $group): array
    {
        $rules = [];

        foreach (self::keysIn($group) as $key) {
            if (self::isReadonly($key)) {
                continue;
            }

            // Keyed as the request sends them: short, because the group is
            // already in the URL. See shortKey().
            $rules[self::shortKey($key)] = self::KEYS[$key]['rules'];
        }

        return $rules;
    }

    /**
     * Turn a stored string into the thing it represents.
     *
     * Everything is TEXT in the table, so this is the only place that knows a
     * checkbox is a boolean. Without it '0' comes back truthy and every toggle
     * in the product is stuck on.
     */
    public static function cast(string $key, mixed $raw): mixed
    {
        $type = self::KEYS[$key]['type'] ?? self::TYPE_STRING;

        if ($raw === null) {
            return self::KEYS[$key]['default'] ?? null;
        }

        return match ($type) {
            self::TYPE_BOOL => in_array($raw, ['1', 1, true, 'true'], true),
            self::TYPE_INT => (int) $raw,
            default => (string) $raw,
        };
    }

    /** Turn a value from the API into what goes in the column. */
    public static function serialise(string $key, mixed $value): ?string
    {
        $type = self::KEYS[$key]['type'] ?? self::TYPE_STRING;

        return match ($type) {
            self::TYPE_BOOL => $value ? '1' : '0',
            self::TYPE_INT => (string) (int) $value,
            default => $value === null ? null : (string) $value,
        };
    }

    /** The keys in a group that point at a media item. */
    public static function mediaKeysIn(string $group): array
    {
        return array_values(array_filter(
            self::keysIn($group),
            fn (string $key) => (self::KEYS[$key]['type'] ?? null) === self::TYPE_MEDIA,
        ));
    }
}
