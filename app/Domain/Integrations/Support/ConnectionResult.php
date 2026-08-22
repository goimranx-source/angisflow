<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

/**
 * Whether a shop answered, and what it said.
 *
 * ── Why failure is a value and not an exception ──────────────────────────────
 *
 * Every likely outcome of testing a connection is somebody's typo: the URL has
 * a trailing path, the key was pasted with a newline, the shop is behind
 * maintenance mode, the credentials were read-only. None of those is a fault in
 * this product, and throwing for them would put them in the error log beside
 * things that genuinely are, while showing the person a message written for a
 * developer.
 *
 * So an unreachable shop returns a result, carrying a sentence fit to put on
 * screen. Exceptions stay for what they are for — something here being broken.
 *
 * ── The detail is for us, the message is for them ────────────────────────────
 *
 * `detail` holds the status code and whatever the platform actually said, which
 * is what makes a support conversation short. It is kept apart from `message`
 * so a raw 401 body is never the thing a subscriber is asked to interpret.
 */
final readonly class ConnectionResult
{
    private function __construct(
        public bool $ok,
        public string $message,
        /** @var array<string, mixed> */
        public array $detail = [],
    ) {}

    /** @param array<string, mixed> $detail */
    public static function ok(string $message, array $detail = []): self
    {
        return new self(true, $message, $detail);
    }

    /** @param array<string, mixed> $detail */
    public static function failed(string $message, array $detail = []): self
    {
        return new self(false, $message, $detail);
    }

    /**
     * The shop was reached but refused us.
     *
     * Separated from a generic failure because the fix is different and worth
     * naming: the address is right, so nobody should go hunting for a typo in
     * it — the credentials or their permissions are what to look at.
     */
    public static function unauthorised(string $detail = ''): self
    {
        return self::failed(
            'The shop answered but refused these credentials. Check the key and secret, '
            .'and that they carry read and write permission.',
            $detail === '' ? [] : ['response' => $detail],
        );
    }

    /** Nothing answered at all. */
    public static function unreachable(string $url, string $reason = ''): self
    {
        return self::failed(
            "Nothing answered at {$url}. Check the address is the shop's own, reachable from "
            .'the internet, and not behind a maintenance page.',
            $reason === '' ? [] : ['reason' => $reason],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'message' => $this->message, 'detail' => $this->detail];
    }
}
