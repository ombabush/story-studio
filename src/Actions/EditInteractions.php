<?php

namespace Ombabush\StoryStudio\Actions;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Ombabush\StoryStudio\Interaction\Draft;
use Ombabush\StoryStudio\Interaction\ProfileValidator;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;
use Ombabush\StoryStudio\Support\Version;

/**
 * Every change the Studio's Interaction layer can make to an edition's draft profile, without Livewire.
 * Authorization is the caller's (Access), as for StoryEditor.
 *
 * Saves are optimistic: the caller passes the version it edited (`version()`), and a draft changed
 * meanwhile by another editor is a conflict, not a silent overwrite of their geometry (C-39). The draft
 * may be invalid after any call — that is what drafts are for; Draft::analyse() says what is wrong.
 */
class EditInteractions
{
    public const CONFLICT = 'The interactions were changed by someone else; reload to see their version.';

    public static function version(Story $story): string
    {
        return md5(json_encode([$story->interaction, $story->interaction_surfaces]));
    }

    public function language(Story $story, string $expect, string $language): string
    {
        return $this->change($story, $expect, function (array &$d) use ($language) {
            if (! preg_match('/^[A-Za-z]{2,8}(-[A-Za-z0-9]{1,8})*$/', $language)) {
                throw new InvalidArgumentException('Not a language tag.');
            }
            $d['language'] = $language;
        });
    }

    /** A new region on a page, drawn on its current picture. Returns [version, interaction id]. */
    public function addRegion(Story $story, string $expect, string $pageId, array $shape): array
    {
        $id = 'IX_'.strtoupper(Str::random(10));
        $version = $this->change($story, $expect, function (array &$d) use ($pageId, $shape, $id) {
            $page = &$this->page($d, $pageId);
            $page['interactions'][] = ['id' => $id, 'kind' => 'object', 'label' => [$d['language'] => ''],
                'anchor' => ['type' => 'region', 'shape' => $shape], 'action' => ['type' => 'open-card', 'cardId' => '']];
        }, $pageId);

        return [$version, $id];
    }

    /** The hit shape, or the precise outline (null removes the outline). Re-binds the page's picture. */
    public function setShape(Story $story, string $expect, string $id, ?array $shape, string $which = 'shape'): string
    {
        $pageId = $this->pageOf($story, $id);

        return $this->change($story, $expect, function (array &$d) use ($id, $shape, $which) {
            $it = &$this->item($d, $id);
            if (($it['anchor']['type'] ?? null) !== 'region') {
                throw new InvalidArgumentException('Only a region has a shape.');
            }
            if ($which === 'outline' && $shape === null) {
                unset($it['anchor']['outline']);
            } elseif (in_array($which, ['shape', 'outline'], true) && $shape !== null) {
                $it['anchor'][$which] = $shape;
            }
        }, $pageId);
    }

    /** Kind, label (per language), action, importance, group, preferred effect. Unknown keys are ignored. */
    public function update(Story $story, string $expect, string $id, array $fields): string
    {
        return $this->change($story, $expect, function (array &$d) use ($id, $fields) {
            $it = &$this->item($d, $id);
            if (isset($fields['kind']) && in_array($fields['kind'], ProfileValidator::KINDS, true)) {
                $it['kind'] = $fields['kind'];
            }
            if (isset($fields['label']) && is_array($fields['label'])) {
                $it['label'] = array_filter(array_map(fn ($t) => trim((string) $t), $fields['label']), fn ($t) => $t !== '') ?: [$d['language'] => ''];
            }
            if (isset($fields['action']) && is_array($fields['action'])) {
                $it['action'] = $this->action($fields['action']);
                if ($it['action']['type'] !== 'jump') {
                    unset($it['importance']);
                }
            }
            if (array_key_exists('essential', $fields)) {
                if ($fields['essential'] && ($it['action']['type'] ?? null) === 'jump') {
                    $it['importance'] = 'essential';
                } else {
                    unset($it['importance']);
                }
            }
            if (array_key_exists('groupId', $fields)) {
                if ($fields['groupId']) {
                    $it['groupId'] = (string) $fields['groupId'];
                } else {
                    unset($it['groupId']);
                }
            }
            if (array_key_exists('effect', $fields)) {
                if (in_array($fields['effect'], ProfileValidator::EFFECTS, true)) {
                    $it['presentation'] = ['preferredEffect' => $fields['effect']];
                } else {
                    unset($it['presentation']);
                }
            }
        });
    }

    public function delete(Story $story, string $expect, string $id): string
    {
        return $this->change($story, $expect, function (array &$d) use ($id) {
            foreach ($d['pages'] as &$p) {
                $p['interactions'] = array_values(array_filter($p['interactions'], fn ($it) => ($it['id'] ?? null) !== $id));
            }
        });
    }

    /** Order inside the page: what the list, the chooser and the keyboard follow. */
    public function move(Story $story, string $expect, string $id, int $delta): string
    {
        return $this->change($story, $expect, function (array &$d) use ($id, $delta) {
            foreach ($d['pages'] as &$p) {
                $ids = array_column($p['interactions'], 'id');
                $at = array_search($id, $ids, true);
                if ($at !== false && isset($p['interactions'][$at + $delta])) {
                    [$p['interactions'][$at], $p['interactions'][$at + $delta]] = [$p['interactions'][$at + $delta], $p['interactions'][$at]];
                }
            }
        });
    }

    /** Create or update a card; returns [version, card id]. Cards are public text: no private dossier. */
    public function card(Story $story, string $expect, array $card): array
    {
        $id = (string) ($card['id'] ?? '') ?: 'CARD_'.strtoupper(Str::random(8));
        $version = $this->change($story, $expect, function (array &$d) use ($card, $id) {
            $clean = ['id' => $id, 'kind' => in_array($card['kind'] ?? null, ProfileValidator::CARD_KINDS, true) ? $card['kind'] : 'character',
                'title' => array_filter(array_map('trim', (array) ($card['title'] ?? [])), fn ($t) => $t !== '') ?: [$d['language'] => '']];
            $body = array_filter(array_map('trim', (array) ($card['body'] ?? [])), fn ($t) => $t !== '');
            if ($body) {
                $clean['body'] = $body;
            }
            $at = array_search($id, array_column($d['cards'], 'id'), true);
            if ($at === false) {
                $d['cards'][] = $clean;
            } else {
                $d['cards'][$at] = $clean;
            }
        });

        return [$version, $id];
    }

    public function deleteCard(Story $story, string $expect, string $cardId): string
    {
        return $this->change($story, $expect, function (array &$d) use ($cardId) {
            $d['cards'] = array_values(array_filter($d['cards'], fn ($c) => ($c['id'] ?? null) !== $cardId));
        });
    }

    /** A named group on a page (a drawn menu, «Продолжить историю»). Returns [version, group id]. */
    public function group(Story $story, string $expect, string $pageId, string $label): array
    {
        $id = 'GROUP_'.strtoupper(Str::random(6));
        $version = $this->change($story, $expect, function (array &$d) use ($pageId, $label, $id) {
            $page = &$this->page($d, $pageId);
            $page['groups'][] = ['id' => $id, 'label' => [$d['language'] => trim($label)]];
        });

        return [$version, $id];
    }

    /** Areas a preview must not cover (balloons, important text). */
    public function protect(Story $story, string $expect, string $pageId, ?array $shape, ?int $remove = null): string
    {
        return $this->change($story, $expect, function (array &$d) use ($pageId, $shape, $remove) {
            $page = &$this->page($d, $pageId);
            $areas = (array) ($page['protectedAreas'] ?? []);
            if ($remove !== null) {
                unset($areas[$remove]);
            } elseif ($shape) {
                $areas[] = $shape;
            }
            if ($areas) {
                $page['protectedAreas'] = array_values($areas);
            } else {
                unset($page['protectedAreas']);
            }
        });
    }

    /**
     * Turn characters [start, end) of the page's published caption or a paragraph into a link: the text
     * becomes a block of runs whose concatenation is exactly that text (FORMAT «textBlocks»).
     * Returns [version, interaction id].
     */
    public function textLink(Story $story, string $expect, string $pageId, string $target, string $text, int $start, int $end): array
    {
        $id = 'IX_'.strtoupper(Str::random(10));
        $len = mb_strlen($text);
        if ($start < 0 || $end > $len || $end <= $start || trim(mb_substr($text, $start, $end - $start)) === '') {
            throw new InvalidArgumentException('Select a piece of the text first.');
        }
        $version = $this->change($story, $expect, function (array &$d) use ($pageId, $target, $text, $start, $end, $id) {
            $page = &$this->page($d, $pageId);
            $blockId = 'TB_'.strtoupper($target);
            $runs = array_values(array_filter([
                ['id' => $blockId.'_A'.$start, 'text' => mb_substr($text, 0, $start)],
                ['id' => $blockId.'_L'.$start.'_'.$end, 'text' => mb_substr($text, $start, $end - $start)],
                ['id' => $blockId.'_B'.$end, 'text' => mb_substr($text, $end)],
            ], fn ($r) => $r['text'] !== ''));
            $linkRun = $blockId.'_L'.$start.'_'.$end;
            // One link per block in this editor: a new selection replaces the earlier split and its link.
            $page['interactions'] = array_values(array_filter($page['interactions'],
                fn ($it) => ! (($it['anchor']['type'] ?? null) === 'text' && ($it['anchor']['blockId'] ?? null) === $blockId)));
            $blocks = array_values(array_filter((array) ($page['textBlocks'] ?? []), fn ($b) => ($b['id'] ?? null) !== $blockId));
            $blocks[] = ['id' => $blockId, 'runs' => $runs];
            $page['textBlocks'] = $blocks;
            $page['interactions'][] = ['id' => $id, 'kind' => 'character', 'label' => [$d['language'] => mb_substr($text, $start, $end - $start)],
                'anchor' => ['type' => 'text', 'blockId' => $blockId, 'runId' => $linkRun], 'action' => ['type' => 'open-card', 'cardId' => '']];
        });

        return [$version, $id];
    }

    /** «These contours are right for the new picture» — after a replacement, the author's explicit review. */
    public function confirmSurface(Story $story, string $expect, string $pageId): string
    {
        return $this->change($story, $expect, fn () => null, $pageId);
    }

    /** Replace the whole draft (Undo/Redo). The version guard still applies. */
    public function restore(Story $story, string $expect, ?array $draft, ?array $surfaces): string
    {
        if (self::version($story) !== $expect) {
            throw new InvalidArgumentException(self::CONFLICT);
        }
        $story->forceFill(['interaction' => $draft, 'interaction_surfaces' => $surfaces])->save();

        return self::version($story);
    }

    // ── internals ───────────────────────────────────────────────────────────────────────────

    private function change(Story $story, string $expect, callable $edit, ?string $bindPage = null): string
    {
        $story->refresh();
        if (self::version($story) !== $expect) {
            throw new InvalidArgumentException(self::CONFLICT);
        }
        $draft = Draft::of($story);
        $edit($draft);
        $draft['pages'] = array_values(array_filter($draft['pages'], fn ($p) => $p['interactions'] || ! empty($p['textBlocks'])
            || ! empty($p['protectedAreas']) || ! empty($p['groups']) || ! empty($p['anchors'])));
        $surfaces = (array) ($story->interaction_surfaces ?? []);
        if ($bindPage !== null) {
            // The contours now belong to the picture the author is looking at.
            $image = Page::where('story_id', $story->id)->where('public_id', $bindPage)->first()?->currentImage;
            if ($image) {
                $surfaces[$bindPage] = Version::sha1($image);
            }
        }
        $story->forceFill(['interaction' => $draft, 'interaction_surfaces' => $surfaces ?: null])->save();

        return self::version($story);
    }

    private function &page(array &$d, string $pageId): array
    {
        foreach ($d['pages'] as &$p) {
            if ($p['id'] === $pageId) {
                return $p;
            }
        }
        $d['pages'][] = ['id' => $pageId, 'interactions' => []];

        return $d['pages'][array_key_last($d['pages'])];
    }

    private function &item(array &$d, string $id): array
    {
        foreach ($d['pages'] as &$p) {
            foreach ($p['interactions'] as &$it) {
                if (($it['id'] ?? null) === $id) {
                    return $it;
                }
            }
        }
        throw new InvalidArgumentException("No interaction $id.");
    }

    private function pageOf(Story $story, string $id): ?string
    {
        foreach (Draft::of($story)['pages'] as $p) {
            if (in_array($id, array_column($p['interactions'], 'id'), true)) {
                return $p['id'];
            }
        }

        return null;
    }

    /** Only the four closed actions, with only their own parameters. */
    private function action(array $a): array
    {
        return match ($a['type'] ?? null) {
            'jump' => array_filter(['type' => 'jump', 'pageId' => (string) ($a['pageId'] ?? ''), 'anchorId' => $a['anchorId'] ?? null], fn ($v) => $v !== null),
            'open-url' => ['type' => 'open-url', 'url' => trim((string) ($a['url'] ?? ''))],
            'play-media' => ['type' => 'play-media', 'assetId' => (string) ($a['assetId'] ?? '')],
            default => ['type' => 'open-card', 'cardId' => (string) ($a['cardId'] ?? '')],
        };
    }
}
