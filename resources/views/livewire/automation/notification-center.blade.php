<div>
    <x-automation-nav />
    <main class="page-shell">
        <section class="page-heading"><div><div class="eyebrow">Operator inbox</div><h1 class="page-title">Notifikasi</h1><p class="page-subtitle">Peringatan operasional aman tanpa credential atau session data.</p></div><button type="button" wire:click="markAllRead" class="secondary-button">Tandai semua dibaca</button></section>

        <div class="space-y-3">
            @forelse ($notifications as $notification)
                <article class="panel p-5 {{ $notification->read_at ? 'opacity-60' : '' }}">
                    <div class="flex items-start gap-4">
                        <span class="mt-0.5 grid h-10 w-10 shrink-0 place-items-center rounded-xl {{ $notification->read_at ? 'bg-slate-100 text-slate-500' : 'bg-amber-100 text-amber-700' }}">!</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-sm font-bold text-slate-800">{{ \App\Support\SystemBranding::text(str_replace('_', ' ', ucwords($notification->data['event'] ?? 'notification', '_'))) }}</h2><p class="mt-1 text-sm leading-6 text-slate-600">{{ \App\Support\SystemBranding::text($notification->data['message'] ?? '') }}</p></div>@if (!$notification->read_at)<button type="button" wire:click="markRead('{{ $notification->id }}')" class="text-xs font-semibold text-indigo-700">Tandai dibaca</button>@endif</div>
                            <div class="mt-3 flex flex-wrap items-center gap-3 text-xs text-slate-400"><span>{{ $notification->created_at->diffForHumans() }}</span>@if ($notification->data['error_code'] ?? null)<span class="badge badge--danger">{{ \App\Support\SystemBranding::text($notification->data['error_code']) }}</span>@endif @if ($notification->data['run_id'] ?? null)<a href="{{ route('automation.runs.show', $notification->data['run_id']) }}" class="font-semibold text-indigo-700">Buka automation run →</a>@endif</div>
                        </div>
                    </div>
                </article>
            @empty
                <div class="panel empty-state"><div class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-full bg-emerald-50 text-xl text-emerald-600">✓</div><strong class="block text-slate-700">Semua terkendali</strong><span class="mt-1 block">Tidak ada notifikasi operasional.</span></div>
            @endforelse
        </div>
    </main>
</div>
