{{-- Яндекс.Метрика. Подключается только при заданном config('seo.yandex_metrika_id').
     Официальный сниппет с минимальным набором опций. Вебвизор сознательно не
     включён: он записывает действия посетителя на странице (приватность — у
     нас весь трафик псевдонимизирован, см. PostViewService) и заметно весит
     на каждой странице, а ответа на вопрос «что читают» не даёт — для этого
     есть своя аналитика post_views. --}}
<script nonce="{{ $cspNonce ?? '' }}" type="text/javascript">
    (function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
    m[i].l=1*new Date();k=e.createElement(t),a=e.getElementsByTagName(t)[0],
    k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
    (window,document,"script","https://mc.yandex.ru/metrika/tag.js","ym");

    ym({{ (int) config('seo.yandex_metrika_id') }}, "init", {
        clickmap: true,
        trackLinks: true,
        accurateTrackBounce: true
    });
</script>
<noscript><div><img src="https://mc.yandex.ru/watch/{{ (int) config('seo.yandex_metrika_id') }}" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
