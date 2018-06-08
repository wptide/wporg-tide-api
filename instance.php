<?php
/**
 * Instantiates the WPOrg Tide API plugin
 *
 * @package WPOrg_Tide_API
 */

namespace WPOrg_Tide_API;

global $wporg_tide_api_plugin;

require_once __DIR__ . '/php/class-plugin-base.php';
require_once __DIR__ . '/php/class-plugin.php';

$wporg_tide_api_plugin = new Plugin();

/**
 * WPOrg Tide API Plugin Instance
 *
 * @return Plugin
 */
function get_plugin_instance() {
	global $wporg_tide_api_plugin;
	return $wporg_tide_api_plugin;
}
