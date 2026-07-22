<?php

namespace App\DataTransferObjects;

/**
 * Typed carrier for the data PostService::store()/update() work with.
 * Boundary-only DTO: PostService converts it back to an array immediately
 * via toArray() and keeps its existing array-based logic untouched.
 */
class PostData
{
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $content = null,
        public readonly ?string $contentOrig = null,
        public readonly ?int $categoryId = null,
        public readonly ?array $tagIds = null,
        public readonly mixed $previewImage = null,
        public readonly mixed $mainImage = null,
        public readonly mixed $translate = null,
        public readonly ?string $url = null,
        public readonly ?string $selector = null,
        public readonly ?string $htmlFile = null,
        public readonly ?string $code = null,
        public readonly ?bool $published = null,
        public readonly ?bool $translationIncomplete = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            title: $data['title'] ?? null,
            content: $data['content'] ?? null,
            contentOrig: $data['content_orig'] ?? null,
            categoryId: isset($data['category_id']) ? (int) $data['category_id'] : null,
            tagIds: $data['tag_ids'] ?? null,
            previewImage: $data['preview_image'] ?? null,
            mainImage: $data['main_image'] ?? null,
            translate: $data['translate'] ?? null,
            url: $data['url'] ?? null,
            selector: $data['selector'] ?? null,
            htmlFile: $data['html_file'] ?? null,
            code: $data['code'] ?? null,
            published: isset($data['published']) ? (bool) $data['published'] : null,
            translationIncomplete: isset($data['translation_incomplete']) ? (bool) $data['translation_incomplete'] : null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'content' => $this->content,
            'content_orig' => $this->contentOrig,
            'category_id' => $this->categoryId,
            'tag_ids' => $this->tagIds,
            'preview_image' => $this->previewImage,
            'main_image' => $this->mainImage,
            'translate' => $this->translate,
            'url' => $this->url,
            'selector' => $this->selector,
            'html_file' => $this->htmlFile,
            'code' => $this->code,
            'published' => $this->published,
            'translation_incomplete' => $this->translationIncomplete,
        ], fn ($value) => $value !== null);
    }
}
