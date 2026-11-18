@props(['status'])
@php
    $v = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $tone = method_exists($status, 'tone') ? $status->tone() : match ($v) {
        'pass', 'succeeded', 'approved', 'valid', 'connected', 'ready', 'rendered', 'preview_ready', 'generated', 'completed' => 'success',
        'fail', 'failed', 'invalid', 'error', 'refused' => 'error',
        'warn', 'waiting_approval', 'blocked_budget', 'pending', 'unverified' => 'warning',
        'running', 'generating', 'validating', 'queued' => 'accent',
        default => 'muted',
    };
    $label = method_exists($status, 'label') ? $status->label() : ucfirst(str_replace('_', ' ', $v));
@endphp
<x-ui.badge :tone="$tone" {{ $attributes }}>@if (in_array($v, ['running', 'generating', 'validating', 'rendering', 'planning'], true))<span class="size-1.5 animate-pulse rounded-full bg-current"></span>@endif{{ $label }}</x-ui.badge>
