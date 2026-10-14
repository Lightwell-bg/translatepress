<?php
/**
 * Справка во вкладке «Помощь» на экранах плагина.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Admin;

use WpMlp\Support\Hookable;

defined( 'ABSPATH' ) || exit;

/**
 * Подсказки в штатной панели «Помощь» WordPress — той, что раскрывается
 * кнопкой в правом верхнем углу экрана.
 *
 * Полное руководство лежит файлом (`docs/user-guide.md`), но до файла на
 * сервере никто не доберётся в тот момент, когда вопрос возник. А
 * возникает он всегда на конкретном экране и про конкретную вещь:
 * «почему список пуст», «почему половина формы на английском». Поэтому
 * здесь не копия руководства, а короткие ответы ровно на те вопросы,
 * которые задают на ЭТОМ экране, плюс раздел «если что-то не так» с
 * симптомами, а не с описанием устройства.
 *
 * Экран определяется по параметру `page` в адресе, а не по `$screen->id`:
 * идентификатор экрана WordPress собирает из переведённого названия
 * родительского пункта меню, и на сайте с другим языком админки он
 * оказывается другим.
 */
final class HelpTabs implements Hookable {

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'current_screen', array( $this, 'addTabs' ) );
	}

	/**
	 * Вешает вкладки на экраны плагина.
	 */
	public function addTabs(): void {
		$screen = get_current_screen();

		if ( null === $screen ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только определение текущего экрана.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) : '';

		$tabs = $this->tabsFor( $page );

		if ( array() === $tabs ) {
			return;
		}

		foreach ( $tabs as $id => $tab ) {
			$screen->add_help_tab(
				array(
					'id'      => 'mlp-' . $id,
					'title'   => $tab['title'],
					'content' => $tab['content'],
				)
			);
		}

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'Full guide', 'lingocanvas' ) . '</strong></p>'
			. '<p>' . esc_html__( 'Located in the plugin folder: docs/user-guide.md — installation, setup and translation from start to finish.', 'lingocanvas' ) . '</p>'
		);
	}

	/**
	 * Вкладки для конкретного экрана.
	 *
	 * @param string $page Значение параметра `page` в адресе.
	 * @return array<string, array{title: string, content: string}>
	 */
	private function tabsFor( string $page ): array {
		if ( SettingsPage::MENU_SLUG === $page ) {
			return $this->settingsTabs();
		}

		if ( StringTranslationPage::MENU_SLUG === $page ) {
			return $this->stringsTabs();
		}

		if ( EditorPage::MENU_SLUG === $page ) {
			return $this->editorTabs();
		}

		return array();
	}

	/**
	 * Экран «Языки».
	 *
	 * @return array<string, array{title: string, content: string}>
	 */
	private function settingsTabs(): array {
		return array(
			'setup' => array(
				'title'   => __( 'Getting started', 'lingocanvas' ),
				'content' => '<p>' . esc_html__( 'Fill in a row for each language, choose the default language — the one you write in — and save.', 'lingocanvas' ) . '</p>'
					. '<p><strong>' . esc_html__( 'Be sure to check two things:', 'lingocanvas' ) . '</strong></p>'
					. '<ol>'
					. '<li>' . esc_html__( '"WordPress locale" is NOT the language code. The code (bg) goes into the page address; the locale (bg_BG) is the name under which WordPress stores translations of its own interface. For English it can be either en_US or en_GB.', 'lingocanvas' ) . '</li>'
					. '<li>' . esc_html__( 'The language pack. If it says "no pack", click "Download". Without it WordPress shows the English originals of all labels, and it looks as if the plugin does not work.', 'lingocanvas' ) . '</li>'
					. '</ol>'
					. '<p>' . esc_html__( 'To check: open a site page with the language prefix. The menu and comment form should be translated on their own, without any effort on your part.', 'lingocanvas' ) . '</p>',
			),
			'switcher' => array(
				'title'   => __( 'Language switcher', 'lingocanvas' ),
				'content' => '<p>' . esc_html__( 'Three ways, take your pick:', 'lingocanvas' ) . '</p>'
					. '<ul>'
					. '<li>' . esc_html__( '"Appearance → Menus", the "Languages" box: tick "Dropdown" or "Inline" and add it to the menu. One item is enough — the plugin builds all the languages under it.', 'lingocanvas' ) . '</li>'
					. '<li>' . esc_html__( 'The [mlp_language_switcher] shortcode in the text.', 'lingocanvas' ) . '</li>'
					. '<li>' . esc_html__( 'The "Language switcher" widget.', 'lingocanvas' ) . '</li>'
					. '</ul>'
					. '<p><strong>' . esc_html__( 'Language order', 'lingocanvas' ) . '</strong> '
					. esc_html__( 'follows the order of rows in the table: rearrange them with the arrows in the first column and save. The default language can be moved too — it does not have to be first.', 'lingocanvas' ) . '</p>'
					. '<p><strong>' . esc_html__( 'What the visitor sees', 'lingocanvas' ) . '</strong> '
					. esc_html__( 'is chosen below the table: language name, code (RU, EN), flag only, or flag with code. Flags have their own tab in this help.', 'lingocanvas' ) . '</p>'
					. '<p>' . esc_html__( 'In "Dropdown" mode, the item that opens it shows the current language in the same form, not its own menu label. This way the visitor can see which language the page is in before opening the list.', 'lingocanvas' ) . '</p>',
			),
			'flags' => array(
				'title'   => __( 'Flags', 'lingocanvas' ),
				'content' => '<p>' . esc_html__( 'Upload a flag right here: in the "Flag" column click "Choose file" in the right row and save. The file name does not matter — the plugin saves it under the language code from the first column.', 'lingocanvas' ) . '</p>'
					. '<p>' . sprintf(
						/* translators: %s: link to the flag-icons repository */
						esc_html__( 'Where to get them: %s is a ready-made set of all flags (MIT license). The ones you need are in the flags/4x3 folder; you do not have to download the whole set: open the file you need and save it.', 'lingocanvas' ),
						'<a href="https://github.com/lipis/flag-icons" target="_blank" rel="noopener noreferrer">lipis/flag-icons</a>'
					) . '</p>'
					. '<p><strong>' . esc_html__( 'Which file to take:', 'lingocanvas' ) . '</strong> '
					. esc_html__( 'in flag sets the files are named by country, not by language. Russian and Bulgarian correspond to ru and bg, and English to gb (United Kingdom) or us (United States), your choice. There is no need to rename anything: the plugin saves the file under the right name itself.', 'lingocanvas' ) . '</p>'
					. '<p>' . esc_html__( 'Only SVG up to 64 KB is accepted. The uploaded file is stripped of scripts: an SVG is not just an image, it can contain executable code, and without cleaning it would run on your domain for anyone who opened the file link. This is why WordPress does not allow SVG uploads without a plugin.', 'lingocanvas' ) . '</p>'
					. '<p>' . esc_html__( 'To remove an image, tick "remove" next to it and save.', 'lingocanvas' ) . '</p>'
					. '<p>' . esc_html__( 'No image: the plugin shows the emoji from the same field, and if that is empty too, the language code. The switcher is never left blank.', 'lingocanvas' ) . '</p>'
					. '<p>' . esc_html__( 'Why an image and not an emoji: Windows cannot draw emoji flags at all — instead of a flag the visitor sees two letters. On a MacBook or phone everything looks fine, so the problem is easy to miss.', 'lingocanvas' ) . '</p>'
					. '<p>' . esc_html__( 'In the dropdown the flag is always text: HTML itself does not allow an image inside it.', 'lingocanvas' ) . '</p>',
			),
			'ai' => array(
				'title'   => __( 'AI translation', 'lingocanvas' ),
				'content' => '<p>' . esc_html__( 'Without an OpenAI key there are simply no "Translate with AI" buttons — everything else works as usual.', 'lingocanvas' ) . '</p>'
					. '<p>' . esc_html__( 'The plugin never translates on its own and never contacts OpenAI in the background: only when you click a button. The daily character limit caps spending, and a translation that is already saved is not paid for again.', 'lingocanvas' ) . '</p>',
			),
		);
	}

	/**
	 * Экран «Перевод строк».
	 *
	 * @return array<string, array{title: string, content: string}>
	 */
	private function stringsTabs(): array {
		return array(
			'tabs' => array(
				'title'   => __( 'Which tab', 'lingocanvas' ),
				'content' => '<p><strong>' . esc_html__( 'Rule: if you wrote it yourself, it is "Content"; if you did not, it is "Interface".', 'lingocanvas' ) . '</strong></p>'
					. '<ul>'
					. '<li>' . esc_html__( '"Content" is article text, headings, menu items, image captions. This is yours, and it is the main tab.', 'lingocanvas' ) . '</li>'
					. '<li>' . esc_html__( '"Interface" is "Reply", "Leave a comment", cart buttons. WordPress, the theme and plugins draw these; almost all of it is translated automatically from the language pack.', 'lingocanvas' ) . '</li>'
					. '<li>' . esc_html__( '"SEO/GEO" is what is not visible on the page: the description for Google and the preview when a link is sent in a messenger.', 'lingocanvas' ) . '</li>'
					. '</ul>',
			),
			'search' => array(
				'title'   => __( 'How to search', 'lingocanvas' ),
				'content' => '<p>' . esc_html__( 'Without special characters the search looks for the whole phrase, but not as part of another word: "AI" will find "AI Act" but not "domain" — the letters there match only by chance.', 'lingocanvas' ) . '</p>'
					. '<p><strong>' . esc_html__( 'An asterisk (*) stands for any number of any characters, as in Windows file search or Spotlight on Mac:', 'lingocanvas' ) . '</strong></p>'
					. '<ul>'
					. '<li>' . esc_html__( '*blog/en/ — the string ENDS with "blog/en/".', 'lingocanvas' ) . '</li>'
					. '<li>' . esc_html__( 'blog/en/* — the string STARTS with "blog/en/".', 'lingocanvas' ) . '</li>'
					. '<li>' . esc_html__( '*plugin* — "plugin" anywhere, including inside another word (for example "plugins") — without asterisks it would not be found.', 'lingocanvas' ) . '</li>'
					. '</ul>'
					. '<p>' . esc_html__( 'It searches both the source and the translation. On the "Links" tab this is the most reliable way to find a specific address: without asterisks the search also finds anything that merely contains the phrase somewhere in the middle of the path.', 'lingocanvas' ) . '</p>',
			),
			'interface' => array(
				'title'   => __( 'What to translate and what not', 'lingocanvas' ),
				'content' => '<p>' . esc_html__( 'On the "Interface" tab, look at the "Source" column:', 'lingocanvas' ) . '</p>'
					. '<ul>'
					. '<li>' . esc_html__( '"Theme: …" — translate these first. This is what the visitor sees on every page.', 'lingocanvas' ) . '</li>'
					. '<li>' . esc_html__( 'Shop plugin — translate it if you sell: an English button in the storefront hurts sales.', 'lingocanvas' ) . '</li>'
					. '<li>' . esc_html__( 'Rank Math, Akismet and other admin-side plugins — do not touch them at all. Their labels are visible only to you; a visitor never sees them.', 'lingocanvas' ) . '</li>'
					. '</ul>'
					. '<p>' . esc_html__( 'Your translation always takes priority over the official one. "Reset to official" restores the version from the language pack.', 'lingocanvas' ) . '</p>',
			),
			'trouble' => array(
				'title'   => __( 'If something is wrong', 'lingocanvas' ),
				'content' => '<p><strong>' . esc_html__( 'The text is on the site but not in the list.', 'lingocanvas' ) . '</strong> '
					. esc_html__( 'Most often the page simply has not been opened in this language — the list fills only after a page is displayed. If it has been opened: the text may be in a "Preformatted" block — the plugin deliberately leaves such blocks alone, as they usually hold code. Replace it with a regular paragraph.', 'lingocanvas' ) . '</p>'
					. '<p><strong>' . esc_html__( 'Half of the form is translated and half is not.', 'lingocanvas' ) . '</strong> '
					. esc_html__( 'The WordPress language pack translated what WordPress draws, but the theme replaced some labels with its own, and there is no theme translation for your language. Look for them on the "Interface" tab, source: your theme.', 'lingocanvas' ) . '</p>'
					. '<p><strong>' . esc_html__( 'The list is cluttered.', 'lingocanvas' ) . '</strong> '
					. esc_html__( 'The "Clear untranslated strings" button at the bottom removes discovered strings that nobody translated. It does not touch translated ones, and anything needed will be collected again.', 'lingocanvas' ) . '</p>'
					. '<p><strong>' . esc_html__( 'The translation is saved but the site shows the old text.', 'lingocanvas' ) . '</strong> '
					. esc_html__( 'Clear the page cache — the caching plugin is serving a saved copy.', 'lingocanvas' ) . '</p>',
			),
		);
	}

	/**
	 * Экран «Визуальный редактор».
	 *
	 * @return array<string, array{title: string, content: string}>
	 */
	private function editorTabs(): array {
		return array(
			'howto' => array(
				'title'   => __( 'How to translate', 'lingocanvas' ),
				'content' => '<p>' . esc_html__( 'Choose a language, enter the page address and click "Open". Then there are two ways:', 'lingocanvas' ) . '</p>'
					. '<ul>'
					. '<li>' . esc_html__( '"Translate the whole post with AI" — title, excerpt and full text in one operation. The "Empty segments only" mode does not touch what is already translated.', 'lingocanvas' ) . '</li>'
					. '<li>' . esc_html__( 'Click the text directly in the preview — a field for that string opens on the left.', 'lingocanvas' ) . '</li>'
					. '</ul>'
					. '<p>' . esc_html__( 'Nothing is saved automatically: until you click "Save", the translation remains a draft.', 'lingocanvas' ) . '</p>',
			),
			'quirks' => array(
				'title'   => __( 'If the layout gets in the way', 'lingocanvas' ),
				'content' => '<p><strong>' . esc_html__( 'The theme menu covers the preview.', 'lingocanvas' ) . '</strong> '
					. esc_html__( 'The panel on the left narrows the preview, and the theme switches to its mobile layout with a slide-out menu. Click "Collapse panel" — the preview takes the full width and the menu becomes normal.', 'lingocanvas' ) . '</p>'
					. '<p><strong>' . esc_html__( 'Clicking a string does nothing, and the left side says "interface string".', 'lingocanvas' ) . '</strong> '
					. esc_html__( 'This is a theme or plugin label and cannot be translated here. Go to "String translation" → the "Interface" tab.', 'lingocanvas' ) . '</p>'
					. '<p><strong>' . esc_html__( 'A paragraph is broken up by links and translates badly.', 'lingocanvas' ) . '</strong> '
					. esc_html__( 'Click it and choose "Translate whole paragraph" — it becomes a single string together with its markup.', 'lingocanvas' ) . '</p>',
			),
		);
	}
}
