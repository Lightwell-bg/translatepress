<?php
/**
 * Извлечение переводимых строк из записи целиком: заголовок, excerpt, тело.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rendering;

/**
 * Готовит запись к переводу «Перевести весь материал с ИИ» одним заходом.
 *
 * Работает НЕ с готовой HTML-страницей (как {@see Translator} для обычного
 * фронтенда), а с сырыми полями записи (`post_title`, `post_excerpt`,
 * `post_content`) — это принципиально: готовая страница уже прошла через
 * `the_content` и потеряла комментарии Gutenberg (`<!-- wp:paragraph -->` и
 * подобные ядро вырезает при рендере блоков) — их негде было бы взять на
 * этом пути. Здесь блоки не рендерятся и шорткоды не разворачиваются: три
 * поля разбираются как есть через тот же {@see Extractor}, что и обычная
 * страница, — значит те же правила «что вообще переводимо», тот же способ
 * посчитать `uniq_hash`, и один и тот же словарь строк с остальным плагином.
 *
 * Четвёртый источник строк — alt-текст миниатюры записи. Он лежит не в
 * полях записи, а в мета вложения (`_wp_attachment_image_alt`), поэтому
 * приходит готовой строкой снаружи: сам класс остаётся свободным от
 * вызовов WordPress. На странице миниатюра выводится как `<img alt="…">`,
 * и обычный {@see Extractor} находит этот alt как атрибутный сегмент —
 * здесь строка проходит через тот же путь, чтобы `uniq_hash` совпал.
 */
final class PostContentExtractor {

	/**
	 * @param Extractor $extractor Извлечение строк из DOM.
	 */
	public function __construct( private readonly Extractor $extractor ) {
	}

	/**
	 * Извлекает все переводимые сегменты записи.
	 *
	 * @param object              $post        Запись: нужны только post_title,
	 *                                         post_excerpt, post_content (WP_Post
	 *                                         подходит, но тип не сужен до него —
	 *                                         так класс проверяется без WordPress).
	 * @param string              $locale      Исходный язык сайта.
	 * @param array<string, true> $blockHashes Хеши уже заведённых translation blocks.
	 * @param string              $thumbnailAlt Alt-текст миниатюры записи (пусто — нет миниатюры или alt).
	 */
	public function extract( object $post, string $locale = 'ru', array $blockHashes = array(), string $thumbnailAlt = '' ): PostExtractionResult {
		$segments = array();

		list( $titleSegments, $titleDocument ) = $this->extractField(
			PostSegment::FIELD_TITLE,
			'h1',
			(string) $post->post_title,
			$locale,
			$blockHashes
		);
		list( $excerptSegments, $excerptDocument ) = $this->extractField(
			PostSegment::FIELD_EXCERPT,
			'p',
			(string) $post->post_excerpt,
			$locale,
			$blockHashes
		);
		list( $contentSegments, $contentDocument ) = $this->extractContent(
			(string) $post->post_content,
			$locale,
			$blockHashes
		);

		$thumbnailSegments = $this->extractThumbnailAlt( $thumbnailAlt, $locale );

		foreach ( array( $titleSegments, $excerptSegments, $contentSegments, $thumbnailSegments ) as $group ) {
			foreach ( $group as $segment ) {
				$segments[] = $segment;
			}
		}

		return new PostExtractionResult( $segments, $titleDocument, $excerptDocument, $contentDocument );
	}

	/**
	 * Заголовок и excerpt — обычно голый текст, но иногда содержат инлайновую
	 * разметку (акцент, дробь и т. п.), поэтому разбираются тем же
	 * DOM-путём, что и содержимое, а не берутся строкой напрямую: так
	 * встроенные теги переживают перевод, а не превращаются в текст.
	 *
	 * @param string              $field       PostSegment::FIELD_*.
	 * @param string              $wrapper     Тег-обёртка для разбора фрагмента.
	 * @param string              $raw         Сырое значение поля.
	 * @param string              $locale      Исходный язык.
	 * @param array<string, true> $blockHashes Хеши translation blocks.
	 * @return array{0: list<PostSegment>, 1: ?HtmlDocument}
	 */
	private function extractField( string $field, string $wrapper, string $raw, string $locale, array $blockHashes ): array {
		if ( '' === trim( $raw ) ) {
			return array( array(), null );
		}

		$document = HtmlDocument::parse( "<!DOCTYPE html><html><body><{$wrapper}>{$raw}</{$wrapper}></body></html>" );

		if ( null === $document ) {
			return array( array(), null );
		}

		$segments = array_map(
			static fn( Segment $segment ): PostSegment => new PostSegment( $field, $segment ),
			self::withoutLinkTargets( $this->extractor->extract( $document, $locale, $blockHashes ) )
		);

		return array( $segments, $document );
	}

	/**
	 * Содержимое записи.
	 *
	 * @param string              $raw         `post_content` как есть.
	 * @param string              $locale      Исходный язык.
	 * @param array<string, true> $blockHashes Хеши translation blocks.
	 * @return array{0: list<PostSegment>, 1: ?HtmlDocument}
	 */
	private function extractContent( string $raw, string $locale, array $blockHashes ): array {
		if ( '' === trim( $raw ) ) {
			return array( array(), null );
		}

		$document = HtmlDocument::parse( '<!DOCTYPE html><html><body>' . $this->prepareContent( $raw ) . '</body></html>' );

		if ( null === $document ) {
			return array( array(), null );
		}

		$segments = array_map(
			static fn( Segment $segment ): PostSegment => new PostSegment( PostSegment::FIELD_CONTENT, $segment ),
			self::withoutLinkTargets( $this->extractor->extract( $document, $locale, $blockHashes ) )
		);

		return array( $segments, $document );
	}

	/**
	 * Alt-текст миниатюры записи.
	 *
	 * Ядро WordPress при выводе миниатюры отдаёт alt как
	 * `trim( strip_tags( $alt ) )` — здесь то же самое, затем alt оборачивается
	 * в `<img>` и разбирается общим {@see Extractor}: нормализация, отсев
	 * непереводимого и `uniq_hash` те же, что у страницы на фронтенде.
	 *
	 * @param string $alt    Значение `_wp_attachment_image_alt`.
	 * @param string $locale Исходный язык.
	 * @return list<PostSegment>
	 */
	private function extractThumbnailAlt( string $alt, string $locale ): array {
		$alt = trim( strip_tags( $alt ) );

		if ( '' === $alt ) {
			return array();
		}

		// double_encode = false: как esc_attr() в WordPress, уже закодированные сущности не кодируются повторно.
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><body><img alt="' . htmlspecialchars( $alt, ENT_QUOTES, 'UTF-8', false ) . '"></body></html>'
		);

		if ( null === $document ) {
			return array();
		}

		$segments = array_filter(
			$this->extractor->extract( $document, $locale ),
			static fn( Segment $segment ): bool =>
				Segment::KIND_ATTRIBUTE === $segment->kind && 'alt' === $segment->attribute
		);

		return array_values(
			array_map(
				static fn( Segment $segment ): PostSegment => new PostSegment( PostSegment::FIELD_THUMBNAIL_ALT, $segment ),
				$segments
			)
		);
	}

	/**
	 * Убирает адреса ссылок из набора строк записи. Чистая функция.
	 *
	 * Массовый перевод записи — про её текст: заголовок, анонс, абзацы. Он
	 * показывает список на проверку и отправляет его языковой модели, а
	 * адресу там делать нечего — модель его переписывает, и ссылка молча
	 * ведёт в никуда.
	 *
	 * Возможность править адрес при этом не теряется: страница, отрисованная
	 * в визуальном редакторе, отдаёт ту же самую ссылку тем же самым видом
	 * строки, и правится она там же или во вкладке «Ссылки». Строка словаря
	 * получается одна и та же — идентичность считается от самого адреса, а
	 * не от того, каким путём его нашли.
	 *
	 * @param list<Segment> $segments Найденные строки.
	 * @return list<Segment>
	 */
	private static function withoutLinkTargets( array $segments ): array {
		return array_values(
			array_filter(
				$segments,
				static fn( Segment $segment ): bool =>
					Segment::KIND_ATTRIBUTE !== $segment->kind || 'href' !== $segment->attribute
			)
		);
	}

	/**
	 * Готовит `post_content` к разбору DOM-парсером.
	 *
	 * Gutenberg-запись уже хранит валидный HTML в базе — оборачивать её
	 * ничем не нужно, и нельзя: `wpautop()` расставляет `<p>` по пустым
	 * строкам, а между блоками пустые строки как раз есть, значит она
	 * вставила бы лишние абзацы вокруг комментариев `<!-- wp:... -->`.
	 * А вот классический редактор в базе часто хранит голый текст без тегов
	 * вовсе — ровно то же самое `post_content` на обычной странице проходит
	 * через `wpautop()` внутри фильтра `the_content` перед показом. Не
	 * применить её здесь значило бы разобрать текст не так, как он вообще
	 * когда-либо показывается посетителю.
	 *
	 * @param string $raw `post_content` как есть.
	 */
	private function prepareContent( string $raw ): string {
		return has_blocks( $raw ) ? $raw : wpautop( $raw );
	}
}
