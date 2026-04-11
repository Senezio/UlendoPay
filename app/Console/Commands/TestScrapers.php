<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestScrapers extends Command
{
    protected $signature   = 'scrapers:test';
    protected $description = 'Test connectivity to all central bank websites';

    public function handle(): int
    {
        $sites = [
            'SARB' => 'https://www.resbank.co.za/en/home/what-we-do/financial-markets/exchange-rates',
            'CBK'  => 'https://www.centralbank.go.ke/rates/forex-exchange-rates/',
            'BOT'  => 'https://www.bot.go.tz/exchrate/BOT_ExchangeRate.asp',
            'BOZ'  => 'https://www.boz.zm/exchange-rates.htm',
            'RBZ'  => 'https://www.rbz.co.zw/index.php/research/markets/exchange-rates',
            'BOB'  => 'https://www.bankofbotswana.bw/exchange-rates',
            'BM'   => 'https://www.bancomoc.mz/fm_pgTabCambio.aspx',
            'BNA'  => 'https://www.bna.ao/Conteudos/Artigos/lista_artigos_medias.aspx?idarea=15&idc=161',
        ];

        foreach ($sites as $name => $url) {
            try {
                $r = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36'])
                    ->timeout(15)
                    ->get($url);

                $this->line("{$name}: HTTP {$r->status()} — " . strlen($r->body()) . " bytes");

                // Show first 500 chars so we can see the structure
                $preview = substr(strip_tags($r->body()), 0, 300);
                $preview = preg_replace('/\s+/', ' ', $preview);
                $this->line("  Preview: {$preview}");
                $this->newLine();

            } catch (\Throwable $e) {
                $this->error("{$name}: FAILED — {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
