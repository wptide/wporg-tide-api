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
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * Setup.
	 *
	 * @inheritdoc
	 */
	public function setUp() {
		parent::setUp();
		$this->plugin = get_plugin_instance();
	}

	/**
	 * Test tide_api_get_altid_post.
	 *
	 * @see Plugin::tide_api_get_altid_post()
	 */
	public function test_tide_api_get_altid_post() {
		$request = new \WP_REST_Request( 'POST', 'tide/v1/audit/wporg/plugin/akismet/4.1' );
		$request->set_param( 'project_client', 'not-wporg' );

		$this->plugin::$handled = true;
		$this->assertTrue( $this->plugin->tide_api_get_altid_post( true, $request ) );
		$this->plugin::$handled = false;

		$this->assertTrue( $this->plugin->tide_api_get_altid_post( true, $request ) );
		$request = new \WP_REST_Request( 'GET', 'tide/v1/audit/wporg/plugin/akismet/4.1' );

		$this->assertTrue( $this->plugin->tide_api_get_altid_post( true, $request ) );

		$request->set_param( 'project_client', 'wporg' );
		$this->assertTrue( $this->plugin->tide_api_get_altid_post( true, $request ) );

		$request->set_param( 'project_type', 'plugin' );
		$this->assertTrue( $this->plugin->tide_api_get_altid_post( true, $request ) );

		$request->set_param( 'project_slug', 'akismet' );
		$this->assertTrue( $this->plugin->tide_api_get_altid_post( true, $request ) );

		$request->set_param( 'version', '4.1' );
		$this->assertTrue( $this->plugin->tide_api_get_altid_post( true, $request ) );

		$user_data = array(
			'role'       => 'api_client',
			'user_login' => 'wporg',
			'user_pass'  => 'password',
		);
		$this->factory->user->create( $user_data );

		$obj             = new \stdClass();
		$obj->post_title = 'Exists';
		$post            = new \WP_Post( $obj );

		$mock = $this->getMockBuilder( get_class( $this->plugin ) )
			->setMethods(
				array(
					'handle_existing_post',
					'handle_non_existing_post',
				)
			)
			->getMock();
		$mock->method( 'handle_existing_post' )->willReturn( $post );
		$mock->method( 'handle_non_existing_post' )->willReturn( 'created' );

		$this->assertEquals( 'created', $mock->tide_api_get_altid_post( new \WP_Error(), $request ) );
		$this->assertEquals( $post, $mock->tide_api_get_altid_post( $post, $request ) );
	}

	/**
	 * Test handle_existing_post.
	 *
	 * @see Plugin::handle_existing_post()
	 */
	public function test_handle_existing_post() {
		$request  = new \WP_REST_Request( 'GET', 'tide/v1/audit/wporg/plugin/akismet/4.1' );
		$taxonomy = 'audit_project';
		$term     = 'akismet';
		register_taxonomy( $taxonomy, null );

		// No user found.
		$this->assertTrue( $this->plugin->handle_existing_post( true, $request ) );

		$user_data = array(
			'role'       => 'api_client',
			'user_login' => 'wporg',
			'user_pass'  => 'password',
		);
		$user_id   = $this->factory->user->create( $user_data );

		$post_id = $this->factory()->post->create(
			array(
				'post_title'   => 'akismet',
				'post_content' => '',
				'post_status'  => 'publish',
				'post_type'    => 'audit',
				'post_author'  => $user_id,
				'meta_input'   => array(
					'project_type' => 'plugin',
					'source_url'   => 'https://downloads.wordpress.org/plugin/akismet.4.1.zip',
					'source_type'  => 'zip',
					'standards'    => array(
						'phpcs_phpcompatibility',
						'phpcs_wordpress',
					),
					'visibility'   => 'public',
				),
			)
		);

		$post = get_post( $post_id );

		// No slug.
		wp_set_current_user( $user_id );
		$this->assertEquals( $post, $this->plugin->handle_existing_post( $post, $request ) );

		wp_insert_term( $term, $taxonomy );
		wp_add_object_terms( $post_id, $term, $taxonomy );

		$mock = $this->getMockBuilder( get_class( $this->plugin ) )
			->setMethods(
				array(
					'dispatch_new_request',
				)
			)
			->getMock();
		$mock->method( 'dispatch_new_request' )->willReturn( null );

		$response = $mock->handle_existing_post( $post, $request );
		$this->assertEquals( $post->ID, $response->ID );
		$this->assertTrue( $mock::$handled );
		$mock::$handled = false;
	}

	/**
	 * Test handle_non_existing_post.
	 *
	 * @see Plugin::handle_non_existing_post()
	 */
	public function test_handle_non_existing_post() {
		$request   = new \WP_REST_Request( 'GET', 'tide/v1/audit/wporg/plugin/akismet/4' );
		$error     = new \WP_Error( 'some_error' );
		$taxonomy  = 'audit_project';
		$term      = 'akismet';
		$user_data = array(
			'role'       => 'api_client',
			'user_login' => 'wporg',
			'user_pass'  => 'password',
		);
		$user_id   = $this->factory->user->create( $user_data );
		$this->assertEquals( $error, $this->plugin->handle_non_existing_post( $error, $request, $user_id ) );

		$this->assertTrue( $this->plugin->handle_non_existing_post( true, $request, $user_id ) );

		$request = new \WP_REST_Request( 'GET', 'tide/v1/audit/wporg/plugin/akismet/4.1' );

		$request->set_param( 'project_client', 'wporg' );
		$request->set_param( 'project_type', 'plugin' );
		$request->set_param( 'project_slug', 'akismet' );
		$request->set_param( 'version', '4.1' );

		register_taxonomy( $taxonomy, null );

		$mock = $this->getMockBuilder( get_class( get_plugin_instance() ) )
			->setMethods(
				array(
					'dispatch_new_request',
					'exists_in_repo',
				)
			)
			->getMock();
		$mock->method( 'dispatch_new_request' )->willReturn( null );
		$mock->method( 'exists_in_repo' )->willReturn( true );

		$this->assertFalse( $mock::$handled );
		$response = $mock->handle_non_existing_post( null, $request, $user_id );
		$this->assertEquals( 'plugin', get_post_meta( $response->ID, 'project_type', true ) );
		$this->assertTrue( $mock::$handled );
	}

	/**
	 * Test exists_in_repo.
	 *
	 * @see Plugin::exists_in_repo()
	 */
	public function test_exists_in_repo() {
		$this->assertTrue( $this->plugin->exists_in_repo( 'plugin', 'akismet', '4.1' ) );

		// Test invalid version.
		$this->assertFalse( $this->plugin->exists_in_repo( 'plugin', 'akismet', '0.1' ) );

		// Test missing `body`.
		$function = function( $response, $r, $url ) {
			return array();
		};
		add_filter( 'pre_http_request', $function, 10, 3 );
		$this->assertFalse( $this->plugin->exists_in_repo( 'plugin', 'akismet', '0.1' ) );
		remove_filter( 'pre_http_request', $function, 10, 3 );
	}
}
