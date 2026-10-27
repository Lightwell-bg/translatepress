# LingoCanvas

[English](README.md) | [Русский](README_RU.md) · [Quick start (RU)](docs/QUICK_START.md)

> LingoCanvas (developer: CenterAI, WordPress.org contributor `centeraieu`). Plugin folder and text domain: `lingocanvas`.

> **Running a site, not developing the plugin?** You need the [user guide](docs/user-guide.md) — installation, language setup and translating posts without a single line of code. This file is written for people who work on the plugin.

A multilingual WordPress plugin built on the TranslatePress model:

```
1 WordPress post  +  N language URLs  +  string translations in its own tables
```

No duplicate posts are created. The plugin intercepts the already rendered HTML, parses it into a DOM, substitutes translations from the database and serves the finished page at `/en/...`. That is why everything the theme actually printed gets translated: texts, menus, header, footer, widgets, forms, image captions — not only the post content.

Current state: **Stages 1, 2 and part of Stage 3**. Translations are still entered by hand — but you can now ask AI (OpenAI) to suggest a translation with one button, review it and save it yourself. No automation: the plugin does not translate the whole site by itself and does not call OpenAI in the background.

> Note: the admin screens are in English and switch to Russian automatically when the site or user language is Russian (bundled translation in `languages/`).

## What it can do now

- Site languages: code, URL slug, status `published` / `draft`, default language.
- Language URLs: `/about/` is Russian, `/en/about/` is English. The language is determined by URL only, with no cookie.
- A single canonical 301: a redundant default-language prefix and a wrong-case slug. A draft language returns 404.
- Server-side translation of the finished HTML: text nodes, `alt`, `title`, `placeholder`, `aria-label`, button labels, `<title>` and meta description.
- Automatic string collection: pages opened in an additional language fill the dictionary.
- **Visual editor**: page preview next to the translation panel, text picked by click, saving without a reload.
- **"Translate the whole content with AI"**: title, excerpt, the whole post content and the thumbnail's alt text in one operation — instead of translating block by block, with automatic chunking and validation before saving.
- **"Languages" column in the post and page lists**: for every additional language it shows whether the post is fully translated (✅), the percentage, "no translation" or "no strings yet" (the plugin has not collected the post's strings: they appear once the post is opened on the site in an additional language, or "Translate the whole content with AI" is run; the plugin never scans on its own). Link targets are not counted, SEO strings are (with a separate "SEO: N" link). Clicking a language opens the visual editor (no link for a draft language), the "strings" link opens "String translation" for that post. A "Not fully translated: language" filter sits above the list. Counts come from the same dictionary as the "String translation" screen.
- **Manual control of which posts are visible on other languages**: in the "Languages" settings choose "only posts enabled by hand" and switch posts on per language — with a switch in the posts list, a bulk action, a filter, the edit screen box, the visual editor or the "Enable all fully translated" button. A hidden post redirects to the default language; the admin sees a "Hidden on DE" plaque in the admin bar.
- **Translation blocks**: a paragraph broken up by `<b>` and `<a>` tags is translated as a whole, together with its markup.
- **Interface translation without manual work**: on an additional language the plugin swaps the WordPress locale, and core, the theme and plugins serve their own strings from the official language pack — "Reply", "Leave a comment", cart buttons are translated without a single row in the database and without a single OpenAI token.
- The "String translation" admin page: three tabs — "Content", "SEO/GEO" and "Interface", language and status filters, search, manual translation entry.
- Deleting translations: one string at a time, or all translations of the selected language at once.
- Language switcher: one WordPress menu item (dropdown or inline), the `[lingocanvas_language_switcher]` shortcode and a widget. The language order is set by hand, and you can show the name, the language code, a flag or a flag with the code.
- The OpenAI key is stored in the database and entered in the admin — no access to the hosting files is needed.
- On an additional language, lists show only translated posts.
- A multilingual sitemap with mutual `hreflang` for search engines.
- SEO markup translation: meta description, Open Graph, Twitter Card, `article:*`, JSON-LD (`inLanguage`, titles, breadcrumbs) — with protection for identifiers, image URLs and brand names.
- A post title is translated once and substituted identically into `<title>`, H1, `og:title`, `twitter:title` and JSON-LD `headline` — with no risk of different wordings in different places.
- The site logo (`Organization.logo` in JSON-LD) does not change its `@id` between languages — just like the organization itself; the post's own image is not affected.
- Internal links in the theme footer and other hard-coded blocks get a language prefix the same way as links built through WordPress.
- The "String translation" page has a "Type" filter that collects SEO/GEO fields separately.
- Basic SEO: self-canonical, mutual hreflang with `x-default`, the `lang` attribute.
- **AI translation**: a button next to the translation field — both in the string list and in the visual editor — fills the field with an OpenAI suggestion. Nothing is saved automatically: you must review the translation and press "Save" yourself. A daily character limit protects against accidental spending.

What is not there yet: an automatic background translation queue, a ONE-BUTTON crawl of the WHOLE SITE (bulk translation by button covers a single post, not the whole catalog), glossary and translation memory, edit history with rollback, language-specific images and links, slug translation, sitemap adapters, WooCommerce support, gettext and dynamic JS content.

## Requirements

| Component | Version |
|---|---|
| PHP | 8.1+ (**8.4+** recommended) |
| WordPress | 6.4+ |
| MySQL / MariaDB | MySQL 8 / MariaDB 10.6+ |
| Permalinks | any structure except "Plain" |

On PHP 8.4+ the built-in HTML5 parser `\Dom\HTMLDocument` is used — fast and correct. On 8.1–8.3 the plugin falls back to the libxml `DOMDocument`: it works too, but accuracy on non-standard markup is lower.

**There are no runtime dependencies.** The `vendor/` folder does not need to be uploaded to the hosting: if there is no Composer autoloader, the plugin loads its own PSR-4 autoloader.

## Installation

1. Build the ZIP (`php bin/build-zip.php`, result: `dist/lingocanvas-<version>.zip`) and upload it via "Plugins → Add New → Upload Plugin", or copy the plugin folder to `wp-content/plugins/lingocanvas`.
2. Activate "LingoCanvas" in the "Plugins" section. On activation four tables and default settings are created: Russian as the main language, English at `/en/`.
3. Open "Settings → Permalinks" and click "Save" — this rebuilds the language URL rules.

If the permalink structure is "Plain", the plugin shows a warning: language URLs do not work with it.

For a step-by-step version see [docs/QUICK_START.md](docs/QUICK_START.md) (Russian).

## Stored names

All internal identifiers use the `lingocanvas` prefix: tables `{prefix}lingocanvas_*`, options `lingocanvas_*`, post meta `_lingocanvas_*`, the language switcher widget `lingocanvas_language_switcher`, REST namespace `lingocanvas/v1`, the PHP namespace `LingoCanvas`, the sitemap `/lingocanvas-sitemap.xml` and the folder `wp-content/uploads/lingocanvas-flags/`.

## Uninstalling

Deleting the plugin removes no data by default: translations are manual work. To remove everything, tick "Delete all translations and tables when the plugin is uninstalled" in "Multilingual → Languages" before deleting the plugin: the four tables, the `lingocanvas_*` options, the post meta, the transients and the `lingocanvas-flags` folder are removed. Without the tick only the service options are removed.

## Where to start and in what order

In short: **set it up once, then translate only your own text.** The site interface is translated by itself, and most lists need not be touched at all.

### Once, at installation

1. **"Multilingual → Languages"** — add languages. In each row check the **"WordPress locale"** (`bg_BG`, `en_US`): this is the name under which WordPress looks for translations of its interface. It is filled in automatically, but `en` can mean both `en_US` and `en_GB` — it cannot be guessed.
2. In the same place, next to the locale, is the **language pack** indicator. If it says "no pack", click "Download language pack". Without it the interface stays English and it will look as if the plugin does not work. English does not need a pack.
3. Check: open any page at `/bg/`. The menu, the comment form and the buttons should become Bulgarian **without any action from you**. If they did, the most important part already works.

### Then — for every new post

4. Write the post in the main language as usual.
5. **"Multilingual → Visual editor"**, pick a language and a page → "Open".
6. Click **"Translate the whole content with AI"** — title, excerpt and the whole text in one operation. Review the suggestion and click "Save all".
7. Look through the page. Whatever is still untranslated — click it right in the preview and type the translation.

That is enough in 90% of cases. Everything below is for the rest.

### When something is left untranslated

Open **"Multilingual → String translation"** and choose the tab by **what exactly** is untranslated:

| What you see on the site | Tab | Who wrote it |
|---|---|---|
| Post text, title, menu item, image caption | **Content** | you |
| "Reply", "Leave a comment", the cart button | **Interface** | WordPress, theme or plugin |
| Nothing visible, but wrong in Google or when sharing a link | **SEO/GEO** | the SEO plugin |

The rule is simple: **you wrote it — "Content", you did not — "Interface"**.

### What you can skip forever

- Do not translate admin plugin strings (Rank Math, Akismet, Action Scheduler) on the "Interface" tab. Only you see their labels, in the admin; visitors never do. Look at the "Source" column.
- Do not look for strings in advance: the list fills only when a page is actually shown in an additional language. An empty list means "not opened yet", not a breakage.
- By default strings are remembered only when you open the page (an administrator, `manage_options`) — only what you decide to open gets translated. Strings from pages opened by visitors are collected only if you turn on "Also remember strings from pages opened by visitors" in "Multilingual → Settings → String collection" (`discover_from_visitors`). The master switch is "Remember new strings…" (`discover_strings`). If the site sits behind a page-cache plugin, administrators usually bypass it, so collection works.
- Do not translate a post title twice. It is shared by H1, `<title>`, social networks and structured data — it is translated once on the "Content" tab.

### If the list gets cluttered

The **"Delete untranslated strings (N)"** button on each tab removes found strings that have no translation at all ("Content" and "SEO/GEO" share one set, "Links" only covers addresses, "Interface" covers gettext strings); N is how many will be deleted, and at zero the button is inactive. Deletion goes in batches of 500. It does not touch anything translated, and whatever is needed is collected again the next time pages are shown. Useful if junk got into the list — for example, you browsed the site before installing the language pack.

## How to use

### Add a language

"Multilingual → Languages": use the **"Add language"** field under the table — start typing a name or locale, pick a language from the WordPress list (or type a locale such as `de_DE` by hand) and click "Add". The row comes pre-filled (code `de`, URL slug `de`, name `Deutsch`, WordPress locale `de_DE`, status Published); if the code or slug is already taken, the full locale is used (`pt-br`). The number of languages is unlimited; an unsaved row can be dropped with "Delete language". Optionally set an emoji flag (`🇩🇪`) and change the status. Save — the URL rules are rebuilt automatically. Without JavaScript one empty row is shown instead of the picker.

- **Published** — the language is available to visitors and appears in `hreflang`.
- **Draft** — the language is visible only in the admin: its URL returns 404 and it is not in `hreflang`. Handy while the translation is not ready.

The default language is served without a prefix and cannot be a draft.

**Language order** in the switcher is set by the order of rows in the table — rearrange them with the arrows in the first column and save. The default language moves like any other: it does not have to be first.

**What the visitor sees** is chosen below the table, in the "Show in switcher" field:

| Mode | What is printed |
|---|---|
| Language name | `Русский` — how it was before the setting existed, the default |
| Language code | `RU` |
| Flag only | the flag image |
| Flag and language code | the image and `RU` |

### Flags

A flag is uploaded right in the languages table: in the "Flag" column pick a file in the right row and save. The file name does not matter — the plugin stores it under the language code itself. To remove the image, tick "remove" next to it and save.

Where to get them: [lipis/flag-icons](https://github.com/lipis/flag-icons) — a set of all flags under the MIT license, the ones you need are in `flags/4x3`.

**Which file to take.** In these sets flags are named by country, not by language: Russian and Bulgarian correspond to `ru` and `bg`, while English is `gb` (Great Britain) or `us` (USA), your choice. Nothing needs renaming.

Only **SVG up to 64 KB** is accepted. The uploaded file is sanitized of executable content: SVG is XML with DOM access, and it may contain `<script>`, `onload` handlers and `javascript:` links. The file lives in uploads and opens by a direct link, so without sanitizing such a script would run on your domain — which is exactly why WordPress does not allow SVG uploads without a plugin. The plugin parses the file, cuts out the dangerous parts and saves the already safe version; whatever does not parse as SVG is not saved at all.

The files themselves are in `wp-content/uploads/lingocanvas-flags/`, not in the plugin directory: the plugin directory is overwritten on every update, and flags kept there would one day vanish without warning.

If there is no image, the plugin shows the emoji from the same field, and if that is empty too, the language code. The switcher is never left empty.

Why a file and not emoji: **Windows cannot render emoji flags at all** — instead of `🇷🇺` the visitor sees the letters `RU`. On macOS, iPhone and Android they display fine, so the problem is easy to miss. An image looks the same everywhere.

In a dropdown (`<select>`) the flag is always text — emoji or code: HTML itself does not allow an image inside an `<option>`.

### Translate a page in the visual editor

The fastest way when you need to translate a specific page.

1. "Multilingual → Visual editor". Or open the page on the site and click "Translate page" in the top bar.
2. Pick a language and find the page: start typing the title in the "Page" field (a suggestion list, arrows and Enter) or paste an address or path, for example `/about/`.
3. In the preview on the right, hover — the translatable places are outlined. Click the one you want.
4. On the left the original and the translation field appear. Type the text and click "Save" (or Ctrl+Enter) — the text in the preview is replaced immediately.
5. Follow links right in the preview: the editor mode is kept.

The "Delete translation" button clears the translation of this string only.

### Translate the whole content with AI

For a specific post or page — instead of translating block by block by clicking each node.

1. "Multilingual → Visual editor", open a post (the path must point to a real post/page — there will be no button on archives and service pages).
2. The "Translate the whole content with AI" button opens a choice: **"Empty segments only"** (the default — does not touch what is already translated) or **"Translate the whole content again"** (overwrites everything, including already confirmed translations — a deliberate choice, not the default).
3. The plugin parses the post's title, excerpt and `post_content`, shows progress by chunks (the content is automatically split into chunks for the model's context window — nothing needs to be split by hand) and fills the translation fields right in the panel. Each translated string is immediately shown in the preview on the right.
4. The list is a draft, not a final result: any string can be edited by hand right in the panel, and so can its status.
5. "Save all" writes the whole list in one operation. If saving is interrupted by an error, none of the strings of that operation are saved, and whatever was translated earlier (by hand or last time) is not touched.

`post_content` in the database is not changed at all — neither during translation nor after "Save all": as everywhere in the plugin, the translation lives in the separate table `{prefix}lingocanvas_translations` and is substituted only when the page is shown to a visitor. The title, excerpt and content are parsed into segments with a stable `uniq_hash` — the same way as an ordinary page (spec 4.4) — but the source is the post's own fields, not the rendered HTML: the wrapper comment `<!-- wp:... -->` does not survive block rendering (core strips it on display), so it can only be taken from `post_content` directly.

The format around the translated text is not touched by a single character — and this is not "we try not to change more than needed" but a consequence of how substitution works: the translation of each segment is found in the source string as an exact substring and replaced in place, with no parsing and no HTML reassembly. Everything else is copied from the source one to one, including what any tool that reconstructs markup through the DOM used to spoil: self-closing tags (`<img ... />` does not turn into `<img ...>`), the exact quote style and attribute order, Gutenberg comments with JSON parameters, classes, inline styles, `id`, `data-*`, links and images. If an exact substring for a segment cannot be found (for example, a character in the text is encoded with an unusual HTML entity) — the operation for that field stops explicitly instead of putting the translation "roughly there".

If the text has a shortcode mixed with ordinary words (`Click [button url="/x"]here[/button]`), the AI is asked not to touch the tag itself. The check after the answer is not "looks like the same tag" but an exact comparison of the whole sequence of shortcodes: names, order, nesting, attributes and their values. If the model's answer contains even one discrepancy, that string does not enter the suggested translation (translate it by hand), and if a corrupted translation somehow does reach saving, the server rejects the whole "Save all" operation without writing a single string from it.

Strings whose title/excerpt/content match ones already translated elsewhere on the site do not need translating again: the dictionary is shared (see "Translate a string from a list" below). On the next change of the post a repeated run finds only what really changed since last time (compared by `uniq_hash` with the stored snapshot of the post's last translation) — the rest of the finished translation is not reset.

Strings of global theme elements (menus, footer, widgets) and SEO/GEO fields (meta, Open Graph, JSON-LD) are deliberately not included here — they are still handled by the "String translation" tool below; a post and the site as a whole have different translation screens.

**Atomicity of "Save all".** Validating the batch and the database write itself are two independent steps. First the whole submitted list is validated entirely, without a single database write: an unknown or foreign (belonging to another post) string identifier, a duplicated identifier, a corrupted shortcode — any of these findings stops everything, and no string is saved. Only after the validation has passed in full does the write begin — as one SQL transaction: if the database fails in the middle, the strings already written in this operation are rolled back too. Previously saved translations (from earlier operations) are not touched by this check until the current one succeeds as a whole.

If the "batch validation failed" error names `foreign_segment` for strings that definitely belong to this very post, that was a real bug (fixed on 09.08.2026): the record of a string's "places of use" did not take into account which post it belongs to, only the fact that it "occurred during bulk translation". If the same phrase (a shared boilerplate paragraph repeated in several posts) occurred during bulk translation of SEVERAL posts, ownership stayed only with the one parsed last — the other posts could not confirm that the phrase belongs to them too, and "Save all" rejected the whole batch. The fix is backward compatible by itself: the next time the "Visual editor" is opened for an affected post, its strings are re-registered correctly, with no manual database action.

**What is verified automatically and what is not.** Parsing a post into segments, substituting a translation as an exact substring (including memory of self-closing tags and HTML entities), chunking, choosing the "empty only"/"translate again" mode, full batch validation before writing (foreign/unknown/duplicated identifier, corrupted shortcode), building "places of use" with the post id taken into account (see `foreign_segment` above) and the whole chain "parse → several chunks → validate → write → re-read", including two posts with a shared paragraph, are covered by unit and integration tests (`tests/Rendering/PostContentExtractorTest.php`, `tests/Rendering/RawContentPatcherTest.php`, `tests/Rendering/SegmentLocatorTest.php`, `tests/Rendering/PostFieldPatcherTest.php`, `tests/Support/ShortcodeGuardTest.php`, `tests/Translation/PostCommitValidatorTest.php`, `tests/Translation/BulkTranslationModeTest.php`, `tests/Translation/PostOccurrenceRowsTest.php`, `tests/Integration/PostBulkTranslationChainTest.php`) and run without WordPress installed. Not covered automatically and requires a manual check on an installed plugin: the REST routing itself, `permission_callback`/nonce on top of a real WordPress, and the behavior of a real SQL transaction (`START TRANSACTION`/`ROLLBACK`) on a live MySQL when a failure occurs mid-write — this cannot be reproduced without a real database server.

### Translate a whole paragraph

Sometimes a paragraph is broken up by tags: `Read <b>our</b> blog` is three separate pieces, and translating them separately properly is impossible because word order differs between languages.

Click such a paragraph in the editor — a "Translate whole paragraph" button appears under the translation field. After clicking, the paragraph becomes a single string, and it must be translated together with its markup: `Lies <b>unseren</b> Blog`.

Only presentational tags are allowed (`b`, `i`, `a`, `span`, `img`, `br` and the like). Scripts, styles and event handlers are stripped.

### Interface translation: WordPress, theme and plugins

Strings like "Reply", "Your email address will not be published", "Required fields are marked *" or the cart buttons **do not need to be translated by hand**. They are already translated by the WordPress community and live in language packs — the plugin only has to tell core that the current page is in another language.

How it works: on an additional language the WordPress locale is swapped (`locale`, `plugin_locale`, `theme_locale`), and core, the theme and plugins return their own strings from `wp-content/languages/`. Not a single database row, not a single OpenAI request.

**What to do once:**

1. "Multilingual → Languages", the **"WordPress locale"** column — check the value (`en_US`, `bg_BG`, `ru_RU`). It is prefilled from the language code, but not everything can be guessed: British English needs `en_GB`, and Catalan's locale has no region at all — `ca`.
2. In the same place is the language pack indicator. If it says "No language pack", click **"Download language pack"**: without translation files core returns the English originals, and the locale swap will look as if it "did not work". English does not need a pack — WordPress ships with it.

**English is special**: at `/en/` the effect is visible immediately and without downloading, because the original WordPress strings are English text. For Bulgarian without a pack the interface stays English — that is not a breakage but exactly the absence of the pack.

The **"Interface" tab** in "String translation" is for what the official pack did not cover: a niche plugin may have no translation into the needed language at all. There you can also override an unfortunate wording from the official translation — your version always wins over the pack, and the "Reset to official" button brings the pack's one back.

The list shows where a string comes from ("WordPress (core)", "Plugin: Easy Digital Downloads"), its context and its plural form. Strings the pack has already translated deliberately do not enter the list: otherwise it would swell with thousands of core strings, and finding the ones that need attention among them would become impossible.

**What the locale swap does NOT touch**: the admin, AJAX, cron, REST and POST requests. So `wp-admin` stays in your language, and transactional emails (comment notifications) go out in the source language — their language is not changed at all in this version.

The date format, month names and text direction change together with the language — that is part of the locale, and it is meant to be so.

### Translate a string from a list

1. Open any page of the site in an additional language, for example `https://site.ru/en/about/`. The plugin remembers all strings found on it.
2. Go to "Multilingual → String translation", pick a language and, if you like, the "No translation" filter.
3. Type the translation and click "Save" (or Ctrl+Enter).
4. Refresh the page in the additional language — the text is replaced.

Strings appear in the list **only after the page has been visited** in an additional language: the plugin translates what the theme actually printed and cannot know this list in advance.

Identical text is translated once for the whole site. Text and an attribute value count as different strings: "Buy" in a button and "Buy" in an image `alt` are translated separately.

The "Type" filter divides strings into text, attributes (`alt`, `title`, `placeholder`), blocks with markup and **SEO/GEO** — meta description, Open Graph, Twitter Card, `article:section`/`article:tag` and the text fields of JSON-LD. Normally these fields are scattered across the page mixed with ordinary text; the filter gathers them into one list so you need not search by hand.

### Delete translations

- The "Delete" link in a table row or the button in the editor removes a single translation.
- The "Delete all translations of the language" button under the filters removes all translations of the selected language. The action is irreversible and asks for confirmation. Source strings stay; only translations are deleted.

### Language switcher

Three ways to put it on the site.

**In the site menu (usually what you want).** "Appearance → Menus" → the "Languages" block on the left → tick **one** of the two checkboxes — "Dropdown" or "Inline" — and click "Add to menu". Nothing else is needed: under this single item the plugin substitutes the real languages itself every time the menu is shown, and nothing has to be nested in advance.

```
"Dropdown" → items appear on hover/click,
             styled by your theme's submenu:

Home  Blog  Contacts  🇷🇺 Русский ▾
                       ├ 🇷🇺 Русский
                       └ 🇬🇧 English

"Inline" → languages are visible at once, as separate items in a row:

Home  Blog  🇷🇺 Русский  🇬🇧 English
```

The item that opens the dropdown shows the **current** language — in the same form as chosen in the settings (flag, code, name). That way the visitor sees which language the page is in before opening the list. The item title set in the menu is not used here; it can be brought back with the `lingocanvas_nav_menu_trigger_title` filter.

Both items can be added at the same time, and both can be removed like an ordinary menu item. For the "Inline" item the title does not matter either — it becomes the first language itself.

**Shortcode** in content or a template:

```
[lingocanvas_language_switcher]
[lingocanvas_language_switcher type="dropdown"]
[lingocanvas_language_switcher show_current="no"]
```

**Widget** "Language switcher" in "Appearance → Widgets".

Draft languages are not shown anywhere: they have no available address. If the only published language is the menu item itself, there is nothing to switch to, and the plugin does not print it at all.

The dropdown is designed for themes with the standard WordPress submenu markup (`.sub-menu`, the `menu-item-has-children` class) — the vast majority of themes work this way. For very non-standard mega-menus the theme may not pick this up automatically; in that case you can add your own class to the trigger item with the `lingocanvas_nav_menu_dropdown_classes` filter.

### Sitemap

The plugin serves its own sitemap at `/lingocanvas-sitemap.xml`. It contains all language versions with mutual `xhtml:link hreflang` — Google uses them to understand that `/about/` and `/en/about/` are one page in two languages, not two duplicates.

Untranslated pages do not get into the sitemap: their `/en/` address serves Russian text, and for a search engine that would be a duplicate. If a post in the sitemap has no pair in some language, it almost always means "not translated into this language yet", not an error: as soon as at least one translation appears (manual or via "Translate the whole content with AI"), its language version appears in the sitemap on the next generation.

The home page now has a `lastmod` too: if it is static (set in "Settings → Reading"), its own edit date is used; if the home page is the latest-posts feed, the date of the freshest publicly visible post of the site is used.

The `lastmod` of each post is computed from the real content modification date, not the sitemap build time: for the default language it is the post's own edit date (`post_modified`), for other languages — that same date OR the date of the last translation change, whichever is later. A translation is stored not in the post itself but in a separate table, and an ordinary post edit does not touch it: without this comparison the `lastmod` of the English version of a page would forever stay the date of the last edit of the Russian text, even if the translation itself appeared only today.

Service pages are excluded from the sitemap — this is decided not by slug or title but by several independent signs at once:
- WooCommerce role pages — cart, checkout, my account (together with all nested ones — order history, confirmation, invoice, failed transaction, etc.), if WooCommerce is active; the product catalog (the "Shop" page) is not part of this exclusion — it is an ordinary section, valuable for SEO;
- password-protected pages;
- pages explicitly marked `noindex` in Yoast SEO, Rank Math or SEOPress — the same three plugins the plugin is already compatible with elsewhere;
- pages whose slug is entered by hand in "Multilingual → Languages → Exclude from sitemap" — for cases without WooCommerce and without a `noindex` mark (for example, a checkout page built by hand without any cart plugin, or a technical page like "Access denied").

A match on any of these signs excludes both the page itself and everything nested under it. To remove something else programmatically, not through settings (for example, from a separate plugin or snippet), use the `lingocanvas_sitemap_post_ids` filter — it receives the ready list of post ids right before the sitemap is built.

**If problem addresses already got into the sitemap earlier and Google had time to index them** (Search Console → Sitemaps shows the number of processed pages) — fixing the sitemap alone is not enough: Google does not remove a page from the index just because it disappeared from the sitemap; this can take weeks. To speed it up: Search Console → Indexing → Removals → "New request" for each address that must not be in the results.

The address is automatically added to `robots.txt` as a single `Sitemap: ...` line, so search engines find the sitemap themselves; the line is not added again even if something triggers the addition once more. Other plugins (Yoast, Rank Math) may append their own `Sitemap:` line to `robots.txt` — that is normal, several different sitemaps in one `robots.txt` are compatible with each other and are not a duplicate of the same sitemap. Submitting the sitemap manually in Google Search Console is faster than waiting for it to be found. The link is in "Multilingual → Languages".

The sitemap is not embedded in the WordPress core sitemap and does not replace the Yoast or Rank Math sitemaps — it works alongside them. The reason: the core sitemap renderer outputs only `loc` and `lastmod` and silently drops `xhtml:link`, so language versions cannot be added there.

### SEO markup and compatibility with Yoast / Rank Math

An exact list of fields is translated — a whitelist, with no wildcard matching: `<title>`, meta description, `og:title`, `og:description`, `og:image:alt`, `article:section`, `article:tag`, `twitter:title`, `twitter:description`, `twitter:image:alt`, `twitter:label1`, `twitter:label2`, `twitter:data2`. `twitter:data1` is deliberately not in the list — it usually holds the author name, a proper noun rather than text for the reader. `og:site_name` is deliberately not in the list either: per the Open Graph specification it is the name of the whole site ("CenterAI"), the same brand name as `name` of `Organization`/`WebSite` in JSON-LD — that one is already protected from translation, and og:site_name is protected the same way: by its absence from the whitelist. The other fields (`twitter:card`, `twitter:site`, `twitter:creator`, `article:published_time`, `article:author` and so on) are not touched at all — they are not in the list and stay as the SEO plugin printed them. Translatable strings go into the common "String translation" list alongside the page text — they can be found separately with the "Type → SEO/GEO" filter.

Separately, what must not be translated but has to be replaced is fixed: `og:url` and `twitter:url` point to the language address, `og:locale` to the current language in the full `xx_XX` form (`ru_RU`, `bg_BG`, `en_US`), as the Facebook documentation requires — a bare two-letter code is not enough. `og:locale:alternate` is rebuilt from the other languages in the same format. `og:image` is never touched — if the SEO plugin already has the post's image set, it stays exactly the same in any language; the site logo is used by the SEO plugin only as a fallback when the post has no image of its own — the plugin does not interfere in this choice at all, it only makes sure the address does not break when the language prefix is substituted.

**One post title translation — everywhere.** `<title>`, H1, `og:title`, `twitter:title` and JSON-LD `headline` in the vast majority of SEO plugin configurations carry the same text — the post title. These used to be four independently hashed segments (a text node, two meta attributes, a JSON-LD field): they could be translated separately, and the resulting page could easily show different wordings in the browser tab, in the text itself and in the social preview. Now `og:title`/`twitter:title`/`headline` are equated to ordinary text when the dictionary key is computed — if their content matches the text of `<title>`/H1 (and it always does, unless the SEO title is overridden by a template with a suffix like `%title% — %sitename%`), they all share one dictionary key and one translation, and the substitution reaches all five places at once from a single translation.

At the same time, in the `sources` table such a merged string is stored and found by the "Type → SEO/GEO" filter precisely as an SEO field, not as ordinary text: `SegmentDeduplicator` — the part that chooses the "representative" when dictionary keys coincide — deliberately prefers the SEO variant (`og:title`/`headline`) over H1/`<title>`, even if the HTML node occurs earlier in the document. Without this priority, H1/`<title>` would almost always win (they are in `<head>`/the start of `<body>`, while JSON-LD is usually closer to the end of `<head>`), and the merged title string would stop being found through the SEO/GEO filter, remaining only in "Text".

**JSON-LD** (Rank Math, Yoast structured data): only titles and descriptions are translated — `headline`, `description`, `name` in breadcrumbs and the like. Proper nouns are not touched: `name` of `Person`, `Organization` and `Brand` stays as is, so that "CenterAI" does not turn into something else. `inLanguage` is replaced with the current language code. `url` inside `WebPage`/`Article` gets the language prefix, but `url` inside `ImageObject`/`VideoObject`/`AudioObject` never does: it is a link to a file in `wp-content/uploads`, the same for all languages, and an added prefix would turn it into a nonexistent address. Double protection: by object type and, independently, by file extension — if the type in someone else's graph is given inaccurately, the extension saves it.

Identifiers (`@id`) are processed in passes rather than in place in the tree:

1. First a map of the graph's DEFINED entities is built — nodes where `@id` stands next to its OWN `@type` (`{"@type":"Organization","@id":"..."}`). `@type` is read both as a string and as a list (`["Person","Organization"]`) — a match on any element is enough. For each entity the new `@id` value is decided by ITS OWN type: `Organization`/`Person`/`WebSite` (a real-world object, the same in any language) — without a language prefix, always, in any language, including the default one; `WebPage`/`Article`/`BlogPosting`/`NewsArticle`/`BreadcrumbList` (a specific language version of a page) — with the current language prefix, exactly like the `url` field. The SEO plugin usually builds a stable `@id` through `home_url()`, and that call already goes through our own language prefix filter before the page reaches DOM parsing — so the `@id` may arrive ALREADY with a foreign prefix (`.../en/#organization`), and the plugin does not just leave it as is but actively returns it to the canonical form.
2. Separately, in the same way, nodes referenced by the `logo` property (`Organization.logo`, `Person.logo`) are found — regardless of their place in the graph and the kind of reference (a bare `{"@id":"..."}"` or a full inline definition with its own `"@type": "ImageObject"`). The site logo is just as stable and language-independent an asset as the Organization itself, but by `@type` alone it cannot be told apart from the image of a specific post (`Article.image`): both are `ImageObject`. The difference is that the logo is referenced SPECIFICALLY as `logo`; a post image is never expressed that way, and this check does not affect its `@id`/`url`.
3. The ready "old value → new value" map is applied to ALL occurrences of `@id` in the graph — not only where the entity is defined but also where it is referenced: `"publisher":{"@id":"..."}"`, `"author":{"@id":"..."}"`, `"about"`, `"isPartOf"`, `"mainEntityOfPage"`. Such a reference usually has no `@type` of its own at all, and this used to be the source of a bug: the type of the reference was inferred from its parent in the tree (`Article` for `publisher`), but the reference to an organization does not become an organization because of that. Now a reference finds its new value by matching the `@id` itself, not by structural position.

Types for which no rule is defined and which `logo` does not reference (for example `ImageObject` — the post's own image) are not touched by the plugin at all — it neither adds nor removes a prefix: touching what you do not understand is more dangerous than leaving it as is.

Compatibility with Yoast and Rank Math is done not through their PHP filters but by editing the finished HTML — the same that goes to the visitor. Whatever they print, the result is brought to the current language, and updating those plugins breaks nothing.

### Internal links and the theme footer

Links built with WordPress means (`get_permalink()`, menu items and the like) get the language prefix automatically. But a theme may write a link in a template as literal text — `<a href="/kontakty/">`, bypassing these functions. Such a link often appears in the footer if it is hard-coded in `footer.php`: without extra processing it would lead to the source language even when opened from `/en/...`.

A separate pass over the finished HTML finds such links to your own domain and adds the language prefix if it is not there yet. External domains, `mailto:`, `tel:`, anchors (`#section`) and an address that has already gone through `home_url()` are not touched — nor are files in `wp-content/uploads` (documents, images), which have one address for all languages.

A separate case is menu items saved with a literal address before this plugin appeared on the site (for example `<a href="/ru/kontakty/">`, where `/ru/` is the old way of marking the Russian section). If you just prepend the new prefix to such a link, you get `/en/ru/kontakty/` — double localization. The plugin recognizes the first path segment as the slug of ANOTHER known language and replaces it with the target one instead of adding on top.

The language switcher's own links (the NavMenu item, the `[lingocanvas_language_switcher]` shortcode and widget) are not touched by this pass: each of them already leads to ITS OWN language, not to the current page's language — they are marked with the `mlp-language-item` class right on the `<a>`, and the pass skips them by it. Without this mark the switcher would look like an ordinary theme link on the second pass and get the current page's prefix on top of an already correct address — ru/bg/en would all lead to the same place.

Footer text (copyright, slogans, any phrases) is translated by the same common mechanism as all other page text: for the string extractor `<footer>` is no different from `<main>` — there is no separate tag or flag for the footer in the code and none is needed. If a footer phrase in an additional language is still untranslated, it means it has not yet been translated in "String translation" (or you have not scrolled to it), not that the plugin skipped it; you need to visit a page with that footer in the needed language at least once — then the string appears in the list. The only practical nuance: if the footer has a widget like "Recent posts", it shows titles of OTHER posts of the site in their own text (usually in the source language) — if a particular post has no title translation yet, it will appear untranslated in the footer until such a translation exists (in the same way as for H1 — see "One post title translation — everywhere" above).

### Untranslated posts

"Multilingual → Languages → Untranslated posts" has two modes:

- **Show all posts (translated or not)** — an additional language shows every post and page of the site.
- **Show on a language only posts enabled by hand (recommended)** — a post or page appears on an additional language (blog, categories, search, widgets, menus, previous/next links, the sitemap and its own address) only when you have switched it on for that language. A hidden post's address on the language redirects (302) to the same page in the default language.

Enabling in manual mode (a post is stored as the `_lingocanvas_visible_<language>` meta key):

- the **"on site" switch** next to each language in the "Languages" column of the posts and pages list;
- a bulk action in the list: "Show on language: X" / "Hide on language: X";
- the "Shown on X" / "Hidden on X" filter above the list;
- the "Show on site" checkboxes in the post edit screen's "Translation" box;
- the "Show this page on X" switch in the visual editor (with the translation percentage);
- the **"Enable all fully translated on X"** button under the mode in "Languages" — it uses the same translated-strings count as the "Languages" column; the number of enabled posts per language is shown next to it.

Switching a post needs both `manage_options` and the right to edit that post. Block-theme navigation links (`core/navigation-link`, `core/navigation-submenu`) to hidden posts are removed, and comments on hidden posts are left out of "Recent comments" and the comment feeds.

Known limits:

- category/tag post counts and `wp_get_archives()` still include hidden posts;
- if the static front page is not enabled on a language, that language's home redirects to the default language (and the switcher's "home of the language" fallback lands there) — enable the front page first;
- page-cache plugins with a "cache for logged-in users" option (e.g. WP Rocket) may serve the editor's view of a hidden page — keep that option off.

A logged-in user who can edit a hidden post is not redirected: the admin bar shows "Hidden on DE — visitors do not see it" with a "Show on DE" button (or "Shown on DE" with "Hide on DE"). Lists and menus stay filtered for them as for visitors.

## Building the ZIP

```bash
php bin/build-zip.php            # bump the patch version and build dist/lingocanvas-<version>.zip
php bin/build-zip.php --no-bump  # without changing the version
php bin/build-zip.php --dry-run  # only list files, write nothing
```

The archive is named after the version (`dist/lingocanvas-0.3.14.zip`); an existing archive is never overwritten. The archive (folder `lingocanvas/`) contains only the runtime: `lingocanvas.php`, `uninstall.php`, `readme.txt`, `src/`, `assets/`, `languages/`. `README.md` and `.env.example` are not shipped, and the real `.env` never gets into the archive. Unless `--no-bump` is given, the build also syncs `Stable tag:` in `readme.txt` with the new plugin version and fails if they differ.

`readme.txt` is the WordPress.org readme (plugin directory page); `README.md` / `README_RU.md` are the repository docs.

## Connecting OpenAI (AI translation)

Without this step the plugin works as usual — the "Translate with AI" button is simply not visible, neither in the string list nor in the editor. No access to the hosting files is needed — everything is through the admin (or, optionally, through `wp-config.php`, see below).

1. "Multilingual → Languages". In the "OpenAI key" block enter the key — <https://platform.openai.com/api-keys>. API documentation: <https://platform.openai.com/docs>.
2. Below is "OpenAI model": a drop-down with the models the API currently offers for your key (loaded live from `{API address}/models`, cached for 12 hours; the "Refresh list" button reloads it; "Other model…" lets you type an identifier by hand, e.g. for a proxy). The list appears once the key is saved. Touch "API address" only for your own proxy/compatible gateway; the default is `api.openai.com`.
3. Save. In the same block "Key saved, ends with «…»" appears — the key itself is never shown again, and the field for entering a new value is always empty. To replace the key, enter a new one; to keep it as is, just do not touch the field when saving other settings; to delete it, tick "Delete the saved key".
4. Set the daily character limit (100,000 by default) — this is protection against accidental spending, not automation: the plugin translates nothing by itself, the limit just caps the amount you can spend by pressing the button.

The key is stored in the site database (the `lingocanvas_settings` option) and is never printed to HTML, JS, REST responses or logs (spec, section 13) — only the last 4 characters, to confirm "this is the key you entered". This is more convenient than `.env`, but this storage has a different risk profile: the key ends up in the DB dump and in site backups if they are not encrypted — keep this in mind when moving the site or exporting the DB to third parties.

### Where to store the key, model and API address

For each of the three fields (key, model, API address) the sources are checked in turn, and the first one found wins:

1. **Constants in `wp-config.php`** (before the "That's all, stop editing!" line). The admin field is not used in this case:

   ```php
   define( 'LINGOCANVAS_OPENAI_API_KEY', 'sk-...' );
   define( 'LINGOCANVAS_OPENAI_BASE_URL', 'https://api.openai.com/v1' ); // or https://openrouter.ai/api/v1
   define( 'LINGOCANVAS_OPENAI_MODEL', 'gpt-5.6-terra' );
   ```

2. **Admin settings** ("Multilingual → Languages") — stored in the database.
3. **The file `wp-content/lingocanvas.env.php`** — the format is like `.env.example` (`OPENAI_API_KEY`, `OPENAI_MODEL`, `OPENAI_BASE_URL`), but **the first line must be `<?php exit; ?>`**. The file lies inside the web root, and this line makes a direct request to it execute PHP and print nothing. Without the guard line the file is not read, and a warning appears on the settings page:

   ```
   <?php exit; ?>
   OPENAI_API_KEY=sk-...
   OPENAI_MODEL=gpt-5.6-terra
   ```

4. **The file `lingocanvas.env` one level above the WordPress root** (`dirname( ABSPATH )`) — the same `KEY=VALUE` format, without the guard line: it is not reachable from the web.
5. **The `.env` file in the plugin folder** (legacy method). Real server environment variables take precedence over all files.

**Never put an ordinary `.env` or `lingocanvas.env` inside `wp-content`** — that folder is served by the web server, and a file with the key can be downloaded by a direct link. The most reliable option is constants in `wp-config.php`.

Methods 1–4 survive replacing the plugin. **Replacing the plugin through "Upload plugin" (ZIP) deletes the whole plugin folder — together with the `.env` inside it, and the key is lost.** If the key currently lives in the plugin's `.env`, a warning appears on the settings page: paste the key into the field or move it to `wp-content/lingocanvas.env.php`. The key source status (without the key itself) is visible on the "Languages" page.

API address: an empty field in the admin means "not set" (taken from the environment, otherwise `https://api.openai.com/v1`); any non-empty value, including the standard address, is set explicitly and takes precedence over the environment.

**OpenRouter and other OpenAI-compatible gateways:** the API address is `https://openrouter.ai/api/v1`, the key is an OpenRouter key (<https://openrouter.ai/keys>), the model is an identifier of the form `provider/model`.

**How it works:** next to the translation field (in the string list and in the visual editor) a "Translate with AI" button appears. Clicking it sends the source text to OpenAI and puts the answer into the field — **without saving it**. The translation stays a draft (status "Machine") until you review it and click "Save" yourself.

You can plug in your own provider (another service instead of OpenAI) with a filter, without editing the plugin core:

```php
add_filter( 'lingocanvas_translation_provider', function () {
    return new My\CustomProvider();
} );
```

The class must implement `LingoCanvas\Translation\ProviderInterface`.

### If "Translate with AI" fails with a network error

A message like `Failed to get a translation from OpenAI: network error: cURL error 28: Operation timed out after ... with 0 bytes received` means the request to OpenAI got no response at all — no error and no start of data — within the allotted time. The plugin makes **up to two attempts of 45 seconds each** with a pause between them and, for the duration of the request itself, raises PHP's `max_execution_time` so the hosting does not cut it off earlier — if the message still appeared, both attempts failed to reach the server. Beyond that it is not the plugin's job: it honestly waited and clearly reported the failure, but cannot get around a broken connection. What to check:

- **API address** ("Multilingual → Languages → API address"): by default `api.openai.com` — if your own proxy/gateway is set there, the problem may be in it and not in OpenAI;
- **The hosting's outgoing connections**: some cheap plans block or heavily throttle outgoing HTTPS to non-standard addresses — ask hosting support whether `curl https://api.openai.com` works from the server at all;
- **OpenAI status**: <https://status.openai.com> — if they have an outage, waiting 30–45 seconds is pointless with any timeout;
- **Content size**: with "Translate the whole content with AI" the text is cut into chunks of ~6000 characters (`BatchChunker`) — if the error is stable on the same large chunk while small strings translate fine, it is not about the connection but about the model not fitting into the allotted time on exactly that volume.

If the error is not a one-off but stable, the translation spoils nothing in any case: the string simply stays a draft with no saved translation, and retrying is safe at any moment.

## Connecting LingoCanvas Cloud (paid AI translation)

LingoCanvas Cloud is an optional paid translation service by CenterAI (translation is performed by DeepSeek, servers in China). It is an alternative to your own OpenAI key: manual translation and the own-key mode stay free and work without registration. Texts being translated (including drafts and private posts), the languages, the site name and the optional e-mail are sent to `cloud.centerai.eu` only after you tick the consent box and select the provider. Terms: <https://centerai.eu/lingocanvas/terms/>, Privacy Policy: <https://centerai.eu/lingocanvas/privacy/>.

1. "Multilingual → Languages". In the row "LingoCanvas Cloud" read the notice, tick "I agree to send texts for translation to LingoCanvas Cloud" (optionally enter an e-mail) and click "Subscribe — Starter, 9 € / month (100,000 characters)".
2. You are taken to the Stripe payment page (`checkout.stripe.com`). Pay, then come back to the "Languages" page. While the payment is not confirmed the page shows "Waiting for payment confirmation…" and checks it every 5 seconds for up to 10 minutes; "Check payment" checks right now. "Cancel" first checks the payment once more (if it is already confirmed, the site connects), otherwise it moves the pending payment aside on this site until its session expires; if you had already paid, the button "Already paid? Check again" checks it later (do not cancel a payment you have made — the confirmation can take a minute). Clicking "Subscribe" twice, or in two tabs, leads to the same Stripe payment instead of creating a second one. If the response with the connection key is lost on the way, the next check simply fetches the key again (the service re-issues it for 24 hours until the plugin confirms receipt; the plugin retries the confirmation later if it fails). If the 24 hours have passed and the key still did not reach this site, the page shows the claim ID to quote to support; the pending payment is kept. When the payment is confirmed the site connects by itself and the provider switches to LingoCanvas Cloud.
3. The connected site shows the plan, subscription status, renewal date ("Ends on …" if canceled), characters left this month, extra-pack characters and the number of sites ("Refresh" bypasses the 60-second cache). "Buy 250,000 characters — 7 €" needs a live subscription; "Subscribe again" appears when the subscription is not live; "Manage subscription" opens the Stripe portal (card, cancel, invoices).
4. The provider radio ("Own API key" / "LingoCanvas Cloud") is saved with the rest of the settings. Un-ticking the consent box and saving stops all requests to Cloud: no account status, no payment polling and no billing buttons (they explain the reason) — even when the site token is set in `wp-config.php`; AI buttons then explain what is missing.
5. Repeated translation requests: the request ID of every translation request is stored in the database (option `lingocanvas_cloud_ops`, 24 hours, up to 200 entries), so a second click on the same strings after a timeout resends the same ID and the service answers without charging again. If the service asks to wait longer than 10 seconds ("busy"), the plugin stops and tells you after how many seconds to try again.

The site token is stored in the database (option `lingocanvas_settings`) and is never printed to HTML, JS or REST responses. Optional `wp-config.php` constants:

```php
define( 'LINGOCANVAS_CLOUD_TOKEN', '...' );                          // site token; the page then shows "set in wp-config.php"
define( 'LINGOCANVAS_CLOUD_API_URL', 'https://cloud.centerai.eu' ); // only for a test/staging service
```

**Disconnect** removes the token from this site and switches the provider back to your own key. It does NOT cancel the subscription (it keeps being billed): cancel it with "Manage subscription". Saved translations are not touched. To connect an existing subscription to this site again, contact support. A token set by the `LINGOCANVAS_CLOUD_TOKEN` constant can only be removed by deleting the constant.

## Structure

```
lingocanvas.php             bootstrap: plugin header, autoloader, startup
uninstall.php               data removal — only with an explicit checkbox
src/
  Plugin.php                composition root: container and hooks
  Container.php             minimal DI
  Support/                  language codes, hashes, text normalization
  Settings/                 site languages in a single wp_options option
  Routing/                  language from URL, rewrite rules, canonical 301
  Rendering/                output buffering, DOM, extraction, substitution,
                            editor mode and node markers
  Storage/                  DB schema, repositories, object cache, daily budget
  Translation/              ProviderInterface, ManualProvider, OpenAiProvider
  Admin/                    "Languages", "String translation", "Visual editor"
  Rest/                     translation and block endpoints
  Frontend/                 language switcher, hreflang and canonical
docs/technical-spec.md      the full technical specification
docs/PROMPTS.md             tasks by stage
```

### Tables

| Table | Purpose |
|---|---|
| `{prefix}lingocanvas_sources` | source strings: text, hashes, kind, language |
| `{prefix}lingocanvas_translations` | translations: text, status, author, provider |
| `{prefix}lingocanvas_occurrences` | where a string occurred |
| `{prefix}lingocanvas_routes` | localized slugs (filled in at Stage 4) |

String uniqueness is ensured by the `uniq_hash` column — a SHA-256 of all parts of the key. The composite unique index from spec 6.1 contains nullable columns, and MySQL treats `NULL` values as distinct, so it would not stop duplicates.

### REST API

All endpoints require the `manage_options` capability and a REST nonce.

| Method | Address | Purpose |
|---|---|---|
| `GET` | `/wp-json/lingocanvas/v1/sources/{id}` | a string and its translations in all languages |
| `PUT` | `/wp-json/lingocanvas/v1/translations/{id}/{locale}` | save a translation |
| `DELETE` | `/wp-json/lingocanvas/v1/translations/{id}/{locale}` | delete a translation |
| `POST` | `/wp-json/lingocanvas/v1/translations/{id}/{locale}/suggest` | get a translation from AI without saving |
| `POST` | `/wp-json/lingocanvas/v1/blocks` | merge a paragraph into a translation block |
| `DELETE` | `/wp-json/lingocanvas/v1/blocks/{id}` | split a block back into parts |
| `POST` | `/wp-json/lingocanvas/v1/posts/visibility` | `{ids, locale, visible}` — show/hide posts on a language (`manage_options` AND the right to edit EACH post; up to 200 ids) |
| `POST` | `/wp-json/lingocanvas/v1/posts/visibility/enable-translated` | `{locale}` — enable all fully translated posts on a language (`manage_options`) |
| `GET` | `/wp-json/lingocanvas/v1/posts/{post_id}/summary` | "Translate the whole content": parse the post, create missing strings, return the segment list and chunk boundaries |
| `POST` | `/wp-json/lingocanvas/v1/posts/{post_id}/chunk` | translate one chunk via AI, saving nothing |
| `POST` | `/wp-json/lingocanvas/v1/posts/{post_id}/commit` | save the whole list as one atomic operation |
| `GET` | `/wp-json/lingocanvas/v1/cloud/status[?refresh=1]` | LingoCanvas Cloud account status as display fields only (cached 60 s); never contains the token |
| `POST` | `/wp-json/lingocanvas/v1/cloud/claim` | one check "is the payment confirmed"; returns only `{status: pending\|connected\|error, message}` |

### Performance

With a warm dictionary a page adds **2–3 SQL queries**, regardless of the number of strings on it: translations are fetched with a single `IN` query. Writing new strings is deferred to `shutdown`, that is, after the response has been sent. The "string is still alive" mark is updated no more than once an hour per page.

The list of translation blocks lives in an autoloaded option, so while there are no blocks they cost no queries. Once blocks appear, page parsing starts to additionally serialize and check every paragraph with inline tags — this becomes noticeable only on very large pages.

The editor preview works differently from an ordinary page: it is not cached, serves `noindex` and writes found strings immediately rather than on `shutdown` — otherwise the markers would have nothing to refer to.

If the site has a persistent object cache (Redis, Memcached), translations are cached between requests; when a translation is saved, the cache is invalidated as a whole through a version number.

### Page cache (WP Rocket, WP Fastest Cache, etc.)

When a string is saved, deleted or machine-translated, the plugin tries to flush the finished-HTML cache of the most common caching plugins — WP Rocket, WP Fastest Cache, WP Super Cache, W3 Total Cache, LiteSpeed Cache, SiteGround Optimizer, WP Engine. Without this the visitor could keep seeing the old, untranslated version of the page for a long time: a cache plugin that serves pages knows nothing about translations and does not ask WordPress whether anything in the database changed.

The integration is built through `function_exists()`/`class_exists()` checks against each plugin's documented functions — it was not tested live on real installations of those plugins, only by code. If after saving a translation the page on the site still does not update, check whether it remained in your particular plugin's cache and flush it by hand at least once.

For a cache that is not in the list (Cloudflare, Varnish, a custom reverse proxy) there is a hook:

```php
add_action( 'lingocanvas_purge_page_cache', function () {
    // flush your own cache
} );
```

## Development

There are no runtime dependencies; Composer is needed only for tests. There is no Python virtual environment in this project — only PHP 8.1+ and Composer are required.

```bash
composer install
composer test                 # or: php vendor/bin/phpunit
php bin/build-zip.php         # installable ZIP: dist/lingocanvas-<version>.zip (see "Building the ZIP")
```

Composer is not mandatory — tests can also be run with the PHPUnit phar:

```bash
curl -sSLO https://phar.phpunit.de/phpunit-12.phar
php phpunit-12.phar
```

### Translations (`languages/`)

Source strings in the code are always English. The Russian interface ships with the plugin in `languages/`: `lingocanvas-ru_RU.po` is the only file edited by hand; `.mo`, `.l10n.php` and `lingocanvas.pot` are generated from it. After editing the `.po`:

```bash
php bin/i18n-compile.php languages/lingocanvas-ru_RU.po          # .mo + .l10n.php
php bin/i18n-compile.php --pot languages/lingocanvas-ru_RU.po    # lingocanvas.pot
```

Both commands must exit with code 0 (the compiler checks that printf placeholders in the translation match the source). A string used in the middle of a sentence where the same word is a capitalised label elsewhere gets its own entry via `_x( 'OpenAI key', 'mid-sentence', 'lingocanvas' )` and `msgctxt "mid-sentence"` in the `.po`.

### Checks in real WordPress (WordPress Playground)

Both scripts need Node 24 and internet on the first run (they download WordPress and the pinned Playground CLI into a scratch dir; nothing is written into the plugin tree). Run them in Git Bash:

```bash
bash bin/plugin-check.sh   # official WordPress Plugin Check (plugin_repo category), prints a summary
bash bin/smoke-test.sh     # real WordPress 6.x + PHP 8.3 with WP_DEBUG: activation, tables, front end and /en/, REST GET routes, manual translation and unsafe-href rejection, repository queries, bundled ru_RU, uninstall, debug.log scan
```

Note: Playground runs WordPress on SQLite, so MySQL-specific behaviour (for example comparing the `BINARY` hash column with `UNHEX()`) cannot be verified there; the smoke test marks such checks as `SKIP`.

The tests (`tests/`) check pure logic without loading WordPress: string normalization, hashes, language code validation, URL parsing, rewrite rule building, string extraction from the DOM, translation substitution (including the fallback libxml parser), editor markers, translation blocks, `.env` parsing and building/parsing an OpenAI request. WordPress functions are replaced by stubs in `tests/stubs.php`. The network call to OpenAI is not tested — `OpenAiRequestBuilder` (payload building, response parsing) is deliberately separated from `OpenAiProvider` (the HTTP request itself), so the logic can be checked without mocking the network.

Everything that depends on `$wpdb` and hooks is checked by hand on a real site — the acceptance scenario is described in `docs/PROMPTS.md`, section 9.

If PHP is not installed locally:

```powershell
winget install PHP.PHP.8.4
```

## LingoCanvas Cloud backend (cloud/)

The `cloud/` folder holds the backend of the paid "LingoCanvas Cloud" translation provider (FastAPI + PostgreSQL, quota-based, Stripe billing); the plugin side is described in "Connecting LingoCanvas Cloud" above. It lives in this repository for convenience only and is never part of the plugin ZIP. Status: not deployed yet. See [cloud/README.md](cloud/README.md).

## External services

| Service | Why | Where to get a key | Where to enter it |
|---|---|---|---|
| OpenAI (optional) | the "Translate with AI" button | <https://platform.openai.com/api-keys> ([docs](https://platform.openai.com/docs)) | "Multilingual → Languages → OpenAI key" (stored in the database), or `LINGOCANVAS_OPENAI_API_KEY` in `wp-config.php` — see "Connecting OpenAI" above |
| LingoCanvas Cloud (optional, paid) | AI translation without your own key | subscribe on the plugin settings page (Stripe checkout); the site token arrives automatically | "Multilingual → Languages → LingoCanvas Cloud" (stored in the database), or `LINGOCANVAS_CLOUD_TOKEN` in `wp-config.php` — see "Connecting LingoCanvas Cloud" above |

## License

GPL-2.0-or-later.
