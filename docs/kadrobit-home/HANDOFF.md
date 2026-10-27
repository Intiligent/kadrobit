# Home page — files to copy into the repository

Ports `Landing v3.dc.html` to `/` for guests. Copy everything except this file over the repository root (same paths).

New:
- `app/Http/Controllers/LocaleController.php` — `POST /locale`: guest language in the session, user language in `general.locale`
- `resources/js/layouts/LandingLayout.vue`
- `resources/js/components/home/` — `PhotoWall.vue`, `HeroDemoCard.vue`, `StockMarquee.vue`, `HowItWorks.vue`, `FeatureGrid.vue`, `CheckPrints.vue`
- `resources/js/composables/useTicker.js`, `resources/js/directives/reveal.js`, `resources/js/utils/homeDemo.js`
- `public/images/home/*.webp` — 39 placeholder photos (picsum.photos / Unsplash), 842 KB
- `tests/Feature/HomeTest.php`, `tests/js/homeDemo.test.js`, `docs/home.md`

Changed (full files, based on the current main):
- `app/Http/Controllers/HomeController.php` — passes `marketplaces` and `unsupported`
- `routes/web.php` — `POST /locale`
- `lang/ru/home.php`, `lang/en/home.php` — page texts (`greeting` and `guest` are no longer used and were removed)
- `resources/js/pages/home/index.vue`
- `resources/js/app.js` — registers `v-reveal`
- `resources/js/config/constant.js` — `LOCALE_URL`
- `vite.config.js` — Instrument Serif from Bunny Fonts
- `resources/style/tailwind.css` — `--font-serif`

## Check

```sh
php artisan test --filter=HomeTest
php artisan test --filter=InertiaTest
npm test
npm run build
```

Proposed commit: `Add public home page`
