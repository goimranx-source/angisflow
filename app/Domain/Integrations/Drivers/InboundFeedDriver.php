<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Drivers;

use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Support\Capabilities;
use App\Domain\Integrations\Support\ConfigField;
use App\Domain\Integrations\Support\ConnectionResult;
use Illuminate\Http\Request;

/**
 * A shop with no API, sending its orders to us.
 *
 * ── Why the direction is reversed ────────────────────────────────────────────
 *
 * Every other connection here works by asking: we hold credentials, we call the
 * shop, we read what changed. That needs the shop to have an API worth calling,
 * and plenty do not — a hand-built site, a platform whose API costs extra, an
 * old install nobody will upgrade.
 *
 * Those shops can still be connected, by turning the arrow round. They send us
 * an order when one is placed and again when it changes; we hold a secret and
 * check every delivery against it. Nothing about the order book downstream
 * cares which direction the data arrived from.
 *
 * ── Why this must be server-side, and not a snippet in a page ────────────────
 *
 * The obvious version of this idea is a tag pasted into the shop's template,
 * like an analytics pixel. It should not be built, and it is worth writing down
 * why so nobody proposes it again:
 *
 *   A script in a page runs on the customer's computer. Whatever key it carries
 *   is readable by anybody who opens the browser's inspector, which means
 *   anybody can post invented orders into somebody's books. An analytics
 *   product can live with that; an order book cannot — its whole value is being
 *   the thing you can trust when the money is counted.
 *
 *   It also only sees what a browser sees. An order taken over the phone, one
 *   entered by staff, one where the customer closed the tab before the thank-you
 *   page rendered, a status changed later in the shop's admin — all invisible,
 *   and invisible silently, which is the worst way for an order to be missing.
 *
 * Server-side, none of that applies: the secret stays on the shop's own machine,
 * every order is seen however it was taken, and later changes arrive too.
 *
 * ── What this driver does and does not do ────────────────────────────────────
 *
 * It cannot pull, because there is nothing to pull from — which is why a
 * backfill of past orders is a spreadsheet import rather than a sync. It
 * receives, and it verifies. Pushing back is possible only if the shop chooses
 * to expose somewhere to push to, which by definition it has not.
 */
class InboundFeedDriver extends GenericRestDriver
{
    public function key(): string
    {
        return 'inbound_feed';
    }

    public function label(): string
    {
        return 'Send orders to us';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            entities: ['order'],
            // Nothing to call: this shop is the one doing the calling.
            canPull: false,
            canPush: false,
            supportsWebhooks: true,
            // The secret below is what makes a delivery provable rather than
            // merely plausible, and it is the whole reason this is safe.
            verifiesWebhooks: true,
            supportsIncremental: false,
        );
    }

    /**
     * One field, because there is only one thing we need.
     *
     * No address, because we never call them. No credentials for their side,
     * because there is no their-side API. Just the secret both ends sign with —
     * and it is generated rather than typed, since a secret somebody invents is
     * a secret somebody can guess.
     *
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField(
                key: 'webhook_secret',
                label: 'Signing secret',
                type: 'password',
                required: false,
                secret: true,
                help: 'Generated for you. Paste it into the snippet on your shop — it never travels in a request.',
            ),
        ];
    }

    /**
     * There is nothing to test until they send something.
     *
     * Reported honestly rather than as a pass: a green tick for a connection
     * that has never received anything would be the screen agreeing with itself.
     */
    public function testConnection(Integration $integration): ConnectionResult
    {
        // Read from the delivery note the receiver leaves behind, which is
        // where an inbound connection's only evidence of life lives.
        if (($integration->metadata['last_webhook']['at'] ?? null) !== null) {
            return ConnectionResult::ok('Receiving orders from your shop.');
        }

        return ConnectionResult::failed(
            'Nothing has arrived yet. Add the snippet to your shop and place a test order.',
        );
    }

    public function webhookEvent(Request $request, array $payload): ?string
    {
        // The sender says what happened; anything else is treated as an update,
        // which is the safe reading — a create we mistake for an update finds
        // nothing to update and inserts.
        return is_string($payload['event'] ?? null) ? $payload['event'] : 'order.updated';
    }
}
