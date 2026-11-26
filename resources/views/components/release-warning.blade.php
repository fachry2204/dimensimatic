@props(['reason', 'technical' => '', 'action' => '', 'detailKey'])
@php
    $fullReason = \App\Support\SystemBranding::text($reason);
    $shortReason = \Illuminate\Support\Str::limit($fullReason, 220);
@endphp
<div class="release-error-box release-warning">
    <details wire:key="{{ $detailKey }}" wire:ignore.self>
        <summary class="release-warning__summary">
            <span class="release-warning__reason">Alasan: {{ $shortReason }}</span>
            <span class="release-warning__toggle"><span aria-hidden="true" class="release-warning__arrow">▸</span> Detail</span>
        </summary>
        <div class="release-warning__details">
            @if($shortReason !== $fullReason)<p>{{ $fullReason }}</p>@endif
            @if(filled($action))<p>{{ \App\Support\SystemBranding::text($action) }}</p>@endif
            @if(filled($technical))<p>{{ \App\Support\SystemBranding::text($technical) }}</p>@endif
        </div>
    </details>
</div>
