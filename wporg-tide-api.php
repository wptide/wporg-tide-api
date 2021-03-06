<?php
/**
 * Plugin Name: WPOrg Tide API
 * Plugin URI: https://github.com/xwp/wp-wporg-tide-api
 * Description: This plugin extends the WP Tide API for the needs of WordPress.org.
 * Version: 1.0.0-beta
 * Author:  XWP
 * Author URI: https://xwp.co/
 * License: GPLv2+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: wporg-tide-api
 * Domain Path: /languages
 *
 * Copyright (c) 2018 XWP (https://xwp.co/)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2 or, at
 * your discretion, any later version, as published by the Free
 * Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
 *
 * @package WPOrg_Tide_API
 */

if ( version_compare( phpversion(), '7.2', '>=' ) ) {
	require_once __DIR__ . '/instance.php';
} else {
	if ( defined( 'WP_CLI' ) ) {
		WP_CLI::warning( _wporg_tide_api_php_version_text() );
	} else {
		add_action( 'admin_notices', '_wporg_tide_api_php_version_error' );
	}
}

/**
 * Admin notice for incompatible versions of PHP.
 */
function _wporg_tide_api_php_version_error() {
	printf( '<div class="error"><p>%s</p></div>', esc_html( _wporg_tide_api_php_version_text() ) );
}

/**
 * String describing the minimum PHP version.
 *
 * @return string
 */
function _wporg_tide_api_php_version_text() {
	return __( 'WPOrg Tide API plugin error: Your version of PHP is too old to run this plugin. You must be running PHP 7.2 or higher.', 'wporg-tide-api' );
}
