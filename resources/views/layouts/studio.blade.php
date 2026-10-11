<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Story Studio' }}</title>
    @include('story-studio::partials.fonts')
    @storyStudioAssets
    @livewireStyles
    <style>html,body{margin:0;background:#09090b}</style>
</head>
<body>
    {{ $slot }}
    @livewireScripts
</body>
</html>
