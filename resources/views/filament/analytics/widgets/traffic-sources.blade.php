<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Источники переходов</x-slot>
        <x-slot name="description">
            За {{ $periodLabel }}. Роботы не учитываются.
            @if ($truncated)
                Данные с {{ $since->translatedFormat('j F Y') }} — раньше источник перехода не сохранялся.
            @endif
        </x-slot>

        @if ($top->isEmpty() && $direct['views'] === 0 && $internal['views'] === 0)
            <div class="flex flex-col items-center gap-2 py-8 text-center">
                <x-filament::icon
                    icon="heroicon-o-arrow-trending-up"
                    class="h-8 w-8 text-gray-400 dark:text-gray-500"
                />
                <p class="text-sm font-medium text-gray-950 dark:text-white">Переходов за период нет</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Источник сохраняется с {{ \Illuminate\Support\Carbon::parse(\App\Models\PostView::ATTRIBUTION_SINCE)->translatedFormat('j F Y') }}
                    — у более ранних просмотров его не знает никто.
                </p>
            </div>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 text-left font-medium">Источник</th>
                        <th class="py-2 text-right font-medium">Переходы</th>
                        <th class="py-2 text-right font-medium">Читатели</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($top as $source)
                        <tr>
                            <td class="py-2 text-gray-950 dark:text-white">
                                {{-- Домен пришёл из заголовка запроса: выводим
                                     текстом, а не ссылкой, чтобы админка не
                                     ходила по адресам, которые ей подсунули. --}}
                                {{ $source->referer_host }}
                            </td>
                            <td class="py-2 text-right tabular-nums text-gray-950 dark:text-white">{{ number_format($source->views, 0, ',', ' ') }}</td>
                            <td class="py-2 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($source->visitors, 0, ',', ' ') }}</td>
                        </tr>
                    @endforeach

                    @if ($restViews > 0)
                        <tr class="text-gray-500 dark:text-gray-400">
                            <td class="py-2">Прочие домены ({{ $restDomains }})</td>
                            <td class="py-2 text-right tabular-nums">{{ number_format($restViews, 0, ',', ' ') }}</td>
                            <td class="py-2 text-right">—</td>
                        </tr>
                    @endif

                    <tr class="text-gray-500 dark:text-gray-400">
                        <td class="py-2">
                            Прямые заходы
                            <span class="text-xs">— закладки, мессенджеры, адресная строка</span>
                        </td>
                        <td class="py-2 text-right tabular-nums">{{ number_format($direct['views'], 0, ',', ' ') }}</td>
                        <td class="py-2 text-right tabular-nums">{{ number_format($direct['visitors'], 0, ',', ' ') }}</td>
                    </tr>

                    <tr class="text-gray-500 dark:text-gray-400">
                        <td class="py-2">
                            Переходы внутри сайта
                            <span class="text-xs">— читатель пришёл с другой страницы блога</span>
                        </td>
                        <td class="py-2 text-right tabular-nums">{{ number_format($internal['views'], 0, ',', ' ') }}</td>
                        <td class="py-2 text-right tabular-nums">{{ number_format($internal['visitors'], 0, ',', ' ') }}</td>
                    </tr>
                </tbody>
            </table>
        @endif

        @if ($campaigns->isNotEmpty())
            <div class="mt-6">
                <h3 class="mb-2 text-sm font-medium text-gray-950 dark:text-white">Метки кампаний</h3>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 text-left font-medium">Источник</th>
                            <th class="py-2 text-left font-medium">Канал</th>
                            <th class="py-2 text-left font-medium">Кампания</th>
                            <th class="py-2 text-right font-medium">Переходы</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($campaigns as $campaign)
                            <tr>
                                <td class="py-2 text-gray-950 dark:text-white">{{ $campaign->utm_source }}</td>
                                <td class="py-2 text-gray-500 dark:text-gray-400">{{ $campaign->utm_medium ?? '—' }}</td>
                                <td class="py-2 text-gray-500 dark:text-gray-400">{{ $campaign->utm_campaign ?? '—' }}</td>
                                <td class="py-2 text-right tabular-nums text-gray-950 dark:text-white">{{ number_format($campaign->views, 0, ',', ' ') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
