<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Drivers;

use App\Domain\Integrations\Contracts\PullsRecords;
use App\Domain\Integrations\Contracts\PushesRecords;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Support\Capabilities;
use App\Domain\Integrations\Support\ConfigField;
use App\Domain\Integrations\Support\FieldPath;
use App\Domain\Integrations\Support\PullPage;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;

/**
 * Any REST API, described rather than coded.
 *
 * ── The one that makes the requirement achievable ────────────────────────────
 *
 * Named platforms are the easy half. The hard half is the shop somebody built
 * themselves — a Laravel site, a Next.js storefront, an in-house system — which
 * by definition nobody here can write a driver for.
 *
 * The answer is to stop treating a driver as code. Everything that differs
 * between REST APIs is a small set of facts: where it lives, how it proves who
 * you are, what its endpoints are called, and where in the response the records
 * are. Those are fields on a form. Once they are configuration, connecting a
 * bespoke shop is a person filling in a form, not us shipping a release — and
 * that is the difference between supporting four platforms and supporting
 * whatever anybody actually runs.
 *
 * ── Where it stops ───────────────────────────────────────────────────────────
 *
 * It cannot guess a payload's shape, so a connection made this way is only as
 * good as its field mapping. It also cannot know how a bespoke site signs a
 * webhook, so it defines one scheme — HMAC-SHA256 over the raw body, in a named
 * header — and asks the site to follow it. Prescribing rather than detecting is
 * the only workable direction when the other end is unknown.
 */
class GenericRestDriver extends RestDriver implements PullsRecords, PushesRecords
{
    private const PER_PAGE = 100;

    public function supportsEntity(Integration $integration, string $entity): bool
    {
        return $this->entityPath($integration, $entity) !== null;
    }

    /**
     * Page-numbered, because that is what a Laravel API does by default.
     *
     * The one platform whose paging cannot be known, so it is assumed to be the
     * commonest convention — `?page=2` — and everything else about it is
     * configuration. A site that pages differently is not served by guessing
     * harder; it is served by its own endpoint returning everything, or by
     * pushing to us over a webhook.
     *
     * Stops on a short page rather than on a total, since a bespoke site may
     * report its count in any of a dozen shapes or not at all.
     */
    public function pull(Integration $integration, string $entity, ?CarbonInterface $since = null, ?string $cursor = null): PullPage
    {
        $path = $this->entityPath($integration, $entity);

        if ($path === null) {
            return PullPage::failure("No {$entity} path has been configured for this site.");
        }

        $page = max(1, (int) ($cursor ?? 1));
        $query = ['page' => $page, 'per_page' => self::PER_PAGE];

        $changedSince = (string) $integration->config('updated_since_param', '');

        if ($since !== null && $changedSince !== '') {
            $query[$changedSince] = $since->utc()->toIso8601String();
        }

        $result = $this->fetch($integration, $path, $query);

        if (! $result['ok']) {
            return PullPage::failure((string) $result['message']);
        }

        /*
         * Where the list lives is configuration — 'data' for a Laravel resource
         * collection, blank when the response is the list itself. Resolved
         * through FieldPath so a nested 'result.items' works as readily.
         */
        $at = (string) $integration->config('records_path', '');
        $records = $at === '' ? $result['body'] : FieldPath::resolve((array) $result['body'], $at, []);
        $records = is_array($records) ? array_values($records) : [];

        return new PullPage($records, count($records) === self::PER_PAGE ? (string) ($page + 1) : null);
    }

    private function entityPath(Integration $integration, string $entity): ?string
    {
        $key = match ($entity) {
            'order' => 'orders_path',
            'product' => 'products_path',
            'customer' => 'customers_path',
            default => null,
        };

        if ($key === null) {
            return null;
        }

        $path = trim((string) $integration->config($key, ''));

        // Blank means this site does not share that entity — skipped quietly
        // rather than attempted against the API root and failing oddly.
        return $path === '' ? null : $path;
    }

    public function key(): string
    {
        return 'generic_rest';
    }

    public function label(): string
    {
        return 'Custom site (REST API)';
    }

    public function capabilities(): Capabilities
    {
        /*
         * Reported at their most generous, because what this connection can do
         * is decided by what the person configuring it filled in, not by
         * anything knowable here. A site that gave no product endpoint simply
         * has no product sync to run; the sync layer skips an entity with no
         * path rather than failing on it.
         */
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
                key: 'base_url',
                label: 'API address',
                type: 'url',
                help: 'The root of the API, without a trailing slash. Every path below is added to it.',
                placeholder: 'https://mysite.com/api/v1',
            ),
            new ConfigField(
                key: 'auth_type',
                label: 'How it authenticates',
                type: 'select',
                help: 'Whatever the site expects on each request.',
                options: [
                    ['value' => 'bearer', 'label' => 'Bearer token'],
                    ['value' => 'header', 'label' => 'Custom header'],
                    ['value' => 'basic', 'label' => 'Username and password'],
                    ['value' => 'none', 'label' => 'None'],
                ],
            ),
            new ConfigField(
                key: 'auth_token',
                label: 'Token or password',
                type: 'password',
                required: false,
                secret: true,
            ),
            new ConfigField(
                key: 'auth_username',
                label: 'Username',
                required: false,
                help: 'Only for username and password.',
            ),
            new ConfigField(
                key: 'auth_header',
                label: 'Header name',
                required: false,
                help: 'Only for a custom header — the name the site expects, such as X-API-Key.',
                placeholder: 'X-API-Key',
            ),

            new ConfigField(
                key: 'probe_path',
                label: 'Health path',
                required: false,
                help: 'Something cheap that proves the credentials work. Left blank, the API root is used.',
                placeholder: '/ping',
            ),
            new ConfigField(
                key: 'orders_path',
                label: 'Orders path',
                required: false,
                help: 'Blank if this site does not share orders.',
                placeholder: '/orders',
            ),
            new ConfigField(
                key: 'products_path',
                label: 'Products path',
                required: false,
                placeholder: '/products',
            ),
            new ConfigField(
                key: 'customers_path',
                label: 'Customers path',
                required: false,
                placeholder: '/customers',
            ),
            new ConfigField(
                key: 'orders_create_path',
                label: 'Create orders path',
                required: false,
                help: 'POST path used when creating an order on this site.',
                placeholder: '/orders',
            ),
            new ConfigField(
                key: 'orders_update_path',
                label: 'Update orders path',
                required: false,
                help: 'Path template used when updating an order. Use {id} for the external id.',
                placeholder: '/orders/{id}',
            ),
            new ConfigField(
                key: 'products_create_path',
                label: 'Create products path',
                required: false,
                help: 'POST path used when creating a product on this site.',
                placeholder: '/products',
            ),
            new ConfigField(
                key: 'products_update_path',
                label: 'Update products path',
                required: false,
                help: 'Path template used when updating a product. Use {id} for the external id.',
                placeholder: '/products/{id}',
            ),

            new ConfigField(
                key: 'records_path',
                label: 'Where the records are',
                required: false,
                help: 'The key holding the list in a response — "data" for most Laravel APIs. '
                    .'Blank if the response is the list itself.',
                placeholder: 'data',
            ),
            new ConfigField(
                key: 'updated_since_param',
                label: 'Changed-since parameter',
                required: false,
                help: 'The query parameter that limits results to recent changes. Without one, every '
                    .'sync reads the whole catalogue.',
                placeholder: 'updated_after',
            ),

            new ConfigField(
                key: 'webhook_secret',
                label: 'Webhook secret',
                type: 'password',
                required: false,
                help: 'The site signs the raw body with HMAC-SHA256 using this, and sends the hex '
                    .'digest in the header named below. Without it, incoming calls are refused.',
                secret: true,
            ),
            new ConfigField(
                key: 'webhook_signature_header',
                label: 'Signature header',
                required: false,
                help: 'Where that signature arrives.',
                placeholder: 'X-Signature',
            ),
        ];
    }

    protected function baseUrl(Integration $integration): string
    {
        return rtrim((string) $integration->config('base_url'), '/');
    }

    protected function authenticate(PendingRequest $request, Integration $integration): PendingRequest
    {
        $token = (string) $integration->config('auth_token');

        return match ((string) $integration->config('auth_type', 'bearer')) {
            'bearer' => $token === '' ? $request : $request->withToken($token),
            'basic' => $request->withBasicAuth((string) $integration->config('auth_username'), $token),
            'header' => $token === ''
                ? $request
                : $request->withHeaders([
                    (string) $integration->config('auth_header', 'X-API-Key') => $token,
                ]),
            default => $request,
        };
    }

    protected function probe(Integration $integration): array
    {
        // The one driver whose probe is configuration: a bespoke site is asked
        // where its health endpoint is, because nothing here could know. Blank
        // falls back to the API root, which is usually enough to prove the
        // credentials are accepted.
        return ['path' => (string) $integration->config('probe_path', ''), 'query' => []];
    }

    public function verifyWebhook(Integration $integration, Request $request): bool
    {
        $secret = (string) $integration->config('webhook_secret');
        $header = (string) $integration->config('webhook_signature_header', 'X-Signature');

        if ($secret === '' || $header === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return $this->signatureMatches($expected, (string) $request->header($header, ''));
    }

    public function webhookEvent(Request $request, array $payload): ?string
    {
        // A bespoke site is asked to say what happened, in this product's own
        // vocabulary, in the body. Guessing from the payload's shape would be
        // fragile in a way nobody could debug from the outside.
        $event = $payload['event'] ?? $request->header('X-Event');

        return is_string($event) && $event !== '' ? $event : null;
    }

    public function create(Integration $integration, string $entity, array $payload): array
    {
        $path = trim((string) $integration->config($entity.'s_create_path', ''));

        if ($path === '') {
            return ['ok' => false, 'external_id' => null, 'body' => null, 'message' => "No create path configured for {$entity}."];
        }

        $result = $this->send($integration, 'POST', $path, $payload);

        return [
            'ok' => $result['ok'],
            'external_id' => $result['ok'] ? $this->externalId((array) ($result['body'] ?? [])) : null,
            'body' => $result['body'],
            'message' => $result['message'],
        ];
    }

    public function update(Integration $integration, string $entity, string $externalId, array $payload): array
    {
        $path = str_replace('{id}', rawurlencode($externalId), trim((string) $integration->config($entity.'s_update_path', '')));

        if ($path === '') {
            return ['ok' => false, 'body' => null, 'message' => "No update path configured for {$entity}."];
        }

        $result = $this->send($integration, 'PATCH', $path, $payload);

        return ['ok' => $result['ok'], 'body' => $result['body'], 'message' => $result['message']];
    }

    /** @param array<string, mixed> $body */
    private function externalId(array $body): ?string
    {
        foreach (['id', 'public_id', 'external_id'] as $key) {
            if (isset($body[$key]) && is_scalar($body[$key])) {
                return (string) $body[$key];
            }
        }

        foreach (['data', 'order', 'product'] as $container) {
            if (is_array($body[$container] ?? null)) {
                $id = $this->externalId($body[$container]);
                if ($id !== null) {
                    return $id;
                }
            }
        }

        return null;
    }
}
