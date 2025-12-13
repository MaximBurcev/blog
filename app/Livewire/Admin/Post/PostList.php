<?php

namespace App\Livewire\Admin\Post;

use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

class PostList extends Component
{

    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    #[Url]
    public int $limit = 10;
    public array $limitList = [5, 10, 25, 50, 100];
    #[Url]
    public string $search = '';

    public string $orderByField = 'posts.id';
    public string $orderByDirection = 'desc';
    public array $orderByFieldList = [
        'posts.id'         => 'ID',
        'posts.title'      => 'Название',
        'posts.code'       => 'Символьный код',
        'categories.title' => 'Категория',
    ];

    public function mount()
    {
        if (!in_array($this->limit, $this->limitList)) {
            $this->redirectRoute('home');
        }

        $this->js('console.log("mount")');
    }

    public function hydrate() {
        $this->js('console.log("hydrate")');
    }

    public function boot(){
        $this->js('console.log("boot")');
    }

    public function updating($property, $value)
    {
        if ($property == 'search') {
            $this->resetPage();
        }

        $this->js('console.log("updating")');
    }

    public function updated(){
        $this->js('console.log("updated")');
    }

    public function rendering()
    {
        $this->js('console.log("rendering")');
    }

    public function rendered()
    {
        $this->js('console.log("rendered")');
    }

    public function dehydrate()
    {
        $this->js('console.log("dehydrate")');
    }

    public function exception($e, $stopPropagation)
    {
        $this->js('console.log("exception: "' . $e->getMessage() . ')');
    }

    public function changeOrder($field)
    {
        if ($this->orderByField == $field) {
            $this->orderByDirection = $this->orderByDirection == 'asc' ? 'desc' : 'asc';
            return;
        }
        $this->orderByField = $this->orderByFieldList[$field] ? $field : 'posts.id';
        $this->orderByDirection = 'asc';
    }



    public function changeLimit()
    {
        $this->limit = in_array($this->limit, $this->limitList) ? $this->limit : $this->limitList[0];
        $this->resetPage();
    }


    public function delete(int $id)
    {
        Post::find($id)->delete();
    }

    #[On('user-created')]
    public function updateUserList($user = null)
    {
//        dump($user);
    }

    public function render()
    {
        $posts = Post::query()
            ->select('posts.id', 'posts.title', 'posts.code', 'categories.title as category_name')
            ->join('categories', 'posts.category_id', '=', 'categories.id')
            ->when($this->search, function (Builder $query) {
                $query->where(function (Builder $q) {
                    $q->where('posts.title', 'like', '%' . $this->search . '%')
                        ->orWhere('posts.code', 'like', '%' . $this->search . '%')
                        ->orWhere('categories.title', 'like', '%' . $this->search . '%');
                });
            })
            ->orderBy($this->orderByField, $this->orderByDirection)
            ->paginate($this->limit);

        return view('livewire.admin.post.post-list', [
            'posts' => $posts
        ]);
    }
}
