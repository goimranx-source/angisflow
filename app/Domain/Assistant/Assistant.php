<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Identity\Models\User;
use App\Support\Navigation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The question box that answers instead of filtering.
 *
 * ── Why it is grounded in the menu ───────────────────────────────────────────
 *
 * A model asked "where do I record a refund" with no context will invent a
 * plausible-sounding menu path, and a plausible-sounding wrong answer is worse
 * than no answer — the person goes looking for a screen that does not exist and
 * concludes the tool is broken. So the catalogue this subscriber can actually
 * see is handed over as the ground truth, including which departments are still
 * unbuilt, and the model is told to answer only from it.
 *
 * That also makes the answer *this* subscriber's answer: the catalogue is
 * already filtered by their capabilities, so it cannot point somebody at a
 * screen their role does not grant.
 */
class Assistant
{
    public static function isConfigured(): bool
    {
        return config('assistant.enabled') && filled(config('assistant.key'));
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{answer: string, pages: list<array{key: string, label: string, href: string, built: bool}>}
     */
    public function ask(User $user, string $question, array $history = []): array
    {
        $tenant = app(\App\Domain\Tenancy\TenantContext::class);
        $nav = Navigation::forUser($user, $tenant->workspace(), $tenant->business());
        $pages = $this->flatten($nav);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.config('assistant.key'),
                'HTTP-Referer' => config('app.url'),
                'X-Title' => config('app.name'),
            ])
            ->timeout(30)
            ->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => config('assistant.model'),
                'max_tokens' => (int) config('assistant.max_tokens'),
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt($pages)],
                    ...$history,
                    ['role' => 'user', 'content' => $question],
                ],
                'temperature' => 0.7,
            ]);

            Log::info('OpenRouter API Response', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            if ($response->failed()) {
                Log::error('OpenRouter API failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return ['answer' => 'I could not work that out right now.', 'pages' => []];
            }

            $data = $response->json();
            
            if (!isset($data['choices'][0]['message']['content'])) {
                Log::error('OpenRouter API: Invalid response format', ['data' => $data]);
                return ['answer' => 'I could not work that out.', 'pages' => []];
            }

            $content = $data['choices'][0]['message']['content'];

            // Try to parse as JSON first
            $decoded = json_decode($content, true);
            
            if (!is_array($decoded)) {
                // If not JSON, try to extract JSON from the content
                if (preg_match('/\{.*\}/s', $content, $matches)) {
                    $decoded = json_decode($matches[0], true);
                }
            }

            if (!is_array($decoded)) {
                Log::warning('OpenRouter API: Could not parse response as JSON', ['content' => $content]);
                return ['answer' => $content, 'pages' => []];
            }

            // Page keys come back as keys, not URLs — the model is never the source
            // of a link. Anything it names is resolved against the catalogue, so an
            // invented page simply resolves to nothing.
            $byKey = collect($pages)->keyBy('key');

            return [
                'answer' => $decoded['answer'] ?? 'I could not work that out.',
                'pages' => collect($decoded['pages'] ?? [])
                    ->map(fn ($key) => $byKey->get((string) $key))
                    ->filter()
                    ->take(4)
                    ->map(fn (array $page) => [
                        'key' => $page['key'],
                        'label' => $page['label'],
                        'href' => $page['href'],
                        'built' => $page['built'],
                    ])
                    ->values()
                    ->all(),
            ];
        } catch (\Throwable $e) {
            Log::error('OpenRouter API Exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return ['answer' => 'I could not work that out right now.', 'pages' => []];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     */
    private function systemPrompt(array $pages): string
    {
        $catalogue = collect($pages)
            ->map(fn (array $page) => sprintf(
                '- %s (key: %s)%s — %s',
                $page['label'],
                $page['key'],
                $page['built'] ? '' : ' [not built yet]',
                $page['summary'] ?: 'No description.',
            ))
            ->implode("\n");

        return <<<PROMPT
        You answer questions about Angisflow, an ERP tool for running a business —
        orders, stock, production, staff, and money.

        Below is every page this particular person can reach, and what each one
        is for. It is the complete and only list. Some are marked as not built
        yet: those are planned, and saying so plainly is the correct answer for
        them — never imply an unbuilt page is usable.

        {$catalogue}

        Answer only from that list and from what an ERP of this kind ordinarily
        does. If the answer is not in the list, say so in one sentence rather
        than guessing — a confident wrong direction costs somebody a search
        through a menu that never had what they wanted.

        Keep it to three short sentences at most. Write plainly, in prose, with
        no markdown, no headings, and no bullet points; this is read inside a
        small search box, not a document. Name pages by their label, and put
        their keys in the pages field so they can be linked.

        Always respond with a JSON object in this exact format:
        {"answer": "your answer here", "pages": ["page-key-1", "page-key-2"]}
        PROMPT;
    }

    /**
     * @param  list<array<string, mixed>>  $nav
     * @return list<array<string, mixed>>
     */
    private function flatten(array $nav): array
    {
        $pages = [];

        foreach ($nav as $section) {
            foreach ($section['items'] as $item) {
                $pages[] = $item;
            }
        }

        return $pages;
    }
}
