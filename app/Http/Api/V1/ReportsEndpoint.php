<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Ledger\FinancialReports;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiResponse;
use App\Http\Api\Endpoint;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Financial reports — the ledger read back out.
 *
 * Every figure here is computed from journal_lines on demand. There is no
 * cached balance table to drift from the postings beneath it. See
 * FinancialReports for why that is the right trade at this scale.
 *
 * ── Periods ──────────────────────────────────────────────────────────────────
 *
 * All date parameters are YYYY-MM-DD strings. The endpoint validates them and
 * defaults sensibly so the client can call without parameters and get something
 * useful — the current month for P&L, today for the balance sheet.
 */
class ReportsEndpoint extends Endpoint
{
    public function __construct(
        private readonly FinancialReports $reports,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Profit and loss for a period.
     *
     * Defaults to the current calendar month. The client passes `from` and `to`
     * as YYYY-MM-DD; the endpoint anchors them to the business's timezone so
     * "this month" means the same thing to the owner and to the server.
     */
    public function profitAndLoss(Request $request): JsonResponse
    {
        $this->requireBusiness();

        $now = CarbonImmutable::now($this->userTimezone($request));

        $from = $request->string('from', $now->startOfMonth()->toDateString())->value();
        $to   = $request->string('to',   $now->endOfMonth()->toDateString())->value();

        $this->validateDateRange($from, $to);

        return ApiResponse::cacheable([
            'data' => $this->reports->profitAndLoss($from, $to),
        ], seconds: 30);
    }

    /**
     * Balance sheet as at a date.
     *
     * Defaults to today. Assets must equal liabilities plus equity; the
     * response carries a `balances` boolean and a `difference` so the client
     * can surface a warning if the books are out of balance.
     */
    public function balanceSheet(Request $request): JsonResponse
    {
        $this->requireBusiness();

        $asAt = $request->string('as_at', now()->toDateString())->value();

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $asAt)) {
            return response()->json(['message' => 'as_at must be a date in YYYY-MM-DD format.'], 422);
        }

        return ApiResponse::cacheable([
            'data' => $this->reports->balanceSheet($asAt),
        ], seconds: 30);
    }

    /**
     * Trial balance — every account with movement, debits and credits.
     *
     * The report that proves the books. If `balanced` is false in the response,
     * something posted outside Ledger::post() and the figures cannot be trusted.
     */
    public function trialBalance(Request $request): JsonResponse
    {
        $this->requireBusiness();

        $from = $request->string('from')->value() ?: null;
        $to   = $request->string('to')->value() ?: null;

        if ($from !== null && $to !== null) {
            $this->validateDateRange($from, $to);
        }

        $result = $this->reports->trialBalance($from, $to);

        return ApiResponse::cacheable([
            'data' => [
                'rows'     => $result['rows'],
                'debit'    => $result['debit']->jsonSerialize(),
                'credit'   => $result['credit']->jsonSerialize(),
                'balanced' => $result['balanced'],
                'from'     => $from,
                'to'       => $to,
            ],
        ], seconds: 30);
    }

    /**
     * Receivables aging — who owes money and how overdue.
     *
     * Bucketed from the due date, not the invoice date. "60 days old" and
     * "60 days overdue" are different claims and only the second is a reason
     * to chase somebody.
     */
    public function receivablesAging(Request $request): JsonResponse
    {
        $this->requireBusiness();

        $asAt = $request->string('as_at', now()->toDateString())->value();

        return ApiResponse::cacheable([
            'data' => $this->reports->receivablesAging($asAt),
        ], seconds: 60);
    }

    /**
     * The chart of accounts — every account in statement order.
     *
     * Postable accounts only by default; pass `all=1` to include headings.
     * Used by the accounts screen and by any form that needs to pick an account.
     */
    public function accounts(Request $request): JsonResponse
    {
        $this->requireBusiness();

        $query = LedgerAccount::query()
            ->inStatementOrder()
            ->with('parent:id,code,name');

        if (! $request->boolean('all')) {
            $query->postable();
        }

        $accounts = $query->get()->map(fn (LedgerAccount $a) => $a->toPayload() + [
            'parent_code' => $a->parent?->code,
        ]);

        return ApiResponse::collection($accounts);
    }

    /**
     * Every posting against one account, with a running balance.
     *
     * The screen somebody opens when a figure looks wrong. Carries the entry
     * reference and description on every row so there is a way back to what
     * caused each movement.
     */
    public function accountLedger(Request $request, string $id): JsonResponse
    {
        $this->requireBusiness();

        $account = LedgerAccount::where('public_id', $id)->firstOrFail();

        $from = $request->string('from')->value() ?: null;
        $to   = $request->string('to')->value() ?: null;

        if ($from !== null && $to !== null) {
            $this->validateDateRange($from, $to);
        }

        return ApiResponse::cacheable([
            'data' => $this->reports->accountLedger($account, $from, $to),
        ], seconds: 30);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function requireBusiness(): void
    {
        if ($this->tenant->business() === null) {
            throw new RuntimeException('Open a business before reading its reports.');
        }
    }

    private function validateDateRange(string $from, string $to): void
    {
        $pattern = '/^\d{4}-\d{2}-\d{2}$/';

        if (! preg_match($pattern, $from) || ! preg_match($pattern, $to)) {
            abort(422, 'Dates must be in YYYY-MM-DD format.');
        }

        if ($from > $to) {
            abort(422, 'The start date must be on or before the end date.');
        }
    }

    private function userTimezone(Request $request): string
    {
        return $request->user()?->timezoneOrDefault() ?? 'UTC';
    }
}
