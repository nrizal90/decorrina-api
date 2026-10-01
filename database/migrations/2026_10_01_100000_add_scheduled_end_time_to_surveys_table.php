<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CR-07: survey memakai jam mulai & jam selesai pilihan tamu, bukan sesi
 * Pagi/Siang. Kolom `session` dibiarkan (tidak diisi lagi) supaya riwayat
 * lama tetap terbaca.
 *
 * Baris lama diisi jam selesainya: sesi Pagi/Siang = akhir sesinya dulu
 * (11:00/15:00), jadwal bebas admin = +1 jam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            $table->time('scheduled_end_time')->nullable()->after('scheduled_time');
        });

        DB::table('surveys')->whereNull('scheduled_end_time')->orderBy('id')
            ->each(function ($row) {
                $end = match ($row->session) {
                    'Pagi' => '11:00',
                    'Siang' => '15:00',
                    default => Carbon::parse($row->scheduled_time)->addHour()->format('H:i'),
                };

                DB::table('surveys')->where('id', $row->id)->update(['scheduled_end_time' => $end]);
            });
    }

    public function down(): void
    {
        Schema::table('surveys', fn (Blueprint $table) => $table->dropColumn('scheduled_end_time'));
    }
};
