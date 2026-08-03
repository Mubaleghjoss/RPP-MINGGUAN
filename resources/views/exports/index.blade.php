@extends('layouts.app')
@section('title', 'Ekspor Excel')
@section('content')
<header><p class="text-sm font-semibold text-emerald-700">Workbook terverifikasi</p><h1 class="mt-1 text-3xl font-semibold text-balance text-slate-950">Ekspor RPP 2026/2027</h1><p class="mt-2 max-w-3xl text-pretty text-slate-600">File asli tidak ditimpa. Ekspor baru berisi Overview audit dan 17 sheet RPP global mingguan.</p></header>
<section class="mt-7 grid gap-4 sm:grid-cols-3"><article class="panel p-5"><p class="text-sm text-slate-500">Jenjang</p><p class="metric-number mt-2">{{ $levelCount }}</p></article><article class="panel p-5"><p class="text-sm text-slate-500">RPP tervalidasi</p><p class="metric-number mt-2">{{ $validatedCount }}</p></article><article class="panel p-5"><p class="text-sm text-slate-500">Cakupan rata-rata</p><p class="metric-number mt-2">{{ number_format($averageCoverage, 1) }}%</p></article></section>
<section class="panel mt-6 p-6"><h2 class="text-xl font-semibold text-slate-950">RPP_26_27_TangKot_Terverifikasi.xlsx</h2><p class="mt-2 max-w-2xl text-pretty text-slate-600">Rentang halaman dan ayat ditulis sebagai teks agar Excel tidak mengubahnya menjadi tanggal. Setiap materi memiliki halaman sumber.</p><div class="mt-5"><a href="{{ route('exports.workbook') }}" class="button-primary">Buat dan Unduh Excel</a></div></section>
@endsection
