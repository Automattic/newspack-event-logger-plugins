<?php
/**
 * WP_CLI\Utils namespace stubs for testing.
 */

namespace WP_CLI\Utils;

if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
	function format_items( $format, $items, $fields ) {
		// Store for test assertions.
		$GLOBALS['_wp_test_cli_format_items'] = [
			'format' => $format,
			'items'  => $items,
			'fields' => $fields,
		];
	}
}
