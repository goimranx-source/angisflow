<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

/**
 * What a sync actually did.
 *
 * ── Why skipped records are counted, not swallowed ───────────────────────────
 *
 * The failure mode of every sync ever written is the silent one: it reports
 * success, and three weeks later somebody notices forty orders are missing and
 * there is nothing anywhere saying why.
 *
 * So a record that could not be imported is counted against its entity with the
 * reason it gave, and those reasons are surfaced rather than logged. A run that
 * wrote three hundred records and skipped four is not a failure, but it is also
 * not a clean success, and the difference has to be visible on the screen
 * somebody looks at.
 */
final class SyncReport
{
    /** @var array<string, array{created: int, updated: int, skipped: int}> */
    private array $counts = [];

    /** @var list<array{entity: string, reason: string}> */
    private array $reasons = [];

    /** @var list<array{entity: string, note: string}> */
    private array $notes = [];

    public ?string $error = null;

    /**
     * Entities that could not be read at all, and why.
     *
     * @var array<string, string>
     */
    private array $entityErrors = [];

    public function created(string $entity): void
    {
        $this->bump($entity, 'created');
    }

    public function updated(string $entity): void
    {
        $this->bump($entity, 'updated');
    }

    public function skip(string $entity, string $reason): void
    {
        $this->bump($entity, 'skipped');

        // Capped: four hundred records failing for the same reason is one
        // problem, and a report carrying four hundred copies of it is unreadable
        // and large enough to matter in a response.
        if (count($this->reasons) < 25) {
            $this->reasons[] = ['entity' => $entity, 'reason' => mb_substr($reason, 0, 300)];
        }
    }

    /**
     * One entity could not be read. The rest of the run carries on.
     *
     * -- Why this is not fail() ----------------------------------------------
     *
     * Because the entities are independent, and treating them as one unit
     * throws away work that succeeded. A shop whose product listing times out
     * still has orders we can read, and orders are the ones somebody is waiting
     * for — losing a night of them because the catalogue was slow is a far
     * worse outcome than an incomplete catalogue.
     *
     * It also mislabels the connection. A run marked failed calls
     * recordFailure(), so a shop that imported every order it had gets a red
     * light and a failure counted against it — which is how somebody comes to
     * distrust a status that was telling the truth about something else.
     *
     * The reason is still kept and still surfaced; see toArray. Quietly
     * carrying on is the other half of the same mistake.
     */
    public function entityFailed(string $entity, string $reason): void
    {
        $this->entityErrors[$entity] = mb_substr($reason, 0, 300);
    }

    /**
     * Did every entity we tried fall over?
     *
     * The one case that genuinely is a failed run: nothing read and nothing
     * written, which means the shop is down or the credentials stopped working
     * — a connection-level problem rather than an entity one.
     *
     * @param  list<string>  $attempted
     */
    public function everyEntityFailed(array $attempted): bool
    {
        if ($attempted === []) {
            return false;
        }

        return count(array_intersect($attempted, array_keys($this->entityErrors))) === count($attempted);
    }

    /** @return array<string, string> */
    public function entityErrors(): array
    {
        return $this->entityErrors;
    }

    public function skipEntity(string $entity, string $why): void
    {
        $this->notes[] = ['entity' => $entity, 'note' => $why];
    }

    public function note(string $entity, string $note): void
    {
        $this->notes[] = ['entity' => $entity, 'note' => $note];
    }

    /** Stops the run. Returned rather than thrown so the caller can report it. */
    public function fail(string $message): self
    {
        $this->error ??= $message;

        return $this;
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }

    /** How many records this run actually put somewhere. */
    public function written(): int
    {
        $total = 0;

        foreach ($this->counts as $count) {
            $total += $count['created'] + $count['updated'];
        }

        return $total;
    }

    public function skipped(): int
    {
        return array_sum(array_column($this->counts, 'skipped'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok' => ! $this->failed(),
            'error' => $this->error,
            'entities' => $this->counts,
            'written' => $this->written(),
            'skipped' => $this->skipped(),
            'reasons' => $this->reasons,
            // Per-entity failures, so a screen can say "orders imported,
            // products timed out" rather than one word for the whole run.
            'entity_errors' => $this->entityErrors,
            'notes' => $this->notes,
        ];
    }

    private function bump(string $entity, string $key): void
    {
        $this->counts[$entity] ??= ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $this->counts[$entity][$key]++;
    }
}
