<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Reconcilers;

use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Support\MappedRecord;
use App\Domain\Sales\Models\Customer;

/**
 * A shop's customer, becoming ours.
 *
 * The simplest of the three, and the one where getting it wrong is quietest: a
 * duplicated customer looks fine until somebody notices the same person's
 * history split across two records, months later.
 */
class CustomerReconciler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function apply(Integration $integration, MappedRecord $record, array $payload, ?int $localId): ?Customer
    {
        $attributes = $record->attributes;

        // A customer with no name is unusable on every screen that lists one.
        // Falling back to the email's local part beats a row reading "—", and
        // beats refusing an otherwise valid record.
        if (trim((string) ($attributes['name'] ?? '')) === '') {
            $email = (string) ($attributes['email'] ?? '');
            $attributes['name'] = $email === '' ? 'Guest customer' : ucfirst(strtok($email, '@') ?: 'Customer');
        }

        if ($localId !== null) {
            $customer = Customer::query()->find($localId);

            if ($customer !== null) {
                /*
                 * Only what the shop actually sent. `attributes` already omits
                 * anything absent from the payload, so a partial webhook cannot
                 * blank a phone number somebody typed in here.
                 */
                $customer->fill($attributes)->save();

                return $customer;
            }
        }

        return Customer::query()->create([
            'account_id' => $integration->account_id,
            'business_id' => $integration->business_id,
            'is_active' => true,
            ...$attributes,
        ]);
    }
}
