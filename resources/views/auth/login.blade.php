<x-layouts.guest title="Sign in">
    <h1 class="text-xl">Sign in</h1>
    <p class="mt-1 text-sm text-muted">Welcome back to your studio.</p>
    <form method="POST" action="{{ route('login.store') }}" class="mt-6 grid gap-4">
        @csrf
        <div><label class="label" for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" class="input"></div>
        <div><label class="label" for="password">Password</label><input id="password" name="password" type="password" required autocomplete="current-password" class="input"></div>
        <label class="flex items-center gap-2 text-sm text-muted"><input type="checkbox" name="remember" class="accent-primary"> Keep me signed in</label>
        <button class="btn-primary w-full">Sign in</button>
    </form>
    <p class="mt-5 text-center text-sm text-muted">No account? <a class="text-indigo-300 hover:underline" href="{{ route('register') }}">Create one</a></p>
</x-layouts.guest>
