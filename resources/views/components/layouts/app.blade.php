<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $systemName }} Automation</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
<x-system-identity /></head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    {{ $slot }}
</body>
</html>
