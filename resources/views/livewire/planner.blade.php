<div>
    <header class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <a href="{{ route('dashboard') }}" class="text-sm font-semibold text-emerald-700">Dashboard</a>
            <h1 class="mt-2 text-3xl font-semibold text-balance text-slate-950">RPP mingguan {{ $level->name }}</h1>
            <p class="mt-2 max-w-3xl text-pretty text-slate-600">Draf otomatis mengikuti urutan silabus dan hanya menggunakan minggu efektif. Materi manual yang dikunci tidak berubah saat disusun ulang.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button wire:click="generateAll" wire:loading.attr="disabled" class="button-secondary">Susun Semua Kelas</button>
            <button wire:click="generate" wire:loading.attr="disabled" class="button-primary">Susun {{ $level->name }}</button>
            <button wire:click="validatePlan" wire:loading.attr="disabled" class="button-secondary">Validasi</button>
        </div>
    </header>

    @if($notice)<div class="mt-5 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900 ring-1 ring-emerald-200" role="status" aria-live="polite">{{ $notice }}</div>@endif
    @if($errorMessage)<div class="mt-5 rounded-xl bg-red-50 px-4 py-3 text-sm font-medium text-red-900 ring-1 ring-red-200" role="alert">{{ $errorMessage }}</div>@endif

    <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Ringkasan RPP">
        <article class="panel p-5"><p class="text-sm text-slate-500">Cakupan</p><p class="metric-number mt-2">{{ number_format((float)$plan->coverage_percent, 1) }}%</p></article>
        <a href="{{ route('planner.show', ['level' => $level, 'detail' => 'unplanned']) }}#planner-detail" class="panel group p-5 transition-[box-shadow,background-color] duration-150 ease-out hover:bg-red-50/40 hover:shadow-md {{ $detail === 'unplanned' ? 'ring-2 ring-red-600' : '' }}" @if($detail === 'unplanned') aria-current="true" @endif>
            <p class="text-sm font-medium text-slate-600">Belum dijadwalkan</p>
            <p class="metric-number mt-2 {{ $unplanned ? 'text-red-700' : 'text-emerald-800' }}">{{ $unplanned }}</p>
            <p class="mt-2 text-sm font-semibold text-red-700 group-hover:underline">Lihat daftar materi</p>
        </a>
        <a href="{{ route('planner.show', ['level' => $level, 'detail' => 'allocation']) }}#planner-detail" class="panel group p-5 transition-[box-shadow,background-color] duration-150 ease-out hover:bg-amber-50/50 hover:shadow-md {{ $detail === 'allocation' ? 'ring-2 ring-amber-600' : '' }}" @if($detail === 'allocation') aria-current="true" @endif>
            <p class="text-sm font-medium text-slate-600">Perlu alokasi</p>
            <p class="metric-number mt-2 text-amber-700">{{ $needsAllocation }}</p>
            <p class="mt-2 text-sm font-semibold text-amber-800 group-hover:underline">Lihat dan perbaiki</p>
        </a>
        <article class="panel p-5"><p class="text-sm text-slate-500">Status</p><p class="mt-3"><span class="status {{ $plan->status === 'validated' ? 'status-success' : 'status-warning' }}">{{ $plan->status === 'validated' ? 'Tervalidasi' : 'Draf' }}</span></p></article>
    </section>

    @if($detailItems)
        <section id="planner-detail" class="panel mt-6 scroll-mt-24 overflow-hidden" aria-labelledby="planner-detail-title">
            <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 id="planner-detail-title" class="text-lg font-semibold text-slate-950">{{ $detail === 'unplanned' ? 'Materi belum dijadwalkan' : 'Materi perlu alokasi' }}</h2>
                    <p class="mt-1 text-sm text-slate-600">{{ $detail === 'unplanned' ? 'Materi beralokasi dapat dipilih dan dijadwalkan secara massal. Materi tanpa alokasi harus diperbaiki dahulu.' : 'Lengkapi alokasi dan jumlah pertemuan melalui editor Silabus sebelum menyusun ulang RPP.' }}</p>
                </div>
                <button type="button" wire:click="closeDetail" class="button-secondary shrink-0">Tutup detail</button>
            </div>

            @if($detail === 'unplanned')
                <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" wire:click="selectVisibleSyllabus(@js($detailItems->getCollection()->where('needs_allocation', false)->pluck('id')->values()->all()))" class="button-secondary">Pilih yang dapat dijadwalkan</button>
                        <button type="button" wire:click="clearSyllabusSelection" class="button-secondary">Kosongkan pilihan</button>
                        <span class="status status-neutral"><span class="font-mono tabular-nums">{{ count($selectedSyllabus) }}</span>&nbsp;dipilih</span>
                    </div>
                </div>
            @endif

            <div class="divide-y divide-slate-100">
                @forelse($detailItems as $item)
                    <article class="grid gap-3 p-4 sm:grid-cols-[44px_minmax(0,1fr)_minmax(180px,280px)_auto] sm:items-center" wire:key="detail-syllabus-{{ $item->id }}">
                        <div>
                            @if($detail === 'unplanned')
                                <label class="flex size-11 items-center justify-center rounded-lg hover:bg-slate-100" title="{{ $item->needs_allocation ? 'Lengkapi alokasi sebelum menjadwalkan' : 'Pilih materi' }}">
                                    <input wire:model.live="selectedSyllabus" type="checkbox" value="{{ $item->id }}" class="size-5 rounded border-slate-300 text-emerald-700" @disabled($item->needs_allocation) aria-label="Pilih {{ $item->title }}">
                                </label>
                            @endif
                        </div>
                        <div class="min-w-0"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $item->category }}</p><h3 class="mt-1 font-semibold text-pretty text-slate-950">{{ $item->title }}</h3><p class="mt-1 font-mono text-xs text-slate-500">{{ $item->stable_code }}</p></div>
                        <div><p class="text-sm text-slate-700">{{ $item->allocation_text ?: 'Alokasi belum tersedia' }}</p><span class="status {{ $item->needs_allocation ? 'status-warning' : 'status-success' }} mt-2">{{ $item->needs_allocation ? 'Perlu Alokasi' : 'Siap Dijadwalkan' }}</span></div>
                        <a href="{{ route('curriculum.edit', ['level' => $level, 'tab' => 'syllabus', 'search' => $item->stable_code] + ($item->needs_allocation ? ['filter' => 'allocation'] : [])) }}" class="button-secondary">Edit Silabus</a>
                    </article>
                @empty
                    <div class="p-8 text-center"><p class="font-semibold text-slate-900">Tidak ada materi pada kategori ini.</p><p class="mt-1 text-sm text-slate-500">Ringkasan sudah tidak memiliki temuan terbuka.</p></div>
                @endforelse
            </div>
            <div class="border-t border-slate-200 px-4 py-3">{{ $detailItems->withQueryString()->links() }}</div>
        </section>
    @endif

    <section class="panel mt-6 p-4"><h2 class="font-semibold text-slate-950">Bantuan cepat</h2><div class="mt-3 flex flex-wrap gap-2"><button wire:click="fillEmpty" class="button-secondary">Isi Minggu Kosong</button><button wire:click="rebalance" class="button-secondary">Ratakan Beban</button><button wire:click="restartFromSyllabus" class="button-secondary">Ulangi dari Silabus</button></div><p class="mt-3 text-sm text-slate-500">Tindakan menyusun ulang bagian otomatis secara deterministik. Materi duplikat dan yang berstatus Perlu Alokasi tidak dipaksakan masuk; baris terkunci tetap aman.</p></section>

    <section class="mt-6 rounded-2xl bg-white/95 p-4 shadow-lg ring-1 ring-slate-200 backdrop-blur md:sticky md:top-3 md:z-20" aria-labelledby="bulk-title">
        <div class="flex flex-col gap-3 xl:flex-row xl:items-end">
            <div class="min-w-0 flex-1"><h2 id="bulk-title" class="font-semibold text-slate-950">Bulk action</h2><p class="mt-1 text-sm text-slate-500"><span class="font-mono font-semibold tabular-nums text-slate-800">{{ count($selectedPlacements) + count($selectedSyllabus) }}</span> materi dipilih. Semua tindakan diterapkan dalam satu transaksi.</p></div>
            <label class="block min-w-56 text-sm font-medium text-slate-700">Minggu tujuan
                <select wire:model="bulkWeekId" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3">
                    <option value="">Pilih minggu efektif</option>
                    @foreach($weeks->where('is_effective', true) as $target)<option value="{{ $target->id }}">M{{ $target->week_number }} · {{ $target->starts_on->translatedFormat('d M Y') }}</option>@endforeach
                </select>
            </label>
            <label class="block min-w-72 flex-1 text-sm font-medium text-slate-700">Alasan tindakan
                <input wire:model="bulkReason" maxlength="500" aria-describedby="bulk-reason-help" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3" placeholder="Contoh: penyesuaian hasil rapat kurikulum">
                <span id="bulk-reason-help" class="mt-1 block text-xs font-normal text-slate-500">Wajib diisi, minimal 5 karakter.</span>
            </label>
        </div>
        <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" wire:click="selectAllPlacements" class="button-secondary">Pilih Semua Terjadwal</button>
            <button type="button" wire:click="clearPlacementSelection" class="button-secondary">Kosongkan Terjadwal</button>
            <button type="button" wire:click="applyPlacementBulk('move')" wire:loading.attr="disabled" @disabled(count($selectedPlacements) === 0) class="button-primary">Pindahkan ke Minggu</button>
            <button type="button" wire:click="applyPlacementBulk('lock')" wire:loading.attr="disabled" @disabled(count($selectedPlacements) === 0) class="button-secondary">Kunci</button>
            <button type="button" wire:click="applyPlacementBulk('unlock')" wire:loading.attr="disabled" @disabled(count($selectedPlacements) === 0) class="button-secondary">Buka Kunci</button>
            <button type="button" wire:click="scheduleSelected" wire:loading.attr="disabled" @disabled(count($selectedSyllabus) === 0) class="button-primary">Jadwalkan Pilihan Baru</button>
            <span wire:loading.delay class="self-center text-sm font-medium text-slate-600" role="status" aria-live="polite">Memproses tindakan…</span>
        </div>
    </section>

    <section class="mt-6 space-y-3" aria-label="Rencana per minggu">
        @foreach($weeks as $week)
            @php($weekItems = collect($itemsByWeek->get($week->id, [])))
            <article class="panel overflow-hidden {{ $week->is_effective ? '' : 'bg-slate-100' }}" wire:key="planner-week-{{ $week->id }}">
                <div class="flex flex-col gap-2 border-b border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="font-semibold text-slate-950">M{{ $week->week_number }} · {{ $week->starts_on->translatedFormat('d F Y') }}</h2><p class="text-sm text-slate-500">{{ $week->month_label }}</p></div><span class="status {{ $week->is_effective ? 'status-success' : 'status-neutral' }}">{{ $week->is_effective ? 'Minggu Efektif' : str_replace('_', ' ', ucfirst($week->type)) }}</span></div>
                @if($weekItems->isEmpty())
                    <div class="p-4"><p class="text-sm text-slate-500">{{ $week->is_effective ? 'Belum ada materi pada minggu ini.' : 'Minggu ini tidak menerima materi.' }}</p></div>
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach($weekItems as $item)
                            <div class="grid gap-3 p-4 lg:grid-cols-[44px_180px_1fr_190px_110px] lg:items-center" wire:key="placement-{{ $item->id }}">
                                <label class="flex size-11 items-center justify-center rounded-lg hover:bg-slate-100"><input wire:model.live="selectedPlacements" type="checkbox" value="{{ $item->id }}" class="size-5 rounded border-slate-300 text-emerald-700" aria-label="Pilih {{ $item->content }}"></label>
                                <div><p class="font-semibold text-slate-900">{{ $item->strand }}</p><span class="status {{ $item->is_locked ? 'status-warning' : 'status-neutral' }} mt-1">{{ $item->is_locked ? 'Terkunci' : 'Otomatis' }}</span></div>
                                <p class="text-sm text-pretty text-slate-700">{{ $item->content }}</p>
                                <select wire:change="movePlacement({{ $item->id }}, $event.target.value)" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3" aria-label="Pindahkan {{ $item->content }}"><option value="">Pindahkan ke...</option>@foreach($weeks->where('is_effective', true) as $target)<option value="{{ $target->id }}" @selected($target->id === $week->id)>M{{ $target->week_number }} · {{ $target->starts_on->format('d/m') }}</option>@endforeach</select>
                                <button wire:click="toggleLock({{ $item->id }})" wire:loading.attr="disabled" class="button-secondary">{{ $item->is_locked ? 'Buka Kunci' : 'Kunci' }}</button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </article>
        @endforeach
    </section>
</div>
