<section id="matrix-gaps" class="panel scroll-mt-24 overflow-hidden" aria-labelledby="matrix-gaps-title">
    <div class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
        <div>
            <p class="text-sm font-semibold text-amber-700">Diagnostik matriks</p>
            <h2 id="matrix-gaps-title" class="mt-1 text-xl font-semibold text-slate-950">{{ $matrixStats['missing'] }} sel materi belum terisi</h2>
            <p class="mt-1 text-sm text-slate-600">Minggu non-efektif, kolom identitas, dan Paraf Pengajar tidak dihitung.</p>
        </div>
        <div class="flex flex-wrap gap-2"><button type="button" wire:click="generate" class="button-primary">Susun Otomatis</button><button type="button" wire:click="showDetail('')" class="button-secondary">Tutup detail</button></div>
    </div>
    <div class="divide-y divide-slate-200">
        @forelse($matrixStats['gaps'] as $gap)
            <article class="grid gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:p-5">
                <div><p class="font-semibold text-slate-950">M{{ $gap['week_number'] }} · {{ $gap['starts_on']->format('d/m/Y') }} · {{ $gap['column'] }}</p><p class="mt-1 text-sm text-slate-600">{{ $gap['reason'] }}</p></div>
                <button type="button" wire:click="openMaterialPicker({{ $gap['week_id'] }}, {{ $gap['column_id'] }})" class="button-secondary">Isi Sel Ini</button>
            </article>
        @empty
            <div class="p-8 text-center"><p class="font-semibold text-emerald-800">Semua sel materi pada minggu efektif sudah terisi.</p><p class="mt-1 text-sm text-slate-600">Kelengkapan matriks mencapai 100%.</p></div>
        @endforelse
    </div>
</section>
