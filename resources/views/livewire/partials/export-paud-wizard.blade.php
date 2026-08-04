<section id="paud-completion-guide" class="panel overflow-hidden" aria-labelledby="paud-guide-title">
    <div class="border-b border-slate-200 bg-gradient-to-r from-emerald-950 to-emerald-800 p-4 text-white sm:p-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm font-semibold text-emerald-200">Panduan bertahap · Tahun ajaran {{ $plan->academicYear->label }}</p>
                <h2 id="paud-guide-title" class="mt-1 text-xl font-semibold">RPP PAUD sampai lengkap 100%</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-emerald-50">Selesaikan lima pemeriksaan berikut secara berurutan. Sistem tidak menimpa materi manual atau yang sudah dikunci.</p>
            </div>
            <div class="min-w-48 rounded-xl bg-white/10 p-3 ring-1 ring-white/20">
                <div class="flex items-end justify-between gap-3"><span class="text-sm text-emerald-100">Kelengkapan keseluruhan</span><strong class="font-mono text-2xl">{{ $completionReport['percent'] }}%</strong></div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-emerald-950/60" role="progressbar" aria-label="Kelengkapan RPP PAUD" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $completionReport['percent'] }}"><div class="h-full rounded-full bg-emerald-300" style="width: {{ $completionReport['percent'] }}%"></div></div>
                <p class="mt-2 text-xs text-emerald-100">{{ $completionReport['completed_steps'] }}/{{ $completionReport['total_steps'] }} langkah selesai</p>
            </div>
        </div>
    </div>

    @if($completionReport['complete'])
        <div class="border-b border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900 sm:px-5" role="status">RPP PAUD lengkap 100%: kalender, GGB tahunan, Silabus, Tilawati 44/44, dan kedua semester sudah tervalidasi.</div>
    @endif

    <ol class="divide-y divide-slate-200">
        @foreach($completionReport['steps'] as $index => $step)
            @php($diagnostics = $step['diagnostics'] ?? [])
            <li class="grid gap-3 p-4 sm:p-5 lg:grid-cols-[44px_minmax(0,1fr)_auto] lg:items-center">
                <span @class(['flex size-11 items-center justify-center rounded-full text-sm font-bold ring-1', 'bg-emerald-100 text-emerald-800 ring-emerald-300' => $step['complete'], 'bg-amber-50 text-amber-900 ring-amber-300' => ! $step['complete']]) aria-hidden="true">{{ $index + 1 }}</span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2"><h3 class="font-semibold text-slate-950">{{ $step['label'] }}</h3><span class="status {{ $step['complete'] ? 'status-success' : 'status-warning' }}">{{ $step['complete'] ? 'Selesai' : 'Perlu tindakan' }}</span></div>
                    <p class="mt-1 text-sm leading-6 {{ $step['complete'] ? 'text-slate-600' : 'text-amber-900' }}">{{ $step['summary'] }}</p>
                </div>
                <div class="flex flex-wrap gap-2 lg:justify-end">
                    @if($step['action'] === 'calendar')
                        <a href="{{ route('exports.index', ['level' => $plan->level_id, 'semester' => $semester, 'detail' => 'calendar']) }}#calendar-detail" class="button-secondary">Atur Waktu</a>
                    @elseif($step['key'] === 'ggb_confirmation')
                        @if($completionReport['ggb']['needs_semester'] > 0)
                            <a href="{{ route('exports.index', ['level' => $plan->level_id, 'semester' => $semester, 'detail' => 'ggb', 'ggb_status' => 'semester']) }}#ggb-detail" class="button-secondary">Lihat {{ $completionReport['ggb']['needs_semester'] }} Perlu Semester</a>
                        @endif
                        @if($completionReport['ggb']['needs_mapping'] > 0)
                            <a href="{{ route('exports.index', ['level' => $plan->level_id, 'semester' => $semester, 'detail' => 'ggb', 'ggb_status' => 'mapping']) }}#ggb-detail" class="button-secondary">Lihat {{ $completionReport['ggb']['needs_mapping'] }} Materi</a>
                        @endif
                        @if($completionReport['ggb']['needs_semester'] === 0 && $completionReport['ggb']['needs_mapping'] === 0)
                            <a href="{{ route('exports.index', ['level' => $plan->level_id, 'semester' => $semester, 'detail' => 'ggb']) }}#ggb-detail" class="button-secondary">Buka Daftar GGB</a>
                        @endif
                    @elseif($step['key'] === 'annual_ggb')
                        @if(($diagnostics['ggb_missing'] ?? 0) > 0)
                            <a href="{{ route('exports.index', ['level' => $plan->level_id, 'semester' => $semester, 'detail' => 'ggb', 'ggb_status' => 'missing']) }}#ggb-detail" class="button-secondary">Lihat {{ $diagnostics['ggb_missing'] }} Belum Masuk</a>
                        @elseif($diagnostics['annual_validation_pending'] ?? false)
                            <button type="button" wire:click="validateAnnualGgb" wire:loading.attr="disabled" wire:target="validateAnnualGgb" class="button-secondary"><span wire:loading.remove wire:target="validateAnnualGgb">Validasi GGB Tahunan</span><span wire:loading wire:target="validateAnnualGgb">Memvalidasi…</span></button>
                        @else
                            <a href="{{ route('exports.index', ['level' => $plan->level_id, 'semester' => $semester, 'detail' => 'ggb']) }}#ggb-detail" class="button-secondary">Buka Daftar GGB</a>
                        @endif
                    @elseif($step['action'] === 'semester_1' || $step['action'] === 'semester_2')
                        @php($stepSemester = $step['action'] === 'semester_1' ? 1 : 2)
                        <a href="{{ route('exports.index', ['level' => $plan->level_id, 'semester' => $stepSemester]) }}#preview-heading" class="button-secondary">Buka Semester {{ $stepSemester }}</a>
                        @if(($diagnostics['syllabus_missing'] ?? 0) > 0)
                            <a href="{{ route('planner.show', ['level' => $plan->level_id, 'semester' => $stepSemester, 'detail' => 'unplanned']) }}#planner-detail" class="button-secondary">Lihat {{ $diagnostics['syllabus_missing'] }} Silabus</a>
                        @endif
                        @if(($diagnostics['target_issue_count'] ?? 0) > 0)
                            <a href="{{ route('exports.index', ['level' => $plan->level_id, 'semester' => $stepSemester, 'focus' => 'targets']) }}#target-editor" class="button-secondary">Perbaiki {{ $diagnostics['target_issue_count'] }} Target</a>
                        @endif
                        @if(($diagnostics['validation_pending'] ?? false) && ($diagnostics['can_validate'] ?? false))
                            <button type="button" wire:click="validatePaudSemester({{ $stepSemester }})" wire:loading.attr="disabled" wire:target="validatePaudSemester({{ $stepSemester }})" class="button-secondary"><span wire:loading.remove wire:target="validatePaudSemester({{ $stepSemester }})">Validasi Semester {{ $stepSemester }}</span><span wire:loading wire:target="validatePaudSemester({{ $stepSemester }})">Memeriksa…</span></button>
                        @endif
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
</section>
