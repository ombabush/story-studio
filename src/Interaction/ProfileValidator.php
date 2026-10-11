<?php

namespace Ombabush\StoryStudio\Interaction;

/**
 * Story Interaction Profile 1.0-draft.1 — structural and semantic validation
 * (docs/FORMAT.md, schema/interaction.schema.json).
 *
 * Content is data: every object has a closed set of fields, an unknown one is an error rather than a
 * new hook, and nothing here ever executes, fetches or renders. Errors carry a JSON-pointer-like path
 * so an importer can say exactly what is wrong instead of "invalid file".
 */
class ProfileValidator
{
    public const PROFILE = 'story-interaction/1.0-draft.1';

    public const KINDS = ['nav', 'character', 'object', 'place', 'note', 'media', 'reference'];

    public const CARD_KINDS = ['character', 'object', 'place', 'note', 'reference'];

    public const EFFECTS = ['outline', 'brackets', 'ink', 'quiet', 'spotlight', 'soft-focus', 'halo', 'lift', 'duotone'];

    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const MEDIA_TYPES = ['audio/mpeg', 'audio/ogg', 'audio/mp4', 'video/mp4'];

    private const ID = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/';

    private const LANG = '/^[A-Za-z]{2,8}(-[A-Za-z0-9]{1,8})*$/';

    /** @var array<int, string> */
    private array $errors = [];

    /**
     * @param  array<int, string>|null  $knownPageIds  every page id of the bound story (jump targets may be
     *                                                pages without bindings); null = only the profile's pages
     * @return array<int, string> errors; empty means valid
     */
    public function validate(mixed $profile, ?array $knownPageIds = null): array
    {
        $this->errors = [];
        if (! is_array($profile) || array_is_list($profile)) {
            return ['/: the profile must be a JSON object'];
        }
        $this->keys($profile, '', ['profile', 'storyId', 'revision', 'language', 'assets', 'cards', 'pages'], []);
        if (($profile['profile'] ?? null) !== self::PROFILE) {
            $this->err('/profile', 'must be exactly '.self::PROFILE);
        }
        $this->id($profile['storyId'] ?? null, '/storyId');
        if (! is_string($profile['revision'] ?? null) || ! preg_match('/^sha256:[0-9a-f]{64}$/', $profile['revision'])) {
            $this->err('/revision', 'must be sha256: followed by 64 lowercase hex digits');
        }
        $lang = $profile['language'] ?? null;
        if (! is_string($lang) || ! preg_match(self::LANG, $lang)) {
            $this->err('/language', 'must be a BCP 47 language tag');
            $lang = null;
        }

        $assets = $this->registry($profile['assets'] ?? null, '/assets', 10000, fn ($a, $p) => $this->asset($a, $p));
        $cards = $this->registry($profile['cards'] ?? null, '/cards', 2000, fn ($c, $p) => $this->card($c, $p, $lang, $assets));
        $pages = $profile['pages'] ?? null;
        if (! is_array($pages) || ! array_is_list($pages) || count($pages) < 1 || count($pages) > 5000) {
            $this->err('/pages', 'must be an array of 1…5000 pages');

            return $this->errors;
        }

        $pageIds = [];
        foreach ($pages as $i => $page) {
            if (is_array($page) && is_string($page['id'] ?? null)) {
                if (isset($pageIds[$page['id']])) {
                    $this->err("/pages/$i/id", 'duplicate page id');
                }
                $pageIds[$page['id']] = $page;
            }
        }
        $targets = $knownPageIds === null ? array_keys($pageIds) : $knownPageIds;
        $interactionIds = [];
        foreach ($pages as $i => $page) {
            $this->page($page, "/pages/$i", $lang, $assets, $cards, $pageIds, array_flip($targets), $interactionIds);
        }

        return $this->errors;
    }

    // ── objects ─────────────────────────────────────────────────────────────────────────────

    private function asset(mixed $a, string $p): ?string
    {
        if (! $this->object($a, $p)) {
            return null;
        }
        $image = in_array($a['mediaType'] ?? null, self::IMAGE_TYPES, true);
        $media = in_array($a['mediaType'] ?? null, self::MEDIA_TYPES, true);
        $this->keys($a, $p, ['id', 'path', 'sha256', 'mediaType'], $image ? ['width', 'height'] : []);
        if (! $image && ! $media) {
            $this->err("$p/mediaType", 'is not an allowed media type');
        }
        $path = $a['path'] ?? null;
        if (! is_string($path) || strlen($path) < 8 || strlen($path) > 240 || ! preg_match('#^assets/[A-Za-z0-9_./-]+$#', $path)
            || preg_match('#(^|/)\.{1,2}(/|$)|//#', $path)) {
            $this->err("$p/path", 'must be a plain relative path under assets/ without . or .. segments');
        }
        if (! is_string($a['sha256'] ?? null) || ! preg_match('/^[0-9a-f]{64}$/', $a['sha256'])) {
            $this->err("$p/sha256", 'must be 64 lowercase hex digits');
        }
        if ($image) {
            foreach (['width', 'height'] as $k) {
                if (! is_int($a[$k] ?? null) || $a[$k] < 1 || $a[$k] > 40000) {
                    $this->err("$p/$k", 'must be an integer 1…40000');
                }
            }
        }

        return $this->id($a['id'] ?? null, "$p/id") ? ($image ? 'image' : 'media') : null;
    }

    private function card(mixed $c, string $p, ?string $lang, array $assets): ?string
    {
        if (! $this->object($c, $p)) {
            return null;
        }
        $this->keys($c, $p, ['id', 'kind', 'title'], ['body', 'imageAssetId']);
        if (! in_array($c['kind'] ?? null, self::CARD_KINDS, true)) {
            $this->err("$p/kind", 'must be one of '.implode('|', self::CARD_KINDS));
        }
        $this->localized($c['title'] ?? null, "$p/title", $lang, 500);
        if (array_key_exists('body', $c)) {
            $this->localized($c['body'], "$p/body", null, 20000);
        }
        if (array_key_exists('imageAssetId', $c) && ($assets[$c['imageAssetId']] ?? null) !== 'image') {
            $this->err("$p/imageAssetId", 'must name an image asset');
        }

        return $this->id($c['id'] ?? null, "$p/id") ? 'card' : null;
    }

    private function page(mixed $page, string $p, ?string $lang, array $assets, array $cards, array $pages, array $targets, array &$interactionIds): void
    {
        if (! $this->object($page, $p)) {
            return;
        }
        $this->keys($page, $p, ['id', 'surface', 'interactions'], ['anchors', 'textBlocks', 'groups', 'protectedAreas']);
        $this->id($page['id'] ?? null, "$p/id");
        $surface = $page['surface'] ?? 'missing';
        if ($surface === 'missing') {
            $this->err("$p/surface", 'is required (an image asset, or null for a text page)');
        } elseif ($surface !== null) {
            if ($this->object($surface, "$p/surface")) {
                $this->keys($surface, "$p/surface", ['assetId'], []);
                if (($assets[$surface['assetId'] ?? ''] ?? null) !== 'image') {
                    $this->err("$p/surface/assetId", 'must name an image asset');
                }
            }
        }

        $anchors = $this->list($page['anchors'] ?? [], "$p/anchors", 512, function ($a, $q) {
            if ($this->object($a, $q)) {
                $this->keys($a, $q, ['id', 'shape'], []);
                $this->shape($a['shape'] ?? null, "$q/shape");

                return $this->id($a['id'] ?? null, "$q/id") ? $a['id'] : null;
            }

            return null;
        });
        $groups = $this->list($page['groups'] ?? [], "$p/groups", 256, function ($g, $q) use ($lang) {
            if ($this->object($g, $q)) {
                $this->keys($g, $q, ['id', 'label'], []);
                $this->localized($g['label'] ?? null, "$q/label", $lang, 500);

                return $this->id($g['id'] ?? null, "$q/id") ? $g['id'] : null;
            }

            return null;
        });
        $runs = [];
        $this->list($page['textBlocks'] ?? [], "$p/textBlocks", 512, function ($b, $q) use (&$runs) {
            if (! $this->object($b, $q)) {
                return null;
            }
            $this->keys($b, $q, ['id', 'runs'], []);
            $this->id($b['id'] ?? null, "$q/id");
            $ids = $this->list($b['runs'] ?? null, "$q/runs", 2000, function ($r, $s) {
                if ($this->object($r, $s)) {
                    $this->keys($r, $s, ['id', 'text'], []);
                    if (! is_string($r['text'] ?? null) || $r['text'] === '' || mb_strlen($r['text']) > 20000) {
                        $this->err("$s/text", 'must be 1…20000 characters of plain text');
                    }

                    return $this->id($r['id'] ?? null, "$s/id") ? $r['id'] : null;
                }

                return null;
            }, 1);
            if (is_string($b['id'] ?? null)) {
                $runs[$b['id']] = array_flip($ids);
            }

            return $b['id'] ?? null;
        });
        $this->list($page['protectedAreas'] ?? [], "$p/protectedAreas", 256, function ($s, $q) {
            $this->shape($s, $q);

            return null;
        });

        $interactions = $page['interactions'] ?? null;
        if (! is_array($interactions) || ! array_is_list($interactions) || count($interactions) > 256) {
            $this->err("$p/interactions", 'must be an array of at most 256 interactions');

            return;
        }
        foreach ($interactions as $i => $it) {
            $q = "$p/interactions/$i";
            if (! $this->object($it, $q)) {
                continue;
            }
            $this->keys($it, $q, ['id', 'kind', 'label', 'anchor', 'action'], ['importance', 'order', 'groupId', 'presentation']);
            if ($this->id($it['id'] ?? null, "$q/id")) {
                if (isset($interactionIds[$it['id']])) {
                    $this->err("$q/id", 'duplicate interaction id (ids are unique in the whole profile)');
                }
                $interactionIds[$it['id']] = true;
            }
            if (! in_array($it['kind'] ?? null, self::KINDS, true)) {
                $this->err("$q/kind", 'must be one of '.implode('|', self::KINDS));
            }
            $this->localized($it['label'] ?? null, "$q/label", $lang, 500);
            $importance = $it['importance'] ?? 'supplementary';
            if (! in_array($importance, ['supplementary', 'essential'], true)) {
                $this->err("$q/importance", 'must be supplementary|essential');
            }
            if (array_key_exists('order', $it) && (! is_int($it['order']) || $it['order'] < 0 || $it['order'] > 10000)) {
                $this->err("$q/order", 'must be an integer 0…10000');
            }
            if (array_key_exists('groupId', $it) && ! in_array($it['groupId'], $groups, true)) {
                $this->err("$q/groupId", 'names no group of this page');
            }
            if (array_key_exists('presentation', $it) && $this->object($it['presentation'], "$q/presentation")) {
                $this->keys($it['presentation'], "$q/presentation", [], ['preferredEffect', 'strength']);
                if (array_key_exists('preferredEffect', $it['presentation']) && ! in_array($it['presentation']['preferredEffect'], self::EFFECTS, true)) {
                    $this->err("$q/presentation/preferredEffect", 'is not in the effect catalogue');
                }
                if (array_key_exists('strength', $it['presentation']) && ! in_array($it['presentation']['strength'], ['subtle', 'normal'], true)) {
                    $this->err("$q/presentation/strength", 'must be subtle|normal');
                }
            }
            $this->anchor($it['anchor'] ?? null, "$q/anchor", $surface, $runs);
            $type = $this->action($it['action'] ?? null, "$q/action", $assets, $cards, $pages, $targets);
            if ($importance === 'essential' && $type !== 'jump') {
                $this->err("$q/importance", 'essential is allowed only for jump');
            }
        }
    }

    private function anchor(mixed $a, string $p, mixed $surface, array $runs): void
    {
        if (! $this->object($a, $p)) {
            return;
        }
        if (($a['type'] ?? null) === 'region') {
            $this->keys($a, $p, ['type', 'shape'], ['outline']);
            if ($surface === null) {
                $this->err($p, 'a region needs an artwork surface; this page has none');
            }
            $this->shape($a['shape'] ?? null, "$p/shape");
            if (array_key_exists('outline', $a)) {
                $this->shape($a['outline'], "$p/outline");
            }
        } elseif (($a['type'] ?? null) === 'text') {
            $this->keys($a, $p, ['type', 'blockId', 'runId'], []);
            if (! isset($runs[$a['blockId'] ?? ''][$a['runId'] ?? ''])) {
                $this->err($p, 'names no text run of this page');
            }
        } else {
            $this->err("$p/type", 'must be region|text');
        }
    }

    private function action(mixed $a, string $p, array $assets, array $cards, array $pages, array $targets): ?string
    {
        if (! $this->object($a, $p)) {
            return null;
        }
        $type = $a['type'] ?? null;
        switch ($type) {
            case 'jump':
                $this->keys($a, $p, ['type', 'pageId'], ['anchorId']);
                if (! isset($targets[$a['pageId'] ?? ''])) {
                    $this->err("$p/pageId", 'names no page of this story');
                } elseif (array_key_exists('anchorId', $a)) {
                    $target = $pages[$a['pageId']] ?? null;
                    $known = array_column(is_array($target['anchors'] ?? null) ? $target['anchors'] : [], 'id');
                    if (! in_array($a['anchorId'], $known, true)) {
                        $this->err("$p/anchorId", 'names no anchor of the target page');
                    }
                }
                break;
            case 'open-card':
                $this->keys($a, $p, ['type', 'cardId'], []);
                if (($cards[$a['cardId'] ?? ''] ?? null) !== 'card') {
                    $this->err("$p/cardId", 'names no card of this profile');
                }
                break;
            case 'open-url':
                $this->keys($a, $p, ['type', 'url'], []);
                if (! self::safeHttpsUrl($a['url'] ?? null)) {
                    $this->err("$p/url", 'must be an absolute https:// URL without credentials or control characters');
                }
                break;
            case 'play-media':
                $this->keys($a, $p, ['type', 'assetId'], []);
                if (($assets[$a['assetId'] ?? ''] ?? null) !== 'media') {
                    $this->err("$p/assetId", 'must name an audio/video asset');
                }
                break;
            default:
                $this->err("$p/type", 'unknown action (jump|open-card|open-url|play-media)');

                return null;
        }

        return $type;
    }

    public static function safeHttpsUrl(mixed $url): bool
    {
        if (! is_string($url) || strlen($url) < 9 || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ! empty($parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass']) && str_starts_with($url, 'https://');
    }

    // ── geometry ────────────────────────────────────────────────────────────────────────────

    private function shape(mixed $s, string $p): void
    {
        if (! $this->object($s, $p)) {
            return;
        }
        $in = fn ($v) => (is_int($v) || is_float($v)) && $v >= 0 && $v <= 1000;
        switch ($s['type'] ?? null) {
            case 'rect':
                $this->keys($s, $p, ['type', 'x', 'y', 'width', 'height'], []);
                $ok = $in($s['x'] ?? null) && $in($s['y'] ?? null) && $in($s['width'] ?? null) && $in($s['height'] ?? null);
                if (! $ok || $s['width'] <= 0 || $s['height'] <= 0 || $s['x'] + $s['width'] > 1000 || $s['y'] + $s['height'] > 1000) {
                    $this->err($p, 'rect must have positive size and lie inside 0…1000');
                }
                break;
            case 'ellipse':
                $this->keys($s, $p, ['type', 'cx', 'cy', 'rx', 'ry'], []);
                $ok = $in($s['cx'] ?? null) && $in($s['cy'] ?? null) && $in($s['rx'] ?? null) && $in($s['ry'] ?? null);
                if (! $ok || $s['rx'] <= 0 || $s['ry'] <= 0 || $s['cx'] - $s['rx'] < 0 || $s['cx'] + $s['rx'] > 1000
                    || $s['cy'] - $s['ry'] < 0 || $s['cy'] + $s['ry'] > 1000) {
                    $this->err($p, 'ellipse must have positive radii and its box inside 0…1000');
                }
                break;
            case 'polygon':
                $this->keys($s, $p, ['type', 'points'], []);
                $pts = $s['points'] ?? null;
                if (! is_array($pts) || ! array_is_list($pts) || count($pts) < 3 || count($pts) > 512) {
                    $this->err("$p/points", 'must have 3…512 points');
                    break;
                }
                foreach ($pts as $pt) {
                    if (! is_array($pt) || count($pt) !== 2 || ! $in($pt[0] ?? null) || ! $in($pt[1] ?? null)) {
                        $this->err("$p/points", 'every point is [x, y] inside 0…1000');
                        break 2;
                    }
                }
                if (count(array_unique(array_map(fn ($pt) => $pt[0].','.$pt[1], $pts))) < 3 || Geometry::polygonSelfIntersects($pts)) {
                    $this->err("$p/points", 'must be a simple closed polygon with at least 3 distinct points');
                }
                break;
            case 'path':
                $this->keys($s, $p, ['type', 'd', 'fillRule'], []);
                if (! in_array($s['fillRule'] ?? null, ['nonzero', 'evenodd'], true)) {
                    $this->err("$p/fillRule", 'must be nonzero|evenodd');
                }
                if (($error = Geometry::checkPath($s['d'] ?? null)) !== null) {
                    $this->err("$p/d", $error);
                }
                break;
            default:
                $this->err("$p/type", 'must be rect|ellipse|polygon|path');
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────

    private function registry(mixed $list, string $p, int $max, callable $each): array
    {
        $out = [];
        if (! is_array($list) || ! array_is_list($list) || count($list) > $max) {
            $this->err($p, "must be an array of at most $max items");

            return $out;
        }
        foreach ($list as $i => $item) {
            $kind = $each($item, "$p/$i");
            if ($kind !== null) {
                if (isset($out[$item['id']])) {
                    $this->err("$p/$i/id", 'duplicate id');
                }
                $out[$item['id']] = $kind;
            }
        }

        return $out;
    }

    /** @return array<int, string> the ids the callback returned, checked for duplicates */
    private function list(mixed $list, string $p, int $max, callable $each, int $min = 0): array
    {
        if (! is_array($list) || ! array_is_list($list) || count($list) < $min || count($list) > $max) {
            $this->err($p, "must be an array of $min…$max items");

            return [];
        }
        $ids = [];
        foreach ($list as $i => $item) {
            $id = $each($item, "$p/$i");
            if ($id !== null) {
                if (in_array($id, $ids, true)) {
                    $this->err("$p/$i/id", 'duplicate id');
                }
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function localized(mixed $map, string $p, ?string $original, int $max): void
    {
        if (! is_array($map) || array_is_list($map) || $map === []) {
            $this->err($p, 'must be an object of language → text with at least one entry');

            return;
        }
        foreach ($map as $tag => $text) {
            if (! preg_match(self::LANG, (string) $tag)) {
                $this->err("$p/$tag", 'is not a language tag');
            }
            if (! is_string($text) || trim($text) === '' || mb_strlen($text) > $max) {
                $this->err("$p/$tag", "must be 1…$max characters");
            }
        }
        if ($original !== null && trim((string) ($map[$original] ?? '')) === '') {
            $this->err($p, "needs text in the original language ($original)");
        }
    }

    private function keys(array $o, string $p, array $required, array $optional): void
    {
        foreach ($required as $k) {
            if (! array_key_exists($k, $o)) {
                $this->err("$p/$k", 'is required');
            }
        }
        foreach (array_diff(array_keys($o), $required, $optional) as $k) {
            $this->err("$p/$k", 'is not a field of this object (unknown fields are rejected)');
        }
    }

    private function object(mixed $v, string $p): bool
    {
        if (! is_array($v) || ($v !== [] && array_is_list($v))) {
            $this->err($p, 'must be an object');

            return false;
        }

        return true;
    }

    private function id(mixed $v, string $p): bool
    {
        if (! is_string($v) || ! preg_match(self::ID, $v)) {
            $this->err($p, 'must be an id: 1…160 of A-Z a-z 0-9 . _ : - starting with a letter or digit');

            return false;
        }

        return true;
    }

    private function err(string $path, string $message): void
    {
        $this->errors[] = ($path === '' ? '/' : $path).': '.$message;
    }
}
