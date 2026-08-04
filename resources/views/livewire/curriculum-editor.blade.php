<div x-data="spreadsheetGrid(@js($tab))" data-grid-domain="{{ $tab }}" x-on:keydown.window="handleShortcut($event)" class="space-y-6">
    <header class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <a href="{{ route('curriculum.show', $level) }}" class="text-sm font-semibold text-emerald-700">Kembali ke {{ $level->name }}</a>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Editor tabel {{ $level->name }}</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Edit seperti spreadsheet, lalu simpan semua perubahan sebagai satu batch yang dapat diaudit dan dipulihkan.</p>
        </div>
        <a href="{{ route('revisions.index') }}" class="button-secondary">Riwayat revisi</a>
    </header>

    <nav class="flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Jenis data">
        @foreach (['ggb' => 'GGB', 'syllabus' => 'Silabus', 'link' => 'Relasi GGB–Silabus', 'rpp' => 'RPP Mingguan'] as $key => $label)
            <button type="button" @click="if (dirtyCount > 0 && !confirm('Buang draf yang belum disimpan dan ganti tabel?')) { $event.stopImmediatePropagation() } else { clearDraft() }" wire:click="setTab('{{ $key }}')" class="min-h-11 shrink-0 border-b-2 px-4 text-sm font-semibold {{ $tab === $key ? 'border-emerald-700 text-emerald-800' : 'border-transparent text-slate-600 hover:text-slate-950' }}" aria-current="{{ $tab === $key ? 'page' : 'false' }}">{{ $label }}</button>
        @endforeach
    </nav>

    @if($tab === 'rpp')
        <div class="inline-flex rounded-xl bg-slate-100 p-1 ring-1 ring-slate-200" aria-label="Semester RPP">
            @foreach([1, 2] as $semesterOption)<button type="button" wire:click="$set('semester', {{ $semesterOption }})" class="min-h-11 rounded-lg px-4 text-sm font-semibold {{ $semester === $semesterOption ? 'bg-white text-emerald-800 shadow-sm' : 'text-slate-600' }}">Semester {{ $semesterOption }}</button>@endforeach
        </div>
    @endif

    <section class="panel p-4">
        <div class="grid gap-3 lg:grid-cols-[minmax(220px,1fr)_220px_auto]">
            <label class="block text-sm font-medium text-slate-700">Cari
                <input wire:model.live.debounce.350ms="search" type="search" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm" placeholder="Kode atau materi…">
            </label>
            <label class="block text-sm font-medium text-slate-700">Filter
                <select wire:model.live="filter" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm">
                    <option value="">Semua baris</option>
                    @if($tab === 'ggb')
                        <option value="section">Bagian</option><option value="item">Butir</option>
                    @elseif($tab === 'syllabus')
                        <option value="allocation">Perlu alokasi</option><option value="duplicate">Duplikat</option><option value="1">Semester 1</option><option value="2">Semester 2</option><option value="both">Kedua semester</option>
                    @elseif($tab === 'link')
                        <option value="sesuai">Sesuai</option><option value="sebagian">Sebagian</option><option value="perlu_verifikasi">Perlu verifikasi</option>
                    @else
                        <option value="locked">Terkunci</option><option value="auto">Otomatis</option>
                    @endif
                </select>
            </label>
            <div class="self-end text-sm text-slate-500"><span class="font-mono tabular-nums">{{ number_format($rows->total()) }}</span> baris</div>
        </div>
    </section>

    <section class="sticky top-16 z-20 rounded-2xl border border-slate-200 bg-white/95 p-3 shadow-sm backdrop-blur md:top-3">
        <div class="flex flex-col gap-3 xl:flex-row xl:items-end">
            <label class="min-w-0 flex-1 text-sm font-medium text-slate-700">Alasan revisi
                <input x-model="reason" type="text" maxlength="500" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm" placeholder="Contoh: koreksi alokasi berdasarkan rapat kurikulum">
            </label>
            <div class="flex flex-wrap gap-2">
                <span class="inline-flex min-h-11 items-center rounded-xl bg-amber-50 px-3 text-sm font-semibold text-amber-900 ring-1 ring-amber-200"><span x-text="dirtyCount"></span>&nbsp;sel berubah</span>
                <button type="button" @click="fillDown()" class="button-secondary">Isi ke bawah</button>
                <button type="button" @click="clearDraft()" class="button-secondary">Batalkan draf</button>
                <button type="button" @click="save()" :disabled="saving || dirtyCount === 0" class="button-primary"><span x-text="saving ? 'Menyimpan…' : 'Simpan semua'"></span></button>
            </div>
        </div>
        <p class="mt-2 text-xs text-slate-500">Pilih baris untuk Isi ke bawah. Pintasan: panah, Enter/F2, Tab, Esc, Ctrl/Cmd+S, serta tempel TSV dari Excel.</p>
        <p x-show="clientMessage" x-text="clientMessage" class="mt-2 text-sm font-medium text-red-700" role="alert"></p>
    </section>

    @if($tab === 'link')
        <section class="panel p-5">
            <h2 class="font-semibold text-slate-950">Tambah relasi</h2>
            <p class="mt-1 text-sm text-slate-500">Gunakan kode stabil persis agar hubungan sumber tetap dapat dilacak.</p>
            <div class="mt-4 grid gap-3 lg:grid-cols-2 xl:grid-cols-5">
                <label class="text-sm font-medium">Kode GGB<input wire:model="newGgbCode" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-mono text-sm"></label>
                <label class="text-sm font-medium">Kode Silabus<input wire:model="newSyllabusCode" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-mono text-sm"></label>
                <label class="text-sm font-medium">Status<select wire:model="newRelationStatus" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"><option value="sesuai">Sesuai</option><option value="sebagian">Sebagian</option><option value="perlu_verifikasi">Perlu verifikasi</option></select></label>
                <label class="text-sm font-medium">Catatan<input wire:model="newRelationNotes" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
                <label class="text-sm font-medium">Alasan revisi<input wire:model="relationReason" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
            </div>
            @error('relation') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
            @error('relationReason') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
            <button wire:click="addRelation" wire:loading.attr="disabled" class="button-primary mt-4">Tambah relasi</button>
        </section>
    @endif

    <section class="panel hidden overflow-hidden md:block">
        <div class="spreadsheet-scroll max-h-[68vh] overflow-auto" x-ref="grid">
            <table class="spreadsheet-table" data-grid-table>
                <thead>
                <tr>
                    <th class="sticky-column w-12"><span class="sr-only">Pilih</span></th>
                    @if($tab === 'ggb')
                        <th class="sticky-column-id" aria-sort="{{ $sortField === 'stable_code' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}"><button wire:click="sortBy('stable_code')">Kode stabil</button></th><th aria-sort="{{ $sortField === 'aspect' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}"><button wire:click="sortBy('aspect')">Aspek</button></th><th aria-sort="{{ $sortField === 'subaspect' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}"><button wire:click="sortBy('subaspect')">Subaspek</button></th><th aria-sort="{{ $sortField === 'title' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}"><button wire:click="sortBy('title')">Materi</button></th><th>Target</th><th aria-sort="{{ $sortField === 'sort_order' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}"><button wire:click="sortBy('sort_order')">Urutan</button></th><th>Sumber</th>
                    @elseif($tab === 'syllabus')
                        <th class="sticky-column-id" aria-sort="{{ $sortField === 'stable_code' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}"><button wire:click="sortBy('stable_code')">Kode stabil</button></th><th aria-sort="{{ $sortField === 'category' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}"><button wire:click="sortBy('category')">Kategori</button></th><th aria-sort="{{ $sortField === 'title' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}"><button wire:click="sortBy('title')">Materi</button></th><th>Penjabaran</th><th>Alokasi</th><th aria-sort="{{ $sortField === 'recommended_sessions' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}"><button wire:click="sortBy('recommended_sessions')">Pertemuan per minggu</button></th><th>Pola jadwal</th><th>Referensi</th><th>Penilaian</th><th>Duplikat</th><th aria-sort="{{ $sortField === 'semester_scope' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}"><button wire:click="sortBy('semester_scope')">Semester efektif</button></th><th>Semester sumber</th><th aria-sort="{{ $sortField === 'sort_order' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}"><button wire:click="sortBy('sort_order')">Urutan</button></th><th>Sumber</th>
                    @elseif($tab === 'link')
                        <th class="sticky-column-id" aria-sort="{{ $sortField === 'id' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}"><button wire:click="sortBy('id')">ID</button></th><th>GGB</th><th>Silabus</th><th aria-sort="{{ $sortField === 'status' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}"><button wire:click="sortBy('status')">Status</button></th><th>Catatan</th><th>Aksi</th>
                    @else
                        <th class="sticky-column-id">ID</th><th aria-sort="{{ $sortField === 'calendar_week_id' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}"><button wire:click="sortBy('calendar_week_id')">Minggu</button></th><th aria-sort="{{ $sortField === 'strand' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}"><button wire:click="sortBy('strand')">Aspek</button></th><th>Isi</th><th aria-sort="{{ $sortField === 'position' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}"><button wire:click="sortBy('position')">Posisi</button></th><th>Terkunci</th><th>Asal / Silabus</th>
                    @endif
                </tr>
                </thead>
                <tbody>
                @forelse($rows as $row)
                    <tr data-grid-row="{{ $row->id }}">
                        <td class="sticky-column text-center"><input type="checkbox" data-row-select="{{ $row->id }}" @change="toggleRow({{ $row->id }}, $event.target.checked)" aria-label="Pilih baris {{ $row->id }}"></td>
                        @if($tab === 'ggb')
                            <td class="sticky-column-id protected-cell"><a href="{{ route('revisions.index', ['domain' => 'ggb', 'baris' => $row->id]) }}" class="font-mono text-xs text-emerald-800 hover:underline" title="Lihat riwayat baris">{{ $row->stable_code }}</a></td>
                            <td><input data-grid-cell data-field="aspect" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}" value="{{ $row->aspect }}"></td>
                            <td><input data-grid-cell data-field="subaspect" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}" value="{{ $row->subaspect }}"></td>
                            <td><textarea data-grid-cell data-field="title" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}">{{ $row->title }}</textarea></td>
                            <td><textarea data-grid-cell data-field="target_text" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}">{{ $row->target_text }}</textarea></td>
                            <td><input data-grid-cell data-field="sort_order" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}" type="number" min="1" value="{{ $row->sort_order }}"></td>
                            <td class="protected-cell text-xs">{{ $row->document?->title ?? 'Dokumen sumber' }} · hlm. {{ $row->source_page }}</td>
                        @elseif($tab === 'syllabus')
                            <td class="sticky-column-id protected-cell"><a href="{{ route('revisions.index', ['domain' => 'syllabus', 'baris' => $row->id]) }}" class="font-mono text-xs text-emerald-800 hover:underline" title="Lihat riwayat baris">{{ $row->stable_code }}</a></td>
                            <td><textarea data-grid-cell data-field="category" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}">{{ $row->category }}</textarea></td>
                            <td><textarea data-grid-cell data-field="title" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}">{{ $row->title }}</textarea></td>
                            <td><textarea data-grid-cell data-field="description" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}">{{ $row->description }}</textarea></td>
                            <td><textarea data-grid-cell data-field="allocation_text" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}">{{ $row->allocation_text }}</textarea></td>
                            <td><input data-grid-cell data-field="recommended_sessions" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}" type="number" min="1" value="{{ $row->recommended_sessions }}"></td>
                            <td><select data-grid-cell data-field="schedule_pattern" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}"><option value="weekly" @selected($row->schedule_pattern === 'weekly')>Setiap minggu efektif</option><option value="month_week_1" @selected($row->schedule_pattern === 'month_week_1')>Minggu ke-1</option><option value="month_week_2" @selected($row->schedule_pattern === 'month_week_2')>Minggu ke-2</option><option value="month_week_3" @selected($row->schedule_pattern === 'month_week_3')>Minggu ke-3</option><option value="month_week_4" @selected($row->schedule_pattern === 'month_week_4')>Minggu ke-4</option><option value="month_week_1_3" @selected($row->schedule_pattern === 'month_week_1_3')>Minggu ke-1 & 3</option><option value="month_week_2_4" @selected($row->schedule_pattern === 'month_week_2_4')>Minggu ke-2 & 4</option><option value="tentative" @selected($row->schedule_pattern === 'tentative')>Tentatif / manual</option><option value="unknown" @selected($row->schedule_pattern === 'unknown')>Perlu pola jadwal</option></select></td>
                            <td><textarea data-grid-cell data-field="reference_text" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}">{{ $row->reference_text }}</textarea></td>
                            <td><textarea data-grid-cell data-field="assessment_text" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}">{{ $row->assessment_text }}</textarea></td>
                            <td><select data-grid-cell data-field="is_duplicate" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}"><option value="0" @selected(!$row->is_duplicate)>Tidak</option><option value="1" @selected($row->is_duplicate)>Ya</option></select></td>
                            <td><select data-grid-cell data-field="semester_scope" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}"><option value="1" @selected($row->semester_scope === '1')>Semester 1</option><option value="2" @selected($row->semester_scope === '2')>Semester 2</option><option value="both" @selected($row->semester_scope === 'both')>Keduanya</option></select></td>
                            <td class="protected-cell text-xs">{{ $row->source_semester === 'both' ? 'Keduanya' : 'Semester '.$row->source_semester }}</td>
                            <td><input data-grid-cell data-field="sort_order" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}" type="number" min="1" value="{{ $row->sort_order }}"></td>
                            <td class="protected-cell text-xs">{{ $row->document?->title ?? 'Dokumen sumber' }} · hlm. {{ $row->source_page }}</td>
                        @elseif($tab === 'link')
                            <td class="sticky-column-id protected-cell font-mono text-xs"><a href="{{ route('revisions.index', ['domain' => 'link', 'baris' => $row->id]) }}" class="text-emerald-800 hover:underline" title="Lihat riwayat baris">#{{ $row->id }}</a></td>
                            <td class="protected-cell"><span class="font-mono text-xs">{{ $row->ggbItem->stable_code }}</span><p>{{ $row->ggbItem->title }}</p></td>
                            <td class="protected-cell"><span class="font-mono text-xs">{{ $row->syllabusItem->stable_code }}</span><p>{{ $row->syllabusItem->title }}</p></td>
                            <td><select data-grid-cell data-field="status" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}"><option value="sesuai" @selected($row->status === 'sesuai')>Sesuai</option><option value="sebagian" @selected($row->status === 'sebagian')>Sebagian</option><option value="perlu_verifikasi" @selected($row->status === 'perlu_verifikasi')>Perlu verifikasi</option></select></td>
                            <td><textarea data-grid-cell data-field="notes" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}">{{ $row->notes }}</textarea></td>
                            <td><button type="button" wire:click="deleteRelation({{ $row->id }})" wire:confirm="Arsipkan relasi ini? Isi alasan revisi relasi terlebih dahulu." class="min-h-10 text-sm font-semibold text-red-700">Arsipkan</button></td>
                        @else
                            <td class="sticky-column-id protected-cell font-mono text-xs"><a href="{{ route('revisions.index', ['domain' => 'rpp', 'baris' => $row->id]) }}" class="text-emerald-800 hover:underline" title="Lihat riwayat baris">#{{ $row->id }}</a></td>
                            <td><select data-grid-cell data-field="calendar_week_id" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}">@foreach($effectiveWeeks as $week)<option value="{{ $week->id }}" @selected($row->calendar_week_id === $week->id)>M{{ $week->week_number }} · {{ $week->starts_on->format('d M Y') }}</option>@endforeach</select></td>
                            <td><textarea data-grid-cell data-field="strand" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}">{{ $row->strand }}</textarea></td>
                            <td><textarea data-grid-cell data-field="content" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}">{{ $row->content }}</textarea></td>
                            <td><input data-grid-cell data-field="position" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}" type="number" min="1" value="{{ $row->position }}"></td>
                            <td><select data-grid-cell data-field="is_locked" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}"><option value="0" @selected(!$row->is_locked)>Tidak</option><option value="1" @selected($row->is_locked)>Ya</option></select></td>
                            <td class="protected-cell text-xs"><strong>{{ ucfirst($row->source) }}</strong><br>{{ $row->syllabusItem?->stable_code }}</td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="12" class="px-5 py-12 text-center text-slate-500">Tidak ada baris yang cocok.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="space-y-3 md:hidden" aria-label="Daftar untuk ponsel">
        @forelse($rows as $row)
            <details class="panel group p-4">
                <summary class="flex cursor-pointer list-none items-start justify-between gap-3 font-semibold text-slate-950">
                    <span>{{ $tab === 'ggb' || $tab === 'syllabus' ? $row->stable_code : '#'.$row->id }}</span><span class="text-sm text-emerald-700">Edit baris</span>
                </summary>
                <div class="mobile-row-editor mt-4 space-y-3">
                    @if($tab === 'ggb')
                        @foreach(['aspect'=>'Aspek','subaspect'=>'Subaspek','title'=>'Materi','target_text'=>'Target','sort_order'=>'Urutan'] as $field => $label)<label>{{ $label }}<textarea data-grid-cell data-field="{{ $field }}" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}">{{ $row->{$field} }}</textarea></label>@endforeach
                    @elseif($tab === 'syllabus')
                        @foreach(['category'=>'Kategori','title'=>'Materi','description'=>'Penjabaran','allocation_text'=>'Alokasi','recommended_sessions'=>'Pertemuan per minggu','reference_text'=>'Referensi','assessment_text'=>'Penilaian','sort_order'=>'Urutan'] as $field => $label)<label>{{ $label }}<textarea data-grid-cell data-field="{{ $field }}" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}">{{ $row->{$field} }}</textarea></label>@endforeach<label>Pola jadwal<select data-grid-cell data-field="schedule_pattern" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}"><option value="weekly" @selected($row->schedule_pattern === 'weekly')>Setiap minggu efektif</option><option value="month_week_1" @selected($row->schedule_pattern === 'month_week_1')>Minggu ke-1</option><option value="month_week_2" @selected($row->schedule_pattern === 'month_week_2')>Minggu ke-2</option><option value="month_week_3" @selected($row->schedule_pattern === 'month_week_3')>Minggu ke-3</option><option value="month_week_4" @selected($row->schedule_pattern === 'month_week_4')>Minggu ke-4</option><option value="month_week_1_3" @selected($row->schedule_pattern === 'month_week_1_3')>Minggu ke-1 & 3</option><option value="month_week_2_4" @selected($row->schedule_pattern === 'month_week_2_4')>Minggu ke-2 & 4</option><option value="tentative" @selected($row->schedule_pattern === 'tentative')>Tentatif / manual</option><option value="unknown" @selected($row->schedule_pattern === 'unknown')>Perlu pola jadwal</option></select></label><label>Duplikat<select data-grid-cell data-field="is_duplicate" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}"><option value="0" @selected(!$row->is_duplicate)>Tidak</option><option value="1" @selected($row->is_duplicate)>Ya</option></select></label><label>Semester efektif<select data-grid-cell data-field="semester_scope" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}"><option value="1" @selected($row->semester_scope === '1')>Semester 1</option><option value="2" @selected($row->semester_scope === '2')>Semester 2</option><option value="both" @selected($row->semester_scope === 'both')>Keduanya</option></select></label><p class="text-xs text-slate-500">Semester sumber: {{ $row->source_semester === 'both' ? 'Keduanya' : $row->source_semester }}</p>
                    @elseif($tab === 'link')
                        <p class="text-sm text-slate-600">{{ $row->ggbItem->title }} → {{ $row->syllabusItem->title }}</p><label>Status<select data-grid-cell data-field="status" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}"><option value="sesuai" @selected($row->status === 'sesuai')>Sesuai</option><option value="sebagian" @selected($row->status === 'sebagian')>Sebagian</option><option value="perlu_verifikasi" @selected($row->status === 'perlu_verifikasi')>Perlu verifikasi</option></select></label><label>Catatan<textarea data-grid-cell data-field="notes" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}">{{ $row->notes }}</textarea></label>
                    @else
                        <label>Minggu<select data-grid-cell data-field="calendar_week_id" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}">@foreach($effectiveWeeks as $week)<option value="{{ $week->id }}" @selected($row->calendar_week_id === $week->id)>M{{ $week->week_number }} · {{ $week->starts_on->format('d M Y') }}</option>@endforeach</select></label>@foreach(['strand'=>'Aspek','content'=>'Isi','position'=>'Posisi'] as $field => $label)<label>{{ $label }}<textarea data-grid-cell data-field="{{ $field }}" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}">{{ $row->{$field} }}</textarea></label>@endforeach<label>Terkunci<select data-grid-cell data-field="is_locked" data-id="{{ $row->id }}" data-version="{{ $row->lock_version }}"><option value="0" @selected(!$row->is_locked)>Tidak</option><option value="1" @selected($row->is_locked)>Ya</option></select></label>
                    @endif
                </div>
            </details>
        @empty
            <div class="panel p-8 text-center text-slate-500">Tidak ada baris yang cocok.</div>
        @endforelse
    </section>

    <div>{{ $rows->links() }}</div>
</div>
