@extends('layouts.app')
@section('title', $level->name.' · Master Kurikulum')
@section('content')
<header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div><a href="{{ route('curriculum.index') }}" class="text-sm font-semibold text-emerald-700">Kembali ke semua jenjang</a><h1 class="mt-2 text-3xl font-semibold text-balance text-slate-950">{{ $level->name }}</h1><p class="mt-2 text-pretty text-slate-600">Telusuri materi lengkap beserta dokumen dan halaman sumbernya.</p></div>
    <div class="flex flex-wrap gap-2"><a href="{{ route('curriculum.edit', $level) }}" class="button-secondary">Edit seperti Excel</a><a href="{{ route('planner.show', $level) }}" class="button-primary">Buka Penyusun RPP</a></div>
</header>

<section class="mt-6 grid gap-3 sm:grid-cols-2">
@foreach ($level->documents as $document)
    <article class="panel p-4"><div class="flex items-start justify-between gap-3"><div><p class="text-xs font-semibold uppercase text-slate-500">{{ $document->type }}</p><h2 class="mt-1 font-semibold text-pretty text-slate-950">{{ $document->title }}</h2><p class="mt-1 text-sm text-slate-500">{{ $document->page_count }} halaman · SHA {{ substr($document->sha256, 0, 10) }}</p></div>@if($document->is_available)<a href="{{ route('documents.show', $document) }}" target="_blank" class="button-secondary">Buka PDF</a>@else<span class="status status-neutral text-center">PDF hanya tersedia lokal</span>@endif</div></article>
@endforeach
</section>

<section class="panel mt-6 overflow-hidden">
    <div class="border-b border-slate-200 px-5 py-4"><h2 class="text-lg font-semibold text-slate-950">GGB sebagai induk</h2><p class="text-sm text-slate-500">Semua baris bermakna dipertahankan, termasuk judul hierarki dan butir detail.</p></div>
    <div class="overflow-x-auto"><table class="data-table"><thead><tr><th>ID terbaca</th><th>Aspek</th><th>Subaspek</th><th>Materi GGB</th><th>Halaman</th></tr></thead><tbody>@foreach($ggbItems as $item)<tr><td class="max-w-52 font-mono text-xs">{{ $item->stable_code }}</td><td>{{ $item->aspect }}</td><td>{{ $item->subaspect }}</td><td class="min-w-80"><p class="text-pretty text-slate-900">{{ $item->title }}</p></td><td class="font-mono tabular-nums">{{ $item->source_page }}</td></tr>@endforeach</tbody></table></div>
    <div class="border-t border-slate-200 px-4 py-3">{{ $ggbItems->withQueryString()->links() }}</div>
</section>

<section class="panel mt-6 overflow-hidden">
    <div class="border-b border-slate-200 px-5 py-4"><h2 class="text-lg font-semibold text-slate-950">Turunan silabus</h2><p class="text-sm text-slate-500">Nama lengkap menggantikan kode singkat yang sebelumnya tidak memiliki legenda.</p></div>
    <div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Kategori</th><th>Materi</th><th>Alokasi</th><th>Hubungan GGB</th><th>Halaman</th></tr></thead><tbody>@foreach($syllabusItems as $item)<tr><td class="min-w-44 font-semibold text-slate-900">{{ $item->category }}</td><td class="min-w-80">{{ $item->title }}</td><td class="min-w-64 text-pretty">{{ $item->allocation_text ?: 'Perlu alokasi' }}</td><td class="min-w-72">@foreach($item->ggbItems as $ggb)<div class="mb-2"><span class="status {{ $ggb->pivot->status === 'sesuai' ? 'status-success' : ($ggb->pivot->status === 'sebagian' ? 'status-warning' : 'status-danger') }}">{{ str_replace('_', ' ', ucfirst($ggb->pivot->status)) }}</span><p class="mt-1 text-xs text-slate-500">{{ $ggb->title }} · hlm. {{ $ggb->source_page }}</p></div>@endforeach</td><td class="font-mono tabular-nums">{{ $item->source_page }}</td></tr>@endforeach</tbody></table></div>
    <div class="border-t border-slate-200 px-4 py-3">{{ $syllabusItems->withQueryString()->links() }}</div>
</section>
@endsection
