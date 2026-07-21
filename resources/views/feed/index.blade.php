<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<rss version="2.0">
    <channel>
        <title>{{ config('app.name', 'Laravel') }}</title>
        <link>{{ url('/') }}</link>
        <description>{{ config('app.name', 'Laravel') }} — блог</description>
        <language>ru</language>
        @foreach ($posts as $post)
            <item>
                <title>{{ $post->title }}</title>
                <link>{{ route('post.show', $post->code) }}</link>
                <guid isPermaLink="true">{{ route('post.show', $post->code) }}</guid>
                <pubDate>{{ $post->created_at->toRssString() }}</pubDate>
                <description>{{ $post->excerpt() }}</description>
            </item>
        @endforeach
    </channel>
</rss>
