<?php

namespace App\Livewire\Admin\Post;

use App\Livewire\Forms\PostForm;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

class CreatePost extends Component
{

    use WithFileUploads;

    public $title;
    public $content;
    public $preview_image;
    public $main_image;
    public $category_id;
    public $tag_ids = [];
    public $translate = false;

    protected $rules = [
        'title'         => 'required|string|max:255',
        'content'       => 'required|string',
        'preview_image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        'main_image'    => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        'category_id'   => 'required|exists:categories,id',
        'tag_ids'       => 'required|array',
        'tag_ids.*'     => 'exists:tags,id',
    ];
    public string $success = '';

    public function save()
    {
        $this->validate();

        $previewPath = $this->preview_image->store('posts/previews', 'public');
        $mainPath = $this->main_image->store('posts/main', 'public');

        $post = Post::create([
            'title'         => $this->title,
            'code'          => Str::slug($this->title),
            'content'       => $this->content,
            'preview_image' => $previewPath,
            'main_image'    => $mainPath,
            'category_id'   => $this->category_id,
            'translate'     => $this->translate,
            'url'           => '',
            'selector'      => ''
        ]);

        $post->tags()->attach($this->tag_ids);

        //$this->notification()->success('Готово!', 'Пост успешно создан!');
        $this->success = 'Пост успешно создан!';
        return redirect()->to(route('admin.post.index'))->with('success', $this->success);

    }

    public function render()
    {
        return view('livewire.admin.post.create-post', [
            'categories' => Category::query()->get(),
            'tags'       => Tag::query()->get(),
        ]);
    }

}
