<?php

use App\Http\Controllers\Main\IndexController;
use App\Http\Controllers\Main\SearchController;
use App\Http\Controllers\Post\Comment\StoreController;
use App\Http\Controllers\Post\Like\StoreController as PostLikeStoreController;
use App\Http\Controllers\Post\ShowController;
use App\Http\Controllers\UploadController;
use App\Livewire\Counter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

use App\Events\UserNotification;
use App\Models\User;

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

Route::get('/search', SearchController::class)->name('main.search');

Route::get('/sitemap', [\App\Http\Controllers\Sitemap\IndexController::class, 'index'])->name('sitemap.index');

Route::prefix('posts')->group(function () {
    Route::get('/', IndexController::class)->name('posts.index');
    Route::post('/{post}/comments', StoreController::class)->name('post.comment.store');
    Route::get('/{post}', ShowController::class)->name('post.show');
    Route::post('/{post}/like', [PostLikeStoreController::class, 'like'])->middleware('auth');
});

Route::prefix('categories')->group(function () {
    Route::get('/', 'App\Http\Controllers\Category\IndexController')->name('category.index');
    Route::get('/{category}', 'App\Http\Controllers\Category\ShowController')->name('category.show');
});

Route::prefix('tags')->group(function () {
    Route::get('/', 'App\Http\Controllers\Tag\IndexController')->name('tag.index');
    Route::get('/{tag}', 'App\Http\Controllers\Tag\ShowController')->name('tag.show');
});

Route::prefix('admin')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/', 'App\Http\Controllers\Admin\Main\IndexController')->name('admin.main.index');

    Route::prefix('posts')->group(function () {

        Route::get('/', 'App\Http\Controllers\Admin\Post\IndexController')->name('admin.post.index');
        Route::get('/create', 'App\Http\Controllers\Admin\Post\CreateController')->name('admin.post.create');
        Route::get('/add', 'App\Http\Controllers\Admin\Post\AddController')->name('admin.post.add');

        Route::prefix('add')->group(function () {
            Route::post('/', 'App\Http\Controllers\Admin\Post\StoreAddController')->name('admin.post.store.add');
        });

        Route::post('/', 'App\Http\Controllers\Admin\Post\StoreController')->name('admin.post.store');

        Route::get('/{post}', 'App\Http\Controllers\Admin\Post\ShowController')->name('admin.post.show');
        Route::get('/{post}/edit', 'App\Http\Controllers\Admin\Post\EditController')->name('admin.post.edit');
        Route::patch('/{post}', 'App\Http\Controllers\Admin\Post\UpdateController')->name('admin.post.update');
        Route::delete('/{post}', 'App\Http\Controllers\Admin\Post\DeleteController')->name('admin.post.delete');
    });

    Route::prefix('categories')->group(function () {
        Route::get('/', 'App\Http\Controllers\Admin\Category\IndexController')->name('admin.category.index');
        Route::get('/create', 'App\Http\Controllers\Admin\Category\CreateController')->name('admin.category.create');
        Route::post('/', 'App\Http\Controllers\Admin\Category\StoreController')->name('admin.category.store');
        Route::get('/{category}', 'App\Http\Controllers\Admin\Category\ShowController')->name('admin.category.show');
        Route::get('/{category}/edit',
            'App\Http\Controllers\Admin\Category\EditController')->name('admin.category.edit');
        Route::patch('/{category}',
            'App\Http\Controllers\Admin\Category\UpdateController')->name('admin.category.update');
        Route::delete('/{category}',
            'App\Http\Controllers\Admin\Category\DeleteController')->name('admin.category.delete');
    });

    Route::prefix('tags')->group(function () {
        Route::get('/', 'App\Http\Controllers\Admin\Tag\IndexController')->name('admin.tag.index');
        Route::get('/create', 'App\Http\Controllers\Admin\Tag\CreateController')->name('admin.tag.create');
        Route::post('/', 'App\Http\Controllers\Admin\Tag\StoreController')->name('admin.tag.store');
        Route::get('/{tag}', 'App\Http\Controllers\Admin\Tag\ShowController')->name('admin.tag.show');
        Route::get('/{tag}/edit', 'App\Http\Controllers\Admin\Tag\EditController')->name('admin.tag.edit');
        Route::patch('/{tag}', 'App\Http\Controllers\Admin\Tag\UpdateController')->name('admin.tag.update');
        Route::delete('/{tag}', 'App\Http\Controllers\Admin\Tag\DeleteController')->name('admin.tag.delete');
    });

    Route::prefix('users')->group(function () {
        Route::get('/', 'App\Http\Controllers\Admin\User\IndexController')->name('admin.user.index');
        Route::get('/create', 'App\Http\Controllers\Admin\User\CreateController')->name('admin.user.create');
        Route::post('/', 'App\Http\Controllers\Admin\User\StoreController')->name('admin.user.store');
        Route::get('/{user}', 'App\Http\Controllers\Admin\User\ShowController')->name('admin.user.show');
        Route::get('/{user}/edit', 'App\Http\Controllers\Admin\User\EditController')->name('admin.user.edit');
        Route::patch('/{user}', 'App\Http\Controllers\Admin\User\UpdateController')->name('admin.user.update');
        Route::delete('/{user}', 'App\Http\Controllers\Admin\User\DeleteController')->name('admin.user.delete');
    });

    Route::prefix('comments')->group(function () {
        Route::get('/', 'App\Http\Controllers\Admin\Comment\IndexController')->name('admin.comment.index');
        Route::get('/create', 'App\Http\Controllers\Admin\Comment\CreateController')->name('admin.comment.create');
        Route::post('/', 'App\Http\Controllers\Admin\Comment\StoreController')->name('admin.comment.store');
        Route::get('/{comment}', 'App\Http\Controllers\Admin\Comment\ShowController')->name('admin.comment.show');
        Route::get('/{comment}/edit', 'App\Http\Controllers\Admin\Comment\EditController')->name('admin.comment.edit');
        Route::patch('/{comment}', 'App\Http\Controllers\Admin\Comment\UpdateController')->name('admin.comment.update');
        Route::delete('/{comment}',
            'App\Http\Controllers\Admin\Comment\DeleteController')->name('admin.comment.delete');
    });

    Route::prefix('releases')->group(function () {
        Route::get('/', 'App\Http\Controllers\Admin\Release\IndexController')->name('admin.release.index');
        Route::get('/create', 'App\Http\Controllers\Admin\Release\CreateController')->name('admin.release.create');
        Route::post('/', 'App\Http\Controllers\Admin\Release\StoreController')->name('admin.release.store');
        Route::get('/{release}', 'App\Http\Controllers\Admin\Release\ShowController')->name('admin.release.show');
        Route::get('/{release}/edit', 'App\Http\Controllers\Admin\Release\EditController')->name('admin.release.edit');
        Route::patch('/{release}', 'App\Http\Controllers\Admin\Release\UpdateController')->name('admin.release.update');
        Route::delete('/{release}',
            'App\Http\Controllers\Admin\Release\DeleteController')->name('admin.release.delete');
    });

});

Auth::routes();

Route::post('/upload', UploadController::class)->name('upload');

Route::get('phpinfo', function () {
    phpinfo();
})->name('phpinfo');

Route::get('/sitemap.xml', 'App\Http\Controllers\SitemapController@index');
Route::get('/sitemap/posts', 'App\Http\Controllers\SitemapController@posts');

Route::get('test', function () {


    $user = User::find(Auth::user()->getAuthIdentifier());

    dump($user->id);

    broadcast(new UserNotification($user, time() . ': У вас новое уведомление!'));

})->name('test');

Route::get('/counter', Counter::class);

