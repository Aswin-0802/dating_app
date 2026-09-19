@props(['show' => false, 'close' => null, 'size' => 'md'])

{{-- The member app's dialogs use the shared state-driven dialog. --}}
<x-ui.dialog :show="$show" :close="$close" :size="$size" {{ $attributes }}>{{ $slot }}</x-ui.dialog>
