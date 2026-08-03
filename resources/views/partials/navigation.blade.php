@php
    $links = [
        ['route' => 'dashboard', 'match' => 'dashboard', 'label' => 'Dashboard'],
        ['route' => 'curriculum.index', 'match' => 'curriculum.*', 'label' => 'Master Kurikulum'],
        ['route' => 'revisions.index', 'match' => 'revisions.*', 'label' => 'Riwayat Revisi'],
        ['route' => 'audit.index', 'match' => 'audit.*', 'label' => 'Audit Materi'],
        ['route' => 'calendar.index', 'match' => 'calendar.*', 'label' => 'Kalender Akademik'],
        ['route' => 'exports.index', 'match' => 'exports.*', 'label' => 'Ekspor Excel'],
    ];
@endphp
<div class="space-y-1">
    @foreach ($links as $link)
        <a href="{{ route($link['route']) }}" wire:navigate @if(request()->routeIs($link['match'])) aria-current="page" @endif class="flex min-h-11 items-center rounded-xl px-3 py-2 text-sm font-semibold {{ request()->routeIs($link['match']) ? 'bg-emerald-50 text-emerald-800' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950' }}">
            {{ $link['label'] }}
        </a>
    @endforeach
</div>
