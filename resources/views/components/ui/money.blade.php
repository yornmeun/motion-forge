@props(['value'])
@if ($value === null)<span class="text-muted" title="No verified price for this model">n/a</span>@else<span class="tabular-nums">${{ number_format((float) $value, (float) $value < 1 ? 4 : 2) }}</span>@endif
