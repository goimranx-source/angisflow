<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Sales\OrderImporter;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Import orders from external files (CSV, Excel, JSON).
 *
 * Supports multiple formats and automatically maps columns to order fields.
 */
class OrderImportEndpoint
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly OrderImporter $importer,
    ) {}

    public function import(Request $request): JsonResponse
    {
        $business = $this->tenant->business();

        abort_if($business === null, 409, 'No business is open.');

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'max:10240'], // 10MB max
            'format' => ['required', 'string', 'in:csv,excel,json'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $file = $request->file('file');
        $format = (string) $request->input('format');

        try {
            $result = $this->importer->import($file, $format, (int) $business->id);

            return response()->json([
                'message' => 'Import completed successfully',
                'data' => [
                    'imported' => $result['imported'],
                    'updated' => $result['updated'],
                    'skipped' => $result['skipped'],
                    'failed' => $result['failed'],
                    'total' => $result['total'],
                    'errors' => $result['errors'],
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Import failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
