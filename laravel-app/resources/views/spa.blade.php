<!DOCTYPE html>
<html lang="th">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#0f766e">

        <title>{{ config('app.name', 'School DSS') }} V2</title>

        @viteReactRefresh
        @vite('resources/js/spa/main.tsx')
    </head>
    <body>
        <div id="root"></div>
    </body>
</html>
