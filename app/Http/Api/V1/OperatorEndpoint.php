<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Operator\OperatorService;
use App\Domain\Tenancy\Models\Account;
use App\Domain\Tenancy\Models\Workspace;
use App\Http\Api\ApiResponse;
use App\Http\Api\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The operator admin panel API.
 *
 * All routes here are behind the 'operator' middleware — only platform staff
 * with is_operator = true may call them. The middleware returns 403 for
 * everyone else; the routes are not hidden.
 *
 * ── What is here ─────────────────────────────────────────────────────────────
 *
 *   GET    /operator/accounts              list all accounts
 *   GET    /operator/accounts/{id}         account detail
 *   POST   /operator/accounts/{id}/suspend
 *   POST   /operator/accounts/{id}/unsuspend
 *   POST   /operator/accounts/{id}/extend-trial
 *   POST   /operator/accounts/{id}/change-plan
 *   GET    /operator/plans                 all plans (for the change-plan picker)
 *   POST   /operator/workspaces/{id}/grant-module
 *   POST   /operator/workspaces/{id}/revoke-module
 *   DELETE /operator/workspaces/{id}/overrides/{key}
 */
class OperatorEndpoint extends Endpoint
{
    public function __construct(
        private readonly OperatorService $operator,
    ) {}

    public function accounts(Request $request): JsonResponse
    {
        $search = $request->string('search', '')->value();
        $page   = $this->operator->accounts($search);

        $items = collect($page->items())->map(fn (Account $a) => [
            'id'         => $a->public_id,
            'name'       => $a->name,
            'slug'       => $a->slug,
            'status'     => $a->status,
            'plan'       => $a->subscription?->plan?->code,
            'created_at' => $a->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'data' => $items,
            'meta' => [
                'next_cursor' => $page->nextCursor()?->encode(),
                'has_more'    => $page->hasMorePages(),
            ],
        ]);
    }

    public function accountDetail(string $id): JsonResponse
    {
        return ApiResponse::item($this->operator->accountDetail($id));
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        $data    = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $account = $this->findAccount($id);

        $this->operator->suspend($account, $data['reason'], $request->user());

        return response()->json(['message' => "{$account->name} has been suspended."]);
    }

    public function unsuspend(Request $request, string $id): JsonResponse
    {
        $account = $this->findAccount($id);

        $this->operator->unsuspend($account, $request->user());

        return response()->json(['message' => "{$account->name} has been unsuspended."]);
    }

    public function extendTrial(Request $request, string $id): JsonResponse
    {
        $data    = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:365']]);
        $account = $this->findAccount($id);

        $this->operator->extendTrial($account, $data['days'], $request->user());

        return response()->json([
            'message'       => "Trial extended by {$data['days']} days.",
            'trial_ends_at' => $account->fresh()->trial_ends_at?->toIso8601String(),
        ]);
    }

    public function changePlan(Request $request, string $id): JsonResponse
    {
        $data    = $request->validate(['plan_code' => ['required', 'string']]);
        $account = $this->findAccount($id);

        $sub = $this->operator->changePlan($account, $data['plan_code'], $request->user());

        return response()->json([
            'message' => "{$account->name} moved to {$sub->plan->code}.",
            'status'  => $account->fresh()->status,
        ]);
    }

    public function plans(): JsonResponse
    {
        $plans = $this->operator->plans()->map(fn ($p) => [
            'code'        => $p->code,
            'name'        => $p->name,
            'price_minor' => $p->price_minor,
            'currency'    => $p->currency,
            'interval'    => $p->interval,
        ]);

        return ApiResponse::collection($plans);
    }

    public function grantModule(Request $request, string $workspaceId): JsonResponse
    {
        $data = $request->validate([
            'module_key' => ['required', 'string', 'max:80'],
            'reason'     => ['required', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $workspace = $this->findWorkspace($workspaceId);
        $expires   = isset($data['expires_at']) ? new \DateTime($data['expires_at']) : null;

        $override = $this->operator->grantModule(
            $workspace, $data['module_key'], $data['reason'], $request->user(), $expires,
        );

        return response()->json(['data' => $override->toPayload()], 201);
    }

    public function revokeModule(Request $request, string $workspaceId): JsonResponse
    {
        $data = $request->validate([
            'module_key' => ['required', 'string', 'max:80'],
            'reason'     => ['required', 'string', 'max:500'],
        ]);

        $workspace = $this->findWorkspace($workspaceId);

        $this->operator->revokeModule(
            $workspace, $data['module_key'], $data['reason'], $request->user(),
        );

        return response()->json(['message' => "Module {$data['module_key']} revoked for {$workspace->name}."]);
    }

    public function removeOverride(string $workspaceId, string $moduleKey): JsonResponse
    {
        $workspace = $this->findWorkspace($workspaceId);

        $this->operator->removeOverride($workspace, $moduleKey);

        return response()->json(['message' => "Override for {$moduleKey} removed."]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function findAccount(string $publicId): Account
    {
        return Account::withoutGlobalScopes()
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function findWorkspace(string $publicId): Workspace
    {
        return Workspace::withoutGlobalScopes()
            ->where('public_id', $publicId)
            ->firstOrFail();
    }
}
