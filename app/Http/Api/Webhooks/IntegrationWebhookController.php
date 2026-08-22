<?php

declare(strict_types=1);

namespace App\Http\Api\Webhooks;

use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Models\IntegrationLink;
use App\Domain\Integrations\PlatformRegistry;
use App\Domain\Integrations\PullSync;
use App\Domain\Tenancy\Models\Account;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A shop telling us something changed.
 *
 * ── Why this endpoint is public ──────────────────────────────────────────────
 *
 * A shop has no session and no CSRF token. It has a URL and, if we are lucky, a
 * signature. So the token in the path says *which connection* is being
 * addressed, and the signature says whether the body can be trusted — and the
 * two are checked in that order, because there is no point verifying a payload
 * against a secret before knowing whose secret to use.
 *
 * ── Why almost everything answers 2xx ────────────────────────────────────────
 *
 * Every platform retries a non-2xx, backs off, and eventually disables the
 * webhook. So a 500 for a record this application could not make sense of does
 * not get the record in later — it loses the webhook altogether, silently, and
 * the shop stops talking to us. Accepted-and-recorded is the right answer to
 * anything that reached us with a valid signature.
 *
 * The exceptions are the two that mean "you have the wrong address or the wrong
 * secret", which a shop's own settings screen needs to hear.
 */
class IntegrationWebhookController
{
    public function __invoke(
        Request $request,
        string $token,
        PlatformRegistry $registry,
        PullSync $sync,
    ): JsonResponse {
        /*
         * Found by token alone, without a tenant.
         *
         * There is no session here, so the global account scope has nothing to
         * filter by and would match nothing. The token is the credential: 64
         * random characters, unique, and generated rather than chosen.
         */
        $integration = Integration::withoutGlobalScopes()
            ->whereNotNull('webhook_token')
            ->where('webhook_token', $token)
            ->first();

        if ($integration === null) {
            // The one case worth a 404: nothing here answers to that address.
            return response()->json(['message' => 'Unknown webhook address.'], 404);
        }

        $driver = $registry->for($integration);

        /*
         * Debug logging for webhook headers and payload
         */
        \Log::info('Webhook received', [
            'integration_id' => $integration->id,
            'integration_name' => $integration->name,
            'headers' => $request->headers->all(),
            'signature_header' => $request->header('X-WC-Webhook-Signature'),
            'topic_header' => $request->header('X-WC-Webhook-Topic'),
            'payload_size' => strlen($request->getContent()),
        ]);

        /*
         * Proven genuine, or refused.
         *
         * Refused loudly with a 401 rather than quietly ignored, because a shop
         * whose secret does not match ours has a configuration problem its owner
         * can only find out about from the shop's own delivery log.
         */
        if (! $driver->verifyWebhook($integration, $request)) {
            // Recorded, so the connection can say so on screen. A refusal that
            // leaves no trace here is the failure that took longest to find:
            // the shop keeps delivering, we keep refusing, and both sides go
            // on reporting that everything is configured.
            $integration->rememberDelivery(false, 'The signature did not match the secret held here.');

            return response()->json(['message' => 'That signature could not be verified.'], 401);
        }

        $payload = $request->json()->all();

        if (! is_array($payload) || $payload === []) {
            return response()->json(['ok' => true, 'ignored' => 'empty body']);
        }

        // What kind of event, in this application's own vocabulary. Null means
        // one we have no interest in, which is most of them.
        $event = $driver->webhookEvent($request, $payload);
        $entity = $this->entityFor($event);

        if ($entity === null) {
            return response()->json(['ok' => true, 'ignored' => $event ?? 'unrecognised event']);
        }

        $externalId = $this->externalId($payload);
        $link = $externalId === null ? null : IntegrationLink::withoutGlobalScopes()
            ->where('integration_id', $integration->id)
            ->where('entity', $entity)
            ->where('external_id', $externalId)
            ->first();

        if ($link !== null && $link->isEcho($payload)) {
            $link->recordPull();

            return response()->json(['ok' => true, 'ignored' => 'outbound echo']);
        }

        /*
         * Everything from here runs as the connection's own tenant.
         *
         * ── Why this is not optional ─────────────────────────────────────────
         *
         * The connection was found without a tenant, deliberately — there is no
         * session on a webhook. But the import that follows is full of scoped
         * reads: does this SKU already exist, is this buyer already a customer,
         * have we seen this order number. With no account in context the global
         * scope answers every one of them with `1 = 0`.
         *
         * Nothing errors. The import simply concludes the business is empty and
         * creates everything afresh — a second customer, a second product, a
         * second variant — until it reaches a unique index and the whole record
         * is refused. So a shop's first webhook arrived and every later one
         * failed, which is the worst possible shape for this bug: it looks like
         * it works.
         */
        $report = $this->asTenant($integration, fn () => $sync->single($integration, $entity, $payload));

        $integration->rememberDelivery(! $report->failed(), $report->error);

        /*
         * 200 even when the record was skipped. It arrived, it was verified, and
         * the reason it could not be used is recorded — a retry would produce
         * the same result and eventually cost us the webhook.
         */
        return response()->json([
            'ok' => ! $report->failed(),
            'event' => $event,
            'result' => $report->toArray(),
        ]);
    }

    /**
     * Adopt the tenancy of whoever owns this connection, for one call.
     *
     * Loaded without global scopes for the same reason the connection was: at
     * this point there is no tenant, so a scoped read of the account would be
     * filtered by the very thing it is being fetched to establish.
     *
     * The business matters as much as the account. Records written during an
     * import — an order line, a variant — take their business from context when
     * the caller does not pass one, and refuse to be written at all when there
     * is none.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asTenant(Integration $integration, callable $callback): mixed
    {
        $context = app(TenantContext::class);

        $account = Account::withoutGlobalScopes()->find($integration->account_id);
        $business = Business::withoutGlobalScopes()->find($integration->business_id);

        $wasAccount = $context->account();
        $wasBusiness = $context->business();

        $context->setAccount($account);
        $context->setBusiness($business);

        try {
            return $callback();
        } finally {
            // Put back even when the import threw, so nothing downstream in
            // this process inherits a tenant it was never given.
            $context->setAccount($wasAccount);
            $context->setBusiness($wasBusiness);
        }
    }

    /**
     * Which entity an event concerns.
     *
     * Deletions are deliberately absent. A shop deleting an order does not mean
     * this business should lose its record of a sale — that is a document in a
     * set of books, and removing it is a decision for a person, not an echo of
     * somebody tidying up a storefront.
     */
    private function entityFor(?string $event): ?string
    {
        if ($event === null) {
            return null;
        }

        return match (true) {
            str_starts_with($event, 'order.') => 'order',
            str_starts_with($event, 'product.') => 'product',
            str_starts_with($event, 'customer.') => 'customer',
            default => null,
        };
    }

    /** @param array<string, mixed> $payload */
    private function externalId(array $payload): ?string
    {
        foreach (['id', 'order_id', 'product_id', 'external_id', 'externalId'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                return (string) $payload[$key];
            }
        }

        foreach (['data', 'order', 'product'] as $container) {
            if (is_array($payload[$container] ?? null)) {
                $id = $this->externalId($payload[$container]);
                if ($id !== null) {
                    return $id;
                }
            }
        }

        return null;
    }
}
