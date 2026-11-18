<x-layouts.app title="Character Library">
    <x-slot:header><h1 class="text-lg">Character Library</h1></x-slot:header>
    <p class="mb-6 max-w-3xl text-sm text-muted">Characters are drawn by the engine in the house style (white oval head, 9-unit outline, stick limbs, flat colours), so they look identical in every video. Kiki always uses the fixed preset.</p>
    <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <div class="grid gap-4">
            @foreach ($characters as $c)
                <section class="card overflow-hidden">
                    <iframe src="{{ route('characters.preview', $c) }}" sandbox="allow-scripts" loading="lazy" class="aspect-[16/7] w-full" title="{{ $c->name }} expressions"></iframe>
                    <div class="flex items-center gap-3 p-4"><div class="flex-1"><div class="font-medium">{{ $c->name }} @if($c->is_builtin)<x-ui.badge tone="muted">built-in</x-ui.badge>@endif</div><p class="text-sm text-muted">{{ $c->description }}</p></div>
                        @if (! $c->is_builtin && $c->user_id === auth()->id())<x-ui.confirm :action="route('characters.destroy', $c)" method="DELETE" label="Remove" message="Remove this character from your library?" />@endif</div>
                </section>
            @endforeach
        </div>
        <form method="POST" action="{{ route('characters.store') }}" class="card card-pad grid h-fit gap-3" x-data="{ kind: 'kid' }">
            @csrf
            <h2>New side character</h2>
            <div><label class="label">Name</label><input name="name" required maxlength="60" class="input"></div>
            <div><label class="label">Type</label><select name="kind" x-model="kind" class="input"><option value="kid">Kid</option><option value="suit">Adult in suit</option></select></div>
            <div><label class="label">Hair</label><select name="hair" class="input"><template x-if="kind === 'kid'"><optgroup label="Kid">@foreach (['bob', 'pony', 'buns', 'long', 'side', 'spiky', 'curly', 'beanie'] as $h)<option>{{ $h }}</option>@endforeach</optgroup></template><template x-if="kind === 'suit'"><optgroup label="Adult">@foreach (['karl', 'grey', 'greycap', 'bunGrey'] as $h)<option>{{ $h }}</option>@endforeach</optgroup></template></select></div>
            <div class="grid grid-cols-3 gap-2">
                <div x-show="kind === 'kid'"><label class="label">Shirt</label><input type="color" name="shirt" value="#5b6f9a" class="h-9 w-full rounded"></div>
                <div x-show="kind === 'suit'"><label class="label">Suit</label><input type="color" name="suit" value="#3f4a5a" class="h-9 w-full rounded"></div>
                <div x-show="kind === 'suit'"><label class="label">Tie</label><input type="color" name="tie" value="#2d3a4a" class="h-9 w-full rounded"></div>
                <div><label class="label">Hair</label><input type="color" name="hairCol" value="#3a2a20" class="h-9 w-full rounded"></div>
            </div>
            <div x-show="kind === 'suit'" class="flex gap-4 text-sm"><label class="flex items-center gap-2"><input type="checkbox" name="glasses" value="1" class="accent-primary">Glasses</label><label class="flex items-center gap-2"><input type="checkbox" name="mustache" value="1" class="accent-primary">Mustache</label></div>
            <div><label class="label">Description</label><textarea name="description" rows="2" class="input" maxlength="300" placeholder="Personality and role (helps AI direction)"></textarea></div>
            <button class="btn-primary">Add character</button>
        </form>
    </div>
</x-layouts.app>
