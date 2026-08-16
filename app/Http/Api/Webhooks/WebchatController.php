<?php

declare(strict_types=1);

namespace App\Http\Api\Webhooks;

use App\Domain\Inbox\WebchatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The chat widget's own endpoints.
 *
 * Outside every auth group: a visitor on somebody else's website has no session
 * with us and never will. The widget key names the business; the visitor token
 * scopes everything after that. See WebchatService for the full reasoning.
 *
 * Every failure is the same shape and the same status. Telling a caller apart
 * "no such widget" from "wrong origin" from "expired token" is telling somebody
 * probing exactly which of their guesses was closest.
 */
class WebchatController
{
    public function __construct(
        private readonly WebchatService $chat,
    ) {}

    /** What the widget needs to draw itself. */
    public function boot(Request $request): JsonResponse
    {
        return $this->attempt(fn () => [
            'config' => $this->chat->boot(
                (string) $request->query('k', ''),
                $request->headers->get('Origin'),
            )['config'],
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:40'],
            'visitor_key' => ['nullable', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'page_url' => ['nullable', 'string', 'max:500'],
            'page_title' => ['nullable', 'string', 'max:190'],
            'referrer' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->attempt(function () use ($validated, $request) {
            $result = $this->chat->start($validated['key'], $request->headers->get('Origin'), [
                ...$validated,
                'user_agent' => $request->userAgent(),
                'ip' => $request->ip(),
            ]);

            return [
                // Null when an existing session was resumed — the browser
                // already holds a token and must keep using it.
                'token' => $result['token'],
                'visitor_key' => $result['session']->visitor_key,
                'conversation' => $result['conversation']->public_id,
            ];
        });
    }

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'body' => ['required', 'string', 'max:4000'],
            'client_id' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->attempt(fn () => [
            'id' => $this->chat->send($validated['token'], $validated['body'], $validated)->public_id,
        ]);
    }

    public function transcript(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'since' => ['nullable', 'date'],
        ]);

        return $this->attempt(fn () => $this->chat->transcript(
            $validated['token'],
            $validated['since'] ?? null,
        ));
    }

    public function identify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        return $this->attempt(function () use ($validated) {
            $this->chat->identify($validated['token'], $validated);

            return ['ok' => true];
        });
    }

    /**
     * One shape for every outcome.
     *
     * @param  callable(): array<string, mixed>  $work
     */
    private function attempt(callable $work): JsonResponse
    {
        try {
            return response()->json(['ok' => true, ...$work()]);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 403);
        }
    }
}
