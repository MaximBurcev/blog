<?php

namespace App\Http\Controllers\Tool;

use App\Http\Controllers\Controller;
use App\Models\Tool;
use Illuminate\Contracts\View\View;

class IndexController extends Controller
{
    private const PER_PAGE = 30;

    public function __invoke(): View
    {
        $tools = Tool::published()
            ->select(['id', 'name', 'url', 'description', 'description_orig', 'created_at'])
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return view('tools.index', [
            'tools' => $tools,
            'title' => 'Инструменты',
            'description' => 'Утилиты и библиотеки для PHP из дайджеста PHP Weekly: что вышло нового — с описанием на русском и ссылкой на GitHub.',
            'robots' => $tools->isEmpty() ? 'noindex, follow' : null,
            // url()->current() отбрасывает query, и все страницы пагинации
            // канонизировались бы на первую — как в Category\ShowController.
            'canonical' => $tools->currentPage() > 1
                ? $tools->url($tools->currentPage())
                : url()->current(),
        ]);
    }
}
