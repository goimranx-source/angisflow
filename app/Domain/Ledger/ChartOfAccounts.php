<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Tenancy\Models\Business;
use Illuminate\Support\Facades\DB;

/**
 * The accounts a new set of books starts with.
 *
 * ── Why these numbers ────────────────────────────────────────────────────────
 *
 * Assets 1000, liabilities 2000, equity 3000, revenue 4000, cost of sales 5000,
 * operating expenses 6000. Every accounting package in the world numbers them
 * this way, which means a bookkeeper can read this chart without being taught
 * it, and accounts sort into statement order on their own without a separate
 * ordering column to keep in step.
 *
 * ── Subtypes are not decoration ──────────────────────────────────────────────
 *
 * Reports subtotal on them, and three of them change what a statement says:
 *
 *   inventory        must never be counted as cash. "How much can I spend" and
 *                    "how much do I own" are different questions and inventory
 *                    answers only the second.
 *   cogs             sits above gross profit; an operating expense sits below
 *                    it. Put packaging in the wrong one and the margin on every
 *                    product line is wrong.
 *   contra_revenue   is deducted from sales, not added to expenses. Refunds
 *                    booked as an expense overstate both revenue and costs, and
 *                    the two errors hide each other in the profit figure.
 *
 * Carried over from the first Prism, which got this part right.
 */
final class ChartOfAccounts
{
    /**
     * @var list<array<string, mixed>>
     */
    public const DEFAULTS = [
        // ── Assets ──────────────────────────────────────────────────────────
        ['1000', 'Cash', 'asset', 'current_asset', true, 'Notes and coins you physically hold'],
        ['1100', 'Bank Account', 'asset', 'current_asset', true, 'Money in the business bank account'],
        ['1200', 'Accounts Receivable', 'asset', 'current_asset', false, 'Sales delivered but not yet paid for'],
        ['1250', 'Courier Receivable', 'asset', 'current_asset', false, 'Cash on delivery collected by a courier and not yet settled to you'],
        ['1350', 'Advances to Staff', 'asset', 'current_asset', false, 'Salary paid ahead of time, recovered from a later payslip'],
        // Split by class because IAS 2 requires inventory to be disclosed that
        // way, and because "how much finished stock can we ship" is a different
        // question from "how much raw material is waiting to be made into it".
        ['1400', 'Inventory — Finished Goods', 'asset', 'inventory', false, 'Stock ready to pack and sell — an asset until it sells'],
        ['1410', 'Inventory — Raw Materials', 'asset', 'inventory', false, 'Materials bought to be made into something else'],
        ['1420', 'Inventory — Packaging', 'asset', 'inventory', false, 'Cartons, bubble wrap, labels — held until used'],
        ['1500', 'Prepaid Expenses', 'asset', 'current_asset', false, 'Paid in advance for something not yet received, like a year of rent'],
        ['1600', 'Security Deposits', 'asset', 'non_current_asset', false, 'Refundable deposits — an office deed, a utility connection'],
        ['1700', 'Equipment', 'asset', 'non_current_asset', false, 'Things you bought to keep and use, not to sell'],
        ['1790', 'Accumulated Depreciation', 'asset', 'contra_asset', false, 'Wear written off equipment so far — deducted from what it cost'],

        // ── Liabilities ─────────────────────────────────────────────────────
        ['2000', 'Accounts Payable', 'liability', 'current_liability', false, 'Goods or services received that you have not paid for yet'],
        ['2100', 'Accrued Salaries', 'liability', 'current_liability', false, 'Wages earned by staff but not yet paid out'],
        ['2200', 'Tax Payable', 'liability', 'current_liability', false, 'Tax collected or owed and not yet handed over'],
        ['2300', 'Customer Advances', 'liability', 'current_liability', false, 'Money taken before delivery — owed back until the goods go out'],
        ['2500', 'Loans Payable', 'liability', 'non_current_liability', false, 'Borrowed money that must be repaid — never equity'],

        // ── Equity ──────────────────────────────────────────────────────────
        ['3000', "Owner's Equity", 'equity', 'equity', false, 'General owner stake, for anything not tied to a named partner'],
        ['3100', 'Drawings', 'equity', 'contra_equity', false, 'Money taken out by an owner — a reduction of their stake, not an expense'],
        ['3900', 'Retained Earnings', 'equity', 'equity', false, 'Profit from earlier years that was left in the business'],

        // ── Revenue ─────────────────────────────────────────────────────────
        ['4100', 'Sales Income', 'revenue', 'operating', false, 'What customers pay for the goods themselves'],
        ['4150', 'Delivery Charge Income', 'revenue', 'operating', false, 'Delivery charged on to the customer — income, kept apart from goods'],
        ['4200', 'Other Income', 'revenue', 'other', false, 'Anything earned outside normal trading'],
        ['4900', 'Sales Returns & Refunds', 'revenue', 'contra_revenue', false, 'Money given back — deducted from sales, never added to expenses'],
        ['4950', 'Discounts Given', 'revenue', 'contra_revenue', false, 'Price reduced at the till — a deduction from sales, not a cost'],

        // ── Cost of sales ───────────────────────────────────────────────────
        ['5000', 'Cost of Goods Sold', 'expense', 'cogs', false, 'What the goods you sold had cost you — posted as they sell'],
        ['5100', 'Packaging', 'expense', 'cogs', false, 'Cartons, bubble wrap, tape — a cost of each sale'],
        ['5200', 'Inventory Write-off', 'expense', 'cogs', false, 'Stock damaged, lost or expired'],

        // ── Operating expenses ──────────────────────────────────────────────
        ['6100', 'Rent', 'expense', 'operating', false, null],
        ['6200', 'Utilities', 'expense', 'operating', false, null],
        ['6300', 'Marketing', 'expense', 'operating', false, null],
        ['6400', 'Software & Tools', 'expense', 'operating', false, null],
        ['6450', 'Staff Salaries', 'expense', 'operating', false, 'Wages for everyone on the payroll, owners included when they work here'],
        ['6500', 'Other Expense', 'expense', 'other', false, null],
        ['6600', 'Depreciation', 'expense', 'operating', false, 'Equipment wearing out, spread across the years it is used'],
        ['6700', 'Courier Charge', 'expense', 'operating', false, 'What the courier charges you to deliver and to return'],
        ['6800', 'Bank Charges', 'expense', 'operating', false, null],
        ['6900', 'Exchange Gain or Loss', 'expense', 'other', false, 'What movement in exchange rates cost or earned between billing and settling'],
    ];

    /**
     * Give a business any standard account it does not already have.
     *
     * Matches on code and never touches an existing row, so a business trading
     * for a year picks up newly added accounts without a single balance moving,
     * and this is safe to re-run on every deploy.
     *
     * @return int how many were created
     */
    public function install(Business $business): int
    {
        $existing = LedgerAccount::query()
            ->withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->pluck('code')
            ->all();

        $rows = [];
        $now = now();

        foreach (self::DEFAULTS as $order => [$code, $name, $type, $subtype, $spendable, $hint]) {
            if (in_array($code, $existing, true)) {
                continue;
            }

            $rows[] = [
                'public_id' => strtolower((string) \Illuminate\Support\Str::ulid()),
                'account_id' => $business->account_id,
                'business_id' => $business->id,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'subtype' => $subtype,
                'description' => $hint,
                'is_postable' => true,
                'is_spendable' => $spendable,
                'is_system' => true,
                'is_active' => true,
                'sort_order' => $order * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        DB::table('ledger_accounts')->insert($rows);

        return count($rows);
    }
}
