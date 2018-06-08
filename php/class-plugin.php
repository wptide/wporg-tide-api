<?php
/**
 * Bootstraps the WPOrg Tide API plugin.
 *
 * @package WPOrg_Tide_API
 */

namespace WPOrg_Tide_API;

/**
 * Main plugin bootstrap file.
 */
class Plugin extends Plugin_Base {

	/**
	 * Initiate the plugin resources.
	 *
	 * @action init
	 */
	public function init() {
		$this->config = apply_filters( 'wporg_tide_api_plugin_config', $this->config, $this );
	}
}
