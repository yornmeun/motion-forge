<x-layouts.app title="Profile">
    <x-slot:header><div class="text-sm text-muted">Settings › <span class="text-text">Profile</span></div></x-slot:header>
    <form method="POST" action="{{ route('settings.profile.update') }}" class="card card-pad grid max-w-lg gap-4">@csrf @method('PUT')
        <h1>Profile</h1>
        <div><label class="label">Name</label><input name="name" value="{{ $user->name }}" required class="input"></div>
        <div><label class="label">Email</label><input name="email" type="email" value="{{ $user->email }}" required class="input"></div>
        <div><span class="label">Role</span>{{ $user->role?->name ?? 'None' }}</div>
        <div><button class="btn-primary">Save</button></div>
    </form>
</x-layouts.app>
