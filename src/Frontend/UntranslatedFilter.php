<?php
/**
 * Скрытие непереведённых записей из списков.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Frontend;

use WP_Query;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Settings\Settings;
use WpMlp\Storage\OccurrenceRepository;
use WpMlp\Storage\TranslationCache;
use WpMlp\Support\Hookable;

/**
 * На дополнительном языке показывает в списках только переведённые записи.
 *
 * Затрагивает только перечисления — блог, рубрики, метки, архивы, поиск.
 * Отдельная запись остаётся доступной по прямому адресу: посетитель мог
 * прийти на неё из поисковика или по ссылке, и отдавать ему 404 вместо
 * частично переведённой страницы было бы хуже.
 */
final class UntranslatedFilter implements Hookable {

	/**
	 * Группа объектного кэша со списком переведённых записей.
	 */
	private const CACHE_GROUP = 'mlp_translated_posts';

	/**
	 * @param Settings             $settings    Настройки плагина.
	 * @param LanguageResolver     $resolver    Язык текущего запроса.
	 * @param OccurrenceRepository $occurrences Места использования строк.
	 * @param TranslationCache     $cache       Кэш переводов (нужен его номер версии).
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly LanguageResolver $resolver,
		private readonly OccurrenceRepository $occurrences,
		private readonly TranslationCache $cache
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'pre_get_posts', array( $this, 'filterQuery' ) );
	}

	/**
	 * Оставляет в списке только записи с переводом.
	 *
	 * @param WP_Query $query Выполняемый запрос.
	 */
	public function filterQuery( $query ): void {
		if ( ! $query instanceof WP_Query || ! $this->shouldFilter( $query ) ) {
			return;
		}

		$ids = $this->translatedIds();

		/*
		 * Пустой post__in WordPress игнорирует, поэтому при полном отсутствии
		 * переводов подставляем несуществующий идентификатор — иначе вместо
		 * пустого списка показался бы весь непереведённый блог.
		 */
		$query->set( 'post__in', array() !== $ids ? $ids : array( 0 ) );
	}

	/**
	 * Нужно ли вмешиваться в этот запрос.
	 *
	 * @param WP_Query $query Выполняемый запрос.
	 */
	private function shouldFilter( WP_Query $query ): bool {
		if ( is_admin() || ! $query->is_main_query() ) {
			return false;
		}

		if ( ! $this->settings->hidesUntranslatedPosts() || $this->resolver->isDefault() ) {
			return false;
		}

		// Только перечисления: у одиночной записи скрывать нечего.
		return $query->is_home() || $query->is_archive() || $query->is_search() || $query->is_feed();
	}

	/**
	 * Идентификаторы переведённых записей текущего языка.
	 *
	 * @return list<int>
	 */
	private function translatedIds(): array {
		$locale = $this->resolver->currentLocale();

		// Номер версии кэша переводов уже растёт при каждом сохранении
		// перевода, поэтому отдельная инвалидация этому списку не нужна.
		$key = $this->cache->version() . ':' . $locale;

		$cached = wp_cache_get( $key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ids = $this->occurrences->translatedObjectIds( $locale );

		wp_cache_set( $key, $ids, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $ids;
	}
}
