<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Business;
use App\Http\Api\Endpoint;
use App\Support\BootPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessEndpoint extends Endpoint
{
    /**
     * Change which set of books the screens are showing.
     *
     * A POST, and the identifier is the business's public ULID. Both matter: a
     * GET that changes what a whole session sees can be triggered by an image
     * tag on somebody else's page, and a row id in a URL is an invitation to
     * try the next number along. The lookup runs through the account scope, so
     * the next number along belongs to nobody.
     */
    public function switch(Request $request): JsonResponse
    {
        $request->validate(['business' => ['required', 'string', 'size:26']]);

        /** @var User $user */
        $user = $request->user();

        $business = Business::query()
            ->wherePublicId($request->string('business')->value())
            ->where('is_active', true)
            ->with(['categories' => function ($query) {
                $query->with('parent');
            }])
            ->first();

        if ($business === null) {
            return response()->json(['message' => 'That business is not available.'], 404);
        }

        $user->forceFill(['current_business_id' => $business->id])->save();

        app(\App\Domain\Tenancy\TenantContext::class)->setBusiness($business);

        // The client empties its query cache when it sees this — everything it
        // holds belongs to the previous business. Returning the new shell in
        // the same response means the switch is one round trip, not three.
        return response()->json([
            'message' => "Now showing {$business->name}.",
            'switched_to' => $business->public_id,
            'boot' => BootPayload::build(),
        ]);
    }
}
