<?php
/**
 * Сборка ZIP плагина для загрузки в WordPress.
 *
 * Запуск: `php bin/build-zip.php [--no-bump] [--dry-run]`
 *
 * 1. Поднимает patch-версию в wp-mlp.php (заголовок `Version:` и константа
 *    WP_MLP_VERSION) и `Stable tag:` в readme.txt (если он есть);
 *    `--no-bump` пропускает этот шаг.
 * 2. Берёт следующий номер N после максимального `_<N>wp-mlp.zip` в dist/.
 * 3. Кладёт в архив `wp-mlp/` только рантайм: wp-mlp.php, uninstall.php,
 *    readme.txt (если есть), src/, assets/, languages/. Ни tests, docs, bin,
 *    vendor (у плагина свой PSR-4 автозагрузчик), ни README.md, ни скрытых
 *    файлов (.env, .env.example и т. п.: скрытые файлы — ошибка Plugin Check).
 * 4. Сборка падает, если в списке оказался скрытый файл или любой `.env*`,
 *    а также если `Stable tag` в readme.txt не равен `Version` плагина.
 *
 * `--dry-run` только печатает список файлов: ничего не пишет и не меняет версию.
 * Существующий архив никогда не перезаписывается. readme.txt и wp-mlp.php
 * правятся на диске только после успешного переименования архива; при сбое
 * записи оба возвращаются к исходному содержимому, а архив удаляется.
 */

declare( strict_types=1 );

$root    = dirname( __DIR__ );
$options = array_slice( $argv, 1 );
$noBump  = in_array( '--no-bump', $options, true );
$dryRun  = in_array( '--dry-run', $options, true );

if ( ! class_exists( ZipArchive::class ) ) {
	fwrite( STDERR, "Нужно расширение PHP zip.\n" );
	exit( 1 );
}

$mainFile = $root . '/wp-mlp.php';
$source   = (string) file_get_contents( $mainFile );

if ( 1 !== preg_match( '/^(\s*\*\s*Version:\s*)(\d+)\.(\d+)\.(\d+)\s*$/m', $source, $m ) ) {
	fwrite( STDERR, "Не найдена строка Version: в wp-mlp.php.\n" );
	exit( 1 );
}

$version = "{$m[2]}.{$m[3]}.{$m[4]}";

if ( ! $noBump ) {
	$version = "{$m[2]}.{$m[3]}." . ( (int) $m[4] + 1 );
	$updated = preg_replace( '/^(\s*\*\s*Version:\s*)\d+\.\d+\.\d+/m', '${1}' . $version, $source, 1 );
	$updated = preg_replace( "/(const WP_MLP_VERSION = ')\d+\.\d+\.\d+(')/", '${1}' . $version . '${2}', (string) $updated, 1, $count );

	if ( 1 !== $count ) {
		fwrite( STDERR, "Не найдена константа WP_MLP_VERSION в wp-mlp.php.\n" );
		exit( 1 );
	}

	$source = (string) $updated;
}

// readme.txt для wordpress.org: Stable tag обязан совпадать с версией плагина.
$readmeFile   = $root . '/readme.txt';
$readmeSource = is_file( $readmeFile ) ? (string) file_get_contents( $readmeFile ) : null;
$readmeNew    = $readmeSource;

if ( null !== $readmeNew && ! $noBump ) {
	$readmeNew = preg_replace( '/^(\s*Stable tag:\s*)\S+/mi', '${1}' . $version, $readmeNew, 1, $tagCount );

	if ( 1 !== $tagCount || null === $readmeNew ) {
		fwrite( STDERR, "Не найдена строка Stable tag: в readme.txt.\n" );
		exit( 1 );
	}
}

if ( null !== $readmeNew ) {
	if ( 1 !== preg_match( '/^\s*Stable tag:\s*(\S+)/mi', $readmeNew, $tag ) || $tag[1] !== $version ) {
		fwrite( STDERR, 'Stable tag в readme.txt (' . ( $tag[1] ?? 'нет' ) . ") не равен версии плагина {$version}.\n" );
		exit( 1 );
	}
}

// Файлы рантайма: относительный путь => абсолютный.
$files = array();

foreach ( array( 'wp-mlp.php', 'uninstall.php', 'readme.txt' ) as $name ) {
	if ( is_file( $root . '/' . $name ) ) {
		$files[ $name ] = $root . '/' . $name;
	}
}

foreach ( array( 'src', 'assets', 'languages' ) as $dir ) {
	if ( ! is_dir( $root . '/' . $dir ) ) {
		continue;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		/** @var SplFileInfo $file */
		if ( ! $file->isFile() || str_starts_with( $file->getFilename(), '.' ) ) {
			continue;
		}

		$relative           = $dir . '/' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root . '/' . $dir ) + 1 ) );
		$files[ $relative ] = $file->getPathname();
	}
}

// Скрытые файлы и любые .env* в архив не попадают никогда (секреты; Plugin Check).
foreach ( array_keys( $files ) as $relative ) {
	foreach ( explode( '/', (string) $relative ) as $part ) {
		if ( str_starts_with( $part, '.' ) || str_starts_with( strtolower( $part ), '.env' ) ) {
			fwrite( STDERR, "В архив попал скрытый файл или .env* ({$relative}) — сборка прервана.\n" );
			exit( 1 );
		}
	}
}

ksort( $files );

// Следующий номер архива.
$distDir = $root . '/dist';
$max     = 0;

foreach ( (array) glob( $distDir . '/_*wp-mlp.zip' ) as $existing ) {
	if ( 1 === preg_match( '/_(\d+)wp-mlp\.zip$/', (string) $existing, $n ) ) {
		$max = max( $max, (int) $n[1] );
	}
}

$target = $distDir . '/_' . ( $max + 1 ) . 'wp-mlp.zip';

if ( file_exists( $target ) ) {
	fwrite( STDERR, "Файл уже существует: {$target}\n" );
	exit( 1 );
}

if ( $dryRun ) {
	foreach ( array_keys( $files ) as $relative ) {
		echo 'wp-mlp/' . $relative . "\n";
	}

	printf( "[dry-run] %s, версия %s, файлов: %d\n", $target, $version, count( $files ) );
	exit( 0 );
}

if ( ! is_dir( $distDir ) && ! mkdir( $distDir, 0777, true ) ) {
	fwrite( STDERR, "Не удалось создать dist/.\n" );
	exit( 1 );
}

/*
 * Транзакционная сборка: архив собирается во временный файл (имя не подпадает
 * под `_<N>wp-mlp.zip`), wp-mlp.php кладётся из уже поднятой версии в памяти.
 * Исходный wp-mlp.php правится только после успешного переименования, и если
 * запись не удалась — готовый архив удаляется, чтобы версия и архив не разошлись.
 */
$temp = $distDir . '/.building-' . ( $max + 1 ) . '.zip';

/**
 * Прерывает сборку: удаляет временный файл, печатает ошибку в STDERR.
 *
 * @param string $message Текст ошибки.
 * @param string $temp    Путь временного архива.
 */
function build_fail( string $message, string $temp ): never {
	if ( is_file( $temp ) ) {
		unlink( $temp );
	}

	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

if ( is_file( $temp ) ) {
	unlink( $temp );
}

$zip = new ZipArchive();

if ( true !== $zip->open( $temp, ZipArchive::CREATE | ZipArchive::EXCL ) ) {
	build_fail( "Не удалось создать временный архив {$temp}.", $temp );
}

foreach ( $files as $relative => $absolute ) {
	if ( 'wp-mlp.php' === $relative ) {
		$added = $zip->addFromString( 'wp-mlp/' . $relative, $source );
	} elseif ( 'readme.txt' === $relative && null !== $readmeNew ) {
		$added = $zip->addFromString( 'wp-mlp/' . $relative, $readmeNew );
	} else {
		$added = $zip->addFile( $absolute, 'wp-mlp/' . $relative );
	}

	if ( true !== $added ) {
		$zip->close();
		build_fail( "Не удалось добавить в архив: {$relative}.", $temp );
	}
}

if ( true !== $zip->close() ) {
	build_fail( 'Не удалось закрыть архив: ' . $zip->getStatusString(), $temp );
}

if ( file_exists( $target ) ) {
	build_fail( "Файл уже существует: {$target}", $temp );
}

if ( ! rename( $temp, $target ) ) {
	build_fail( "Не удалось переименовать {$temp} в {$target}.", $temp );
}

if ( ! $noBump ) {
	$originalMain = (string) file_get_contents( $mainFile );

	if ( false === file_put_contents( $mainFile, $source ) ) {
		unlink( $target );
		fwrite( STDERR, "Не удалось записать wp-mlp.php, архив удалён.\n" );
		exit( 1 );
	}

	if ( null !== $readmeNew && $readmeNew !== $readmeSource && false === file_put_contents( $readmeFile, $readmeNew ) ) {
		// Откат wp-mlp.php, чтобы версия и Stable tag не разошлись.
		file_put_contents( $mainFile, $originalMain );
		unlink( $target );
		fwrite( STDERR, "Не удалось записать readme.txt, wp-mlp.php возвращён, архив удалён.\n" );
		exit( 1 );
	}
}

printf(
	"Архив: %s\nВерсия: %s\nФайлов: %d\nРазмер: %.1f КБ\n",
	$target,
	$version,
	count( $files ),
	filesize( $target ) / 1024
);

echo "Внимание: .env в папке плагина на сервере будет удалён при замене через ZIP — ключ храните в настройках, wp-config.php, wp-content/wp-mlp.env.php (первая строка <?php exit; ?>) или wp-mlp.env выше корня WordPress.
";
