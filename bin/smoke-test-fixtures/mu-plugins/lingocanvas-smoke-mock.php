<?php
/**
 * Smoke-test fixture (mu-plugin, mounted by bin/smoke-test.sh for scenario S1 only).
 * Not part of the plugin ZIP.
 *
 * 1. Defines a FAKE OpenAI key + model through the wp-config constants the plugin reads.
 * 2. Intercepts every request to .../chat/completions (pre_http_request) and answers with a
 *    well-formed chat.completions body: the user message is a JSON object id => source text,
 *    the reply content is a JSON object with the same ids and "MOCK-EN: <source>" values.
 *    No network access happens.
 * 3. Counts calls of the deprecated filter alias mlp_sitemap_post_ids and of its replacement
 *    lingocanvas_sitemap_post_ids (one line per call in /out/hook-old.txt and /out/hook-new.txt).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LINGOCANVAS_SMOKE_FAKE_KEY = 'sk-smoke-FAKEKEY-9f8e7d6c5b4a';

if ( ! defined( 'WP_MLP_OPENAI_API_KEY' ) ) {
	define( 'WP_MLP_OPENAI_API_KEY', LINGOCANVAS_SMOKE_FAKE_KEY );
}

if ( ! defined( 'WP_MLP_OPENAI_MODEL' ) ) {
	define( 'WP_MLP_OPENAI_MODEL', 'smoke-model' );
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( ! is_string( $url ) || ! str_ends_with( rtrim( strtok( $url, '?' ), '/' ), '/chat/completions' ) ) {
			return $pre;
		}

		$body    = json_decode( (string) ( $args['body'] ?? '' ), true );
		$user    = is_array( $body ) ? (string) ( $body['messages'][1]['content'] ?? '' ) : '';
		$items   = json_decode( $user, true );
		$reply   = array();
		$headers = (array) ( $args['headers'] ?? array() );

		foreach ( is_array( $items ) ? $items : array() as $id => $text ) {
			$reply[ (string) $id ] = 'MOCK-EN: ' . $text;
		}

		file_put_contents(
			'/out/mock-calls.jsonl',
			json_encode( // phpcs:ignore
				array(
					'url'         => $url,
					'model'       => is_array( $body ) ? ( $body['model'] ?? '' ) : '',
					'auth_header' => isset( $headers['Authorization'] ) ? 'Bearer ***' : 'MISSING',
					'key_sent'    => ( $headers['Authorization'] ?? '' ) === 'Bearer ' . LINGOCANVAS_SMOKE_FAKE_KEY,
					'items'       => count( $reply ),
				)
			) . "\n",
			FILE_APPEND
		);

		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => json_encode( // phpcs:ignore
				array(
					'id'      => 'chatcmpl-smoke',
					'object'  => 'chat.completion',
					'choices' => array(
						array(
							'index'         => 0,
							'finish_reason' => 'stop',
							'message'       => array(
								'role'    => 'assistant',
								'content' => json_encode( $reply, JSON_UNESCAPED_UNICODE ), // phpcs:ignore
							),
						),
					),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

add_filter(
	'mlp_sitemap_post_ids',
	static function ( $ids ) {
		file_put_contents( '/out/hook-old.txt', "1\n", FILE_APPEND );

		return $ids;
	}
);

add_filter(
	'lingocanvas_sitemap_post_ids',
	static function ( $ids ) {
		file_put_contents( '/out/hook-new.txt', "1\n", FILE_APPEND );

		return $ids;
	}
);
