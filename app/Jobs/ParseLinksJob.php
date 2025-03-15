<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use GuzzleHttp\Client;
use DOMDocument;
use DOMXPath;

class ParseLinksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * URL для парсинга.
     *
     * @var string
     */
    protected $url;

    /**
     * Create a new job instance.
     *
     * @param string $url
     */
    public function __construct(string $url)
    {
        $this->url = $url;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Инициализация Guzzle для выполнения HTTP-запроса
        $client = new Client();
        try {
            $response = $client->get($this->url);
            $html = $response->getBody()->getContents();
        } catch (\Exception $e) {
            \Log::error('Failed to fetch the page: ' . $e->getMessage());
            return;
        }

        // Парсинг HTML с помощью DOMDocument
        $dom = new DOMDocument();
        libxml_use_internal_errors(true); // Подавление ошибок парсинга
        $dom->loadHTML($html);
        libxml_clear_errors();

        // Используем DOMXPath для поиска всех ссылок
        $xpath = new DOMXPath($dom);

        // Выбираем все элементы <a>, которые содержат атрибут href
        $linkNodes = $xpath->query('//a[@href]');

        // Сбор ссылок и их описаний
        $linkList = [];
        foreach ($linkNodes as $linkNode) {
            $href = $linkNode->getAttribute('href'); // Получаем ссылку
            $description = trim($linkNode->textContent); // Получаем текст внутри тега <a>

            // Если описание пустое, проверяем соседние элементы
            if (empty($description)) {
                $nextSibling = $linkNode->nextSibling;
                if ($nextSibling && $nextSibling->nodeType === XML_TEXT_NODE) {
                    $description = trim($nextSibling->textContent);
                }
            }

            $linkList[] = [
                'href' => $href,
                'description' => $description,
            ];
        }

        // Логирование результатов
        if (empty($linkList)) {
            \Log::info('No links found on the page.');
        } else {
            \Log::info('Found links with descriptions:', $linkList);
        }
    }
}
