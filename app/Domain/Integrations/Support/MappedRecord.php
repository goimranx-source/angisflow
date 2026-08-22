<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

/**
 * One external record, translated into our vocabulary.
 *
 * Deliberately not a model and not saved: this is the halfway point, after the
 * mapping has run and before anything has been decided about what to do with
 * it. The pull sync takes one of these and reconciles it against whatever it
 * already holds; keeping the translation separate from that decision is what
 * makes the translation testable without a database.
 *
 * ── Why absent fields are tracked ────────────────────────────────────────────
 *
 * `attributes` carries only what the shop actually sent. A field it omitted is
 * absent here rather than present-and-null, because filling a model with nulls
 * for everything unmentioned is how a partial webhook payload — which carries
 * six fields out of twenty — wipes the other fourteen.
 */
final readonly class MappedRecord
{
    /**
     * @param  array<string, mixed>  $attributes  writable columns, keyed as our own
     * @param  array<string, mixed>  $custom  custom fields, stored on the link
     * @param  list<string>  $skipped  mappings whose source the payload did not carry
     */
    public function __construct(
        public array $attributes = [],
        public array $custom = [],
        public array $skipped = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->attributes === [] && $this->custom === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'attributes' => $this->attributes,
            'custom' => $this->custom,
            'skipped' => $this->skipped,
        ];
    }
}
