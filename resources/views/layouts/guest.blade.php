<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    {{-- Tampilan dikunci di skala 1 (tanpa zoom): mencegah layar "bergerak" karena cubit, ketuk dua kali, atau zoom otomatis iPhone saat
         kolom isian diketuk. Safari iPhone mengabaikan user-scalable, jadi cubitnya juga dicegah di bawah (gesture*) dan di app.css (touch-action). --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <script>
        ['gesturestart', 'gesturechange', 'gestureend'].forEach((type) => document.addEventListener(type, (event) => event.preventDefault(), { passive: false }));
    </script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1b1511">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Checklist">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <script>
        window.__i18n = @json(\App\Support\Translations::forJs());
    </script>
    @vite(['resources/css/app.css', 'resources/js/password-toggle.js'])
</head>
<body class="min-h-screen bg-white text-slate-800 antialiased">
{{ $slot }}
</body>
</html>
