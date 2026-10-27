=== LingoCanvas ===
Contributors: centeraieu
Tags: multilingual, translation, translate, language switcher, hreflang
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.5.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One post, a URL per language: visual translation editor, hreflang, multilingual sitemap and optional AI translation.

== Description ==

LingoCanvas makes a WordPress site multilingual without duplicating content. You write a post once, and the plugin serves it at several language URLs:

* /about/ - the default language
* /en/about/ - English
* /bg/about/ - Bulgarian

There is still one post in the admin, and you edit only that one. Translations are stored in separate database tables and are substituted when the page is shown. Fix a typo in the original text and it is fixed in every language.

The plugin takes the HTML that the theme has already rendered, parses it and replaces the texts with your translations. This is why everything the theme actually prints is translated: post content, menus, header, footer, widgets, forms and image captions, not only the post body.

**Key features**

* Any number of site languages: code, URL slug, flag, status (published or draft), default language. A draft language is visible only in the admin and returns 404 to visitors.
* The language is determined by the URL only. No cookies, no redirects based on the browser language.
* Visual translation editor: a page preview next to the translation panel. Click a text on the page, type the translation, save without a reload.
* String translation screen with three tabs (Content, SEO/GEO, Interface), language and status filters, search and manual entry.
* Translation of whole paragraphs that contain inline tags (bold, links), so word order can differ between languages.
* Interface strings (comment form, cart buttons and similar) are served from the official WordPress.org language packs: the plugin switches the WordPress locale on additional languages, and the pack can be downloaded from the languages screen.
* "Languages" column in the post and page lists showing how completely each post is translated.
* Per-language visibility: show on a language only the posts you have enabled by hand (switch in the posts list, bulk action, filter, edit screen box, visual editor). A hidden post redirects to the default language.
* Language switcher: a navigation menu item (dropdown or inline), the [lingocanvas_language_switcher] shortcode and a widget. Show the language name, code, flag or flag with code. Flags are uploaded as SVG files and sanitized.
* Internal links in hard-coded theme blocks (for example the footer) get the language prefix.
* Optional AI translation with OpenAI, an OpenAI-compatible service or the paid LingoCanvas Cloud service. It runs only when an administrator clicks a button (see "External services" below). Nothing is saved automatically: you review the suggestion and save it yourself. A daily character limit caps the usage.
* Flushes the page cache of common caching plugins when a translation changes. A hook (lingocanvas_purge_page_cache) is available for other caches.

**SEO**

* Self-canonical and mutual hreflang links, including x-default, and the html lang attribute.
* A multilingual sitemap at /lingocanvas-sitemap.xml with hreflang alternates. Untranslated or hidden posts are not listed. Service pages (password-protected, noindex, WooCommerce cart and checkout, and slugs you exclude) are left out. The sitemap address is added to robots.txt.
* Translation of SEO markup: meta description, Open Graph, Twitter Card, article tags and JSON-LD titles and descriptions. Identifiers, image URLs and brand names are protected from translation. Works with the output of Yoast SEO, Rank Math and SEOPress.
* A post title is translated once and used for the page title, H1, og:title, twitter:title and JSON-LD headline.

**How it works with AI**

The plugin never translates anything in the background and never translates your site by itself. The "Translate with AI" and "Translate the whole content with AI" buttons appear only after an OpenAI key and a model are configured. The result is a draft until you save it.

**Not included in this version**

Automatic background translation queue, one-click translation of the whole site, glossary and translation memory, edit history, language-specific images, slug translation, WooCommerce support, dynamic content added by JavaScript.

The admin interface is in English and ships with a Russian translation.

**Developer hooks**

* Filters: lingocanvas_nav_menu_trigger_title, lingocanvas_nav_menu_dropdown_classes, lingocanvas_visibility_bypass, lingocanvas_sitemap_post_ids, lingocanvas_translation_provider.
* Action: lingocanvas_purge_page_cache.

== External services ==

This plugin can connect to external AI translation services. The features are optional and are off until an administrator provides an API key or connects the paid LingoCanvas Cloud service.

**OpenAI API (or an OpenAI-compatible endpoint set by the site administrator)**

What it is used for: producing machine-translation suggestions and listing the models available for the configured API key.

When data is sent:

* When an administrator clicks "Translate with AI" next to a translation field, or "Translate the whole content with AI" for a post or page, in the plugin's admin screens. For a bulk translation the request is repeated for each chunk of text. A failed request is retried once.
* When an administrator opens the plugin's "Languages" settings page and a key is already saved, or clicks "Refresh list", the plugin asks the service for its list of models (a GET request to the /models endpoint of the configured API address). The result is cached for 12 hours.

No request is made without an API key and an action by a logged-in administrator. Visitors and search engine crawlers cannot trigger requests. The plugin does not translate anything in the background.

What data is sent:

* For translation: the text segments being translated (title, excerpt, post content, strings from the page), which may include content of drafts and private posts; the source and target language; and the model id. The API key is sent as a Bearer token in the Authorization header.
* For the model list: only the API key as a Bearer token.
* No visitor data is sent.

Service links (OpenAI):

* Terms of use: https://openai.com/policies/terms-of-use/
* Privacy policy: https://openai.com/policies/privacy-policy/
* API data usage policies: https://openai.com/policies/api-data-usage-policies/

If the administrator sets a custom API address (a proxy, OpenRouter or a self-hosted gateway), the data is sent to that service instead of OpenAI, and the terms and privacy policy of that service apply.

**LingoCanvas Cloud (cloud.centerai.eu, operated by CenterAI)**

What it is used for: an optional paid AI translation service. The administrator can choose it instead of their own OpenAI key in "Multilingual → Languages → AI translation provider". The translation itself is performed by DeepSeek (its servers are in China) through the LingoCanvas Cloud service. Payments are handled by Stripe.

When data is sent (only after the administrator ticks the consent box "I agree to send texts for translation to LingoCanvas Cloud", subscribes or connects the site, and selects LingoCanvas Cloud as the provider):

* When an administrator clicks "Translate with AI" next to a translation field, or "Translate the whole content with AI" for a post or page. For a bulk translation the request is repeated for each chunk of text.
* When an administrator subscribes (sign-up), checks the payment, buys an extra pack of characters or opens "Manage subscription" on the plugin's settings page.
* When an administrator opens the settings page of a connected site (or clicks "Refresh") and the consent box is ticked, the plugin asks the service for the account status (plan, remaining characters, renewal date). The result is cached for 60 seconds. While the payment is waiting for confirmation, the settings page checks it every 5 seconds (also only with the consent box ticked).

No request is made without the administrator's consent and action: without the ticked consent box the plugin makes no requests to LingoCanvas Cloud at all (no status, no payment check, no billing buttons). Visitors and search engine crawlers cannot trigger requests. The plugin does not translate anything in the background. The free features (manual translation, your own API key) work without registering with LingoCanvas Cloud.

What data is sent:

* For translation: the text segments being translated (title, excerpt, post content, strings from the page), which may include content of drafts and private posts; the source and target language; the language name; the translation glossary terms. The site token is sent as a Bearer token in the Authorization header.
* At sign-up: the site name (the host name of your site), the optional e-mail address you enter, and a hash of a random one-time secret that the plugin keeps on your server. The site token itself is issued by the service after payment and stored in the site database; it is never shown on any page.
* For the account status: only the site token.
* No visitor data is sent.

Payments are handled by Stripe: the administrator is redirected to checkout.stripe.com (payment) and billing.stripe.com (manage the subscription). The plugin does not see or store card data.

Service links:

* LingoCanvas Cloud Terms: https://centerai.eu/lingocanvas/terms/
* LingoCanvas Cloud Privacy Policy: https://centerai.eu/lingocanvas/privacy/
* Stripe Terms: https://stripe.com/legal/ssa
* Stripe Privacy Policy: https://stripe.com/privacy

**WordPress.org language packs**

When an administrator adds a language or clicks "Download language pack", the plugin uses WordPress core functions (wp_get_available_translations() and wp_download_language_pack()) to get the list of available languages and the translation files from api.wordpress.org. These are standard WordPress core requests; no site content is sent by the plugin. See https://wordpress.org/about/privacy/ for the WordPress.org privacy policy.

== Installation ==

1. In the admin go to Plugins > Add New > Upload Plugin, choose the plugin ZIP, click Install Now and then Activate. Alternatively, copy the plugin folder to wp-content/plugins/.
2. On activation the plugin creates its database tables and default settings (the site's default language plus English at /en/).
3. Go to Settings > Permalinks and choose any structure except "Plain" (the plugin shows a notice if "Plain" is selected, because language URLs do not work with it). Click Save to rebuild the URL rules.
4. Open Multilingual > Languages. Add your languages with the "Add language" field, check the "WordPress locale" of each (for example en_US or en_GB) and click "Download language pack" where it says there is no pack. Save.
5. Add the language switcher: Appearance > Menus > "Languages" block, or the [lingocanvas_language_switcher] shortcode, or the "Language switcher" widget.
6. Open a page with a language prefix, for example /en/. Menus, comment forms and buttons should already be shown in that language from the official language pack.
7. Translate your own content: Multilingual > Visual editor, choose a language and a page, click texts on the page and type translations.

**Optional: configuring the OpenAI key**

AI translation is optional. Without a key the plugin works as usual and the AI buttons are not shown. For each of the key (OPENAI_API_KEY), model (OPENAI_MODEL) and API address (OPENAI_BASE_URL), the sources are checked in this order and the first one found is used:

1. Constants in wp-config.php (recommended, survives plugin updates):

    define( 'LINGOCANVAS_OPENAI_API_KEY', 'your-key' );
    define( 'LINGOCANVAS_OPENAI_MODEL', 'model-id' );
    define( 'LINGOCANVAS_OPENAI_BASE_URL', 'https://api.openai.com/v1' );

2. The fields on the Multilingual > Languages screen (stored in the site database; the key is never shown again after saving, only its last characters).
3. The file wp-content/lingocanvas.env.php. Lines are KEY=VALUE (OPENAI_API_KEY, OPENAI_MODEL, OPENAI_BASE_URL) and the first line must be `<?php exit; ?>`. Without that line the file is not read.
4. The file lingocanvas.env one level above the WordPress root (the same KEY=VALUE format, no guard line).

5. A .env file in the plugin folder and real server environment variables. A .env file inside the plugin folder is deleted when the plugin is replaced through "Upload Plugin".

Security notes: never put an ordinary .env or lingocanvas.env file inside wp-content or any other folder served by your web server, because a file with the key could be downloaded by its URL. The protection described above (the exit line) is only for lingocanvas.env.php, and you should not rely on .htaccess-based protection of dot files: it is not applied on nginx. Constants in wp-config.php are the safest option.

== Frequently Asked Questions ==

= Does the plugin create a copy of every post for each language? =

No. There is one post and N language URLs. Translations are stored in separate tables (lingocanvas_sources, lingocanvas_translations, lingocanvas_occurrences and lingocanvas_routes, with your database prefix) and are substituted when the page is displayed. The content of the post itself is never changed by translation.

= Is anything sent to an external service automatically? =

No. Content is sent to OpenAI (or the compatible service you configured) or, if you gave consent and selected it, to LingoCanvas Cloud, only when an administrator clicks an AI translation button. The model list is requested when an administrator opens the settings page with a saved key, and the Cloud account status when the settings page of a connected site is opened. Without an API key or Cloud connection nothing is sent. See "External services".

= Where are translations stored? =

In the WordPress database, in the plugin's own tables. Uploaded flag images are stored in wp-content/uploads/lingocanvas-flags/. Per-language visibility flags are post meta keys named _lingocanvas_visible_<language>. The OpenAI key, if you enter it in the admin, is stored in the plugin's settings option in the database.

= What happens when I delete the plugin? =

By default your translations are kept, so you can reinstall the plugin and find everything in place. The tables, settings, the plugin's post meta and uploaded flags are removed only if you tick "Delete all translations and tables when the plugin is deleted" in the settings before deleting the plugin.

= Do I have to translate the theme and WordPress interface texts by hand? =

Usually not. On additional languages the plugin switches the WordPress locale, so core, the theme and plugins use their own official translation files. Download the language pack for the language on the Languages screen. What is left untranslated can be translated on the "Interface" tab of the string translation screen.

= Will strings be collected from visitors' page views? =

By default new strings are collected only when an administrator opens a page in an additional language. Collecting from visitors is a separate setting that is off by default.

= Does it work with caching plugins? =

When a translation is saved, deleted or machine-translated, the plugin tries to flush the page cache of WP Rocket, WP Fastest Cache, WP Super Cache, W3 Total Cache, LiteSpeed Cache, SiteGround Optimizer and WP Engine. This is implemented by checking each plugin's documented functions and has not been tested on live installations of all of them. For other caches (a CDN, Varnish, a reverse proxy) use the lingocanvas_purge_page_cache action. If a page still shows the old text, flush the cache once by hand.

= Does it work on WordPress Multisite? =

Multisite has not been fully tested. The plugin is activated and configured per site, and its data is removed per site on uninstall.

= Which PHP version is needed? =

PHP 8.1 or newer. On PHP 8.4+ the built-in HTML5 parser is used; on 8.1 to 8.3 the plugin falls back to libxml, which works but is less accurate on non-standard markup.

= Which output is translated? =

LingoCanvas translates every page that WordPress renders through a theme template: content, menus, widgets, header, footer and SEO tags (title, meta description, canonical, hreflang). Output that another plugin or theme prints and ends itself before WordPress loads the template (for example a custom page printed on `template_redirect` followed by `exit`) bypasses the template and is not translated.

== Changelog ==

= 0.5.3 =
* LingoCanvas Cloud: the saved payment claim is no longer dropped when your site's local clock passes its expiry while a response was lost; it is kept until the service gives a final answer or the 24-hour recovery window plus a safety margin ends. The payment-check lock now has an owner and is taken over atomically, so two checks can never overwrite each other's key. The receipt state is saved before the key, and a "key already issued" answer after a failed receipt confirmation is reported with the claim ID instead of being treated as success. Expired canceled-payment records are now actually removed.

= 0.5.2 =
* LingoCanvas Cloud: a lost response during sign-up no longer loses your connection key. The service re-issues the key for 24 hours until the plugin confirms it received it, and the plugin confirms receipt, retries the confirmation later if it fails, and never runs two payment checks at once.

= 0.5.1 =
* LingoCanvas Cloud hardening: a repeated click on the same translation reuses the saved request ID, so a lost response is never paid twice; a long "busy" answer from the service stops the request with a clear message instead of waiting; a lost connection key is reported with the claim ID and the pending payment is kept; double sign-up clicks lead to the same payment; a canceled payment can still be checked with "Already paid? Check again"; without the consent box the plugin makes no Cloud requests at all (no status, no payment polling, no billing buttons).

= 0.5.0 =
* New optional AI translation provider: LingoCanvas Cloud (paid service by CenterAI, translation by DeepSeek). Subscribe, check the payment, buy extra characters, manage or disconnect the subscription on the settings page. Needs the administrator's consent; your own OpenAI key keeps working and stays the default.
* The "could not get a translation" message no longer names OpenAI and shows the provider's own explanation.

= 0.4.5 =
* String translation no longer collects reading times such as "13 minutes" produced by SEO plugins' own translations, nor image file names used as alt text or captions.

= 0.4.4 =
* Page translation now uses the WordPress 6.9+ template enhancement output buffer, with a template wrapper fallback on WordPress 6.4 to 6.8.
* Admin output is escaped with wp_kses allowlists.

= 0.4.3 =
* First public release.
* One post, a URL per language: visual translation editor, string translation screen, language switcher (menu item, shortcode, widget) and per-language visibility of posts.
* SEO: hreflang, canonical, a multilingual sitemap and translation of SEO markup (meta description, Open Graph, Twitter Card, JSON-LD).
* Interface strings served from the official WordPress.org language packs; optional AI translation with OpenAI, only on an administrator's click.
