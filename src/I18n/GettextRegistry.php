<?php
/**
 * Перевод строк темы и плагинов через gettext-фильтры WordPress.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\I18n;

use WpMlp\Settings\Settings;
use WpMlp\Storage\GettextStore;
use WpMlp\Storage\TranslationCache;
use WpMlp\Support\Hookable;
use WpMlp\Support\Text;

/**
 * Второй эшелон перевода интерфейса — для того, что не закрыл официальный
 * языковой пакет (см. {@see LocaleSwitcher}).
 *
 * Порядок ответа на каждый вызов строго такой:
 *
 * 1. **наше переопределение** — если владелец сайта поправил формулировку
 *    руками, она всегда главнее пакета: иначе неудачный официальный
 *    перевод нечем было бы исправить;
 * 2. **официальный языковой пакет** — то, что вернул сам WordPress;
 * 3. **оригинал** (`msgid`) — если перевода нет нигде.
 *
 * Строки, которые закрыл пакет, в базу НЕ ПИШУТСЯ вовсе. Это осознанно:
 * иначе словарь мгновенно распух бы на тысячи строк ядра, которые никто
 * никогда не будет переводить руками, и найти среди них те немногие, что
 * действительно требуют внимания, стало бы невозможно. В базе живёт
 * только (а) то, для чего официального перевода нет, и (б) наши ручные
 * переопределения.
 *
 * ## Производительность
 *
 * Это самый горячий код плагина: `gettext` срабатывает сотни, а на
 * тяжёлых странице тысячи раз за запрос. Отсюда весь дизайн:
 *
 * - словарь переопределений грузится ОДИН раз за запрос, одним SQL
 *   (плюс объектный кэш) и живёт в памяти;
 * - поиск идёт по дешёвой склейке строк ({@see GettextKey::lookup()}),
 *   без SHA-256 на каждый вызов;
 * - когда переопределений нет вовсе — а это обычное состояние сайта —
 *   не считается даже склейка: карта пуста, и до неё дело не доходит;
 * - новые строки копятся в памяти и пишутся одной пачкой на `shutdown`,
 *   уже после того, как страница ушла посетителю.
 */
final class GettextRegistry implements Hookable {

	/**
	 * Переопределения: дешёвый ключ => перевод. null — ещё не загружены.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $overrides = null;

	/**
	 * Найденные строки без перевода, к записи на `shutdown`.
	 *
	 * @var array<string, array{msgid: string, domain: string, context: string, plural_key: ?int}>
	 */
	private array $discovered = array();

	/**
	 * Нормализованные тексты, которые контур уже отдал на этой странице.
	 *
	 * Нужны {@see \WpMlp\Rendering\Extractor}: строку, обслуженную здесь,
	 * он обязан пропустить, иначе одна и та же фраза попадёт в словарь
	 * дважды — как `gettext` и как обычный `text` — и переводить её
	 * придётся в двух разных местах.
	 *
	 * @var array<string, true>
	 */
	private array $served = array();

	/**
	 * Запись на `shutdown` уже запланирована.
	 */
	private bool $flushScheduled = false;

	/**
	 * @param LocaleSwitcher   $switcher   Решение «переключаемся ли мы сейчас».
	 * @param GettextStore     $repository Gettext-часть словаря.
	 * @param TranslationCache $cache      Кэш переводов (нужен номер версии).
	 * @param Settings         $settings   Настройки плагина.
	 */
	public function __construct(
		private readonly LocaleSwitcher $switcher,
		private readonly GettextStore $repository,
		private readonly TranslationCache $cache,
		private readonly Settings $settings
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		if ( null === $this->switcher->targetLocale() ) {
			/*
			 * Язык по умолчанию или служебный запрос: переводить нечего, а
			 * фильтры стоили бы своей цены на каждом вызове __() в админке.
			 * Решение то же самое, что у подмены локали, — намеренно: эти
			 * двое обязаны включаться и выключаться вместе.
			 */
			return;
		}

		add_filter( 'gettext', array( $this, 'filterText' ), 10, 3 );
		add_filter( 'gettext_with_context', array( $this, 'filterTextWithContext' ), 10, 4 );
		add_filter( 'ngettext', array( $this, 'filterPlural' ), 10, 5 );
		add_filter( 'ngettext_with_context', array( $this, 'filterPluralWithContext' ), 10, 6 );
	}

	/**
	 * `__()`, `_e()` — строка без контекста и без множественного числа.
	 *
	 * @param mixed $translation Что вернул WordPress.
	 * @param mixed $text        Оригинал (msgid).
	 * @param mixed $domain      Text domain.
	 */
	public function filterText( $translation, $text, $domain ): string {
		return $this->resolve( (string) $translation, (string) $text, (string) $domain, '', null );
	}

	/**
	 * `_x()` — строка с контекстом.
	 *
	 * @param mixed $translation Что вернул WordPress.
	 * @param mixed $text        Оригинал (msgid).
	 * @param mixed $context     Контекст.
	 * @param mixed $domain      Text domain.
	 */
	public function filterTextWithContext( $translation, $text, $context, $domain ): string {
		return $this->resolve( (string) $translation, (string) $text, (string) $domain, (string) $context, null );
	}

	/**
	 * `_n()` — множественное число.
	 *
	 * @param mixed $translation Что вернул WordPress (уже выбранная форма).
	 * @param mixed $single      Оригинал в единственном числе.
	 * @param mixed $plural      Оригинал во множественном числе.
	 * @param mixed $number      Число, по которому выбрана форма.
	 * @param mixed $domain      Text domain.
	 */
	public function filterPlural( $translation, $single, $plural, $number, $domain ): string {
		unset( $plural );

		return $this->resolve(
			(string) $translation,
			(string) $single,
			(string) $domain,
			'',
			$this->pluralKey( (string) $domain, (int) $number )
		);
	}

	/**
	 * `_nx()` — множественное число с контекстом.
	 *
	 * @param mixed $translation Что вернул WordPress.
	 * @param mixed $single      Оригинал в единственном числе.
	 * @param mixed $plural      Оригинал во множественном числе.
	 * @param mixed $number      Число, по которому выбрана форма.
	 * @param mixed $context     Контекст.
	 * @param mixed $domain      Text domain.
	 */
	public function filterPluralWithContext( $translation, $single, $plural, $number, $context, $domain ): string {
		unset( $plural );

		return $this->resolve(
			(string) $translation,
			(string) $single,
			(string) $domain,
			(string) $context,
			$this->pluralKey( (string) $domain, (int) $number )
		);
	}

	/**
	 * Нормализованные тексты, отданные контуром на этой странице.
	 *
	 * @return array<string, true>
	 */
	public function servedTexts(): array {
		return $this->served;
	}

	/**
	 * Общая логика всех четырёх фильтров.
	 *
	 * @param string   $translation Что вернул WordPress.
	 * @param string   $msgid       Оригинал строки.
	 * @param string   $domain      Text domain.
	 * @param string   $context     Контекст `_x()`, пустая строка — если нет.
	 * @param int|null $pluralKey   Номер формы множественного числа.
	 */
	private function resolve( string $translation, string $msgid, string $domain, string $context, ?int $pluralKey ): string {
		$override = $this->override( $msgid, $domain, $context, $pluralKey );

		if ( null !== $override ) {
			$this->remember( $override );

			return $override;
		}

		/*
		 * WordPress вернул оригинал нетронутым — значит в языковом пакете
		 * этой строки нет и переводить её придётся нам. Сравнение строгое:
		 * официальный перевод, случайно совпавший с оригиналом (у коротких
		 * слов вроде «OK» бывает), от непереведённой строки не отличить
		 * никак, и попадание такой строки в словарь безвредно.
		 */
		if ( $translation === $msgid ) {
			$this->discover( $msgid, $domain, $context, $pluralKey );
		}

		$this->remember( $translation );

		return $translation;
	}

	/**
	 * Ручное переопределение строки, если оно есть.
	 *
	 * @param string   $msgid     Оригинал строки.
	 * @param string   $domain    Text domain.
	 * @param string   $context   Контекст `_x()`.
	 * @param int|null $pluralKey Номер формы множественного числа.
	 */
	private function override( string $msgid, string $domain, string $context, ?int $pluralKey ): ?string {
		if ( null === $this->overrides ) {
			$this->overrides = $this->loadOverrides();
		}

		if ( array() === $this->overrides ) {
			// Обычное состояние сайта: руками не переопределяли ничего.
			// Даже склейку ключа считать незачем.
			return null;
		}

		return $this->overrides[ GettextKey::lookup( $msgid, $domain, $context, $pluralKey ) ] ?? null;
	}

	/**
	 * Загружает словарь переопределений — один раз за запрос.
	 *
	 * Язык берётся коротким кодом (`bg`), а не локалью WordPress
	 * (`bg_BG`): формат колонки `target_locale` в `translations` один на
	 * все виды строк, и смешивать в ней два формата значило бы получить
	 * один и тот же язык под двумя именами.
	 *
	 * @return array<string, string>
	 */
	private function loadOverrides(): array {
		$language = $this->switcher->targetLanguage();

		if ( null === $language ) {
			return array();
		}

		return $this->repository->overridesFor( $language->locale, $this->cache->version() );
	}

	/**
	 * Запоминает строку без перевода — записывать будем на `shutdown`.
	 *
	 * @param string   $msgid     Оригинал строки.
	 * @param string   $domain    Text domain.
	 * @param string   $context   Контекст `_x()`.
	 * @param int|null $pluralKey Номер формы множественного числа.
	 */
	private function discover( string $msgid, string $domain, string $context, ?int $pluralKey ): void {
		if ( ! $this->settings->isDiscoveryEnabled() || '' === trim( $msgid ) ) {
			return;
		}

		if ( $this->targetIsSourceLanguage() ) {
			/*
			 * Целевой язык — тот же, на котором написан сам msgid (то есть
			 * английский). Тогда «WordPress вернул оригинал» означает не
			 * «перевода нет», а «перевод не нужен»: английский текст уже
			 * и есть готовый ответ. Без этой проверки открытие /en/ занесло
			 * бы в словарь ВСЕ строки ядра, темы и плагинов разом — тысячи
			 * строк, которые нечего переводить, — и утопило бы в них те
			 * немногие, что действительно ждут перевода.
			 */
			return;
		}

		$key = GettextKey::lookup( $msgid, $domain, $context, $pluralKey );

		if ( isset( $this->discovered[ $key ] ) ) {
			return;
		}

		$this->discovered[ $key ] = array(
			'msgid'      => $msgid,
			'domain'     => $domain,
			'context'    => $context,
			'plural_key' => $pluralKey,
		);

		$this->scheduleFlush();
	}

	/**
	 * Совпадает ли целевой язык с языком самих `msgid`.
	 *
	 * Сравнивается полная локаль, а не код языка: `en_GB` — это НЕ язык
	 * оригинала. Британский сайт вполне может захотеть переопределить
	 * «color» на «colour», и такие строки в словарь попадать должны.
	 */
	private function targetIsSourceLanguage(): bool {
		$language = $this->switcher->targetLanguage();

		return null !== $language && GettextKey::SOURCE_LOCALE === $language->wpLocale;
	}

	/**
	 * Откладывает запись новых строк на конец запроса.
	 */
	private function scheduleFlush(): void {
		if ( $this->flushScheduled ) {
			return;
		}

		$this->flushScheduled = true;

		add_action( 'shutdown', array( $this, 'flush' ), 100 );
	}

	/**
	 * Пишет накопленные строки. Выполняется уже после отправки ответа.
	 */
	public function flush(): void {
		if ( array() === $this->discovered ) {
			return;
		}

		$rows             = array_values( $this->discovered );
		$this->discovered = array();

		$this->repository->insertMissing( $rows );
	}

	/**
	 * Отмечает текст как обслуженный gettext-контуром.
	 *
	 * @param string $text Строка в том виде, в каком она уйдёт в разметку.
	 */
	private function remember( string $text ): void {
		$normalized = Text::normalize( $text );

		if ( '' !== $normalized ) {
			$this->served[ $normalized ] = true;
		}
	}

	/**
	 * Номер формы множественного числа для этого числа и домена.
	 *
	 * Форму выбирает не наш код, а сам объект переводов домена — у каждого
	 * языка свои правила (в русском три формы, в английском и болгарском
	 * две), и повторять их здесь значило бы однажды разойтись с ядром.
	 * Если переводов для домена нет вовсе, WordPress подставляет
	 * `NOOP_Translations` с английским правилом — это тоже верный ответ:
	 * без пакета формы и выбираются по-английски.
	 *
	 * @param string $domain Text domain.
	 * @param int    $number Число из `_n()`.
	 */
	private function pluralKey( string $domain, int $number ): int {
		if ( ! function_exists( 'get_translations_for_domain' ) ) {
			return 1 === $number ? 0 : 1;
		}

		$translations = get_translations_for_domain( $domain );

		if ( ! is_object( $translations ) || ! method_exists( $translations, 'select_plural_form' ) ) {
			return 1 === $number ? 0 : 1;
		}

		return (int) $translations->select_plural_form( $number );
	}
}
