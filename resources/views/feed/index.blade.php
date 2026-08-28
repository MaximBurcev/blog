<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">
    <channel>
        <title>{{ config('app.name', 'Laravel') }}</title>
        <link>{{ url('/') }}</link>
        {{-- atom:link rel="self" — адрес самой ленты; без него валидатор
             RSS ругается, а читалки при смене адреса не могут её найти. --}}
        <atom:link href="{{ route('feed.index') }}" rel="self" type="application/rss+xml" />
        <description>{{ config('app.name', 'Laravel') }} — блог</description>
        <language>ru</language>
        @if ($posts->isNotEmpty())
            <lastBuildDate>{{ $posts->max('created_at')->toRssString() }}</lastBuildDate>
        @endif
        {{-- Логотип канала — та же картинка, что в og:image по умолчанию. --}}
        <image>
            <url>{{ asset(config('seo.default_image')) }}</url>
            <title>{{ config('app.name', 'Laravel') }}</title>
            <link>{{ url('/') }}</link>
        </image>
        @foreach ($posts as $post)
            <item>
                <title>{{ $post->title }}</title>
                <link>{{ $post->permalink() }}</link>
                <guid isPermaLink="true">{{ $post->permalink() }}</guid>
                <pubDate>{{ $post->created_at->toRssString() }}</pubDate>
                {{-- В description — короткий анонс, в content:encoded — полный
                     текст для читалок с полнотекстовым режимом. CDATA, потому
                     что content — это HTML; «]]>» внутри текста разбивается на
                     две CDATA-секции, иначе он закрыл бы секцию досрочно и
                     сломал бы весь XML. --}}
                <description>{{ $post->excerpt() }}</description>
                <content:encoded><![CDATA[{!! str_replace(']]>', ']]]]><![CDATA[>', (string) $post->content) !!}]]></content:encoded>
            </item>
        @endforeach
    </channel>
</rss>
