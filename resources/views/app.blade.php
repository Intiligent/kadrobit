<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        @fonts

        @vite(['resources/style/tailwind.css', 'resources/style/app.scss', 'resources/js/app.js'])

        <x-inertia::head>
            <title>{{ config('app.name') }}</title>
        </x-inertia::head>
    </head>
    <body class="min-h-screen bg-[#FDFDFC] font-sans text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
        <x-inertia::app />
    </body>
</html>
