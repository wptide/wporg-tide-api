<?php
/**
 * Class Plugin_Base
 *
 * @package WPOrg_Tide_API
 */

namespace WPOrg_Tide_API;

/**
 * Class Plugin_Base
 *
 * @package WPOrg_Tide_API
 */
abstract class Plugin_Base {

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	public $slug;

	/**
	 * Plugin directory path.
	 *
	 * @var string
	 */
	public $dir_path;

	/**
	 * Plugin directory URL.
	 *
	 * @var string
	 */
	public $dir_url;

	/**
	 * Directory in plugin containing autoloaded classes.
	 *
	 * @var string
	 */
	protected $autoload_class_dir = 'php';

	/**
	 * Autoload matches cache.
	 *
	 * @var array
	 */
	protected $autoload_matches_cache = [];

	/**
	 * Required instead of a static variable inside the add_doc_hooks method
	 * for the sake of unit testing.
	 *
	 * @var array
	 */
	protected $_called_doc_hooks = [];

	/**
	 * Plugin_Base constructor.
	 */
	public function __construct() {
		$location       = $this->locate_plugin();
		$this->slug     = $location['dir_basename'];
		$this->dir_path = $location['dir_path'];
		$this->dir_url  = $location['dir_url'];
		spl_autoload_register( array( $this, 'autoload' ) );
		$this->add_doc_hooks();
	}

	/**
	 * Plugin_Base destructor.
	 */
	public function __destruct() {
		$this->remove_doc_hooks();
	}

	/**
	 * Get reflection object for this class.
	 *
	 * @return \ReflectionObject
	 */
	public function get_object_reflection() {
		static $reflection;
		if ( empty( $reflection ) ) {
			$reflection = new \ReflectionObject( $this );
		}

		return $reflection;
	}

	/**
	 * Autoload for classes that are in the same namespace as $this.
	 *
	 * @codeCoverageIgnore
	 *
	 * @param string $class Class name.
	 *
	 * @return void
	 */
	public function autoload( $class ) {

		$class_root_pattern = 'WPOrg_Tide_API';

		// If its not a WP_Tide_API class, exit now.
		if ( ! preg_match( '/^' . $class_root_pattern . '/', $class ) ) {
			return;
		}

		if ( ! isset( $this->found_matches[ $class ] ) ) {

			if ( ! preg_match( '/^(?P<namespace>.+)\\\\(?P<class>[^\\\\]+)$/', $class, $matches ) ) {
				$matches = false;
			}

			$this->found_matches[ $class ] = $matches;
		} else {
			$matches = $this->found_matches[ $class ];
		}

		$class_parts = explode( '\\', $matches['namespace'] );

		if ( ! empty( $class_parts ) && $class_root_pattern === $class_parts[0] ) {
			array_shift( $class_parts );
		} else {
			return;
		}

		foreach ( $class_parts as $key => $item ) {
			$class_parts[ $key ] = strtolower( implode( '-', array_filter( preg_split( '/(?=[A-Z])(?<![A-Z])/', $item ) ) ) );
		}

		$class_string = str_replace( '_', '', strtolower( implode( '-', array_filter( preg_split( '/(?=[A-Z])(?<![A-Z])/', $matches['class'] ) ) ) ) );
		$class_path   = ! empty( $class_parts ) ? implode( DIRECTORY_SEPARATOR, array_filter( $class_parts ) ) . DIRECTORY_SEPARATOR : '';
		$basedir      = $this->dir_path . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . $class_path;

		if ( ! empty( $class_string ) ) {

			// One last chance to override the filename.
			$filename = apply_filters( 'wporg_tide_api_class_file_override', $basedir . 'class-' . $class_string . '.php', $class );

			// Include it if it exists and we have access.
			if ( is_readable( $filename ) ) {
				include_once $filename;
			}
		}
	}

	/**
	 * Version of plugin_dir_url() which works for plugins installed in the plugins directory,
	 * and for plugins bundled with themes.
	 *
	 * @throws Exception If the plugin is not located in the expected location.
	 * @return array
	 */
	public function locate_plugin() {
		$file_name = $this->get_object_reflection()->getFileName();
		if ( '/' !== \DIRECTORY_SEPARATOR ) {
			$file_name = str_replace( \DIRECTORY_SEPARATOR, '/', $file_name ); // Windows compat.
		}

		$plugin_dir   = dirname( dirname( $file_name ) );
		$plugin_path  = $this->relative_path( $plugin_dir, basename( content_url() ), \DIRECTORY_SEPARATOR );
		$dir_url      = content_url( trailingslashit( $plugin_path ) );
		$dir_path     = $plugin_dir;
		$dir_basename = basename( $plugin_dir );

		return compact( 'dir_url', 'dir_path', 'dir_basename' );
	}

	/**
	 * Relative Path
	 *
	 * Returns a relative path from a specified starting position of a full path
	 *
	 * @param string $path  The full path to start with.
	 * @param string $start The directory after which to start creating the relative path.
	 * @param string $sep   The directory separator.
	 *
	 * @return string
	 */
	public function relative_path( $path, $start, $sep ) {
		$path = explode( $sep, untrailingslashit( $path ) );
		if ( count( $path ) > 0 ) {
			foreach ( $path as $p ) {
				array_shift( $path );
				if ( $p === $start ) {
					break;
				}
			}
		}

		return implode( $sep, $path );
	}

	/**
	 * Return whether we're on WordPress.com VIP production.
	 *
	 * @return bool
	 */
	public function is_wpcom_vip_prod() {
		return ( defined( '\WPCOM_IS_VIP_ENV' ) && \WPCOM_IS_VIP_ENV );
	}

	/**
	 * Call trigger_error() if not on VIP production.
	 *
	 * @param string $message Warning message.
	 * @param int    $code    Warning code.
	 */
	public function trigger_warning( $message, $code = \E_USER_WARNING ) {
		if ( ! $this->is_wpcom_vip_prod() ) {
			trigger_error( esc_html( get_class( $this ) . ': ' . $message ), $code );
		}
	}

	/**
	 * Hooks a function on to a specific filter.
	 *
	 * @param string $name     The hook name.
	 * @param array  $callback The class object and method.
	 * @param array  $args     An array with priority and arg_count.
	 *
	 * @return mixed
	 */
	public function add_filter( $name, $callback, $args = [] ) {
		$default_args = array(
			'priority'  => 10,
			'arg_count' => PHP_INT_MAX,
		);

		return $this->_add_hook( 'filter', $name, $callback, array_merge( $default_args, $args ) );
	}

	/**
	 * Hooks a function on to a specific action.
	 *
	 * @param string $name     The hook name.
	 * @param array  $callback The class object and method.
	 * @param array  $args     An array with priority and arg_count.
	 *
	 * @return mixed
	 */
	public function add_action( $name, $callback, $args = [] ) {
		$default_args = array(
			'priority'  => 10,
			'arg_count' => PHP_INT_MAX,
		);

		return $this->_add_hook( 'action', $name, $callback, array_merge( $default_args, $args ) );
	}

	/**
	 * Hooks a function on to a specific action/filter.
	 *
	 * @param string $type     The hook type. Options are action/filter.
	 * @param string $name     The hook name.
	 * @param array  $callback The class object and method.
	 * @param array  $args     An array with priority and arg_count.
	 *
	 * @return mixed
	 */
	protected function _add_hook( $type, $name, $callback, $args = [] ) {
		$priority  = isset( $args['priority'] ) ? $args['priority'] : 10;
		$arg_count = isset( $args['arg_count'] ) ? $args['arg_count'] : PHP_INT_MAX;
		$fn        = sprintf( '\add_%s', $type );
		$retval    = \call_user_func( $fn, $name, $callback, $priority, $arg_count );

		return $retval;
	}

	/**
	 * Add actions/filters from the methods of a class based on DocBlocks.
	 *
	 * @param object $object The class object.
	 */
	public function add_doc_hooks( $object = null ) {
		if ( is_null( $object ) ) {
			$object = $this;
		}
		$class_name = get_class( $object );
		if ( isset( $this->_called_doc_hooks[ $class_name ] ) ) {
			$notice = sprintf( 'The add_doc_hooks method was already called on %s. Note that the Plugin_Base constructor automatically calls this method.', $class_name );
			if ( ! $this->is_wpcom_vip_prod() ) {
				trigger_error( esc_html( $notice ), \E_USER_NOTICE );
			}

			return;
		}
		$this->_called_doc_hooks[ $class_name ] = true;

		$reflector = new \ReflectionObject( $object );
		foreach ( $reflector->getMethods() as $method ) {
			$doc       = $method->getDocComment();
			$arg_count = $method->getNumberOfParameters();
			if ( preg_match_all( '#\* @(?P<type>filter|action)\s+(?P<name>[a-z0-9\-\._/=]+)(?:,\s+(?P<priority>\-?[0-9]+))?#', $doc, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$type     = $match['type'];
					$name     = $match['name'];
					$priority = empty( $match['priority'] ) ? 10 : intval( $match['priority'] );
					$callback = array( $object, $method->getName() );
					call_user_func(
						array(
							$this,
							"add_{$type}",
						),
						$name,
						$callback,
						compact( 'priority', 'arg_count' )
					);
				}
			}
		}
	}

	/**
	 * Removes the added DocBlock hooks.
	 *
	 * @param object $object The class object.
	 */
	public function remove_doc_hooks( $object = null ) {
		if ( is_null( $object ) ) {
			$object = $this;
		}
		$class_name = get_class( $object );

		$reflector = new \ReflectionObject( $object );
		foreach ( $reflector->getMethods() as $method ) {
			$doc = $method->getDocComment();
			if ( preg_match_all( '#\* @(?P<type>filter|action)\s+(?P<name>[a-z0-9\-\._/=]+)(?:,\s+(?P<priority>\-?[0-9]+))?#', $doc, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$type     = $match['type'];
					$name     = $match['name'];
					$priority = empty( $match['priority'] ) ? 10 : intval( $match['priority'] );
					$callback = array( $object, $method->getName() );
					call_user_func( "remove_{$type}", $name, $callback, $priority );
				}
			}
		}
		unset( $this->_called_doc_hooks[ $class_name ] );
	}
}
