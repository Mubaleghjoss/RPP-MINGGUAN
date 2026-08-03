@extends('layouts.app')
@section('title', 'Dashboard · Sistem RPP PPG')
@section('content')
<header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div><p class="text-sm font-semibold text-emerald-700">Tahun ajaran 2026/2027</p><h1 class="mt-1 text-3xl font-semibold text-balance text-slate-950">Kelengkapan kurikulum dalam satu pandangan</h1><p class="mt-2 max-w-3xl text-pretty text-slate-600">GGB adalah induk. Setiap jenjang memiliki RPP Semester 1 dan Semester 2 yang dapat ditelusuri sampai dokumen serta halaman sumber.</p></div>
    <div class="flex flex-wrap gap-2"><a href="{{ route('calendar.index') }}" class="button-secondary">Atur Kalender</a><a href="{{ route('exports.index') }}" class="button-primary">Preview & Ekspor</a></div>
</header>

<section class="panel mt-6 p-5" aria-labelledby="overview-explanation">
    <h2 id="overview-explanation" class="text-lg font-semibold text-slate-950">Mengapa Overview lama sulit dibaca?</h2>
    <p class="mt-2 max-w-4xl text-pretty text-slate-600">Kode seperti <span class="font-mono text-sm">a1</span>, <span class="font-mono text-sm">b4</span>, dan <span class="font-mono text-sm">c17–20</span> tidak memiliki legenda, halaman sumber, atau status turunan. Sistem baru memakai nama materi lengkap, ID terbaca, semester, status, dan sumber halaman.</p>
    <p class="mt-3 text-sm font-semibold text-emerald-800">Legenda ID: <span class="font-mono">8-SMP / FAQIH / 001</span> = jenjang / aspek / urutan.</p>
</section>

<section class="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-6" aria-label="Ringkasan">
    @foreach ([['Jenjang', $summary['levels']], ['Dokumen', $summary['documents']], ['Butir silabus', $summary['syllabus']], ['Temuan terbuka', $summary['open_findings']], ['Semester tervalidasi', $summary['validated'].' / 34'], ['Cakupan rata-rata', $summary['coverage'].'%']] as [$label, $value])
        <article class="panel p-5"><p class="text-sm text-slate-500">{{ $label }}</p><p class="metric-number mt-2">{{ $value }}</p></article>
    @endforeach
</section>

<section class="panel mt-6 overflow-hidden">
    <div class="flex flex-col gap-2 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="text-lg font-semibold text-slate-950">Status per jenjang dan semester</h2><p class="text-sm text-slate-500">Setiap jenjang memiliki dua plan mandiri.</p></div><a href="{{ route('audit.index') }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-900">Lihat audit lengkap</a></div>
    <div class="hidden overflow-x-auto md:block">
        <table class="data-table"><thead><tr><th>Jenjang</th><th>Semester</th><th>GGB</th><th>Silabus</th><th>Cakupan</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
            @foreach($levels as $level)
                @foreach([1, 2] as $semester)
                    @php($plan = $level->plans->firstWhere('semester', $semester))
                    <tr><td><p class="font-semibold text-slate-950">{{ $level->name }}</p><p class="text-xs text-slate-500">{{ $level->age }}</p></td><td class="font-semibold">Semester {{ $semester }}</td><td class="font-mono tabular-nums">{{ number_format($level->ggb_items_count) }}</td><td class="font-mono tabular-nums">{{ number_format($level->syllabus_items_count) }}</td><td class="font-mono tabular-nums">{{ number_format((float) ($plan?->coverage_percent ?? 0), 1) }}%</td><td><span class="status {{ $plan?->status === 'validated' ? 'status-success' : 'status-warning' }}">{{ $plan?->status === 'validated' ? 'Tervalidasi' : 'Draf' }}</span></td><td><div class="flex gap-3"><a class="font-semibold text-emerald-700" href="{{ route('planner.show', ['level' => $level, 'semester' => $semester]) }}">RPP</a><a class="font-semibold text-slate-700" href="{{ route('exports.index', ['level' => $level->id, 'semester' => $semester]) }}">Preview</a></div></td></tr>
                @endforeach
            @endforeach
        </tbody></table>
    </div>
    <div class="divide-y divide-slate-100 md:hidden">
        @foreach($levels as $level)
            <article class="p-4"><h3 class="font-semibold text-slate-950">{{ $level->name }}</h3><p class="mt-1 text-sm text-slate-500">{{ $level->ggb_items_count }} GGB · {{ $level->syllabus_items_count }} silabus</p><div class="mt-3 grid grid-cols-2 gap-2">@foreach([1,2] as $semester) @php($plan = $level->plans->firstWhere('semester', $semester)) <a href="{{ route('planner.show', ['level' => $level, 'semester' => $semester]) }}" class="min-h-16 rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200"><span class="text-xs font-semibold text-slate-500">Semester {{ $semester }}</span><span class="mt-1 block font-mono font-semibold tabular-nums text-slate-950">{{ number_format((float) ($plan?->coverage_percent ?? 0), 0) }}%</span></a> @endforeach</div></article>
        @endforeach
    </div>
</section>
@endsection
