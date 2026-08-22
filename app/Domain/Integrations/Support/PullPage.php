<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

/**
 * One page of records from a shop, and how to ask for the next.
 *
 * The cursor is deliberately opaque. WooCommerce's is a page number, Shopify's
 * is a signed string that must be sent back byte for byte, and a bespoke site's
 * is whatever its author chose. The sync loop carries it from one call to the
 * next without inspecting it, which is the only way one loop can drive four
 * platforms that agree on nothing about paging.
 */
final readonly class PullPage
{
    /**
     * @param  list<array<string, mixed>>  $records  exactly as the platform sent them
     * @param  string|null  $next  pass back to continue; null when there is no more
     */
    public function __construct(
        public array $records = [],
        public ?string $next = null,
        public bool $failed = false,
        public ?string $message = null,
    ) {}

    /**
     * A page that could not be fetched.
     *
     * A value rather than an exception for the same reason ConnectionResult is
     * one: a shop being down mid-sync is an expected event, and the loop needs
     * to stop cleanly, record why, and leave the cursor where it was — not
     * unwind through a job handler that knows nothing about how far it got.
     */
    public static function failure(string $message): self
    {
        return new self(failed: true, message: $message);
    }

    public function isEmpty(): bool
    {
        return $this->records === [];
    }

    /** Is there another page to ask for? */
    public function hasMore(): bool
    {
        return ! $this->failed && $this->next !== null && $this->records !== [];
    }

    public function count(): int
    {
        return count($this->records);
    }
}
