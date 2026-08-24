<?php

namespace App\Service;

use App\Service\Translation\TranslationResult;
use App\Service\Translation\Translator;
use App\Support\SelectorXPathBuilder;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Log;

/**
 * Перевод статьи на русский.
 *
 * Извлекает содержимое по селектору, отдаёт его движку перевода и локализует
 * картинки. Сам движок сервису неизвестен: Translator собирается в
 * AppServiceProvider и может оказаться как LLM, так и прежним скрейпером —
 * снаружи разница видна только в поле translated_by у поста.
 *
 * До 20.08.2026 сервис обходил DOM поблочно и переводил каждый абзац
 * отдельным запросом: скрейпер translate.google.com не понимает HTML, и
 * разметку приходилось прятать за плейсхолдерами. LLM понимает её сама,
 * поэтому содержимое уходит одним куском — вместе с контекстом, из-за потери
 * которого термин из первого абзаца в десятом переводился иначе.
 */
class TranslateService
{
    public function __construct(
        private readonly ContentImageService $imageService,
        private readonly Translator $translator,
    ) {}

    public function translate(mixed $data): mixed
    {
        $incomplete = false;

        try {
            $dom = new DOMDocument;
            @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$data['content'], LIBXML_NOERROR | LIBXML_NOWARNING);

            $titleResult = $this->translator->translateText((string) ($data['title'] ?? ''));
            $data['title'] = $titleResult->text;
            $incomplete = $titleResult->failed;

            $finder = new DOMXPath($dom);

            // Панель кнопок «copy/expand» у подсветки кода на dev.to — чистый
            // интерфейс площадки, в статье ей делать нечего.
            $panels = $finder->query("//div[contains(@class, 'highlight__panel') and contains(@class, 'js-actions-panel')]");
            foreach ($panels as $panel) {
                $panel->parentNode->removeChild($panel);
            }

            if (! empty($data['selector'])) {
                // Через SelectorXPathBuilder, а не конкатенацией: selector
                // приходит из формы админки, и кавычка в нём иначе ломает
                // структуру XPath-выражения (XPath injection, CWE-91).
                $nodes = $finder->query(SelectorXPathBuilder::build((string) $data['selector']));
            } else {
                // Содержимое <body>, а не корень документа: там лежат ещё
                // processing instruction от loadHTML и обёртка <html>. Раньше
                // это было безобидно — обход DOM просто спускался глубже, — но
                // теперь фрагмент уходит в промпт целиком, и разбиение длинных
                // статей видело бы ровно один верхнеуровневый узел <html>,
                // то есть не срабатывало бы никогда.
                $nodes = $finder->query('//body/node()');
            }

            $source = '';
            foreach ($nodes as $node) {
                $source .= $dom->saveHTML($node);
            }

            $result = $this->translator->translateHtml($source);
            $incomplete = $incomplete || $result->failed || $result->partial;

            $postContent = $this->modifyContent($result->text);
            $postContent = $this->imageService->replacePictureElements($postContent);
            $postContent = $this->imageService->downloadAndReplaceImages($postContent);

            if (! empty($postContent)) {
                $data['content'] = $postContent;
            }

            // По результату, а не по тому, кто взялся за работу — см. тот же
            // разбор в StorePostJob::translateContentNodes. Этот путь ведёт из
            // админки и post:translate, и врать он должен не меньше того.
            $translated = $result->text !== $source;

            if (! $translated) {
                $incomplete = true;
            }

            $data['translated_by'] = $translated ? $result->engine : TranslationResult::NO_ENGINE;

            Log::debug('TranslateService::translate: done', [
                'title' => $data['title'] ?? null,
                'engine' => $data['translated_by'],
            ]);
        } catch (\Throwable $exception) {
            // Исключение до/во время перевода — контент остался (частично)
            // непереведённым. Пост важнее перевода: пятисотка в очереди стоила
            // бы статьи целиком.
            $incomplete = true;
            Log::error('TranslateService::translate failed', [
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
        }

        $data['translation_incomplete'] = $incomplete;

        return $data;
    }

    /**
     * Пробелы вокруг инлайн-тегов: без них соседние слово и код слипаются в
     * вёрстке статьи.
     */
    private function modifyContent(string $postContent): string
    {
        return str_replace(
            ['<code>', '</code>', '<strong>'],
            ['&nbsp;<code>', '</code>&nbsp;', '&nbsp;<strong>'],
            $postContent
        );
    }
}
