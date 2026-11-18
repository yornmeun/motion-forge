{{-- Form button with a confirmation dialog. --}}
@props(['action', 'method' => 'POST', 'label', 'message' => 'Are you sure?', 'class' => 'btn-danger btn-sm', 'icon' => null, 'confirm' => 'Confirm'])
<div x-data="{ open: false }" class="inline">
    <button type="button" class="{{ $class }}" @click="open = true">@if($icon)<i data-lucide="{{ $icon }}" class="size-4"></i>@endif{{ $label }}</button>
    <div x-show="open" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-black/60 p-4" @keydown.escape.window="open = false" @click.self="open = false" role="dialog" aria-modal="true">
        <div class="card card-pad w-full max-w-md text-left">
            <h2 class="mb-2">{{ $label }}</h2>
            <p class="text-sm text-muted">{{ $message }}</p>
            <form method="POST" action="{{ $action }}" class="mt-5 flex justify-end gap-2">
                @csrf @if (strtoupper($method) !== 'POST') @method($method) @endif
                {{ $slot }}
                <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
                <button class="btn-primary" x-init="$watch('open', v => v && $nextTick(() => $el.focus()))">{{ $confirm }}</button>
            </form>
        </div>
    </div>
</div>
