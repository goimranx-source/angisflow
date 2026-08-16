<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Auth;

use App\Http\Api\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Email verification, from the client's side.
 *
 * The link itself is a signed GET the mail client opens directly — see
 * routes/web.php — because a browser following a link out of an inbox is not
 * running our JavaScript and cannot make an API call.
 *
 * ── Why none of this is a wall ───────────────────────────────────────────────
 *
 * Verification is a prompt, not a gate. Mail is unreliable in a way logins are
 * not: it lands in spam, corporate filters eat it, and somebody typed their
 * address slightly wrong. Blocking a paying subscriber's whole business on a
 * message that may never arrive turns a hygiene measure into an outage. It is
 * asked for visibly and repeatedly, and required only for the specific things
 * that genuinely need a reachable address.
 */
class EmailVerificationEndpoint extends Endpoint
{
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'That address is already verified.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'A new verification link is on its way.']);
    }
}
