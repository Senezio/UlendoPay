<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OutboxEvent;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionAdminController extends Controller
{
    public function transactions(Request $request): JsonResponse
    {
        $query = Transaction::with([
            'sender:id,name,email',
            'recipient:id,full_name,mobile_number,country_code',
        ]);

        if ($request->filled('status'))        $query->where('status', $request->status);
        if ($request->filled('from_currency')) $query->where('send_currency', $request->from_currency);
        if ($request->filled('to_currency'))   $query->where('receive_currency', $request->to_currency);
        if ($request->filled('date_from'))     $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))       $query->whereDate('created_at', '<=', $request->date_to);
        if ($request->filled('search'))        $query->where('reference_number', 'like', "%{$request->search}%");

        return response()->json($query->latest()->paginate(25));
    }

    public function transactionShow(string $reference): JsonResponse
    {
        $transaction = Transaction::with([
            'sender:id,name,email',
            'recipient',
            'partner',
            'disbursements',
            'journalGroup.entries.account',
        ])->where('reference_number', $reference)->firstOrFail();

        return response()->json(['transaction' => $transaction]);
    }

    public function retryTransaction(Request $request, string $reference): JsonResponse
    {
        $transaction = Transaction::where('reference_number', $reference)
            ->whereIn('status', ['failed', 'escrowed', 'processing', 'retrying'])
            ->firstOrFail();

        OutboxEvent::create([
            'event_type'     => 'disbursement_requested',
            'transaction_id' => $transaction->id,
            'payload'        => [
                'transaction_id' => $transaction->id,
                'manual_retry'   => true,
                'retried_by'     => $request->user()->id,
            ],
            'status'          => 'pending',
            'next_attempt_at' => now(),
        ]);

        $transaction->update(['status' => 'retrying']);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'admin.transaction.retry',
            'entity_type' => 'Transaction',
            'entity_id'   => $transaction->id,
            'new_values'  => ['reference' => $reference, 'manual_retry' => true],
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'Transaction queued for retry.', 'status' => 'retrying']);
    }

    public function exportTransactions(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $data = $request->validate([
            'format' => 'required|in:csv,xlsx,pdf',
            'from'   => 'nullable|date',
            'to'     => 'nullable|date',
            'status' => 'nullable|string',
        ]);

        $query = Transaction::with(['sender:id,name,email', 'recipient:id,full_name,mobile_number'])->latest();

        if (!empty($data['from']))   $query->whereDate('created_at', '>=', $data['from']);
        if (!empty($data['to']))     $query->whereDate('created_at', '<=', $data['to']);
        if (!empty($data['status'])) $query->where('status', $data['status']);

        $transactions = $query->get();

        $rows = $transactions->map(fn($t) => [
            'Reference'        => $t->reference_number,
            'Date'             => $t->created_at->format('Y-m-d H:i'),
            'Sender'           => $t->sender?->name,
            'Recipient'        => $t->recipient?->full_name,
            'Recipient Phone'  => $t->recipient?->mobile_number,
            'Send Amount'      => $t->send_amount,
            'Send Currency'    => $t->send_currency,
            'Receive Amount'   => $t->receive_amount,
            'Receive Currency' => $t->receive_currency,
            'Fee'              => $t->fee_amount,
            'Rate'             => $t->locked_rate,
            'Status'           => $t->status,
        ]);

        $filename = 'transactions_' . now()->format('Ymd_His');

        if ($data['format'] === 'csv') {
            $handle = fopen('php://temp', 'r+');
            fputcsv($handle, array_keys($rows->first() ?? []));
            foreach ($rows as $row) fputcsv($handle, array_values($row));
            rewind($handle);
            $csv = stream_get_contents($handle);
            fclose($handle);
            return response($csv, 200, [
                'Content-Type'        => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}.csv\"",
            ]);
        }

        if ($data['format'] === 'xlsx') {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet       = $spreadsheet->getActiveSheet();
            $headers     = array_keys($rows->first() ?? []);

            foreach ($headers as $col => $header) {
                $sheet->setCellValueByColumnAndRow($col + 1, 1, $header);
                $sheet->getStyleByColumnAndRow($col + 1, 1)->getFont()->setBold(true);
            }

            foreach ($rows as $rowIndex => $row) {
                foreach (array_values($row) as $col => $value) {
                    $sheet->setCellValueByColumnAndRow($col + 1, $rowIndex + 2, $value);
                }
            }

            foreach (range(1, count($headers)) as $col) {
                $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
            }

            $writer  = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $tmpFile = tempnam(sys_get_temp_dir(), 'ulendo_') . '.xlsx';
            $writer->save($tmpFile);

            while (ob_get_level()) ob_end_clean();

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
            header('Content-Length: ' . filesize($tmpFile));
            header('Cache-Control: no-cache, no-store');
            header('Pragma: no-cache');
            readfile($tmpFile);
            unlink($tmpFile);
            exit;
        }

        $adminName = $request->user()?->name ?? 'Administrator';
        $logoPath  = public_path('logo.png');
        $logoData  = file_exists($logoPath) ? base64_encode(file_get_contents($logoPath)) : null;
        $logoImg   = $logoData
            ? "<img src=\"data:image/png;base64,{$logoData}\" style=\"height:48px;width:auto;display:block;\" />"
            : "<div style=\"font-size:22px;font-weight:900;color:#1a1a1a;\">Ulendo<span style=\"color:#e85d04;\">Pay</span></div>";

        $html  = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\">";
        $html .= "<style>@page{margin:20mm;size:A4 landscape;}*{box-sizing:border-box;margin:0;padding:0;}body{font-family:Arial,sans-serif;font-size:9px;color:#1a1a1a;background:#fff;}table{border-collapse:collapse;}table.data{width:100%;border-top:2px solid #1a1a1a;border-bottom:1px solid #ccc;margin-top:6px;}table.data thead tr{border-bottom:1px solid #1a1a1a;}table.data th{padding:7px 8px;text-align:left;font-size:9px;font-weight:700;}table.data td{padding:5px 8px;font-size:9px;color:#333;border-bottom:1px solid #f0f0f0;}table.data tbody tr:nth-child(even) td{background:#f9f9f9;}table.data tbody tr:nth-child(odd) td{background:#fff;}</style></head><body>";
        $html .= "<table width=\"100%\" style=\"margin-bottom:24px;\"><tr><td width=\"50%\" style=\"vertical-align:top;\">{$logoImg}<div style=\"margin-top:8px;font-size:9px;color:#444;line-height:1.8;\">Ulendo Technologies Limited<br>P.O. Box 37894, Lilongwe 3, Malawi<br>www.ulendopay.com</div></td><td width=\"50%\" style=\"vertical-align:top;text-align:right;\"><div style=\"font-size:16px;font-weight:700;color:#1a1a1a;text-transform:uppercase;\">Transaction Export Report</div></td></tr></table>";
        $html .= "<hr style=\"border:none;border-top:1px solid #ccc;margin-bottom:16px;\" />";
        $html .= "<table width=\"100%\" style=\"margin-bottom:16px;\"><tr><td style=\"font-size:9px;color:#555;width:120px;\">Generated By:</td><td style=\"font-size:9px;font-weight:600;\">" . htmlspecialchars($adminName) . "</td></tr>";
        $html .= "<tr><td style=\"font-size:9px;color:#555;\">Report Date:</td><td style=\"font-size:9px;\">" . now()->format('d/m/Y H:i') . "</td></tr>";
        $html .= "<tr><td style=\"font-size:9px;color:#555;\">Total Records:</td><td style=\"font-size:9px;font-weight:600;\">" . $rows->count() . "</td></tr></table>";
        $html .= "<hr style=\"border:none;border-top:1px solid #ccc;margin-bottom:16px;\" />";
        $html .= "<table class=\"data\"><thead><tr>";

        foreach (array_keys($rows->first() ?? []) as $header) {
            $html .= "<th>" . htmlspecialchars($header) . "</th>";
        }

        $html .= "</tr></thead><tbody>";
        foreach ($rows as $row) {
            $html .= "<tr>";
            foreach ($row as $val) $html .= "<td>" . htmlspecialchars((string) $val) . "</td>";
            $html .= "</tr>";
        }

        $html .= "<tr><td colspan=\"" . count($rows->first() ?? []) . "\" style=\"padding:8px;font-size:9px;color:#999;text-align:center;border-top:1px solid #e0e0e0;\">--- End of Transactions ---</td></tr>";
        $html .= "</tbody></table>";
        $html .= "<div style=\"margin-top:40px;border-top:1px solid #ccc;padding-top:10px;\"><table width=\"100%\"><tr><td style=\"font-size:8px;color:#999;\">Ulendo Technologies Limited &middot; P.O. Box 37894, Lilongwe 3, Malawi</td><td style=\"font-size:8px;color:#999;text-align:right;\">&copy; " . now()->year . " Ulendo Technologies Limited. Confidential.</td></tr></table></div>";
        $html .= "</body></html>";

        return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', 'landscape')->download($filename . '.pdf');
    }
}
