<x-layouts.app title="Asset Library">
    <x-slot:header><h1 class="text-lg">Asset Library</h1></x-slot:header>
    <section class="mb-8">
        <h2 class="mb-3">Environments</h2>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">@foreach ($backgrounds as $b)<div class="card card-pad"><div class="font-medium">{{ $b->name }}</div><p class="mt-1 text-xs text-muted">{{ $b->description }}</p><div class="mt-2 flex gap-1"><x-ui.badge>{{ $b->default_look }}</x-ui.badge><x-ui.badge tone="accent">{{ str_replace('_', ' ', $b->ambience) }}</x-ui.badge></div></div>@endforeach</div>
    </section>
    <div class="mb-3 flex items-center justify-between"><h2>Uploaded files</h2>
        <form method="GET"><select name="role" class="input w-auto" onchange="this.form.submit()"><option value="">All types</option>@foreach ($roles as $r)<option value="{{ $r }}" @selected(request('role') === $r)>{{ str_replace('_', ' ', $r) }}</option>@endforeach</select></form></div>
    <div class="card overflow-x-auto"><table class="table"><thead><tr><th>File</th><th>Type</th><th>Project</th><th>Size</th><th>SHA-256</th><th>Uploaded</th></tr></thead><tbody>
        @forelse ($files as $f)
            <tr><td><a class="hover:underline" href="{{ route('media.show', [$f->project, 'file', $f->id]) }}" target="_blank" rel="noopener">{{ $f->original_name }}</a></td><td>{{ str_replace('_', ' ', $f->role) }}</td><td class="text-muted">{{ $f->project->title }}</td><td class="tabular-nums">{{ number_format($f->size / 1024, 0) }} KB</td><td class="font-mono text-[11px] text-muted">{{ substr($f->sha256, 0, 12) }}…</td><td class="text-xs text-muted">{{ $f->created_at->diffForHumans() }}</td></tr>
        @empty<tr><td colspan="6" class="text-muted">No files.</td></tr>@endforelse
    </tbody></table></div>
    <div class="mt-4">{{ $files->links() }}</div>
</x-layouts.app>
