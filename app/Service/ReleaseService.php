<?php

namespace App\Service;

use App\Jobs\StorePostJob;
use App\Models\Release;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReleaseService
{
    public function store($data)
    {
        try {
            DB::beginTransaction();

            $release = Release::create([
                'url' => $data['url']
            ]);

            DB::commit();

            return $release;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update($data, $release)
    {
        try {
            DB::beginTransaction();

            $release->update([
                'url' => $data['url']
            ]);

            DB::commit();

            return $release;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function parsePostsUrl($url): void
    {
        // Инициализация Guzzle для выполнения HTTP-запроса
        $client = new Client();
        try {
            $response = $client->get($url);
            $html = $response->getBody()->getContents();
        } catch (\Exception $e) {
            \Log::error('Failed to fetch the page: ' . $e->getMessage());
            return;
        }

        // Парсинг HTML с помощью DOMDocument
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true); // Подавление ошибок парсинга
        $dom->loadHTML($html);
        libxml_clear_errors();

        // Используем DOMXPath для поиска всех ссылок
        $xpath = new \DOMXPath($dom);

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
                'url'      => $href,
                'title'    => $description,
                'selector' => '.content'
            ];
        }

        // Логирование результатов
        if (empty($linkList)) {
            Log::info('No links found on the page.');
        } else {
            Log::info('Found links with descriptions:', $linkList);

            foreach ($linkList as $linkListItem) {
                StorePostJob::dispatch($linkListItem);
            }
        }
    }
}
