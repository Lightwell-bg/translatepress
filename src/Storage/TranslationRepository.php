<?php
/**
 * Доступ к таблице переводов.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Storage;

use WpMlp\Support\Hash;
use WpMlp\Support\Locale;

/**
 * Чтение и запись `wp_mlp_translations`.
 */
final class TranslationRepository {

	/**
	 * Сохраняет перевод строки на конкретный язык.
	 *
	 * Пишется через INSERT ... ON DUPLICATE KEY UPDATE: уникальный индекс
	 * (source_id, target_locale) гарантирует один перевод на пару.
	 *
	 * @param int         $sourceId   Идентификатор исходной строки.
	 * @param string      $locale     Целевой язык.
	 * @param string      $text       Текст перевода.
	 * @param string      $status     Статус из TranslationStatus.
	 * @param int|null    $userId     Кто сохранил.
	 * @param string|null $revision   Hex source_hash исходника на момент перевода.
	 * @param string|null $provider   Провайдер машинного перевода, если он был.
	 * @param string|null $model      Модель провайдера.
	 */
	public function save(
		int $sourceId,
		string $locale,
		string $text,
		string $status,
		?int $userId = null,
		?string $revision = null,
		?string $provider = null,
		?string $model = null
	): bool {
		global $wpdb;

		if ( $sourceId <= 0 || ! Locale::isValid( $locale ) || ! TranslationStatus::isValid( $status ) ) {
			return false;
		}

		$table    = Schema::table( 'translations' );
		$now      = gmdate( 'Y-m-d H:i:s' );
		$revision = null !== $revision && Hash::isValid( $revision ) ? $revision : null;

		$revisionSql = null !== $revision ? 'UNHEX(%s)' : 'NULL';

		$args = array( $sourceId, $locale, $text, $status, (string) $provider, (string) $model );

		if ( null !== $revision ) {
			$args[] = $revision;
		}

		$args[] = (int) $userId;
		$args[] = $now;
		$args[] = $now;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$sql = $wpdb->prepare(
			"INSERT INTO {$table}
				(source_id, target_locale, translated_text, status, provider, model, source_revision, created_by, created_at, updated_at)
			 VALUES (%d, %s, %s, %s, NULLIF(%s, ''), NULLIF(%s, ''), {$revisionSql}, NULLIF(%d, 0), %s, %s)
			 ON DUPLICATE KEY UPDATE
				translated_text = VALUES(translated_text),
				status          = VALUES(status),
				provider        = VALUES(provider),
				model           = VALUES(model),
				source_revision = VALUES(source_revision),
				created_by      = VALUES(created_by),
				updated_at      = VALUES(updated_at)",
			$args
		);

		$result = $wpdb->query( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		return false !== $result;
	}

	/**
	 * Один перевод.
	 *
	 * @param int    $sourceId Исходная строка.
	 * @param string $locale   Целевой язык.
	 * @return array<string, mixed>|null
	 */
	public function find( int $sourceId, string $locale ): ?array {
		global $wpdb;

		if ( ! Locale::isValid( $locale ) ) {
			return null;
		}

		$table = Schema::table( 'translations' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, source_id, target_locale, translated_text, status, provider, model, created_by, created_at, updated_at
				 FROM {$table} WHERE source_id = %d AND target_locale = %s",
				$sourceId,
				$locale
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Удаляет перевод строки на язык.
	 *
	 * @param int    $sourceId Исходная строка.
	 * @param string $locale   Целевой язык.
	 */
	public function delete( int $sourceId, string $locale ): bool {
		global $wpdb;

		if ( ! Locale::isValid( $locale ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->delete(
			Schema::table( 'translations' ),
			array(
				'source_id'     => $sourceId,
				'target_locale' => $locale,
			),
			array( '%d', '%s' )
		);
	}
}
