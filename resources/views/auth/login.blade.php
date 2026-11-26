<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>Login — {{ $systemName }} Automation</title>@vite(['resources/css/app.css','resources/js/app.js'])<x-system-identity /></head>
<body class="premium-login text-slate-900 antialiased">
 <main class="login-layout">
  <section class="login-story" aria-labelledby="login-story-title">
   <a href="{{ route('login') }}" class="brand"><img src="{{ $systemFavicon ?: $systemDefaultFavicon }}" alt=""><span><strong>{{ $systemName }}</strong><small>Release Automation</small></span></a>
   <div><h1 id="login-story-title">Satu kendali.<br>Seluruh rilisan.</h1><p>Kelola metadata, siapkan draft Aggregator, dan pantau status distribusi musik dalam satu pusat kendali.</p></div>
   <div class="login-orbits" aria-hidden="true"><i></i><i></i><i></i></div>
   <p class="login-story-footer">{{ $systemName }} · Workspace operasional musik</p>
  </section>
  <section class="login-form-side">
   <div class="login-form-wrap">
    <div class="brand mobile-login-brand lg:hidden"><img src="{{ $systemFavicon ?: $systemDefaultFavicon }}" alt=""><span><strong>{{ $systemName }}</strong><small>Release Automation</small></span></div>
    <h2 class="page-title">Selamat datang kembali</h2><p class="page-subtitle">Masuk ke {{ $systemName }} untuk mengelola rilisan musik Anda.</p>
    <form method="post" action="{{ route('login.store') }}" class="mt-8 space-y-5">@csrf
     <div><label for="username" class="form-label">Username</label><input id="username" name="username" type="text" value="{{ old('username') }}" required autofocus autocomplete="username" class="form-input" placeholder="Masukkan username"></div>
     <div><label for="password" class="form-label">Password</label><div class="password-field"><input id="password" name="password" type="password" required autocomplete="current-password" class="form-input" placeholder="Masukkan password"><button type="button" class="password-toggle" aria-label="Tampilkan password" aria-pressed="false" data-password-toggle="password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></div></div>
     <label class="flex items-center gap-2 text-xs font-medium text-slate-600"><input type="checkbox" name="remember" value="1" class="accent-indigo-600"> Ingat saya di perangkat ini</label>
     @error('username')<div role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ \App\Support\SystemBranding::text($message) }}</div>@enderror
     <button class="primary-button w-full">Masuk ke dashboard</button>
    </form>
    <div class="login-health">
     <div class="flex flex-wrap gap-2 text-[10px] font-semibold" aria-label="Status sistem">
      <span id="server-status" class="rounded-full px-2.5 py-1 {{ $systemStatus['server'] ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' }}">Server: {{ $systemStatus['server'] ? 'Online' : 'Offline' }}</span>
      <span id="worker-status" class="rounded-full px-2.5 py-1 {{ $systemStatus['worker'] ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' }}">Browser: {{ $systemStatus['worker'] ? 'Online' : 'Offline' }}</span>
      <span id="queue-status" class="rounded-full px-2.5 py-1 {{ $systemStatus['queues_online'] ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' }}">Queue: {{ $systemStatus['queues_online'] ? 'Online' : 'Offline' }}</span>
     </div>
     @if(session('system_message'))<p class="mt-3 text-xs font-medium text-emerald-700">{{ session('system_message') }}</p>@endif
     @error('system')<p class="mt-3 text-xs font-medium text-red-600">{{ \App\Support\SystemBranding::text($message) }}</p>@enderror
     <details><summary>Kelola Worker</summary><div class="mt-3 grid grid-cols-2 gap-2">
      <form method="post" action="{{ route('login.start-system') }}">@csrf<button class="secondary-button w-full" type="submit">Start Semua Worker</button></form>
      <form method="post" action="{{ route('login.stop-system') }}">@csrf<button class="secondary-button w-full border-red-200 text-red-600" type="submit">Stop Semua Worker</button></form>
     </div><p id="worker-capacity" class="mt-2 text-[11px] leading-4 text-slate-400">{{ $systemStatus['release_workers'] }} pipeline worker · {{ $systemStatus['status_workers'] }} checker worker · {{ $systemStatus['browser_slots'] }} sesi browser tersedia.</p><p class="mt-1 text-[11px] leading-4 text-slate-400">Start menyalakan browser, pipeline queue, dan checker queue. Stop menghentikan seluruh worker tersebut; server aplikasi tetap aktif.</p></details>
    </div>
    <p class="mt-6 text-center text-[10px] text-slate-400">Akses terbatas untuk operator yang berwenang.</p>
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
