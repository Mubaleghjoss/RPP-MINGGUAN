@extends('layouts.app')
@section('title', 'Master Kurikulum')
@section('content')
<header><p class="text-sm font-semibold text-emerald-700">Master acuan tetap</p><h1 class="mt-1 text-3xl font-semibold text-balance text-slate-950">GGB dan silabus per jenjang</h1><p class="mt-2 max-w-3xl text-pretty text-slate-600">Dokumen asli tidak diubah. Data berikut dipakai sebagai sumber audit dan penyusunan RPP.</p></header>
<section class="mt-7 grid gap-4 lg:grid-cols-2">
@foreach ($levels as $level)
    <article class="panel p-5"><div class="flex items-start justify-between gap-4"><div><p class="text-xs font-semibold text-emerald-700">{{ $level->stage }}</p><h2 class="mt-1 text-xl font-semibold text-slate-950">{{ $level->name }}</h2><p class="text-sm text-slate-500">{{ $level->age }}</p></div><span class="status status-neutral">{{ $level->documents->count() }}/2 sumber</span></div><dl class="mt-5 grid grid-cols-2 gap-4 border-t border-slate-100 pt-4"><div><dt class="text-xs text-slate-500">Item GGB</dt><dd class="mt-1 font-mono text-lg font-semibold tabular-nums">{{ number_format($level->ggb_items_count) }}</dd></div><div><dt class="text-xs text-slate-500">Item Silabus</dt><dd class="mt-1 font-mono text-lg font-semibold tabular-nums">{{ number_format($level->syllabus_items_count) }}</dd></div></dl><div class="mt-5 flex flex-wrap gap-4 text-sm font-semibold"><a href="{{ route('curriculum.show', $level) }}" class="text-emerald-700">Buka Materi</a><a href="{{ route('curriculum.edit', $level) }}" class="text-emerald-700">Edit Tabel</a><a href="{{ route('planner.show', $level) }}" class="text-slate-700">Buka RPP</a></div></article>
@endforeach
</section>
@endsection
