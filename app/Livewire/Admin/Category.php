<?php

namespace App\Livewire\Admin;

use Livewire\Attributes\Validate;
use Livewire\Component;

use App\Models\Category as CategoryModel;

class Category extends Component
{
    #[Validate('required|min:3', onUpdate: false)]
    public $title;

    #[Validate('required|min:3', onUpdate: false)]
    public $code;

    public function render()
    {
        return view('livewire.admin.category', ['categories' => CategoryModel::all()]);
    }

    public function add(){
        CategoryModel::create($this->validate());

        $this->reset(['title','code']);
    }

    public function delete($category_id){
        CategoryModel::destroy($category_id);
    }
}
