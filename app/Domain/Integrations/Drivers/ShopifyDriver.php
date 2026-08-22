<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Drivers;

use App\Domain\Integrations\Contracts\PullsRecords;
use App\Domain\Integrations\Contracts\PushesRecords;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Support\Capabilities;
use App\Domain\Integrations\Support\ConfigField;
use App\Domain\Integrations\Support\PullPage;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;

/**
 * Shopify, through a custom app's Admin API token.
 *
 * Custom app rather than a public one: a public app means an OAuth round trip,
 * an app listing and a review process, all of which exist so that strangers can
 * install it. A subscriber connecting their own shop is not a stranger to it —
 * they can issue themselves a token in two minutes and skip the lot.
 *
 * Its custom fields arrive in two places rather than one: `note_attributes` on
 * an order, and `metafields` — which are a separate request per record, and so
 * are only fetched when a mapping actually refers to them.
 */
class ShopifyDriver extends RestDriver implements PullsRecords, PushesRecords
{
    private const PER_PAGE = 250;

    public function supportsEntity(Integration $integration, string $entity): bool
    {
        return $this->path($entity) !== null;
    }

    /**
     * ── Shopify's cursor is not a page number ────────────────────────────────
     *
     * It hands back an opaque `page_info` in a Link header which must be sent
     * back byte for byte, and which is *exclusive* of every other filter — send
     * `page_info` together with `updated_at_min` and the request is rejected
     * outright. So the filters go on the first call only, and every call after
     * it carries the cursor alone. Getting this wrong is the classic Shopify
     * integration bug: page one works, page two 400s, and the shop appears to
     * have exactly 250 orders.
     */
    public function pull(Integration $integration, string $entity, ?CarbonInterface $since = null, ?string $cursor = null): PullPage
    {
        $path = $this->path($entity);

        if ($path === null) {
            return PullPage::failure("Shopify has no {$entity} endpoint.");
        }

        if ($cursor !== null) {
            $query = ['limit' => self::PER_PAGE, 'page_info' => $cursor];
        } else {
            $query = ['limit' => self::PER_PAGE];

            if ($entity === 'order') {
                // Shopify returns only open orders unless told otherwise, so a
                // shop's archived history would import as nothing.
                $query['status'] = 'any';
            }

            if ($since !== null) {
                $query['updated_at_min'] = $since->utc()->toIso8601String();
            }
        }

        $result = $this->fetch($integration, $path.'.json', $query);

        if (! $result['ok']) {
            return PullPage::failure((string) $result['message']);
        }

        // Wrapped under a plural key — {"orders": [...]} — rather than returned
        // as a bare list.
        $records = $result['body'][$path] ?? [];

        return new PullPage(
            is_array($records) ? array_values($records) : [],
            $this->nextPageInfo($result['headers']),
        );
    }

    /**
     * The cursor for the next page, dug out of the Link header.
     *
     * Shopify writes it as `<https://…?page_info=xyz>; rel="next"`, alongside a
     * `rel="previous"` on any page after the first. Matching on rel rather than
     * on position matters: taking the first link on page two walks backwards
     * for ever.
     *
     * @param  array<string, array<int, string>>  $headers
     */
    private function nextPageInfo(array $headers): ?string
    {
        $link = $headers['Link'][0] ?? $headers['link'][0] ?? '';

        if ($link === '' || ! preg_match('/<([^>]+)>;\s*rel="next"/', $link, $m)) {
            return null;
        }

        parse_str((string) parse_url($m[1], PHP_URL_QUERY), $query);

        return isset($query['page_info']) ? (string) $query['page_info'] : null;
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

    /**
     * Pinned deliberately.
     *
     * Shopify retires a version roughly yearly and an unpinned request follows
     * whatever is current, which means a shop that has worked for a year breaks
     * on a morning nobody deployed anything. Pinned, it keeps working until we
     * choose to move it.
     */
    private const API_VERSION = '2024-10';

    public function key(): string
    {
        return 'shopify';
    }

    public function label(): string
    {
        return 'Shopify';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            entities: ['order', 'product', 'customer'],
            canPull: true,
            canPush: true,
            supportsWebhooks: true,
            verifiesWebhooks: true,
            supportsIncremental: true,
        );
    }

    public function configSchema(): array
    {
        return [
            new ConfigField(
                key: 'shop_domain',
                label: 'Shop domain',
                help: 'The myshopify address, not a custom domain pointed at it.',
                placeholder: 'my-shop.myshopify.com',
            ),
            new ConfigField(
                key: 'access_token',
                label: 'Admin API access token',
                type: 'password',
                help: 'Shopify admin → Settings → Apps → Develop apps → your app → Admin API token. '
                    .'Shown once, and starts shpat_.',
                placeholder: 'shpat_…',
                secret: true,
            ),
            new ConfigField(
                key: 'webhook_secret',
                label: 'Webhook signing secret',
                type: 'password',
                required: false,
                help: 'The app\'s API secret key. Without it, incoming calls cannot be proven '
                    .'genuine and will be refused.',
                secret: true,
            ),
        ];
    }

    protected function baseUrl(Integration $integration): string
    {
        // Tolerate a pasted https:// or trailing slash rather than refusing a
        // value that is obviously the right shop.
        $domain = trim((string) $integration->config('shop_domain'));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');

        return "https://{$domain}/admin/api/".self::API_VERSION;
    }

    protected function authenticate(PendingRequest $request, Integration $integration): PendingRequest
    {
        return $request->withHeaders([
            'X-Shopify-Access-Token' => (string) $integration->config('access_token'),
        ]);
    }

    protected function probe(Integration $integration): array
    {
        // The shop record: one row, always present, and it confirms both the
        // domain and the token in a single call.
        return ['path' => 'shop.json', 'query' => []];
    }

    /** Shopify can update an existing order, but cannot create one here. */
    public function create(Integration $integration, string $entity, array $payload): array
    {
        return [
            'ok' => false,
            'external_id' => null,
            'body' => null,
            'message' => 'Shopify order creation from this application is not supported.',
        ];
    }

    /** @return array{ok: bool, body: mixed, message: string|null} */
    public function update(Integration $integration, string $entity, string $externalId, array $payload): array
    {
        if ($entity !== 'order') {
            return ['ok' => false, 'body' => null, 'message' => "Shopify cannot update {$entity} records here."];
        }

        $status = (string) ($payload['status'] ?? '');
        unset($payload['status']);

        $action = match ($status) {
            'cancelled' => 'cancel',
            'closed' => 'close',
            default => null,
        };

        $result = $action === null
            ? $this->send(
                $integration,
                'PUT',
                'orders/'.rawurlencode($externalId).'.json',
                ['order' => $payload + ['id' => (int) $externalId]],
            )
            : $this->send(
                $integration,
                'POST',
                'orders/'.rawurlencode($externalId).'/'.$action.'.json',
            );

        return [
            'ok' => $result['ok'],
            'body' => $result['body'],
            'message' => $result['message'],
        ];
    }

    public function verifyWebhook(Integration $integration, Request $request): bool
    {
        return $this->signedWithOurSecret(
            $integration,
            $request->getContent(),
            (string) $request->header('X-Shopify-Hmac-Sha256', ''),
        );
    }

    public function webhookEvent(Request $request, array $payload): ?string
    {
        // Shopify writes 'orders/create'; this product says 'order.created'.
        // Translating in the driver is the whole point of it — callers get one
        // vocabulary whichever shop is speaking.
        $topic = (string) $request->header('X-Shopify-Topic', '');

        return match ($topic) {
            'orders/create' => 'order.created',
            'orders/updated' => 'order.updated',
            'orders/cancelled' => 'order.cancelled',
            'products/create' => 'product.created',
            'products/update' => 'product.updated',
            'customers/create' => 'customer.created',
            'customers/update' => 'customer.updated',
            default => null,
        };
    }

    /**
     * Fetch the store's configured currency.
     *
     * Shopify exposes shop details via /admin/api/2024-01/shop.json, where the
     * currency is the 'currency' field. This allows auto-detection of the
     * store's actual currency without relying on order payloads.
     *
     * @return string|null Three-letter currency code or null if unavailable
     */
    public function fetchStoreCurrency(Integration $integration): ?string
    {
        $result = $this->fetch($integration, 'shop.json');

        if (! $result['ok'] || ! is_array($result['body'])) {
            return null;
        }

        $value = trim((string) ($result['body']['shop']['currency'] ?? ''));

        return mb_strlen($value) === 3 ? mb_strtoupper($value) : null;
    }
}
