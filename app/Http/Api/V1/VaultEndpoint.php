<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Vault\Models\Credential;
use App\Domain\Vault\Vault;
use App\Http\Api\ApiResponse;
use App\Http\Api\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Credential vault — store, list, rotate, revoke.
 *
 * ── What is never returned ────────────────────────────────────────────────────
 *
 * The decrypted payload is not in any list or show response. The only way to
 * read it is GET /credentials/{id}/reveal, which sits behind the
 * password.confirm middleware — a recent password confirmation is required.
 * This means a session hijack or a stolen cookie does not expose stored secrets
 * unless the attacker also knows the account password.
 *
 * ── What "rotate" means ───────────────────────────────────────────────────────
 *
 * PATCH /credentials/{id}/rotate replaces the payload and stamps
 * last_rotated_at. The ref does not change, so every integration that points
 * at this credential continues to work without any update to those tables.
 */
class VaultEndpoint extends Endpoint
{
    public function __construct(
        private readonly Vault $vault,
    ) {}

    /** All credentials for this account — no payloads. */
    public function index(): JsonResponse
    {
        $credentials = $this->vault->list();

        return ApiResponse::collection($credentials->map->toPayload());
    }

    /** Store a new credential. Returns the ref the caller should save. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label'   => ['required', 'string', 'max:120'],
            'kind'    => ['required', 'string', 'in:api_key,oauth_token,basic_auth,generic'],
            'payload' => ['required', 'array'],
        ]);

        $credential = $this->vault->store($data['label'], $data['kind'], $data['payload']);

        return response()->json(['data' => $credential->toPayload()], 201);
    }

    /** Metadata for one credential — no payload. */
    public function show(string $id): JsonResponse
    {
        $credential = Credential::where('public_id', $id)->firstOrFail();

        return ApiResponse::item($credential->toPayload());
    }

    /**
     * Reveal the decrypted payload.
     *
     * This route sits behind password.confirm middleware — a password typed in
     * the last few minutes is required. A stolen session cookie is not enough.
     */
    public function reveal(string $id): JsonResponse
    {
        $credential = Credential::where('public_id', $id)->firstOrFail();

        $payload = $this->vault->retrieve($credential->ref);

        return ApiResponse::item([
            'id'      => $credential->public_id,
            'label'   => $credential->label,
            'kind'    => $credential->kind,
            'payload' => $payload,
        ]);
    }

    /**
     * Replace the payload without changing the ref.
     *
     * Integrations that store the ref continue to work after rotation — they
     * will pick up the new payload on their next retrieve() call.
     */
    public function rotate(Request $request, string $id): JsonResponse
    {
        $credential = Credential::where('public_id', $id)->firstOrFail();

        $data = $request->validate([
            'payload' => ['required', 'array'],
        ]);

        $updated = $this->vault->rotate($credential->ref, $data['payload']);

        return ApiResponse::item($updated->toPayload());
    }

    /**
     * Revoke a credential.
     *
     * Sets is_active = false and clears the payload. The ref row stays so
     * integrations pointing at it get a clear "revoked" error rather than a
     * missing-ref error.
     */
    public function revoke(string $id): JsonResponse
    {
        $credential = Credential::where('public_id', $id)->firstOrFail();

        $this->vault->revoke($credential->ref);

        return response()->json(['message' => "Credential \"{$credential->label}\" has been revoked."], 200);
    }
}
