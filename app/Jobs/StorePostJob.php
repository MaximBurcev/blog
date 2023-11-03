<?php

namespace App\Jobs;

use App\Service\PostService;
use DOMDocument;
use DOMXPath;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Stichoza\GoogleTranslate\GoogleTranslate;

class StorePostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $data;
    private PostService $service;

    /**
     * Create a new job instance.
     */
    public function __construct($data)
    {
        //
        $this->data = $data;
        $this->service = new PostService();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $googleTranslate = new GoogleTranslate('ru');
        try {
            $dom = new DOMDocument();
            //dd(file_get_contents($data['url']));
            @$dom->loadHTML(@file_get_contents($this->data['url']));
            $h1 = $dom->getElementsByTagName("h1");
            if ($h1->length > 0) {
                $title = $h1->item(0)->nodeValue;
                $this->data['title'] = $googleTranslate->translate($title);
                $this->data['code'] = Str::slug($title);

                $finder = new DomXPath($dom);
                $nodes = $finder->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $data['selector'] . " ')]");

                ob_start();
                foreach ($nodes as $node) {
                    $this->showDOMNode($node);
                }

                $this->data['content'] = ob_get_contents();

            }
        } catch (\Exception $exception) {
            logger($exception->getMessage());
        }

        $this->service->store($this->data);
    }

    private function showDOMNode(DOMNode $domNode) {
        $googleTranslate = new GoogleTranslate('ru');
        foreach ($domNode->childNodes as $node)
        {
            echo $googleTranslate->translate($node->nodeValue);
            if($node->hasChildNodes()) {
                $this->showDOMNode($node);
            }
        }

    }
}
