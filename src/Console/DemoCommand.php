<?php

namespace Ombabush\StoryStudio\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Ombabush\StoryStudio\Actions\StoryEditor;
use Ombabush\StoryStudio\Models\Node;
use Ombabush\StoryStudio\Models\Page;

/**
 * One edition with all five page types, two chapters in a volume, story time and a draft — enough to look
 * at every Reader and Studio state without real material. Pictures are drawn here with GD: nothing to
 * download, nothing copyrighted.
 */
class DemoCommand extends Command
{
    protected $signature = 'story-studio:demo {--owner= : user id that owns the edition} {--publish : publish it}';

    protected $description = 'Create a demo edition with every page type (image, spread, panorama, text, mixed)';

    public function handle(StoryEditor $editor): int
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->error('The demo draws its pages with GD, and ext-gd is not loaded.');

            return self::FAILURE;
        }
        $tmp = storage_path('app/story-studio-tmp/demo-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($tmp);

        try {
            $story = $editor->createStory(['title' => 'Демо: Маяк', 'authors' => 'Story Studio',
                'description' => 'Пример издания со всеми типами страниц.'], null);
            $story->forceFill(['owner_id' => $this->option('owner')])->save();

            $volume = $editor->addNode($story, Node::VOLUME, 'Том 1');
            $one = $editor->addNode($story, Node::CHAPTER, 'Прибытие', $volume);
            $two = $editor->addNode($story, Node::CHAPTER, 'Шторм', $volume);

            $pages = $editor->addImagePages($story, $one, [
                ['file' => $this->draw($tmp, 'p1', 900, 1300, [40, 70, 110], 'I'), 'name' => 'p1.png'],
                ['file' => $this->draw($tmp, 'p2', 900, 1300, [70, 50, 90], 'II'), 'name' => 'p2.png'],
                ['file' => $this->draw($tmp, 'spread', 1800, 1300, [30, 90, 80], 'РАЗВОРОТ'), 'name' => 'spread.png'],
            ], null, Page::READY);
            $editor->updatePage($pages[0], ['caption' => 'Остров показался на рассвете.', 'time_label' => 'День 1', 'time_rank' => 1,
                'note' => 'Проверить цвет неба.', 'dialogue' => "КАПИТАН: Земля!\nЮНГА: Это маяк?"]);
            $editor->updatePage($pages[1], ['caption' => 'Маяк стоял тёмный.', 'body' => "Никто не помнил, когда он горел в последний раз.\n\nНо дверь была не заперта."]);
            $editor->updatePage($pages[2], ['caption' => 'Вид с галереи маяка.']);

            $editor->addTextPage($story, $one, ['caption' => 'Из дневника смотрителя', 'status' => Page::READY,
                'body' => "Третью неделю ветер не меняется.\n\nЯ записываю это не для себя: тот, кто придёт после, должен знать, что огонь гас не сам."]);

            $storm = $editor->addImagePages($story, $two, [
                ['file' => $this->draw($tmp, 'pano', 3600, 1000, [20, 40, 60], 'ПАНОРАМА'), 'name' => 'pano.png'],
                ['file' => $this->draw($tmp, 'mixed', 900, 1300, [90, 60, 30], 'III'), 'name' => 'mixed.png', 'type' => 'mixed'],
                ['file' => $this->draw($tmp, 'draft', 900, 1300, [60, 60, 60], 'IV'), 'name' => 'draft.png'],
            ], null, Page::READY);
            $editor->updatePage($storm[0], ['caption' => 'Шторм шёл с запада.', 'time_label' => 'Ночь 2', 'time_rank' => 2]);
            $editor->updatePage($storm[1], ['caption' => 'Смотритель', 'body' => "Он поднимался по лестнице, считая ступени.\n\nНа сто двенадцатой он понял, что свет внизу — не отражение.\n\nКто-то зажёг огонь раньше него."]);
            $editor->updatePage($storm[2], ['status' => Page::DRAFT, 'caption' => 'Не дорисовано']);

            if ($this->option('publish')) {
                $editor->publish($story);
            }
        } finally {
            File::deleteDirectory($tmp);
        }

        $this->info("Demo edition «{$story->title}» created: {$story->public_id}");

        return self::SUCCESS;
    }

    private function draw(string $dir, string $name, int $w, int $h, array $rgb, string $label): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));
        $light = imagecolorallocate($img, min(255, $rgb[0] + 70), min(255, $rgb[1] + 70), min(255, $rgb[2] + 70));
        // Panel borders, so the page reads as a comic page at thumbnail size.
        for ($i = 0; $i < 6; $i++) {
            imagerectangle($img, 40 + $i, 40 + $i, $w - 41 - $i, $h - 41 - $i, $light);
        }
        imageline($img, 40, intdiv($h, 2), $w - 40, intdiv($h, 2), $light);
        imagestring($img, 5, 70, 70, $label, $light);
        $path = $dir.'/'.$name.'.png';
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }
}
