<?php
/**
 * Tests for Plugin class.
 *
 * @package WPOrg_Tide_API
 */

namespace WPOrg_Tide_API;

/**
 * Tests for Plugin class.
 *
 * @package WPOrg_Tide_API
 */
class Test_Plugin extends \WP_UnitTestCase {

	/**
	 * Test constructor.
	 *
	 * @see Plugin::__construct()
	 */
	public function test_construct() {
		$plugin = new Plugin();
		$this->assertEquals( 10, has_action( 'init', array( $plugin, 'init' ) ) );
	}

	/**
	 * Test for init() method.
	 *
	 * @see Plugin::init()
	 */
	public function test_init() {
		$plugin = get_plugin_instance();

		add_filter( 'wporg_tide_api_plugin_config', array( $this, 'filter_config' ), 10, 2 );
		$plugin->init();

		$this->assertInternalType( 'array', $plugin->config );
		$this->assertArrayHasKey( 'foo', $plugin->config );
	}

	/**
	 * Filter to test 'wporg_tide_api_plugin_config'.
	 *
	 * @see Plugin::init()
	 * @param array       $config Plugin config.
	 * @param Plugin_Base $plugin Plugin instance.
	 * @return array
	 */
	public function filter_config( $config, $plugin ) {
		unset( $config, $plugin ); // Test should actually use these.
		return array( 'foo' => 'bar' );
	}

	/* Put other test functions here... */
}
