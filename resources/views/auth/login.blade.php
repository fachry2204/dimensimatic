<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>Login — {{ $systemName }} Automation</title>@vite(['resources/css/app.css','resources/js/app.js'])<x-system-identity /></head>
<body class="min-h-screen bg-slate-950 text-slate-900">
    <main class="grid min-h-screen lg:grid-cols-2">
        <section class="relative hidden overflow-hidden bg-[#111a31] p-14 text-white lg:flex lg:flex-col lg:justify-between">
            <div class="absolute -right-20 -top-20 h-96 w-96 rounded-full bg-indigo-500/20 blur-3xl"></div><div class="absolute -bottom-24 -left-20 h-96 w-96 rounded-full bg-blue-500/10 blur-3xl"></div>
            <div class="brand relative text-white">@if($systemFavicon)<img src="{{ $systemFavicon }}" alt="" class="h-10 w-10 rounded-lg object-contain">@else<span class="brand__mark"><span></span><span></span><span></span></span>@endif<span><strong class="text-white">{{ $systemName }}</strong><small class="text-indigo-200">Release Automation</small></span></div>
            <div class="relative max-w-xl"><span class="badge border border-white/10 bg-white/10 text-indigo-100">Internal operations</span><h1 class="mt-6 text-5xl font-bold leading-tight tracking-tight">Kelola alur rilisan dengan aman dan terukur.</h1><p class="mt-5 max-w-lg text-base leading-7 text-slate-300">Satu pusat kendali untuk sinkronisasi {{ $systemName }}, pembuatan draft SoundOn, validasi metadata, dan audit operasional.</p><div class="mt-10 grid grid-cols-3 gap-4"><div class="rounded-xl border border-white/10 bg-white/5 p-4"><strong class="block text-xl">Draft</strong><span class="text-xs text-slate-400">Only workflow</span></div><div class="rounded-xl border border-white/10 bg-white/5 p-4"><strong class="block text-xl">24/7</strong><span class="text-xs text-slate-400">Monitoring</span></div><div class="rounded-xl border border-white/10 bg-white/5 p-4"><strong class="block text-xl">Secure</strong><span class="text-xs text-slate-400">Encrypted data</span></div></div></div>
            <p class="relative text-xs text-slate-500">{{ $systemName }} Automation · Internal operator access</p>
        </section>
        <section class="flex items-center justify-center bg-slate-50 p-6 sm:p-10">
            <div class="w-full max-w-md"><div class="mb-9 lg:hidden"><div class="brand">@if($systemFavicon)<img src="{{ $systemFavicon }}" alt="" class="h-10 w-10 rounded-lg object-contain">@else<span class="brand__mark"><span></span><span></span><span></span></span>@endif<span><strong>{{ $systemName }}</strong><small>Release Automation</small></span></div></div><div class="eyebrow">Secure access</div><h2 class="page-title">Selamat datang kembali</h2><p class="page-subtitle">Masuk menggunakan akun operator internal Anda.</p>
                <div class="mt-6 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm font-semibold text-slate-700">Status sistem</span>
                        <div class="flex flex-wrap justify-end gap-2 text-xs font-semibold">
                            <span id="server-status" class="rounded-full px-2.5 py-1 {{ $systemStatus['server'] ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' }}">Server: {{ $systemStatus['server'] ? 'Online' : 'Offline' }}</span>
                            <span id="worker-status" class="rounded-full px-2.5 py-1 {{ $systemStatus['worker'] ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' }}">Browser: {{ $systemStatus['worker'] ? 'Online' : 'Offline' }}</span>
                            <span id="queue-status" class="rounded-full px-2.5 py-1 {{ $systemStatus['queues_online'] ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' }}">Queue: {{ $systemStatus['queues_online'] ? 'Online' : 'Offline' }}</span>
                        </div>
                    </div>
                    @if(session('system_message'))<p class="mt-3 text-xs font-medium text-emerald-700">{{ session('system_message') }}</p>@endif
                    @error('system')<p class="mt-3 text-xs font-medium text-red-600">{{ \App\Support\SystemBranding::text($message) }}</p>@enderror
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <form method="post" action="{{ route('login.start-system') }}">@csrf
                            <button class="secondary-button w-full" type="submit">Start Semua Worker</button>
                        </form>
                        <form method="post" action="{{ route('login.stop-system') }}">@csrf
                            <button class="secondary-button w-full border-red-200 text-red-600 hover:bg-red-50" type="submit">Stop Semua Worker</button>
                        </form>
                    </div>
                    <p id="worker-capacity" class="mt-2 text-[11px] leading-4 text-slate-400">{{ $systemStatus['release_workers'] }} pipeline worker · {{ $systemStatus['status_workers'] }} checker worker · {{ $systemStatus['browser_slots'] }} sesi browser tersedia.</p>
                    <p class="mt-1 text-[11px] leading-4 text-slate-400">Start menyalakan browser, pipeline queue, dan checker queue. Stop menghentikan seluruh worker tersebut; server aplikasi tetap aktif.</p>
                </div>
                <form method="post" action="{{ route('login.store') }}" class="mt-8 space-y-5">@csrf
                    <div><label for="username" class="form-label">Username</label><input id="username" name="username" type="text" value="{{ old('username') }}" required autofocus autocomplete="username" class="form-input" placeholder="Masukkan username"></div>
                    <div><div class="flex items-center justify-between"><label for="password" class="form-label">Password</label></div><input id="password" name="password" type="password" required autocomplete="current-password" class="form-input" placeholder="Masukkan password"></div>
                    <label class="flex items-center gap-2 text-xs font-medium text-slate-600"><input type="checkbox" name="remember" value="1" class="accent-indigo-600"> Ingat saya di perangkat ini</label>
                    @error('username')<div role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ \App\Support\SystemBranding::text($message) }}</div>@enderror
                    <button class="primary-button w-full">Masuk ke dashboard</button>
                </form>
                <p class="mt-7 text-center text-xs text-slate-400">Akses terbatas untuk operator yang berwenang.</p>
            </div>
        </section>
    </main>
    <script>
        (() => {
            const update = (id, label, online) => {
                const element = document.getElementById(id);
                if (!element) return;
                element.textContent = `${label}: ${online ? 'Online' : 'Offline'}`;
                element.className = `rounded-full px-2.5 py-1 ${online ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}`;
            };
            const refresh = () => fetch(@json(route('login.system-status')), { headers: { Accept: 'application/json' }, cache: 'no-store' })
                .then(response => response.ok ? response.json() : Promise.reject())
                .then(status => {
                    update('server-status', 'Server', !!status.server);
                    update('worker-status', 'Browser', !!status.worker);
                    update('queue-status', 'Queue', !!status.queues_online);
                    const capacity = document.getElementById('worker-capacity');
                    if (capacity) capacity.textContent = `${status.release_workers ?? 0} pipeline worker · ${status.status_workers ?? 0} checker worker · ${status.browser_slots ?? 2} sesi browser tersedia.`;
                })
                .catch(() => { update('server-status', 'Server', false); update('worker-status', 'Browser', false); update('queue-status', 'Queue', false); });
            window.setInterval(refresh, 5000);
        })();
    </script>
</body>
</html>
