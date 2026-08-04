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
@php
    $initialNotifications = collect([
        session('status') ? ['type' => 'success', 'title' => 'Berhasil', 'message' => session('status')] : null,
        session('notice') ? ['type' => 'info', 'title' => 'Informasi', 'message' => session('notice')] : null,
        session('error') ? ['type' => 'error', 'title' => 'Tindakan gagal', 'message' => session('error')] : null,
    ])->filter()->values()->all();
@endphp
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

    <section
        x-data="persistentNotifications({{ Js::from($initialNotifications) }})"
        x-on:app-notification.window="push($event.detail.notification ?? $event.detail)"
        class="pointer-events-none fixed inset-x-4 top-20 z-[70] max-h-[calc(100dvh-6rem)] overflow-y-auto overscroll-contain md:left-[17rem] md:right-auto md:top-4 md:w-[min(30rem,calc(100vw-18rem))]"
        aria-label="Pusat notifikasi"
    >
        <div class="grid gap-3">
            <template x-for="item in items" :key="item.id">
                <article
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="-translate-y-2 opacity-0"
                    x-transition:enter-end="translate-y-0 opacity-100"
                    class="pointer-events-auto overflow-hidden rounded-2xl border bg-white shadow-xl"
                    :class="{
                        'border-emerald-300': item.type === 'success',
                        'border-red-300': item.type === 'error',
                        'border-amber-300': item.type === 'warning',
                        'border-sky-300': item.type === 'info',
                    }"
                    :role="item.type === 'error' ? 'alert' : 'status'"
                    :aria-live="item.type === 'error' ? 'assertive' : 'polite'"
                >
                    <div class="flex items-start gap-3 p-4">
                        <div
                            class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full text-sm font-bold"
                            :class="{
                                'bg-emerald-100 text-emerald-800': item.type === 'success',
                                'bg-red-100 text-red-800': item.type === 'error',
                                'bg-amber-100 text-amber-900': item.type === 'warning',
                                'bg-sky-100 text-sky-800': item.type === 'info',
                            }"
                            aria-hidden="true"
                        >
                            <svg x-show="item.type === 'success'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-5"><path d="M5 12l4 4L19 6"/></svg>
                            <svg x-show="item.type === 'error' || item.type === 'warning'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-5"><path d="M12 8v5m0 3h.01"/><path d="M10.3 3.8 2.5 17.3A2 2 0 0 0 4.2 20h15.6a2 2 0 0 0 1.7-2.7L13.7 3.8a2 2 0 0 0-3.4 0Z"/></svg>
                            <svg x-show="item.type === 'info'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-5"><circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8h.01"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500" x-text="item.type === 'success' ? 'Berhasil' : (item.type === 'error' ? 'Gagal' : (item.type === 'warning' ? 'Perlu perhatian' : 'Informasi'))"></p>
                                    <h2 class="mt-0.5 font-semibold text-slate-950" x-text="item.title"></h2>
                                </div>
                                <button type="button" @click="dismiss(item.id)" class="inline-flex size-11 shrink-0 items-center justify-center rounded-xl text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-950" aria-label="Tutup notifikasi">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-5" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                                </button>
                            </div>
                            <p class="mt-1 text-sm leading-6 text-slate-700" x-text="item.message"></p>

                            <details x-show="item.details.length || item.suggestions.length || item.reference" class="mt-3 rounded-xl bg-slate-50 px-3 py-2 ring-1 ring-slate-200">
                                <summary class="min-h-10 cursor-pointer py-2 text-sm font-semibold text-slate-700" x-text="item.type === 'success' ? 'Lihat hasil dan langkah berikutnya' : (item.type === 'error' ? 'Lihat penyebab dan cara mengatasi' : 'Lihat rincian')"></summary>
                                <div class="pb-2 text-sm leading-6 text-slate-700">
                                    <template x-if="item.details.length">
                                        <div><p class="font-semibold text-slate-900" x-text="item.type === 'success' ? 'Hasil perubahan' : (item.type === 'error' ? 'Penyebab' : 'Rincian')"></p><ul class="mt-1 list-disc space-y-1 pl-5"><template x-for="detail in item.details"><li x-text="detail"></li></template></ul></div>
                                    </template>
                                    <template x-if="item.suggestions.length">
                                        <div class="mt-3"><p class="font-semibold text-slate-900" x-text="item.type === 'success' ? 'Langkah berikutnya' : (item.type === 'error' ? 'Agar berhasil' : 'Saran')"></p><ul class="mt-1 list-disc space-y-1 pl-5"><template x-for="suggestion in item.suggestions"><li x-text="suggestion"></li></template></ul></div>
                                    </template>
                                    <p x-show="item.reference" class="mt-3 font-mono text-xs text-slate-600">Kode referensi: <span x-text="item.reference"></span></p>
                                </div>
                            </details>

                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <button x-show="item.focusField" type="button" @click="focusProblem(item)" class="button-secondary min-h-10 px-3 py-1.5">Buka bagian bermasalah</button>
                                <button x-show="item.details.length || item.suggestions.length || item.reference" type="button" @click="copyDetails(item)" class="min-h-10 rounded-lg px-3 text-sm font-semibold text-slate-600 hover:bg-slate-100 hover:text-slate-950" x-text="item.copied ? 'Tersalin' : 'Salin detail'"></button>
                                <time class="ml-auto font-mono text-[11px] text-slate-500" x-text="item.createdAt"></time>
                            </div>
                        </div>
                    </div>
                </article>
            </template>
        </div>
    </section>

    <main id="main-content" tabindex="-1" class="md:pl-64">
        <div class="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            {{ $slot ?? '' }}
            @yield('content')
        </div>
    </main>
</div>
@livewireScripts
</body>
</html>
