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
        @if($balancedPreview)
            <section class="mb-4 rounded-xl bg-emerald-950 p-4 text-white ring-1 ring-emerald-900" aria-labelledby="balanced-ggb-title">
                <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_auto] xl:items-start">
                    <div>
                        <p class="text-sm font-semibold text-emerald-200">Langkah 2 · Konfirmasi materi general</p>
                        <h3 id="balanced-ggb-title" class="mt-1 text-lg font-semibold">Bagi seimbang Semester 1 dan Semester 2</h3>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-emerald-50">Sistem mempertahankan urutan setiap kolom. Keputusan semester yang sudah dikonfirmasi manual tidak akan ditimpa.</p>
                        <dl class="mt-3 grid max-w-xl grid-cols-2 gap-3 sm:grid-cols-4">
                            <div><dt class="text-xs text-emerald-200">Belum dikonfirmasi</dt><dd class="mt-1 font-mono text-xl font-semibold">{{ $balancedPreview['unconfirmed'] }}</dd></div>
                            <div><dt class="text-xs text-emerald-200">Rencana Semester 1</dt><dd class="mt-1 font-mono text-xl font-semibold">{{ $balancedPreview['semester_1'] }}</dd></div>
                            <div><dt class="text-xs text-emerald-200">Rencana Semester 2</dt><dd class="mt-1 font-mono text-xl font-semibold">{{ $balancedPreview['semester_2'] }}</dd></div>
                            <div><dt class="text-xs text-emerald-200">Perlu saran kolom</dt><dd class="mt-1 font-mono text-xl font-semibold">{{ $balancedPreview['suggested_mapping_count'] + $balancedPreview['unresolved_mapping_count'] }}</dd></div>
                        </dl>
                    </div>
                    <button type="button" wire:click="balancePaudGgb" wire:loading.attr="disabled" wire:target="balancePaudGgb" class="button-primary bg-white text-emerald-900 hover:bg-emerald-50" @disabled($balancedPreview['unconfirmed'] === 0 || mb_strlen(trim($ggbReason)) < 5 || ($balancedPreview['suggested_mapping_count'] > 0 && ! $ggbConfirmSuggestedMappings) || $balancedPreview['unresolved_mapping_count'] > 0)><span wire:loading.remove wire:target="balancePaudGgb">Bagi Seimbang Sekarang</span><span wire:loading wire:target="balancePaudGgb">Membagi…</span></button>
                </div>

                @if($balancedPreview['suggested_mapping_count'] > 0)
                    <div class="mt-4 rounded-xl bg-white/10 p-3 ring-1 ring-white/20">
                        <p class="text-sm font-semibold">Saran kolom yang membutuhkan persetujuan Admin</p>
                        <ul class="mt-2 space-y-1 text-sm text-emerald-50">@foreach($balancedPreview['suggested_mappings'] as $suggestion)<li><strong>{{ $suggestion['code'] }}</strong> — {{ $suggestion['title'] }} → <strong>{{ $suggestion['column'] }}</strong></li>@endforeach</ul>
                        <label class="mt-3 flex min-h-11 items-start gap-3 rounded-lg bg-white/10 px-3 py-2"><input type="checkbox" wire:model.live="ggbConfirmSuggestedMappings" data-validation-field="ggbConfirmSuggestedMappings" class="mt-0.5 size-5 rounded border-emerald-200 text-emerald-700"><span class="text-sm font-semibold">Saya menyetujui {{ $balancedPreview['suggested_mapping_count'] }} saran kolom di atas.</span></label>
                        @error('ggbConfirmSuggestedMappings')<p class="mt-2 text-sm font-semibold text-red-200" role="alert">{{ $message }}</p>@enderror
                    </div>
                @endif
                @if($balancedPreview['unresolved_mapping_count'] > 0)
                    <p class="mt-4 rounded-xl bg-red-950/60 p-3 text-sm font-semibold text-red-100 ring-1 ring-red-300" role="alert">{{ $balancedPreview['unresolved_mapping_count'] }} materi belum mempunyai saran kolom. Gunakan Atur Kolom sebelum membagi semester.</p>
                @endif
            </section>
        @endif

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
                <select wire:model="ggbSemester" data-validation-field="ggbSemester" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"><option value="">Tidak diubah</option><option value="1">Semester 1</option><option value="2">Semester 2</option></select>
                @error('ggbSemester')<span class="text-xs font-semibold text-red-700" role="alert">{{ $message }}</span>@enderror
            </label>
            <label class="grid gap-1 text-sm font-medium text-slate-700">Kolom RPP
                <select wire:model="ggbColumnId" data-validation-field="ggbColumnId" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"><option value="">Tidak diubah</option>@foreach($columns as $column)<option value="{{ $column->id }}">{{ $column->aspect_label }} · {{ $column->label }}</option>@endforeach</select>
                @error('ggbColumnId')<span class="text-xs font-semibold text-red-700" role="alert">{{ $message }}</span>@enderror
            </label>
            <label class="grid gap-1 text-sm font-medium text-slate-700">Alasan tindakan <span class="text-red-700">(wajib)</span>
                <input type="text" wire:model.live.debounce.300ms="ggbReason" data-validation-field="ggbReason" aria-describedby="ggb-reason-help" @class(['min-h-11 rounded-xl border bg-white px-3', 'border-red-500 ring-1 ring-red-300' => $errors->has('ggbReason'), 'border-slate-300' => ! $errors->has('ggbReason')]) placeholder="Contoh: Penyusunan awal RPP PAUD">
                <span id="ggb-reason-help" class="text-xs text-slate-500">Minimal 5 karakter · {{ mb_strlen(trim($ggbReason)) }}/500 karakter.</span>
                @error('ggbReason')<span class="text-xs font-semibold text-red-700" role="alert">{{ $message }}</span>@enderror
            </label>
            <div class="flex flex-col gap-2 sm:flex-row">
                <button type="button" wire:click="confirmGgb" wire:loading.attr="disabled" wire:target="confirmGgb" class="button-secondary" @disabled(count($selectedGgb) === 0 || mb_strlen(trim($ggbReason)) < 5)><span wire:loading.remove wire:target="confirmGgb">Konfirmasi {{ count($selectedGgb) }} pilihan</span><span wire:loading wire:target="confirmGgb">Menyimpan…</span></button>
                <button type="button" wire:click="completeAnnualGgb" wire:loading.attr="disabled" wire:target="completeAnnualGgb" class="button-primary" @disabled(mb_strlen(trim($ggbReason)) < 5)><span wire:loading.remove wire:target="completeAnnualGgb">Lengkapi GGB 1 Tahun</span><span wire:loading wire:target="completeAnnualGgb">Menyusun dua semester…</span></button>
            </div>
        </div>
        @error('selectedGgb')<p class="mt-2 text-sm font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror
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
