<?php
/**
 * Перевод готового HTML.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rendering;

use WpMlp\I18n\GettextRegistry;
use WpMlp\Settings\Language;
use WpMlp\Settings\Settings;
use WpMlp\Storage\OccurrenceRepository;
use WpMlp\Storage\SourceRepository;
use WpMlp\Storage\TranslationCache;
use WpMlp\Support\Hash;

/**
 * Разбирает ответ страницы, подставляет переводы и собирает HTML обратно.
 *
 * Порядок работы повторяет ТЗ 7.2: разбор → извлечение → пакетный поиск
 * переводов → подстановка → сериализация. Запись новых строк в БД отложена
 * на `shutdown`, чтобы не удлинять время ответа посетителю.
 */
final class Translator {

	/**
	 * Объект, к которому привязываются места использования строк.
	 */
	private const OBJECT_TYPE_URL = 'url';

	/**
	 * Как часто обновлять «строка ещё встречается» для одной и той же страницы.
	 */
	private const TOUCH_INTERVAL = HOUR_IN_SECONDS;

	/**
	 * @param Extractor            $extractor   Извлечение строк из DOM.
	 * @param SourceRepository     $sources     Исходные строки.
	 * @param OccurrenceRepository $occurrences Места использования.
	 * @param TranslationCache     $cache       Объектный кэш переводов.
	 * @param Settings             $settings    Настройки плагина.
	 * @param EditorContext        $editor      Режим визуального редактора.
	 * @param EditorMarkers        $markers     Маркеры узлов для редактора.
	 * @param GettextRegistry      $gettext     Контур строк темы и плагинов.
	 * @param list<DocumentFilter> $filters     Пост-обработчики готового DOM.
	 */
	public function __construct(
		private readonly Extractor $extractor,
		private readonly SourceRepository $sources,
		private readonly OccurrenceRepository $occurrences,
		private readonly TranslationCache $cache,
		private readonly Settings $settings,
		private readonly EditorContext $editor,
		private readonly EditorMarkers $markers,
		private readonly GettextRegistry $gettext,
		private readonly array $filters = array()
	) {
	}

	/**
	 * Переводит готовый HTML. Возвращает null, если разобрать не удалось.
	 *
	 * @param string   $html   Ответ страницы на исходном языке.
	 * @param Language $target Язык, на который переводим.
	 */
	public function translateHtml( string $html, Language $target ): ?string {
		$document = HtmlDocument::parse( $html );

		if ( null === $document ) {
			return null;
		}

		$sourceLocale = $this->settings->defaultLanguage()->locale;
		$editorMode   = $this->editor->isActive();
		$segments     = $this->extractor->extract(
			$document,
			$sourceLocale,
			$this->sources->blockHashes(),
			$editorMode,
			/*
			 * Строки, уже обслуженные gettext-контуром на этом же запросе,
			 * в словарь второй раз не заводим: иначе одна и та же фраза
			 * («Ответить») оказалась бы и в «Интерфейсе», и в «Контенте», и
			 * переводить её пришлось бы дважды, в двух разных экранах.
			 */
			$this->gettext->servedTexts()
		);

		if ( array() === $segments ) {
			$this->runFilters( $document, $target );

			return $document->html();
		}

		// Одинаковые строки на странице ищем один раз (ТЗ 4.5).
		$unique = SegmentDeduplicator::deduplicate( $segments );

		$found = $editorMode
			? $this->sources->lookup( array_keys( $unique ), $target->locale )
			: $this->lookup( array_keys( $unique ), $target->locale );

		if ( $editorMode ) {
			// В редакторе у каждой строки должен быть идентификатор прямо
			// сейчас: без него не на что вешать маркер и нечего сохранять.
			$found += $this->registerNow( array_diff_key( $unique, $found ), $sourceLocale );
		}

		foreach ( $segments as $segment ) {
			$translation = $found[ $segment->uniqHash ]['text'] ?? null;

			if ( is_string( $translation ) && '' !== $translation ) {
				$segment->apply( $translation, $document );
			}
		}

		$this->runFilters( $document, $target );

		if ( $editorMode ) {
			$ids = array();

			foreach ( $found as $hash => $row ) {
				$ids[ $hash ] = $row['id'];
			}

			$this->markers->mark( $segments, $ids, $document );
			$this->markers->markGettext( $document, $this->gettext->servedTexts() );
		} else {
			$this->scheduleDiscovery( $unique, $found, $sourceLocale );
		}

		return $document->html();
	}

	/**
	 * Записывает новые строки немедленно и возвращает их идентификаторы.
	 *
	 * Обычный показ страницы откладывает запись на `shutdown`, но редактору
	 * идентификаторы нужны до сериализации ответа.
	 *
	 * @param array<string, Segment> $missing Новые строки.
	 * @param string                 $locale  Исходный язык.
	 * @return array<string, array{id: int, text: ?string, status: ?string}>
	 */
	private function registerNow( array $missing, string $locale ): array {
		if ( array() === $missing ) {
			return array();
		}

		$this->sources->insertMissing( $this->sourceRows( $missing, $locale ) );

		$rows = array();

		foreach ( $this->sources->idsByHashes( array_keys( $missing ) ) as $hash => $id ) {
			$rows[ $hash ] = array(
				'id'     => $id,
				'text'   => null,
				'status' => null,
			);
		}

		return $rows;
	}

	/**
	 * Готовит строки для вставки в таблицу источников.
	 *
	 * @param array<string, Segment> $missing Новые строки.
	 * @param string                 $locale  Исходный язык.
	 * @return list<array{locale: string, kind: string, text: string, source_hash: string, context_hash: string, uniq_hash: string}>
	 */
	private function sourceRows( array $missing, string $locale ): array {
		$contextHash = Hash::of( '' );
		$rows        = array();

		foreach ( $missing as $hash => $segment ) {
			$rows[] = array(
				'locale'       => $locale,
				'kind'         => $segment->kind,
				'text'         => $segment->text,
				'source_hash'  => $segment->sourceHash,
				'context_hash' => $contextHash,
				'uniq_hash'    => $hash,
			);
		}

		return $rows;
	}

	/**
	 * Даёт пост-обработчикам доработать готовый DOM.
	 *
	 * @param HtmlDocument $document Разобранный документ.
	 * @param Language     $target   Язык страницы.
	 */
	private function runFilters( HtmlDocument $document, Language $target ): void {
		foreach ( $this->filters as $filter ) {
			$filter->apply( $document, $target );
		}
	}

	/**
	 * Пакетный поиск переводов: сначала объектный кэш, затем один SQL-запрос.
	 *
	 * @param list<string> $hashes Hex-хеши строк страницы.
	 * @param string       $locale Целевой язык.
	 * @return array<string, array{id: int, text: ?string, status: ?string}>
	 */
	private function lookup( array $hashes, string $locale ): array {
		$cached = $this->cache->getMany( $hashes, $locale );

		$found = array_filter( $cached['hits'], static fn( $row ): bool => null !== $row );

		if ( array() === $cached['misses'] ) {
			return $found;
		}

		$fresh = $this->sources->lookup( $cached['misses'], $locale );

		$this->cache->setMany( $fresh, $cached['misses'], $locale );

		return $found + $fresh;
	}

	/**
	 * Откладывает запись новых строк на конец запроса (ТЗ 4.6).
	 *
	 * @param array<string, Segment>                                        $unique Уникальные строки страницы.
	 * @param array<string, array{id: int, text: ?string, status: ?string}> $found  Уже известные строки.
	 * @param string                                                        $locale Исходный язык.
	 */
	private function scheduleDiscovery( array $unique, array $found, string $locale ): void {
		if ( ! $this->settings->isDiscoveryEnabled() || $this->isCrawler() ) {
			return;
		}

		if ( array() === $unique ) {
			return;
		}

		add_action(
			'shutdown',
			function () use ( $unique, $found, $locale ): void {
				$this->persist( $unique, $found, $locale );
			},
			100
		);
	}

	/**
	 * Пишет найденное в БД. Выполняется уже после отправки ответа.
	 *
	 * @param array<string, Segment>                                        $unique Все уникальные строки страницы.
	 * @param array<string, array{id: int, text: ?string, status: ?string}> $found  Уже известные строки.
	 * @param string                                                        $locale Исходный язык.
	 */
	private function persist( array $unique, array $found, string $locale ): void {
		$urlHash = Hash::of( $this->currentUrlKey() );
		$missing = array_diff_key( $unique, $found );

		$ids = array();

		foreach ( $found as $hash => $row ) {
			$ids[ $hash ] = $row['id'];
		}

		if ( array() !== $missing ) {
			$this->sources->insertMissing( $this->sourceRows( $missing, $locale ) );

			// Идентификаторы известны только после вставки — они нужны occurrences.
			$ids += $this->sources->idsByHashes( array_keys( $missing ) );
		}

		/*
		 * Раз в час на страницу переписываем места использования всех её строк,
		 * а не только новых. Иначе строка меню, впервые найденная на одной
		 * записи, навсегда осталась бы «строкой этой записи»: второй записи
		 * о ней просто не появилось бы, и отличить общий элемент сайта от
		 * содержимого конкретной страницы было бы нечем.
		 */
		$refresh = false === get_transient( 'mlp_touched_' . $urlHash );
		$record  = $refresh ? $unique : $missing;

		if ( array() !== $record ) {
			$this->occurrences->insertMany( $this->occurrenceRows( $record, $ids, $urlHash ) );
		}

		if ( $refresh ) {
			$this->sources->touch( array_values( $ids ) );
			set_transient( 'mlp_touched_' . $urlHash, 1, self::TOUCH_INTERVAL );
		}
	}

	/**
	 * Готовит записи о местах использования строк.
	 *
	 * @param array<string, Segment> $segments Строки, которые нужно отметить.
	 * @param array<string, int>     $ids      Идентификаторы строк по hex uniq_hash.
	 * @param string                 $urlHash  Хеш адреса страницы.
	 * @return list<array{source_id: int, object_type: string, object_id: ?int, url_hash: ?string, attribute_name: ?string, uniq_hash: string}>
	 */
	private function occurrenceRows( array $segments, array $ids, string $urlHash ): array {
		$objectId = $this->currentObjectId();
		$rows     = array();

		foreach ( $segments as $hash => $segment ) {
			if ( ! isset( $ids[ $hash ] ) ) {
				continue;
			}

			$rows[] = array(
				'source_id'      => $ids[ $hash ],
				'object_type'    => self::OBJECT_TYPE_URL,
				'object_id'      => $objectId,
				'url_hash'       => $urlHash,
				'attribute_name' => $segment->attribute,
				'uniq_hash'      => Hash::ofParts(
					array( $ids[ $hash ], self::OBJECT_TYPE_URL, $urlHash, (string) $segment->attribute )
				),
			);
		}

		return $rows;
	}

	/**
	 * Идентификатор текущей записи, если страница — запись WordPress.
	 */
	private function currentObjectId(): ?int {
		if ( ! did_action( 'wp' ) ) {
			return null;
		}

		$id = get_queried_object_id();

		return $id > 0 ? (int) $id : null;
	}

	/**
	 * Путь текущей страницы — им помечаются места использования строк.
	 */
	private function currentUrlKey(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- значение только хешируется.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/';

		return (string) ( wp_parse_url( $uri, PHP_URL_PATH ) ?? '/' );
	}

	/**
	 * Похож ли посетитель на поискового робота.
	 *
	 * Роботы обходят весь сайт: если разрешить им пополнять словарь, таблицы
	 * вырастут на строках, которых живой человек никогда не увидит (ТЗ 4.6).
	 */
	private function isCrawler(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- значение только сопоставляется с шаблоном.
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		if ( '' === $agent ) {
			return false;
		}

		return 1 === preg_match( '/(bot|crawler|crawling|spider|slurp|facebookexternalhit|preview)/', $agent );
	}
}
