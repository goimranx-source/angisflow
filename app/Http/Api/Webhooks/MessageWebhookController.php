<?php

declare(strict_types=1);

namespace App\Http\Api\Webhooks;

use App\Domain\Inbox\MessageWebhookReceiver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Where the messaging platforms deliver.
 *
 * GET and POST on one URL, because Meta uses the first for its subscription
 * handshake and the second for every message afterwards — and they must be the
 * same address or the handshake verifies an endpoint that will never receive
 * anything.
 */
class MessageWebhookController
{
    public function __construct(
        private readonly MessageWebhookReceiver $receiver,
    ) {}

    /**
     * Meta's subscription challenge.
     *
     * Answered as bare text, not JSON: Meta compares the body byte for byte
     * against the challenge it sent, and a quoted string fails the comparison
     * while looking perfectly correct in a log.
     */
    public function verify(Request $request, string $channel): Response
    {
        $challenge = $this->receiver->verifySubscription($channel, $request->query());

        return $challenge === null
            ? response('', 403)
            : response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, string $channel): JsonResponse
    {
        $result = $this->receiver->receive(
            $channel,
            $request->getContent(),
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
