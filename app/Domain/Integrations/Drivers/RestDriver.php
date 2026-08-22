<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Drivers;

use App\Domain\Integrations\Contracts\PlatformDriver;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Support\ConnectionResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * What every REST-and-JSON platform has in common.
 *
 * All four supported platforms are an HTTPS endpoint that takes a key and
 * returns JSON. Written out per driver, that similarity becomes four copies of
 * the same timeout, the same retry, the same "did it answer at all" handling —
 * and four places to fix when one of them turns out to be wrong.
 *
 * What is genuinely different — how a request is authenticated, what the
 * endpoints are called, how a webhook is signed — stays abstract and is
 * answered by each driver in a few lines.
 */
abstract class RestDriver implements PlatformDriver
{
    /**
     * Ten seconds, and no more — for anything somebody is watching.
     *
     * This is the ceiling on "Test connection". A shop having a bad day must
     * not hold a worker open, and a person waiting on a spinner has already
     * concluded it is broken long before a default timeout would fire.
     */
    protected const TIMEOUT = 10;

    /**
     * A minute, for work nobody is watching.
     *
     * ── Why a sync must not share the interactive ceiling ────────────────────
     *
     * Because the two are answering different questions. Ten seconds is right
     * for "is this shop reachable right now", where a slow answer is as good as
     * a no. It is entirely wrong for "fetch a hundred products", where the only
     * question is whether the records arrive at all.
     *
     * Shops are not fast and are not consistent. A listing that returns in two
     * seconds most of the time will take fifteen when the host is busy, a backup
     * is running, or a plugin decides to rebuild a cache — and under a shared
     * ten-second ceiling that ordinary Tuesday becomes cURL error 28 and a night
     * with no orders. Nothing was wrong with the shop, the credentials or the
     * data; we simply stopped listening too early.
     */
    protected const SYNC_TIMEOUT = 60;

    /** Where this shop lives, without a trailing slash to double up later. */
    abstract protected function baseUrl(Integration $integration): string;

    /** Add whatever this platform accepts as proof of identity. */
    abstract protected function authenticate(PendingRequest $request, Integration $integration): PendingRequest;

    /**
     * A cheap call that proves the credentials work.
     *
     * Deliberately something small and always present — a system endpoint, the
     * first page of one record — rather than a listing that could be slow on a
     * large shop or empty on a new one. "Zero orders" must not read as "cannot
     * connect".
     *
     * Takes the connection because one driver's probe is configuration rather
     * than a constant: a bespoke site is asked where its health endpoint is,
     * since nothing here could know.
     *
     * @return array{path: string, query: array<string, mixed>}
     */
    abstract protected function probe(Integration $integration): array;

    public function testConnection(Integration $integration): ConnectionResult
    {
        $base = rtrim($this->baseUrl($integration), '/');

        if ($base === '') {
            return ConnectionResult::failed('No address has been given for this shop yet.');
        }

        $probe = $this->probe($integration);
        $url = rtrim($base.'/'.ltrim($probe['path'], '/'), '/');

        try {
            $response = $this->client($integration)->get($url, $probe['query']);

            // The same second address fetch() tries — so Test and Sync agree
            // about whether a shop is reachable. Disagreeing is worse than
            // either answer: it tells somebody the connection is fine and then
            // fails every night.
            if (in_array($response->status(), [400, 404], true)) {
                $alternate = $this->alternateUrl($integration, $probe['path']);

                if ($alternate !== null) {
                    $retry = $this->client($integration)->get($this->withQuery($alternate, $probe['query']));

                    if ($retry->successful()) {
                        $response = $retry;
                    }
                }
            }
        } catch (\Throwable $e) {
            // DNS failure, refused connection, TLS problem, timeout. All of
            // them mean the same thing to the person reading it.
            return ConnectionResult::unreachable($base, $e->getMessage());
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return ConnectionResult::unauthorised($response->body());
        }

        if ($response->status() === 404) {
            return ConnectionResult::failed(
                "The address answered, but not with this shop's API. Check the URL points at the "
                .'shop itself rather than a page on it.',
                ['status' => 404, 'url' => $url],
            );
        }

        if (! $response->successful()) {
            return ConnectionResult::failed(
                self::explain($response->status(), $response->body(), $url),
                ['status' => $response->status(), 'response' => mb_substr($response->body(), 0, 300)],
            );
        }

        // Reached, authenticated, and speaking JSON. Anything else that goes
        // wrong from here is a mapping problem, not a connection one.
        if ($response->json() === null) {
            return ConnectionResult::failed(
                'The address answered but did not return JSON. It may be a web page rather than an API.',
                ['status' => $response->status()],
            );
        }

        return ConnectionResult::ok('Connected.', ['status' => $response->status()]);
    }

    public function webhookEvent(Request $request, array $payload): ?string
    {
        return null;
    }

    public function verifyWebhook(Integration $integration, Request $request): bool
    {
        // Refusing by default is the safe direction: a driver that has not said
        // how it proves a payload genuine cannot have one accepted on its
        // behalf. Overridden by each platform that can actually do it.
        return false;
    }

    /**
     * Fetch one page, and turn everything that can go wrong into a value.
     *
     * Shared because the failure modes are identical whatever the platform: the
     * shop is down, the credentials expired overnight, a proxy returned an HTML
     * error page. Written per driver, that is four copies of the same handling
     * and four chances to forget one.
     *
     * @param  array<string, mixed>  $query
     * @return array{ok: bool, body: mixed, status: int, message: string|null, headers: array<string, array<int, string>>}
     */
    protected function fetch(Integration $integration, string $path, array $query = []): array
    {
        $url = rtrim($this->baseUrl($integration), '/').'/'.ltrim($path, '/');

        try {
            $response = $this->client($integration, self::SYNC_TIMEOUT)->get($url, $query);
        } catch (\Throwable $e) {
            /*
             * One more try, and only for a read.
             *
             * A GET changes nothing, so repeating it cannot double anything —
             * unlike send(), which deliberately never retries. And the failures
             * worth retrying are exactly the ones that strike once: a timeout, a
             * refused connection, a TLS handshake that lost a packet. Giving up
             * on the first of those costs a whole entity for the night.
             */
            try {
                $response = $this->client($integration, self::SYNC_TIMEOUT)->get($url, $query);
            } catch (\Throwable) {
                return [
                    'ok' => false,
                    'body' => null,
                    'status' => 0,
                    'message' => self::describeTransport($e),
                    'headers' => [],
                ];
            }
        }

        /*
         * One retry down the platform's other address.
         *
         * WordPress is the reason this exists. Its REST API lives at
         * /wp-json/... only when the site uses pretty permalinks; with plain
         * ones that path is not routed and the site answers 400 or 404 while
         * being perfectly reachable and correctly configured. The same API is
         * always available as ?rest_route=..., so a shop that looks broken is
         * usually one query string away from working.
         *
         * Retried only on the statuses that mean "no such route", and only once.
         * A 401 is a credentials problem and would answer the same either way.
         */
        if (in_array($response->status(), [400, 404], true)) {
            $alternate = $this->alternateUrl($integration, $path);

            if ($alternate !== null) {
                try {
                    $retry = $this->client($integration)->get($this->withQuery($alternate, $query));

                    if ($retry->successful()) {
                        $response = $retry;
                    }
                } catch (\Throwable) {
                    // Keep the original answer; the alternate was a guess.
                }
            }
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'body' => null,
                'status' => $response->status(),
                'message' => self::explain($response->status(), $response->body(), $url),
                'headers' => [],
            ];
        }

        $body = $response->json();

        if ($body === null) {
            return [
                'ok' => false,
                'body' => null,
                'status' => $response->status(),
                'message' => 'The shop answered, but not with JSON.',
                'headers' => [],
            ];
        }

        return ['ok' => true, 'body' => $body, 'status' => $response->status(), 'message' => null, 'headers' => $response->headers()];
    }

    /**
     * A call that changes something on the platform.
     *
     * Separate from fetch() rather than a parameter on it, because the two
     * differ in the one way that matters: fetch() retries down a second address
     * when the first answers 400 or 404, and a write must never be retried on a
     * guess. A POST that timed out may or may not have created something, and
     * sending it again down another URL is how one webhook becomes two.
     *
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, body: mixed, status: int, message: string|null}
     */
    protected function send(Integration $integration, string $method, string $path, array $body = []): array
    {
        $url = rtrim($this->baseUrl($integration), '/').'/'.ltrim($path, '/');

        try {
            $response = $this->client($integration, self::SYNC_TIMEOUT)->send($method, $url, ['json' => $body]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'body' => null, 'status' => 0, 'message' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'body' => null,
                'status' => $response->status(),
                'message' => self::explain($response->status(), $response->body(), $url),
            ];
        }

        return ['ok' => true, 'body' => $response->json(), 'status' => $response->status(), 'message' => null];
    }

    /**
     * A second address for the same endpoint, where the platform has one.
     *
     * Null for platforms whose API lives in exactly one place, which is most of
     * them. See fetch() for when it is tried.
     */
    protected function alternateUrl(Integration $integration, string $path): ?string
    {
        return null;
    }

    /**
     * Fold a query array into a URL that already carries one.
     *
     * Needed because passing both a query string in the URL and an array to
     * get() discards the former — which silently turned
     * `?rest_route=/wc/v3/orders` into a request for the site's home page, and
     * the retry appeared to fail for reasons that had nothing to do with the
     * shop.
     *
     * @param  array<string, mixed>  $query
     */
    protected function withQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }

    /**
     * A transport failure, said in words rather than in libcurl.
     *
     * "cURL error 28: Operation timed out after 10003 milliseconds" names the
     * library, the errno and the exact millisecond, and answers none of the
     * questions a person actually has: whose fault, is it still broken, is there
     * anything to do. The underlying message is kept on the end for whoever does
     * want it.
     */
    protected static function describeTransport(\Throwable $e): string
    {
        $raw = $e->getMessage();
        $lower = mb_strtolower($raw);

        $plain = match (true) {
            str_contains($lower, 'timed out') || str_contains($lower, 'timeout') => 'The shop took too long to answer, twice. It is usually busy rather than broken — the next sync will pick up whatever was missed.',
            str_contains($lower, 'could not resolve') || str_contains($lower, 'name resolution') => 'That address could not be found. Check the shop address on this connection.',
            str_contains($lower, 'connection refused') || str_contains($lower, 'failed to connect') => 'Nothing answered at that address.',
            str_contains($lower, 'ssl') || str_contains($lower, 'certificate') => "The shop's security certificate could not be verified.",
            default => 'The shop could not be reached.',
        };

        return $plain.' ('.mb_substr($raw, 0, 160).')';
    }

    /**
     * What the shop actually said, rather than the number it said it with.
     *
     * ── Why the body matters more than the status ────────────────────────────
     *
     * A bare "400" is unactionable. WooCommerce, Shopify and most REST APIs put
     * the real reason in the body — `rest_invalid_param`, `woocommerce_rest_
     * cannot_view`, "Sorry, you cannot list resources" — and that sentence is
     * usually the whole diagnosis: a key without read permission, a parameter
     * the shop's version does not accept, a security plugin refusing the call.
     *
     * Throwing it away meant every failure looked identical on screen, and the
     * only way to tell them apart was to reproduce the request by hand.
     */
    protected static function explain(int $status, string $body, string $url): string
    {
        $decoded = json_decode($body, true);
        $said = is_array($decoded)
            ? trim((string) ($decoded['message'] ?? $decoded['errors'][0]['message'] ?? ''))
            : '';

        $code = is_array($decoded) ? trim((string) ($decoded['code'] ?? '')) : '';

        // An HTML body means something other than the API answered — a security
        // plugin, a firewall, a maintenance page. Saying so is more use than
        // quoting forty lines of markup.
        if ($said === '' && $body !== '' && ! is_array($decoded)) {
            $said = str_contains(mb_strtolower(mb_substr($body, 0, 400)), '<html')
                ? 'The shop answered with a web page rather than its API — often a security plugin or firewall blocking the request.'
                : mb_substr(trim(strip_tags($body)), 0, 200);
        }

        $prefix = match (true) {
            $status === 401 || $status === 403 => 'The shop refused these credentials',
            $status === 404 => 'The shop has no such endpoint',
            $status >= 500 => 'The shop hit an error of its own',
            default => "The shop answered with {$status}",
        };

        return $said === ''
            ? $prefix.'.'
            : $prefix.': '.$said.($code !== '' ? " ({$code})" : '');
    }

    /**
     * Does this body carry a signature made with one of our secrets?
     *
     * ── One secret per connection, not one per webhook ───────────────────────
     *
     * WooCommerce puts a secret field on every webhook, so a shop with four of
     * them can have four different secrets — while this holds one. That looks
     * like a mismatch, and the instinct is to store four.
     *
     * It is the wrong instinct, and no serious platform does it. Stripe signs
     * with one secret per endpoint; Shopify with one per app. The secret proves
     * a body came from the shop we think it came from — that is a fact about
     * the *connection*, and splitting it per event multiplies the number of
     * places that can be wrong by four while proving nothing extra. So one
     * secret is held here and written to every webhook the provisioner manages,
     * and nobody types it into anything.
     *
     * ── Why two are accepted ─────────────────────────────────────────────────
     *
     * Replacing a secret is not instantaneous. The moment a new one is written
     * to the shop, deliveries already in flight — and any webhook not yet
     * updated — are still signed with the old one, and refusing those means
     * losing real orders in the seconds it takes to roll round. Accepting the
     * previous secret as well closes that window, which is what makes rotating
     * one safe enough to actually do.
     */
    protected function signedWithOurSecret(Integration $integration, string $body, string $given): bool
    {
        if ($given === '') {
            \Log::warning('Webhook signature verification failed: no signature provided', [
                'integration_id' => $integration->id,
                'integration_name' => $integration->name,
            ]);

            return false;
        }

        $secrets = $integration->signingSecrets();

        if (empty($secrets)) {
            \Log::error('Webhook signature verification failed: no secrets configured', [
                'integration_id' => $integration->id,
                'integration_name' => $integration->name,
            ]);

            return false;
        }

        foreach ($secrets as $index => $secret) {
            $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));

            if ($this->signatureMatches($expected, $given)) {
                if ($index > 0) {
                    \Log::info('Webhook verified with previous secret (rotation in progress)', [
                        'integration_id' => $integration->id,
                        'integration_name' => $integration->name,
                    ]);
                }

                return true;
            }
        }

        \Log::warning('Webhook signature verification failed: signature mismatch', [
            'integration_id' => $integration->id,
            'integration_name' => $integration->name,
            'secrets_tried' => count($secrets),
            'given_signature_length' => mb_strlen($given),
            'body_length' => mb_strlen($body),
        ]);

        return false;
    }

    /** A configured client, with this platform's authentication already on it. */
    protected function client(Integration $integration, ?int $timeout = null): PendingRequest
    {
        return $this->authenticate(
            Http::timeout($timeout ?? self::TIMEOUT)->acceptJson()->asJson(),
            $integration,
        );
    }

    /**
     * A constant-time comparison of two signatures.
     *
     * hash_equals rather than ===: string comparison returns as soon as it
     * finds a difference, and the time that takes leaks how much of a guess was
     * right. It is a small leak and an entirely avoidable one.
     */
    protected function signatureMatches(string $expected, string $given): bool
    {
        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }
}
