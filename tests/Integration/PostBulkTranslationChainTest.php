<?php
/**
 * Интеграционный тест цепочки «Перевести весь материал с ИИ».
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\Extractor;
use WpMlp\Rendering\PostContentExtractor;
use WpMlp\Storage\PostTranslationSnapshot;
use WpMlp\Storage\TranslationStatus;
use WpMlp\Support\ShortcodeGuard;
use WpMlp\Translation\BatchChunker;
use WpMlp\Translation\BulkTranslationMode;
use WpMlp\Translation\PostCommitValidator;
use WpMlp\Translation\PostOccurrenceRows;

/**
 * `PostTranslationController` сам не тестируется юнит-тестами (как и
 * соседние TranslationsController/BlocksController — все трое требуют
 * $wpdb, register_rest_route(), REST-nonce и живой WordPress; в этом
 * проекте нет ни тестового WP, ни SQLite-подмены для него). Но ВСЯ
 * логика, которую контроллер использует — разбор записи, дедуп,
 * chunk-разбивка, выбор режима, проверка пакета перед записью — это
 * чистые классы без единого обращения к $wpdb, и здесь они прогоняются
 * вместе, ровно в том порядке, что и в контроллере, с БД, подменённой
 * простыми PHP-массивами:
 *
 * `summary` (PostContentExtractor + BatchChunker + BulkTranslationMode)
 *   → несколько `chunk` (эмуляция ответа ИИ, включая один заведомо
 *     плохой — испорченный шорткод)
 *   → `validation` (PostCommitValidator — весь пакет целиком)
 *   → `commit` (запись в поддельную «таблицу» — только если validate() ok)
 *   → повторное чтение (та же запись разбирается заново, перевод находится
 *     по uniq_hash).
 *
 * Что эта проверка НЕ покрывает и что нужно проверить руками —
 * перечислено в README (раздел «Перевести весь материал с ИИ» →
 * «Что проверить вручную»): реальный REST-роутинг, permission_callback
 * и nonce поверх настоящего WordPress, и поведение самой SQL-транзакции
 * (`START TRANSACTION`/`ROLLBACK`) на настоящем MySQL при сбое посреди
 * записи — без реального сервера БД это не воспроизвести.
 */
#[CoversNothing]
final class PostBulkTranslationChainTest extends TestCase {

	private const POST_ID = 501;
	private const LOCALE  = 'en';

	/**
	 * Поддельная «таблица sources»: uniq_hash => {id, kind, source_text}.
	 * Поддельная «таблица translations»: id => {text, status}.
	 * Поддельная «occurrences»: id => post_id, которой принадлежит строка.
	 *
	 * @return array{sources: array<string, array{id:int, kind:string, source_text:string}>, translations: array<int, array{text:string, status:string}>, occurrences: array<int, int>}
	 */
	private function discover( object $post ): array {
		$result = ( new PostContentExtractor( new Extractor() ) )->extract( $post, 'ru' );

		$unique = array();

		foreach ( $result->segments as $postSegment ) {
			$unique[ $postSegment->segment->uniqHash ] ??= $postSegment;
		}

		$sources     = array();
		$occurrences = array();
		$id          = 1;

		foreach ( $unique as $hash => $postSegment ) {
			$sources[ $hash ] = array(
				'id'          => $id,
				'kind'        => $postSegment->segment->kind,
				'source_text' => $postSegment->segment->text,
			);
			$occurrences[ $id ] = self::POST_ID;
			++$id;
		}

		return array( 'sources' => $sources, 'translations' => array(), 'occurrences' => $occurrences );
	}

	/**
	 * Заголовок и excerpt намеренно пустые: их извлечение и перевод уже
	 * покрыты PostContentExtractorTest — здесь в фокусе именно цепочка
	 * chunk → validate → commit → перечитывание поверх содержимого,
	 * и лишний сегмент от заголовка только усложнил бы арифметику тестов
	 * без дополнительной пользы.
	 */
	private function post(): object {
		$post                = new \stdClass();
		$post->ID            = self::POST_ID;
		$post->post_title    = '';
		$post->post_excerpt  = '';
		$post->post_content  = '<!-- wp:paragraph -->'
			. '<p>Добро пожаловать в наш сервис.</p>'
			. '<!-- /wp:paragraph -->'
			. '<!-- wp:paragraph -->'
			. '<p>Заполните форму [signup_form id="3"] и подтвердите email.</p>'
			. '<!-- /wp:paragraph -->'
			. '<!-- wp:paragraph -->'
			. '<p>Спасибо, что вы с нами.</p>'
			. '<!-- /wp:paragraph -->';

		return $post;
	}

	/**
	 * Полный проход: summary → chunk'и (один из них с испорченным
	 * шорткодом) → жёсткая проверка перед записью → commit → повторное
	 * чтение. Ядро требования: одна плохая строка проваливает ВЕСЬ пакет,
	 * старые переводы не трогаются до успешного commit.
	 */
	public function testFullChainWithOneBadChunkFailsTheWholeCommit(): void {
		$post = $this->post();
		$db   = $this->discover( $post );

		// --- summary: режим "перевести всё", разбивка на чанки ---
		$allHashes = array_keys( $db['sources'] );
		$existing  = array_fill_keys( $allHashes, '' );
		$toSend    = BulkTranslationMode::selectForTranslation( $allHashes, $existing, BulkTranslationMode::ALL );

		$items = array();

		foreach ( $toSend as $hash ) {
			$items[ $hash ] = $db['sources'][ $hash ]['source_text'];
		}

		$chunks = BatchChunker::chunk( $items, 40 ); // маленький бюджет — гарантированно несколько чанков.

		$this->assertGreaterThan( 1, count( $chunks ), 'Фикстура должна дать больше одного чанка — иначе тест не проверяет цепочку.' );

		// --- несколько chunk-запросов подряд, один — с плохим переводом ---
		$fakeAiResponses = array(
			'Добро пожаловать в наш сервис.' => 'Welcome to our service.',
			// Шорткод переставлен местами со скобкой — испорчен намеренно.
			'Заполните форму [signup_form id="3"] и подтвердите email.' => 'Fill out the signup_form[ id="3"] and confirm your email.',
			'Спасибо, что вы с нами.' => 'Thank you for being with us.',
		);

		$payload = array();

		foreach ( $chunks as $chunk ) {
			foreach ( $chunk as $hash => $sourceText ) {
				$translated = $fakeAiResponses[ $sourceText ] ?? null;
				$this->assertNotNull( $translated, "Нет ответа ИИ в фикстуре для: $sourceText" );

				// Мягкое отбрасывание уровня /chunk: испорченный шорткод не
				// попадает в предложенный перевод вовсе (сегмент остаётся
				// пустым, как и был, — ровно то, что делает PostTranslationController::chunk()).
				if ( ShortcodeGuard::containsShortcode( $sourceText ) && ! ShortcodeGuard::isPreserved( $sourceText, $translated ) ) {
					continue;
				}

				$payload[] = array(
					'uniq_hash'       => $hash,
					'translated_text' => $translated,
					'status'          => TranslationStatus::MACHINE,
				);
			}
		}

		// Пришли переводы только для 2 из 3 строк — третья, с шорткодом,
		// осталась вне пакета: /chunk её уже отсеял.
		$this->assertCount( 2, $payload );

		// --- жёсткая проверка перед записью: пакет ЧИСТЫЙ (мягкий фильтр
		// уже сработал выше), поэтому validate() обязан пропустить его ---
		$validated = PostCommitValidator::validate(
			$payload,
			$db['sources'],
			static fn( int $id ): bool => self::POST_ID === ( $db['occurrences'][ $id ] ?? null )
		);

		$this->assertTrue( $validated['ok'], implode( ', ', $validated['errors'] ) );
		$this->assertCount( 2, $validated['rows'] );

		// --- а теперь — тот же пакет, но БЕЗ мягкого фильтра /chunk: как
		// если бы испорченный перевод всё-таки просочился в запрос на
		// commit напрямую (баг клиента, подмена запроса). commit обязан
		// поймать это САМ, второй, независимой линией защиты. ---
		$tamperedPayload   = $payload;
		$tamperedPayload[] = array(
			'uniq_hash'       => array_search( 'Заполните форму [signup_form id="3"] и подтвердите email.', array_map( static fn( $s ) => $s['source_text'], $db['sources'] ), true ),
			'translated_text' => 'Fill out the signup_form[ id="3"] and confirm your email.',
			'status'          => TranslationStatus::MACHINE,
		);

		$tamperedValidation = PostCommitValidator::validate(
			$tamperedPayload,
			$db['sources'],
			static fn( int $id ): bool => self::POST_ID === ( $db['occurrences'][ $id ] ?? null )
		);

		$this->assertFalse( $tamperedValidation['ok'] );
		$this->assertSame( array(), $tamperedValidation['rows'], 'Испорченный пакет не должен вернуть НИ ОДНОЙ строки на запись.' );

		// --- commit ЧИСТОГО пакета — атомарная запись в поддельную БД ---
		foreach ( $validated['rows'] as $row ) {
			$db['translations'][ $row['id'] ] = array( 'text' => $row['text'], 'status' => $row['status'] );
		}

		$snapshot = array_keys( $db['sources'] ); // снимок "переведено на этот раз".

		// --- повторное чтение: та же запись, разобранная заново ---
		$reDiscovered = $this->discover( $post );

		foreach ( $reDiscovered['sources'] as $hash => $source ) {
			$saved = $db['translations'][ $source['id'] ] ?? null;

			if ( 'Заполните форму [signup_form id="3"] и подтвердите email.' === $source['source_text'] ) {
				// Строка с испорченным шорткодом осталась НЕ переведённой —
				// ни один её вариант никогда не попадал в $db['translations'].
				$this->assertNull( $saved );

				continue;
			}

			$this->assertNotNull( $saved, "Перевод для \"{$source['source_text']}\" не нашёлся после перечитывания." );
			$this->assertSame( TranslationStatus::MACHINE, $saved['status'] );
		}

		// Слепок «переведено при последнем массовом переводе» покрывает
		// все хеши записи (нужен для requirement 10 — «только изменившееся»
		// при следующем прогоне), включая непереведённую строку с шорткодом:
		// её тоже видели, просто нечем было заполнить.
		$this->assertSame( array_keys( $db['sources'] ), $snapshot );
	}

	/**
	 * Требование 5, пункт 1: «Только пустые» не отправляет уже переведённые
	 * сегменты в ИИ — они не появляются даже в списке того, что нужно
	 * перевести, не то что в запросе к провайдеру.
	 */
	public function testEmptyModeNeverSendsAlreadyTranslatedSegmentsToAi(): void {
		$post = $this->post();
		$db   = $this->discover( $post );

		$firstHash = array_key_first( $db['sources'] );
		$firstId   = $db['sources'][ $firstHash ]['id'];

		// Первая строка уже переведена и подтверждена человеком в прошлый раз.
		$db['translations'][ $firstId ] = array( 'text' => 'Already translated.', 'status' => TranslationStatus::APPROVED );

		$existing = array();

		foreach ( $db['sources'] as $hash => $source ) {
			$existing[ $hash ] = (string) ( $db['translations'][ $source['id'] ]['text'] ?? '' );
		}

		$toSend = BulkTranslationMode::selectForTranslation( array_keys( $db['sources'] ), $existing, BulkTranslationMode::EMPTY );

		$this->assertNotContains( $firstHash, $toSend, 'Уже переведённая строка не должна попасть в список на отправку ИИ.' );

		// И раз её нет в toSend — соответствующего текста нет и в чанках,
		// то есть он физически не мог уйти в запрос к провайдеру.
		$items = array();

		foreach ( $toSend as $hash ) {
			$items[ $hash ] = $db['sources'][ $hash ]['source_text'];
		}

		$this->assertNotContains( $existing[ $firstHash ], $items );
		foreach ( BatchChunker::chunk( $items ) as $chunk ) {
			$this->assertArrayNotHasKey( $firstHash, $chunk );
		}
	}

	/**
	 * Требование 5, пункт 2: «Перевести заново» готовит новые переводы для
	 * ВСЕХ сегментов, включая уже переведённые, — но старое значение в
	 * поддельной БД остаётся нетронутым, пока commit не завершится успешно.
	 */
	public function testAllModePreparesEverythingButDoesNotTouchOldTranslationsBeforeCommit(): void {
		$post = $this->post();
		$db   = $this->discover( $post );

		$firstHash = array_key_first( $db['sources'] );
		$firstId   = $db['sources'][ $firstHash ]['id'];

		$db['translations'][ $firstId ] = array( 'text' => 'Old approved translation.', 'status' => TranslationStatus::APPROVED );

		$existing = array();

		foreach ( $db['sources'] as $hash => $source ) {
			$existing[ $hash ] = (string) ( $db['translations'][ $source['id'] ]['text'] ?? '' );
		}

		$toSend = BulkTranslationMode::selectForTranslation( array_keys( $db['sources'] ), $existing, BulkTranslationMode::ALL );

		// Режим ALL — уже переведённая строка тоже готовится к переводу…
		$this->assertContains( $firstHash, $toSend );

		// …но пока это только "черновик": поддельная БД ещё не тронута.
		$this->assertSame( 'Old approved translation.', $db['translations'][ $firstId ]['text'] );

		// Запрос к commit НЕ отправляем вовсе (пользователь мог передумать,
		// закрыть вкладку, получить сетевую ошибку) — старое значение должно
		// остаться ровно тем, каким было.
		$this->assertSame( 'Old approved translation.', $db['translations'][ $firstId ]['text'] );
		$this->assertSame( TranslationStatus::APPROVED, $db['translations'][ $firstId ]['status'] );
	}

	/**
	 * Требование 10 в связке с этой же цепочкой: правка ОДНОГО абзаца в
	 * post_content — единственный изменившийся сегмент виден как новый,
	 * остальные хеши те же самые, что и раньше, и их перевод в поддельной
	 * БД остаётся доступен по тому же id.
	 */
	public function testEditingOneParagraphChangesOnlyItsHash(): void {
		$post = $this->post();
		$db   = $this->discover( $post );

		$before = array_keys( $db['sources'] );

		$post->post_content = str_replace( 'Спасибо, что вы с нами.', 'Спасибо, что вы всегда с нами.', $post->post_content );

		$after = array_keys( $this->discover( $post )['sources'] );

		$changed = PostTranslationSnapshot::changed( $after, $before );

		$this->assertCount( 1, $changed );
		// И два хеша из трёх — ровно те же, что были: их перевод и id в
		// поддельной БД остаются валидны без повторного перевода.
		$this->assertCount( 2, array_intersect( $before, $after ) );
	}

	/**
	 * Жалоба 09.08.2026: «Не удалось сохранить: проверка пакета не прошла
	 * (foreign_segment:...)». Причина — общий абзац (один и тот же source_id
	 * в mlp_sources — источник строк общий на весь сайт) встречается в ДВУХ
	 * РАЗНЫХ записях. До фикса occurrence-строка каждой записи собиралась
	 * без учёта object_id, и `INSERT ... ON DUPLICATE KEY UPDATE` (в
	 * occurrences уникальный индекс — ровно uniq_hash, см. Schema.php) тихо
	 * СЛИВАЛ регистрацию второй записи с первой — вторая никогда не могла
	 * подтвердить владение сегментом.
	 *
	 * Фейковая occurrences здесь — словарь, ключ которого САМ uniq_hash
	 * occurrence-строки (не `id => postId`, как в discover() выше выше):
	 * только так вставка второй записи с тем же uniq_hash реально
	 * ЗАМЕЩАЕТ первую в фейковой таблице, если фикс не работает, — ровно
	 * то поведение MySQL, которое и вызывало баг.
	 */
	public function testTwoPostsSharingTheSameParagraphBothOwnTheirOwnOccurrence(): void {
		$postA = $this->post();

		$postB               = $this->post();
		$postB->ID           = 777;
		$postB->post_content = '<!-- wp:paragraph --><p>Спасибо, что вы с нами.</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>Уникальный абзац второй записи.</p><!-- /wp:paragraph -->';

		$sharedSources          = array();
		$nextId                 = 1;
		$occurrencesByUniqHash  = array();

		$register = function ( object $post ) use ( &$sharedSources, &$nextId, &$occurrencesByUniqHash ): array {
			$result = ( new PostContentExtractor( new Extractor() ) )->extract( $post, 'ru' );

			$unique = array();

			foreach ( $result->segments as $postSegment ) {
				$unique[ $postSegment->segment->uniqHash ] ??= $postSegment;
			}

			$ids = array();

			foreach ( $unique as $hash => $postSegment ) {
				if ( ! isset( $sharedSources[ $hash ] ) ) {
					$sharedSources[ $hash ] = array(
						'id'          => $nextId++,
						'kind'        => $postSegment->segment->kind,
						'source_text' => $postSegment->segment->text,
					);
				}

				$ids[ $hash ] = $sharedSources[ $hash ]['id'];
			}

			foreach ( PostOccurrenceRows::build( $unique, $ids, (int) $post->ID ) as $row ) {
				// ON DUPLICATE KEY UPDATE: тот же uniq_hash перезаписывает ту
				// же строку вместо второй, — ровно то, что делает MySQL.
				$occurrencesByUniqHash[ $row['uniq_hash'] ] = $row;
			}

			return $ids;
		};

		$idsA = $register( $postA );
		$idsB = $register( $postB );

		$sharedHash = array_search(
			'Спасибо, что вы с нами.',
			array_map( static fn( array $s ): string => $s['source_text'], $sharedSources ),
			true
		);

		$this->assertNotFalse( $sharedHash, 'Фикстура должна дать общий абзац в обеих записях.' );
		$this->assertSame(
			$idsA[ $sharedHash ],
			$idsB[ $sharedHash ],
			'source_id общего абзаца обязан быть один и тот же в обеих записях — источник строк общий.'
		);

		$sharedSourceId = $idsA[ $sharedHash ];

		$belongsTo = static function ( int $sourceId, int $postId ) use ( $occurrencesByUniqHash ): bool {
			foreach ( $occurrencesByUniqHash as $row ) {
				if ( $row['source_id'] === $sourceId && $row['object_id'] === $postId ) {
					return true;
				}
			}

			return false;
		};

		$this->assertTrue(
			$belongsTo( $sharedSourceId, self::POST_ID ),
			'Общий абзац обязан подтверждаться как принадлежащий первой записи.'
		);
		$this->assertTrue(
			$belongsTo( $sharedSourceId, 777 ),
			'Общий абзац обязан подтверждаться как принадлежащий и второй записи — не только первой (это и есть баг из жалобы).'
		);
	}
}
