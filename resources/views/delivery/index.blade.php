<x-layouts.app title="Completed Videos">
    <x-slot:header><h1 class="text-lg">Completed Videos</h1></x-slot:header>
    @if ($videos->isEmpty())
        <x-ui.empty icon="circle-play" title="No completed videos" text="Finished renders appear here with their thumbnail and caption." />
    @else
        <div class="grid grid-cols-2 gap-5 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ($videos as $v)
                @php $t = $v->project->thumbnails()->first(); @endphp
                <div class="card overflow-hidden">
                    <a href="{{ route('projects.show', [$v->project, 'tab' => 'delivery']) }}" class="block aspect-[9/16] bg-elevated">@if($t)<img src="{{ route('media.show', [$v->project, 'thumbnail', $t->id]) }}" class="size-full object-cover" alt="">@endif</a>
                    <div class="p-3"><div class="truncate text-sm font-medium">{{ $v->project->title }}</div><div class="text-xs text-muted">{{ $v->created_at->format('M j, Y') }} · {{ number_format($v->size / 1048576, 1) }} MB</div>
                        <div class="mt-2 flex gap-1"><a class="btn-secondary btn-sm flex-1" href="{{ route('delivery.download', [$v->project, 'video']) }}">MP4</a><a class="btn-secondary btn-sm flex-1" href="{{ route('delivery.download', [$v->project, 'thumbnail']) }}">PNG</a></div></div>
                </div>
            @endforeach
        </div>
        <div class="mt-6">{{ $videos->links() }}</div>
    @endif
</x-layouts.app>
