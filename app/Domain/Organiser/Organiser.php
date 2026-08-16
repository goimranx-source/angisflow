<?php

declare(strict_types=1);

namespace App\Domain\Organiser;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Tags, favourites, and the things they hang off.
 *
 * ── Why one class for both ───────────────────────────────────────────────────
 *
 * They are the same shape: a mark against a thing, addressed by a short type
 * key and a public id, resolved through the account scope so nothing can be
 * marked that the caller cannot already see. Splitting them would mean two
 * copies of that resolution — and the resolution is the part that has to be
 * right, because it is what stops one subscriber tagging another's business.
 */
class Organiser
{
    /** Short keys rather than class names. See the migration for why. */
    private const TYPES = [
        'workspace' => Workspace::class,
        'business' => Business::class,
    ];

    /**
     * Find a taggable thing by its short type and public id.
     *
     * Goes through the model's account scope, so an id belonging to another
     * subscriber resolves to nothing rather than to their row.
     */
    public static function resolve(string $type, string $publicId): ?Model
    {
        $class = self::TYPES[$type] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("Unknown type [{$type}].");
        }

        return $class::query()->wherePublicId($publicId)->first();
    }

    public static function isKnownType(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    // ── Favourites ──────────────────────────────────────────────────────────

    public static function isFavourite(User $user, string $type, int $id): bool
    {
        return DB::table('favourites')
            ->where('user_id', $user->id)
            ->where('favouritable_type', $type)
            ->where('favouritable_id', $id)
            ->exists();
    }

    public static function favourite(User $user, string $type, int $id): void
    {
        DB::table('favourites')->insertOrIgnore([
            'account_id' => $user->account_id,
            'user_id' => $user->id,
            'favouritable_type' => $type,
            'favouritable_id' => $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function unfavourite(User $user, string $type, int $id): void
    {
        DB::table('favourites')
            ->where('user_id', $user->id)
            ->where('favouritable_type', $type)
            ->where('favouritable_id', $id)
            ->delete();
    }

    /**
     * Every favourite this person has, as "type:publicId" strings.
     *
     * Keyed by public id, not by row id. The client is never given row ids —
     * that is the whole point of having public ones — so a map keyed by them
     * would be a map the client cannot read.
     *
     * @return list<string>
     */
    public static function favouritesFor(User $user): array
    {
        $rows = DB::table('favourites')
            ->where('user_id', $user->id)
            ->get(['favouritable_type', 'favouritable_id']);

        $public = self::publicIds($rows->all(), 'favouritable');

        return $rows
            ->map(function ($row) use ($public) {
                $publicId = $public["{$row->favouritable_type}:{$row->favouritable_id}"] ?? null;

                return $publicId === null ? null : "{$row->favouritable_type}:{$publicId}";
            })
            ->filter()
            ->values()
            ->all();
    }

    // ── Tags ────────────────────────────────────────────────────────────────

    public static function attach(int $accountId, int $tagId, string $type, int $id): void
    {
        DB::table('taggables')->insertOrIgnore([
            'account_id' => $accountId,
            'tag_id' => $tagId,
            'taggable_type' => $type,
            'taggable_id' => $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function detach(int $accountId, int $tagId, string $type, int $id): void
    {
        DB::table('taggables')
            ->where('account_id', $accountId)
            ->where('tag_id', $tagId)
            ->where('taggable_type', $type)
            ->where('taggable_id', $id)
            ->delete();
    }

    /**
     * Which tags are on what, for a whole account at once.
     *
     * Keyed "type:publicId" so a list can look each row up without a query of
     * its own, and without ever being handed a database row id.
     *
     * @return array<string, list<string>>  tag public ids, keyed by "type:publicId"
     */
    public static function tagMapFor(int $accountId): array
    {
        $rows = DB::table('taggables')
            ->join('tags', 'tags.id', '=', 'taggables.tag_id')
            ->where('taggables.account_id', $accountId)
            ->get(['taggables.taggable_type', 'taggables.taggable_id', 'tags.public_id']);

        $public = self::publicIds($rows->all(), 'taggable');
        $map = [];

        foreach ($rows as $row) {
            $key = $public["{$row->taggable_type}:{$row->taggable_id}"] ?? null;

            if ($key !== null) {
                $map["{$row->taggable_type}:{$key}"][] = $row->public_id;
            }
        }

        return $map;
    }

    /**
     * Row id → public id, for whichever rows a set of links points at.
     *
     * Two queries at most however many links there are, rather than one per
     * link: the ids are gathered per type and each table asked once.
     *
     * @param  list<object>  $rows
     * @return array<string, string>
     */
    private static function publicIds(array $rows, string $prefix): array
    {
        $byType = [];

        foreach ($rows as $row) {
            $type = $row->{$prefix.'_type'};
            $byType[$type][] = $row->{$prefix.'_id'};
        }

        $out = [];

        foreach ($byType as $type => $ids) {
            $class = self::TYPES[$type] ?? null;

            if ($class === null) {
                continue;
            }

            // Without global scopes: the caller has already been scoped by the
            // query that produced these links, and re-scoping here would drop
            // rows in a console command where no tenant is set.
            $found = $class::withoutGlobalScopes()
                ->withTrashed()
                ->whereIn('id', array_unique($ids))
                ->pluck('public_id', 'id');

            foreach ($found as $id => $publicId) {
                $out["{$type}:{$id}"] = $publicId;
            }
        }

        return $out;
    }
}
