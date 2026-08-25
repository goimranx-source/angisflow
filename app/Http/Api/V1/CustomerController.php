<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Sales\Models\Customer;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The customer list.
 *
 * ── Why the totals are columns rather than sums ──────────────────────────────
 *
 * `orders_count` and `total_spent` come from `order_count` and
 * `lifetime_value_minor` on the row itself, not from counting orders on read.
 * A customer list is one of the few screens somebody leaves open all day, and
 * an aggregate over every order ever placed, per customer, per repaint, is the
 * query that falls over first once a shop has traded for a year. The columns
 * are maintained where orders are written; here they are simply read.
 *
 * ── Money crosses the wire in minor units ────────────────────────────────────
 *
 * `total_spent` is minor units — paise, cents — as an integer, matching what
 * the table stores. Formatting is the client's business because the client
 * knows the viewer's locale and this endpoint does not.
 */
class CustomerController extends Endpoint
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $business = $this->requireBusiness();

        $query = Customer::where('business_id', $business->id)
            // A customer merged into another is not a customer any more; the
            // survivor carries their history.
            ->whereNull('merged_into_id');

        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%");
            });
        }

        if ($status = $request->get('status')) {
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        // Only columns that exist and are indexed — a sort_by naming anything
        // else falls back rather than erroring, because a stale bookmark
        // carrying an old sort should still open the page.
        $sortColumn = match ($request->get('sort_by')) {
            'name' => 'name',
            'total_spent' => 'lifetime_value_minor',
            'orders_count' => 'order_count',
            'last_order_at' => 'last_order_on',
            default => 'created_at',
        };
        $sortDirection = $request->get('sort_direction') === 'asc' ? 'asc' : 'desc';

        $perPage = min(max((int) $request->get('per_page', 50), 1), 200);
        $page = $query->orderBy($sortColumn, $sortDirection)->paginate($perPage);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Customer $c) => $this->present($c))->all(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(string $publicId): JsonResponse
    {
        return response()->json(['data' => $this->present($this->findByPublicId($publicId))]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $this->requireBusiness();

        $data = $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'nullable|email|max:150',
            'phone' => 'nullable|string|max:30',
            'company' => 'nullable|string|max:150',
            'tax_number' => 'nullable|string|max:50',
            'billing_address' => 'nullable|string|max:255',
            'billing_city' => 'nullable|string|max:100',
            'billing_postcode' => 'nullable|string|max:20',
            'billing_country' => 'nullable|string|size:2',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'notes' => 'nullable|string|max:2000',
            'is_active' => 'nullable|boolean',
        ]);

        $customer = new Customer($data);
        $customer->account_id = $business->account_id;
        $customer->business_id = $business->id;
        $customer->currency = $business->base_currency;
        $customer->is_active = $data['is_active'] ?? true;
        $customer->save();

        return response()->json(['data' => $this->present($customer)], 201);
    }

    public function update(Request $request, string $publicId): JsonResponse
    {
        $customer = $this->findByPublicId($publicId);

        $data = $request->validate([
            'name' => 'sometimes|string|max:150',
            'email' => 'nullable|email|max:150',
            'phone' => 'nullable|string|max:30',
            'company' => 'nullable|string|max:150',
            'tax_number' => 'nullable|string|max:50',
            'billing_address' => 'nullable|string|max:255',
            'billing_city' => 'nullable|string|max:100',
            'billing_postcode' => 'nullable|string|max:20',
            'billing_country' => 'nullable|string|size:2',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'notes' => 'nullable|string|max:2000',
            'is_active' => 'nullable|boolean',
        ]);

        $customer->fill($data)->save();

        return response()->json(['data' => $this->present($customer)]);
    }

    /**
     * Retire a customer rather than erase them.
     *
     * Their orders and invoices reference this row, and a sale with no buyer is
     * a hole in the books. The soft delete keeps the history intact and takes
     * them out of the list.
     */
    public function destroy(string $publicId): JsonResponse
    {
        $this->findByPublicId($publicId)->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Customer $c): array
    {
        return [
            'id' => $c->public_id,
            'name' => $c->name,
            'email' => $c->email ?? '',
            'phone' => $c->phone ?? '',
            'company' => $c->company,

            /*
             * ── Everything update() accepts, present() returns ───────────────
             *
             * These were missing, which was harmless while nothing edited a
             * customer and dangerous the moment something did: a form loads
             * what it is given, so a field the payload omits arrives blank and
             * is saved back blank. The rule is that the two lists match.
             */
            'tax_number' => $c->tax_number,
            'billing_address' => $c->billing_address,
            'billing_city' => $c->billing_city,
            'billing_postcode' => $c->billing_postcode,
            'billing_country' => $c->billing_country,
            'payment_terms_days' => $c->payment_terms_days,
            'notes' => $c->notes,
            'total_spent' => (int) $c->lifetime_value_minor,
            'orders_count' => (int) $c->order_count,
            'status' => $c->is_active ? 'active' : 'inactive',
            // Tags are a shared account-level concept the customer screen does
            // not write yet; an empty list keeps the contract honest until it
            // does, rather than inventing labels.
            'tags' => [],
            'created_at' => $c->created_at?->toIso8601String(),
            'last_order_at' => $c->last_order_on?->toIso8601String(),
        ];
    }

    private function findByPublicId(string $publicId): Customer
    {
        $business = $this->requireBusiness();

        return Customer::where('business_id', $business->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function requireBusiness()
    {
        $business = $this->tenant->business();

        if (! $business) {
            throw new RuntimeException('No business is open — customers belong to one set of books.');
        }

        return $business;
    }
}
