<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Story Reader' }}</title>
    @include('story-studio::partials.fonts')
    @storyStudioAssets
    @livewireStyles
    <style>html,body{margin:0;height:100%;background:#050506}</style>
</head>
<body>
    @yield('content')
    {{-- Livewire brings the single Alpine the Reader registers into. --}}
    @livewireScripts
</body>
</html>
