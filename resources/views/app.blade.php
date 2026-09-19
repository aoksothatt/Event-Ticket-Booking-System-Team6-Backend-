<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Event Tickets</title>
        @if (app()->isLocal())
            <script type="module" src="http://localhost:5173/src/main.js"></script>
        @else
            @php
                $manifestPath = public_path('build/manifest.json');
                $manifest = file_exists($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : null;
            @endphp
            @if($manifest && isset($manifest['src/main.js']))
                @if(isset($manifest['src/styles/app.css']))
                    <link rel="stylesheet" href="{{ asset('build/' . $manifest['src/styles/app.css']['file']) }}">
                @endif
                <script type="module" src="{{ asset('build/' . $manifest['src/main.js']['file']) }}"></script>
            @endif
        @endif
    </head>
    <body class="bg-gray-950 antialiased">
        <div id="app"></div>
    </body>
</html>
