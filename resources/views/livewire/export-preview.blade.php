<div x-data="spreadsheetGrid('rpp')" data-grid-domain="rpp" x-on:keydown.window="handleShortcut($event)" class="preview-grid space-y-6">
    <header class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <p class="text-sm font-semibold text-emerald-700">Preview matriks · {{ $plan->academicYear->label }}</p>
            <h1 class="mt-1 text-3xl font-semibold text-balance text-slate-950">RPP {{ $plan->level->name }} · Semester {{ $semester }}</h1>
            <p class="mt-2 max-w-3xl text-pretty text-slate-600">Satu baris mewakili satu minggu. Kolom materi mengikuti pemetaan GGB dan Silabus, sedangkan perubahan manual disimpan sebagai revisi terkunci.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('planner.show', ['level' => $plan->level_id, 'semester' => $semester]) }}" class="button-secondary">Kembali ke Planner</a>
            <a href="{{ route('exports.workbook', ['level' => $plan->level_id, 'semester' => $semester]) }}" class="button-primary">Unduh Excel semester ini</a>
        </div>
    </header>

    <section class="panel p-4 sm:p-5" aria-label="Pilihan preview">
        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
            <label class="grid gap-1.5 text-sm font-medium text-slate-700">Jenjang
                <select wire:model.live="levelId" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3 text-slate-950">
                    @foreach($levels as $level)<option value="{{ $level->id }}">{{ $level->name }}</option>@endforeach
                </select>
            </label>
            <div class="inline-flex min-h-11 rounded-xl bg-slate-100 p-1" aria-label="Pilih semester">
                @foreach([1, 2] as $number)
                    <button type="button" wire:click="selectSemester({{ $number }})" @class(['min-h-9 rounded-lg px-5 text-sm font-semibold transition-colors', 'bg-white text-emerald-800 shadow-sm' => $semester === $number, 'text-slate-600 hover:text-slate-950' => $semester !== $number]) aria-pressed="{{ $semester === $number ? 'true' : 'false' }}">Semester {{ $number }}</button>
                @endforeach
            </div>
        </div>
    </section>

    @if($notice)<div class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 ring-1 ring-emerald-200" role="status">{{ $notice }}</div>@endif
    @if($errorMessage)<div class="rounded-xl bg-red-50 px-4 py-3 text-sm font-medium text-red-900 ring-1 ring-red-200" role="alert">{{ $errorMessage }}</div>@endif

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-7" aria-label="Ringkasan semester">
        <article class="panel p-4"><p class="text-sm text-slate-500">Cakupan</p><p class="metric-number mt-2">{{ number_format((float) $plan->coverage_percent, 1) }}%</p></article>
        <article class="panel p-4"><p class="text-sm text-slate-500">Status</p><p class="mt-2 font-semibold {{ $plan->status === 'validated' ? 'text-emerald-700' : 'text-amber-700' }}">{{ $plan->status === 'validated' ? 'Tervalidasi' : 'Draf' }}</p></article>
        <article class="panel p-4"><p class="text-sm text-slate-500">Kolom materi</p><p class="metric-number mt-2">{{ $columns->count() }}</p></article>
        <article class="panel p-4"><p class="text-sm text-slate-500">Target terukur</p><p class="metric-number mt-2">{{ $targetAchieved }}/{{ $targetTotal }}</p></article>
        <article class="panel p-4"><p class="text-sm text-slate-500">Perlu pola/manual</p><p class="metric-number mt-2">{{ $patternIssues->count() }}</p></article>
        <article class="panel p-4"><p class="text-sm text-slate-500">Konflik pemetaan</p><p class="metric-number mt-2">{{ $conflictCount }}</p><p class="mt-1 text-xs text-slate-500">{{ $unmappedCount }} materi belum dipetakan.</p></article>
        <a href="{{ route('exports.index', ['level' => $plan->level_id, 'semester' => $semester, 'detail' => 'ggb']) }}#ggb-detail" class="panel block cursor-pointer p-4 transition-colors duration-150 hover:border-emerald-400 hover:bg-emerald-50/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600"><p class="text-sm text-slate-500">Cakupan GGB 1 Tahun</p><p class="metric-number mt-2">{{ number_format($ggbCoverage['percent'], 1) }}%</p><p class="mt-1 text-xs text-slate-500">{{ $ggbCoverage['used'] }}/{{ $ggbCoverage['total'] }} butir rinci · {{ $annualValidation?->status === 'validated' ? 'Tervalidasi tahunan' : 'Lihat daftar' }}</p></a>
    </section>

    @if($detail === 'ggb')
        @include('livewire.partials.export-ggb-detail')
    @elseif($detail === 'calendar')
        @include('livewire.partials.export-calendar-detail')
    @endif

    <section class="panel p-4 shadow-sm lg:sticky lg:top-3 lg:z-30" aria-label="Toolbar preview">
        <div class="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="generate" wire:loading.attr="disabled" wire:target="generate" class="button-primary"><span wire:loading.remove wire:target="generate">Susun Otomatis</span><span wire:loading wire:target="generate">Menyusun…</span></button>
                <button type="button" @click="$refs.layoutEditor.open = true; $nextTick(() => $refs.layoutEditor.scrollIntoView({behavior:'smooth'}))" class="button-secondary">Atur Kolom</button>
                <button type="button" @click="$refs.targetEditor.open = true; $nextTick(() => $refs.targetEditor.scrollIntoView({behavior:'smooth'}))" class="button-secondary">Atur Target</button>
                <button type="button" wire:click="showDetail('calendar')" class="button-secondary">Atur Waktu</button>
                <button type="button" wire:click="validateSemester" wire:loading.attr="disabled" wire:target="validateSemester" class="button-secondary"><span wire:loading.remove wire:target="validateSemester">Validasi Semester</span><span wire:loading wire:target="validateSemester">Memvalidasi…</span></button>
                <button type="button" @click="clearDraft()" :disabled="dirtyCount === 0" class="button-secondary">Batalkan Draf</button>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                <label class="grid min-w-64 gap-1 text-sm font-medium text-slate-700">Alasan revisi<input x-model="reason" type="text" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3" placeholder="Minimal 5 karakter"></label>
                <span class="status status-warning min-h-7 self-start sm:mb-2" x-text="`${dirtyCount} sel berubah`"></span>
                <button type="button" @click="save()" :disabled="saving || dirtyCount === 0" class="button-primary"><span x-text="saving ? 'Menyimpan…' : 'Simpan Semua'"></span></button>
            </div>
        </div>
        <p x-show="clientMessage" x-text="clientMessage" class="mt-2 text-sm font-medium text-red-700" role="alert"></p>
        <p class="mt-2 text-xs text-slate-500">Klik materi atau tekan F2 untuk membuka editor. Ctrl/Cmd+S menyimpan seluruh draf dalam satu revisi.</p>
    </section>

    <details x-ref="layoutEditor" id="layout-editor" class="panel overflow-hidden">
        <summary class="flex min-h-14 items-center justify-between gap-3 px-4 py-3 font-semibold text-slate-950 sm:px-5">Atur kolom dan pemetaan RPP <span class="text-sm font-medium text-emerald-700">{{ $layoutColumns->count() }} kolom</span></summary>
        <div class="border-t border-slate-200 p-4 sm:p-5">
            <p class="mb-4 text-sm text-slate-600">Nama dan urutan hanya mengubah tampilan RPP. ID sumber, dokumen, dan halaman GGB/Silabus tetap terkunci.</p>
            <div class="overflow-x-auto rounded-xl ring-1 ring-slate-200">
                <table class="spreadsheet-table min-w-[1100px]" data-grid-table>
                    <thead><tr><th>Aspek</th><th>Subaspek</th><th>Nama kolom</th><th>Urutan</th><th>Lebar</th><th>Aktif</th><th>Materi</th></tr></thead>
                    <tbody>
                        @foreach($layoutColumns as $column)
                            <tr data-grid-row wire:key="layout-column-{{ $column->id }}">
                                <td><input data-grid-cell data-domain="matrix_column" data-id="{{ $column->id }}" data-version="{{ $column->lock_version }}" data-field="aspect_label" data-original="{{ $column->aspect_label }}" value="{{ $column->aspect_label }}"></td>
                                <td><input data-grid-cell data-domain="matrix_column" data-id="{{ $column->id }}" data-version="{{ $column->lock_version }}" data-field="subaspect_label" data-original="{{ $column->subaspect_label }}" value="{{ $column->subaspect_label }}"></td>
                                <td><input data-grid-cell data-domain="matrix_column" data-id="{{ $column->id }}" data-version="{{ $column->lock_version }}" data-field="label" data-original="{{ $column->label }}" value="{{ $column->label }}"></td>
                                <td><input type="number" min="1" data-grid-cell data-domain="matrix_column" data-id="{{ $column->id }}" data-version="{{ $column->lock_version }}" data-field="sort_order" data-original="{{ $column->sort_order }}" value="{{ $column->sort_order }}"></td>
                                <td><input type="number" min="12" max="60" data-grid-cell data-domain="matrix_column" data-id="{{ $column->id }}" data-version="{{ $column->lock_version }}" data-field="width" data-original="{{ $column->width }}" value="{{ $column->width }}"></td>
                                <td><select data-grid-cell data-domain="matrix_column" data-id="{{ $column->id }}" data-version="{{ $column->lock_version }}" data-field="is_active" data-original="{{ $column->is_active ? 1 : 0 }}"><option value="1" @selected($column->is_active)>Aktif</option><option value="0" @selected(! $column->is_active)>Nonaktif</option></select></td>
                                <td class="protected-cell">{{ $column->mappings_count }} materi</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <h3 class="mt-6 font-semibold text-slate-950">Pemetaan materi Silabus</h3>
            <div class="mt-3 grid max-h-[520px] gap-2 overflow-y-auto pr-1 lg:grid-cols-2">
                @foreach($layoutMappings as $material)
                    <label class="grid gap-2 rounded-xl border border-slate-200 p-3 text-sm" wire:key="layout-map-{{ $material->id }}">
                        <span><span class="font-semibold text-slate-900">{{ $material->title }}</span><span class="mt-1 block text-xs text-slate-500">{{ $material->stable_code }} · {{ $patternLabels[$material->schedule_pattern] ?? 'Perlu Pola Jadwal' }}</span></span>
                        @if($material->matrixMapping)
                            <select data-grid-cell data-domain="matrix_mapping" data-id="{{ $material->matrixMapping->id }}" data-version="{{ $material->matrixMapping->lock_version }}" data-field="rpp_matrix_column_id" data-original="{{ $material->matrixMapping->rpp_matrix_column_id }}" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3">
                                @foreach($layoutColumns as $option)<option value="{{ $option->id }}" @selected($material->matrixMapping->rpp_matrix_column_id === $option->id)>{{ $option->aspect_label }} · {{ $option->label }}</option>@endforeach
                            </select>
                        @else
                            <span class="status status-danger">Belum dipetakan</span>
                        @endif
                    </label>
                @endforeach
            </div>

            <h3 class="mt-6 font-semibold text-slate-950">Katalog GGB belum dipetakan</h3>
            <p class="mt-1 text-sm text-slate-600">Butir ini tetap masuk kamus Excel, tetapi harus dipetakan sebelum dapat dipilih dari sel RPP.</p>
            <div class="mt-3 grid max-h-[420px] gap-2 overflow-y-auto pr-1 lg:grid-cols-2">
                @forelse($unmappedCatalog as $material)
                    <label class="grid gap-2 rounded-xl border border-amber-200 bg-amber-50/40 p-3 text-sm" wire:key="catalog-map-{{ $material->id }}">
                        <span><span class="font-mono text-xs font-semibold text-amber-800">{{ $material->display_code }}</span><span class="mt-1 block font-semibold text-slate-900">{{ $material->title }}</span><span class="mt-1 block text-xs text-slate-500">{{ $material->source_kind === 'ggb' ? 'GGB' : 'Silabus' }}</span></span>
                        <select data-grid-cell data-domain="material_catalog" data-id="{{ $material->id }}" data-version="{{ $material->lock_version }}" data-field="rpp_matrix_column_id" data-original="" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3">
                            <option value="">Pilih kolom RPP</option>
                            @foreach($layoutColumns->where('is_active', true) as $option)<option value="{{ $option->id }}">{{ $option->aspect_label }} · {{ $option->label }}</option>@endforeach
                        </select>
                    </label>
                @empty
                    <p class="rounded-xl bg-emerald-50 p-4 text-sm font-medium text-emerald-800 ring-1 ring-emerald-200">Semua butir katalog sudah dipetakan.</p>
                @endforelse
            </div>
        </div>
    </details>

    <details x-ref="targetEditor" class="panel overflow-hidden">
        <summary class="flex min-h-14 items-center justify-between gap-3 px-4 py-3 font-semibold text-slate-950 sm:px-5">Target progres semester @if($annualTargetTotal)<span class="status status-success">Tilawati tahunan {{ $annualTargetAchieved }}/{{ $annualTargetTotal }}</span>@endif</summary>
        <div class="grid gap-5 border-t border-slate-200 p-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(320px,.8fr)] xl:p-5">
            <div class="space-y-3">
                @forelse($targets as $target)
                    <article class="rounded-xl border border-slate-200 p-4"><div class="flex items-start justify-between gap-3"><div><p class="font-semibold text-slate-950">{{ $target->syllabusItem->title }}</p><p class="mt-1 text-sm text-slate-500">{{ ucfirst($target->unit_label) }} {{ $target->range_start }}–{{ $target->range_end }} · {{ $target->summary['achieved'] }}/{{ $target->summary['total'] }}</p></div><button type="button" wire:click="editTarget({{ $target->syllabus_item_id }})" class="button-secondary">Edit</button></div><div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-600" style="width: {{ min(100, $target->summary['percent']) }}%"></div></div></article>
                @empty<div class="rounded-xl border border-dashed border-slate-300 p-5 text-sm text-slate-600">Belum ada target progres terukur.</div>@endforelse
            </div>
            <form wire:submit="saveTarget" class="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200">
                <h3 class="font-semibold text-slate-950">Atur target</h3>
                <div class="mt-4 grid gap-3">
                    <label class="grid gap-1 text-sm font-medium text-slate-700">Materi<select wire:model.live="targetSyllabusId" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"><option value="">Pilih materi</option>@foreach($eligibleMaterials as $material)<option value="{{ $material->id }}">{{ $material->stable_code }} · {{ $material->title }}</option>@endforeach</select></label>
                    <div class="grid grid-cols-2 gap-3"><label class="grid gap-1 text-sm font-medium text-slate-700">Unit<select wire:model="targetUnit" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"><option>halaman</option><option>ayat</option><option>surat</option><option>bab</option><option>label</option></select></label><label class="grid gap-1 text-sm font-medium text-slate-700">Strategi<select wire:model="targetStrategy" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"><option value="even">Merata</option></select></label></div>
                    <div class="grid grid-cols-2 gap-3"><label class="grid gap-1 text-sm font-medium text-slate-700">Nomor awal<input type="number" min="1" wire:model="targetStart" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"></label><label class="grid gap-1 text-sm font-medium text-slate-700">Nomor akhir<input type="number" min="1" wire:model="targetEnd" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"></label></div>
                    <label class="grid gap-1 text-sm font-medium text-slate-700">Alasan revisi<input type="text" wire:model="targetReason" placeholder="Minimal 5 karakter" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"></label>
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveTarget" class="button-primary" @disabled(! $targetSyllabusId)><span wire:loading.remove wire:target="saveTarget">Simpan target</span><span wire:loading wire:target="saveTarget">Menyimpan…</span></button>
                </div>
            </form>
        </div>
    </details>

    <section class="panel overflow-hidden" aria-labelledby="preview-heading">
        <div class="border-b border-slate-200 px-4 py-4 sm:px-5"><h2 id="preview-heading" class="text-lg font-semibold text-slate-950">Matriks {{ $weeks->count() }} minggu · Semester {{ $semester }}</h2><p class="mt-1 text-sm text-slate-500">Jumlah minggu mengikuti rentang tanggal Admin dan dibagi menjadi dua triwulan. Minggu non-efektif tetap terlihat tetapi tidak menerima materi.</p></div>

        <div class="hidden space-y-8 p-4 lg:block">
            @foreach($trimesterChunks as $trimesterIndex => $trimesterWeeks)
                <div class="overflow-hidden rounded-xl ring-1 ring-slate-300">
                    <div class="border-b border-slate-300 bg-emerald-950 px-4 py-3 text-center text-white"><p class="text-xs font-semibold uppercase tracking-wide">Rencana Program Pembelajaran</p><p class="mt-1 font-semibold">{{ strtoupper($plan->level->name) }} · SEMESTER {{ $semester }} · TRIWULAN {{ (($semester - 1) * 2) + $trimesterIndex + 1 }}</p></div>
                    <div class="matrix-scroll overflow-x-auto">
                        <table class="rpp-matrix-table" data-grid-table>
                            <colgroup>
                                <col class="matrix-col-month"><col class="matrix-col-focus"><col class="matrix-col-week"><col class="matrix-col-date">
                                @foreach($columns as $column)<col style="width: {{ $column->width }}ch; min-width: {{ $column->width }}ch">@endforeach
                                <col class="matrix-col-sign">
                            </colgroup>
                            <thead>
                                <tr class="matrix-header-aspect"><th rowspan="3" class="matrix-sticky matrix-month">Bulan</th><th rowspan="3" class="matrix-sticky matrix-focus">Fokus 29 Karakter Luhur</th><th rowspan="3" class="matrix-sticky matrix-week">Pekan</th><th rowspan="3" class="matrix-sticky matrix-date">Tanggal</th>@foreach($headerTree as $aspect)<th colspan="{{ $aspect['span'] }}" class="matrix-aspect">{{ $aspect['label'] }}</th>@endforeach<th rowspan="3" class="matrix-sign">Paraf Pengajar</th></tr>
                                <tr class="matrix-header-subaspect">@foreach($headerTree as $aspect)@foreach($aspect['subaspects'] as $subaspect)<th colspan="{{ $subaspect['span'] }}" class="matrix-subaspect">{{ $subaspect['label'] }}</th>@endforeach @endforeach</tr>
                                <tr>@foreach($columns as $column)<th style="min-width: {{ $column->width }}ch">{{ $column->label }}</th>@endforeach</tr>
                            </thead>
                            <tbody>
                                @php($previousMonth = null)
                                @foreach($trimesterWeeks as $week)
                                    @php($monthKey = $week->starts_on->format('Y-m'))
                                    @php($newMonth = $previousMonth !== $monthKey)
                                    @php($focus = $plan->monthFocuses->firstWhere('month_key', $monthKey))
                                    <tr data-grid-row @class(['matrix-month-start' => $newMonth]) wire:key="matrix-week-{{ $week->id }}">
                                        <td class="matrix-sticky matrix-month font-semibold">{{ $newMonth ? mb_strtoupper($week->month_label) : '' }}</td>
                                        <td class="matrix-sticky matrix-focus">@if($newMonth && $focus)<textarea rows="3" data-grid-cell data-domain="month_focus" data-id="{{ $focus->id }}" data-version="{{ $focus->lock_version }}" data-field="focus_text" data-original="{{ $focus->focus_text }}" aria-label="Fokus karakter {{ $week->month_label }}">{{ $focus->focus_text }}</textarea><span class="mt-1 block text-[10px] text-slate-500">{{ $focus->source === 'manual' ? 'Manual' : 'Saran otomatis' }}</span>@endif</td>
                                        <td class="matrix-sticky matrix-week text-center font-mono font-semibold">{{ $week->month_ordinal }}</td>
                                        <td class="matrix-sticky matrix-date whitespace-nowrap font-mono text-xs">{{ $week->starts_on->format('d M Y') }}</td>
                                        @if(! $week->resolved_is_effective)
                                            <td colspan="{{ $columns->count() }}" class="matrix-non-effective whitespace-pre-line">{{ $week->resolved_label }}</td>
                                        @else
                                            @foreach($columns as $column)
                                                @php($cellItems = collect($itemsByCell->get($week->id.':'.$column->id, [])))
                                                <td class="matrix-content-cell">
                                                    @forelse($cellItems as $item)
                                                        @php($sourceCode = $item->syllabusItem?->stable_code ?? $item->materials->first()?->ggbItem?->stable_code ?? 'Materi manual')
                                                        <button type="button" @click='openMatrixItem(@js(["id"=>$item->id,"version"=>$item->lock_version,"calendar_week_id"=>$item->calendar_week_id,"rpp_matrix_column_id"=>$item->rpp_matrix_column_id,"content"=>$item->content,"progress_start"=>$item->progress_start,"progress_end"=>$item->progress_end,"progress_kind"=>$item->progress_kind,"position"=>$item->position,"is_locked"=>$item->is_locked?1:0,"source"=>$item->source,"stable_code"=>$sourceCode,"source_note"=>app(\App\Services\RppMatrixService::class)->sourceNote($item)]))' @keydown.f2.prevent="$el.click()" class="matrix-item-button" title="Edit {{ $sourceCode }}"><span>{{ app(\App\Services\RppMaterialCatalogService::class)->placementLabel($item) }}</span>@if($item->progress_start)<small>{{ $item->progress_kind === 'penguatan' ? 'Penguatan ' : '' }}{{ $item->progress_start }}–{{ $item->progress_end }}</small>@elseif($item->progress_kind === 'penguatan')<small>Penguatan</small>@endif</button>
                                                    @empty@endforelse
                                                    <button type="button" wire:click="openMaterialPicker({{ $week->id }}, {{ $column->id }})" class="matrix-fill-button" aria-label="Isi materi {{ $column->label }} pada minggu {{ $week->week_number }}">+ Isi Materi</button>
                                                </td>
                                            @endforeach
                                        @endif
                                        <td class="matrix-sign"></td>
                                    </tr>
                                    @php($previousMonth = $monthKey)
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="divide-y divide-slate-200 lg:hidden">
            @foreach($weeks as $week)
                @php($monthKey = $week->starts_on->format('Y-m'))
                @php($focus = $plan->monthFocuses->firstWhere('month_key', $monthKey))
                <article class="p-4" wire:key="mobile-matrix-week-{{ $week->id }}">
                    <div class="flex items-start justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">{{ $week->month_label }} · Pekan {{ $week->month_ordinal }}</p><h3 class="mt-1 font-semibold text-slate-950">M{{ $week->week_number }} · {{ $week->starts_on->translatedFormat('d F Y') }}</h3></div><span class="status {{ $week->resolved_is_effective ? 'status-success' : 'status-warning' }}">{{ $week->resolved_is_effective ? 'Efektif' : 'Non-efektif' }}</span></div>
                    @if($focus)<label class="mt-3 grid gap-1 text-sm font-medium text-slate-700">Fokus karakter<input data-grid-cell data-domain="month_focus" data-id="{{ $focus->id }}" data-version="{{ $focus->lock_version }}" data-field="focus_text" data-original="{{ $focus->focus_text }}" value="{{ $focus->focus_text }}" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"></label>@endif
                    @if($week->resolved_is_effective)
                        <div class="mt-4 space-y-4">
                            @foreach($columns->groupBy('aspect_label') as $aspect => $aspectColumns)
                                <section><h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $aspect }}</h4><div class="mt-2 grid gap-3">@foreach($aspectColumns as $column)@php($cellItems = collect($itemsByCell->get($week->id.':'.$column->id, [])))<div class="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200"><div class="flex items-center justify-between gap-3"><h5 class="text-xs font-semibold text-emerald-800">{{ $column->label }}</h5><button type="button" wire:click="openMaterialPicker({{ $week->id }}, {{ $column->id }})" class="min-h-11 rounded-lg px-3 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">+ Isi Materi</button></div><div class="mt-2 grid gap-2">@foreach($cellItems as $item)@php($sourceCode = $item->syllabusItem?->stable_code ?? $item->materials->first()?->ggbItem?->stable_code ?? 'Materi manual')<button type="button" @click='openMatrixItem(@js(["id"=>$item->id,"version"=>$item->lock_version,"calendar_week_id"=>$item->calendar_week_id,"rpp_matrix_column_id"=>$item->rpp_matrix_column_id,"content"=>$item->content,"progress_start"=>$item->progress_start,"progress_end"=>$item->progress_end,"progress_kind"=>$item->progress_kind,"position"=>$item->position,"is_locked"=>$item->is_locked?1:0,"source"=>$item->source,"stable_code"=>$sourceCode,"source_note"=>app(\App\Services\RppMatrixService::class)->sourceNote($item)]))' class="flex min-h-11 w-full items-start justify-between gap-3 rounded-lg bg-white p-3 text-left ring-1 ring-slate-200"><span class="text-sm text-slate-900">{{ app(\App\Services\RppMaterialCatalogService::class)->placementLabel($item) }}</span><span class="text-xs font-semibold text-emerald-700">Edit</span></button>@endforeach</div></div>@endforeach</div></section>
                            @endforeach
                        </div>
                    @else<p class="mt-3 whitespace-pre-line text-sm text-slate-600">{{ $week->resolved_label }}</p>@endif
                </article>
            @endforeach
        </div>
    </section>

    @if($pickerWeekId && $pickerColumnId)
        <div class="fixed inset-0 z-[60]" role="dialog" aria-modal="true" aria-labelledby="material-picker-title" x-on:keydown.escape.window="$wire.closeMaterialPicker()" x-init="$nextTick(() => $refs.materialSearch.focus())">
            <button type="button" wire:click="closeMaterialPicker" tabindex="-1" class="absolute inset-0 h-full w-full cursor-default bg-slate-950/45" aria-label="Tutup pemilih materi"></button>
            <aside class="absolute inset-y-0 right-0 flex w-full max-w-2xl flex-col bg-white shadow-2xl">
                <div class="border-b border-slate-200 p-5 sm:p-6">
                    <div class="flex items-start justify-between gap-4"><div><p class="text-sm font-semibold text-emerald-700">{{ $pickerColumn?->aspect_label }} · {{ $pickerColumn?->label }}</p><h2 id="material-picker-title" class="mt-1 text-xl font-semibold text-slate-950">Isi materi minggu ini</h2><p class="mt-1 text-sm text-slate-600">Materi yang sudah digunakan tetap dapat dipilih sebagai penguatan.</p></div><button type="button" wire:click="closeMaterialPicker" class="button-secondary">Tutup</button></div>
                    <label class="mt-4 grid gap-1 text-sm font-medium text-slate-700">Cari kode atau judul<input x-ref="materialSearch" wire:model.live.debounce.250ms="pickerSearch" type="search" class="min-h-11 rounded-xl border border-slate-300 px-3" placeholder="Contoh: Adab 01 atau salam"></label>
                    <div class="mt-3 flex flex-wrap gap-2" aria-label="Filter status materi">
                        @foreach(['all' => 'Semua', 'unused' => 'Belum Masuk', 'used' => 'Sudah Terpasang', 'week' => 'Minggu Ini', 'unmapped' => 'Belum Dipetakan'] as $value => $label)
                            <button type="button" wire:click="$set('pickerStatus', '{{ $value }}')" @class(['min-h-11 rounded-xl px-3 text-sm font-semibold ring-1 transition-colors duration-150', 'bg-emerald-700 text-white ring-emerald-700' => $pickerStatus === $value, 'bg-white text-slate-700 ring-slate-300 hover:bg-slate-50' => $pickerStatus !== $value]) aria-pressed="{{ $pickerStatus === $value ? 'true' : 'false' }}">{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="flex-1 overflow-y-auto p-4 sm:p-5">
                    <div class="grid gap-3">
                        @forelse($pickerMaterials as $material)
                            @php($used = $material->placements->isNotEmpty())
                            @php($inWeek = $material->placements->contains('calendar_week_id', $pickerWeekId))
                            @php($disabled = ! $material->rpp_matrix_column_id || $material->mapping_status === 'unmapped')
                            <label class="flex gap-3 rounded-xl border border-slate-200 p-4 {{ $disabled ? 'bg-slate-50 opacity-70' : 'bg-white hover:border-emerald-300' }}" wire:key="picker-material-{{ $material->id }}">
                                <span class="flex size-11 shrink-0 items-center justify-center"><input wire:model.live="pickerSelected" type="checkbox" value="{{ $material->id }}" class="size-5 rounded border-slate-300 text-emerald-700" @disabled($disabled) aria-label="Pilih {{ $material->display_code }}"></span>
                                <span class="min-w-0 flex-1"><span class="flex flex-wrap items-center gap-2"><strong class="font-mono text-sm text-emerald-800">{{ $material->display_code }}</strong>@if($inWeek)<span class="status status-warning">Minggu ini</span>@elseif($used)<span class="status status-neutral">Sudah terpasang</span>@else<span class="status status-success">Belum masuk</span>@endif @if($disabled)<span class="status status-danger">Belum dipetakan</span>@endif</span><span class="mt-1 block font-semibold text-slate-950">{{ $material->title }}</span>
                                    @if($material->ggbItem)<span class="mt-2 block text-xs leading-5 text-slate-600">GGB · {{ $material->ggbItem->stable_code }} · hlm. {{ $material->ggbItem->source_page }}@if($material->ggbItem->syllabusItems->isNotEmpty())<br>Silabus: {{ $material->ggbItem->syllabusItems->pluck('title')->take(3)->implode('; ') }}@endif</span>@elseif($material->syllabusItem)<span class="mt-2 block text-xs leading-5 text-slate-600">Silabus tambahan · {{ $material->syllabusItem->stable_code }} · hlm. {{ $material->syllabusItem->source_page }}</span>@endif
                                    @if($used)<span class="mt-2 block text-xs font-medium text-slate-500">Dipakai: {{ $material->placements->sortBy(fn($placement) => $placement->week?->week_number)->map(fn($placement) => 'M'.$placement->week?->week_number)->implode(', ') }}</span>@endif
                                </span>
                            </label>
                        @empty
                            <div class="rounded-xl bg-slate-50 p-6 text-center text-sm text-slate-600 ring-1 ring-slate-200">Tidak ada materi yang cocok dengan filter ini.</div>
                        @endforelse
                    </div>
                </div>
                <div class="border-t border-slate-200 bg-white p-4 sm:p-5"><label class="grid gap-1 text-sm font-medium text-slate-700">Alasan penambahan<input wire:model="pickerReason" type="text" class="min-h-11 rounded-xl border border-slate-300 px-3" placeholder="Minimal 5 karakter"></label>@if($errorMessage)<p class="mt-2 text-sm font-medium text-red-700" role="alert">{{ $errorMessage }}</p>@endif<div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"><span class="text-sm font-medium text-slate-600"><span class="font-mono tabular-nums">{{ count($pickerSelected) }}</span> materi dipilih</span><button type="button" wire:click="addSelectedMaterials" wire:loading.attr="disabled" wire:target="addSelectedMaterials" class="button-primary" @disabled(count($pickerSelected) === 0)><span wire:loading.remove wire:target="addSelectedMaterials">Tambahkan & Kunci</span><span wire:loading wire:target="addSelectedMaterials">Menambahkan…</span></button></div></div>
            </aside>
        </div>
    @endif

    <div x-cloak x-show="editorOpen" x-on:keydown.escape.window="closeMatrixEditor()" class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label="Editor materi RPP">
        <button type="button" @click="closeMatrixEditor()" class="absolute inset-0 h-full w-full cursor-default bg-slate-950/45" aria-label="Tutup editor"></button>
        <aside x-show="editorOpen" x-transition class="absolute inset-y-0 right-0 w-full max-w-xl overflow-y-auto bg-white p-5 shadow-2xl sm:p-6">
            <div class="flex items-start justify-between gap-4"><div><p class="text-sm font-semibold text-emerald-700" x-text="editor?.stable_code"></p><h2 class="mt-1 text-xl font-semibold text-slate-950">Edit materi mingguan</h2></div><button type="button" @click="closeMatrixEditor()" class="button-secondary">Tutup</button></div>
            <template x-if="editor"><div class="mt-6 grid gap-4">
                <label class="grid gap-1 text-sm font-medium text-slate-700">Materi<textarea x-ref="matrixEditorContent" x-model="editor.content" rows="5" data-grid-cell data-domain="rpp" :data-id="editor.id" :data-version="editor.version" data-field="content" :data-original="editor.original_content ?? editor.content" class="rounded-xl border border-slate-300 bg-white px-3 py-2"></textarea></label>
                <div class="grid grid-cols-2 gap-3"><label class="grid gap-1 text-sm font-medium text-slate-700">Minggu<select x-model="editor.calendar_week_id" data-grid-cell data-domain="rpp" :data-id="editor.id" :data-version="editor.version" data-field="calendar_week_id" :data-original="editor.original_calendar_week_id ?? editor.calendar_week_id" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3">@foreach($effectiveWeeks as $optionWeek)<option value="{{ $optionWeek->id }}">M{{ $optionWeek->week_number }} · {{ $optionWeek->starts_on->format('d/m/Y') }}</option>@endforeach</select></label><label class="grid gap-1 text-sm font-medium text-slate-700">Kolom<select x-model="editor.rpp_matrix_column_id" data-grid-cell data-domain="rpp" :data-id="editor.id" :data-version="editor.version" data-field="rpp_matrix_column_id" :data-original="editor.original_rpp_matrix_column_id ?? editor.rpp_matrix_column_id" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3">@foreach($columns as $column)<option value="{{ $column->id }}">{{ $column->label }}</option>@endforeach</select></label></div>
                <div class="grid grid-cols-2 gap-3"><label class="grid gap-1 text-sm font-medium text-slate-700">Progres awal<input type="number" min="1" x-model="editor.progress_start" data-grid-cell data-domain="rpp" :data-id="editor.id" :data-version="editor.version" data-field="progress_start" :data-original="editor.original_progress_start ?? editor.progress_start" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"></label><label class="grid gap-1 text-sm font-medium text-slate-700">Progres akhir<input type="number" min="1" x-model="editor.progress_end" data-grid-cell data-domain="rpp" :data-id="editor.id" :data-version="editor.version" data-field="progress_end" :data-original="editor.original_progress_end ?? editor.progress_end" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"></label></div>
                <div class="grid grid-cols-2 gap-3"><label class="grid gap-1 text-sm font-medium text-slate-700">Jenis progres<select x-model="editor.progress_kind" data-grid-cell data-domain="rpp" :data-id="editor.id" :data-version="editor.version" data-field="progress_kind" :data-original="editor.original_progress_kind ?? editor.progress_kind" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"><option value="">Biasa</option><option value="materi_baru">Materi baru</option><option value="penguatan">Penguatan</option></select></label><label class="grid gap-1 text-sm font-medium text-slate-700">Kunci<select x-model="editor.is_locked" data-grid-cell data-domain="rpp" :data-id="editor.id" :data-version="editor.version" data-field="is_locked" :data-original="editor.original_is_locked ?? editor.is_locked" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"><option value="1">Dikunci</option><option value="0">Terbuka</option></select></label></div>
                <div class="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Keterlacakan sumber</p><pre class="mt-2 whitespace-pre-wrap font-sans text-xs leading-5 text-slate-700" x-text="editor.source_note"></pre></div>
                <p class="text-sm text-slate-600">Perubahan materi, minggu, kolom, atau progres otomatis menjadi manual dan terkunci setelah disimpan.</p>
                <button type="button" @click="closeMatrixEditor()" class="button-primary">Selesai mengedit draf</button>
            </div></template>
        </aside>
    </div>
</div>
