<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Jenis non-efektif lama telah disalin menjadi calendar_events oleh
        // migrasi sebelumnya. CalendarWeek kembali menjadi struktur tanggal;
        // status per jenjang diselesaikan oleh AcademicCalendarService.
        DB::table('calendar_weeks')->where('is_effective', false)->update([
            'type' => 'effective',
            'label' => null,
            'is_effective' => true,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Tidak mengembalikan status lama karena provenance-nya tetap berada
        // pada calendar_events dan dapat memiliki cakupan jenjang berbeda.
    }
};
