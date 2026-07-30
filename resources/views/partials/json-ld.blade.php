{{--
    Рендерер структурированных данных schema.org.

    JSON_HEX_TAG обязателен: без него строка «</script>» внутри любого поля
    (а поля тут — заголовки и описания чужих статей) закрыла бы тег и всё
    последующее браузер разобрал бы как HTML.
--}}
<script type="application/ld+json">{!! json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
