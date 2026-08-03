<div>
    <header>
        <p class="text-sm font-semibold text-emerald-700">{{ $year->label }}</p>
        <h1 class="mt-1 text-3xl font-semibold text-balance text-slate-950">Kalender akademik mingguan</h1>
        <p class="mt-2 max-w-3xl text-pretty text-slate-600">Semester 1 memakai M1–M26 dan Semester 2 memakai M27–M52. Perubahan jenis minggu langsung menyusun ulang penempatan otomatis pada semester terkait.</p>
    </header>

    @if($notice)
        <div @class([
            'mt-5 rounded-xl px-4 py-3 text-sm ring-1',
            'bg-red-50 text-red-900 ring-red-200' => str_contains(strtolower($notice), 'gagal') || str_contains(strtolower($notice), 'tidak'),
            'bg-emerald-50 text-emerald-900 ring-emerald-200' => ! str_contains(strtolower($notice), 'gagal') && ! str_contains(strtolower($notice), 'tidak'),
        ]) role="status">{{ $notice }}</div>
    @endif

    <section class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Legenda kalender">
        <div class="rounded-xl bg-white p-4 ring-1 ring-emerald-200"><p class="font-semibold text-emerald-800">Minggu Efektif</p><p class="mt-1 text-sm text-slate-500">Dapat menerima materi.</p></div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-amber-200"><p class="font-semibold text-amber-800">Evaluasi</p><p class="mt-1 text-sm text-slate-500">Tidak menerima materi.</p></div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-slate-200"><p class="font-semibold text-slate-700">Libur</p><p class="mt-1 text-sm text-slate-500">Jadwal dikosongkan.</p></div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-red-200"><p class="font-semibold text-red-800">Hari Raya</p><p class="mt-1 text-sm text-slate-500">Jadwal dikosongkan.</p></div>
    </section>

    <section class="panel mt-6 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="sticky left-0 z-10 bg-slate-50">Pekan</th>
                        <th>Semester</th>
                        <th>Tanggal Mulai</th>
                        <th>Bulan</th>
                        <th>Jenis Minggu</th>
                        <th>Materi Terpasang</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($weeks as $week)
                        <tr wire:key="week-{{ $week->id }}">
                            <td class="sticky left-0 z-[1] bg-white font-mono font-semibold tabular-nums">M{{ $week->week_number }}</td>
                            <td><span class="status-badge">Semester {{ $week->semester }}</span></td>
                            <td>{{ $week->starts_on->translatedFormat('d F Y') }}</td>
                            <td>{{ $week->month_label }}</td>
                            <td>
                                <select wire:change="setType({{ $week->id }}, $event.target.value)" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3 text-sm" aria-label="Jenis minggu {{ $week->week_number }}">
                                    <option value="effective" @selected($week->type === 'effective')>Minggu Efektif</option>
                                    <option value="evaluation" @selected($week->type === 'evaluation')>Evaluasi</option>
                                    <option value="holiday" @selected($week->type === 'holiday')>Libur</option>
                                    <option value="religious_holiday" @selected($week->type === 'religious_holiday')>Hari Raya</option>
                                </select>
                            </td>
                            <td class="font-mono tabular-nums">{{ $week->placements_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
