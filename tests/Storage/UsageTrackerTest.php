<?php
/**
 * Тесты вычисления ключа дневного бюджета.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Storage\UsageTracker;

/**
 * Сама запись и чтение счётчика идёт через $wpdb/wp_options и здесь не
 * проверяется — это устоявшийся API WordPress. Тестируется только то, что
 * ключ детерминирован и различается по дням, что и есть вся логика класса.
 */
#[CoversClass( UsageTracker::class )]
final class UsageTrackerTest extends TestCase {

	public function testKeyIncludesTheDate(): void {
		$this->assertSame( 'mlp_usage_2026-08-07', UsageTracker::optionKey( '2026-08-07' ) );
	}

	public function testDifferentDatesGiveDifferentKeys(): void {
		$this->assertNotSame(
			UsageTracker::optionKey( '2026-08-07' ),
			UsageTracker::optionKey( '2026-08-08' )
		);
	}
}
