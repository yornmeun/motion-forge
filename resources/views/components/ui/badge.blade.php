@props(['tone' => 'muted'])
<span {{ $attributes->merge(['class' => 'badge tone-'.$tone]) }}>{{ $slot }}</span>
