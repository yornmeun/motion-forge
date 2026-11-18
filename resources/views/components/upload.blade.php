{{-- Drag-and-drop file input. --}}
@props(['name', 'label', 'accept' => null, 'hint' => null, 'multiple' => false])
<div x-data="{ files: [], over: false }">
    <span class="label">{{ $label }}</span>
    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-dashed px-3 py-3 text-sm transition" :class="over ? 'border-primary bg-primary/10' : 'border-line hover:border-muted'"
           @dragover.prevent="over = true" @dragleave="over = false" @drop.prevent="over = false; $refs.input.files = $event.dataTransfer.files; files = [...$refs.input.files].map(f => f.name)">
        <i data-lucide="upload" class="size-4 text-muted"></i>
        <span class="min-w-0 flex-1 truncate" :class="files.length ? 'text-text' : 'text-muted'" x-text="files.length ? files.join(', ') : 'Drop a file or click to browse'"></span>
        <input x-ref="input" type="file" name="{{ $name }}" @if($accept) accept="{{ $accept }}" @endif @if($multiple) multiple @endif class="sr-only" @change="files = [...$event.target.files].map(f => f.name)">
    </label>
    @if ($hint)<p class="hint">{{ $hint }}</p>@endif
</div>
