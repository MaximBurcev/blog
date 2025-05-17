<?php

namespace App\Http\Controllers\Admin\Release;

use App\Http\Controllers\Controller;
use App\Service\PostService;
use App\Service\ReleaseService;

class BaseController extends Controller
{
    public ReleaseService $service;

}
