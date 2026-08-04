<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $occurrences = [];
        DB::table('rpp_week_items')->whereNull('source_fingerprint')->orderBy('id')->get()
            ->each(function ($item) use (&$occurrences) {
                $fingerprint = $item->syllabus_item_id ? 'syllabus:'.$item->syllabus_item_id : 'legacy:'.$item->id;
                $key = $item->rpp_plan_id.':'.$item->calendar_week_id.':'.$fingerprint;
                $current = $occurrences[$key] ?? (int) DB::table('rpp_week_items')
                    ->where('rpp_plan_id', $item->rpp_plan_id)
                    ->where('calendar_week_id', $item->calendar_week_id)
                    ->where('source_fingerprint', $fingerprint)
                    ->max('occurrence_no');
                $occurrences[$key] = $current + 1;
                DB::table('rpp_week_items')->where('id', $item->id)->update([
                    'source_fingerprint' => $fingerprint,
                    'occurrence_no' => $occurrences[$key],
                ]);
            });

        Schema::table('rpp_week_items', function (Blueprint $table) {
            $table->string('source_fingerprint')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('rpp_week_items', function (Blueprint $table) {
            $table->string('source_fingerprint')->nullable()->change();
        });
    }
};
