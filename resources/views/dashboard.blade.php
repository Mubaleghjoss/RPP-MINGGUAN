@extends('layouts.app')
@section('title', 'Dashboard · Sistem RPP PPG')
@section('content')
<header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div>
        <p class="text-sm font-semibold text-emerald-700">Tahun ajaran 2026/2027</p>
        <h1 class="mt-1 text-3xl font-semibold text-balance text-slate-950">Kelengkapan kurikulum dalam satu pandangan</h1>
        <p class="mt-2 max-w-3xl text-pretty text-slate-600">GGB adalah induk. Setiap turunan silabus dan penempatan RPP dapat ditelusuri sampai dokumen serta halaman sumber.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('calendar.index') }}" class="button-secondary">Atur Kalender</a>
        <a href="{{ route('exports.index') }}" class="button-primary">Ekspor Excel</a>
    </div>
</header>

<section class="panel mt-6 p-5" aria-labelledby="overview-explanation">
    <h2 id="overview-explanation" class="text-lg font-semibold text-slate-950">Mengapa Overview lama sulit dibaca?</h2>
    <p class="mt-2 max-w-4xl text-pretty text-slate-600">Kode seperti <span class="font-mono text-sm">a1</span>, <span class="font-mono text-sm">b4</span>, dan <span class="font-mono text-sm">c17–20</span> tidak memiliki legenda, halaman sumber, atau tanda apakah materi sudah turun ke silabus dan RPP. Overview baru menampilkan nama materi lengkap, ID terbaca, status GGB ke silabus, minggu RPP, duplikasi, kebutuhan alokasi, serta dokumen dan halaman sumber.</p>
    <p class="mt-3 text-sm font-semibold text-emerald-800">Legenda ID: <span class="font-mono">8-SMP / FAQIH / 001</span> = jenjang / aspek / urutan.</p>
</section>

<section class="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-6" aria-label="Ringkasan">
    @foreach ([['Jenjang', $summary['levels']], ['Dokumen', $summary['documents']], ['Butir silabus', $summary['syllabus']], ['Temuan terbuka', $summary['open_findings']], ['RPP tervalidasi', $summary['validated']], ['Cakupan rata-rata', $summary['coverage'].'%']] as [$label, $value])
        <article class="panel p-5"><p class="text-sm text-slate-500">{{ $label }}</p><p class="metric-number mt-2">{{ $value }}</p></article>
    @endforeach
</section>

<section class="panel mt-6 overflow-hidden">
    <div class="flex flex-col gap-2 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
        <div><h2 class="text-lg font-semibold text-slate-950">Status per jenjang</h2><p class="text-sm text-slate-500">Pilih jenjang untuk melihat sumber dan menyusun RPP.</p></div>
        <a href="{{ route('audit.index') }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-900">Lihat audit lengkap</a>
    </div>
    <div class="hidden overflow-x-auto md:block">
        <table class="data-table">
            <thead><tr><th>Jenjang</th><th>GGB</th><th>Silabus</th><th>Cakupan RPP</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
            @foreach ($levels as $level)
                @php($plan = $level->plans->first())
                <tr><td><p class="font-semibold text-slate-950">{{ $level->name }}</p><p class="text-xs text-slate-500">{{ $level->age }}</p></td><td class="font-mono tabular-nums">{{ number_format($level->ggb_items_count) }}</td><td class="font-mono tabular-nums">{{ number_format($level->syllabus_items_count) }}</td><td class="font-mono tabular-nums">{{ number_format((float) ($plan?->coverage_percent ?? 0), 1) }}%</td><td><span class="status {{ $plan?->status === 'validated' ? 'status-success' : 'status-warning' }}">{{ $plan?->status === 'validated' ? 'Tervalidasi' : 'Draf' }}</span></td><td><div class="flex gap-3"><a class="font-semibold text-emerald-700" href="{{ route('curriculum.show', $level) }}">Materi</a><a class="font-semibold text-slate-700" href="{{ route('planner.show', $level) }}">RPP</a></div></td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="divide-y divide-slate-100 md:hidden">
        @foreach ($levels as $level)
            @php($plan = $level->plans->first())
            <article class="p-4"><div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold text-slate-950">{{ $level->name }}</h3><p class="mt-1 text-sm text-slate-500">{{ $level->ggb_items_count }} GGB · {{ $level->syllabus_items_count }} silabus</p></div><span class="status {{ $plan?->status === 'validated' ? 'status-success' : 'status-warning' }}">{{ number_format((float) ($plan?->coverage_percent ?? 0), 0) }}%</span></div><div class="mt-3 flex gap-4 text-sm font-semibold"><a class="text-emerald-700" href="{{ route('curriculum.show', $level) }}">Lihat Materi</a><a class="text-slate-700" href="{{ route('planner.show', $level) }}">Susun RPP</a></div></article>
        @endforeach
    </div>
</section>
@endsection
