<section id="activity-bank" class="panel scroll-mt-24 overflow-hidden" aria-labelledby="activity-bank-title">
    <div class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
        <div><p class="text-sm font-semibold text-emerald-700">Katalog reusable</p><h2 id="activity-bank-title" class="mt-1 text-xl font-semibold text-slate-950">Atur Bank Kegiatan</h2><p class="mt-1 text-sm text-slate-600">Kegiatan aktif yang dirotasi mengisi kolom tujuan pada minggu efektif. Kata “dll” tidak dibuat sebagai kegiatan.</p></div>
        <button type="button" wire:click="showDetail('')" class="button-secondary">Tutup detail</button>
    </div>
    <form wire:submit="createActivity" class="grid gap-3 border-b border-slate-200 bg-slate-50 p-4 lg:grid-cols-[minmax(220px,1fr)_minmax(180px,.7fr)_150px_150px_minmax(220px,1fr)_auto] lg:items-end sm:p-5">
        <label class="grid gap-1 text-sm font-medium text-slate-700">Nama kegiatan<input wire:model="activityTitle" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3" placeholder="Contoh: Pramuka"></label>
        <label class="grid gap-1 text-sm font-medium text-slate-700">Kolom RPP<select wire:model="activityColumnId" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"><option value="">Pilih kolom</option>@foreach($layoutColumns->where('is_active', true) as $column)<option value="{{ $column->id }}">{{ $column->aspect_label }} · {{ $column->label }}</option>@endforeach</select></label>
        <label class="grid gap-1 text-sm font-medium text-slate-700">Semester<select wire:model="activitySemester" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3"><option value="both">Keduanya</option><option value="1">Semester 1</option><option value="2">Semester 2</option></select></label>
        <label class="flex min-h-11 items-center gap-2 rounded-xl border border-slate-300 bg-white px-3 text-sm font-medium"><input wire:model="activityRotation" type="checkbox" class="size-5 rounded text-emerald-700"> Rotasi otomatis</label>
        <label class="grid gap-1 text-sm font-medium text-slate-700">Alasan tindakan<input wire:model="activityReason" class="min-h-11 rounded-xl border border-slate-300 bg-white px-3" placeholder="Minimal 5 karakter"></label>
        <button type="submit" class="button-primary">Tambah Kegiatan</button>
    </form>
    <div class="overflow-x-auto">
        <table class="spreadsheet-table min-w-[980px]" data-grid-table>
            <thead><tr><th>Kode</th><th>Nama kegiatan</th><th>Kolom RPP</th><th>Semester</th><th>Urutan</th><th>Aktif</th><th>Rotasi</th></tr></thead>
            <tbody>@forelse($activities as $activity)<tr data-grid-row wire:key="activity-{{ $activity->id }}">
                <td class="protected-cell font-mono text-xs">{{ $activity->display_code }}</td>
                <td><input data-grid-cell data-domain="material_catalog" data-id="{{ $activity->id }}" data-version="{{ $activity->lock_version }}" data-field="title" data-original="{{ $activity->title }}" value="{{ $activity->title }}"></td>
                <td><select data-grid-cell data-domain="material_catalog" data-id="{{ $activity->id }}" data-version="{{ $activity->lock_version }}" data-field="rpp_matrix_column_id" data-original="{{ $activity->rpp_matrix_column_id }}">@foreach($layoutColumns->where('is_active', true) as $column)<option value="{{ $column->id }}" @selected($activity->rpp_matrix_column_id === $column->id)>{{ $column->label }}</option>@endforeach</select></td>
                <td><select data-grid-cell data-domain="material_catalog" data-id="{{ $activity->id }}" data-version="{{ $activity->lock_version }}" data-field="semester_scope" data-original="{{ $activity->semester_scope }}"><option value="both" @selected($activity->semester_scope === 'both')>Keduanya</option><option value="1" @selected($activity->semester_scope === '1')>Semester 1</option><option value="2" @selected($activity->semester_scope === '2')>Semester 2</option></select></td>
                <td><input type="number" min="1" data-grid-cell data-domain="material_catalog" data-id="{{ $activity->id }}" data-version="{{ $activity->lock_version }}" data-field="sort_order" data-original="{{ $activity->sort_order }}" value="{{ $activity->sort_order }}"></td>
                <td><select data-grid-cell data-domain="material_catalog" data-id="{{ $activity->id }}" data-version="{{ $activity->lock_version }}" data-field="is_active" data-original="{{ $activity->is_active ? 1 : 0 }}"><option value="1" @selected($activity->is_active)>Aktif</option><option value="0" @selected(! $activity->is_active)>Nonaktif</option></select></td>
                <td><select data-grid-cell data-domain="material_catalog" data-id="{{ $activity->id }}" data-version="{{ $activity->lock_version }}" data-field="rotation_enabled" data-original="{{ $activity->rotation_enabled ? 1 : 0 }}"><option value="1" @selected($activity->rotation_enabled)>Ya</option><option value="0" @selected(! $activity->rotation_enabled)>Tidak</option></select></td>
            </tr>@empty<tr><td colspan="7" class="p-8 text-center text-slate-500">Belum ada Bank Kegiatan. Tambahkan kegiatan yang dapat dipakai berulang.</td></tr>@endforelse</tbody>
        </table>
    </div>
</section>
