<?php
/**
 * Сборка ZIP плагина для загрузки в WordPress.
 *
 * Запуск: `php bin/build-zip.php [--no-bump] [--dry-run]`
 *
 * 1. Поднимает patch-версию в wp-mlp.php (заголовок `Version:` и константа
 *    WP_MLP_VERSION); `--no-bump` пропускает этот шаг.
 * 2. Берёт следующий номер N после максимального `_<N>wp-mlp.zip` в dist/.
 * 3. Кладёт в архив `wp-mlp/` только рантайм: wp-mlp.php, uninstall.php,
 *    README.md, src/, assets/, languages/. Ни tests, docs, bin, vendor
 *    (у плагина свой PSR-4 автозагрузчик), ни dotfiles.
 *
 * `--dry-run` только печатает список файлов: ничего не пишет и не меняет версию.
 * Существующий архив никогда не перезаписывается.
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

// Файлы рантайма: относительный путь => абсолютный.
$files = array();

foreach ( array( 'wp-mlp.php', 'uninstall.php', 'README.md' ) as $name ) {
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
	$added = 'wp-mlp.php' === $relative
		? $zip->addFromString( 'wp-mlp/' . $relative, $source )
		: $zip->addFile( $absolute, 'wp-mlp/' . $relative );

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

if ( ! $noBump && false === file_put_contents( $mainFile, $source ) ) {
	unlink( $target );
	fwrite( STDERR, "Не удалось записать wp-mlp.php, архив удалён.\n" );
	exit( 1 );
}

printf(
	"Архив: %s\nВерсия: %s\nФайлов: %d\nРазмер: %.1f КБ\n",
	$target,
	$version,
	count( $files ),
	filesize( $target ) / 1024
);
