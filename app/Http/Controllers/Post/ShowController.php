<?php

namespace App\Http\Controllers\Post;

use App\Events\PostLiked;
use App\Http\Controllers\Controller;
use App\Models\Post;
use Carbon\Carbon;
use App\Http\Helpers\Content;

class ShowController extends Controller
{

    public function __invoke(String $code)
    {
        try {
            $post = Post::where('code', $code)->where('published', true)->first();
            $date = Carbon::parse($post->created_at);
            $title = $post->title;
            $description = $post->excerpt();
            $ogImage = $post->main_image ? asset('storage/' . $post->main_image) : null;
            $ogType = 'article';

            //$post->content = Content::cleanCodeTags($post->content);
            $tagIds = $post->tags->pluck('id');

            $relatedPostsQuery = Post::where('id', '!=', $post->id)
                ->where('published', true);

            if ($tagIds->isNotEmpty()) {
                $relatedPostsQuery->withCount(['tags as shared_tags_count' => function ($query) use ($tagIds) {
                    $query->whereIn('tags.id', $tagIds);
                }])->orderByDesc('shared_tags_count');
            }

            if ($post->category_id) {
                $relatedPostsQuery->orderByRaw('category_id = ? DESC', [$post->category_id]);
            }

            $relatedPosts = $relatedPostsQuery->orderByDesc('created_at')->take(4)->get();
            return view('post.show',
                compact('post', 'date', 'relatedPosts', 'title', 'description', 'ogImage', 'ogType'));
        } catch (\Exception $exception) {
            abort(404);
        }

    }

    // PostController.php

}
