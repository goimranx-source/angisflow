<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Organiser\Models\Tag;
use App\Domain\Organiser\Organiser;
use App\Http\Api\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Tags, favourites, and the marks on the to-do list.
 *
 * Everything here addresses a thing by a short type key and its public id, and
 * resolves it through the account scope — so an id belonging to another
 * subscriber resolves to nothing rather than to their row, and no endpoint here
 * has to remember that rule for itself.
 */
class OrganiserEndpoint extends Endpoint
{
    // ── Tags ────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => Tag::query()->orderBy('name')->get()->map->toPayload()->all(),
            'assigned' => Organiser::tagMapFor($user->account_id),
            'favourites' => Organiser::favouritesFor($user),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:40',
                // Scoped to the account: one subscriber naming a tag "seasonal"
                // must not stop another from doing the same.
                Rule::unique('tags', 'name')->where('account_id', $user->account_id),
            ],
            'colour' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [
            'name.unique' => 'You already have a tag with that name.',
        ]);

        $tag = Tag::create([
            'account_id' => $user->account_id,
            'name' => trim($validated['name']),
            'colour' => $validated['colour'] ?? null,
        ]);

        return response()->json(['data' => $tag->toPayload()], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $tag = Tag::query()->wherePublicId($id)->firstOrFail();

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:40',
                Rule::unique('tags', 'name')
                    ->where('account_id', $user->account_id)
                    ->ignore($tag->id),
            ],
            'colour' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $tag->update([
            'name' => trim($validated['name']),
            'colour' => $validated['colour'] ?? null,
        ]);

        return response()->json(['data' => $tag->fresh()->toPayload()]);
    }

    public function destroy(string $id): JsonResponse
    {
        $tag = Tag::query()->wherePublicId($id)->firstOrFail();

        // The attachments go with it — the foreign key cascades, so a deleted
        // tag cannot leave rows pointing at nothing.
        $tag->delete();

        return response()->json(['message' => 'Tag removed.']);
    }

    /** Put a tag on something, or take it off. */
    public function assign(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(['workspace', 'business'])],
            'target' => ['required', 'string', 'size:26'],
            'on' => ['required', 'boolean'],
        ]);

        $tag = Tag::query()->wherePublicId($id)->firstOrFail();
        $target = Organiser::resolve($validated['type'], $validated['target']);

        if ($target === null) {
            return response()->json(['message' => 'That is not available.'], 404);
        }

        $validated['on']
            ? Organiser::attach($user->account_id, $tag->id, $validated['type'], $target->getKey())
            : Organiser::detach($user->account_id, $tag->id, $validated['type'], $target->getKey());

        return response()->json(['assigned' => Organiser::tagMapFor($user->account_id)]);
    }

    // ── Favourites ──────────────────────────────────────────────────────────

    public function favourite(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(['workspace', 'business'])],
            'target' => ['required', 'string', 'size:26'],
            'on' => ['required', 'boolean'],
        ]);

        $target = Organiser::resolve($validated['type'], $validated['target']);

        if ($target === null) {
            return response()->json(['message' => 'That is not available.'], 404);
        }

        $validated['on']
            ? Organiser::favourite($user, $validated['type'], $target->getKey())
            : Organiser::unfavourite($user, $validated['type'], $target->getKey());

        return response()->json(['favourites' => Organiser::favouritesFor($user)]);
    }

    // ── To-do marks ─────────────────────────────────────────────────────────

    /**
     * Dismiss a to-do, or mark it done by hand.
     *
     * The to-dos themselves are derived from account state rather than stored,
     * so this records only what somebody has decided about one. A row that comes
     * back because the state came back — a plan lapsing, two-factor being turned
     * off — is the correct behaviour, not a bug: the thing is outstanding again.
     */
    public function markTodo(Request $request, string $key): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'state' => ['required', 'string', Rule::in(['dismissed', 'done', 'open'])],
        ]);

        if ($validated['state'] === 'open') {
            DB::table('todo_marks')->where('user_id', $user->id)->where('key', $key)->delete();
        } else {
            DB::table('todo_marks')->updateOrInsert(
                ['user_id' => $user->id, 'key' => $key],
                [
                    'account_id' => $user->account_id,
                    'state' => $validated['state'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        return response()->json(['marks' => self::marksFor($user)]);
    }

    /**
     * @return array<string, string>
     */
    public static function marksFor(User $user): array
    {
        return DB::table('todo_marks')
            ->where('user_id', $user->id)
            ->pluck('state', 'key')
            ->all();
    }
}
