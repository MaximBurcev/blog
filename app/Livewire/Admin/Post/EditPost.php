<?php

namespace App\Livewire\Admin\Post;

use App\Models\Post;
use App\Models\Category;
use App\Models\Tag;
use Livewire\Component;
use Livewire\WithFileUploads;
use Illuminate\Validation\Rule;

class EditPost extends Component
{
    use WithFileUploads;

    public $post;
    public $title;
    public $code;
    public $content;
    public $preview_image;
    public $main_image;
    public $category_id;
    public $tag_ids = [];
    public $published;

    public $showMessage = false;
    public $showSuccessMessage = false;

    protected $rules = [
        'title' => 'required|string|max:255',
        'code' => 'required|string|max:255|unique:posts,code',
        'content' => 'required|string',
        'preview_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        'main_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        'category_id' => 'required|exists:categories,id',
        'tag_ids' => 'array',
        'tag_ids.*' => 'exists:tags,id',
        'published' => 'boolean',
    ];

    public function mount(Post $post)
    {
        $this->post = $post;
        $this->title = $post->title;
        $this->code = $post->code;
        $this->content = $post->content;
        $this->category_id = $post->category_id;
        $this->tag_ids = $post->tags->pluck('id')->toArray();
        $this->published = (bool) $post->published;
    }

    public function updated($propertyName)
    {
        $this->validateOnly($propertyName);
    }

    public function update()
    {
        $this->validate([
            'code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('posts', 'code')->ignore($this->post->id),
            ],
        ]);


        $data = [
            'title' => $this->title,
            'code' => $this->code,
            'content' => $this->content,
            'category_id' => $this->category_id,
            'published' => $this->published,
        ];

        if ($this->preview_image) {
            $data['preview_image'] = $this->preview_image->store('posts', 'public');
        }

        if ($this->main_image) {
            $data['main_image'] = $this->main_image->store('posts', 'public');
        }

        $this->post->update($data);
        $this->post->tags()->sync($this->tag_ids);

        session()->flash('message', 'Пост успешно обновлён!');
        $this->showMessage = true;
        $this->showSuccessMessage = true;
        $this->dispatch('postUpdated');
    }

    public function render()
    {
        $categories = Category::all();
        $tags = Tag::all();

        return view('livewire.admin.post.edit-post', compact('categories', 'tags'));
    }
}
