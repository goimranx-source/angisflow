<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * What this tool calls itself — the mark in the header, for everybody.
 *
 * Written by the admin panel (not yet built) and read on every page load, so
 * it is cached as one set and dropped on write. Falls back to the files shipped
 * in the repository, which means a fresh install has a logo before anybody has
 * uploaded one.
 */
class PlatformSettings
{
    private const CACHE_KEY = 'platform:settings';

    private const TTL = 3600;

    public const DEFAULTS = [
        'brand.name' => 'Prism',
        'brand.tagline' => 'ERP Management Tool',
        // Shipped assets rather than uploads: an install with no admin panel
        // yet still has a header that looks finished.
        'brand.logo' => '/img/prism-logo.svg',
        'brand.logo_mark' => '/img/prism-mark.svg',
    ];

    /**
     * @return array<string, string|null>
     */
    public static function all(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::TTL,
            fn () => array_replace(
                self::DEFAULTS,
                DB::table('platform_settings')->pluck('value', 'key')->all(),
            ),
        );
    }

    public static function get(string $key): ?string
    {
        return self::all()[$key] ?? null;
    }

    public static function put(string $key, ?string $value): void
    {
        DB::table('platform_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now(), 'created_at' => now()],
        );

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The brand as the shell needs it, with paths already resolved to URLs.
     *
     * @return array<string, string|null>
     */
    public static function brand(): array
    {
        return [
            'name' => self::get('brand.name'),
            'tagline' => self::get('brand.tagline'),
            'logo' => self::url(self::get('brand.logo')),
            'logo_mark' => self::url(self::get('brand.logo_mark')),
        ];
    }

    /**
     * A stored value may be a path the admin panel uploaded to the public disk
     * or one of the shipped defaults. Anything already absolute is returned
     * untouched, so a CDN address keeps working.
     */
    private static function url(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (str_starts_with($value, '/') || str_starts_with($value, 'http')) {
            return $value;
        }

        return Storage::disk(config('filesystems.default'))->url($value);
    }
}
