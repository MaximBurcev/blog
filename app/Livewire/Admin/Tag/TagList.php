<?php

namespace App\Livewire\Admin\Tag;

use App\Models\Tag;
use Livewire\Component;

class TagList extends Component
{

    public function deleteTag($id)
    {
        Tag::destroy($id);
    }

    public function render()
    {
        return view('livewire.admin.tag.tag-list', ['tags' => Tag::all()]);
    }
}
