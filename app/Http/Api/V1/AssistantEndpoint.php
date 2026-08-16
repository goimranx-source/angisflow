<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Assistant\Assistant;
use App\Http\Api\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Ask a question instead of searching for one.
 *
 * ── Why the key never leaves the server ──────────────────────────────────────
 *
 * The obvious implementation calls the model from the browser, which puts a
 * billable credential in a JavaScript bundle that anybody can read. There is no
 * way to scope such a key to one subscriber and no way to take it back once it
 * is out. So the request comes here, and the server is the only thing that ever
 * holds the key.
 *
 * That also puts the spend under the same per-account limits as everything else
 * — a model call is the most expensive request this application can make, and
 * an unmetered one is a way for a single subscriber to run up somebody else's
 * bill.
 */
class AssistantEndpoint extends Endpoint
{
    public function ask(Request $request, Assistant $assistant): JsonResponse
    {
        if (! Assistant::isConfigured()) {
            return response()->json([
                'message' => 'The assistant is not switched on for this installation.',
            ], 503);
        }

        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:500'],
            // Earlier turns, so a follow-up can refer to what came before.
            // Capped: the whole history is re-sent and re-billed on every turn,
            // so an uncapped one is a conversation that gets more expensive the
            // longer somebody stays in it.
            'history' => ['nullable', 'array', 'max:8'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        $key = 'assistant:'.$user->account_id;

        // Per account, not per user and not per IP: the cost lands on the
        // account, so that is what has to be bounded.
        if (RateLimiter::tooManyAttempts($key, (int) config('assistant.rate_limit'))) {
            return response()->json([
                'message' => 'That is a lot of questions at once — try again in a moment.',
            ], 429)->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        try {
            $result = $assistant->ask($user, $validated['question'], $validated['history'] ?? []);
        } catch (Throwable $e) {
            report($e);

            // The provider's own error text can name models, quotas, and
            // account identifiers — none of which is a subscriber's business.
            return response()->json([
                'message' => 'The assistant could not answer that just now.',
            ], 502);
        }

        return response()->json(['data' => $result]);
    }
}
