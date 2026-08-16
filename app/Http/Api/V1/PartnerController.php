<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Partners\PartnerService;
use App\Domain\Partners\ProfitDistributionService;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\Endpoint;
use App\Models\Partner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Partners — the people who own the business.
 *
 * ── Why this is not a category feature ───────────────────────────────────────
 *
 * A partnership is a way of owning a business, not a way of trading. A salon
 * with two owners, a restaurant with four, a haulage firm with a sleeping
 * partner: the trade differs, the equity question does not. So this is offered
 * to every category rather than bolted onto one, and a business that is a sole
 * trader simply never adds a partner.
 *
 * ── Why every figure comes from the ledger ───────────────────────────────────
 *
 * Balances below are read from the partner's three ledger accounts, not from
 * columns on the partner row. Equity is the one place where a convenient cached
 * total is genuinely dangerous: if a partner's stored balance and the accounts
 * behind it ever disagree, there is no way to tell which is the lie, and the
 * disagreement is over who owns what. The accounts are the record; this endpoint
 * reports them.
 *
 * The three accounts, and why there are three, are explained at length in the
 * partner tables migration. In short: capital is permanent investment, current
 * is profit in and drawings out, liability is money the business owes them for
 * expenses they paid personally.
 *
 * ── Why verification gates the money ─────────────────────────────────────────
 *
 * A partner can be created unverified so the record exists, but PartnerService
 * refuses every money method until their identity is checked against a
 * document. A row reading "Jane Smith, 30%" is easy to type and very hard to
 * undo once profit has moved.
 */
class PartnerController extends Endpoint
{
    public function __construct(
        private readonly PartnerService $partners,
        private readonly ProfitDistributionService $distribution,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $business = $this->requireBusiness();

        $query = Partner::where('business_id', $business->id)
            ->with(['liabilityAccount', 'capitalAccount', 'currentAccount']);

        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($status = $request->get('status')) {
            match ($status) {
                'active' => $query->where('is_active', true),
                'inactive' => $query->where('is_active', false),
                'unverified' => $query->whereNull('id_verified_at'),
                'verified' => $query->whereNotNull('id_verified_at'),
                default => null,
            };
        }

        $partners = $query->orderBy('code')->get();

        $currency = $business->base_currency ?? 'BDT';

        return response()->json([
            'data' => $partners->map(fn (Partner $p) => $this->present($p, $currency))->all(),
            'summary' => $this->summary($partners, $currency),
        ]);
    }

    public function show(string $publicId): JsonResponse
    {
        $business = $this->requireBusiness();
        $partner = $this->find($publicId);
        $partner->load(['sharePeriods', 'documents', 'liabilityAccount', 'capitalAccount', 'currentAccount']);

        $currency = $business->base_currency ?? 'BDT';
        $payload = $this->present($partner, $currency);

        $payload['share_history'] = $partner->sharePeriods
            ->sortByDesc('effective_from')
            ->values()
            ->map(fn ($p) => [
                'share_percent' => (float) $p->share_percent,
                'effective_from' => $p->effective_from?->toDateString(),
                'note' => $p->note,
            ])
            ->all();

        return response()->json(['data' => $payload]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $withCapital = (bool) $request->boolean('with_capital_account');

        $partner = $this->partners->create($data, $withCapital);

        return response()->json([
            'data' => $this->present($partner->fresh(), $this->currency()),
        ], 201);
    }

    public function update(Request $request, string $publicId): JsonResponse
    {
        $partner = $this->find($publicId);
        $data = $request->validate($this->rules(creating: false));
        $withCapital = (bool) $request->boolean('with_capital_account');

        $partner = $this->partners->update($partner, $data, $withCapital);

        return response()->json(['data' => $this->present($partner->fresh(), $this->currency())]);
    }

    /**
     * Verify a partner's identity against their document.
     *
     * Until this succeeds every money method refuses, so this is the gate
     * between a name on file and someone who can hold equity.
     */
    public function verify(Request $request, string $publicId): JsonResponse
    {
        $partner = $this->find($publicId);

        $data = $request->validate([
            'id_type' => 'required|string|max:40',
            'id_number' => 'required|string|max:60',
            'id_name' => 'required|string|max:150',
            'id_country' => 'nullable|string|size:2',
            'id_date_of_birth' => 'nullable|date',
        ]);

        $partner = $this->partners->verifyIdentity($partner, $data, (int) $request->user()->id);

        return response()->json(['data' => $this->present($partner->fresh(), $this->currency())]);
    }

    /** Capital contributed or withdrawn. Permanent investment, not drawings. */
    public function capital(Request $request, string $publicId): JsonResponse
    {
        $partner = $this->find($publicId);

        $data = $request->validate([
            'amount_minor' => 'required|integer|min:1',
            'money_account_code' => 'required|string|max:20',
            'date' => 'required|date',
            'direction' => 'required|in:in,out',
            'note' => 'nullable|string|max:500',
        ]);

        $this->partners->recordCapital(
            $partner,
            new Money($data['amount_minor'], $this->currency()),
            $data['money_account_code'],
            $data['date'],
            $data['direction'],
            $data['note'] ?? null,
        );

        return response()->json(['data' => $this->present($partner->fresh(), $this->currency())]);
    }

    /** A partner taking money out of accumulated profit. */
    public function drawing(Request $request, string $publicId): JsonResponse
    {
        $partner = $this->find($publicId);

        $data = $request->validate([
            'amount_minor' => 'required|integer|min:1',
            'money_account_code' => 'required|string|max:20',
            'date' => 'required|date',
            'note' => 'nullable|string|max:500',
        ]);

        $this->partners->recordDrawing(
            $partner,
            new Money($data['amount_minor'], $this->currency()),
            $data['money_account_code'],
            $data['date'],
            $data['note'] ?? null,
        );

        return response()->json(['data' => $this->present($partner->fresh(), $this->currency())]);
    }

    /** A business expense the partner paid personally — the business now owes them. */
    public function expense(Request $request, string $publicId): JsonResponse
    {
        $partner = $this->find($publicId);

        $data = $request->validate([
            'amount_minor' => 'required|integer|min:1',
            'expense_account_code' => 'required|string|max:20',
            'date' => 'required|date',
            'description' => 'required|string|max:255',
            'note' => 'nullable|string|max:500',
        ]);

        $this->partners->recordExpense(
            $partner,
            new Money($data['amount_minor'], $this->currency()),
            $data['expense_account_code'],
            $data['date'],
            $data['description'],
            $data['note'] ?? null,
        );

        return response()->json(['data' => $this->present($partner->fresh(), $this->currency())]);
    }

    /** Repaying what the business owes them. Not a drawing. */
    public function settle(Request $request, string $publicId): JsonResponse
    {
        $partner = $this->find($publicId);

        $data = $request->validate([
            'amount_minor' => 'required|integer|min:1',
            'money_account_code' => 'required|string|max:20',
            'date' => 'required|date',
            'note' => 'nullable|string|max:500',
        ]);

        $this->partners->settle(
            $partner,
            new Money($data['amount_minor'], $this->currency()),
            $data['money_account_code'],
            $data['date'],
            $data['note'] ?? null,
        );

        return response()->json(['data' => $this->present($partner->fresh(), $this->currency())]);
    }

    /**
     * What each partner would receive for a period, before anything is posted.
     *
     * Distribution is irreversible in the sense that undoing it posts more
     * entries rather than deleting any, so the preview exists to be read first.
     */
    public function distributionPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        return response()->json(['data' => $this->distribution->preview($data['from'], $data['to'])]);
    }

    public function distribute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'note' => 'nullable|string|max:500',
        ]);

        $result = $this->distribution->distribute($data['from'], $data['to'], $data['note'] ?? null);

        return response()->json(['data' => ['public_id' => $result->public_id]], 201);
    }

    public function distributionHistory(): JsonResponse
    {
        return response()->json(['data' => $this->distribution->history()]);
    }

    // ── presentation ────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function present(Partner $p, string $currency): array
    {
        $capital = $this->balanceOf($p->capital_account_id);
        $current = $this->balanceOf($p->current_account_id);
        $owed = $this->balanceOf($p->liability_account_id);

        return [
            'id' => $p->public_id,
            'code' => $p->code,
            'name' => $p->name,
            'email' => $p->email,
            'phone' => $p->phone,
            'is_active' => (bool) $p->is_active,
            'joined_on' => $p->joined_on?->toDateString(),
            'profit_share_percent' => $p->profit_share_percent !== null
                ? (float) $p->profit_share_percent
                : null,

            // Verification is the gate on every money action, so the client
            // needs it as a first-class field rather than a derived guess.
            'is_verified' => $p->isVerified(),
            'can_transact' => $p->canTransact(),
            'verified_at' => $p->id_verified_at?->toIso8601String(),

            'has_capital_account' => $p->capital_account_id !== null,

            'currency' => $currency,
            'balances' => [
                // Capital and current are equity: credit balances, so a credit
                // is what the partner is owed. Flipped here so the client shows
                // "invested 50,000" rather than a negative.
                'capital_minor' => -$capital,
                'current_minor' => -$current,
                'owed_minor' => -$owed,
            ],

            'bank' => [
                'name' => $p->bank_name,
                'account_name' => $p->bank_account_name,
                'account_number' => $p->bank_account_number,
                'branch' => $p->bank_branch,
                'mobile_wallet' => $p->mobile_wallet,
            ],
            'nominee' => [
                'name' => $p->nominee_name,
                'relation' => $p->nominee_relation,
                'phone' => $p->nominee_phone,
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Partner>  $partners
     * @return array<string, mixed>
     */
    private function summary($partners, string $currency): array
    {
        $allocated = $partners->sum(fn (Partner $p) => (float) ($p->profit_share_percent ?? 0));

        return [
            'total' => $partners->count(),
            'verified' => $partners->filter(fn (Partner $p) => $p->isVerified())->count(),
            'currency' => $currency,
            'share_allocated_percent' => round($allocated, 3),
            // Anything other than 100 is worth showing plainly: an under- or
            // over-allocated book is a real problem the owner should see before
            // a distribution runs, not after.
            'share_unallocated_percent' => round(100 - $allocated, 3),
        ];
    }

    private function balanceOf(?int $accountId): int
    {
        if ($accountId === null) {
            return 0;
        }

        $row = LedgerAccount::find($accountId)
            ?->lines()
            ->selectRaw('COALESCE(SUM(debit_minor),0) AS d, COALESCE(SUM(credit_minor),0) AS c')
            ->first();

        return $row === null ? 0 : (int) $row->d - (int) $row->c;
    }

    /**
     * @return array<string, string>
     */
    private function rules(bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => $required . '|string|max:150',
            'code' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:150',
            'phone' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:2000',
            'is_active' => 'nullable|boolean',
            'joined_on' => 'nullable|date',

            'profit_share_percent' => 'nullable|numeric|min:0|max:100',
            'share_effective_from' => 'nullable|date',
            'share_note' => 'nullable|string|max:500',

            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|size:2',
            'father_name' => 'nullable|string|max:150',
            'mother_name' => 'nullable|string|max:150',
            'blood_group' => 'nullable|string|max:8',

            'emergency_name' => 'nullable|string|max:150',
            'emergency_phone' => 'nullable|string|max:50',

            'nominee_name' => 'nullable|string|max:150',
            'nominee_relation' => 'nullable|string|max:50',
            'nominee_phone' => 'nullable|string|max:50',
            'nominee_id_number' => 'nullable|string|max:60',

            'bank_name' => 'nullable|string|max:150',
            'bank_account_name' => 'nullable|string|max:150',
            'bank_account_number' => 'nullable|string|max:100',
            'bank_branch' => 'nullable|string|max:150',
            'mobile_wallet' => 'nullable|string|max:100',
        ];
    }

    private function find(string $publicId): Partner
    {
        $business = $this->requireBusiness();

        return Partner::where('business_id', $business->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function currency(): string
    {
        return $this->requireBusiness()->base_currency ?? 'BDT';
    }

    private function requireBusiness()
    {
        $business = $this->tenant->business();

        if (! $business) {
            throw new RuntimeException('No business is open — partners own one set of books.');
        }

        return $business;
    }
}
