@props(['label', 'value', 'icon' => 'activity', 'hint' => null, 'tone' => 'primary'])
<div class="card card-pad">
    <div class="flex items-center justify-between text-muted"><span class="text-xs font-medium uppercase tracking-wide">{{ $label }}</span><span class="grid size-8 place-items-center rounded-lg tone-{{ $tone }}"><i data-lucide="{{ $icon }}" class="size-4"></i></span></div>
    <div class="mt-3 text-2xl font-semibold tabular-nums">{{ $value }}</div>
    @if ($hint)<div class="mt-1 text-xs text-muted">{{ $hint }}</div>@endif
</div>
