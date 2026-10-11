<?php

namespace Ombabush\StoryStudio\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Ombabush\StoryStudio\Actions\ReadingProgress;
use Ombabush\StoryStudio\Models\Mark;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;
use Ombabush\StoryStudio\Support\Access;
use Ombabush\StoryStudio\Support\ReaderIdentity;
use Ombabush\StoryStudio\Support\ReaderPayload;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Every refusal is a 404: a closed story, a draft or a stranger's file does not admit it exists. */
class ReaderController extends Controller
{
    public function __construct(private Access $access) {}

    public function show(Request $request, Story $story)
    {
        abort_unless($this->access->canRead($request->user(), $story), 404);

        return view('story-studio::reader.page', [
            'story' => $story,
            'start' => $request->query('page'),
            'preview' => $request->boolean('preview'),
        ]);
    }

    public function pages(Request $request, Story $story, ReaderPayload $payload): JsonResponse
    {
        abort_unless($this->access->canRead($request->user(), $story), 404);
        $ids = array_filter(explode(',', (string) $request->query('ids')));

        return response()->json(['pages' => $payload->chunk($request, $story, $ids, $request->boolean('preview'))])
            ->header('Cache-Control', 'private, no-store');
    }

    public function progress(Request $request, Story $story, ReadingProgress $progress, ReaderIdentity $identity): Response
    {
        abort_unless($this->access->canRead($request->user(), $story), 404);
        $page = Page::where('story_id', $story->id)->where('public_id', (string) $request->input('page'))->first();
        abort_unless($page && $this->access->canSeePage($request->user(), $page, true), 404);
        $progress->save($story, $identity->key($request), $page);

        return response()->noContent();
    }

    /**
     * Set or clear one of the reader's own marks on a page: { page, kind: bookmark|note, text? }.
     * An empty note or `on: false` removes the mark. Same access as reading the page.
     */
    public function mark(Request $request, Story $story, ReaderIdentity $identity): JsonResponse
    {
        abort_unless($this->access->canRead($request->user(), $story), 404);
        $kind = (string) $request->input('kind');
        abort_unless(in_array($kind, app(\Ombabush\StoryStudio\Support\ReaderCatalog::class)->marks(), true), 422);
        $page = Page::where('story_id', $story->id)->where('public_id', (string) $request->input('page'))->first();
        abort_unless($page && $this->access->canSeePage($request->user(), $page, true), 404);
        $text = $kind === 'note' ? trim(mb_substr((string) $request->input('text'), 0, 5000)) : null;
        $key = ['story_id' => $story->id, 'reader_key' => $identity->key($request), 'page_id' => $page->id, 'kind' => $kind];

        if (($kind === 'bookmark' && ! $request->boolean('on')) || ($kind === 'note' && $text === '')) {
            Mark::where($key)->delete();
        } else {
            Mark::updateOrCreate($key, ['text' => $text]);
        }

        return response()->json(['marks' => app(\Ombabush\StoryStudio\Support\ReaderPayload::class)->marks($request, $story)]);
    }

    /**
     * The reader's own settings: { scope: global|story, prefs: {…} }. Whitelisted by ReaderPrefs; a null
     * value removes a key. Answers with the merged settings. Same access as reading the story.
     */
    public function prefs(Request $request, Story $story, ReaderIdentity $identity, \Ombabush\StoryStudio\Support\ReaderPrefs $prefs): JsonResponse
    {
        abort_unless($this->access->canRead($request->user(), $story), 404);
        $scope = $request->input('scope') === 'story' ? 'story' : 'global';

        return response()->json(['prefs' => (object) $prefs->save($identity->key($request), $story, $scope, (array) $request->input('prefs'))]);
    }

    public function media(Request $request, Story $story, string $image, string $size = 'display'): StreamedResponse
    {
        // Only a version of a page of this very story: a media uuid from anywhere else is unknown here.
        $media = config('media-library.media_model', Media::class);
        $image = $media::where('uuid', $image)->where('model_type', (new Page)->getMorphClass())
            ->where('collection_name', Page::VERSIONS)->first();
        $page = $image ? Page::where('id', $image->model_id)->where('story_id', $story->id)->first() : null;
        abort_unless($page && $this->access->canSeeImage($request->user(), $page, $image, $request->boolean('preview')), 404);

        // A conversion that was never made (a broken file, a format the driver cannot read) falls back
        // to the original, so a page is never blank because a thumbnail is missing.
        $conversion = $size === 'original' || ! $image->hasGeneratedConversion($size) ? '' : $size;
        $path = $image->getPathRelativeToRoot($conversion);
        $disk = Storage::disk($conversion ? ($image->conversions_disk ?: $image->disk) : $image->disk);
        abort_unless($disk->exists($path), 404);
        $mime = $conversion ? 'image/jpeg' : ($image->mime_type ?: 'application/octet-stream');

        return $disk->response($path, null, [
            'Content-Type' => $mime,
            // Private: a published story's files can still become private again on unpublish.
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
