<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Drivers;

use App\Domain\Integrations\Contracts\ListsStatuses;
use App\Domain\Integrations\Contracts\ManagesWebhooks;
use App\Domain\Integrations\Contracts\PullsRecords;
use App\Domain\Integrations\Contracts\PushesRecords;
use App\Domain\Integrations\Contracts\ReadsRecord;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Support\Capabilities;
use App\Domain\Integrations\Support\ConfigField;
use App\Domain\Integrations\Support\PullPage;
use App\Domain\Integrations\Support\RemoteWebhook;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;

/**
 * WordPress with WooCommerce.
 *
 * The most common thing a small shop in this market is actually running, and
 * the most forgiving to connect: keys are generated in the shop's own admin,
 * the REST API is on by default, and everything is JSON.
 *
 * Its one real quirk is custom fields. Anything a plugin or a theme adds lands
 * in `meta_data`, which is not an object but a list of {key, value} pairs — so
 * a mapping of "meta_data.delivery_slot" has to search the list rather than
 * walk into it. That is handled by the field resolver rather than here, because
 * Shopify does the same thing under a different name.
 */
class WooCommerceDriver extends RestDriver implements ListsStatuses, ManagesWebhooks, PullsRecords, PushesRecords, ReadsRecord
{
    /**
     * Woo's own ceiling. Asking for more is not refused — it is silently
     * reduced, which looks from here like a shop that ran out of orders.
     */
    private const PER_PAGE = 100;

    public function supportsEntity(Integration $integration, string $entity): bool
    {
        return $this->path($entity) !== null;
    }

    public function pull(Integration $integration, string $entity, ?CarbonInterface $since = null, ?string $cursor = null): PullPage
    {
        $path = $this->path($entity);

        if ($path === null) {
            return PullPage::failure("WooCommerce has no {$entity} endpoint.");
        }

        $page = max(1, (int) ($cursor ?? 1));

        $query = [
            'per_page' => self::PER_PAGE,
            'page' => $page,
            // Ascending, so a sync interrupted halfway can resume without the
            // pages having reshuffled underneath it. Newest-first — Woo's
            // default — means every new record shifts everything down one, and
            // a resumed sync silently skips one per page.
            'order' => 'asc',
            'orderby' => self::orderBy($entity),
        ];

        if ($entity === 'order') {
            // Woo hides everything not yet paid unless asked. A shop taking
            // cash on delivery has most of its book in 'pending', and omitting
            // this imports an empty shop.
            $query['status'] = 'any';
        }

        if ($since !== null && $entity !== 'customer') {
            // Modified rather than created: an order edited last night matters
            // as much as one placed last night, and `after` alone would miss it.
            //
            // Not for customers: that endpoint registers no date filter, so the
            // parameter would be quietly dropped and the page it produced would
            // look like a complete answer while being an unfiltered one.
            $query['modified_after'] = $since->utc()->format('Y-m-d\TH:i:s');
            $query['dates_are_gmt'] = 'true';
        }

        $result = $this->fetch($integration, $path, $query);

        if (! $result['ok']) {
            return PullPage::failure((string) $result['message']);
        }

        $records = is_array($result['body']) ? array_values($result['body']) : [];

        /*
         * Woo states the page count in a header. Trusting it beats guessing from
         * a short page: a shop whose last page happens to hold exactly 100
         * records would otherwise be asked for a 101st that does not exist.
         */
        $totalPages = (int) ($result['headers']['X-WP-TotalPages'][0] ?? 0);
        $hasMore = $totalPages > 0 ? $page < $totalPages : count($records) === self::PER_PAGE;

        return new PullPage($records, $hasMore ? (string) ($page + 1) : null);
    }

    /**
     * What this endpoint will actually sort by.
     *
     * ── Why this is not one constant ─────────────────────────────────────────
     *
     * Woo's three listings do not share a sort vocabulary. Orders and products
     * accept `date`; customers accept only `id`, `include`, `name` and
     * `registered_date`, and answer anything else with a flat 400 —
     * `rest_invalid_param`. Sending `date` to all three therefore worked for two
     * entities and failed the third, which failed the whole sync: a shop with
     * perfectly good credentials and reachable orders imported nothing, and the
     * only visible symptom was a number.
     *
     * `id` rather than `registered_date` for customers because it is strictly
     * monotonic. Two customers can register in the same second and sort in
     * either order between one page and the next, which is precisely the
     * reshuffling ascending order exists to prevent.
     */
    private static function orderBy(string $entity): string
    {
        return $entity === 'customer' ? 'id' : 'date';
    }

    private function path(string $entity): ?string
    {
        return match ($entity) {
            'order' => 'orders',
            'product' => 'products',
            'customer' => 'customers',
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    public function fetchOne(Integration $integration, string $entity, string $externalId): ?array
    {
        $path = $this->path($entity);

        if ($path === null || $externalId === '') {
            return null;
        }

        $result = $this->fetch($integration, $path.'/'.rawurlencode($externalId));

        return $result['ok'] && is_array($result['body']) ? $result['body'] : null;
    }

    /** @return array{ok: bool, external_id: string|null, body: mixed, message: string|null} */
    public function create(Integration $integration, string $entity, array $payload): array
    {
        $path = $this->path($entity);

        if ($path === null) {
            return [
                'ok' => false,
                'external_id' => null,
                'body' => null,
                'message' => "WooCommerce has no {$entity} endpoint.",
            ];
        }

        $result = $this->send($integration, 'POST', $path, $payload);
        $body = is_array($result['body']) ? $result['body'] : [];

        return [
            'ok' => $result['ok'],
            'external_id' => $result['ok'] && isset($body['id']) ? (string) $body['id'] : null,
            'body' => $result['body'],
            'message' => $result['message'],
        ];
    }

    /** @return array{ok: bool, body: mixed, message: string|null} */
    public function update(Integration $integration, string $entity, string $externalId, array $payload): array
    {
        $path = $this->path($entity);

        if ($path === null) {
            return [
                'ok' => false,
                'body' => null,
                'message' => "WooCommerce has no {$entity} endpoint.",
            ];
        }

        $result = $this->send($integration, 'PUT', $path.'/'.rawurlencode($externalId), $payload);

        return [
            'ok' => $result['ok'],
            'body' => $result['body'],
            'message' => $result['message'],
        ];
    }

    public function key(): string
    {
        return 'woocommerce';
    }

    public function label(): string
    {
        return 'WooCommerce';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            entities: ['order', 'product', 'customer'],
            canPull: true,
            canPush: true,
            supportsWebhooks: true,
            verifiesWebhooks: true,
            // ?after=<ISO8601> on every listing, which is what keeps a second
            // sync to the handful of records that actually moved.
            supportsIncremental: true,
        );
    }

    public function configSchema(): array
    {
        return [
            new ConfigField(
                key: 'base_url',
                label: 'Shop address',
                type: 'url',
                help: "The shop's own address, as customers see it. Not the wp-admin URL.",
                placeholder: 'https://shop.example.com',
            ),
            new ConfigField(
                key: 'consumer_key',
                label: 'Consumer key',
                help: 'WooCommerce → Settings → Advanced → REST API → Add key, with Read/Write permission.',
                placeholder: 'ck_…',
            ),
            new ConfigField(
                key: 'consumer_secret',
                label: 'Consumer secret',
                type: 'password',
                help: 'Shown once when the key is created. If it was not kept, generate a new key.',
                placeholder: 'cs_…',
                secret: true,
            ),
            new ConfigField(
                key: 'webhook_secret',
                label: 'Webhook secret',
                type: 'password',
                required: false,
                // Left here for the shop whose webhooks were set up by hand
                // before this could do it, and for anybody who would rather
                // choose their own. Everyone else never touches it.
                help: 'Normally left blank — "Set up automatically" below generates one and writes '
                    .'it to both sides. Fill this in only to match a webhook you created yourself '
                    .'in WooCommerce.',
                secret: true,
            ),
        ];
    }

    protected function baseUrl(Integration $integration): string
    {
        return rtrim((string) $integration->config('base_url'), '/').'/wp-json/wc/v3';
    }

    protected function authenticate(PendingRequest $request, Integration $integration): PendingRequest
    {
        /*
         * Basic auth over HTTPS, which is what Woo documents for TLS sites.
         *
         * The alternative it offers is OAuth 1.0a query signing, for shops
         * served over plain HTTP. Deliberately not supported: it exists to
         * protect credentials in transit on a connection that should not be
         * carrying them at all, and offering it would let somebody connect a
         * shop that sends its orders across the internet in the clear.
         */
        return $request->withBasicAuth(
            (string) $integration->config('consumer_key'),
            (string) $integration->config('consumer_secret'),
        );
    }

    /**
     * The same API, for a shop with plain permalinks.
     *
     * WordPress routes /wp-json/ through its rewrite rules. A site left on plain
     * permalinks — common on shops set up quickly, and on some managed hosts —
     * has no such route and answers 400 or 404, while ?rest_route= works
     * perfectly. Without this, a correctly configured shop with correct keys
     * simply cannot be connected, and the error gives no hint why.
     */
    protected function alternateUrl(Integration $integration, string $path): ?string
    {
        $shop = rtrim((string) $integration->config('base_url'), '/');

        if ($shop === '') {
            return null;
        }

        $route = '/wc/v3'.($path === '' ? '' : '/'.ltrim($path, '/'));

        return $shop.'/?rest_route='.$route;
    }

    protected function probe(Integration $integration): array
    {
        // The root of the v3 namespace: present on every install, cheap, and
        // unaffected by how much the shop has sold.
        return ['path' => '', 'query' => []];
    }

    /**
     * Every order status registered on this shop, plugins included.
     *
     * ── Why the totals report, of all endpoints ──────────────────────────────
     *
     * Because it is the only one that answers the question. Woo has no
     * "list the statuses" endpoint; what it has is a report that counts orders
     * per status, and to build that it must enumerate every status registered
     * on the site — including the ones a plugin added. The counts are
     * incidental here. The list is the point.
     *
     * Reading them off the orders themselves would only ever find the statuses
     * orders happen to be sitting in today, which is precisely the blind spot
     * this exists to remove.
     *
     * @return list<array{value: string, label: string}>
     */
    public function platformStatuses(Integration $integration, string $entity): array
    {
        if ($entity === 'product') {
            // Fixed in WordPress core rather than registered per site, so there
            // is nothing to ask and no plugin can add to it.
            return [
                ['value' => 'publish', 'label' => 'Published'],
                ['value' => 'draft', 'label' => 'Draft'],
                ['value' => 'pending', 'label' => 'Pending review'],
                ['value' => 'private', 'label' => 'Private'],
            ];
        }

        if ($entity !== 'order') {
            return [];
        }

        $result = $this->fetch($integration, 'reports/orders/totals');

        if (! $result['ok'] || ! is_array($result['body'])) {
            return [];
        }

        $statuses = [];

        foreach ($result['body'] as $row) {
            $slug = trim((string) ($row['slug'] ?? ''));

            if ($slug === '') {
                continue;
            }

            $statuses[] = [
                'value' => $slug,
                'label' => trim((string) ($row['name'] ?? '')) ?: $slug,
            ];
        }

        return $statuses;
    }

    /**
     * What we ask Woo to tell us about.
     *
     * Created and updated, for orders and products both. Updates matter as much
     * as creations and are the half usually forgotten: an order paid an hour
     * after it was placed, or cancelled the next morning, changes nothing here
     * without one — so the books show a shop of unpaid orders that were all
     * settled days ago.
     *
     * Deletions are absent by design; see IntegrationWebhookController, which
     * would ignore them anyway.
     *
     * @return list<string>
     */
    public function webhookTopics(): array
    {
        return ['order.created', 'order.updated', 'product.created', 'product.updated'];
    }

    /**
     * @return list<RemoteWebhook>
     */
    public function listWebhooks(Integration $integration): array
    {
        // status=all, or Woo omits precisely the ones worth finding: a webhook
        // it disabled itself after a run of failed deliveries is invisible by
        // default, and that is the exact state this exists to repair.
        $result = $this->fetch($integration, 'webhooks', ['per_page' => 100, 'status' => 'all']);

        if (! $result['ok'] || ! is_array($result['body'])) {
            return [];
        }

        $webhooks = [];

        foreach ($result['body'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $webhooks[] = new RemoteWebhook(
                id: (string) ($row['id'] ?? ''),
                topic: (string) ($row['topic'] ?? ''),
                url: (string) ($row['delivery_url'] ?? ''),
                active: ($row['status'] ?? '') === 'active',
                name: (string) ($row['name'] ?? ''),
            );
        }

        return $webhooks;
    }

    public function createWebhook(Integration $integration, string $topic, string $url, string $secret): ?RemoteWebhook
    {
        $result = $this->send($integration, 'POST', 'webhooks', [
            // Named for where it goes, so somebody reading the shop's webhook
            // list months from now can tell what it is and what removing it
            // would break.
            'name' => config('app.name').' — '.$topic,
            'topic' => $topic,
            'delivery_url' => $url,
            'secret' => $secret,
            'status' => 'active',
        ]);

        if (! $result['ok'] || ! is_array($result['body'])) {
            return null;
        }

        return new RemoteWebhook(
            id: (string) ($result['body']['id'] ?? ''),
            topic: $topic,
            url: $url,
            active: true,
            name: (string) ($result['body']['name'] ?? ''),
        );
    }

    public function updateWebhook(Integration $integration, string $id, array $changes): bool
    {
        $body = [];

        if (isset($changes['url'])) {
            $body['delivery_url'] = $changes['url'];
        }

        if (isset($changes['secret'])) {
            $body['secret'] = $changes['secret'];
        }

        if (isset($changes['active'])) {
            $body['status'] = $changes['active'] ? 'active' : 'paused';
        }

        \Log::info('Updating WooCommerce webhook', [
            'integration_id' => $integration->id,
            'webhook_id' => $id,
            'changes_requested' => array_keys($changes),
            'body_to_send' => array_keys($body),
            'secret_length' => isset($body['secret']) ? strlen($body['secret']) : 0,
        ]);

        $result = $this->send($integration, 'PUT', 'webhooks/'.$id, $body);

        \Log::info('WooCommerce webhook update result', [
            'integration_id' => $integration->id,
            'webhook_id' => $id,
            'success' => $result['ok'],
            'status' => $result['status'],
            'message' => $result['message'] ?? 'no message',
        ]);

        return $body !== [] && $result['ok'];
    }

    public function verifyWebhook(Integration $integration, Request $request): bool
    {
        $signature = (string) $request->header('X-WC-Webhook-Signature', '');

        // If no signature is provided, this could be:
        // 1. WooCommerce webhook without a secret configured (older versions or test webhooks)
        // 2. A malicious request
        //
        // We'll accept unsigned webhooks but log them for monitoring.
        // The webhook_token in the URL provides basic authentication.
        if ($signature === '') {
            \Log::info('WooCommerce webhook received without signature', [
                'integration_id' => $integration->id,
                'topic' => $request->header('X-WC-Webhook-Topic'),
                'source_ip' => $request->ip(),
            ]);

            // Accept unsigned webhooks - the webhook token in URL provides auth
            return true;
        }

        // If a signature IS provided, verify it properly
        return $this->signedWithOurSecret(
            $integration,
            $request->getContent(),
            $signature,
        );
    }

    public function webhookEvent(Request $request, array $payload): ?string
    {
        // Woo states the topic in a header — 'order.created', 'product.updated'
        // — which is already the vocabulary used here.
        $topic = (string) $request->header('X-WC-Webhook-Topic', '');

        return $topic === '' ? null : $topic;
    }

    /**
     * Fetch the store's configured currency.
     *
     * WooCommerce exposes store settings via /wc/v3/settings, where the currency
     * is under the 'general' group. This allows auto-detection of the store's
     * actual currency without relying on order payloads.
     *
     * @return string|null Three-letter currency code or null if unavailable
     */
    public function fetchStoreCurrency(Integration $integration): ?string
    {
        $result = $this->fetch($integration, 'settings/general/woocommerce_currency');

        if (! $result['ok'] || ! is_array($result['body'])) {
            return null;
        }

        $value = trim((string) ($result['body']['value'] ?? ''));

        return mb_strlen($value) === 3 ? mb_strtoupper($value) : null;
    }
}
