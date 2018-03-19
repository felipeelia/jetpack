<?php

require_once dirname( __FILE__ ) . '/class.jetpack-sync-settings.php';
require_once dirname( __FILE__ ) . '/class.jetpack-sync-item.php';

class Jetpack_Sync_Module_Posts extends Jetpack_Sync_Module {

	private $action_handler;
	private $import_end = false;

	private $sync_items = array();

	const DEFAULT_PREVIOUS_STATE = 'new';

	public function name() {
		return 'posts';
	}

	public function get_object_by_id( $object_type, $id ) {
		if ( $object_type === 'post' && $post = get_post( intval( $id ) ) ) {
			return $this->filter_post_content_and_add_links( $post );
		}

		return false;
	}

	public function set_defaults() {
		$this->import_end = false;
	}

	public function init_listeners( $callable ) {
		$this->action_handler = $callable;

		// Core < 4.7 doesn't deal with nested wp_insert_post calls very well
		global $wp_version;
		$priority = version_compare( $wp_version, '4.7-alpha', '<' ) ? 0 : 11;
		// `wp_insert_post_parent` happens early on `wp_insert_post`
		add_filter( 'wp_insert_post_parent', array( $this, 'set_post_sync_item' ), 10, 2 );

		add_action( 'wp_insert_post', array( $this, 'wp_insert_post' ), $priority, 3 );
		add_action( 'jetpack_post_saved', $callable, 10, 1 );
		add_action( 'jetpack_post_published', $callable, 10, 1 );

		add_action( 'deleted_post', $callable );

		add_action( 'transition_post_status', array( $this, 'save_published' ), 10, 3 );
		add_filter( 'jetpack_sync_before_enqueue_jetpack_post_saved', array( $this, 'filter_blacklisted_post_types' ) );
		add_filter( 'jetpack_sync_before_enqueue_jetpack_post_published', array( $this, 'filter_blacklisted_post_types' ) );

		// listen for meta changes
		$this->init_listeners_for_meta_type( 'post', $callable, $this );
		$this->init_meta_whitelist_handler( 'post', array( $this, 'filter_meta' ) );

		add_action( 'export_wp', $callable );
		add_action( 'jetpack_sync_import_end', $callable, 10, 2 );

		// Movable type, RSS, Livejournal
		add_action( 'import_done', array( $this, 'sync_import_done' ) );

		// WordPress, Blogger, Livejournal, woo tax rate
		add_action( 'import_end', array( $this, 'sync_import_end' ) );

		add_action( 'set_object_terms', array( $this, 'set_object_terms' ), 10, 6 );
	}

	public function set_post_sync_item( $post_parent, $post_ID ) {
		if ( $post_ID ) {
			$this->sync_items[ $post_ID ] = new Jetpack_Sync_Item( 'save_post' );
		} else {
			$this->sync_items[ 'new' ] = new Jetpack_Sync_Item( 'save_post' );
		}
		return $post_parent;
	}

	public function set_object_terms( $post_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		if ( ! self::is_saving_post( $post_id ) ) {
			return;
		}
		$sync_item = new Jetpack_Sync_Item( 'set_object_terms',
			array( $post_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids )
		);
		$this->sync_items[ $post_id ]->add_terms( $sync_item );
	}


	public function is_saving_post( $post_ID ) {
		return isset( $this->sync_items[ $post_ID ] );
	}

	public function init_full_sync_listeners( $callable ) {
		add_action( 'jetpack_full_sync_posts', $callable ); // also sends post meta
	}

	public function init_before_send() {
		add_filter( 'jetpack_sync_before_send_jetpack_post_saved', array( $this, 'expand_jetpack_post_saved' ) );
		add_filter( 'jetpack_sync_before_send_jetpack_post_published', array( $this, 'expand_jetpack_post_saved' ) );

		// full sync
		add_filter( 'jetpack_sync_before_send_jetpack_full_sync_posts', array( $this, 'expand_post_ids' ) );
	}

	public function enqueue_full_sync_actions( $config, $max_items_to_enqueue, $state ) {
		global $wpdb;

		return $this->enqueue_all_ids_as_action( 'jetpack_full_sync_posts', $wpdb->posts, 'ID', $this->get_where_sql( $config ), $max_items_to_enqueue, $state );
	}

	public function estimate_full_sync_actions( $config ) {
		global $wpdb;

		$query = "SELECT count(*) FROM $wpdb->posts WHERE " . $this->get_where_sql( $config );
		$count = $wpdb->get_var( $query );

		return (int) ceil( $count / self::ARRAY_CHUNK_SIZE );
	}

	private function get_where_sql( $config ) {
		$where_sql = Jetpack_Sync_Settings::get_blacklisted_post_types_sql();

		// config is a list of post IDs to sync
		if ( is_array( $config ) ) {
			$where_sql .= ' AND ID IN (' . implode( ',', array_map( 'intval', $config ) ) . ')';
		}

		return $where_sql;
	}

	function get_full_sync_actions() {
		return array( 'jetpack_full_sync_posts' );
	}

	/**
	 * Process content before send
	 *
	 * @param array $args sync_post_saved arguments
	 *
	 * @return array
	 */
	function expand_jetpack_post_saved( $args ) {
		$args[0]['object'] = $this->filter_post_content_and_add_links( $args[0]['object'] );
		return $args;
	}

	function filter_blacklisted_post_types( $args ) {
		$post = $args[0]['object'];
		if ( in_array( $post->post_type, Jetpack_Sync_Settings::get_setting( 'post_types_blacklist' ) ) ) {
			return false;
		}

		return $args;
	}

	// Meta
	function filter_meta( $args ) {
		if ( $this->is_post_type_allowed( $args[1] ) && $this->is_whitelisted_post_meta( $args[2] ) ) {
			return $args;
		}

		return false;
	}

	function is_whitelisted_post_meta( $meta_key ) {
		// _wpas_skip_ is used by publicize
		return in_array( $meta_key, Jetpack_Sync_Settings::get_setting( 'post_meta_whitelist' ) ) || wp_startswith( $meta_key, '_wpas_skip_' );
	}

	function is_post_type_allowed( $post_id ) {
		$post = get_post( intval( $post_id ) );
		if( $post->post_type ) {
			return ! in_array( $post->post_type, Jetpack_Sync_Settings::get_setting( 'post_types_blacklist' ) );
		}
		return false;
	}

	function remove_embed() {
		global $wp_embed;
		remove_filter( 'the_content', array( $wp_embed, 'run_shortcode' ), 8 );
		// remove the embed shortcode since we would do the part later.
		remove_shortcode( 'embed' );
		// Attempts to embed all URLs in a post
		remove_filter( 'the_content', array( $wp_embed, 'autoembed' ), 8 );
	}

	function add_embed() {
		global $wp_embed;
		add_filter( 'the_content', array( $wp_embed, 'run_shortcode' ), 8 );
		// Shortcode placeholder for strip_shortcodes()
		add_shortcode( 'embed', '__return_false' );
		// Attempts to embed all URLs in a post
		add_filter( 'the_content', array( $wp_embed, 'autoembed' ), 8 );
	}

	// Expands wp_insert_post to include filtered content
	function filter_post_content_and_add_links( $post_object ) {
		global $post;
		$post = $post_object;

		// return non existant post
		$post_type = get_post_type_object( $post->post_type );
		if ( empty( $post_type ) || ! is_object( $post_type ) ) {
			$non_existant_post                    = new stdClass();
			$non_existant_post->ID                = $post->ID;
			$non_existant_post->post_modified     = $post->post_modified;
			$non_existant_post->post_modified_gmt = $post->post_modified_gmt;
			$non_existant_post->post_status       = 'jetpack_sync_non_registered_post_type';

			return $non_existant_post;
		}
		/**
		 * Filters whether to prevent sending post data to .com
		 *
		 * Passing true to the filter will prevent the post data from being sent
		 * to the WordPress.com.
		 * Instead we pass data that will still enable us to do a checksum against the
		 * Jetpacks data but will prevent us from displaying the data on in the API as well as
		 * other services.
		 * @since 4.2.0
		 *
		 * @param boolean false prevent post data from being synced to WordPress.com
		 * @param mixed $post WP_POST object
		 */
		if ( apply_filters( 'jetpack_sync_prevent_sending_post_data', false, $post ) ) {
			// We only send the bare necessary object to be able to create a checksum.
			$blocked_post                    = new stdClass();
			$blocked_post->ID                = $post->ID;
			$blocked_post->post_modified     = $post->post_modified;
			$blocked_post->post_modified_gmt = $post->post_modified_gmt;
			$blocked_post->post_status       = 'jetpack_sync_blocked';

			return $blocked_post;
		}

		// lets not do oembed just yet.
		$this->remove_embed();

		if ( 0 < strlen( $post->post_password ) ) {
			$post->post_password = 'auto-' . wp_generate_password( 10, false );
		}

		/** This filter is already documented in core. wp-includes/post-template.php */
		if ( Jetpack_Sync_Settings::get_setting( 'render_filtered_content' ) && $post_type->public ) {
			global $shortcode_tags;
			/**
			 * Filter prevents some shortcodes from expanding.
			 *
			 * Since we can can expand some type of shortcode better on the .com side and make the
			 * expansion more relevant to contexts. For example [galleries] and subscription emails
			 *
			 * @since 4.5.0
			 *
			 * @param array - of shortcode tags to remove.
			 */
			$shortcodes_to_remove        = apply_filters( 'jetpack_sync_do_not_expand_shortcodes', array(
				'gallery',
				'slideshow'
			) );
			$removed_shortcode_callbacks = array();
			foreach ( $shortcodes_to_remove as $shortcode ) {
				if ( isset ( $shortcode_tags[ $shortcode ] ) ) {
					$removed_shortcode_callbacks[ $shortcode ] = $shortcode_tags[ $shortcode ];
				}
			}

			array_map( 'remove_shortcode', array_keys( $removed_shortcode_callbacks ) );

			$post->post_content_filtered = apply_filters( 'the_content', $post->post_content );
			$post->post_excerpt_filtered = apply_filters( 'the_excerpt', $post->post_excerpt );

			foreach ( $removed_shortcode_callbacks as $shortcode => $callback ) {
				add_shortcode( $shortcode, $callback );
			}
		}

		$this->add_embed();

		if ( has_post_thumbnail( $post->ID ) ) {
			$image_attributes = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'full' );
			if ( is_array( $image_attributes ) && isset( $image_attributes[0] ) ) {
				$post->featured_image = $image_attributes[0];
			}
		}

		$post->permalink = get_permalink( $post->ID );
		$post->shortlink = wp_get_shortlink( $post->ID );

		if ( function_exists( 'amp_get_permalink' ) ) {
			$post->amp_permalink = amp_get_permalink( $post->ID );
		}

		return $post;
	}

	public function save_published( $new_status, $old_status, $post ) {
		if ( ! isset( $this->sync_items[ $post->ID ] ) ) {
			$this->sync_items[ $post->ID ] = new Jetpack_Sync_Item( 'save_post' );
		}
		$sync_item = $this->sync_items[ $post->ID ];
		$is_just_published = 'publish' === $new_status && 'publish' !== $old_status;
		$sync_item->set_state_value( 'is_just_published', $is_just_published );
		$sync_item->set_state_value( 'previous_status', $old_status );
	}

	public function is_revision( $post ) {
	    return ( wp_is_post_revision( $post ) && $this->is_saving_post( $post->post_parent ) );
    }

    public function process_revision( $post, $post_ID ) {
        $post = (array) $post;
        unset( $post['post_content'] );
        unset( $post['post_title'] );
        unset( $post['post_excerpt'] );
        $sync_item = $this->sync_items[ $post['post_parent'] ];
        $sync_item->set_state_value( 'revision', $post );
        unset( $this->sync_items[ $post_ID ] );
    }

	public function wp_insert_post( $post_ID, $post = null, $update = null ) {
		if ( ! is_numeric( $post_ID ) || is_null( $post ) ) {
			return;
		}

		if ( $this->is_revision( $post ) ) {
			$this->process_revision( $post, $post_ID );
			return;
		}

		// workaround for https://github.com/woocommerce/woocommerce/issues/18007
		if ( $post && 'shop_order' === $post->post_type ) {
			$post = get_post( $post_ID );
		}
		if ( ! isset( $this->sync_items[ $post_ID ] ) ) {
			$this->sync_items[ $post_ID ] = new Jetpack_Sync_Item( 'save_post' );
		}
		$sync_item = $this->sync_items[ $post_ID ];

		if ( ! $sync_item->state_isset( 'previous_status' ) ) {
			$sync_item->set_state_value( 'previous_status', self::DEFAULT_PREVIOUS_STATE );
		}

		$sync_item->set_state_value( 'is_auto_save', (bool) Jetpack_Constants::get_constant( 'DOING_AUTOSAVE' ) );
		$sync_item->set_state_value( 'update', $update );

		$author_user_object = get_user_by( 'id', $post->post_author );
		if ( $author_user_object ) {
			$sync_item->set_state_value( 'author',  array(
				'id'              => $post->post_author,
				'wpcom_user_id'   => get_user_meta( $post->post_author, 'wpcom_user_id', true ),
				'display_name'    => $author_user_object->display_name,
				'email'           => $author_user_object->user_email,
				'translated_role' => Jetpack::translate_user_to_role( $author_user_object ),
			) );
		}

		$sync_item->set_object( $post );

		if ( $sync_item->is_state_value_true( 'is_just_published' ) ) {
			$this->send_published( $sync_item );
		} else {
			/**
			 * Action that gets synced when a post type gets saved
			 *
			 * @since 5.9.0
			 *
			 * @param array Sync Item Payload [ 'object' => post object, 'terms' => related terms, 'state' => additional info about the post ]
			 */

			do_action( 'jetpack_post_saved', $sync_item->get_payload() );
		}

		unset( $this->sync_items[ $post_ID ] );
	}

	public function send_published( $sync_item ) {
		$post = $sync_item->get_object();
		// Post revisions cause race conditions where this send_published add the action before the actual post gets synced
		if ( wp_is_post_autosave( $post ) || wp_is_post_revision( $post ) ) {
			return;
		}

		/**
		 * Filter that is used to add to the post state when a post gets published
		 *
		 * @since 4.4.0
		 *
		 * @param mixed array Post state
		 * @param mixed $post WP_POST object
		 */
		$sync_item_state = apply_filters( 'jetpack_published_post_flags', $sync_item->get_state(), $post );
		$sync_item->set_state( $sync_item_state );

		/**
		 * Action that gets synced when a post type gets published.
		 *
		 * @since 5.9.0
		 *
		 * @param mixed $sync_item  object
		 */
		do_action( 'jetpack_post_published', $sync_item->get_payload() );
	}

	public function expand_post_ids( $args ) {
		$post_ids = $args[0];

		$posts = array_filter( array_map( array( 'WP_Post', 'get_instance' ), $post_ids ) );
		$posts = array_map( array( $this, 'filter_post_content_and_add_links' ), $posts );
		$posts = array_values( $posts ); // reindex in case posts were deleted

		return array(
			$posts,
			$this->get_metadata( $post_ids, 'post', Jetpack_Sync_Settings::get_setting( 'post_meta_whitelist' ) ),
			$this->get_term_relationships( $post_ids ),
		);
	}

	/**
	 * IMPORT SECTIONS .. figures out the whole importing of things.
	 */
	public function sync_import_done( $importer ) {
		// We already ran an send the import
		if ( $this->import_end ) {
			return;
		}

		$importer_name = $this->get_importer_name( $importer );

		/**
		 * Sync Event that tells that the import is finished
		 *
		 * @since 5.0.0
		 *
		 * $param string $importer
		 */
		do_action( 'jetpack_sync_import_end', $importer, $importer_name );
		$this->import_end = true;
	}

	public function sync_import_end() {
		// We already ran an send the import
		if ( $this->import_end ) {
			return;
		}

		$this->import_end = true;
		$importer         = 'unknown';
		$backtrace        = wp_debug_backtrace_summary( null, 0, false );
		if ( $this->is_importer( $backtrace, 'Blogger_Importer' ) ) {
			$importer = 'blogger';
		}

		if ( 'unknown' === $importer && $this->is_importer( $backtrace, 'WC_Tax_Rate_Importer' ) ) {
			$importer = 'woo-tax-rate';
		}

		if ( 'unknown' === $importer && $this->is_importer( $backtrace, 'WP_Import' ) ) {
			$importer = 'wordpress';
		}

		$importer_name = $this->get_importer_name( $importer );

		/** This filter is already documented in sync/class.jetpack-sync-module-posts.php */
		do_action( 'jetpack_sync_import_end', $importer, $importer_name );
	}

	private function get_importer_name( $importer ) {
		$importers = get_importers();
		return isset( $importers[ $importer ] ) ? $importers[ $importer ][0] : 'Unknown Importer';
	}

	private function is_importer( $backtrace, $class_name ) {
		foreach ( $backtrace as $trace ) {
			if ( strpos( $trace, $class_name ) !== false ) {
				return true;
			}
		}

		return false;
	}
}
