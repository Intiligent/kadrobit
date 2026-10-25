# Publish popover — files to copy into the repository

Replaces the publish dialog with the popover from `Publish Popover.dc.html`. Belongs to KB-32 (publishing UI).

## Copy over the repository root (same paths)

New:
- `resources/js/components/library/PublishPopover.vue`: el-popover at the toolbar button; the panel inside mounts only while open
- `resources/js/components/library/PublishPanel.vue`: header, "To: N marketplaces", work list, footer stats, sending
- `resources/js/components/library/PublishWorkRow.vue`: one work with its per-marketplace cards

Changed (full files, based on the current main):
- `app/Services/Marketplaces/ListingCheck.php`: `errors` + `rejections()`; `sendable()` is false when there are errors
- `app/Services/Marketplaces/ListingChecks.php`: resolution and file size are errors now (format stays a warning)
- `app/Services/Marketplaces/Publishing.php`: `summary()` adds `items` (per work, with the checks of every pair); `queue()` takes optional `pairs`
- `app/Http/Controllers/ListingController.php`: `POST /api/listings/batch` takes `accounts` or `pairs`; retry explains file errors
- `app/Http/Resources/MarketplaceAccountResource.php`: `marketplace.upload_protocol`
- `resources/js/utils/publishing.js`: `batchAccounts`, `defaultAccounts`, `pairState`, `workRows`, `publishTotals` (replace `batchRows`, `defaultSelection`, `selectionTotals`)
- `resources/js/components/library/PublishButton.vue`: no `open` event (the popover reference handles the click), `active` prop
- `resources/js/pages/library/index.vue`: `PublishPopover` instead of `PublishButton` + `PublishDialog`
- `lang/ru/library.php`, `lang/en/library.php`: `publishing.dialog` → `publishing.popover`
- `tests/Feature/Publishing/PublishingTest.php`: summary test updated, 4 new tests
- `tests/js/publishing.test.js`
- `docs/publishing.md`, `docs/library.md`

Delete:
- `resources/js/components/library/PublishDialog.vue`

## Behaviour change to review

Files a marketplace rejects on upload (resolution under the minimum or over the maximum, file too large) are no longer sent: the pair shows "Не отправится" and is skipped by the server too. Until now they went out with a warning. `docs/publishing.md` ("Проверки готовности") describes the new rule.

## Check

```sh
php artisan test --filter=Publishing
npm test
npm run build      # regenerates lang/php_*.json for laravel-vue-i18n
```

Proposed commit: `KB-32 - Replace publish dialog with popover`
