<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReportSubscriptions extends Command
{
    protected $signature = 'report:subscriptions
        {--date= : Tek gün (YYYY-MM-DD)}
        {--from= : Başlangıç (dahil)}
        {--to=   : Bitiş (dahil)}';

    protected $description = 'Gün bazında yeni, biten ve yenilenen abonelik sayılarını raporlar';

    public function handle(): int
    {
        //  Tarih aralığı
        $date = $this->option('date');
        $from = $this->option('from');
        $to = $this->option('to');

        if ($date) {
            $from = $to = $date;
        }
        if (! $from) {
            $from = Carbon::today()->subDays(7)->toDateString();
        }
        if (! $to) {
            $to = Carbon::today()->toDateString();
        }

        //  Sorgu
        $rows = DB::table('subscriptions')
            ->selectRaw("
                DATE(created_at) AS day,

                COUNT(*) AS new_count,

                SUM(CASE WHEN status IN ('cancelled','expired')
                          AND DATE(updated_at) = DATE(created_at)
                    THEN 1 ELSE 0 END) AS ended_same_day,

                SUM(CASE WHEN status IN ('cancelled','expired')
                          AND DATE(updated_at) BETWEEN ? AND ?
                    THEN 1 ELSE 0 END) AS ended_total,

                SUM(CASE WHEN status = 'active'
                          AND DATE(updated_at) BETWEEN ? AND ?
                          AND DATE(updated_at) <> DATE(created_at)
                    THEN 1 ELSE 0 END) AS renewed_count
            ", [$from, $to, $from, $to])
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        //  Tablo çıktısı
        $this->table(
            ['Day', 'New', 'Ended (Same Day)', 'Ended (Total)', 'Renewed'],
            $rows->map(fn ($r) => [
                $r->day,
                $r->new_count,
                $r->ended_same_day,
                $r->ended_total,
                $r->renewed_count,
            ])->toArray()
        );

        return self::SUCCESS;
    }
}
