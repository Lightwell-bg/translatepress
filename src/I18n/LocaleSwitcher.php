<?php
/**
 * Подмена локали WordPress на дополнительных языках.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\I18n;

use WpMlp\Routing\LanguageResolver;
use WpMlp\Support\FrontendRequest;
use WpMlp\Support\Hookable;

/**
 * Говорит WordPress, что текущая страница — на целевом языке, и ядро,
 * тема и плагины сами отдают свои строки из официального языкового пакета.
 *
 * Это самый дешёвый способ перевести интерфейс и главный рычаг всей
 * задачи: «Ответить», «Ваш адрес email не будет опубликован», кнопки
 * корзины и сотни других строк уже переведены сообществом WordPress и
 * лежат в `wp-content/languages/`. Переводить их своими руками (или, тем
 * более, платить за это токенами OpenAI) — значит переводить переведённое.
 * Поэтому подмена локали идёт ПЕРВОЙ, до собственного gettext-словаря:
 * тот нужен только для того, что осталось непокрытым (см. GettextRegistry).
 *
 * Регистрируется на `plugins_loaded` (приоритет 5, см. wp-mlp.php) — это
 * важно по времени: `load_default_textdomain()` ядра, `load_theme_textdomain()`
 * темы и `load_plugin_textdomain()` плагинов вызываются ПОЗЖЕ, уже после
 * `plugins_loaded`, и к этому моменту фильтры должны стоять. Плагин,
 * который грузит свой домен раньше нас, останется непереведённым — это
 * известное ограничение, лечится только загрузкой ещё раньше (mu-plugin).
 *
 * Чего этот класс НЕ делает:
 *
 * - не трогает админку, ajax, cron, REST и CLI (см. FrontendRequest) —
 *   иначе wp-admin внезапно стал бы англоязычным для владельца сайта;
 * - не трогает POST-запросы, а значит и транзакционные письма, которые
 *   WordPress шлёт при отправке форм (ТЗ 11): язык писем в этой версии
 *   не меняется вовсе;
 * - ничего не пишет в базу и не знает про наш словарь переводов.
 *
 * Побочные эффекты смены локали — ожидаемые и правильные: вместе с языком
 * меняются формат даты, названия месяцев и направление текста
 * (`is_rtl()`), потому что всё это часть локали, а не отдельная настройка.
 */
final class LocaleSwitcher implements Hookable {

	/**
	 * Фильтры, через которые WordPress спрашивает «какая сейчас локаль».
	 *
	 * `locale` отвечает за ядро и за общий ответ `get_locale()`,
	 * `plugin_locale` и `theme_locale` — за загрузку доменов конкретного
	 * плагина и темы. Последние два формально получают уже посчитанное
	 * значение из `determine_locale()`, но фильтруются отдельно: плагин
	 * вправе загрузить свой домен принудительно для другой локали, и тогда
	 * до `locale` дело не дойдёт.
	 */
	private const LOCALE_FILTERS = array( 'locale', 'plugin_locale', 'theme_locale' );

	/**
	 * @param LanguageResolver $resolver Язык текущего запроса.
	 */
	public function __construct( private readonly LanguageResolver $resolver ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		foreach ( self::LOCALE_FILTERS as $filter ) {
			add_filter( $filter, array( $this, 'filterLocale' ) );
		}
	}

	/**
	 * Возвращает локаль целевого языка вместо локали сайта.
	 *
	 * @param mixed $locale Локаль, которую посчитал WordPress.
	 */
	public function filterLocale( $locale ): string {
		$target = $this->targetLocale();

		return null !== $target ? $target : (string) $locale;
	}

	/**
	 * Локаль, на которую нужно переключиться, или null — если не нужно.
	 *
	 * Публичный, потому что на тот же самый ответ опирается gettext-контур:
	 * решать «переключаемся ли мы сейчас» в двух местах по-разному нельзя.
	 */
	public function targetLocale(): ?string {
		if ( ! FrontendRequest::isPublicRender() ) {
			return null;
		}

		$language = $this->resolver->current();

		if ( $language->isDefault ) {
			// Язык по умолчанию — это и есть локаль сайта, менять нечего.
			return null;
		}

		return '' !== $language->wpLocale ? $language->wpLocale : null;
	}
}
