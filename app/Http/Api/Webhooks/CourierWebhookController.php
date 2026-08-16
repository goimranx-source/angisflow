<?php

declare(strict_types=1);

namespace App\Http\Api\Webhooks;

use App\Domain\Delivery\WebhookReceiver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The URL a subscriber gives their courier.
 *
 * Deliberately outside the authenticated API: a courier has no session and
 * never will. The connection's unguessable public id in the path is what
 * identifies it, and the signature — where the courier can sign — is what
 * proves it.
 *
 * Accepts any method a courier might use. Several send GET with query
 * parameters, which is poor practice and entirely real.
 */
class CourierWebhookController
{
    public function __construct(
        private readonly WebhookReceiver $receiver,
    ) {}

    public function __invoke(Request $request, string $connection): JsonResponse
    {
        $body = $request->getContent();

        // A GET or form post has no JSON body. Rebuilding one from the input
        // means the same parsing path handles every courier, rather than a
        // branch per transport that only one of them exercises.
        if (trim($body) === '' && $request->all() !== []) {
            $body = json_encode($request->all(), JSON_THROW_ON_ERROR);
        }

        $result = $this->receiver->receive(
            $connection,
            $body,
            $request->headers->all(),
            $request->ip(),
        );

        return response()->json([
            'received' => true,
            'id' => $result['delivery']->public_id,
            'message' => $result['message'],
        ], $result['status']);
    }
}
