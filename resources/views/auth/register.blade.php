<x-layouts.guest title="Create account">
    <h1 class="text-xl">Create your account</h1>
    <p class="mt-1 text-sm text-muted">The first account becomes the administrator.</p>
    <form method="POST" action="{{ route('register.store') }}" class="mt-6 grid gap-4">
        @csrf
        <div><label class="label" for="name">Name</label><input id="name" name="name" value="{{ old('name') }}" required autofocus class="input"></div>
        <div><label class="label" for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email" class="input"></div>
        <div><label class="label" for="password">Password</label><input id="password" name="password" type="password" required minlength="12" autocomplete="new-password" class="input"><p class="hint">At least 12 characters.</p></div>
        <div><label class="label" for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="input"></div>
        <button class="btn-primary w-full">Create account</button>
    </form>
    <p class="mt-5 text-center text-sm text-muted">Already registered? <a class="text-indigo-300 hover:underline" href="{{ route('login') }}">Sign in</a></p>
</x-layouts.guest>
