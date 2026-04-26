<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; background: #fff; }

  .header { display: flex; justify-content: space-between; align-items: flex-start; padding: 24px 32px 20px; border-bottom: 3px solid #e85d04; }
  .brand { display: flex; align-items: center; gap: 10px; }
  .brand-name { font-size: 22px; font-weight: 800; color: #1a1a1a; letter-spacing: -0.03em; }
  .brand-name span { color: #e85d04; }
  .brand-tag { font-size: 10px; color: #888; margin-top: 2px; }
  .header-right { text-align: right; }
  .statement-title { font-size: 16px; font-weight: 700; color: #e85d04; text-transform: uppercase; letter-spacing: 0.08em; }
  .header-right p { font-size: 10px; color: #666; margin-top: 3px; }

  .info-bar { display: flex; justify-content: space-between; padding: 16px 32px; background: #f8f8f8; border-bottom: 1px solid #eee; }
  .info-block { }
  .info-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.1em; color: #999; font-weight: 600; }
  .info-value { font-size: 12px; font-weight: 700; color: #1a1a1a; margin-top: 2px; }

  .balances { display: flex; justify-content: space-between; padding: 14px 32px; border-bottom: 1px solid #eee; }
  .balance-block { text-align: center; padding: 10px 24px; border-radius: 8px; }
  .balance-block.opening { background: #f0f0f0; }
  .balance-block.closing { background: #fff4ed; border: 1px solid #e85d04; }
  .balance-block.current { background: #e85d04; }
  .balance-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.1em; color: #888; font-weight: 600; }
  .balance-block.current .balance-label { color: rgba(255,255,255,0.7); }
  .balance-amount { font-size: 16px; font-weight: 800; color: #1a1a1a; margin-top: 3px; letter-spacing: -0.02em; }
  .balance-block.closing .balance-amount { color: #e85d04; }
  .balance-block.current .balance-amount { color: #fff; }
  .balance-currency { font-size: 10px; font-weight: 600; color: #888; margin-left: 3px; }
  .balance-block.current .balance-currency { color: rgba(255,255,255,0.7); }

  .table-wrap { padding: 16px 32px 24px; }
  table { width: 100%; border-collapse: collapse; }
  thead tr { background: #1a1a1a; }
  thead th { padding: 9px 10px; text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 0.08em; color: #fff; font-weight: 600; }
  thead th.right { text-align: right; }
  tbody tr { border-bottom: 1px solid #f0f0f0; }
  tbody tr:nth-child(even) { background: #fafafa; }
  tbody tr:hover { background: #fff4ed; }
  tbody td { padding: 8px 10px; font-size: 10px; color: #333; vertical-align: middle; }
  tbody td.right { text-align: right; }
  tbody td.mono { font-family: DejaVu Sans Mono, monospace; font-size: 9px; color: #666; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 9px; font-weight: 700; letter-spacing: 0.05em; }
  .badge.credit { background: #dcfce7; color: #16a34a; }
  .badge.debit  { background: #fee2e2; color: #dc2626; }
  .amount.credit { color: #16a34a; font-weight: 700; }
  .amount.debit  { color: #dc2626; font-weight: 700; }
  .running { font-weight: 700; color: #1a1a1a; }

  .footer { padding: 12px 32px; border-top: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
  .footer-left { font-size: 9px; color: #aaa; }
  .footer-right { font-size: 9px; color: #aaa; text-align: right; }
  .confidential { font-size: 9px; color: #ccc; text-align: center; margin-top: 4px; }
</style>
</head>
<body>

  <!-- Header -->
  <div class="header">
    <div class="brand">
      <div>
        <div class="brand-name">Ulendo <span>Pay</span></div>
        <div class="brand-tag">Cross-Border Money Transfer · Sub-Saharan Africa</div>
      </div>
    </div>
    <div class="header-right">
      <div class="statement-title">Account Statement</div>
      <p>Generated: {{ $generated_at }}</p>
      <p>Period: {{ $from }} — {{ $to }}</p>
    </div>
  </div>

  <!-- Account Info -->
  <div class="info-bar">
    <div class="info-block">
      <div class="info-label">Account Holder</div>
      <div class="info-value">{{ $user->name }}</div>
    </div>
    <div class="info-block">
      <div class="info-label">Account Number</div>
      <div class="info-value" style="font-family: monospace;">{{ $account->code }}</div>
    </div>
    <div class="info-block">
      <div class="info-label">Currency</div>
      <div class="info-value">{{ $currency }}</div>
    </div>
    <div class="info-block">
      <div class="info-label">KYC Status</div>
      <div class="info-value">{{ strtoupper($user->kyc_status) }}</div>
    </div>
    <div class="info-block">
      <div class="info-label">Account Status</div>
      <div class="info-value">{{ strtoupper($user->status) }}</div>
    </div>
    <div class="info-block">
      <div class="info-label">Total Entries</div>
      <div class="info-value">{{ count($entries) }}</div>
    </div>
  </div>

  <!-- Balances -->
  <div class="balances">
    <div class="balance-block opening">
      <div class="balance-label">Opening Balance</div>
      <div class="balance-amount">{{ $opening_balance }} <span class="balance-currency">{{ $currency }}</span></div>
    </div>
    <div class="balance-block closing">
      <div class="balance-label">Closing Balance</div>
      <div class="balance-amount">{{ $closing_balance }} <span class="balance-currency">{{ $currency }}</span></div>
    </div>
    <div class="balance-block current">
      <div class="balance-label">Current Balance</div>
      <div class="balance-amount">{{ $current_balance }} <span class="balance-currency">{{ $currency }}</span></div>
    </div>
  </div>

  <!-- Transactions Table -->
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Date & Time</th>
          <th>Reference</th>
          <th>Description</th>
          <th>Type</th>
          <th class="right">Amount</th>
          <th class="right">Running Balance</th>
        </tr>
      </thead>
      <tbody>
        @forelse($entries as $entry)
        <tr>
          <td class="mono">{{ $entry["date"] }}</td>
          <td class="mono">{{ $entry["reference"] }}</td>
          <td>{{ $entry["description"] }}</td>
          <td><span class="badge {{ strtolower($entry["type"]) }}">{{ $entry["type"] }}</span></td>
          <td class="right amount {{ strtolower($entry["type"]) }}">
            {{ $entry["type"] === "CREDIT" ? "+" : "-" }} {{ $entry["amount"] }}
          </td>
          <td class="right running">{{ $entry["running_balance"] }}</td>
        </tr>
        @empty
        <tr>
          <td colspan="6" style="text-align:center; padding: 24px; color: #aaa;">No transactions found for this period.</td>
        </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <!-- Footer -->
  <div class="footer">
    <div class="footer-left">
      Ulendo Technologies Limited · Cross-Border Remittance Platform<br>
      This statement is system-generated and does not require a signature.
    </div>
    <div class="footer-right">
      {{ $user->name }} · {{ $account->code }} · {{ $currency }}<br>
      Statement period: {{ $from }} to {{ $to }}
    </div>
  </div>
  <div class="confidential">CONFIDENTIAL — This document is intended solely for the named account holder.</div>

</body>
</html>
