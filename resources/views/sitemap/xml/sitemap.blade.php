<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>{{ url('/') }}</loc>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>
    {{-- Лента новостей. Сами новости идут ниже общим списком постов: с тех
         пор, как у новости появилась своя страница /news/{code}, отдельного
         обхода они не требуют — адрес подставляет Post::permalink(). --}}
    @if($hasNews)
        <url>
            <loc>{{ route('news.index') }}</loc>
            <changefreq>daily</changefreq>
            <priority>0.7</priority>
        </url>
    @endif
    @if($hasTools)
        <url>
            <loc>{{ route('tools.index') }}</loc>
            <changefreq>weekly</changefreq>
            <priority>0.7</priority>
        </url>
    @endif
    @foreach ($categories as $category)
        <url>
            <loc>{{ route('category.show', $category->code) }}</loc>
            <changefreq>weekly</changefreq>
            <priority>0.6</priority>
        </url>
    @endforeach
    @foreach ($tags as $tag)
        <url>
            <loc>{{ route('tag.show', $tag->code) }}</loc>
            <changefreq>weekly</changefreq>
            <priority>0.6</priority>
        </url>
    @endforeach
    @foreach ($posts as $post)
        <url>
            <loc>{{ $post->permalink() }}</loc>
            <lastmod>{{ $post->updated_at->tz('UTC')->toAtomString() }}</lastmod>
            <changefreq>weekly</changefreq>
            <priority>0.8</priority>
        </url>
    @endforeach
</urlset>
