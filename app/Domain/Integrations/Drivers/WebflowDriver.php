<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Drivers;

use App\Domain\Integrations\Contracts\PullsRecords;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Support\Capabilities;
use App\Domain\Integrations\Support\ConfigField;
use App\Domain\Integrations\Support\PullPage;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;

/**
 * Webflow, through its Data API.
 *
 * ── Narrower than the others, and it says so ─────────────────────────────────
 *
 * Webflow is a site builder with commerce attached rather than a shop platform,
 * and its API reflects that: orders and products are there, customers are not
 * exposed the way they are elsewhere. Its capabilities leave 'customer' out
 * rather than claiming it and failing later — a settings screen offering a
 * customer sync that can never run is worse than one that quietly does not
 * offer it.
 *
 * Its webhooks carry no signature by default, so verifiesWebhooks is false and
 * inbound calls are refused unless a secret has been configured on the webhook
 * itself. Refusing is the right default for an endpoint anyone can reach.
 */
class WebflowDriver extends RestDriver implements PullsRecords
{
    private const API_VERSION = 'v2';

    private const PER_PAGE = 100;

    public function supportsEntity(Integration $integration, string $entity): bool
    {
        return in_array($entity, ['order', 'product'], true);
    }

    /**
     * Offset paging, and no changed-since filter.
     *
     * Webflow's commerce endpoints take offset and limit and nothing else —
     * which is why its capabilities report supportsIncremental as false. Every
     * sync reads the whole book, so this is one to schedule daily rather than
     * every five minutes, and the connection says so rather than pretending
     * otherwise.
     */
    public function pull(Integration $integration, string $entity, ?CarbonInterface $since = null, ?string $cursor = null): PullPage
    {
        if (! $this->supportsEntity($integration, $entity)) {
            return PullPage::failure("Webflow has no {$entity} endpoint.");
        }

        $offset = max(0, (int) ($cursor ?? 0));
        $site = (string) $integration->config('site_id');
        $path = $entity === 'order' ? "sites/{$site}/orders" : "sites/{$site}/products";

        $result = $this->fetch($integration, $path, ['offset' => $offset, 'limit' => self::PER_PAGE]);

        if (! $result['ok']) {
            return PullPage::failure((string) $result['message']);
        }

        $records = $result['body'][$entity === 'order' ? 'orders' : 'items'] ?? [];
        $records = is_array($records) ? array_values($records) : [];

        $total = (int) ($result['body']['pagination']['total'] ?? 0);
        $seen = $offset + count($records);
        $hasMore = $total > 0 ? $seen < $total : count($records) === self::PER_PAGE;

        return new PullPage($records, $hasMore ? (string) $seen : null);
    }

    public function key(): string
    {
        return 'webflow';
    }

    public function label(): string
    {
        return 'Webflow';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            entities: ['order', 'product'],
            canPull: true,
            // Orders can be updated (fulfilment), products created and edited.
            canPush: false,
            supportsWebhooks: true,
            // Only when a secret has been set on the webhook; see below.
            verifiesWebhooks: true,
            supportsIncremental: false,
        );
    }

    public function configSchema(): array
    {
        return [
            new ConfigField(
                key: 'site_id',
                label: 'Site ID',
                help: 'Webflow → Site settings → General → Site ID.',
            ),
            new ConfigField(
                key: 'api_token',
                label: 'API token',
                type: 'password',
                help: 'A site token with read and write scope for commerce.',
                secret: true,
            ),
            new ConfigField(
                key: 'webhook_secret',
                label: 'Webhook secret',
                type: 'password',
                required: false,
                help: 'Set when creating the webhook. Without it, incoming calls are refused.',
                secret: true,
            ),
        ];
    }

    protected function baseUrl(Integration $integration): string
    {
        return 'https://api.webflow.com/'.self::API_VERSION;
    }

    protected function authenticate(PendingRequest $request, Integration $integration): PendingRequest
    {
        return $request->withToken((string) $integration->config('api_token'));
    }

    protected function probe(Integration $integration): array
    {
        // The site itself: proves the token is valid and that it grants access
        // to this particular site, which are two separate ways to be wrong.
        return ['path' => 'sites/'.(string) $integration->config('site_id'), 'query' => []];
    }

    public function verifyWebhook(Integration $integration, Request $request): bool
    {
        $secret = (string) $integration->config('webhook_secret');

        if ($secret === '') {
            return false;
        }

        /*
         * Signed over timestamp + body, so a captured call cannot be replayed
         * indefinitely — the timestamp is part of what was signed, and one
         * older than five minutes is refused regardless of whether the
         * signature checks out.
         */
        $timestamp = (string) $request->header('X-Webflow-Timestamp', '');

        if ($timestamp === '' || abs(now()->getTimestampMs() - (int) $timestamp) > 300_000) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.':'.$request->getContent(), $secret);

        return $this->signatureMatches($expected, (string) $request->header('X-Webflow-Signature', ''));
    }

    public function webhookEvent(Request $request, array $payload): ?string
    {
        $type = $payload['triggerType'] ?? null;

        return match ($type) {
            'ecomm_new_order' => 'order.created',
            'ecomm_order_changed' => 'order.updated',
            'ecomm_inventory_changed' => 'product.updated',
            default => null,
        };
    }
}
