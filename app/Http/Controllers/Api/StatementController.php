<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StatementController extends Controller
{
    public function download(Request $request)
    {
        $data = $request->validate([
            'currency' => 'required|string|size:3',
            'from'     => 'required|date',
            'to'       => 'required|date|after_or_equal:from',
        ]);

        $user     = $request->user();
        $currency = strtoupper($data['currency']);
        $from     = Carbon::parse($data['from'])->startOfDay();
        $to       = Carbon::parse($data['to'])->endOfDay();

        $account = Account::where('owner_id', $user->id)
            ->where('owner_type', \App\Models\User::class)
            ->where('type', 'user_wallet')
            ->where('currency_code', $currency)
            ->firstOrFail();

        // Opening balance — all entries before the period
        $openingBalance = JournalEntry::where('account_id', $account->id)
            ->where('posted_at', '<', $from)
            ->get()
            ->reduce(function ($carry, $entry) {
                return $entry->entry_type === 'credit'
                    ? $carry + (float) $entry->amount
                    : $carry - (float) $entry->amount;
            }, 0.0);

        $periodOpeningBalance = $openingBalance;

        // Entries within the period
        $entries = JournalEntry::where('account_id', $account->id)
            ->whereBetween('posted_at', [$from, $to])
            ->with('group')
            ->orderBy('posted_at')
            ->get()
            ->map(function ($entry) use (&$openingBalance) {
                if ($entry->entry_type === 'credit') {
                    $openingBalance += (float) $entry->amount;
                } else {
                    $openingBalance -= (float) $entry->amount;
                }
                return [
                    'date'            => $entry->posted_at->format('d M Y, H:i'),
                    'type'            => strtoupper($entry->entry_type),
                    'amount'          => (float) $entry->amount,
                    'running_balance' => $openingBalance,
                    'reference'       => $entry->group?->reference ?? '—',
                    'description'     => $entry->description ?? $entry->group?->description ?? '—',
                ];
            });

        $closingBalance = $openingBalance;

        $totalCredits = $entries->filter(fn($e) => $e['type'] === 'CREDIT')->sum('amount');
        $totalDebits  = $entries->filter(fn($e) => $e['type'] === 'DEBIT')->sum('amount');

        $currencyNames = [
            'MWK' => 'Malawian Kwacha',
            'ZMW' => 'Zambian Kwacha',
            'KES' => 'Kenyan Shilling',
            'TZS' => 'Tanzanian Shilling',
            'ZAR' => 'South African Rand',
            'UGX' => 'Ugandan Shilling',
            'GHS' => 'Ghanaian Cedi',
            'MZN' => 'Mozambican Metical',
            'ETB' => 'Ethiopian Birr',
            'RWF' => 'Rwandan Franc',
            'BWP' => 'Botswana Pula',
            'MGA' => 'Malagasy Ariary',
            'XAF' => 'Central African CFA Franc',
            'XOF' => 'West African CFA Franc',
        ];
        $currencyName = $currencyNames[$currency] ?? $currency;

        $logoPath = public_path('logo.png');
        $logoData = file_exists($logoPath) ? base64_encode(file_get_contents($logoPath)) : null;
        $logoImg  = $logoData
            ? '<img src="data:image/png;base64,' . $logoData . '" style="height:48px;width:auto;display:block;" />'
            : '<div style="font-size:22px;font-weight:900;color:#1a1a1a;">Ulendo<span style="color:#e85d04;">Pay</span></div>';

        $fmt = fn($v) => number_format((float) $v, 2);

        // ── Transaction rows ──────────────────────────────────────────────
        $rowsHtml = '';
        if ($entries->isEmpty()) {
            $rowsHtml = '<tr>
                <td colspan="5" style="padding:20px 8px;text-align:center;color:#999;font-style:italic;font-size:9px;">
                    No transactions found for this period.
                </td>
            </tr>';
        } else {
            foreach ($entries as $i => $e) {
                $bg          = $i % 2 === 0 ? '#ffffff' : '#f9f9f9';
                $isCredit    = $e['type'] === 'CREDIT';
                $creditAmt   = $isCredit ? $fmt($e['amount']) : '';
                $debitAmt    = !$isCredit ? $fmt($e['amount']) : '';
                $desc        = htmlspecialchars($e['description']);
                $rowsHtml   .= '<tr style="background:' . $bg . ';">';
                $rowsHtml   .= '<td style="padding:6px 8px;font-size:9px;color:#333;white-space:nowrap;">' . $e['date'] . '</td>';
                $rowsHtml   .= '<td style="padding:6px 8px;font-size:9px;color:#333;">' . $desc . '</td>';
                $rowsHtml   .= '<td style="padding:6px 8px;font-size:9px;text-align:right;color:#333;">' . $creditAmt . '</td>';
                $rowsHtml   .= '<td style="padding:6px 8px;font-size:9px;text-align:right;color:#333;">' . $debitAmt . '</td>';
                $rowsHtml   .= '<td style="padding:6px 8px;font-size:9px;text-align:right;color:#333;font-weight:600;">' . $fmt($e['running_balance']) . '</td>';
                $rowsHtml   .= '</tr>';
            }
            // End of transactions row
            $rowsHtml .= '<tr>
                <td colspan="5" style="padding:8px 8px;font-size:9px;color:#999;text-align:center;border-top:1px solid #e0e0e0;">
                    --- End of Transactions ---
                </td>
            </tr>';
        }

        // ── HTML ──────────────────────────────────────────────────────────
        $html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; font-size: 10px; color: #1a1a1a; background: #fff; }
  table { border-collapse: collapse; }
</style>
</head>
<body style="padding: 36px 40px;">

  <!-- ── Header ──────────────────────────────────────────────────────── -->
  <table width="100%" style="margin-bottom: 24px;">
    <tr>
      <td width="50%" style="vertical-align:top;">
        ' . $logoImg . '
        <div style="margin-top:8px;font-size:9px;color:#444;line-height:1.8;">
          Ulendo Technologies Limited<br>
          P.O. Box 37894, Lilongwe 3, Malawi<br>
          www.ulendopay.com<br>
          support@ulendopay.com
        </div>
      </td>
      <td width="50%" style="vertical-align:top;text-align:right;">
        <div style="font-size:16px;font-weight:700;color:#1a1a1a;letter-spacing:0.04em;text-transform:uppercase;">
          Statement of Account
        </div>
      </td>
    </tr>
  </table>

  <hr style="border:none;border-top:1px solid #ccc;margin-bottom:16px;" />

  <!-- ── Account metadata ────────────────────────────────────────────── -->
  <table width="100%" style="margin-bottom:16px;">
    <tr>
      <td style="font-size:9px;color:#555;padding:3px 0;width:120px;">Account Number:</td>
      <td style="font-size:9px;color:#1a1a1a;font-weight:600;font-family:monospace;">' . $account->code . '</td>
      <td style="text-align:right;font-size:9px;color:#555;">Page 1 of 1</td>
    </tr>
    <tr>
      <td style="font-size:9px;color:#555;padding:3px 0;">Statement Date:</td>
      <td style="font-size:9px;color:#1a1a1a;">' . now()->format('d/m/Y') . '</td>
      <td></td>
    </tr>
    <tr>
      <td style="font-size:9px;color:#555;padding:3px 0;">Period Covered:</td>
      <td style="font-size:9px;color:#1a1a1a;">' . $from->format('d/m/Y') . ' to ' . $to->format('d/m/Y') . '</td>
      <td></td>
    </tr>
  </table>

  <hr style="border:none;border-top:1px solid #ccc;margin-bottom:16px;" />

  <!-- ── Customer info + Summary ─────────────────────────────────────── -->
  <table width="100%" style="margin-bottom:24px;">
    <tr>
      <td width="50%" style="vertical-align:top;">
        <div style="font-size:14px;font-weight:700;color:#1a1a1a;margin-bottom:4px;">' . htmlspecialchars($user->name) . '</div>
        <div style="font-size:9px;color:#555;line-height:1.8;">
          Account Type: ' . $currencyName . ' Wallet<br>
          Currency: ' . $currency . '<br>
          KYC Status: ' . strtoupper($user->kyc_status) . '
        </div>
      </td>
      <td width="50%" style="vertical-align:top;">
        <table width="100%">
          <tr>
            <td style="font-size:9px;color:#555;padding:3px 0;">Opening Balance:</td>
            <td style="font-size:9px;font-weight:600;text-align:right;">' . $fmt($periodOpeningBalance) . '</td>
          </tr>
          <tr>
            <td style="font-size:9px;color:#555;padding:3px 0;">Total Credit Amount:</td>
            <td style="font-size:9px;font-weight:600;text-align:right;">' . $fmt($totalCredits) . '</td>
          </tr>
          <tr>
            <td style="font-size:9px;color:#555;padding:3px 0;">Total Debit Amount:</td>
            <td style="font-size:9px;font-weight:600;text-align:right;">' . $fmt($totalDebits) . '</td>
          </tr>
          <tr>
            <td style="font-size:9px;color:#555;padding:3px 0;border-top:1px solid #ccc;">Closing Balance:</td>
            <td style="font-size:9px;font-weight:700;text-align:right;border-top:1px solid #ccc;">' . $fmt($closingBalance) . '</td>
          </tr>
          <tr>
            <td style="font-size:9px;color:#555;padding:3px 0;">Account Type:</td>
            <td style="font-size:9px;text-align:right;">Wallet Account</td>
          </tr>
          <tr>
            <td style="font-size:9px;color:#555;padding:3px 0;">Number of Transactions:</td>
            <td style="font-size:9px;font-weight:600;text-align:right;">' . $entries->count() . '</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <!-- ── Transactions table ──────────────────────────────────────────── -->
  <div style="font-size:11px;font-weight:700;color:#1a1a1a;margin-bottom:6px;">Transactions</div>
  <table width="100%" style="border-top:2px solid #1a1a1a;border-bottom:1px solid #ccc;">
    <thead>
      <tr style="border-bottom:1px solid #1a1a1a;">
        <th style="padding:7px 8px;text-align:left;font-size:9px;font-weight:700;width:16%;">Date</th>
        <th style="padding:7px 8px;text-align:left;font-size:9px;font-weight:700;">Description</th>
        <th style="padding:7px 8px;text-align:right;font-size:9px;font-weight:700;width:13%;">Credit</th>
        <th style="padding:7px 8px;text-align:right;font-size:9px;font-weight:700;width:13%;">Debit</th>
        <th style="padding:7px 8px;text-align:right;font-size:9px;font-weight:700;width:14%;">Balance</th>
      </tr>
    </thead>
    <tbody>' . $rowsHtml . '</tbody>
  </table>

  <!-- ── Footer ──────────────────────────────────────────────────────── -->
  <div style="margin-top:40px;border-top:1px solid #ccc;padding-top:10px;">
    <table width="100%">
      <tr>
        <td style="font-size:8px;color:#999;">
          Ulendo Technologies Limited &middot; P.O. Box 37894, Lilongwe 3, Malawi<br>
          This is a system-generated document. UlendoPay will NEVER ask for your PIN or password.
        </td>
        <td style="font-size:8px;color:#999;text-align:right;">
          &copy; ' . now()->year . ' Ulendo Technologies Limited. Confidential.
        </td>
      </tr>
    </table>
  </div>

</body>
</html>';

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', 'portrait');

        $filename = 'UlendoPay_Statement_' . $currency . '_' . $from->format('Ymd') . '_' . $to->format('Ymd') . '.pdf';

        return $pdf->download($filename);
    }
}
