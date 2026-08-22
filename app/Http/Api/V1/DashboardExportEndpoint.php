<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Money\CurrencyService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\Endpoint;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The dashboard, as a file.
 *
 * ── What "Export" has to mean ────────────────────────────────────────────────
 *
 * A file that opens. The button existed on the screen for a while doing nothing
 * at all, which is worse than not offering it: somebody presses it, nothing
 * happens, and they conclude the product is broken rather than that the feature
 * is unfinished.
 *
 * ── Why the spreadsheet is not .xlsx ─────────────────────────────────────────
 *
 * A real xlsx is a zip archive, and this platform has no ext-zip — so every
 * library that writes one is unavailable. Rather than shipping a .csv wearing an
 * .xlsx extension, which Excel opens with a "the format does not match" warning
 * and looks like a bug in our product, this writes SpreadsheetML: Microsoft's
 * own XML workbook format, plain text, no archive, opened natively by Excel and
 * by LibreOffice. It carries real column widths, a bold header row and typed
 * numeric cells, so figures arrive as numbers that sum rather than as text.
 *
 * If ext-zip is ever enabled, this is the seam to swap.
 */
class DashboardExportEndpoint extends Endpoint
{
    public function __invoke(Request $request, TenantContext $tenant, CurrencyService $currencyService): Response|StreamedResponse
    {
        $this->validate($request, [
            'format' => 'required|in:pdf,excel',
            'from' => 'sometimes|date',
            'to' => 'sometimes|date|after_or_equal:from',
        ]);

        /** @var User $user */
        $user = $request->user();

        $business = $tenant->business();

        if ($business === null) {
            return response(['message' => 'No business selected'], 400);
        }

        $now = CarbonImmutable::now($user->timezoneOrDefault());
        $from = $request->filled('from')
            ? CarbonImmutable::parse($request->string('from')->value())
            : $now->startOfMonth();
        $to = $request->filled('to')
            ? CarbonImmutable::parse($request->string('to')->value())
            : $now->endOfMonth();

        $report = app(DashboardEndpoint::class);
        $payload = $report->index(
            $request->merge(['period' => 'this_month']),
            $tenant,
            $currencyService,
        );

        /** @var array{data: array<string, mixed>} $decoded */
        $decoded = json_decode($payload->getContent() ?: '{}', true);
        $data = $decoded['data'] ?? [];

        $rows = collect($data['kpis'] ?? [])
            ->map(fn (array $kpi) => [
                $kpi['label'] ?? '',
                $kpi['value'] ?? '',
                $kpi['delta'] === null ? '—' : $kpi['delta'].'%',
                ucfirst((string) ($kpi['direction'] ?? 'flat')),
            ])
            ->all();

        $meta = [
            'business' => $business->name,
            // The workspace's reporting currency — the same one every figure
            // above has already been converted into — not the business's own,
            // which is what $business->base_currency would give the fallback
            // if $data['currency'] were ever absent.
            'currency' => $data['currency'] ?? $currencyService->base(),
            'categories' => collect($data['context']['categories'] ?? [])
                ->pluck('name')
                ->implode(', '),
            'from' => $from->format('j M Y'),
            'to' => $to->format('j M Y'),
            'generated' => $now->format('j M Y, H:i'),
        ];

        // Spaces and slashes in a business name make a filename that some
        // browsers quietly truncate at the first one.
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($business->name)) ?: 'business';
        $stem = trim($slug, '-').'-overview-'.$from->format('Y-m-d');

        return $request->string('format')->value() === 'pdf'
            ? $this->pdf($rows, $meta, $stem)
            : $this->spreadsheet($rows, $meta, $stem);
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @param  array<string, string>  $meta
     */
    private function pdf(array $rows, array $meta, string $stem): Response
    {
        $html = view('exports.dashboard', [
            'rows' => $rows,
            'meta' => $meta,
        ])->render();

        $options = new Options();
        // The report is generated from our own view with our own data — there is
        // no user-authored HTML in it — but a PDF renderer that will fetch a URL
        // is an SSRF primitive, so it is switched off rather than relied upon
        // never to be handed a remote src.
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$stem.'.pdf"',
        ]);
    }

    /**
     * SpreadsheetML 2003 — plain XML, no archive, opened natively by Excel.
     *
     * @param  array<int, array<int, string>>  $rows
     * @param  array<string, string>  $meta
     */
    private function spreadsheet(array $rows, array $meta, string $stem): Response
    {
        $esc = fn (string $v) => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $cells = function (array $values, string $style = '') use ($esc): string {
            $s = $style !== '' ? ' ss:StyleID="'.$style.'"' : '';

            return collect($values)
                ->map(function ($value) use ($esc, $s) {
                    // Typed, so a figure lands as a number Excel will sum rather
                    // than as text with a green triangle in the corner.
                    $numeric = is_numeric(str_replace([',', ' '], '', (string) $value));
                    $type = $numeric ? 'Number' : 'String';
                    $out = $numeric ? str_replace([',', ' '], '', (string) $value) : $esc((string) $value);

                    return '<Cell'.$s.'><Data ss:Type="'.$type.'">'.$out.'</Data></Cell>';
                })
                ->implode('');
        };

        $body = '';

        foreach ([
            ['Business', $meta['business']],
            ['Trades', $meta['categories'] !== '' ? $meta['categories'] : '—'],
            ['Period', $meta['from'].' to '.$meta['to']],
            ['Currency', $meta['currency']],
            ['Generated', $meta['generated']],
        ] as $line) {
            $body .= '<Row>'.$cells([$line[0]], 'sLabel').$cells([$line[1]]).'</Row>';
        }

        $body .= '<Row/>';
        $body .= '<Row>'.$cells(['Measure', 'Value', 'Change', 'Direction'], 'sHead').'</Row>';

        foreach ($rows as $row) {
            $body .= '<Row>'.$cells($row).'</Row>';
        }

        $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <?mso-application progid="Excel.Sheet"?>
        <Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
                  xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
          <Styles>
            <Style ss:ID="sHead">
              <Font ss:Bold="1" ss:Color="#FFFFFF"/>
              <Interior ss:Color="#0891B2" ss:Pattern="Solid"/>
            </Style>
            <Style ss:ID="sLabel"><Font ss:Bold="1"/></Style>
          </Styles>
          <Worksheet ss:Name="Overview">
            <Table>
              <Column ss:Width="150"/><Column ss:Width="120"/>
              <Column ss:Width="80"/><Column ss:Width="80"/>
              {$body}
            </Table>
          </Worksheet>
        </Workbook>
        XML;

        return response($xml, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$stem.'.xls"',
        ]);
    }
}
