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
	 * Initiate the plugin resources.
	 *
	 * @action init
	 */
	public function init() {
		$this->config = apply_filters( 'wporg_tide_api_plugin_config', $this->config, $this );
	}

	/**
	 * Intercept the post response to inject webhook'like features for wp.org.
	 *
	 * @param \WP_Post|\WP_Error $post    The post object.
	 * @param \WP_REST_Request   $request The original request.
	 *
	 * @filter tide_api_get_altid_post 10, 2
	 *
	 * @return \WP_Error | \WP_Post
	 */
	public function tide_api_get_altid_post( $post, $request ) {

		// If its not a wporg project its not of our concern. Pass it on.
		if ( 'wporg' !== $request->get_param( 'project_client' ) ) {
			return $post;
		}

		// The fact that we got this far means the user exists. We just need the ID.
		$user    = get_user_by( 'login', $request->get_param( 'project_client' ) );
		$user_id = $user->ID;

		// Handle wporg web hooks.
		if ( ! is_wp_error( $post ) ) {
			return $this->handle_existing_post( $post, $request, $user_id );
		} else {
			return $this->handle_non_existing_post( $post, $request, $user_id );
		}
	}

	/**
	 * For non-wporg clients, just return the post. Else, rerun the audit.
	 *
	 * @todo This needs to be implemented still.
	 *
	 * @param \WP_Post         $post    The existing post.
	 * @param \WP_REST_Request $request The original request.
	 * @param int              $user_id The wporg user id.
	 *
	 * @return mixed
	 */
	public function handle_existing_post( $post, $request, $user_id ) {

		// If not authenticated user with capabilities then just send the post.
		if ( ! User::has_cap( 'alter_dot_org_project' ) ) {
			return $post;
		}

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
		if ( ! array_key_exists( 'rest_post_invalid_altid_lookup', $error->errors ) ) {
			return $error;
		}

		$project_type = $request->get_param( 'project_type' );
		$slug         = $request->get_param( 'project_slug' );
		$version      = $request->get_param( 'version' );
		$standards    = array_keys( Audit::executable_audit_fields() );
		$standards    = Audit::filter_standards( $standards );
		$source_url   = sprintf( 'https://downloads.wordpress.org/%s/%s.%s.zip', $project_type, $slug, $version );
		$source_type  = 'zip';

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
		] );

		// Update the required meta.
		update_post_meta( $post_id, 'project_type', $project_type );
		update_post_meta( $post_id, 'source_url', $source_url );
		update_post_meta( $post_id, 'source_type', $source_type );
		update_post_meta( $post_id, 'standards', $standards );
		update_post_meta( $post_id, 'version', $version );
		update_post_meta( $post_id, 'visibility', 'public' );
		wp_add_object_terms( $post_id, $slug, 'audit_project' );

		// Initiate new audit request.
		$post          = get_post( $post_id );
		$audit_request = new \WP_REST_Request( \WP_REST_Server::CREATABLE, $request->get_route() );
		$controller    = new Audit_Posts_Controller( 'audit' );
		$audit_request->set_param( 'title', $slug );
		$audit_request->set_param( 'content', 'pending' );
		$audit_request->set_param( 'source_url', $source_url );
		$audit_request->set_param( 'source_type', $source_type );
		$audit_request->set_param( 'project_type', $project_type );
		$audit_request->set_param( 'force', false );
		$audit_request->set_param( 'visibility', 'public' );
		$audit_request->set_param( 'slug', $slug );

		$controller->create_audit_request( $audit_request, $post, $standards );

		return $post;
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
	private function exists_in_repo( $type, $slug, $version ) {
		$url = sprintf( 'https://api.wordpress.org/%s/info/1.1/?action=%s_information&request[slug]=%s&request[fields][versions]=1&request[fields][description]=0',
			$type . 's',
			$type,
			$slug
		);

		$response = wp_remote_post( $url );

		if ( is_wp_error( $response ) || empty( $response['body'] ) ) {
			return false;
		}

		$versions = json_decode( $response['body'], true )['versions'];

		if ( ! array_key_exists( $version, $versions ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Enable new WP.org client capabilities.
	 *
	 * @action edit_user_profile
	 *
	 * @param \WP_User $user The user who's profile is getting viewed.
	 */
	public function user_profile_fields( $user ) {
		?>
		<h2><?php esc_html_e( 'WordPress.org Tide API Integration', 'tide-api' ); ?></h2>
		<table class="form-table">
			<tbody>
			<tr>
				<th>
					<?php esc_html_e( 'Authorized to Alter', 'tide-api' ); ?>
				</th>
				<td>
					<label for="alter-wporg-projects">
						<input
								class="regular-text"
								name="alter-wporg-projects"
								id="alter-wporg-projects"
								type="checkbox"
							<?php checked( user_can( $user, 'alter_dot_org_project' ) ); ?>
						/>
						<?php esc_html_e( 'User is able to alter repo projects.', 'tide-api' ); ?>
					</label>
				</td>
			</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Update user profile.
	 *
	 * @action edit_user_profile_update
	 *
	 * @param int $user_id The user ID.
	 */
	public function edit_user_profile_update( $user_id ) {
		if ( current_user_can( 'edit_user', $user_id ) ) {
			$user = new \WP_User( $user_id );
			if ( array_key_exists( 'alter-wporg-projects', $_POST ) ) {
				$user->add_cap( 'alter_dot_org_project' );
			} else {
				$user->remove_cap( 'alter_dot_org_project' );
			}
		}
	}
}
