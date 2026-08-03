<div x-data="spreadsheetGrid('rpp')" data-grid-domain="rpp" x-on:keydown.window="handleShortcut($event)" class="preview-grid space-y-6">
    <header class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <p class="text-sm font-semibold text-emerald-700">Preview matriks · {{ $plan->academicYear->label }}</p>
            <h1 class="mt-1 text-3xl font-semibold text-balance text-slate-950">RPP {{ $plan->level->name }} · Semester {{ $semester }}</h1>
            <p class="mt-2 max-w-3xl text-pretty text-slate-600">Satu baris mewakili satu minggu. Kolom materi mengikuti pemetaan GGB dan Silabus, sedangkan perubahan manual disimpan sebagai revisi terkunci.</p>
        </div>
        <a href="{{ route('exports.workbook', ['level' => $plan->level_id, 'semester' => $semester]) }}" class="button-primary">Unduh Excel semester ini</a>
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

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6" aria-label="Ringkasan semester">
        <article class="panel p-4"><p class="text-sm text-slate-500">Cakupan</p><p class="metric-number mt-2">{{ number_format((float) $plan->coverage_percent, 1) }}%</p></article>
        <article class="panel p-4"><p class="text-sm text-slate-500">Status</p><p class="mt-2 font-semibold {{ $plan->status === 'validated' ? 'text-emerald-700' : 'text-amber-700' }}">{{ $plan->status === 'validated' ? 'Tervalidasi' : 'Draf' }}</p></article>
        <article class="panel p-4"><p class="text-sm text-slate-500">Kolom materi</p><p class="metric-number mt-2">{{ $columns->count() }}</p></article>
        <article class="panel p-4"><p class="text-sm text-slate-500">Target terukur</p><p class="metric-number mt-2">{{ $targetAchieved }}/{{ $targetTotal }}</p></article>
        <article class="panel p-4"><p class="text-sm text-slate-500">Perlu pola/manual</p><p class="metric-number mt-2">{{ $patternIssues->count() }}</p></article>
        <article class="panel p-4"><p class="text-sm text-slate-500">Konflik pemetaan</p><p class="metric-number mt-2">{{ $conflictCount }}</p><p class="mt-1 text-xs text-slate-500">{{ $unmappedCount }} materi belum dipetakan.</p></article>
    </section>

    <section class="panel p-4 shadow-sm lg:sticky lg:top-3 lg:z-30" aria-label="Toolbar preview">
        <div class="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="generate" wire:loading.attr="disabled" wire:target="generate" class="button-primary"><span wire:loading.remove wire:target="generate">Susun Otomatis</span><span wire:loading wire:target="generate">Menyusun…</span></button>
                <button type="button" @click="$refs.layoutEditor.open = true; $nextTick(() => $refs.layoutEditor.scrollIntoView({behavior:'smooth'}))" class="button-secondary">Atur Kolom</button>
                <button type="button" @click="$refs.targetEditor.open = true; $nextTick(() => $refs.targetEditor.scrollIntoView({behavior:'smooth'}))" class="button-secondary">Atur Target</button>
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
        <div class="border-b border-slate-200 px-4 py-4 sm:px-5"><h2 id="preview-heading" class="text-lg font-semibold text-slate-950">Matriks 26 minggu · Semester {{ $semester }}</h2><p class="mt-1 text-sm text-slate-500">Dibagi menjadi dua triwulan. Minggu non-efektif tetap terlihat tetapi tidak menerima materi.</p></div>

        <div class="hidden space-y-8 p-4 lg:block">
            @foreach($trimesterChunks as $trimesterIndex => $trimesterWeeks)
                <div class="overflow-hidden rounded-xl ring-1 ring-slate-300">
                    <div class="border-b border-slate-300 bg-emerald-950 px-4 py-3 text-center text-white"><p class="text-xs font-semibold uppercase tracking-wide">Rencana Program Pembelajaran</p><p class="mt-1 font-semibold">{{ strtoupper($plan->level->name) }} · SEMESTER {{ $semester }} · TRIWULAN {{ (($semester - 1) * 2) + $trimesterIndex + 1 }}</p></div>
                    <div class="matrix-scroll overflow-x-auto">
                        <table class="rpp-matrix-table" data-grid-table>
                            <thead>
                                <tr><th rowspan="3" class="matrix-sticky matrix-month">Bulan</th><th rowspan="3" class="matrix-sticky matrix-focus">Fokus 29 Karakter Luhur</th><th rowspan="3" class="matrix-sticky matrix-week">Pekan</th><th rowspan="3" class="matrix-sticky matrix-date">Tanggal</th>@foreach($aspectGroups as $group)<th colspan="{{ $group['span'] }}" class="matrix-aspect">{{ $group['label'] }}</th>@endforeach<th rowspan="3" class="matrix-sign">Paraf Pengajar</th></tr>
                                <tr>@foreach($subaspectGroups as $group)<th colspan="{{ $group['span'] }}" class="matrix-subaspect">{{ $group['label'] }}</th>@endforeach</tr>
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
                                        @if(! $week->is_effective)
                                            <td colspan="{{ $columns->count() }}" class="matrix-non-effective">{{ $week->label ?: match($week->type) {'evaluation' => 'Evaluasi', 'religious_holiday' => 'Hari Raya', default => 'Libur'} }}</td>
                                        @else
                                            @foreach($columns as $column)
                                                @php($cellItems = collect($itemsByCell->get($week->id.':'.$column->id, [])))
                                                <td class="matrix-content-cell">
                                                    @forelse($cellItems as $item)
                                                        <button type="button" @click='openMatrixItem(@js(["id"=>$item->id,"version"=>$item->lock_version,"calendar_week_id"=>$item->calendar_week_id,"rpp_matrix_column_id"=>$item->rpp_matrix_column_id,"content"=>$item->content,"progress_start"=>$item->progress_start,"progress_end"=>$item->progress_end,"progress_kind"=>$item->progress_kind,"position"=>$item->position,"is_locked"=>$item->is_locked?1:0,"source"=>$item->source,"stable_code"=>$item->syllabusItem->stable_code,"source_note"=>app(\App\Services\RppMatrixService::class)->sourceNote($item)]))' @keydown.f2.prevent="$el.click()" class="matrix-item-button" title="Edit {{ $item->syllabusItem->stable_code }}"><span>{{ $item->content }}</span>@if($item->progress_start)<small>{{ $item->progress_kind === 'penguatan' ? 'Penguatan ' : '' }}{{ $item->progress_start }}–{{ $item->progress_end }}</small>@endif</button>
                                                    @empty<span class="text-slate-300">—</span>@endforelse
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
                    <div class="flex items-start justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">{{ $week->month_label }} · Pekan {{ $week->month_ordinal }}</p><h3 class="mt-1 font-semibold text-slate-950">M{{ $week->week_number }} · {{ $week->starts_on->translatedFormat('d F Y') }}</h3></div><span class="status {{ $week->is_effective ? 'status-success' : 'status-warning' }}">{{ $week->is_effective ? 'Efektif' : ($week->label ?: 'Non-efektif') }}</span></div>
                    @if($focus)<label class="mt-3 grid gap-1 text-sm font-medium text-slate-700">Fokus karakter<input data-grid-cell data-domain="month_focus" data-id="{{ $focus->id }}" data-version="{{ $focus->lock_version }}" data-field="focus_text" data-original="{{ $focus->focus_text }}" value="{{ $focus->focus_text }}" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"></label>@endif
                    @if($week->is_effective)
                        <div class="mt-4 space-y-4">
                            @foreach($columns->groupBy('aspect_label') as $aspect => $aspectColumns)
                                @php($hasContent = $aspectColumns->contains(fn($column) => collect($itemsByCell->get($week->id.':'.$column->id, []))->isNotEmpty()))
                                @if($hasContent)<section><h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $aspect }}</h4><div class="mt-2 grid gap-2">@foreach($aspectColumns as $column)@php($cellItems = collect($itemsByCell->get($week->id.':'.$column->id, [])))@foreach($cellItems as $item)<button type="button" @click='openMatrixItem(@js(["id"=>$item->id,"version"=>$item->lock_version,"calendar_week_id"=>$item->calendar_week_id,"rpp_matrix_column_id"=>$item->rpp_matrix_column_id,"content"=>$item->content,"progress_start"=>$item->progress_start,"progress_end"=>$item->progress_end,"progress_kind"=>$item->progress_kind,"position"=>$item->position,"is_locked"=>$item->is_locked?1:0,"source"=>$item->source,"stable_code"=>$item->syllabusItem->stable_code,"source_note"=>app(\App\Services\RppMatrixService::class)->sourceNote($item)]))' class="flex min-h-11 w-full items-start justify-between gap-3 rounded-xl bg-slate-50 p-3 text-left ring-1 ring-slate-200"><span><span class="block text-xs font-semibold text-emerald-800">{{ $column->label }}</span><span class="mt-1 block text-sm text-slate-900">{{ $item->content }}</span></span><span class="text-xs font-semibold text-emerald-700">Edit</span></button>@endforeach @endforeach</div></section>@endif
                            @endforeach
                        </div>
                    @else<p class="mt-3 text-sm text-slate-600">{{ $week->label ?: 'Minggu ini tidak menerima materi.' }}</p>@endif
                </article>
            @endforeach
        </div>
    </section>

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
