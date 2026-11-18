@props(['icon' => 'inbox', 'title', 'text' => null])
<div class="grid place-items-center rounded-xl border border-dashed border-line px-6 py-12 text-center">
    <span class="mb-3 grid size-11 place-items-center rounded-full bg-elevated text-muted"><i data-lucide="{{ $icon }}" class="size-5"></i></span>
    <div class="font-medium">{{ $title }}</div>
    @if ($text)<p class="mt-1 max-w-md text-sm text-muted">{{ $text }}</p>@endif
    @if (! $slot->isEmpty())<div class="mt-4">{{ $slot }}</div>@endif
</div>
