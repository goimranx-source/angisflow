<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Models\ProductVariant;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Models\IntegrationLink;
use App\Domain\Integrations\Support\MappedRecord;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Deciding whether an arriving record is one we already hold.
 *
 * ── The question that decides whether this feature is usable ─────────────────
 *
 * A business connects the shop it has been running for three years. It already
 * has those customers in this application — typed in, imported, or created by
 * the last sync. The first pull brings back eleven hundred of them.
 *
 * Get this wrong and the business now has two of every customer, each holding
 * half the history, and no way back short of a support conversation. That is not
 * a rough edge; it is the reason people distrust integrations.
 *
 * ── Three questions, in order ────────────────────────────────────────────────
 *
 * 1. Is there a link? Then it is that record, with no guessing involved. A link
 *    is a fact somebody established, and it outranks any resemblance.
 *
 * 2. Failing that, does something identify it — an email, a SKU? These are
 *    identifiers a shop and a business genuinely share, and matching on one is
 *    safe in a way that matching on a name is not.
 *
 * 3. Otherwise it is new, and the caller creates it.
 *
 * ── What it will not match on ────────────────────────────────────────────────
 *
 * Names, addresses, or anything else two different people can share. A wrong
 * match is worse than a duplicate: a duplicate is visible and can be merged,
 * while a wrong match silently files one person's orders under another's, and
 * every report built on it is quietly wrong from then on.
 */
class EntityLinker
{
    /** The record this external id already belongs to, if any. */
    public function find(Integration $integration, string $entity, string $externalId): ?IntegrationLink
    {
        return IntegrationLink::query()
            ->forIntegration((int) $integration->id)
            ->entity($entity)
            ->where('external_id', $externalId)
            ->first();
    }

    /** The other direction: this record's identity on that shop. */
    public function findLocal(Integration $integration, string $entity, int $localId): ?IntegrationLink
    {
        return IntegrationLink::query()
            ->forIntegration((int) $integration->id)
            ->forLocal($entity, $localId)
            ->first();
    }

    /** Everywhere one of our records is listed, across every connected shop. */
    public function everywhere(string $entity, int $localId, int $businessId): Collection
    {
        return IntegrationLink::query()
            ->where('business_id', $businessId)
            ->forLocal($entity, $localId)
            ->with('integration')
            ->get();
    }

    /**
     * Record that these two are the same thing.
     *
     * Written as an upsert against the unique constraint rather than a
     * check-then-insert, because two webhooks for the same new order can arrive
     * close enough together that both pass the check. The database settles it;
     * the loser here re-reads rather than failing.
     */
    public function link(
        Integration $integration,
        string $entity,
        int $localId,
        string $externalId,
        ?string $reference = null,
    ): IntegrationLink {
        $attributes = [
            'integration_id' => $integration->id,
            'entity' => $entity,
            'external_id' => $externalId,
        ];

        $values = [
            'account_id' => $integration->account_id,
            'business_id' => $integration->business_id,
            'linkable_id' => $localId,
            'external_reference' => $reference,
        ];

        try {
            return IntegrationLink::query()->updateOrCreate($attributes, $values);
        } catch (QueryException $e) {
            // Lost a race, or collided with the reverse constraint — this local
            // record already answers to a different external id on this shop.
            // Either way the existing row is the truth; re-read it.
            $existing = $this->find($integration, $entity, $externalId)
                ?? $this->findLocal($integration, $entity, $localId);

            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
    }

    /**
     * Find the record this one already is, without a link to say so.
     *
     * Only ever consulted for a record that has no link yet — a first sync, or
     * something created outside this application. Returns the local id, or null
     * when nothing identifies it well enough to be sure.
     *
     * @param  array<string, mixed>  $payload  as the platform sent it
     */
    public function match(Integration $integration, string $entity, MappedRecord $record, array $payload = []): ?int
    {
        $businessId = (int) $integration->business_id;

        return match ($entity) {
            IntegrationLink::CUSTOMER => $this->matchCustomer($record, $businessId),
            IntegrationLink::PRODUCT => $this->matchProduct($record, $payload, $businessId),
            IntegrationLink::ORDER => $this->matchOrder($integration, $record, $businessId),
            default => null,
        };
    }

    /**
     * A customer, by something only they have.
     *
     * Email first: it is what a shop authenticates on and what a business
     * recognises. Phone second, compared on digits alone so that +44 7700 900
     * 123 and 07700900123 are not treated as two people.
     *
     * A blank email matches nothing rather than matching every other customer
     * without one — which is the bug that turns a first sync into one enormous
     * merged customer.
     */
    private function matchCustomer(MappedRecord $record, int $businessId): ?int
    {
        $email = trim((string) ($record->attributes['email'] ?? ''));

        if ($email !== '') {
            $id = Customer::query()
                ->where('business_id', $businessId)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
                ->whereNull('merged_into_id')
                ->value('id');

            if ($id !== null) {
                return (int) $id;
            }
        }

        $phone = preg_replace('/\D/', '', (string) ($record->attributes['phone'] ?? '')) ?? '';

        // Short strings are not identifiers. A four-digit extension would match
        // half the book.
        if (mb_strlen($phone) < 7) {
            return null;
        }

        $id = Customer::query()
            ->where('business_id', $businessId)
            ->whereNull('merged_into_id')
            // Compared on the last nine digits so a country code written on one
            // side and not the other does not hide a genuine match.
            ->whereRaw("REPLACE(REPLACE(REPLACE(COALESCE(phone,''),' ',''),'-',''),'+','') LIKE ?", ['%'.mb_substr($phone, -9)])
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * A product, by SKU.
     *
     * The only identifier here worth trusting. Names collide constantly — every
     * catalogue has more than one "Black T-Shirt" — and a wrong product match
     * puts stock movements against the wrong item, which is a correction that
     * has to be made by hand in the ledger.
     *
     * The SKU is read from the payload rather than the mapped record because it
     * lives on a variant here, not on the product, and so is not one of the
     * product's own mappable fields.
     *
     * @param  array<string, mixed>  $payload
     */
    private function matchProduct(MappedRecord $record, array $payload, int $businessId): ?int
    {
        $sku = trim((string) ($payload['sku'] ?? ''));

        if ($sku === '') {
            return null;
        }

        $productId = ProductVariant::query()
            ->where('business_id', $businessId)
            ->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)])
            ->value('product_id');

        if ($productId === null) {
            return null;
        }

        // Confirmed against the products table so a variant left behind by a
        // soft-deleted product cannot resurrect it.
        return Product::query()->whereKey($productId)->value('id') === null ? null : (int) $productId;
    }

    /**
     * An order, by its number.
     *
     * ── Why the number is enough, and the storefront is not a filter ─────────
     *
     * This used to require the local order to already carry the connection's
     * storefront, on the reasoning that a business running a till and two
     * websites could have three things called 1001. The database says
     * otherwise: `orders` is uniquely indexed on (business_id, number), so
     * within one business a number identifies exactly one order and there is
     * no second 1001 to confuse it with.
     *
     * Filtering by storefront therefore could not prevent a wrong match — it
     * could only miss a right one. And it did, on the case that matters most:
     * an order already imported by an earlier tool, or arrived by webhook
     * before the connection was attached to a storefront, has no storefront on
     * it. Unmatched, the sync tried to insert a second order with the same
     * number, and the unique index refused — so a shop's existing history could
     * never be adopted, only rejected once per run.
     *
     * The storefront is now a guard rather than a filter. An order with none is
     * claimed for this connection's; one already belonging to a *different*
     * storefront is left alone and skipped visibly, which is the only case
     * where overwriting would be the wrong answer.
     */
    private function matchOrder(Integration $integration, MappedRecord $record, int $businessId): ?int
    {
        $number = trim((string) ($record->attributes['number'] ?? ''));

        if ($number === '') {
            return null;
        }

        /*
         * Trashed orders included, deliberately.
         *
         * The unique index on (business_id, number) counts a soft-deleted row
         * like any other, so an order trashed here while still live in the shop
         * is invisible to a normal lookup and impossible to insert past. The
         * sync therefore tried to create it, was refused by the database, and
         * reported an SQL constraint error — every run, for ever, over an order
         * somebody had deliberately thrown away.
         *
         * Finding it is what lets the caller say so plainly instead. It is not
         * resurrected: see PullSync, which leaves a trashed order alone.
         */
        $order = Order::withTrashed()
            ->where('business_id', $businessId)
            ->where('number', $number)
            ->first(['id', 'storefront_id']);

        if ($order === null) {
            return null;
        }

        $mine = $integration->storefront_id;

        // Somebody else's shop, under the same number. Refusing to touch it is
        // right; the sync reports the skip rather than silently rewriting an
        // order that belongs to another storefront's books.
        if ($mine !== null && $order->storefront_id !== null && (int) $order->storefront_id !== (int) $mine) {
            return null;
        }

        return (int) $order->id;
    }
}
