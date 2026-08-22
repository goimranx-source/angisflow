/**
 * The shapes the integrations screens exchange with the server.
 *
 * Deliberately mirrors what IntegrationsEndpoint::present() returns rather than
 * the database. `configuration` here is always the masked form — a saved secret
 * reads back as a marker saying one is set, never as the value.
 */

export type ConfigField = {
    key: string;
    label: string;
    /** 'text' | 'url' | 'password' | 'select' | 'boolean' */
    type: string;
    required: boolean;
    help: string | null;
    placeholder: string | null;
    options: Array<{ value: string; label: string }>;
    secret: boolean;
};

export type Capabilities = {
    entities: string[];
    can_pull: boolean;
    can_push: boolean;
    supports_webhooks: boolean;
    verifies_webhooks: boolean;
    supports_incremental: boolean;
    can_sync_both_ways: boolean;
};

/** One platform that can be connected, described by its own driver. */
export type Platform = {
    key: string;
    label: string;
    /** Which kinds this driver can serve. The generic one serves all of them. */
    kinds: string[];
    capabilities: Capabilities;
    fields: ConfigField[];
};

export type Connection = {
    id: string;
    name: string;
    provider: string;
    platform: string;
    /** 'store' | 'courier' | 'other' — what this connection is for. */
    kind: string;
    /** The storefront this connection feeds, when it has one. */
    store: { id: string; name: string } | null;
    status: string;
    is_active: boolean;
    bidirectional: boolean;
    can_pull: boolean;
    capabilities: Capabilities;
    configuration: Record<string, unknown>;
    fields: ConfigField[];
    last_sync_at: string | null;
    last_success_at: string | null;
    last_error_message: string | null;
    success_rate: number | null;
    records_synced_total: number;
    unmapped_statuses: number;
    webhook_url: string;
};

/** One of this tool's statuses, and what the shop calls it. */
export type OurStatus = {
    value: string;
    label: string;
    tone: string;
    /** Added by this business, rather than one of the built-in ten. */
    custom: boolean;
    /** The shop's word for it, or null when not set. */
    mapped_to: string | null;
};

/** One word the shop can send. */
export type TheirStatus = {
    value: string;
    /** What the shop's own admin calls it — 'On hold', not 'on-hold'. */
    label: string;
    /** Actually seen from this connection, rather than merely documented. */
    observed: boolean;
};

export type StatusCatalogue = {
    entity: string;
    ours: OurStatus[];
    theirs: TheirStatus[];
    /** Words the shop sends that no status of ours claims. */
    unclaimed: string[];
};

/** A thing that can be connected: a store, a courier, anything with an API. */
export type Kind = {
    value: string;
    label: string;
    help: string;
};
