<?php
/**
 * Test_WPOrg_Tide_API
 *
 * @package WPOrg_Tide_API
 */

namespace WPOrg_Tide_API;

/**
 * Class Test_WPOrg_Tide_API
 *
 * @package WPOrg_Tide_API
 */
class Test_WPOrg_Tide_API extends \WP_UnitTestCase {

	/**
	 * Test _wporg_tide_api_php_version_error().
	 *
	 * @see _wporg_tide_api_php_version_error()
	 */
	public function test_wporg_tide_api_php_version_error() {
		ob_start();
		_wporg_tide_api_php_version_error();
		$buffer = ob_get_clean();
		$this->assertContains( '<div class="error">', $buffer );
	}

	/**
	 * Test _wporg_tide_api_php_version_text().
	 *
	 * @see _wporg_tide_api_php_version_text()
	 */
	public function test_wporg_tide_api_php_version_text() {
		$this->assertContains( 'WPOrg Tide API plugin error:', _wporg_tide_api_php_version_text() );
	}
}
