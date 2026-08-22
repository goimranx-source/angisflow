<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Support\Capabilities;
use App\Domain\Integrations\Support\ConfigField;
use App\Domain\Integrations\Support\ConnectionResult;
use Illuminate\Http\Request;

/**
 * One external platform, as far as this product is concerned.
 *
 * ── Why this is an interface and not a match statement ───────────────────────
 *
 * The previous build branched on the platform name inside the sync service:
 *
 *     match ($platform) {
 *         'woocommerce' => $this->pullWooCommerce(...),
 *         'shopify'     => $this->pullShopify(...),
 *     }
 *
 * ...in five separate places. Two things follow from that, and both are fatal
 * to the requirement here. Adding a third platform is a five-site edit with no
 * compiler to tell you which site you missed. And connecting a shop nobody
 * anticipated — somebody's own Laravel site, the case this has to serve — is
 * impossible without shipping code, which means it never happens.
 *
 * Behind an interface, a platform is one class that either exists or does not,
 * and the generic driver turns "any REST API" into a form somebody fills in.
 * That is the difference between supporting four platforms and supporting the
 * long tail.
 *
 * ── What a driver is responsible for ─────────────────────────────────────────
 *
 * Speaking the platform's dialect, and nothing else. It knows how that shop
 * authenticates, what its endpoints are called, and how to tell a real webhook
 * from a forged one. It does not know what an order means here, does not touch
 * the ledger, and does not decide when to sync — those are the same for every
 * platform and live above this line.
 *
 * ── Why connection concerns only ─────────────────────────────────────────────
 *
 * Reading and writing records are deliberately absent. A platform that can
 * receive orders but not products, or accept a push but never be polled, is
 * ordinary rather than exceptional, and an interface demanding all of it would
 * force every driver to carry methods that throw. Those capabilities arrive as
 * their own interfaces a driver opts into, and capabilities() below is what the
 * rest of the system asks before offering anything.
 */
interface PlatformDriver
{
    /** Stable identifier stored in api_integrations.provider — 'woocommerce'. */
    public function key(): string;

    /** What a person setting this up would call it — 'WooCommerce'. */
    public function label(): string;

    /**
     * What this platform can actually do.
     *
     * Asked before the interface offers anything, so a shop that cannot accept
     * a pushed product never shows a "push products" switch. The alternative —
     * offering everything and failing at run time — teaches people that the
     * settings are decorative.
     */
    public function capabilities(): Capabilities;

    /**
     * The fields this platform needs before it can be connected.
     *
     * Returned as data rather than rendered as a form here, because the driver
     * has no business knowing how this product draws an input. It is also what
     * lets the generic driver exist at all: its schema is longer, and the
     * screen that renders it does not have to change to accommodate that.
     *
     * @return list<ConfigField>
     */
    public function configSchema(): array;

    /**
     * Prove the credentials work, before anything is saved.
     *
     * Never throws for an ordinary failure — bad credentials, a typo in the
     * URL, a shop that is down are all expected answers here, and an exception
     * would make each of them look like a fault in this product. The result
     * carries a message fit to show somebody.
     */
    public function testConnection(Integration $integration): ConnectionResult;

    /**
     * Is this inbound request genuinely from the platform?
     *
     * The webhook token in the URL says which connection is being addressed;
     * this says whether the body can be trusted. Each platform signs
     * differently, which is exactly the kind of detail that belongs in a driver
     * and nowhere else.
     *
     * False for anything unverifiable. A payload that cannot be proven genuine
     * is refused rather than processed hopefully — this endpoint is public by
     * necessity and is the one place an attacker can reach uninvited.
     */
    public function verifyWebhook(Integration $integration, Request $request): bool;

    /**
     * What kind of event a webhook body describes, in this product's words.
     *
     * Platforms disagree about where that is written — a header, a field, the
     * shape of the payload — so each driver answers for its own, and callers
     * get 'order.created' whichever shop it came from.
     *
     * Null when the event is one this product has no interest in, which is most
     * of them.
     *
     * @param  array<string, mixed>  $payload
     */
    public function webhookEvent(Request $request, array $payload): ?string;
}
