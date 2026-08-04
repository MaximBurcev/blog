<?php

use App\Http\Controllers\Main\IndexController;
use App\Http\Controllers\Main\SearchController;
use App\Http\Controllers\Post\Comment\StoreController;
use App\Http\Controllers\Post\IndexController as PostIndexController;
use App\Http\Controllers\Post\Like\StoreController as PostLikeStoreController;
use App\Http\Controllers\Post\ShowController;
use App\Livewire\Counter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', IndexController::class)->name('main.index');

Route::get('/search', SearchController::class)->name('main.search')->middleware('throttle:interactions');

Route::get('/sitemap', [\App\Http\Controllers\Sitemap\IndexController::class, 'index'])->name('sitemap.index');

Route::prefix('posts')->group(function () {
    // Отдельного листинга у /posts нет — раньше сюда был подвешен
    // Main\IndexController, и адрес отдавал полную копию главной (дубль в
    // индексе). Теперь это 301 на «/» (Post\IndexController).
    Route::get('/', PostIndexController::class)->name('posts.index');
    Route::post('/{post}/comments', StoreController::class)->name('post.comment.store')->middleware('throttle:comments');
    Route::get('/{post}', ShowController::class)->name('post.show');
    // verified: лайк — единственное действие, требующее аккаунта, и именно он
    // был бы целью накрутки с пачки свежесозданных фейков.
    Route::post('/{post}/like', [PostLikeStoreController::class, 'like'])
        ->middleware(['auth', 'verified', 'throttle:interactions']);
});

Route::prefix('categories')->group(function () {
    Route::get('/', 'App\Http\Controllers\Category\IndexController')->name('category.index');
    Route::get('/{category}', 'App\Http\Controllers\Category\ShowController')->name('category.show');
});

Route::prefix('tags')->group(function () {
    Route::get('/', 'App\Http\Controllers\Tag\IndexController')->name('tag.index');
    Route::get('/{tag}', 'App\Http\Controllers\Tag\ShowController')->name('tag.show');
});

// verify => true подключает маршруты подтверждения почты (User реализует
// MustVerifyEmail). Троттлинг самих отправок — в VerificationController.
Auth::routes(['verify' => true]);

Route::get('/sitemap.xml', \App\Http\Controllers\Sitemap\XmlController::class)->name('sitemap.xml');

Route::get('/feed.xml', \App\Http\Controllers\Feed\IndexController::class)->name('feed.index');

Route::get('/counter', Counter::class);
