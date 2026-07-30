<?php

namespace App\Http\Controllers\Post;

use App\Http\Controllers\Controller;

class IndexController extends Controller
{
    public function __invoke()
    {
        // 301, а не временный 302: /posts дублирует главную постоянно,
        // и поисковику нужно перенести на неё вес старого адреса.
        return redirect()->route('main.index', [], 301);
    }
}
