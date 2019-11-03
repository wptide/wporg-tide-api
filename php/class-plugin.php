<?php
/**
 * Bootstraps the WPOrg Tide API plugin.
 *
 * @package WPOrg_Tide_API
 */

namespace WPOrg_Tide_API;

use WP_Tide_API\API\Controller\Audit_Posts_Controller;
use WP_Tide_API\API\Endpoint\Audit;
use WP_Tide_API\Utility\User;

/**
 * Main plugin bootstrap file.
 */
class Plugin extends Plugin_Base {

	/**
	 * $handled determines if a post has already been handled to prevent possible looping.
	 *
	 * @var bool
	 */
	public static $handled = false;

	/**
	 * Intercept the post response to inject webhook like features for wp.org.
	 *
	 * @param \WP_Post|\WP_Error $post    The post object.
	 * @param \WP_REST_Request   $request The original request.
	 *
	 * @filter tide_api_get_altid_post 10, 2
	 *
	 * @return \WP_Error | \WP_Post
	 */
	public function tide_api_get_altid_post( $post, $request ) {

		// Prevent possible loops because of internal requests.
		if ( static::$handled ) {
			return $post;
		}

		// Not a GET request.
		if ( 'GET' !== $request->get_method() ) {
			return $post;
		}

		// Not a wporg project.
		if ( 'wporg' !== $request->get_param( 'project_client' ) ) {
			return $post;
		}

		// Missing project_type param.
		if ( null === $request->get_param( 'project_type' ) ) {
			return $post;
		}

		// Missing project_slug param.
		if ( null === $request->get_param( 'project_slug' ) ) {
			return $post;
		}

		// Missing version param.
		if ( null === $request->get_param( 'version' ) ) {
			return $post;
		}

		// The fact that we got this far means the user should exist, but we need the ID.
		$user = get_user_by( 'login', $request->get_param( 'project_client' ) );

		// User doesn't exist yet.
		if ( ! isset( $user->ID ) ) {
			return $post;
		}

		// Handle request.
		if ( $post instanceof \WP_Post ) {
			return $this->handle_existing_post( $post, $request );
		} else {
			return $this->handle_non_existing_post( $post, $request, $user->ID );
		}
	}

	/**
	 * For non-wporg clients, just return the post. Else, rerun the audit.
	 *
	 * @param \WP_Post         $post    The existing post.
	 * @param \WP_REST_Request $request The original request.
	 *
	 * @return mixed
	 */
	public function handle_existing_post( $post, $request ) {

		$current_user = wp_get_current_user();
		$is_author    = (
			! is_wp_error( $current_user )
			&&
			isset( $post->post_author ) && absint( $post->post_author ) === absint( $current_user->ID )
			&&
			$current_user->has_cap( 'api_client' )
		);

		// If this is not the authenticated `wporg` user return the post.
		if ( ! $is_author ) {
			return $post;
		}

		// Get project slug from 'audit_project' taxonomy.
		$terms = wp_get_post_terms( $post->ID, 'audit_project' );
		$slug  = '';
		if ( ! is_wp_error( $terms ) && isset( $terms[0]->name ) ) {
			$slug = $terms[0]->name;
		}

		// Must have an `audit_project` taxonomy slug associated with the post.
		if ( empty( $slug ) ) {
			return $post;
		}

		// Get relevant meta fields.
		$project_type = get_post_meta( $post->ID, 'project_type', true );
		$source_url   = get_post_meta( $post->ID, 'source_url', true );
		$source_type  = get_post_meta( $post->ID, 'source_type', true );
		$standards    = get_post_meta( $post->ID, 'standards', true );
		$standards    = maybe_unserialize( $standards );
		$visibility   = get_post_meta( $post->ID, 'visibility', true );

		// Create a new audit requests.
		$this->dispatch_new_request(
			$post,
			$post->post_title,
			$post->post_content,
			$source_url,
			$source_type,
			$project_type,
			$slug,
			$visibility,
			true,
			$standards
		);

		if( class_exists( 'Redis_Page_Cache' ) ) {
			$cache          = new Redis_Page_Cache();
			$plugin_version = $request->get_param( 'version' );

			$url_to_clear = get_rest_url( null, sprintf( 'tide/v1/audit/wporg/%s/%s/%s', $project_type, $slug, $plugin_version ) );

			$cache::clear_cache_by_url( $url_to_clear );
		}

		// Consider this request handled!
		static::$handled = true;

		return $post;
	}

	/**
	 * Stub out post if it does not exist and add to the queue.
	 *
	 * Note: Only if the corresponding project can be found via the WordPress Repo APIs.
	 *
	 * @param \WP_Error        $error   A post error.
	 * @param \WP_REST_Request $request The original request.
	 * @param int              $user_id The wporg user id.
	 *
	 * @return mixed
	 */
	public function handle_non_existing_post( $error, $request, $user_id ) {

		// This error is not of our concern so pass it on.
		if ( isset( $error->errors ) && ! array_key_exists( 'rest_post_invalid_altid_lookup', $error->errors ) ) {
			return $error;
		}

		$project_type = $request->get_param( 'project_type' );
		$slug         = $request->get_param( 'project_slug' );
		$version      = $request->get_param( 'version' );
		$standards    = array_keys( Audit::executable_audit_fields() );
		$standards    = Audit::filter_standards( $standards );
		$source_url   = sprintf( 'https://downloads.wordpress.org/%s/%s.%s.zip', $project_type, $slug, $version );
		$source_type  = 'zip';
		$visibility   = 'public';

		// If this does not exist in the WP.org repository then don't do anything. Pass it on.
		if ( ! $this->exists_in_repo( $project_type, $slug, $version ) ) {
			return $error;
		}

		// Remove lighthouse if this is not a theme.
		$lh_key = array_search( 'lighthouse', $standards );
		if ( 'theme' !== $project_type && false !== $lh_key ) {
			unset( $standards[ $lh_key ] );
		}

		// Resetting array keys.
		$standards = array_values( $standards );

		// Insert a stubbed out post.
		$post_id = wp_insert_post( [
			'post_author'    => $user_id,
			'post_content'   => 'pending',
			'post_title'     => $slug,
			'post_status'    => 'publish',
			'post_type'      => 'audit',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
			'meta_input'     => array(
				'project_type' => $project_type,
				'source_url'   => $source_url,
				'source_type'  => $source_type,
				'standards'    => $standards,
				'version'      => $version,
				'visibility'   => $visibility,
			),
		] );

		// Add the slug.
		wp_add_object_terms( $post_id, $slug, 'audit_project' );

		// Get the new post.
		$post = get_post( $post_id );

		// Create new audit request.
		$this->dispatch_new_request(
			$post,
			$slug,
			'pending',
			$source_url,
			$source_type,
			$project_type,
			$slug,
			$visibility,
			false,
			$standards
		);

		// Consider this request handled!
		static::$handled = true;

		return $post;
	}

	/**
	 * Get a new \WP_REST_Request object for the audit.
	 *
	 * @codeCoverageIgnore
	 *
	 * @param \WP_Post $post         The WP post to dispatch.
	 * @param string   $title        Title of the audit.
	 * @param string   $content      The content/description of the project.
	 * @param string   $source_url   Where the project can be downloaded from.
	 * @param string   $source_type  This will usually be zip.
	 * @param string   $project_type Theme or Plugin.
	 * @param string   $slug         Slug of the project (translated to term).
	 * @param string   $visibility   Project is private or public.
	 * @param bool     $force        Force an audit for audit servers who honor this.
	 * @param array    $standards    An array of standards. Leave empty to use defaults.
	 *
	 * @return void
	 */
	public function dispatch_new_request( $post, $title, $content, $source_url, $source_type, $project_type, $slug, $visibility = 'public', $force = false, $standards = [] ) {

		$audit_request = new \WP_REST_Request( \WP_REST_Server::CREATABLE, 'tide/v1/audit' );

		$audit_request->set_param( 'title', $title );
		$audit_request->set_param( 'content', $content );
		$audit_request->set_param( 'source_url', $source_url );
		$audit_request->set_param( 'source_type', $source_type );
		$audit_request->set_param( 'project_type', $project_type );
		$audit_request->set_param( 'slug', $slug );
		$audit_request->set_param( 'visibility', $visibility );
		$audit_request->set_param( 'force', $force );
		$audit_request->set_param( 'standards', $standards );
		$audit_request->set_param( 'request_client', 'wporg' );

		// Send the new request to the audit post controller.
		$controller = new Audit_Posts_Controller( 'audit' );
		$controller->create_audit_request( $audit_request, $post, $standards );
	}

	/**
	 * Does this object exist in the WordPress repository?
	 *
	 * @param string $type    Project type - theme|plugin.
	 * @param string $slug    Project slug.
	 * @param string $version Project version.
	 *
	 * @return bool Does it?
	 */
	public function exists_in_repo( $type, $slug, $version ) {
		$url = sprintf( 'https://api.wordpress.org/%s/info/1.1/?action=%s_information&request[slug]=%s&request[fields][versions]=1&request[fields][description]=0',
			$type . 's',
			$type,
			$slug
		);

		$response = wp_remote_post( $url );

		if ( is_wp_error( $response ) || empty( $response['body'] ) ) {
			return false;
		}

		$body = json_decode( $response['body'], true );

		if ( ! isset( $body['versions'] ) || ! array_key_exists( $version, $body['versions'] ) ) {
			return false;
		}

		return true;
	}
}
