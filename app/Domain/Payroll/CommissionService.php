<?php

namespace App\Domain\Payroll;

use App\Domain\Money\Currencies;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Commission calculation from orders.
 *
 * Commissions are worked out, not stored, deliberately. An order can be edited,
 * refunded or taken off after the fact, and a stored figure would keep paying
 * on a sale that no longer exists. What is stored is the *rate*; the amount is
 * always what the rate says about the orders as they stand.
 *
 * Cancelled and returned orders earn nothing. Paying commission on a sale that
 * came back is how a month's payroll quietly exceeds a month's income, and it
 * is a correction nobody enjoys explaining afterwards.
 */
class CommissionService
{
    /**
     * Calculate total commission for an employee in a period.
     *
     * @return array{total: float, orders: int, order_details: array}
     */
    public function calculate(Employee $employee, string $periodStart, string $periodEnd): array
    {
        if ($employee->commission_type === 'none') {
            return ['total' => 0.0, 'orders' => 0, 'order_details' => []];
        }

        if (! $employee->linked_user_id) {
            return ['total' => 0.0, 'orders' => 0, 'order_details' => []];
        }

        $orders = $this->ordersFor($employee, $periodStart, $periodEnd);

        $total = 0.0;
        $details = [];

        foreach ($orders as $order) {
            $earned = $this->commissionOn($employee, $order);
            $total += $earned;

            $details[] = [
                'order_id' => $order->id,
                'reference' => $order->reference,
                'total' => (float) $order->base_total,
                'status' => $order->status,
                'earned' => $earned,
                'countable' => $this->isEligible($order),
            ];
        }

        return [
            'total' => round($total, 2),
            'orders' => count($details),
            'order_details' => $details,
        ];
    }

    /**
     * Calculate commission on a single order.
     */
    public function commissionOn(Employee $employee, object $order): float
    {
        if (! $this->isEligible($order)) {
            return 0.0;
        }

        $rate = (float) $employee->commission_rate;

        return match ($employee->commission_type) {
            'percent_order' => round((float) $order->base_total * $rate / 100, 2),
            'fixed_order' => $rate,
            default => 0.0,
        };
    }

    /**
     * Whether an order still earns commission.
     *
     * `status` on an order is never "returned" — that is not one of its
     * values (see Order::CANCELLED etc.) — and "refunded" is a value of the
     * separate `payment_status` column, not `status`. A return is its own
     * row in `goods_returns`, so that table is what actually says whether
     * goods came back. Cancelled and returned orders, and orders refunded in
     * full, earn nothing: paying commission on a sale that came back is how
     * a month's payroll quietly exceeds a month's income.
     */
    private function isEligible(object $order): bool
    {
        if ($order->status === 'cancelled') {
            return false;
        }

        if ($order->payment_status === 'refunded') {
            return false;
        }

        if ($order->has_return) {
            return false;
        }

        return true;
    }

    /**
     * Get orders created by this employee in a period.
     *
     * Attributed by who recorded it (created_by), not by the shop's own
     * "order agent" text: that field is whatever the storefront happened
     * to send, is often a customer-facing name, and is empty for anything
     * typed in by hand.
     */
    private function ordersFor(Employee $employee, string $periodStart, string $periodEnd): array
    {
        // Query orders table - using raw DB query to avoid model dependency issues
        $orders = DB::table('orders')
            ->where('business_id', $employee->business_id)
            ->where('created_by', $employee->linked_user_id)
            ->whereBetween('ordered_on', [$periodStart, $periodEnd])
            ->orderByDesc('ordered_on')
            ->get();

        if ($orders->isEmpty()) {
            return [];
        }

        // Any order with a return that has not itself been cancelled — goods
        // expected back, received, or already settled — is a return in
        // progress or completed, and earns no commission either way.
        $returnedOrderIds = DB::table('goods_returns')
            ->whereIn('order_id', $orders->pluck('id'))
            ->where('status', '!=', 'cancelled')
            ->pluck('order_id')
            ->all();

        /*
         * Minor units become a major-unit figure using the order's own
         * currency scale, not a hardcoded 100.
         *
         * Dividing everything by 100 is only right for the two-decimal
         * currencies. A ¥100,000 order (JPY has no minor unit) came out as
         * 1,000 — commission on a hundredth of the sale — and a BHD order
         * (three decimals) came out ten times too large. For a product sold
         * into every country, that is not an edge case, it is a country.
         */
        return $orders->map(function ($order) use ($returnedOrderIds) {
            $divisor = 10 ** Currencies::scale($order->currency ?? 'USD');

            return (object) [
                'id' => $order->id,
                'reference' => $order->number,
                'base_total' => $divisor > 1 ? $order->total_minor / $divisor : $order->total_minor,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'has_return' => in_array($order->id, $returnedOrderIds, true),
                'ordered_on' => $order->ordered_on,
            ];
        })->toArray();
    }

    /**
     * Get commission summary for display (what they've earned but not yet been paid).
     *
     * @return array{
     *   period_start: string,
     *   period_end: string,
     *   commission: float,
     *   orders_count: int,
     *   total_sold: float
     * }
     */
    public function summary(Employee $employee, string $periodStart, string $periodEnd): array
    {
        $result = $this->calculate($employee, $periodStart, $periodEnd);

        $totalSold = array_sum(array_column($result['order_details'], 'total'));

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'commission' => $result['total'],
            'orders_count' => $result['orders'],
            'total_sold' => round($totalSold, 2),
        ];
    }
}
