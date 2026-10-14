<?php
/**
 * Simulates one anonymous front-end GET request inside Playground (the HTTP loopback of
 * the Playground CLI does not reach WordPress from a blueprint step). The blueprint sets
 * $_SERVER['REQUEST_URI'] and $MLP_SMOKE_PAGE before requiring this file.
 * Writes /out/page-<name>.html and /out/page-<name>.json (status, Location).
 */

$mlp_page = (string) ( $GLOBALS['MLP_SMOKE_PAGE'] ?? 'page' );

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = is_readable( '/out/site-host.txt' ) ? trim( (string) file_get_contents( '/out/site-host.txt' ) ) : ( $_SERVER['HTTP_HOST'] ?? 'localhost' );
$_GET                      = array();
$_POST                     = array();
$_COOKIE                   = array();

ob_start(
	static function ( string $html ) use ( $mlp_page ): string {
		file_put_contents( '/out/page-' . $mlp_page . '.html', $html );

		return '';
	}
);

register_shutdown_function(
	static function () use ( $mlp_page ): void {
		$location = '';
		$ctype    = '';

		foreach ( headers_list() as $h ) {
			if ( 0 === stripos( $h, 'Location:' ) ) {
				$location = trim( substr( $h, 9 ) );
			}
			if ( 0 === stripos( $h, 'Content-Type:' ) ) {
				$ctype = trim( substr( $h, 13 ) );
			}
		}

		file_put_contents(
			'/out/page-' . $mlp_page . '.json',
			json_encode( // phpcs:ignore
				array(
					'status'       => (int) http_response_code(),
					'location'     => $location,
					'content_type' => $ctype,
					'headers'      => headers_list(),
					'host'         => (string) ( $_SERVER['HTTP_HOST'] ?? '' ),
				)
			)
		);
	}
);

define( 'WP_USE_THEMES', true );
require '/wordpress/wp-blog-header.php';
