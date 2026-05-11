<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void {
        $accounts = DB::table('accounts')
            ->where('type', 'user_wallet')
            ->get();

        foreach ($accounts as $account) {
            do {
                $first = random_int(1, 9);
                $rest  = str_pad(random_int(1, 999999998), 9, '0', STR_PAD_LEFT);
                $code  = $first . $rest;
            } while (
                substr($code, -1) === '0' ||
                DB::table('accounts')->where('code', $code)->exists()
            );

            DB::table('accounts')
                ->where('id', $account->id)
                ->update(['code' => $code]);
        }
    }

    public function down(): void {}
};
