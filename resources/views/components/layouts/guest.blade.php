@props(['title' => null])
<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · ' : '' }}MotionForge</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-full place-items-center bg-[radial-gradient(ellipse_at_top,_#1e1b4b_0%,_#0B1020_55%)] p-4">
    <main class="w-full max-w-md">
        <div class="mb-6 flex items-center justify-center gap-3">
            <span class="grid size-11 place-items-center rounded-xl bg-gradient-to-br from-primary to-secondary text-white"><i data-lucide="clapperboard" class="size-6"></i></span>
            <div><div class="text-lg font-semibold">MotionForge</div><div class="text-xs text-muted">Create. Animate. Render.</div></div>
        </div>
        <div class="card card-pad shadow-2xl shadow-black/40">
            @if ($errors->any())<div class="mb-4 rounded-lg border border-error/30 bg-error/10 px-3 py-2 text-sm text-red-200" role="alert">{{ $errors->first() }}</div>@endif
            {{ $slot }}
        </div>
    </main>
</body>
</html>
