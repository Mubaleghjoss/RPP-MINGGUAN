<section id="ggb-detail" class="panel scroll-mt-4 overflow-hidden" aria-labelledby="ggb-detail-title">
    <div class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-start sm:justify-between sm:p-5">
        <div>
            <p class="text-sm font-semibold text-emerald-700">Target tahunan {{ $ggbCoverage['used'] }}/{{ $ggbCoverage['total'] }}</p>
            <h2 id="ggb-detail-title" class="mt-1 text-xl font-semibold text-slate-950">Daftar materi GGB {{ $plan->level->name }}</h2>
            <p class="mt-1 max-w-3xl text-sm text-slate-600">Setiap butir rinci cukup muncul sekali di Semester 1 atau 2. Materi general dan pemetaan ambigu menunggu keputusan Admin.</p>
        </div>
        <button type="button" wire:click="showDetail('')" class="button-secondary">Tutup daftar</button>
    </div>

    <div class="border-b border-slate-200 bg-slate-50/70 p-4 sm:p-5">
        <div class="grid gap-3 lg:grid-cols-[minmax(240px,1fr)_220px_auto] lg:items-end">
            <label class="grid gap-1 text-sm font-medium text-slate-700">Cari kode atau judul
                <input type="search" wire:model.live.debounce.250ms="ggbSearch" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3" placeholder="Contoh: Adab 01 atau salam">
            </label>
            <label class="grid gap-1 text-sm font-medium text-slate-700">Status
                <select wire:model.live="ggbStatus" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3">
                    <option value="all">Semua materi</option><option value="used">Sudah masuk</option><option value="ready">Siap dijadwalkan</option><option value="semester">Perlu semester</option><option value="mapping">Perlu pemetaan kolom</option><option value="conflict">Konflik ganda</option>
                </select>
            </label>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="selectVisibleGgb({{ Js::from($ggbItems?->pluck('id')->all() ?? []) }})" class="button-secondary">Pilih halaman ini</button>
                <button type="button" wire:click="clearGgbSelection" class="button-secondary">Kosongkan</button>
            </div>
        </div>

        <div class="mt-4 grid gap-3 rounded-xl bg-white p-4 ring-1 ring-slate-200 xl:grid-cols-[140px_minmax(220px,1fr)_minmax(260px,1.4fr)_auto] xl:items-end">
            <label class="grid gap-1 text-sm font-medium text-slate-700">Semester
                <select wire:model="ggbSemester" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"><option value="">Tidak diubah</option><option value="1">Semester 1</option><option value="2">Semester 2</option></select>
            </label>
            <label class="grid gap-1 text-sm font-medium text-slate-700">Kolom RPP
                <select wire:model="ggbColumnId" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"><option value="">Tidak diubah</option>@foreach($columns as $column)<option value="{{ $column->id }}">{{ $column->aspect_label }} · {{ $column->label }}</option>@endforeach</select>
            </label>
            <label class="grid gap-1 text-sm font-medium text-slate-700">Alasan konfirmasi
                <input type="text" wire:model="ggbReason" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3" placeholder="Minimal 5 karakter">
            </label>
            <div class="flex flex-col gap-2 sm:flex-row">
                <button type="button" wire:click="confirmGgb" wire:loading.attr="disabled" wire:target="confirmGgb" class="button-secondary" @disabled(count($selectedGgb) === 0)><span wire:loading.remove wire:target="confirmGgb">Konfirmasi {{ count($selectedGgb) }} pilihan</span><span wire:loading wire:target="confirmGgb">Menyimpan…</span></button>
                <button type="button" wire:click="completeAnnualGgb" wire:loading.attr="disabled" wire:target="completeAnnualGgb" class="button-primary"><span wire:loading.remove wire:target="completeAnnualGgb">Lengkapi GGB 1 Tahun</span><span wire:loading wire:target="completeAnnualGgb">Menyusun dua semester…</span></button>
            </div>
        </div>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-slate-600"><strong class="font-mono text-slate-950">{{ count($selectedGgb) }}</strong> dipilih. Materi siap diproses lebih dahulu; antrean konfirmasi tetap terlihat.</p>
            <button type="button" wire:click="validateAnnualGgb" wire:loading.attr="disabled" wire:target="validateAnnualGgb" class="button-secondary"><span wire:loading.remove wire:target="validateAnnualGgb">Validasi GGB 1 Tahun</span><span wire:loading wire:target="validateAnnualGgb">Memvalidasi…</span></button>
        </div>
    </div>

    <div class="hidden overflow-x-auto lg:block">
        <table class="spreadsheet-table min-w-[1180px]">
            <thead><tr><th class="w-14">Pilih</th><th>Kode / Materi</th><th>Aspek / Subaspek</th><th>Semester</th><th>Kolom RPP</th><th>Status</th><th>Sumber</th><th>Pemakaian</th></tr></thead>
            <tbody>
                @forelse($ggbItems ?? [] as $material)
                    @php($annualPlacements = $material->placements->filter(fn($placement) => (int) $placement->plan?->academic_year_id === (int) $plan->academic_year_id))
                    @php($needsSemester = $material->source_semester_scope === 'general' && ! $material->semester_confirmed)
                    @php($needsMapping = ! $material->rpp_matrix_column_id || $material->mapping_status !== 'mapped')
                    @php($materialStatus = $annualPlacements->isNotEmpty() ? 'used' : (($needsSemester && $needsMapping) ? 'conflict' : ($needsSemester ? 'semester' : ($needsMapping ? 'mapping' : 'ready'))))
                    <tr wire:key="ggb-row-{{ $material->id }}">
                        <td class="text-center"><input type="checkbox" wire:model.live="selectedGgb" value="{{ $material->id }}" class="size-5 rounded border-slate-300 text-emerald-700" aria-label="Pilih {{ $material->display_code }}"></td>
                        <td><strong class="font-mono text-xs text-emerald-800">{{ $material->display_code }}</strong><span class="mt-1 block font-semibold text-slate-950">{{ $material->title }}</span></td>
                        <td>{{ $material->ggbItem?->aspect }}<span class="mt-1 block text-xs text-slate-500">{{ $material->ggbItem?->subaspect }}</span></td>
                        <td>{{ $material->semester_confirmed ? 'Semester '.$material->semester_scope : 'Perlu konfirmasi' }}<span class="mt-1 block text-xs text-slate-500">Sumber: {{ $material->source_semester_scope === 'general' ? 'General' : 'Semester '.$material->source_semester_scope }}</span></td>
                        <td>{{ $material->matrixColumn?->label ?: 'Belum dipetakan' }}</td>
                        <td>@if($materialStatus === 'used')<span class="status status-success">Sudah Masuk</span>@elseif($materialStatus === 'ready')<span class="status status-success">Siap Dijadwalkan</span>@elseif($materialStatus === 'semester')<span class="status status-warning">Perlu Semester</span>@elseif($materialStatus === 'conflict')<span class="status status-danger">Konflik</span>@else<span class="status status-danger">Perlu Pemetaan</span>@endif</td>
                        <td class="text-xs">{{ $material->ggbItem?->document?->title }}<span class="mt-1 block">Hlm. {{ $material->ggbItem?->source_page }} · {{ $material->ggbItem?->stable_code }}</span></td>
                        <td class="text-xs">{{ $annualPlacements->map(fn($placement) => 'S'.$placement->plan?->semester.' M'.$placement->week?->week_number)->unique()->implode(', ') ?: 'Belum digunakan' }}</td>
                    </tr>
                @empty<tr><td colspan="8" class="p-8 text-center text-slate-600">Tidak ada materi yang cocok dengan filter.</td></tr>@endforelse
            </tbody>
        </table>
    </div>

    <div class="divide-y divide-slate-200 lg:hidden">
        @foreach($ggbItems ?? [] as $material)
            <label class="flex gap-3 p-4"><span class="flex size-11 shrink-0 items-center justify-center"><input type="checkbox" wire:model.live="selectedGgb" value="{{ $material->id }}" class="size-5 rounded border-slate-300 text-emerald-700"></span><span class="min-w-0"><strong class="font-mono text-xs text-emerald-800">{{ $material->display_code }}</strong><span class="mt-1 block font-semibold text-slate-950">{{ $material->title }}</span><span class="mt-2 block text-xs leading-5 text-slate-600">{{ $material->matrixColumn?->label ?: 'Belum dipetakan' }} · {{ $material->semester_confirmed ? 'Semester '.$material->semester_scope : 'Perlu semester' }}<br>{{ $material->ggbItem?->stable_code }} · hlm. {{ $material->ggbItem?->source_page }}</span></span></label>
        @endforeach
    </div>
    @if($ggbItems)<div class="border-t border-slate-200 p-4">{{ $ggbItems->links() }}</div>@endif
</section>
