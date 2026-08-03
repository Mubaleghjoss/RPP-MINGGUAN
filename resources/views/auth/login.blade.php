<!DOCTYPE html>
<html lang="id" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk · Sistem RPP PPG</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-dvh place-items-center px-4 py-10">
    <main class="w-full max-w-md">
        <div class="mb-7">
            <p class="text-sm font-semibold text-emerald-700">RPP PPG 2026/2027</p>
            <h1 class="mt-2 text-3xl font-semibold text-balance text-slate-950">Masuk ke ruang kerja kurikulum</h1>
            <p class="mt-2 text-pretty text-slate-600">Kelola alur GGB, silabus, kalender, dan RPP global dalam satu tempat.</p>
        </div>
        <form method="POST" action="{{ route('login.store') }}" class="panel space-y-5 p-6">@csrf
            <div>
                <label for="email" class="mb-1.5 block text-sm font-semibold text-slate-700">Email Admin</label>
                <input id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" required autofocus class="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-slate-950">
                @error('email')<p class="mt-1.5 text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="password" class="mb-1.5 block text-sm font-semibold text-slate-700">Kata Sandi</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required class="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-slate-950">
                @error('password')<p class="mt-1.5 text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
            </div>
            <label class="flex min-h-11 items-center gap-3 text-sm text-slate-600"><input type="checkbox" name="remember" value="1" class="size-4 rounded border-slate-300 text-emerald-700"> Ingat sesi masuk</label>
            <button class="button-primary w-full">Masuk</button>
        </form>
    </main>
</body>
</html>
