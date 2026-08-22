<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Money\Currencies;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderLine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Import orders from CSV, Excel, or JSON files.
 *
 * Automatically maps columns to order fields using flexible matching.
 */
class OrderImporter
{
    /**
     * Column mapping patterns for automatic detection.
     *
     * Maps common column names from different platforms to our fields.
     */
    private const COLUMN_MAPPINGS = [
        'number' => ['order_number', 'order #', 'order', 'order_id', 'id', 'number'],
        'customer_name' => ['customer', 'customer_name', 'customer name', 'name', 'buyer'],
        'customer_email' => ['email', 'customer_email', 'customer email', 'buyer_email'],
        'customer_phone' => ['phone', 'customer_phone', 'customer phone', 'telephone', 'mobile'],
        'total' => ['total', 'order_total', 'amount', 'grand_total'],
        'subtotal' => ['subtotal', 'sub_total', 'sub total', 'items_total'],
        'tax' => ['tax', 'tax_amount', 'vat'],
        'shipping' => ['shipping', 'shipping_cost', 'delivery_fee', 'shipping_fee'],
        'discount' => ['discount', 'discount_amount'],
        'currency' => ['currency', 'currency_code'],
        'status' => ['status', 'order_status', 'state'],
        'payment_status' => ['payment_status', 'payment', 'paid'],
        'ordered_on' => ['date', 'order_date', 'created_at', 'ordered_on', 'placed_on'],
        'shipping_name' => ['shipping_name', 'ship_to', 'delivery_name'],
        'shipping_address' => ['shipping_address', 'address', 'delivery_address'],
        'shipping_city' => ['city', 'shipping_city', 'delivery_city'],
        'shipping_postcode' => ['postcode', 'zip', 'postal_code', 'zipcode'],
        'shipping_country' => ['country', 'shipping_country'],
        'notes' => ['notes', 'note', 'comments', 'customer_note'],
    ];

    /**
     * Import orders from uploaded file.
     *
     * @return array{imported: int, updated: int, skipped: int, failed: int, total: int, errors: array<string>}
     */
    public function import(UploadedFile $file, string $format, int $businessId): array
    {
        $data = $this->parseFile($file, $format);
        
        if (empty($data)) {
            throw new \Exception('No data found in file');
        }

        return $this->processOrders($data, $businessId);
    }

    /**
     * Parse file based on format.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseFile(UploadedFile $file, string $format): array
    {
        return match ($format) {
            'csv' => $this->parseCsv($file),
            'excel' => $this->parseExcel($file),
            'json' => $this->parseJson($file),
            default => throw new \Exception('Unsupported format'),
        };
    }

    /**
     * Parse CSV file.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw new \Exception('Could not open CSV file');
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            throw new \Exception('CSV file is empty or invalid');
        }

        $data = [];
        while (($row = fgetcsv($handle)) !== false) {
            $data[] = array_combine($headers, $row) ?: [];
        }

        fclose($handle);
        return $data;
    }

    /**
     * Parse Excel file.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseExcel(UploadedFile $file): array
    {
        // For now, treat as CSV. In production, use PhpSpreadsheet library
        return $this->parseCsv($file);
    }

    /**
     * Parse JSON file.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseJson(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());
        if ($content === false) {
            throw new \Exception('Could not read JSON file');
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new \Exception('Invalid JSON format');
        }

        // Handle both array of objects and wrapped formats
        if (isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }

        return $data;
    }

    /**
     * Process and import orders.
     *
     * @param array<int, array<string, mixed>> $data
     * @return array{imported: int, updated: int, skipped: int, failed: int, total: int, errors: array<string>}
     */
    private function processOrders(array $data, int $businessId): array
    {
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($data as $index => $row) {
            try {
                $mapped = $this->mapColumns($row);
                
                if (empty($mapped['number'])) {
                    $skipped++;
                    $errors[] = "Row " . ($index + 2) . ": Missing order number";
                    continue;
                }

                DB::transaction(function () use ($mapped, $businessId, &$imported, &$updated): void {
                    $existing = Order::query()
                        ->where('business_id', $businessId)
                        ->where('number', $mapped['number'])
                        ->first();

                    if ($existing) {
                        $existing->update($this->prepareOrderData($mapped, $businessId));
                        $updated++;
                    } else {
                        Order::query()->create($this->prepareOrderData($mapped, $businessId));
                        $imported++;
                    }
                });
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Row " . ($index + 2) . ": " . $e->getMessage();
            }
        }

        return [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'total' => count($data),
            'errors' => array_slice($errors, 0, 10), // Limit to first 10 errors
        ];
    }

    /**
     * Map columns from import file to our fields.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapColumns(array $row): array
    {
        $mapped = [];

        foreach (self::COLUMN_MAPPINGS as $field => $patterns) {
            foreach ($patterns as $pattern) {
                foreach ($row as $key => $value) {
                    if (strcasecmp(trim((string) $key), $pattern) === 0) {
                        $mapped[$field] = $value;
                        break 2;
                    }
                }
            }
        }

        return $mapped;
    }

    /**
     * Prepare order data for database.
     *
     * @param array<string, mixed> $mapped
     * @return array<string, mixed>
     */
    private function prepareOrderData(array $mapped, int $businessId): array
    {
        $currency = mb_strtoupper(trim((string) ($mapped['currency'] ?? 'USD')));
        $scale = 10 ** Currencies::scale($currency);

        return [
            'business_id' => $businessId,
            'number' => trim((string) $mapped['number']),
            'customer_name' => trim((string) ($mapped['customer_name'] ?? '')),
            'customer_email' => trim((string) ($mapped['customer_email'] ?? '')),
            'customer_phone' => trim((string) ($mapped['customer_phone'] ?? '')),
            'shipping_name' => trim((string) ($mapped['shipping_name'] ?? $mapped['customer_name'] ?? '')),
            'shipping_address' => trim((string) ($mapped['shipping_address'] ?? '')),
            'shipping_city' => trim((string) ($mapped['shipping_city'] ?? '')),
            'shipping_postcode' => trim((string) ($mapped['shipping_postcode'] ?? '')),
            'shipping_country' => trim((string) ($mapped['shipping_country'] ?? '')),
            'subtotal_minor' => (int) (((float) ($mapped['subtotal'] ?? 0)) * $scale),
            'tax_minor' => (int) (((float) ($mapped['tax'] ?? 0)) * $scale),
            'shipping_minor' => (int) (((float) ($mapped['shipping'] ?? 0)) * $scale),
            'discount_minor' => (int) (((float) ($mapped['discount'] ?? 0)) * $scale),
            'total_minor' => (int) (((float) ($mapped['total'] ?? 0)) * $scale),
            'currency' => $currency,
            'status' => $this->normalizeStatus((string) ($mapped['status'] ?? 'pending')),
            'payment_status' => $this->normalizePaymentStatus((string) ($mapped['payment_status'] ?? 'unpaid')),
            'fulfilment_status' => 'unfulfilled',
            'channel' => 'api',
            'ordered_on' => $this->parseDate((string) ($mapped['ordered_on'] ?? now())),
            'notes' => trim((string) ($mapped['notes'] ?? '')),
        ];
    }

    /**
     * Normalize status values.
     */
    private function normalizeStatus(string $status): string
    {
        $status = mb_strtolower(trim($status));

        return match ($status) {
            'pending', 'new', 'received' => 'pending',
            'processing', 'confirmed' => 'confirmed',
            'completed', 'complete', 'fulfilled' => 'completed',
            'cancelled', 'canceled', 'void' => 'cancelled',
            'refunded' => 'refunded',
            'failed' => 'failed',
            default => 'pending',
        };
    }

    /**
     * Normalize payment status.
     */
    private function normalizePaymentStatus(string $status): string
    {
        $status = mb_strtolower(trim($status));

        return match (true) {
            in_array($status, ['paid', 'payment_received', 'complete', 'yes', '1', 'true'], true) => 'paid',
            default => 'unpaid',
        };
    }

    /**
     * Parse date from various formats.
     */
    private function parseDate(string $date): string
    {
        try {
            return \Carbon\Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return now()->toDateString();
        }
    }
}
