<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

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
