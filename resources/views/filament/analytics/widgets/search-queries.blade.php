<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Поиск по сайту</x-slot>
        <x-slot name="description">
            За {{ $periodLabel }} — {{ number_format($total, 0, ',', ' ') }} {{ trans_choice('запрос|запроса|запросов', $total) }}.
            Запросы обезличены: кто искал, не сохраняется.
        </x-slot>

        @if ($total === 0)
            <div class="flex flex-col items-center gap-2 py-8 text-center">
                <x-filament::icon
                    icon="heroicon-o-magnifying-glass"
                    class="h-8 w-8 text-gray-400 dark:text-gray-500"
                />
                <p class="text-sm font-medium text-gray-950 dark:text-white">За период ничего не искали</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Запросы сохраняются с 20.08.2026 — раньше поиск не оставлял следов.
                </p>
            </div>
        @else
            <div class="grid gap-6 md:grid-cols-2">
                <div>
                    {{-- Первым делом то, ради чего отчёт и заведён: чего на
                         сайте нет. --}}
                    <h3 class="mb-2 text-sm font-medium text-gray-950 dark:text-white">
                        Искали и не нашли
                    </h3>

                    @if ($missing->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Все запросы за период что-то находили.
                        </p>
                    @else
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($missing as $row)
                                    <tr>
                                        {{-- break-all: запрос приходит из адресной строки, и 191 символ
                                             без пробелов иначе распирает колонку. --}}
                                        <td class="py-2 break-all text-gray-950 dark:text-white">{{ $row->query }}</td>
                                        <td class="py-2 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                            {{ $row->times }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            Готовый список тем: читатель искал это и ушёл ни с чем.
                        </p>
                    @endif
                </div>

                <div>
                    <h3 class="mb-2 text-sm font-medium text-gray-950 dark:text-white">
                        Находили
                    </h3>

                    @if ($found->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            За период удачных запросов не было.
                        </p>
                    @else
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                                    <th class="py-2 text-left font-medium">Запрос</th>
                                    <th class="py-2 text-right font-medium">Раз</th>
                                    <th class="py-2 text-right font-medium">Найдено</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($found as $row)
                                    <tr>
                                        {{-- break-all: запрос приходит из адресной строки, и 191 символ
                                             без пробелов иначе распирает колонку. --}}
                                        <td class="py-2 break-all text-gray-950 dark:text-white">{{ $row->query }}</td>
                                        <td class="py-2 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                            {{ $row->times }}
                                        </td>
                                        <td class="py-2 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                            {{ $row->results }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
