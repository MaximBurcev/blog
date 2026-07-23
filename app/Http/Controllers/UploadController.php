<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadRequest;
use Illuminate\Support\Facades\Storage;

class UploadController extends Controller
{
    public function __invoke(UploadRequest $request)
    {
        $path = $request->file('file')->store('images', 'public');

        return Storage::disk('public')->url($path);
    }
}
