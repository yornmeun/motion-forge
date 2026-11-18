<x-layouts.app title="Security">
    <x-slot:header><div class="text-sm text-muted">Settings › <span class="text-text">Security</span></div></x-slot:header>
    <div class="grid max-w-3xl gap-6">
        <form method="POST" action="{{ route('settings.security.update') }}" class="card card-pad grid gap-4">@csrf @method('PUT')
            <h1>Change password</h1>
            <div><label class="label">Current password</label><input name="current_password" type="password" required autocomplete="current-password" class="input"></div>
            <div><label class="label">New password</label><input name="password" type="password" required minlength="12" autocomplete="new-password" class="input"></div>
            <div><label class="label">Confirm new password</label><input name="password_confirmation" type="password" required autocomplete="new-password" class="input"></div>
            <div><button class="btn-primary">Change password</button></div>
        </form>
        <section class="card card-pad"><h2 class="mb-3">Active sessions</h2>
            <ul class="grid gap-2 text-sm">@foreach ($sessions as $s)<li class="flex justify-between gap-4"><span class="truncate text-muted">{{ \Illuminate\Support\Str::limit($s->user_agent, 80) }}</span><span class="whitespace-nowrap text-xs">{{ $s->ip_address }} · {{ \Illuminate\Support\Carbon::createFromTimestamp($s->last_activity)->diffForHumans() }}@if($s->id === session()->getId()) · this device @endif</span></li>@endforeach</ul>
            <p class="hint mt-3">Changing your password signs out all other sessions.</p></section>
        <section class="card card-pad text-sm text-muted"><h2 class="mb-2 text-text">How MotionForge protects your keys and media</h2>
            <ul class="grid list-disc gap-1 pl-5">
                <li>API keys are encrypted with the application key and never sent to the browser or written to logs.</li>
                <li>Provider endpoints are fixed in server configuration; no user-supplied URLs are requested.</li>
                <li>AI-generated animation code is validated against an allowlist and runs only in a sandboxed headless browser (no network, no eval, time limits, no server environment variables).</li>
                <li>Uploads, renders and deliveries are private and served only to authorised users.</li>
            </ul></section>
    </div>
</x-layouts.app>
