<!DOCTYPE html>
<html lang="id" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? trim($__env->yieldContent('title')) ?: 'Sistem RPP PPG' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
<a href="#main-content" class="sr-only z-50 rounded-lg bg-white px-4 py-3 font-semibold text-emerald-800 shadow-lg focus:not-sr-only focus:fixed focus:left-4 focus:top-4">Lewati ke konten utama</a>
<div x-data="{ open: false }" class="min-h-dvh">
    <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 md:hidden">
        <div class="flex min-h-16 items-center justify-between px-4">
            <div>
                <p class="font-semibold text-slate-950">RPP PPG</p>
                <p class="text-xs text-slate-500">GGB → Silabus → RPP</p>
            </div>
            <button type="button" @click="open = !open" class="button-secondary" aria-label="Buka atau tutup navigasi">Menu</button>
        </div>
        <nav x-show="open" x-cloak class="border-t border-slate-200 p-3" aria-label="Navigasi seluler">
            @include('partials.navigation')
            <form method="POST" action="{{ route('logout') }}" class="mt-3 border-t border-slate-200 pt-3">@csrf
                <button class="button-secondary w-full text-red-700">Keluar</button>
            </form>
        </nav>
    </header>

    <aside class="fixed inset-y-0 left-0 z-20 hidden w-64 border-r border-slate-200 bg-white md:flex md:flex-col">
        <div class="border-b border-slate-200 px-6 py-6">
            <p class="text-xl font-semibold text-balance text-slate-950">Sistem RPP PPG</p>
            <p class="mt-1 text-sm text-pretty text-slate-500">Peta kurikulum global mingguan</p>
        </div>
        <nav class="flex-1 overflow-y-auto p-3" aria-label="Navigasi utama">
            @include('partials.navigation')
        </nav>
        <div class="border-t border-slate-200 p-4">
            <p class="truncate text-sm font-medium text-slate-800">{{ auth()->user()->name }}</p>
            <form method="POST" action="{{ route('logout') }}" class="mt-2">@csrf
                <button class="min-h-10 text-sm font-semibold text-slate-600 hover:text-red-700">Keluar</button>
            </form>
        </div>
    </aside>

    <main id="main-content" tabindex="-1" class="md:pl-64">
        <div class="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            @if (session('status'))
                <div class="mb-5 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 ring-1 ring-emerald-200">{{ session('status') }}</div>
            @endif
            {{ $slot ?? '' }}
            @yield('content')
        </div>
    </main>
</div>
@livewireScripts
</body>
</html>
