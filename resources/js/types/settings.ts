/**
 * The settings contract, typed once.
 *
 * Mirrors App\Domain\Settings\SettingsRegistry and the endpoints that read it.
 * The registry is the single source of truth on the server; this is the single
 * place the client agrees with it.
 */

export type SettingsGroupKey = 'appearance' | 'currency' | 'media' | 'integrations';

export type SettingsGroup = {
    key: SettingsGroupKey;
    label: string;
    icon: string;
    blurb: string;
    capability: string;
};

export type SettingFieldType = 'string' | 'bool' | 'int' | 'media';

export type SettingField = {
    key: string;
    type: SettingFieldType;
    label: string;
    help: string | null;
};

/** Values come back keyed by their full registry key, plus `key:url` for media. */
export type SettingValues = Record<string, string | boolean | number | null>;

export type MediaItem = {
    id: string;
    url: string;
    /** The small derivative a grid should render. Falls back to `url` when
     *  none was made — an SVG, a small PNG, a PDF. */
    thumb_url: string;
    name: string;
    original_name: string;
    mime_type: string;
    extension: string;
    size: number;
    readable_size: string;
    width: number | null;
    height: number | null;
    alt_text: string | null;
    is_image: boolean;
    uploaded_at: string | null;
};

export type MediaKind = 'image' | 'document';

export type MediaSort = 'newest' | 'oldest' | 'name' | 'largest' | 'smallest';

export type MediaStats = {
    total_count: number;
    total_bytes: number;
    images: { count: number; bytes: number };
    documents: { count: number; bytes: number };
};

export type CurrencyOption = { code: string; name: string; symbol: string };

export type CurrencyRate = {
    code: string;
    name: string;
    rate: number | null;
    source: 'manual' | 'auto' | null;
    fetched_at: string | null;
    in_use: boolean;
};

export type CurrencyPanel = {
    base: string;
    base_name: string;
    base_symbol: string;
    mode: 'manual' | 'auto';
    provider: string;
    providers: { key: string; label: string; needs_key: boolean }[];
    in_use: string[];
    missing: string[];
    last_refreshed: string | null;
    rebuilt_at: string | null;
    options: CurrencyOption[];
    rates: CurrencyRate[];
};

export type SettingsPanel = {
    group: SettingsGroupKey;
    values: SettingValues;
    fields: SettingField[];
    media?: MediaItem[];
    currency?: CurrencyPanel;
};
