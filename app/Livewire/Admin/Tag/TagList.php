<?php

namespace App\Livewire\Admin\Tag;

use App\Models\Tag;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Attributes\Validate;

class TagList extends Component
{

    #[Validate('required|min:3', onUpdate: false)]
    public $title;

    #[Validate('required|min:3', onUpdate: false)]
    public $code;

    public function addTag() {

        $this->code = Str::slug($this->title);
        $validated = $this->validate();

        Tag::create($validated);

        $this->reset('title');
    }

    public function deleteTag($id)
    {
        Tag::destroy($id);
    }

    public function render()
    {
        return view('livewire.admin.tag.tag-list', ['tags' => Tag::all()]);
    }
}
