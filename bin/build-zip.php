<?php
/**
 * Сборка ZIP плагина для загрузки в WordPress.
 *
 * Запуск: `php bin/build-zip.php [--no-bump] [--dry-run]`
 *
 * 1. Поднимает patch-версию в lingocanvas.php (заголовок `Version:` и константа
 *    LINGOCANVAS_VERSION) и `Stable tag:` в readme.txt (если он есть);
 *    `--no-bump` пропускает этот шаг.
 * 2. Имя архива: `dist/lingocanvas-<версия>.zip`; существующий файл не перезаписывается.
 * 3. Кладёт в архив `lingocanvas/` только рантайм: lingocanvas.php, uninstall.php,
 *    readme.txt (если есть), src/, assets/, languages/. Ни tests, docs, bin,
 *    vendor (у плагина свой PSR-4 автозагрузчик), ни README.md, ни скрытых
 *    файлов (.env, .env.example и т. п.: скрытые файлы — ошибка Plugin Check).
 * 4. Сборка падает, если в списке оказался скрытый файл или любой `.env*`,
 *    а также если `Stable tag` в readme.txt не равен `Version` плагина.
 *
 * `--dry-run` только печатает список файлов: ничего не пишет и не меняет версию.
 * Существующий архив никогда не перезаписывается. readme.txt и lingocanvas.php
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

$mainFile = $root . '/lingocanvas.php';
$source   = (string) file_get_contents( $mainFile );

if ( 1 !== preg_match( '/^(\s*\*\s*Version:\s*)(\d+)\.(\d+)\.(\d+)\s*$/m', $source, $m ) ) {
	fwrite( STDERR, "Не найдена строка Version: в lingocanvas.php.\n" );
	exit( 1 );
}

$version = "{$m[2]}.{$m[3]}.{$m[4]}";

if ( ! $noBump ) {
	$version = "{$m[2]}.{$m[3]}." . ( (int) $m[4] + 1 );
	$updated = preg_replace( '/^(\s*\*\s*Version:\s*)\d+\.\d+\.\d+/m', '${1}' . $version, $source, 1 );
	$updated = preg_replace( "/(const LINGOCANVAS_VERSION = ')\d+\.\d+\.\d+(')/", '${1}' . $version . '${2}', (string) $updated, 1, $count );

	if ( 1 !== $count ) {
		fwrite( STDERR, "Не найдена константа LINGOCANVAS_VERSION в lingocanvas.php.\n" );
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

foreach ( array( 'lingocanvas.php', 'uninstall.php', 'readme.txt' ) as $name ) {
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

// Имя архива задаётся версией плагина.
$distDir = $root . '/dist';
$target  = $distDir . '/lingocanvas-' . $version . '.zip';

if ( file_exists( $target ) ) {
	fwrite( STDERR, "Файл уже существует: {$target}\n" );
	exit( 1 );
}

if ( $dryRun ) {
	foreach ( array_keys( $files ) as $relative ) {
		echo 'lingocanvas/' . $relative . "\n";
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
 * под `lingocanvas-<версия>.zip`), lingocanvas.php кладётся из уже поднятой версии в памяти.
 * Исходный lingocanvas.php правится только после успешного переименования, и если
 * запись не удалась — готовый архив удаляется, чтобы версия и архив не разошлись.
 */
$temp = $distDir . '/.building-' . $version . '.zip';

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
	if ( 'lingocanvas.php' === $relative ) {
		$added = $zip->addFromString( 'lingocanvas/' . $relative, $source );
	} elseif ( 'readme.txt' === $relative && null !== $readmeNew ) {
		$added = $zip->addFromString( 'lingocanvas/' . $relative, $readmeNew );
	} else {
		$added = $zip->addFile( $absolute, 'lingocanvas/' . $relative );
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
	/**
	 * Атомарно заменяет файл: пишет во временный файл рядом, сверяет размер, переименовывает.
	 *
	 * @param string $path    Путь файла.
	 * @param string $content Новое содержимое.
	 */
	function build_replace_file( string $path, string $content ): bool {
		$tmp     = $path . '.tmp-' . getmypid();
		$written = file_put_contents( $tmp, $content );

		if ( strlen( $content ) !== $written || ! rename( $tmp, $path ) ) {
			if ( is_file( $tmp ) ) {
				unlink( $tmp );
			}

			return false;
		}

		return true;
	}

	// Оригиналы держим в памяти: при любом сбое возвращаем ОБА файла и удаляем новый архив.
	$originalMain   = (string) file_get_contents( $mainFile );
	$originalReadme = is_file( $readmeFile ) ? (string) file_get_contents( $readmeFile ) : null;

	$failed = '';

	if ( ! build_replace_file( $mainFile, $source ) ) {
		$failed = 'lingocanvas.php';
	} elseif ( null !== $readmeNew && $readmeNew !== $readmeSource && ! build_replace_file( $readmeFile, $readmeNew ) ) {
		$failed = 'readme.txt';
	}

	if ( '' !== $failed ) {
		$problems = array();

		if ( ! build_replace_file( $mainFile, $originalMain ) ) {
			$problems[] = 'lingocanvas.php';
		}

		if ( null !== $originalReadme && ! build_replace_file( $readmeFile, $originalReadme ) ) {
			$problems[] = 'readme.txt';
		}

		if ( is_file( $target ) ) {
			unlink( $target );
		}

		fwrite( STDERR, "Не удалось записать {$failed}, архив удалён.\n" );

		if ( array() !== $problems ) {
			fwrite( STDERR, 'ВНИМАНИЕ: не удалось восстановить исходные файлы: ' . implode( ', ', $problems ) . ". Проверьте их вручную (git diff).\n" );
		}

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

echo "Внимание: .env в папке плагина на сервере будет удалён при замене через ZIP — ключ храните в настройках, wp-config.php, wp-content/wp-mlp.env.php (первая строка <?php exit; ?>) или wp-mlp.env выше корня WordPress (имена файлов окружения сохранены от прежнего плагина).
Если на сайте ещё активен прежний «WP Multilang Press» (папка wp-mlp/): загрузите этот ZIP как новый плагин LingoCanvas и активируйте его — прежний плагин будет выключен автоматически, данные общие.
";
