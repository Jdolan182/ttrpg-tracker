<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- For search engines and link previews, which only see this first response (see App\Support\Seo). --}}
        @php($seo = \App\Support\Seo::for($page['component'] ?? ''))
        <title inertia>{{ $seo['title'] }}</title>
        <meta name="description" content="{{ $seo['description'] }}">
        @if ($seo['index'])
            <link rel="canonical" href="{{ url()->current() }}">
        @else
            <meta name="robots" content="noindex">
        @endif
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta property="og:title" content="{{ $seo['title'] }}">
        <meta property="og:description" content="{{ $seo['description'] }}">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:image" content="{{ asset('og-image.png') }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="{{ config('app.name') }}: a combat tracker for tabletop RPGs">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="theme-color" content="#a02233">
        @if (($page['component'] ?? '') === 'Encounters/Index')
            {{-- Lets search results show it as a free web app. --}}
            <script type="application/ld+json">
                {!! json_encode([
                    '@context' => 'https://schema.org',
                    '@type' => 'WebApplication',
                    'name' => config('app.name'),
                    'url' => url('/'),
                    'description' => $seo['description'],
                    'applicationCategory' => 'GameApplication',
                    'operatingSystem' => 'Any (in the browser)',
                ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}
            </script>
        @endif

        {{-- A saved dark theme goes on before anything draws, so the page doesn't flash light first (see useTheme.ts). --}}
        <script>
            try {
                if (localStorage.getItem('appearance') === 'dark') document.documentElement.classList.add('dark');
            } catch (e) {}
        </script>

        {{-- Browsers keep tab icons long after a reload; bump ?v= when the icon changes. --}}
        <link rel="icon" href="/favicon.svg?v=2" type="image/svg+xml">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=fraunces:500,600|instrument-sans:400,500,600" rel="stylesheet" />

        @routes
        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
