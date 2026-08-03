<div class="space-y-6">
    <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div><h1 class="text-3xl font-semibold tracking-tight text-slate-950">Riwayat revisi</h1><p class="mt-2 text-sm text-slate-600">Setiap simpan massal menjadi satu batch. Pemulihan tidak menghapus sejarah, tetapi membuat revisi baru.</p></div>
        <a href="{{ route('curriculum.index') }}" class="button-secondary">Pilih jenjang</a>
    </header>
    @if($notice)<div role="status" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 ring-1 ring-emerald-200">{{ $notice }}</div>@endif
    @if($errorMessage)<div role="alert" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-900 ring-1 ring-red-200">{{ $errorMessage }}</div>@endif
    <section class="panel p-4">
        <div class="grid gap-3 lg:grid-cols-[180px_180px_1fr]">
            <label class="block text-sm font-medium text-slate-700">Jenis baris<select wire:model.live="domain" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3"><option value="">Semua jenis</option><option value="ggb">GGB</option><option value="syllabus">Silabus</option><option value="link">Relasi</option><option value="rpp">RPP</option><option value="progress_target">Target progres</option><option value="matrix_column">Kolom matriks</option><option value="matrix_mapping">Pemetaan matriks</option><option value="month_focus">Fokus karakter</option></select></label>
            <label class="block text-sm font-medium text-slate-700">ID baris<input wire:model.live.debounce.350ms="rowId" type="number" min="1" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3" placeholder="Semua ID"></label>
            <label class="block text-sm font-medium text-slate-700">Alasan pemulihan<input wire:model="restoreReason" maxlength="500" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3" placeholder="Wajib diisi sebelum memulihkan batch"></label>
        </div>
    </section>
    <div class="space-y-3">
        @forelse($batches as $batch)
            <article class="panel overflow-hidden">
                <div class="flex flex-col gap-3 border-b border-slate-200 p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div><p class="font-mono text-xs text-slate-500">{{ $batch->uuid }}</p><h2 class="mt-1 font-semibold text-slate-950">{{ $batch->reason }}</h2><p class="mt-1 text-sm text-slate-500">{{ $batch->user?->name ?? 'Sistem' }} · {{ $batch->created_at->format('d M Y H:i') }} · {{ $batch->item_count }} baris · {{ $batch->action === 'restore' ? 'Pemulihan' : 'Edit' }}</p></div>
                    <button wire:click="restore({{ $batch->id }})" wire:confirm="Pulihkan seluruh perubahan dalam batch ini?" wire:loading.attr="disabled" class="button-secondary">Pulihkan revisi</button>
                </div>
                <details class="p-5"><summary class="cursor-pointer text-sm font-semibold text-emerald-800">Lihat perubahan</summary>
                    <div class="mt-4 space-y-3">
                        @foreach($batch->items as $item)
                            <div class="grid gap-2 rounded-xl bg-slate-50 p-3 text-sm ring-1 ring-slate-200 lg:grid-cols-[150px_1fr_1fr]"><div><span class="status status-neutral">{{ strtoupper($item->revisable_type) }} #{{ $item->revisable_id }}</span></div><div><p class="mb-1 text-xs font-semibold uppercase text-red-700">Sebelum</p><pre class="overflow-auto whitespace-pre-wrap font-mono text-xs">{{ json_encode($item->before_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div><div><p class="mb-1 text-xs font-semibold uppercase text-emerald-700">Sesudah</p><pre class="overflow-auto whitespace-pre-wrap font-mono text-xs">{{ json_encode($item->after_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div></div>
                        @endforeach
                    </div>
                </details>
            </article>
        @empty
            <div class="panel p-10 text-center text-slate-500">Belum ada revisi yang cocok dengan filter.</div>
        @endforelse
    </div>
    <div>{{ $batches->links() }}</div>
</div>
