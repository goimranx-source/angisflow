<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

use App\Domain\Integrations\Models\Integration;

/**
 * What this tool's statuses correspond to on one connected shop.
 *
 * ── Which way round this reads ───────────────────────────────────────────────
 *
 * Our statuses are the rows. For each one, the business picks the word that
 * shop uses for it.
 *
 * That is the direction people actually think in. Somebody setting this up knows
 * their own operation — they know what Completed means to them — and what they
 * are doing is finding its counterpart on the shop. Presented the other way
 * round, as a list of the shop's words to be explained one by one, the same
 * task becomes a quiz about somebody else's software.
 *
 * It also gives both directions from one answer. Reading in, the map is
 * inverted: their word arrives, we find whose counterpart it is. Pushing out, it
 * is used directly: our status goes out as their word. Two lists would let those
 * disagree.
 *
 * ── Where the choices come from ──────────────────────────────────────────────
 *
 * The shop, not from us. The options offered are the statuses that connection has
 * actually been seen to send, joined with whatever its platform documents — so a
 * bespoke site's own words appear once it has sent them, and nothing is invented.
 *
 * ── Precedence ───────────────────────────────────────────────────────────────
 *
 * What the business configured wins. Failing that, the platform's built-in table
 * (StatusVocabulary) still answers for the standard words. Failing that, nothing
 * is written: a status nobody has explained leaves the order exactly as it was.
 */
final readonly class StatusMap
{
    /** Where these live on the connection. */
    private const SETTING = 'status_map';

    /**
     * @param  array<string, string>  $rules  our status key => that shop's word
     */
    private function __construct(
        public Integration $integration,
        public string $entity,
        public array $rules,
    ) {}

    public static function for(Integration $integration, string $entity = 'order'): self
    {
        $stored = data_get($integration->sync_settings ?? [], self::SETTING.'.'.$entity, []);
        $ours = OrderStatuses::for($integration->business);
        $rules = [];

        if (is_array($stored)) {
            foreach ($stored as $ourKey => $theirWord) {
                $key = OrderStatuses::key((string) $ourKey);

                // A rule naming a status this business no longer has is dropped
                // rather than kept as a mapping to nowhere.
                if (! isset($ours[$key])) {
                    continue;
                }

                // Older saves recorded a list; take the first usable entry so a
                // mapping somebody made survives the change of shape.
                if (is_array($theirWord)) {
                    $theirWord = $theirWord[0] ?? '';
                }

                $theirWord = trim((string) $theirWord);

                if ($theirWord !== '') {
                    $rules[$key] = $theirWord;
                }
            }
        }

        return new self($integration, $entity, $rules);
    }

    /** This tool's statuses, for this connection's business. */
    public function ours(): array
    {
        return OrderStatuses::for($this->integration->business);
    }

    /**
     * A word arriving from the shop, in our terms.
     *
     * Inverted from the configured map: their word is matched against what each
     * of our statuses was pointed at, comparing on the flattened form so
     * 'On Hold', 'on-hold' and 'ON_HOLD' are one word.
     *
     * @return array<string, string> the columns this settles, if any
     */
    public function translate(?string $theirWord): array
    {
        $wanted = self::flatten((string) $theirWord);

        if ($wanted === '') {
            return [];
        }

        foreach ($this->rules as $ourKey => $theirs) {
            if (self::flatten($theirs) === $wanted) {
                return $this->ours()[$ourKey]['sets'] ?? ['status' => $ourKey];
            }
        }

        // Nothing configured for this word. The platform's own table is the next
        // best authority; for a bespoke site it says nothing, which is correct
        // until somebody maps it.
        return StatusVocabulary::order((string) $this->integration->provider, $theirWord);
    }

    /**
     * The other direction: our status, as this shop's word.
     *
     * Used by the push. Null when the business has not said what this status is
     * called over there — better to send nothing than to invent a word the shop
     * will reject or, worse, silently store.
     */
    public function toPlatform(string $ourStatus): ?string
    {
        return $this->rules[OrderStatuses::key($ourStatus)] ?? null;
    }

    /** Has the business said what this status is called on the shop? */
    public function covers(string $ourStatus): bool
    {
        return isset($this->rules[OrderStatuses::key($ourStatus)]);
    }

    /**
     * The words this shop sends that no status of ours claims.
     *
     * What the screen warns about: orders are arriving with these and nothing is
     * happening to them.
     *
     * @return list<string>
     */
    public function unclaimed(): array
    {
        $claimed = array_map(self::flatten(...), array_values($this->rules));
        $loose = [];

        foreach ($this->integration->seenStatuses($this->entity) as $word) {
            $key = self::flatten($word);

            if ($key === '' || in_array($key, $claimed, true) || isset($loose[$key])) {
                continue;
            }

            // Still covered by the platform's built-in table, so it is not
            // actually going unhandled — only unconfigured.
            if (StatusVocabulary::order((string) $this->integration->provider, $word) !== []) {
                continue;
            }

            $loose[$key] = $word;
        }

        return array_values($loose);
    }

    /**
     * Everything the mapping screen needs.
     *
     * @return array<string, mixed>
     */
    public function catalogue(): array
    {
        $rows = [];

        foreach ($this->ours() as $key => $status) {
            $rows[] = [
                'value' => $key,
                'label' => $status['label'],
                'tone' => $status['tone'],
                'custom' => $status['custom'],
                // What this status is called on the shop, or null.
                'mapped_to' => $this->rules[$key] ?? null,
            ];
        }

        return [
            'entity' => $this->entity,
            'ours' => $rows,
            'theirs' => $this->shopStatuses(),
            'unclaimed' => $this->unclaimed(),
        ];
    }

    /**
     * The words this shop can send.
     *
     * ── Three sources, in order of how much they know ────────────────────────
     *
     * The shop's own answer first. WooCommerce lets a plugin register an order
     * status, and shops use that for the parts of their process that are
     * actually theirs — "Shipped", "Follow-up", "Awaiting courier". Those are
     * the statuses a mapping screen exists to handle, and they are exactly the
     * ones no list written here could contain.
     *
     * Then what this connection has been seen to send, which covers the shop
     * that cannot be asked and confirms the ones that are genuinely in use.
     *
     * Then the platform's documented defaults, so a connection made five
     * minutes ago and never synced still offers something sensible.
     *
     * Marked rather than merged blindly: `observed` says this shop really sends
     * this word, which is worth knowing when choosing between two that look
     * alike.
     *
     * @return list<array{value: string, label: string, observed: bool}>
     */
    private function shopStatuses(): array
    {
        $seen = $this->integration->seenStatuses($this->entity);
        $reported = $this->integration->platformStatuses($this->entity);
        $known = StatusCatalogue::known((string) $this->integration->provider, $this->entity);

        $observed = [];

        foreach ($seen as $word) {
            $observed[self::flatten((string) $word)] = true;
        }

        $out = [];

        // The shop's own list carries labels; the other two are bare words, so
        // they are shaped to match rather than handled separately below.
        $candidates = [
            ...$reported,
            ...array_map(fn (string $w): array => ['value' => $w, 'label' => ''], [...$seen, ...$known]),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate['value'] ?? ''));
            $key = self::flatten($value);

            if ($key === '' || isset($out[$key])) {
                continue;
            }

            $label = trim((string) ($candidate['label'] ?? ''));

            $out[$key] = [
                'value' => $value,
                // Falls back to the word itself, so a selector always has
                // something to render rather than a blank row.
                'label' => $label !== '' ? $label : $value,
                'observed' => isset($observed[$key]),
            ];
        }

        return array_values($out);
    }

    /**
     * Save what the screen submitted: our status key => that shop's word.
     *
     * @param  array<string, mixed>  $input
     */
    public static function store(Integration $integration, array $input, string $entity = 'order'): void
    {
        $ours = OrderStatuses::for($integration->business);
        $clean = [];

        foreach ($input as $ourKey => $theirWord) {
            $key = OrderStatuses::key((string) $ourKey);

            if (is_array($theirWord)) {
                $theirWord = $theirWord[0] ?? '';
            }

            $theirWord = trim((string) $theirWord);

            // A blank clears the row, and a status we do not have is refused —
            // the same allowlist discipline the field targets use, for the same
            // reason: these values end up written into a column.
            if ($theirWord === '' || ! isset($ours[$key])) {
                continue;
            }

            $clean[$key] = mb_substr($theirWord, 0, 60);
        }

        $settings = $integration->sync_settings ?? [];
        data_set($settings, self::SETTING.'.'.$entity, $clean);

        $integration->sync_settings = $settings;
    }

    /**
     * Two spellings of one word are one word.
     *
     * Public because the connection uses it when recording what a shop has been
     * seen to send: two spellings must not become two rows.
     */
    public static function key(string $status): string
    {
        return self::flatten($status);
    }

    private static function flatten(string $status): string
    {
        $flat = mb_strtolower(trim($status));
        $flat = preg_replace('/[\s\-_]+/', ' ', $flat) ?? $flat;

        return trim($flat);
    }
}
