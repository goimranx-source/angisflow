<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Integrations\Contracts\ManagesWebhooks;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Support\RemoteWebhook;
use Illuminate\Support\Str;

/**
 * Makes the shop call us, and keeps it calling us.
 *
 * ── The problem this exists to remove ────────────────────────────────────────
 *
 * A webhook only works while three things agree: the shop's delivery URL, the
 * shop's secret, and the secret held here. Nothing checks that agreement, and
 * nothing announces when it breaks. The connection goes on testing green — a
 * test only proves we can reach the shop, which says nothing about whether the
 * shop can reach us — while orders quietly stop arriving.
 *
 * They break for ordinary reasons. The application moves and every delivery URL
 * is stale. A tunnel restarts during development and does the same. The shop
 * gives up on a webhook after a run of failures and disables it without telling
 * anybody. A secret gets changed on one side.
 *
 * All three are things we can read and write through the shop's own API, so
 * none of them should be a person's problem. This reconciles them: it finds our
 * webhooks by the one part that never changes, repairs whatever has drifted,
 * creates what is missing, and leaves everybody else's webhooks alone.
 */
final class WebhookProvisioner
{
    public function __construct(private readonly PlatformRegistry $registry) {}

    /** Can this connection's platform do any of this? */
    public function supported(Integration $integration): bool
    {
        return $this->registry->for($integration) instanceof ManagesWebhooks;
    }

    /**
     * What the shop currently has, without changing any of it.
     *
     * Read-only on purpose. A settings screen showing the true state — pointing
     * at the right address, disabled, missing entirely — is what turns "orders
     * stopped arriving" from a mystery into a sentence.
     *
     * @return array<string, mixed>
     */
    public function status(Integration $integration): array
    {
        $driver = $this->registry->for($integration);

        if (! $driver instanceof ManagesWebhooks) {
            return ['supported' => false, 'topics' => [], 'url' => null];
        }

        $url = $this->deliveryUrl($integration);
        $token = (string) $integration->webhook_token;
        $ours = $this->ours($driver->listWebhooks($integration), $token);

        $topics = [];

        foreach ($driver->webhookTopics() as $topic) {
            $existing = $ours[$topic] ?? null;

            $topics[] = [
                'topic' => $topic,
                'state' => match (true) {
                    $existing === null => 'missing',
                    ! $existing->active => 'disabled',
                    $existing->url !== $url => 'wrong-address',
                    default => 'ok',
                },
                'url' => $existing?->url,
            ];
        }

        /*
         * What the shop's last actual call did, which the list above cannot
         * know.
         *
         * Every topic can read 'ok' — registered, active, pointing here — while
         * every delivery is refused for a secret that does not match. Nothing
         * about the registration reveals that, because the secret cannot be
         * read back to compare. Only a real call can tell us, so a real call is
         * what this reports.
         *
         * It is also why `healthy` is not decided by the topic list alone. A
         * screen that says everything is fine over a shop whose orders are all
         * being rejected is the exact failure this whole feature exists to
         * stop — and saying nothing would have been better, because at least
         * nobody would have believed it.
         */
        $last = $integration->lastDelivery();
        $refused = $last !== null && $last['accepted'] === false;

        return [
            'supported' => true,
            'url' => $url,
            'has_secret' => (string) $integration->config('webhook_secret') !== '',
            'topics' => $topics,
            'last_delivery' => $last,
            // One flag for a screen that only wants to know whether to show a
            // warning, rather than render four rows of detail.
            'healthy' => ! $refused && ! collect($topics)->contains(fn (array $t): bool => $t['state'] !== 'ok'),
        ];
    }

    /**
     * Put it right, whatever is wrong with it.
     *
     * ── Why the secret is always written ─────────────────────────────────────
     *
     * Because it cannot be read. Woo will not hand back a webhook's secret —
     * quite correctly — so there is no way to compare the shop's against ours
     * and repair only on mismatch. The choice is to write it every time or to
     * never be sure, and writing it is one field on a call already being made.
     *
     * ── Why a secret is generated rather than asked for ──────────────────────
     *
     * A secret nobody types is a secret nobody mistypes, and its only job is to
     * be identical in two places. Asking somebody to invent one, then paste it
     * into two admin screens without transposing a character, adds a failure
     * mode and no security.
     *
     * @return array<string, mixed>
     */
    public function reconcile(Integration $integration, bool $rotate = false): array
    {
        $driver = $this->registry->for($integration);

        if (! $driver instanceof ManagesWebhooks) {
            return ['ok' => false, 'message' => 'This platform cannot have its webhooks set up from here.'];
        }

        $url = $this->deliveryUrl($integration);
        $token = (string) $integration->webhook_token;

        $ours = $this->ours($driver->listWebhooks($integration), $token);

        $created = [];
        $repaired = [];
        $failed = [];

        /*
         * Ensure secret exists and is saved BEFORE creating/updating webhooks.
         *
         * ── Critical fix for signature mismatch ──────────────────────────────
         *
         * The secret must be persisted to the database BEFORE we write it to
         * WooCommerce. Otherwise, if a webhook is created successfully but the
         * Integration save fails or is interrupted, WooCommerce has a secret
         * that doesn't match what we have saved — causing all future webhook
         * deliveries to fail signature verification.
         *
         * The fix:
         * 1. Ensure secret exists and is saved to Integration model
         * 2. Reload the integration to confirm the secret is persisted
         * 3. Only THEN create/update webhooks with that secret
         * 4. If rotation is requested and all succeed, rotate afterward
         */

        // Ensure we have a secret saved
        $currentSecret = (string) $integration->config('webhook_secret');
        if ($currentSecret === '') {
            $currentSecret = Str::random(48);
            $integration->configuration = [
                ...($integration->configuration ?? []),
                'webhook_secret' => $currentSecret,
            ];
            $integration->save();
            $integration->refresh(); // Ensure it's persisted
        }

        $secret = $currentSecret;

        foreach ($driver->webhookTopics() as $topic) {
            $webhook = $ours[$topic] ?? null;

            if ($webhook === null) {
                if ($driver->createWebhook($integration, $topic, $url, $secret) !== null) {
                    $created[] = $topic;
                } else {
                    $failed[] = $topic;
                }

                continue;
            }

            /*
             * Everything it could need, in one call.
             *
             * Re-enabling matters most. A shop that disabled a webhook after a
             * run of failures will never enable it again on its own, so fixing
             * the cause — the address, the secret — without also turning it
             * back on leaves a connection that is correct and still silent.
             */
            $changes = ['secret' => $secret];

            if ($webhook->url !== $url) {
                $changes['url'] = $url;
            }

            if (! $webhook->active) {
                $changes['active'] = true;
            }

            if (! $driver->updateWebhook($integration, $webhook->id, $changes)) {
                $failed[] = $topic;

                continue;
            }

            // Reported as repaired only when something was actually wrong.
            // "Repaired 4 webhooks" every time somebody presses the button
            // trains people to ignore the number.
            if (count($changes) > 1) {
                $repaired[] = $topic;
            }
        }

        /*
         * Only rotate the secret if requested AND all webhooks succeeded.
         *
         * This ensures we never have a mismatch between what's saved here and
         * what's actually configured on the shop. If rotation was requested but
         * some webhooks failed, we keep the old secret — better to defer
         * rotation than to break working webhooks.
         */
        if ($rotate && $failed === []) {
            $newSecret = Str::random(48);
            $integration->rotateSigningSecret($newSecret);
        }

        return [
            'ok' => $failed === [],
            'created' => $created,
            'repaired' => $repaired,
            'failed' => $failed,
            'url' => $url,
            'message' => $this->describe($created, $repaired, $failed),
        ];
    }

    /**
     * Ours, keyed by topic.
     *
     * A shop can end up with two webhooks on one topic — somebody clicked twice,
     * or an earlier version of this created one before it knew how to find what
     * it had already made. The active one wins, so repair does not fix the
     * abandoned copy and leave the live one broken.
     *
     * @param  list<RemoteWebhook>  $webhooks
     * @return array<string, RemoteWebhook>
     */
    private function ours(array $webhooks, string $token): array
    {
        $ours = [];

        foreach ($webhooks as $webhook) {
            if (! $webhook->belongsTo($token)) {
                continue;
            }

            $held = $ours[$webhook->topic] ?? null;

            if ($held === null || (! $held->active && $webhook->active)) {
                $ours[$webhook->topic] = $webhook;
            }
        }

        return $ours;
    }

    /**
     * The one this connection already uses, or a new one.
     *
     * ── One secret, every webhook ────────────────────────────────────────────
     *
     * Woo gives each webhook its own secret field, which invites the idea that
     * four webhooks need four secrets and that this application is missing
     * three fields. It is not. The secret proves a body came from this shop —
     * a fact about the connection, not about the event — so one is held here
     * and written to every webhook below. Nobody types it anywhere.
     *
     * Rotation replaces it and keeps the old one valid until the next rotation,
     * so the seconds it takes to update four webhooks do not cost any orders.
     * See Integration::rotateSigningSecret.
     */
    private function secret(Integration $integration, bool $rotate = false): string
    {
        $secret = (string) $integration->config('webhook_secret');

        if ($secret !== '' && ! $rotate) {
            return $secret;
        }

        $fresh = Str::random(48);
        $integration->rotateSigningSecret($fresh);

        return $fresh;
    }

    /**
     * Where the shop should call.
     *
     * ── The path from the route, the host from configuration ─────────────────
     *
     * The path is generated rather than written out, so the day it changes
     * every connection can be repointed by pressing repair instead of by
     * editing a string in two places and hoping.
     *
     * The host cannot come from the same place. An absolute route() is built
     * from the host of the request being served, and the request being served
     * is somebody's browser — which, for anybody working locally, means
     * http://127.0.0.1:8000. Writing that into a live shop is worse than doing
     * nothing: the shop accepts it, reports the webhook as active, and posts
     * every order to an address that exists only on the developer's laptop.
     *
     * APP_URL is the one place that states where this application actually
     * answers from the outside, so that is what a shop is told.
     */
    private function deliveryUrl(Integration $integration): string
    {
        $path = route('api.v1.webhooks.integrations', ['token' => $integration->webhook_token], absolute: false);

        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * @param  list<string>  $created
     * @param  list<string>  $repaired
     * @param  list<string>  $failed
     */
    private function describe(array $created, array $repaired, array $failed): string
    {
        if ($failed !== []) {
            return 'The shop refused to set up '.implode(', ', $failed).'. Check the API key has write permission.';
        }

        $parts = [];

        if ($created !== []) {
            $parts[] = count($created).' created';
        }

        if ($repaired !== []) {
            $parts[] = count($repaired).' repaired';
        }

        return $parts === []
            ? 'The shop was already set up correctly.'
            : 'Webhooks '.implode(' and ', $parts).'. This shop will now tell us about new orders as they happen.';
    }
}
