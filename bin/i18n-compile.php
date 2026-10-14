<?php
/**
 * Компиляция переводов плагина без gettext: .po -> .mo + .l10n.php (WordPress 6.5+).
 *
 * Запуск:
 *   php bin/i18n-compile.php [languages/wp-mlp-ru_RU.po ...]   (по умолчанию все languages/*.po)
 *   php bin/i18n-compile.php --pot languages/wp-mlp-ru_RU.po   (пишет languages/wp-mlp.pot)
 *
 * Для каждого .po рядом пишутся `<name>.mo` (GNU, little-endian) и `<name>.l10n.php`
 * (формат WP_Translation_File_PHP: заголовки в нижнем регистре + ключ `messages`).
 * Ключ: контекст как "ctxt\4msgid", множественное число как "singular\0plural";
 * значение: формы через "\0". Fuzzy, пустые и устаревшие (#~) записи пропускаются.
 * Вывод детерминирован (сортировка по байтам ключа).
 *
 * Проверки: набор printf-плейсхолдеров в msgstr должен совпадать с msgid (иначе
 * предупреждение в STDERR и код выхода 1); одинаковые msgctxt+msgid с разными
 * msgstr - ошибка.
 */

declare( strict_types=1 );

/**
 * Раскодирует содержимое строки PO (без внешних кавычек).
 */
function wp_mlp_i18n_unescape( string $s ): string {
	return (string) preg_replace_callback(
		'/\\\\(.)/s',
		static function ( array $m ): string {
			switch ( $m[1] ) {
				case 'n':
					return "\n";
				case 't':
					return "\t";
				case 'r':
					return "\r";
				case '"':
					return '"';
				case '\\':
					return '\\';
				default:
					return $m[0];
			}
		},
		$s
	);
}

/**
 * Достаёт текст из строки вида `"..."`.
 */
function wp_mlp_i18n_unquote( string $line ): string {
	$line = trim( $line );
	if ( strlen( $line ) >= 2 && '"' === $line[0] && '"' === substr( $line, -1 ) ) {
		$line = substr( $line, 1, -1 );
	}
	return wp_mlp_i18n_unescape( $line );
}

/**
 * Парсит содержимое .po.
 *
 * @return array{header: array<string,string>, entries: list<array{ctxt: ?string, id: string, plural: ?string, str: array<int,string>, fuzzy: bool, refs: list<string>, comments: list<string>}>}
 */
function wp_mlp_i18n_parse_po( string $content ): array {
	$content = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $content );
	$lines   = preg_split( '/\r\n|\n|\r/', $content );
	$lines   = false === $lines ? array() : $lines;

	$entries = array();
	$cur     = null;
	$field   = null; // array{0: string, 1: int}
	$state   = 'none';

	$new = static function (): array {
		return array(
			'ctxt'     => null,
			'id'       => '',
			'plural'   => null,
			'str'      => array(),
			'fuzzy'    => false,
			'refs'     => array(),
			'comments' => array(),
			'has_id'   => false,
		);
	};

	$flush = static function () use ( &$entries, &$cur, &$field, &$state ): void {
		if ( null !== $cur && $cur['has_id'] ) {
			unset( $cur['has_id'] );
			if ( array() === $cur['str'] ) {
				$cur['str'] = array( 0 => '' );
			}
			ksort( $cur['str'] );
			$entries[] = $cur;
		}
		$cur   = null;
		$field = null;
		$state = 'none';
	};

	foreach ( $lines as $raw ) {
		$line = trim( $raw );
		if ( '' === $line ) {
			$flush();
			continue;
		}
		if ( str_starts_with( $line, '#~' ) ) {
			continue;
		}
		if ( '#' === $line[0] ) {
			if ( 'str' === $state ) {
				$flush();
			}
			if ( null === $cur ) {
				$cur = $new();
			}
			if ( str_starts_with( $line, '#:' ) ) {
				$cur['refs'][] = trim( substr( $line, 2 ) );
			} elseif ( str_starts_with( $line, '#,' ) ) {
				$flags = array_map( 'trim', explode( ',', substr( $line, 2 ) ) );
				if ( in_array( 'fuzzy', $flags, true ) ) {
					$cur['fuzzy'] = true;
				}
			} elseif ( str_starts_with( $line, '#.' ) ) {
				$cur['comments'][] = trim( substr( $line, 2 ) );
			}
			continue;
		}
		if ( '"' === $line[0] ) {
			if ( null !== $cur && null !== $field ) {
				$text = wp_mlp_i18n_unquote( $line );
				if ( 'str' === $field[0] ) {
					$cur['str'][ $field[1] ] = ( $cur['str'][ $field[1] ] ?? '' ) . $text;
				} elseif ( 'ctxt' === $field[0] ) {
					$cur['ctxt'] = ( $cur['ctxt'] ?? '' ) . $text;
				} else {
					$cur[ $field[0] ] = ( $cur[ $field[0] ] ?? '' ) . $text;
				}
			}
			continue;
		}
		if ( 1 === preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr)(?:\[(\d+)\])?\s*(".*")?\s*$/s', $line, $m ) ) {
			$kw   = $m[1];
			$idx  = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
			$text = isset( $m[3] ) ? wp_mlp_i18n_unquote( $m[3] ) : '';

			if ( 'str' === $state && in_array( $kw, array( 'msgctxt', 'msgid' ), true ) ) {
				$flush();
			}
			if ( null === $cur ) {
				$cur = $new();
			}
			switch ( $kw ) {
				case 'msgctxt':
					$cur['ctxt'] = $text;
					$field       = array( 'ctxt', 0 );
					$state       = 'ctxt';
					break;
				case 'msgid':
					$cur['id']     = $text;
					$cur['has_id'] = true;
					$field         = array( 'id', 0 );
					$state         = 'id';
					break;
				case 'msgid_plural':
					$cur['plural'] = $text;
					$field         = array( 'plural', 0 );
					$state         = 'id';
					break;
				default:
					$cur['str'][ $idx ] = $text;
					$field              = array( 'str', $idx );
					$state              = 'str';
			}
		}
	}
	$flush();

	$header = array();
	$out    = array();
	foreach ( $entries as $e ) {
		if ( '' === $e['id'] && null === $e['ctxt'] && null === $e['plural'] ) {
			foreach ( explode( "\n", $e['str'][0] ?? '' ) as $hl ) {
				$pos = strpos( $hl, ':' );
				if ( false !== $pos ) {
					$header[ trim( substr( $hl, 0, $pos ) ) ] = trim( substr( $hl, $pos + 1 ) );
				}
			}
			continue;
		}
		$out[] = $e;
	}

	return array(
		'header'  => $header,
		'entries' => $out,
	);
}

/**
 * Ключ записи в .mo / .l10n.php.
 */
function wp_mlp_i18n_key( array $e ): string {
	$key = null !== $e['ctxt'] ? $e['ctxt'] . "\x04" . $e['id'] : $e['id'];
	if ( null !== $e['plural'] ) {
		$key .= "\0" . $e['plural'];
	}
	return $key;
}

/**
 * Printf-плейсхолдеры строки.
 *
 * 'u' — безпозиционные (%s, %d) как отсортированный список с повторами (мультимножество);
 * 'n' — позиционные (%1$s) как карта «номер => тип», порядок в строке не важен.
 *
 * @return array{u: list<string>, n: array<int,string>}
 */
function wp_mlp_i18n_placeholders( string $s ): array {
	$s = str_replace( '%%', '', $s );
	preg_match_all( '/%(?:(\d+)\$)?[-+ 0]*(?:\'.)?\d*(?:\.\d+)?([bcdeEfFgGosuxX])/', $s, $m, PREG_SET_ORDER );
	$u = array();
	$n = array();
	foreach ( $m as $hit ) {
		if ( '' !== $hit[1] ) {
			$n[ (int) $hit[1] ] = $hit[2];
		} else {
			$u[] = '%' . $hit[2];
		}
	}
	sort( $u, SORT_STRING );
	ksort( $n );
	return array(
		'u' => $u,
		'n' => $n,
	);
}

/**
 * Человекочитаемая запись набора плейсхолдеров.
 *
 * @param array{u: list<string>, n: array<int,string>} $p Набор.
 */
function wp_mlp_i18n_format_placeholders( array $p ): string {
	$parts = $p['u'];
	foreach ( $p['n'] as $num => $type ) {
		$parts[] = '%' . $num . '$' . $type;
	}
	return implode( ' ', $parts );
}

/**
 * Проверка плейсхолдеров записи. Возвращает список текстов ошибок.
 *
 * @return list<string>
 */
function wp_mlp_i18n_check_placeholders( array $e ): array {
	$errors = array();
	$idSet  = wp_mlp_i18n_placeholders( $e['id'] );
	$plSet  = null !== $e['plural'] ? wp_mlp_i18n_placeholders( $e['plural'] ) : $idSet;
	// Для множественного числа допустимо всё, что есть хотя бы в одной из форм исходника.
	$srcN = $idSet['n'] + $plSet['n'];
	$srcU = array_count_values( $idSet['u'] );
	foreach ( array_count_values( $plSet['u'] ) as $type => $cnt ) {
		$srcU[ $type ] = max( $srcU[ $type ] ?? 0, $cnt );
	}
	foreach ( $e['str'] as $i => $str ) {
		if ( '' === $str ) {
			continue;
		}
		$got = wp_mlp_i18n_placeholders( $str );
		if ( null === $e['plural'] ) {
			// Безпозиционные — то же мультимножество; позиционные — те же номера с теми же типами.
			$bad = $got['u'] !== $idSet['u'] || $got['n'] !== $idSet['n'];
		} else {
			// Формы множественного числа могут опускать число, но не требовать лишних аргументов.
			$bad = false;
			foreach ( array_count_values( $got['u'] ) as $type => $cnt ) {
				if ( $cnt > ( $srcU[ $type ] ?? 0 ) ) {
					$bad = true;
				}
			}
			foreach ( $got['n'] as $num => $type ) {
				if ( ( $srcN[ $num ] ?? null ) !== $type ) {
					$bad = true;
				}
			}
		}
		if ( $bad ) {
			$expected = null === $e['plural']
				? wp_mlp_i18n_format_placeholders( $idSet )
				: wp_mlp_i18n_format_placeholders(
					array(
						'u' => count( $plSet['u'] ) > count( $idSet['u'] ) ? $plSet['u'] : $idSet['u'],
						'n' => $srcN,
					)
				);
			$errors[] = sprintf(
				'Несовпадение плейсхолдеров в msgstr[%d] для "%s": ожидалось [%s], получено [%s]',
				$i,
				$e['id'],
				$expected,
				wp_mlp_i18n_format_placeholders( $got )
			);
		}
	}
	return $errors;
}

/**
 * Собирает пары key => translation (с проверками).
 *
 * @return array{messages: array<string,string>, errors: list<string>}
 */
function wp_mlp_i18n_messages( array $parsed ): array {
	$messages = array();
	$errors   = array();
	foreach ( $parsed['entries'] as $e ) {
		if ( $e['fuzzy'] ) {
			continue;
		}
		$forms = array_values( $e['str'] );
		if ( array() === $forms || in_array( '', $forms, true ) ) {
			continue;
		}
		$errors = array_merge( $errors, wp_mlp_i18n_check_placeholders( $e ) );
		$key    = wp_mlp_i18n_key( $e );
		$value  = implode( "\0", $forms );
		if ( array_key_exists( $key, $messages ) ) {
			if ( $messages[ $key ] !== $value ) {
				$errors[] = sprintf( 'Дубликат msgctxt+msgid с разным msgstr: "%s"', $e['id'] );
			}
			continue;
		}
		$messages[ $key ] = $value;
	}
	uksort(
		$messages,
		static fn( $a, $b ): int => strcmp( (string) $a, (string) $b )
	);
	return array(
		'messages' => $messages,
		'errors'   => $errors,
	);
}

/**
 * Строка GNU .mo (little-endian, без хеш-таблицы). Заголовок идёт ключом "".
 *
 * @param array<string,string> $messages Отсортированные key => translation.
 * @param array<string,string> $header   Заголовки .po.
 */
function wp_mlp_i18n_build_mo( array $messages, array $header ): string {
	$all = array();
	if ( array() !== $header ) {
		$hs = '';
		foreach ( $header as $k => $v ) {
			$hs .= $k . ': ' . $v . "\n";
		}
		$all[''] = $hs;
	}
	foreach ( $messages as $k => $v ) {
		$all[ (string) $k ] = $v;
	}
	uksort(
		$all,
		static fn( $a, $b ): int => strcmp( (string) $a, (string) $b )
	);

	$n       = count( $all );
	$origTab = '';
	$trTab   = '';
	$origStr = '';
	$trStr   = '';
	$base    = 28 + 16 * $n;
	$origOff = $base;
	$origLen = 0;
	foreach ( $all as $k => $_ ) {
		$origLen += strlen( (string) $k ) + 1;
	}
	$trOff = $origOff + $origLen;

	foreach ( $all as $k => $v ) {
		$k        = (string) $k;
		$origTab .= pack( 'VV', strlen( $k ), $origOff + strlen( $origStr ) );
		$origStr .= $k . "\0";
		$trTab   .= pack( 'VV', strlen( $v ), $trOff + strlen( $trStr ) );
		$trStr   .= $v . "\0";
	}

	// Магия 0x950412de little-endian как байты (переносимо на 32-битный PHP).
	return pack( 'H*', 'de120495' ) . pack( 'VVVVVV', 0, $n, 28, 28 + 8 * $n, 0, $base ) .$origTab . $trTab . $origStr . $trStr;
}

/**
 * Содержимое <name>.l10n.php.
 *
 * @param array<string,string> $messages
 * @param array<string,string> $header
 */
function wp_mlp_i18n_build_php( array $messages, array $header, string $language ): string {
	$data = array(
		'domain'             => $header['X-Domain'] ?? 'wp-mlp',
		'plural-forms'       => $header['Plural-Forms'] ?? 'nplurals=2; plural=(n != 1);',
		'language'           => $language,
		'project-id-version' => $header['Project-Id-Version'] ?? '',
	);
	$out  = "<?php\n// Generated by bin/i18n-compile.php. Do not edit.\nreturn array(\n";
	foreach ( $data as $k => $v ) {
		$out .= "\t" . var_export( $k, true ) . ' => ' . var_export( $v, true ) . ",\n";
	}
	$out .= "\t'messages' => array(\n";
	foreach ( $messages as $k => $v ) {
		$out .= "\t\t" . var_export( (string) $k, true ) . ' => ' . var_export( $v, true ) . ",\n";
	}
	$out .= "\t),\n);\n";
	return $out;
}

/**
 * Язык: заголовок Language или хвост имени файла (wp-mlp-ru_RU.po -> ru_RU).
 */
function wp_mlp_i18n_language( array $header, string $poPath ): string {
	if ( ! empty( $header['Language'] ) ) {
		return $header['Language'];
	}
	$base = basename( $poPath, '.po' );
	return 1 === preg_match( '/-([a-z]{2,3}(?:_[A-Za-z]+)*)$/', $base, $m ) ? $m[1] : $base;
}

/**
 * Строка в кавычках PO; многострочные значения разбиваются по \n.
 */
function wp_mlp_i18n_quote( string $s ): string {
	$esc = static fn( string $p ): string => '"' . str_replace( array( '\\', '"', "\n", "\t", "\r" ), array( '\\\\', '\\"', '\\n', '\\t', '\\r' ), $p ) . '"';
	if ( false === strpos( $s, "\n" ) || strlen( $s ) === strpos( $s, "\n" ) + 1 && substr_count( $s, "\n" ) === 1 ) {
		return $esc( $s );
	}
	$parts = preg_split( '/(?<=\n)/', $s, -1, PREG_SPLIT_NO_EMPTY );
	return "\"\"\n" . implode( "\n", array_map( $esc, $parts ) );
}

/**
 * Содержимое .pot из разобранного .po.
 */
function wp_mlp_i18n_build_pot( array $parsed ): string {
	$h   = $parsed['header'];
	$n   = 3;
	$pf  = $h['Plural-Forms'] ?? '';
	if ( 1 === preg_match( '/nplurals\s*=\s*(\d+)/', $pf, $m ) ) {
		$n = (int) $m[1];
	}
	$hdr  = "Project-Id-Version: WP Multilang Press\n";
	$hdr .= "Report-Msgid-Bugs-To: https://github.com/Lightwell-bg/translatepress/issues\n";
	$hdr .= 'POT-Creation-Date: ' . ( $h['POT-Creation-Date'] ?? 'YEAR-MO-DA HO:MI+ZONE' ) . "\n";
	$hdr .= "PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\n";
	$hdr .= "Last-Translator: FULL NAME <EMAIL@ADDRESS>\n";
	$hdr .= "Language-Team: LANGUAGE <LL@li.org>\n";
	$hdr .= "MIME-Version: 1.0\n";
	$hdr .= "Content-Type: text/plain; charset=UTF-8\n";
	$hdr .= "Content-Transfer-Encoding: 8bit\n";
	$hdr .= "X-Domain: wp-mlp\n";

	$out  = "# Translation template for WP Multilang Press.\n";
	$out .= "msgid \"\"\nmsgstr " . wp_mlp_i18n_quote( $hdr ) . "\n";

	$seen = array();
	foreach ( $parsed['entries'] as $e ) {
		$key = wp_mlp_i18n_key( $e );
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
		$out         .= "\n";
		foreach ( $e['comments'] as $c ) {
			$out .= '#. ' . $c . "\n";
		}
		foreach ( $e['refs'] as $r ) {
			$out .= '#: ' . $r . "\n";
		}
		if ( null !== $e['ctxt'] ) {
			$out .= 'msgctxt ' . wp_mlp_i18n_quote( $e['ctxt'] ) . "\n";
		}
		$out .= 'msgid ' . wp_mlp_i18n_quote( $e['id'] ) . "\n";
		if ( null !== $e['plural'] ) {
			$out .= 'msgid_plural ' . wp_mlp_i18n_quote( $e['plural'] ) . "\n";
			for ( $i = 0; $i < $n; $i++ ) {
				$out .= 'msgstr[' . $i . "] \"\"\n";
			}
		} else {
			$out .= "msgstr \"\"\n";
		}
	}
	return $out;
}

/**
 * Обрабатывает один .po. Возвращает сводку; $errors заполняется предупреждениями.
 *
 * @return array{entries: int, written: list<string>, errors: list<string>}
 */
function wp_mlp_i18n_process( string $poPath, bool $pot, ?string $potPath = null ): array {
	$content = file_get_contents( $poPath );
	if ( false === $content ) {
		return array(
			'entries' => 0,
			'written' => array(),
			'errors'  => array( 'Не удалось прочитать ' . $poPath ),
		);
	}
	$parsed  = wp_mlp_i18n_parse_po( $content );
	$written = array();
	if ( $pot ) {
		$potPath = $potPath ?? dirname( $poPath ) . '/wp-mlp.pot';
		file_put_contents( $potPath, wp_mlp_i18n_build_pot( $parsed ) );
		return array(
			'entries' => count( $parsed['entries'] ),
			'written' => array( $potPath ),
			'errors'  => array(),
		);
	}

	$res      = wp_mlp_i18n_messages( $parsed );
	$dir      = dirname( $poPath );
	$name     = basename( $poPath, '.po' );
	$mo       = $dir . '/' . $name . '.mo';
	$php      = $dir . '/' . $name . '.l10n.php';
	$language = wp_mlp_i18n_language( $parsed['header'], $poPath );
	file_put_contents( $mo, wp_mlp_i18n_build_mo( $res['messages'], $parsed['header'] ) );
	file_put_contents( $php, wp_mlp_i18n_build_php( $res['messages'], $parsed['header'], $language ) );
	$written = array( $mo, $php );

	return array(
		'entries' => count( $res['messages'] ),
		'written' => $written,
		'errors'  => $res['errors'],
	);
}

/**
 * Точка входа CLI.
 *
 * @param list<string> $argv
 */
function wp_mlp_i18n_main( array $argv ): int {
	$args  = array_slice( $argv, 1 );
	$pot   = in_array( '--pot', $args, true );
	$files = array_values( array_filter( $args, static fn( string $a ): bool => '--pot' !== $a ) );
	if ( array() === $files ) {
		$files = glob( dirname( __DIR__ ) . '/languages/*.po' ) ?: array();
		sort( $files );
	}
	if ( array() === $files ) {
		fwrite( STDERR, "Нет .po файлов.\n" );
		return 1;
	}
	if ( $pot ) {
		$files = array( $files[0] );
	}

	$failed = false;
	foreach ( $files as $file ) {
		$r = wp_mlp_i18n_process( $file, $pot );
		echo $file . ': ' . $r['entries'] . " entries\n";
		foreach ( $r['written'] as $w ) {
			echo '  -> ' . $w . "\n";
		}
		foreach ( $r['errors'] as $err ) {
			fwrite( STDERR, '  ! ' . $err . "\n" );
			$failed = true;
		}
	}
	return $failed ? 1 : 0;
}

if ( 'cli' === PHP_SAPI && realpath( $argv[0] ?? '' ) === __FILE__ ) {
	exit( wp_mlp_i18n_main( $argv ) );
}
