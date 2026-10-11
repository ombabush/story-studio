<?php

namespace Ombabush\StoryStudio\Support;

use InvalidArgumentException;
use Ombabush\StoryStudio\Models\Node;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;
use Ombabush\StoryStudio\Models\Text;

/**
 * Translations of an edition (ss_texts, docs/DATA-MODEL.md §1.2). The original stays in
 * the subject's own columns; a row here holds one language's fields for one story, node or page.
 * An empty field is "not translated" — the reader falls back along its language chain.
 */
class Texts
{
    public const FIELDS = [
        'story' => ['title', 'description'],
        'node' => ['title'],
        'page' => ['caption', 'body', 'alt', 'time_label', 'time_place', 'dialogue'],
    ];

    /** Fields only an editor receives, as with the original column. */
    public const INTERNAL = ['page' => ['dialogue']];

    /** @var array<int, array> story id → [subject][subject id][lang] => fields */
    private array $memo = [];

    public function all(Story $story): array
    {
        return $this->memo[$story->id] ??= Text::where('story_id', $story->id)->orderBy('id')->get()
            ->reduce(function (array $out, Text $t) {
                $out[$t->subject][$t->subject_id][$t->lang] = (array) $t->fields;

                return $out;
            }, []);
    }

    /** @return array<string, array<string, string>> lang => fields of one subject */
    public function of(Story $story, string $subject, string $subjectId): array
    {
        return $this->all($story)[$subject][$subjectId] ?? [];
    }

    /**
     * Merge fields of one language into a subject. Empty strings remove a field; a row left with no
     * fields is deleted. Unknown fields are ignored. Returns the fields now stored.
     */
    public function set(Story $story, string $subject, string $subjectId, string $lang, array $fields): array
    {
        if (! isset(self::FIELDS[$subject])) {
            throw new InvalidArgumentException('Unknown subject.');
        }
        if (! ($lang = Edition::tag($lang)) || $lang === Edition::of($story)->original) {
            throw new InvalidArgumentException('Not a translation language for this edition.');
        }
        $row = Text::firstOrNew(['story_id' => $story->id, 'subject' => $subject, 'subject_id' => $subjectId, 'lang' => $lang]);
        $stored = (array) ($row->fields ?? []);
        foreach (array_intersect_key($fields, array_flip(self::FIELDS[$subject])) as $field => $value) {
            $value = trim(str_replace("\r\n", "\n", (string) $value));
            if ($value === '') {
                unset($stored[$field]);
            } else {
                $stored[$field] = mb_substr($value, 0, $field === 'body' ? 50000 : 5000);
            }
        }
        unset($this->memo[$story->id]);
        if (! $stored) {
            $row->exists && $row->delete();

            return [];
        }
        $row->fields = $stored;
        $row->save();

        return $stored;
    }

    /** Public fields only (what a reader or a snapshot may carry). */
    public function public(string $subject, array $fields): array
    {
        return array_diff_key($fields, array_flip(self::INTERNAL[$subject] ?? []));
    }

    /**
     * Translations of one subject for the Reader: the edition's offered languages for a reader, every
     * stored language for an editor (who previews unfinished ones); internal fields only for an editor.
     */
    public function forReader(Story $story, string $subject, string $subjectId, bool $editor): array
    {
        $offered = Edition::of($story)->languages;
        $out = [];
        foreach ($this->of($story, $subject, $subjectId) as $lang => $fields) {
            if (! $editor && ! in_array($lang, $offered, true)) {
                continue;
            }
            $fields = $editor ? $fields : $this->public($subject, $fields);
            if ($fields) {
                $out[$lang] = $fields;
            }
        }

        return $out;
    }

    /** How many of the given pages have anything in this language. */
    public function coverage(Story $story, string $lang, iterable $pages): array
    {
        $byPage = $this->all($story)['page'] ?? [];
        $n = 0;
        $total = 0;
        foreach ($pages as $page) {
            $total++;
            if ($this->public('page', $byPage[$page->public_id][$lang] ?? [])) {
                $n++;
            }
        }

        return ['n' => $n, 'total' => $total];
    }

    /** Public translations of the published subjects, for the snapshot. */
    public function export(Story $story, array $pageIds, array $nodeIds): array
    {
        $allowed = ['story' => [$story->public_id => true], 'node' => array_flip($nodeIds), 'page' => array_flip($pageIds)];
        $out = [];
        foreach ($this->all($story) as $subject => $ids) {
            foreach ($ids as $id => $langs) {
                if (! isset($allowed[$subject][$id])) {
                    continue;
                }
                foreach ($langs as $lang => $fields) {
                    if ($fields = $this->public($subject, $fields)) {
                        $out[] = ['subject' => $subject, 'id' => $id, 'lang' => $lang, 'fields' => $fields];
                    }
                }
            }
        }

        return $out;
    }

    /** Mirror a snapshot's translations: exactly these rows, ids mapped through $map when given. */
    public function replace(Story $story, array $rows, array $map = []): void
    {
        Text::where('story_id', $story->id)->delete();
        unset($this->memo[$story->id]);
        foreach ($rows as $r) {
            $subject = (string) ($r['subject'] ?? '');
            if ($map && $subject !== 'story' && ! isset($map[$r['id'] ?? ''])) {
                continue;   // a subject the import did not bring
            }
            $id = $subject === 'story' ? $story->public_id : ($map[$r['id'] ?? ''] ?? (string) ($r['id'] ?? ''));
            $lang = Edition::tag($r['lang'] ?? null);
            if (! isset(self::FIELDS[$subject]) || ! $lang || $id === '' || ! is_array($r['fields'] ?? null)) {
                continue;
            }
            $fields = array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : '', $this->public($subject,
                array_intersect_key($r['fields'], array_flip(self::FIELDS[$subject])))), fn ($v) => $v !== '');
            if ($fields) {
                Text::create(['story_id' => $story->id, 'subject' => $subject, 'subject_id' => $id, 'lang' => $lang, 'fields' => $fields]);
            }
        }
    }

    /** A subject was deleted: its translations go with it. */
    public static function forget(string $subject, Page|Node $model): void
    {
        Text::where('story_id', $model->story_id)->where('subject', $subject)->where('subject_id', $model->public_id)->delete();
    }
}
