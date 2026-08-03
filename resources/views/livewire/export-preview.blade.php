<div x-data="spreadsheetGrid('rpp')" data-grid-domain="rpp" x-on:keydown.window="handleShortcut($event)" class="preview-grid space-y-6">
    <header class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <p class="text-sm font-semibold text-emerald-700">Preview terpilih · {{ $plan->academicYear->label }}</p>
            <h1 class="mt-1 text-3xl font-semibold text-balance text-slate-950">RPP {{ $plan->level->name }} · Semester {{ $semester }}</h1>
            <p class="mt-2 max-w-3xl text-pretty text-slate-600">Preview ini adalah sumber ekspor. Edit manual disimpan sebagai revisi dan otomatis menjadi jangkar terkunci saat RPP disusun ulang.</p>
        </div>
        <a href="{{ route('exports.workbook', ['level' => $plan->level_id, 'semester' => $semester]) }}" class="button-primary min-h-11">Unduh Excel semester ini</a>
    </header>

    <section class="panel p-4 sm:p-5" aria-label="Pilihan preview">
        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
            <label class="grid gap-1.5 text-sm font-medium text-slate-700">
                Jenjang
                <select wire:model.live="levelId" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3 text-slate-950">
                    @foreach($levels as $level)
                        <option value="{{ $level->id }}">{{ $level->name }}</option>
                    @endforeach
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

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5" aria-label="Ringkasan semester">
        <article class="panel p-4"><p class="text-sm text-slate-500">Cakupan</p><p class="metric-number mt-2">{{ number_format((float) $plan->coverage_percent, 1) }}%</p></article>
        <article class="panel p-4"><p class="text-sm text-slate-500">Status</p><p class="mt-2 font-semibold {{ $plan->status === 'validated' ? 'text-emerald-700' : 'text-amber-700' }}">{{ $plan->status === 'validated' ? 'Tervalidasi' : 'Draf' }}</p></article>
        <article class="panel p-4"><p class="text-sm text-slate-500">Target terukur</p><p class="metric-number mt-2">{{ $targetAchieved }}/{{ $targetTotal }}</p></article>
        <article class="panel p-4"><p class="text-sm text-slate-500">Sisa unit</p><p class="metric-number mt-2">{{ max(0, $targetTotal - $targetAchieved) }}</p></article>
        <article class="panel p-4"><p class="text-sm text-slate-500">Konflik</p><p class="metric-number mt-2">{{ $conflictCount }}</p><p class="mt-1 text-xs text-slate-500">Konflik ditolak sebelum tersimpan.</p></article>
    </section>

    <section class="panel overflow-hidden" aria-labelledby="target-heading">
        <div class="border-b border-slate-200 px-4 py-4 sm:px-5">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"><h2 id="target-heading" class="text-lg font-semibold text-slate-950">Target progres semester</h2>@if($annualTargetTotal)<span class="status status-success">Tilawati tahunan {{ $annualTargetAchieved }}/{{ $annualTargetTotal }}</span>@endif</div>
            <p class="mt-1 text-sm text-slate-500">Tilawati PAUD disiapkan 1–22 pada Semester 1 dan 23–44 pada Semester 2. Materi lain dapat diberi target halaman, ayat, surat, bab, atau label khusus.</p>
        </div>
        <div class="grid gap-5 p-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(320px,.8fr)] xl:p-5">
            <div class="space-y-3">
                @forelse($targets as $target)
                    <article class="rounded-xl border border-slate-200 p-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div><p class="font-semibold text-slate-950">{{ $target->syllabusItem->title }}</p><p class="mt-1 text-sm text-slate-500">{{ ucfirst($target->unit_label) }} {{ $target->range_start }}–{{ $target->range_end }} · sumber {{ $target->syllabusItem->document->title }} hlm. {{ $target->syllabusItem->source_page }}</p></div>
                            <button type="button" wire:click="editTarget({{ $target->syllabus_item_id }})" class="button-secondary min-h-11 shrink-0">Edit target</button>
                        </div>
                        <div class="mt-3 flex items-center gap-3"><div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-600" style="width: {{ min(100, $target->summary['percent']) }}%"></div></div><span class="font-mono text-sm tabular-nums text-slate-700">{{ $target->summary['achieved'] }}/{{ $target->summary['total'] }}</span></div>
                    </article>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 p-5 text-sm text-slate-600">Belum ada target terukur. Materi biasa tetap dapat disusun mengikuti urutan silabus.</div>
                @endforelse
            </div>
            <form wire:submit="saveTarget" class="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200">
                <h3 class="font-semibold text-slate-950">Atur target</h3>
                <div class="mt-4 grid gap-3">
                    <label class="grid gap-1 text-sm font-medium text-slate-700">Materi
                        <select wire:model.live="targetSyllabusId" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3">
                            <option value="">Pilih materi</option>
                            @foreach($eligibleMaterials as $material)<option value="{{ $material->id }}">{{ $material->stable_code }} · {{ $material->title }}</option>@endforeach
                        </select>
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="grid gap-1 text-sm font-medium text-slate-700">Unit
                            <select wire:model="targetUnit" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"><option>halaman</option><option>ayat</option><option>surat</option><option>bab</option><option>label</option></select>
                        </label>
                        <label class="grid gap-1 text-sm font-medium text-slate-700">Strategi
                            <select wire:model="targetStrategy" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"><option value="even">Merata</option></select>
                        </label>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="grid gap-1 text-sm font-medium text-slate-700">Nomor awal<input type="number" min="1" wire:model="targetStart" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"></label>
                        <label class="grid gap-1 text-sm font-medium text-slate-700">Nomor akhir<input type="number" min="1" wire:model="targetEnd" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"></label>
                    </div>
                    <label class="grid gap-1 text-sm font-medium text-slate-700">Alasan revisi<input type="text" wire:model="targetReason" placeholder="Minimal 5 karakter" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"></label>
                    @if($errorMessage)<p class="text-sm font-medium text-red-700" role="alert">{{ $errorMessage }}</p>@endif
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveTarget" class="button-primary min-h-11" @disabled(! $targetSyllabusId)><span wire:loading.remove wire:target="saveTarget">Simpan target</span><span wire:loading wire:target="saveTarget">Menyimpan…</span></button>
                    @php($activeTarget = $targetSyllabusId ? $targets->firstWhere('syllabus_item_id', (int) $targetSyllabusId) : null)
                    @if($activeTarget)<button type="button" wire:click="deleteTarget({{ $activeTarget->id }})" wire:confirm="Nonaktifkan target ini? Penempatan otomatisnya akan diperbarui saat Susun Otomatis." class="min-h-11 rounded-xl border border-red-200 bg-white px-4 text-sm font-semibold text-red-700">Nonaktifkan target</button>@endif
                </div>
            </form>
        </div>
    </section>

    <section class="panel p-4 shadow-sm lg:sticky lg:top-3 lg:z-20" aria-label="Toolbar preview">
        <div class="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="generate" wire:loading.attr="disabled" wire:target="generate" class="button-primary min-h-11"><span wire:loading.remove wire:target="generate">Susun Otomatis</span><span wire:loading wire:target="generate">Menyusun…</span></button>
                <button type="button" wire:click="validateSemester" wire:loading.attr="disabled" wire:target="validateSemester" class="button-secondary min-h-11"><span wire:loading.remove wire:target="validateSemester">Validasi Semester</span><span wire:loading wire:target="validateSemester">Memvalidasi…</span></button>
                <button type="button" @click="clearDraft()" :disabled="dirtyCount === 0" class="button-secondary min-h-11">Batalkan Draf</button>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                <label class="grid min-w-64 gap-1 text-sm font-medium text-slate-700">Alasan edit tabel<input x-model="reason" type="text" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3" placeholder="Minimal 5 karakter"></label>
                <span class="status status-warning min-h-7 self-start sm:mb-2" x-text="`${dirtyCount} sel berubah`"></span>
                <button type="button" @click="save()" :disabled="saving || dirtyCount === 0" class="button-primary min-h-11"><span x-text="saving ? 'Menyimpan…' : 'Simpan Semua'"></span></button>
            </div>
        </div>
        <p x-show="clientMessage" x-text="clientMessage" class="mt-2 text-sm font-medium text-red-700" role="alert"></p>
        <p class="mt-2 text-xs text-slate-500">Pintasan: panah, Enter/F2, Tab, Escape, Ctrl/Cmd+S, serta tempel TSV dari Excel.</p>
    </section>

    <section class="panel overflow-hidden" aria-labelledby="preview-heading">
        <div class="border-b border-slate-200 px-4 py-4 sm:px-5"><h2 id="preview-heading" class="text-lg font-semibold text-slate-950">Preview 26 minggu · Semester {{ $semester }}</h2><p class="mt-1 text-sm text-slate-500">Minggu non-efektif tetap ditampilkan, tetapi tidak dapat menerima materi.</p></div>

        <div class="hidden overflow-x-auto lg:block">
            <table class="data-table min-w-[1180px]" data-grid-table>
                <thead><tr><th class="sticky left-0 z-10 bg-slate-50">Minggu</th><th class="sticky left-[92px] z-10 bg-slate-50">Materi</th><th>Kategori</th><th>Rentang</th><th>Jenis progres</th><th>Posisi</th><th>Kunci</th><th>Sumber</th></tr></thead>
                <tbody>
                    @foreach($weeks as $week)
                        @php($weekItems = collect($itemsByWeek->get($week->id, [])))
                        @forelse($weekItems as $item)
                            <tr data-grid-row wire:key="preview-{{ $item->id }}">
                                <td class="sticky left-0 z-[1] bg-white align-top"><select data-grid-cell data-id="{{ $item->id }}" data-version="{{ $item->lock_version }}" data-field="calendar_week_id" data-original="{{ $item->calendar_week_id }}" class="grid-cell w-24 font-mono font-semibold">@foreach($effectiveWeeks as $optionWeek)<option value="{{ $optionWeek->id }}" @selected($item->calendar_week_id === $optionWeek->id)>M{{ $optionWeek->week_number }}</option>@endforeach</select><p class="mt-1 text-xs text-slate-500">{{ $week->starts_on->format('d/m/Y') }}</p></td>
                                <td class="sticky left-[92px] z-[1] min-w-80 bg-white"><textarea rows="3" data-grid-cell data-id="{{ $item->id }}" data-version="{{ $item->lock_version }}" data-field="content" data-original="{{ $item->content }}" class="grid-cell min-w-72">{{ $item->content }}</textarea><p class="mt-1 text-xs text-slate-500">{{ $item->syllabusItem->stable_code }}</p></td>
                                <td><input data-grid-cell data-id="{{ $item->id }}" data-version="{{ $item->lock_version }}" data-field="strand" data-original="{{ $item->strand }}" value="{{ $item->strand }}" class="grid-cell min-w-48"></td>
                                <td><div class="flex items-center gap-1"><input type="number" min="1" data-grid-cell data-id="{{ $item->id }}" data-version="{{ $item->lock_version }}" data-field="progress_start" data-original="{{ $item->progress_start }}" value="{{ $item->progress_start }}" class="grid-cell w-20"><span>–</span><input type="number" min="1" data-grid-cell data-id="{{ $item->id }}" data-version="{{ $item->lock_version }}" data-field="progress_end" data-original="{{ $item->progress_end }}" value="{{ $item->progress_end }}" class="grid-cell w-20"></div></td>
                                <td><select data-grid-cell data-id="{{ $item->id }}" data-version="{{ $item->lock_version }}" data-field="progress_kind" data-original="{{ $item->progress_kind }}" class="grid-cell min-w-36"><option value="">Biasa</option><option value="materi_baru" @selected($item->progress_kind === 'materi_baru')>Materi baru</option><option value="penguatan" @selected($item->progress_kind === 'penguatan')>Penguatan</option></select></td>
                                <td><input type="number" min="1" data-grid-cell data-id="{{ $item->id }}" data-version="{{ $item->lock_version }}" data-field="position" data-original="{{ $item->position }}" value="{{ $item->position }}" class="grid-cell w-20"></td>
                                <td><select data-grid-cell data-id="{{ $item->id }}" data-version="{{ $item->lock_version }}" data-field="is_locked" data-original="{{ $item->is_locked ? 1 : 0 }}" class="grid-cell min-w-28"><option value="1" @selected($item->is_locked)>Dikunci</option><option value="0" @selected(! $item->is_locked)>Terbuka</option></select></td>
                                <td><span class="status {{ $item->source === 'manual' ? 'status-warning' : 'status-success' }}">{{ $item->source === 'manual' ? 'Manual' : 'Otomatis' }}</span></td>
                            </tr>
                        @empty
                            <tr><td class="sticky left-0 bg-white"><p class="font-mono font-semibold">M{{ $week->week_number }}</p><p class="text-xs text-slate-500">{{ $week->starts_on->format('d/m/Y') }}</p></td><td colspan="7" class="text-sm text-slate-500">{{ $week->is_effective ? 'Belum ada materi pada minggu efektif ini.' : ($week->label ?: match($week->type) {'evaluation' => 'Evaluasi', 'religious_holiday' => 'Hari Raya', default => 'Libur'}) }}</td></tr>
                        @endforelse
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="divide-y divide-slate-100 lg:hidden">
            @foreach($weeks as $week)
                @php($weekItems = collect($itemsByWeek->get($week->id, [])))
                <article class="p-4">
                    <div class="flex items-start justify-between gap-3"><div><h3 class="font-mono font-semibold text-slate-950">M{{ $week->week_number }}</h3><p class="mt-1 text-sm text-slate-500">{{ $week->starts_on->translatedFormat('d F Y') }}</p></div><span class="status {{ $week->is_effective ? 'status-success' : 'status-warning' }}">{{ $week->is_effective ? 'Efektif' : ($week->label ?: 'Non-efektif') }}</span></div>
                    <div class="mt-3 space-y-3">
                        @forelse($weekItems as $item)
                            <details class="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200"><summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3"><span><span class="block font-semibold text-slate-950">{{ $item->content }}</span><span class="mt-1 block text-sm text-slate-600">{{ $item->strand }}@if($item->progress_start) · {{ $item->progress_start }}–{{ $item->progress_end }}@endif · {{ $item->is_locked ? 'Dikunci' : 'Terbuka' }}</span></span><span class="text-sm font-semibold text-emerald-700">Edit manual</span></summary><div class="mt-3 grid gap-3 border-t border-slate-200 pt-3"><label class="grid gap-1 text-sm font-medium text-slate-700">Minggu<select data-grid-cell data-id="{{ $item->id }}" data-version="{{ $item->lock_version }}" data-field="calendar_week_id" data-original="{{ $item->calendar_week_id }}" class="grid-cell">@foreach($effectiveWeeks as $optionWeek)<option value="{{ $optionWeek->id }}" @selected($item->calendar_week_id === $optionWeek->id)>M{{ $optionWeek->week_number }} · {{ $optionWeek->starts_on->format('d/m/Y') }}</option>@endforeach</select></label><label class="grid gap-1 text-sm font-medium text-slate-700">Materi<textarea rows="3" data-grid-cell data-id="{{ $item->id }}" data-version="{{ $item->lock_version }}" data-field="content" data-original="{{ $item->content }}" class="grid-cell">{{ $item->content }}</textarea></label><label class="grid gap-1 text-sm font-medium text-slate-700">Kategori<input data-grid-cell data-id="{{ $item->id }}" data-version="{{ $item->lock_version }}" data-field="strand" data-original="{{ $item->strand }}" value="{{ $item->strand }}" class="grid-cell"></label><div class="grid grid-cols-2 gap-3"><label class="grid gap-1 text-sm font-medium text-slate-700">Progres awal<input type="number" min="1" data-grid-cell data-id="{{ $item->id }}" data-version="{{ $item->lock_version }}" data-field="progress_start" data-original="{{ $item->progress_start }}" value="{{ $item->progress_start }}" class="grid-cell"></label><label class="grid gap-1 text-sm font-medium text-slate-700">Progres akhir<input type="number" min="1" data-grid-cell data-id="{{ $item->id }}" data-version="{{ $item->lock_version }}" data-field="progress_end" data-original="{{ $item->progress_end }}" value="{{ $item->progress_end }}" class="grid-cell"></label></div><div class="grid grid-cols-2 gap-3"><label class="grid gap-1 text-sm font-medium text-slate-700">Posisi<input type="number" min="1" data-grid-cell data-id="{{ $item->id }}" data-version="{{ $item->lock_version }}" data-field="position" data-original="{{ $item->position }}" value="{{ $item->position }}" class="grid-cell"></label><label class="grid gap-1 text-sm font-medium text-slate-700">Kunci<select data-grid-cell data-id="{{ $item->id }}" data-version="{{ $item->lock_version }}" data-field="is_locked" data-original="{{ $item->is_locked ? 1 : 0 }}" class="grid-cell"><option value="1" @selected($item->is_locked)>Dikunci</option><option value="0" @selected(! $item->is_locked)>Terbuka</option></select></label></div></div></details>
                        @empty<p class="text-sm text-slate-500">{{ $week->is_effective ? 'Belum ada materi.' : ($week->label ?: 'Tidak menerima materi.') }}</p>@endforelse
                    </div>
                </article>
            @endforeach
        </div>
    </section>
</div>
