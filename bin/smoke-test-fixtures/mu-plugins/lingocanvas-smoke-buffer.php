<?php
/**
 * Smoke-test fixture (mu-plugin, mounted by bin/smoke-test.sh for scenario S1 only).
 * Not part of the plugin ZIP.
 *
 * Inert unless the render step sets $GLOBALS['MLP_SMOKE_BUFFER_MODE'] before loading WordPress:
 *   trace        only record what the output buffers look like (no behaviour change);
 *   core_off     another plugin disables the WordPress 6.9+ template enhancement buffer, so the
 *                plugin must fall back to its own buffer;
 *   open_buffer  the template leaves an ob_start() unclosed;
 *   exit         the template calls exit after printing the page;
 *   late_template another plugin replaces the template at template_include priority PHP_INT_MAX
 *                (a full HTML document using wp_head/the_content/wp_footer).
 *
 * The record goes to /out/buffer-<page>.json (page = $GLOBALS['MLP_SMOKE_PAGE']), written at
 * wp_footer, i.e. while the page is still being buffered: the list of active output handlers,
 * whether core started its buffer and the WordPress version.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$lingocanvas_smoke_mode = $GLOBALS['MLP_SMOKE_BUFFER_MODE'] ?? null;

if ( is_string( $lingocanvas_smoke_mode ) && '' !== $lingocanvas_smoke_mode ) {
	$lingocanvas_smoke_page = (string) ( $GLOBALS['MLP_SMOKE_PAGE'] ?? 'page' );

	if ( 'core_off' === $lingocanvas_smoke_mode ) {
		add_filter( 'wp_should_output_buffer_template_for_enhancement', '__return_false', PHP_INT_MAX );
	}

	if ( 'open_buffer' === $lingocanvas_smoke_mode || 'exit' === $lingocanvas_smoke_mode ) {
		// Below the plugin's own template_include priority (PHP_INT_MAX), so the plugin wraps this template.
		add_filter(
			'template_include',
			static function ( $template ) use ( $lingocanvas_smoke_mode ) {
				if ( ! is_string( $template ) ) {
					return $template;
				}

				$GLOBALS['lingocanvas_smoke_real_template'] = $template;
				$file                                       = rtrim( sys_get_temp_dir(), '/' ) . '/lingocanvas-smoke-template-' . $lingocanvas_smoke_mode . '.php';
				$code                                       = 'open_buffer' === $lingocanvas_smoke_mode
					? "<?php\nob_start();\ninclude \$GLOBALS['lingocanvas_smoke_real_template'];\n"
					: "<?php\ninclude \$GLOBALS['lingocanvas_smoke_real_template'];\nexit;\n";

				file_put_contents( $file, $code );

				return $file;
			},
			PHP_INT_MAX - 1
		);
	}

	if ( 'late_template' === $lingocanvas_smoke_mode ) {
		// Another plugin or theme layer that replaces the template at the very last priority: the plugin's
		// own filter is added later (template_redirect), at the same priority, so it still runs after this one.
		$lingocanvas_smoke_tpl = rtrim( sys_get_temp_dir(), '/' ) . '/lingocanvas-smoke-template-late.php';
		$lingocanvas_smoke_src = "<?php\n"
			. "defined( 'ABSPATH' ) || exit;\n"
			. "?><!doctype html>\n<html <?php language_attributes(); ?>><head><meta charset=\"utf-8\"><?php wp_head(); ?></head>"
			. "<body><main><?php while ( have_posts() ) { the_post(); the_content(); } ?></main><?php wp_footer(); ?></body></html>\n";

		file_put_contents( $lingocanvas_smoke_tpl, $lingocanvas_smoke_src );

		add_filter(
			'template_include',
			static function () use ( $lingocanvas_smoke_tpl ): string {
				return $lingocanvas_smoke_tpl;
			},
			PHP_INT_MAX
		);
	}

	add_action(
		'wp_footer',
		static function () use ( $lingocanvas_smoke_page, $lingocanvas_smoke_mode ): void {
			file_put_contents(
				'/out/buffer-' . $lingocanvas_smoke_page . '.json',
				json_encode( // phpcs:ignore
					array(
						'mode'        => $lingocanvas_smoke_mode,
						'template'    => (string) ( $GLOBALS['template'] ?? '' ),
						'wp_version'  => get_bloginfo( 'version' ),
						'core_started' => (int) did_action( 'wp_template_enhancement_output_buffer_started' ),
						'handlers'    => ob_list_handlers(),
					)
				)
			);
		},
		PHP_INT_MAX
	);
}

unset( $lingocanvas_smoke_mode, $lingocanvas_smoke_page );
