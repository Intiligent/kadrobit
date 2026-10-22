# UI: типографика и стили

Источник: основной контентный блок стартовой страницы Laravel (`welcome.blade.php`, удалена в KB-3). Сейчас эти стили использует `resources/js/pages/home/index.vue`, корневой шаблон — `resources/views/app.blade.php`.

## Шрифт

| Параметр | Значение |
|---|---|
| Семейство | **Instrument Sans** |
| Fallback | `ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji'` |
| Начертания | 400 (regular), 500 (medium), 600 (semibold) |
| Откуда грузится | Bunny Fonts через `laravel-vite-plugin/fonts`, подключается директивой `@fonts` в `<head>` |

Где настроено:

- `vite.config.js`: `bunny('Instrument Sans', { weights: [400, 500, 600] })`
- `resources/style/tailwind.css`: токен темы Tailwind `--font-sans`

```css
@theme {
    --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji',
        'Segoe UI Symbol', 'Noto Color Emoji';
}
```

Tailwind применяет `--font-sans` ко всему документу (`html { font-family: var(--font-sans) }`), поэтому блок наследует его без отдельного класса.

## Текст в контентном блоке

| Элемент | Классы | Значение |
|---|---|---|
| Базовый текст | `text-[13px] leading-[20px]` | 13px / 20px, weight 400 |
| Заголовок (`h1`) | `mb-1 font-medium` | 13px / 20px, weight 500 |
| Второстепенный текст | `text-[#706f6c] dark:text-[#A1A09A]` | — |
| Ссылки | `font-medium underline underline-offset-4 text-[#f53003] dark:text-[#FF4433]` | weight 500 |
| Кнопки в шапке | `text-sm leading-normal` | 14px / 1.5 |

## Цвета

| Назначение | Светлая тема | Тёмная тема |
|---|---|---|
| Фон страницы (`body`) | `#FDFDFC` | `#0a0a0a` |
| Фон блока | `#ffffff` | `#161615` |
| Основной текст | `#1b1b18` | `#EDEDEC` |
| Второстепенный текст | `#706f6c` | `#A1A09A` |
| Акцент / ссылки | `#f53003` | `#FF4433` |
| Обводка блока (inset shadow) | `rgba(26,26,0,0.16)` | `#fffaed2d` |

## Контентный блок: эталонные классы

```html
<div class="text-[13px] leading-[20px] flex-1 p-6 pb-6 lg:p-20 lg:pb-10
            bg-white dark:bg-[#161615] dark:text-[#EDEDEC]
            shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)]
            dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d]
            rounded-bl-lg rounded-br-lg lg:rounded-tl-lg lg:rounded-br-none">
```

| Параметр | Мобильные | `lg` (≥1024px) |
|---|---|---|
| Отступы | `p-6` (24px), снизу 24px | `p-20` (80px), снизу `pb-10` (40px) |
| Скругления | снизу слева и справа `rounded-lg` (8px) | сверху слева и снизу слева, снизу справа без скругления |
| Обводка | 1px через inset `box-shadow` | — |
