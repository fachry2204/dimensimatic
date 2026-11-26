<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $systemName }} Automation</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
<x-system-identity /></head>
<body class="app-workspace min-h-screen text-slate-900 antialiased">
    <a class="skip-link" href="#main-content">Lewati navigasi</a>
    {{ $slot }}
    @auth
        @can('sessions.manage')
            <livewire:automation.application-update-prompt />
        @endcan
    @endauth
</body>
</html>
