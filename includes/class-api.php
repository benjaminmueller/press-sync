<?php

class Press_Sync_API {

	/**
	 * Parent plugin class.
	 *
	 * @var   Press_Sync
	 * @since 0.1.0
	 */
	protected $plugin = null;

	/**
	 * Prefix for meta keys
	 *
	 * @var string
	 * @since  0.1.0
	 */
	protected $prefix = 'press_sync_api_';

	/**
	 * Constructor.
	 *
	 * @since  0.1.0
	 *
	 * @param  WDS_Fordham_Library_Calendar $plugin Main plugin object.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		$this->hooks();
	}

	/**
	 * Add our hooks.
	 *
	 * @since  0.1.0
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_api_endpoints' ) );
		add_action( 'press_sync_insert_new_post', array( $this, 'add_p2p_connections' ), 10, 2 );
	}

	/**
	 * Register the api endpoints
	 *
	 * @since 0.1.0
	 */
	public function register_api_endpoints() {

		register_rest_route( 'press-sync/v1', '/status', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_connection_status_via_api' ),
		) );

		register_rest_route( 'press-sync/v1', '/post', array(
			'methods' => 'POST',
			'callback' => array( $this, 'insert_new_post' ),
			'permission_callback' => array( $this, 'validate_sync_key' ),
		) );

		register_rest_route( 'press-sync/v1', '/page', array(
			'methods' => 'POST',
			'callback' => array( $this, 'insert_new_post' ),
			'permission_callback' => array( $this, 'validate_sync_key' ),
		) );

		register_rest_route( 'press-sync/v1', '/attachment', array(
			'methods' => 'POST',
			'callback' => array( $this, 'insert_new_media' ),
			'permission_callback' => array( $this, 'validate_sync_key' ),
		) );

		register_rest_route( 'press-sync/v1', '/user', array(
			'methods' => 'POST',
			'callback' => array( $this, 'insert_new_user' ),
			'permission_callback' => array( $this, 'validate_sync_key' ),
		) );

	}

	/**
	 * Gets the connection status via API request
	 *
	 * @since 0.1.0
	 * @return JSON
	 */
	public function get_connection_status_via_api() {

		if ( ! $this->validate_sync_key() ) {
			wp_send_json_error();
		}

		wp_send_json_success();
	}

	/**
	 * Validate the supplied press_sync_key by the sending server.
	 * Target server can't receive data without a valid press_sync_key.
	 *
	 * @since 0.1.0
	 */
	public function validate_sync_key() {

		$press_sync_key_from_remote = isset( $_REQUEST['press_sync_key'] ) ? $_REQUEST['press_sync_key'] : '';
		$press_sync_key = $this->plugin->press_sync_option('press_sync_key');

		if ( ! $press_sync_key || ( $press_sync_key_from_remote != $press_sync_key ) ) {
			return false;
		}

		return true;

	}

	public function insert_new_post( $request ) {

		$post_args 	= $request->get_params();
		$comments 	= $request->get_param('comments');

		if ( ! $post_args ) {
			return wp_send_json_error();
		}

		if ( $post = $this->post_exists( $post_args ) ) {

			// Attach media
			$this->attach_media( $post['ID'], $post_args );

			// Attach featured image
			$this->attach_featured_image( $post['ID'], $post_args );

			// Attach any comments
			$this->attach_comments( $post['ID'], $comments );

			// Insert Comments
			// $this->insert_comments( $post['ID'], $post_args );

			// Run any secondary commands
			do_action( 'press_sync_insert_new_post', $post['ID'], $post_args );

			// Check if the post has been modified
			if ( strtotime( $post_args['post_modified'] ) > strtotime( $post['post_modified'] ) ) {

				$post_args['ID'] = $post['ID'];

			} else {

				$data['id'] = $post['ID'];
				$data['message'] = 'post already exists';

				return wp_send_json_error( $data );

			}

		}

		// Check for post parent and update
		if ( isset( $post_args['post_parent'] ) && $post_parent_id = $post_args['post_parent'] ) {

			$post_parent_args['post_type'] = $post_args['post_type'];
			$post_parent_args['meta_input']['press_sync_post_id'] = $post_parent_id;

			$parent_post = $this->post_exists( $post_parent_args );

			$post_args['post_parent'] = ( $parent_post ) ? $parent_post['ID'] : 0;

		}

		$post_args['post_author'] = $this->get_press_sync_author_id( $post_args['post_author'] );

		$post_id = wp_insert_post( $post_args );

		if ( is_wp_error( $post_id ) ) {
			return wp_send_json_error( array( 'debug' => $post_id ) );
		}

		// Set taxonomies for custom post type
		if ( ! in_array( $post_args['post_type'], array( 'post', 'page' ) ) ) {

			if ( isset( $post_args['tax_input'] ) ) {

				foreach ( $post_args['tax_input'] as $taxonomy => $terms )	{
					wp_set_object_terms( $post_id, $terms, $taxonomy, false );
				}

			}

		}

		// Attach media
		$this->attach_media( $post_id, $post_args );

		// Attach featured image
		$this->attach_featured_image( $post_id, $post_args );

		// Attach any comments
		$this->attach_comments( $post_id, $comments );

		// Insert Comments
		// $this->insert_comments( $post_id, $post_args );

		// Run any secondary commands
		do_action( 'press_sync_insert_new_post', $post_id, $post_args );

		$data['id'] = $post_id;

		return wp_send_json_success( $data );

	}

	public function insert_new_media( $request, $return_local = false ) {

		$data['id'] = 0;

		$attachment_args = $request->get_params();

	    // Attachment URL does not exist so bail early.
	    if ( ! array_key_exists( 'guid', $attachment_args ) ) {
	    	return ( $return_local ) ? $data : wp_send_json_error( $data );
	    }

	    $attachment_url = $attachment_args['guid'];

		unset( $attachment_args['guid'] );

		require_once( ABSPATH . '/wp-admin/includes/image.php' );
	    require_once( ABSPATH . '/wp-admin/includes/file.php' );
	    require_once( ABSPATH . '/wp-admin/includes/media.php' );

		if ( $media_id = $this->media_exists( $attachment_url ) ) {

			$data['id'] = $media_id;
			$data['message'] = 'file already exists';

			return ( $return_local ) ? $data : wp_send_json_error( $data );

		}

		// Allow download_url() to use an external request to retrieve featured images.
		add_filter( 'http_request_host_is_external', array( $this, 'allow_sync_external_host' ), 10, 3 );

		// 1) Download the url
		$temp_file = download_url( $attachment_url, 5000 );

		// Remove filter that allowed an external request to be made via download_url().
		remove_filter( 'http_request_host_is_external', array( $this, 'allow_sync_external_host' ) );

		$file_array['name'] = basename( $attachment_url );
        $file_array['tmp_name'] = $temp_file;

         if ( is_wp_error( $temp_file ) ) {
	        @unlink( $file_array['tmp_name'] );
	        return ( $return_local ) ? $data : wp_send_json_error( $data );
	    }

		$attachment_id = media_handle_sideload( $file_array, 0, '', $attachment_args );

		// Check for handle sideload errors.
	    if ( is_wp_error( $attachment_id ) ) {
	        @unlink( $file_array['tmp_name'] );
	        return ( $return_local ) ? $data : wp_send_json_error( $data );
	    }

	    $data['id'] = $attachment_id;

		return ( $return_local ) ? $data : wp_send_json_success( $data );

	}

	public function insert_new_user( $request ) {

		$user_args = $request->get_params();
		$username = isset( $user_args['user_login'] ) ? $user_args['user_login'] : '';

		// Check to see if the user exists
		$user = get_user_by( 'login', $username );

		if ( ! $user ) {

			$user_id = wp_insert_user( $user_args );

			if ( is_wp_error( $user_id ) ) {
				return wp_send_json_error();
			}

			$user = get_user_by( 'id', $user_id );

		} else {
			$user_id = $user->ID;
		}

		// Update the meta
		foreach ( $user_args['meta_input'] as $usermeta_key => $usermeta_value ) {
			update_user_meta( $user_id, $usermeta_key, $usermeta_value );
		}

		// Asign user role
		$user->add_role( $user_args['role'] );

		// Prepare response
		$data['user_id'] = $user_id;

		return wp_send_json_success( $data );

	}

	public function post_exists( $post_args ) {

		$press_sync_post_id = isset( $post_args['meta_input']['press_sync_post_id'] ) ? $post_args['meta_input']['press_sync_post_id'] : 0;

		$query_args = array(
			'post_type' 		=> $post_args['post_type'],
			'posts_per_page' 	=> 1,
			'meta_key'			=> 'press_sync_post_id',
			'meta_value'		=> $press_sync_post_id,
			'post_status'		=> 'any',
		);

		$post = get_posts( $query_args );

		if ( $post ) {
			return (array) $post[0];
		}

		return false;

	}

	public function media_exists( $media_url ) {

		global $wpdb;

		$media_url = basename( $media_url );

		$sql = "SELECT ID FROM $wpdb->posts WHERE guid LIKE '%%%s%%' LIMIT 1;";
		$prepared_sql = $wpdb->prepare( $sql, $media_url );

		$media_id = $wpdb->get_var( $prepared_sql );

		if ( $media_id ) {
			return $media_id;
		}

		return 0;

	}

	public function comment_exists( $comment_args = array() ) {

		$press_sync_comment_id 	= isset( $comment_args['meta_input']['press_sync_comment_id'] ) ? $comment_args['meta_input']['press_sync_comment_id'] : 0;
		$press_sync_source 		= isset( $comment_args['meta_input']['press_sync_source'] ) ? $comment_args['meta_input']['press_sync_source'] : 0;

		$query_args = array(
			'number'		=> 1,
			'meta_query' 	=> array(
				array(
					'key'     => 'press_sync_comment_id',
					'value'   => $press_sync_comment_id,
					'compare' => '='
				),
				array(
					'key'     => 'press_sync_source',
					'value'   => $press_sync_source,
					'compare' => '='
				),
			)
		);

		$comment = get_comments( $query_args );

		if ( $comment ) {
			return (array) $comment[0];
		}

		return false;

	}

	public function get_post_by_orig_id( $press_sync_post_id ) {

		global $wpdb;

		$sql = "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'press_sync_post_id' AND meta_value = $press_sync_post_id";

		return $wpdb->get_var( $sql );

	}

	public function get_press_sync_author_id( $user_id ) {

		if ( ! $user_id ) {
			return 0;
		}

		$args = array(
			'fields'		=> array('ID'),
			'meta_key'		=> 'press_sync_user_id',
			'meta_value'	=> $user_id
		);

		$user = get_users( $args );

		if ( $user ) {
			return $user[0]->ID;
		}

		return $user_id;

	}

	public function attach_media( $post_id, $post_args ) {
		if ( empty( $post_id ) || empty( $post_args['attached_media'] ) ) {
			return false;
		}

		foreach ( $post_args['attached_media'] as $attachment_id => $attachment_args ) {

			if ( ! empty( $attachment_args['post_parent'] ) ) {
				$attachment_args['post_parent'] = $post_id;
			}

			$request = new WP_REST_Request( 'POST' );
			$request->set_body_params( $attachment_args );

			$attachment = $this->insert_new_media( $request, true );
		}
	}

	public function attach_featured_image( $post_id, $post_args ) {
		// Post does not have a featured image so bail early.
		if ( empty( $post_args['featured_image'] ) ) {
			return false;
		}

		$request = new WP_REST_Request( 'POST' );
		$request->set_body_params( $post_args['featured_image'] );

		// Download the attachment
		$attachment 	= $this->insert_new_media( $request, true );
		$thumbnail_id 	= isset( $attachment['id'] ) ? $attachment['id'] : 0;

		$response = set_post_thumbnail( $post_id, $thumbnail_id );

	}

	/**
	 * Filter http_request_host_is_external to return true and allow external requests for the HTTP request.
	 *
	 * @param  bool   $allow  Should external requests be allowed.
	 * @param  string $host   IP of the requested host.
	 * @param  string $url    URL of the requested host.
	 *
	 * @return bool
	 */
	public function allow_sync_external_host( $allow, $host, $url ) {
		// Return true to allow an external request to be made via download_url().
		$allow = true;

		return $allow;
	}

	public function attach_comments( $post_id, $comments ) {

		if ( empty( $post_id ) || ! $comments ) {
			return;
		}

		foreach ( $comments as $comment_args ) {

			// Check to see if the comment already exists
			if ( $comment = $this->comment_exists( $comment_args ) ) {
				continue;
			}

			// Set Comment Post ID to correct local Post ID
			$comment_args['comment_post_ID'] = $post_id;

			// Get the comment author ID
			$comment_args['user_id'] = $this->get_press_sync_author_id( $comment_args['post_author'] );

			$comment_id = wp_insert_comment( $comment_args );

			if ( ! is_wp_error( $comment_id ) ) {

				foreach ( $comment_args['meta_input'] as $meta_key => $meta_value ) {
					update_comment_meta( $comment_id, $meta_key, $meta_value );
				}
			}

		}

	}

	public function add_p2p_connections( $post_id, $post_args ) {

		if ( ! class_exists('P2P_Autoload') || ! $post_args['p2p_connections'] ) {
			return;
		}

		$connections = isset( $post_args['p2p_connections'] ) ? $post_args['p2p_connections'] : array();

		if ( ! $connections ) {
			return;
		}

		foreach ( $connections as $connection ) {

			$p2p_from 	= $this->get_post_id_by_press_sync_id( $connection['p2p_from'] );
			$p2p_to 	= $this->get_post_id_by_press_sync_id( $connection['p2p_to'] );
			$p2p_type 	= $connection['p2p_type'];

			$response = p2p_type( $p2p_type )->connect( $p2p_from, $p2p_to );

		}

	}

	public function get_post_id_by_press_sync_id( $press_sync_post_id ) {

		global $wpdb;

		$sql 		= "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'press_sync_post_id' AND meta_value = $press_sync_post_id";
		$post_id 	= $wpdb->get_var( $sql );

		return $post_id;
	}

	public function insert_comments( $post_id, $post_args ) {
		// Post ID empty or post does not have any comments so bail early.
		if ( empty( $post_id ) || ( ! array_key_exists( 'comments', $post_args ) && empty( $post_args['comments'] ) ) ) {
			return false;
		}

		foreach ( $post_args['comments'] as $comment ) {
			$comment['comment_post_ID'] = $post_id;
			if ( isset( $comment['comment_post_ID'] ) ) {
				wp_insert_comment( $comment );
			}
		}
	}

}
