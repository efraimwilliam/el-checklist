<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Terkirim') }}</title>
</head>
<body style="font-family: system-ui, sans-serif; color: #475569; text-align: center; padding: 3rem 1rem;">
    {{-- Form dari obrolan sudah tersimpan dan kartunya terkirim: jendela form di halaman obrolan ditutup (lihat chat.js "Form di dalam obrolan"). --}}
    <p>{{ __('Terkirim ke obrolan.') }} <a href="{{ route('chat.show', $thread) }}">{{ __('Buka obrolan') }}</a></p>
    <script>
        if (window.parent !== window) window.parent.postMessage({ type: 'chat-tool-done' }, location.origin);
        else location.replace(@json(route('chat.show', $thread)));
    </script>
</body>
</html>
