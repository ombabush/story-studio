@extends('story-studio::layouts.reader', ['title' => $story->title])

@section('content')
    @php
        $access = app(\Ombabush\StoryStudio\Support\Access::class);
        $back = $access->canManage(request()->user(), $story) && config('story-studio.routes.studio.enabled')
            ? route(config('story-studio.routes.studio.name').'edit', ['story' => $story->public_id])
            : config('story-studio.host_home');
    @endphp
    <x-story-studio::reader :story="$story" :page="$start" :preview="$preview" :fullscreen="true" :back="$back" />
@endsection
