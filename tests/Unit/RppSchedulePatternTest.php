<?php

namespace Tests\Unit;

use App\Services\RppSchedulePatternService;
use PHPUnit\Framework\TestCase;

class RppSchedulePatternTest extends TestCase
{
    public function test_allocation_text_is_interpreted_as_cadence_not_week_duration(): void
    {
        $patterns = new RppSchedulePatternService;

        $this->assertSame('weekly', $patterns->detect('4 pertemuan per minggu, ditempuh 12 bulan'));
        $this->assertSame('weekly', $patterns->detect('2 pertemuan x @30 menit • Ditempuh 6 bulan'));
        $this->assertSame('month_week_1_3', $patterns->detect('Minggu ke-1 dan minggu ke-3'));
        $this->assertSame('tentative', $patterns->detect('Tentatif (Sabtu/Minggu)'));
        $this->assertSame('unknown', $patterns->detect('Makna Al-Quran'));
    }
}
