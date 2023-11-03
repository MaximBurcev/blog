<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadRequest;
use Illuminate\Support\Facades\Log;

class UploadController extends Controller
{
    public function __invoke(UploadRequest $request){
        $name = str_replace($request->file->getClientOriginalExtension(), '', $request->file->getClientOriginalName())  . time().'.'.$request->file->getClientOriginalExtension();
        $request->file->move(public_path('uploads'), $name);
        Log::info($name);
        Log::debug('debug', $request->toArray());
        Log::build([
            'driver' => 'single',
            'path' => storage_path('logs/custom.log'),
        ])->info('Upload happened!');
        return asset('uploads') . '/' .  $name;
    }
}
