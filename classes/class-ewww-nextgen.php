<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'EWWW_Nextgen' ) ) {
	class EWWW_Nextgen {
		/* initializes the nextgen integration functions */
		public function __construct() {
			/* add_filter( 'ngg_manage_images_columns', array( $this, 'ewww_manage_images_columns' ) ); */
			add_filter( 'ngg_manage_images_number_of_columns', array( $this, 'ewww_manage_images_number_of_columns' ) );
			add_filter( 'ngg_manage_images_row_actions', array( $this, 'ewww_manage_images_row_actions' ) );
			/* add_action( 'ngg_manage_image_custom_column', array( $this, 'ewww_manage_image_custom_column' ), 10, 2 ); */
			if ( ewww_image_optimizer_test_background_opt() ) {
				add_action( 'ngg_added_new_image', array( $this, 'queue_new_image' ) );
				ewwwio_debug_message( 'background mode enabled for nextgen' );
			} else {
				add_action( 'ngg_added_new_image', array( $this, 'ewww_added_new_image' ) );
				ewwwio_debug_message( 'background mode NOT enabled for nextgen' );
			}
			add_action( 'wp_ajax_ewww_ngg_manual', array( $this, 'ewww_ngg_manual' ) );
			add_action( 'wp_ajax_ewww_ngg_cloud_restore', array( $this, 'ewww_ngg_cloud_restore' ) );
			add_action( 'admin_action_ewww_ngg_manual', array( $this, 'ewww_ngg_manual' ) );
			add_action( 'admin_menu', array( $this, 'ewww_ngg_bulk_menu' ) );
			add_action( 'admin_head', array( $this, 'ewww_ngg_bulk_actions_script' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'ewww_ngg_bulk_script' ), 20 );
			add_action( 'wp_ajax_bulk_ngg_preview', array( $this, 'ewww_ngg_bulk_preview' ) );
			add_action( 'wp_ajax_bulk_ngg_init', array( $this, 'ewww_ngg_bulk_init' ) );
			add_action( 'wp_ajax_bulk_ngg_filename', array( $this, 'ewww_ngg_bulk_filename' ) );
			add_action( 'wp_ajax_bulk_ngg_loop', array( $this, 'ewww_ngg_bulk_loop' ) );
			add_action( 'wp_ajax_bulk_ngg_cleanup', array( $this, 'ewww_ngg_bulk_cleanup' ) );
			add_action( 'ngg_generated_image', array( $this, 'ewww_ngg_generated_image' ), 10, 2 );
		}

		/* adds the Bulk Optimize page to the tools menu, and a hidden page for optimizing thumbnails */
		function ewww_ngg_bulk_menu() {
			if ( ! defined( 'NGGFOLDER' ) ) {
				return;
			}
			add_submenu_page( NGGFOLDER, esc_html__( 'Bulk Optimize', 'ewww-image-optimizer' ), esc_html__( 'Bulk Optimize', 'ewww-image-optimizer' ), apply_filters( 'ewww_image_optimizer_manual_permissions', '' ), 'ewww-ngg-bulk', array( &$this, 'ewww_ngg_bulk_preview' ) );
		}

		function queue_new_image( $image, $storage = null ) {
			ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
			if ( empty( $storage ) ) {
				// creating the 'registry' object for working with nextgen
				$registry = C_Component_Registry::get_instance();
				// creating a database storage object from the 'registry' object
				$storage  = $registry->get_utility( 'I_Gallery_Storage' );
			}
			// find the image id
			if ( is_array( $image ) ) {
				$image_id = $image['id'];
					$image = $storage->object->_image_mapper->find( $image_id, true );
			} else {
				$image_id = $storage->object->_get_image_id( $image );
			}
			global $ewwwio_ngg2_background;
			if ( ! class_exists( 'WP_Background_Process' ) ) {
				require_once( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'background.php' );
			}
			if ( ! is_object( $ewwwio_ngg2_background ) ) {
				$ewwwio_ngg2_background = new EWWWIO_Ngg2_Background_Process();
			}
			ewwwio_debug_message( "backgrounding optimization for $image_id" );
			$ewwwio_ngg2_background->push_to_queue( array(
				'id' => $image_id,
			) );
			$ewwwio_ngg2_background->save()->dispatch();
			set_transient( 'ewwwio-background-in-progress-ngg-' . $image_id, true, 24 * HOUR_IN_SECONDS );
			ewww_image_optimizer_debug_log();
		}

		/* ngg_added_new_image hook */
		function ewww_added_new_image( $image, $storage = null ) {
			ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
			if ( empty( $storage ) ) {
				// creating the 'registry' object for working with nextgen
				$registry = C_Component_Registry::get_instance();
				// creating a database storage object from the 'registry' object
				$storage  = $registry->get_utility( 'I_Gallery_Storage' );
			}
			global $ewww_image;
			// find the image id
			if ( is_array( $image ) ) {
				$image_id = $image['id'];
				$image = $storage->object->_image_mapper->find( $image_id, true );
			} else {
				$image_id = $storage->object->_get_image_id( $image );
			}
			ewwwio_debug_message( "image id: $image_id" );
			// get an array of sizes available for the $image
			$sizes = $storage->get_image_sizes();
			// run the optimizer on the image for each $size
			if ( ewww_image_optimizer_iterable( $sizes ) ) {
				foreach ( $sizes as $size ) {
					if ( $size === 'full' ) {
						$full_size = true;
					} else {
						$full_size = false;
					}
					// get the absolute path
					$file_path = $storage->get_image_abspath( $image, $size );
					ewwwio_debug_message( "optimizing (nextgen): $file_path" );
					$ewww_image = new EWWW_Image( $image_id, 'nextgen', $file_path );
					$ewww_image->resize = $size;
					// optimize the image and grab the results
					$res = ewww_image_optimizer( $file_path, 2, false, false, $full_size );
					ewwwio_debug_message( "results {$res[1]}" );
					// only if we're dealing with the full-size original
					if ( $size === 'full' ) {
						// update the metadata for the optimized image
						$image->meta_data['ewww_image_optimizer'] = $res[1];
					} else {
						$image->meta_data[ $size ]['ewww_image_optimizer'] = $res[1];
					}
					nggdb::update_image_meta( $image_id, $image->meta_data );
					ewwwio_debug_message( 'storing results for full size image' );
				}
			}
			return $image;
		}

		function ewww_ngg_generated_image( $image, $size ) {
			global $ewww_image;
			// creating the 'registry' object for working with nextgen
			$registry = C_Component_Registry::get_instance();
			// creating a database storage object from the 'registry' object
			$storage  = $registry->get_utility( 'I_Gallery_Storage' );
			$filename = $storage->get_image_abspath( $image, $size );
			$ewww_image = new EWWW_Image( $image->pid, 'nextgen', $filename );
			$ewww_image->resize = $size;
			if ( file_exists( $filename ) ) {
				ewww_image_optimizer( $filename, 2 );
				ewwwio_debug_message( "nextgen dynamic thumb saved: $filename" );
				$image_size = ewww_image_optimizer_filesize( $filename );
				ewwwio_debug_message( "optimized size: $image_size" );
			}
			ewww_image_optimizer_debug_log();
		}

		/* Manually process an image from the NextGEN Gallery */
		function ewww_ngg_manual() {
			// check permission of current user
			$permissions = apply_filters( 'ewww_image_optimizer_manual_permissions', '' );
			if ( false === current_user_can( $permissions ) ) {
				if ( ! wp_doing_ajax() ) {
					wp_die( esc_html__( 'You do not have permission to optimize images.', 'ewww-image-optimizer' ) );
				}
				wp_die( json_encode( array( 'error' => esc_html__( 'You do not have permission to optimize images.', 'ewww-image-optimizer' ) ) ) );
			}
			// make sure function wasn't called without an attachment to work with
			if ( false === isset( $_REQUEST['ewww_attachment_ID'] ) ) {
				if ( ! wp_doing_ajax() ) {
					wp_die( esc_html__( 'No attachment ID was provided.', 'ewww-image-optimizer' ) );
				}
				wp_die( json_encode( array( 'error' => esc_html__( 'No attachment ID was provided.', 'ewww-image-optimizer' ) ) ) );
			}
			// store the attachment $id
			$id = intval( $_REQUEST['ewww_attachment_ID'] );
			if ( empty( $_REQUEST['ewww_manual_nonce'] ) || ! wp_verify_nonce( $_REQUEST['ewww_manual_nonce'], "ewww-manual-$id" ) ) {
				if ( ! wp_doing_ajax() ) {
						wp_die( esc_html__( 'Access denied.', 'ewww-image-optimizer' ) );
				}
				wp_die( json_encode( array( 'error' => esc_html__( 'Access denied.', 'ewww-image-optimizer' ) ) ) );
			}
			// creating the 'registry' object for working with nextgen
			$registry = C_Component_Registry::get_instance();
			// creating a database storage object from the 'registry' object
			$storage  = $registry->get_utility( 'I_Gallery_Storage' );
			// get an image object
			$image = $storage->object->_image_mapper->find( $id );
			$image = $this->ewww_added_new_image( $image, $storage );
			$success = $this->ewww_manage_image_custom_column( '', $image );
			if ( get_transient( 'ewww_image_optimizer_cloud_status' ) == 'exceeded' || ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_exceeded' ) > time() ) {
				die( json_encode( array( 'error' => esc_html__( 'License exceeded', 'ewww-image-optimizer' ) ) ) );
			}
			if ( ! wp_doing_ajax() ) {
				// get the referring page, and send the user back there
				$sendback = wp_get_referer();
				$sendback = preg_replace( '|[^a-z0-9-~+_.?#=&;,/:]|i', '', $sendback );
				wp_redirect( $sendback );
				die;
			}
			die( json_encode( array( 'success' => $success ) ) );
		}

		/* Restore an image from the NextGEN Gallery */
		function ewww_ngg_cloud_restore() {
			// check permission of current user
			$permissions = apply_filters( 'ewww_image_optimizer_manual_permissions', '' );
			if ( false === current_user_can( $permissions ) ) {
				if ( ! wp_doing_ajax() ) {
					wp_die( esc_html__( 'You do not have permission to optimize images.', 'ewww-image-optimizer' ) );
				}
				wp_die( json_encode( array( 'error' => esc_html__( 'You do not have permission to optimize images.', 'ewww-image-optimizer' ) ) ) );
			}
			// make sure function wasn't called without an attachment to work with
			if ( false === isset( $_REQUEST['ewww_attachment_ID'] ) ) {
				if ( ! wp_doing_ajax() ) {
					wp_die( esc_html__( 'No attachment ID was provided.', 'ewww-image-optimizer' ) );
				}
				wp_die( json_encode( array( 'error' => esc_html__( 'No attachment ID was provided.', 'ewww-image-optimizer' ) ) ) );
			}
			// store the attachment $id
			$id = intval( $_REQUEST['ewww_attachment_ID'] );
			if ( empty( $_REQUEST['ewww_manual_nonce'] ) || ! wp_verify_nonce( $_REQUEST['ewww_manual_nonce'], "ewww-manual-$id" ) ) {
				if ( ! wp_doing_ajax() ) {
						wp_die( esc_html__( 'Access denied.', 'ewww-image-optimizer' ) );
				}
				wp_die( json_encode( array( 'error' => esc_html__( 'Access denied.', 'ewww-image-optimizer' ) ) ) );
			}
			// creating the 'registry' object for working with nextgen
			$registry = C_Component_Registry::get_instance();
			// creating a database storage object from the 'registry' object
			$storage  = $registry->get_utility( 'I_Gallery_Storage' );
			// get an image object
			$image = $storage->object->_image_mapper->find( $id );
			ewww_image_optimizer_cloud_restore_from_meta_data( $image->pid, 'nextgen' );
			$success = $this->ewww_manage_image_custom_column( '', $image );
			die( json_encode( array( 'success' => $success ) ) );
		}

		/* ngg_manage_images_number_of_columns hook, changed in NGG 2.0.50ish */
		function ewww_manage_images_number_of_columns( $count ) {
			add_thickbox();
			wp_enqueue_script( 'ewwwnextgenscript', plugins_url( '/includes/nextgen.js', __FILE__ ), array( 'jquery' ), EWWW_IMAGE_OPTIMIZER_VERSION );
			wp_enqueue_style( 'jquery-ui-tooltip-custom', plugins_url( '/includes/jquery-ui-1.10.1.custom.css', __FILE__ ), array(), EWWW_IMAGE_OPTIMIZER_VERSION );
			// submit a couple variables to the javascript to work with
			$loading_image = plugins_url( '/images/wpspin.gif', __FILE__ );
			$loading_image = plugins_url( '/images/spinner.gif', __FILE__ );
			wp_localize_script(
				'ewwwnextgenscript',
				'ewww_vars',
				array(
					'optimizing' => '<p>' . esc_html__( 'Optimizing', 'ewww-image-optimizer' ) . "&nbsp;<img src='$loading_image' /></p>",
					'restoring' => '<p>' . esc_html__( 'Restoring', 'ewww-image-optimizer' ) . "&nbsp;<img src='$loading_image' /></p>",
				)
			);
			$count++;
			add_filter( "ngg_manage_images_column_{$count}_header", array( &$this, 'ewww_manage_images_columns' ) );
			add_filter( "ngg_manage_images_column_{$count}_content", array( &$this, 'ewww_manage_image_custom_column' ), 10, 2 );
			return $count;
		}

		/* ngg_manage_images_columns hook */
		function ewww_manage_images_columns( $columns = null ) {
			if ( is_array( $columns ) ) {
				$columns['ewww_image_optimizer'] = esc_html__( 'Image Optimizer', 'ewww-image-optimizer' );
				return $columns;
			} else {
				return esc_html__( 'Image Optimizer', 'ewww-image-optimizer' );
			}
		}

		/* ngg_manage_image_custom_column hook */
		function ewww_manage_image_custom_column( $column_name, $id ) {
			// once we've found our custom column
			if ( $column_name == 'ewww_image_optimizer' || $column_name == '' ) {
				// creating the 'registry' object for working with nextgen
				$registry = C_Component_Registry::get_instance();
				// creating a database storage object from the 'registry' object
				$storage  = $registry->get_utility( 'I_Gallery_Storage' );
				if ( is_object( $id ) ) {
					$image = $id;
				} else {
					// get an image object
					$image = $storage->object->_image_mapper->find( $id );
				}
				$output = "<div id='ewww-nextgen-status-$image->pid'>";
				if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_debug' ) && ewww_image_optimizer_function_exists( 'print_r' ) ) {
					$print_meta = print_r( $image->meta_data, true );
					$print_meta = preg_replace( array( '/ /', '/\n+/' ), array( '&nbsp;', '<br />' ), esc_html( $print_meta ) );
					$output .= '<div style="background-color:#ffff99;font-size: 10px;padding: 10px;margin:-10px -10px 10px;line-height: 1.1em">' . $print_meta . '</div>';
				}
				$msg = '';
				// get the absolute path
				$file_path = $storage->get_image_abspath( $image, 'full' );
				// get the mimetype of the image
				$type = ewww_image_optimizer_quick_mimetype( $file_path );
				$valid = true;
				// check to see if we have a tool to handle the mimetype detected
				if ( ! defined( 'EWWW_IMAGE_OPTIMIZER_JPEGTRAN' ) ) {
					ewww_image_optimizer_tool_init();
					ewww_image_optimizer_notice_utils( 'quiet' );
				}
				$skip = ewww_image_optimizer_skip_tools();
				switch ( $type ) {
					case 'image/jpeg':
						// if jpegtran is missing, tell the user
						if ( ! EWWW_IMAGE_OPTIMIZER_JPEGTRAN && ! $skip['jpegtran'] ) {
							$valid = false;
							$msg = '<br>' . wp_kses( sprintf( __( '%s is missing', 'ewww-image-optimizer' ), '<em>jpegtran</em>' ), array( 'em' => array() ) );
						}
						break;
					case 'image/png':
						// if the PNG tools are missing, tell the user
						if ( ! EWWW_IMAGE_OPTIMIZER_PNGOUT && ! EWWW_IMAGE_OPTIMIZER_OPTIPNG && ! $skip['optipng'] && ! $skip['pngout'] ) {
							$valid = false;
							$msg = '<br>' . wp_kses( sprintf( __( '%s is missing', 'ewww-image-optimizer' ), '<em>optipng/pngout</em>' ), array( 'em' => array() ) );
						}
						break;
					case 'image/gif':
						// if gifsicle is missing, tell the user
						if ( ! EWWW_IMAGE_OPTIMIZER_GIFSICLE && ! $skip['gifsicle'] ) {
							$valid = false;
							$msg = '<br>' . wp_kses( sprintf( __( '%s is missing', 'ewww-image-optimizer' ), '<em>gifsicle</em>' ), array( 'em' => array() ) );
						}
						break;
					default:
						$valid = false;
				}
				// file isn't in a format we can work with, we don't work with strangers
				if ( $valid == false ) {
					echo esc_html__( 'Unsupported file type', 'ewww-image-optimizer' ) . $msg;
					return;
				}
				// if we have metadata, populate db from meta
				if ( ! empty( $image->meta_data['ewww_image_optimizer'] ) ) {
					$sizes = $storage->get_image_sizes();
					if ( ewww_image_optimizer_iterable( $sizes ) ) {
						foreach ( $sizes as $size ) {
							if ( $size === 'full' ) {
								$full_size = true;
							} else {
								$file_path = $storage->get_image_abspath( $image, $size );
								$full_size = false;
							}
							ewww_image_optimizer_update_file_from_meta( $file_path, 'nextgen', $image->pid, $size );
						}
					}
				}
				$backup_available = false;
				global $wpdb;
				$optimized_images = $wpdb->get_results( "SELECT image_size,orig_size,resize,converted,level,backup FROM $wpdb->ewwwio_images WHERE attachment_id = $image->pid AND gallery = 'nextgen' AND image_size <> 0 ORDER BY orig_size DESC", ARRAY_A );
				if ( ! empty( $optimized_images ) ) {
					list( $detail_output, $converted, $backup_available ) = ewww_image_optimizer_custom_column_results( $image->pid, $optimized_images );
					$output .= $detail_output;
					// display the optimization link with the appropriate text
					$output .= $this->ewww_render_optimize_action_link( $image->pid, true, $backup_available );
				} elseif ( get_transient( 'ewwwio-background-in-progress-ngg-' . $image->pid ) ) {
					$output .= esc_html( 'In Progress', 'ewww-image-optimizer' ) . '<br>';
					// otherwise, give the image size, and a link to optimize right now
				} else {
					// display the optimization link with the appropriate text
					$output .= $this->ewww_render_optimize_action_link( $image->pid, false, $backup_available );
				}
				$output .= '</div>';
				if ( is_object( $id ) ) {
					return $output;
				} else {
					echo $output;
				}
			}
		}

		// output the action link for the manage gallery page
		function ewww_render_optimize_action_link( $id, $optimized = false, $restorable = false ) {
			if ( ! current_user_can( apply_filters( 'ewww_image_optimizer_manual_permissions', '' ) ) ) {
				return '';
			}
			$ewww_manual_nonce = wp_create_nonce( 'ewww-manual-' . $id );

			if ( $optimized ) {
				$link = sprintf( '<a class="ewww-manual-optimize" data-id="%1$d" data-nonce="%2$s" href="admin.php?action=ewww_ngg_manual&amp;ewww_manual_nonce=%2$s&amp;ewww_force=1&amp;ewww_attachment_ID=%1$d">%3$s</a>',
					$id,
					$ewww_manual_nonce,
					esc_html__( 'Re-optimize', 'ewww-image-optimizer' )
				);
				if ( $restorable ) {
					$link .= sprintf( '<br><a class="ewww-manual-cloud-restore" data-id="%1$d" data-nonce="%2$s" href="admin.php?action=ewww_ngg_cloud_restore&amp;ewww_manual_nonce=%2$s&amp;ewww_attachment_ID=%1$d">%3$s</a>',
						$id,
						$ewww_manual_nonce,
						esc_html__( 'Restore original', 'ewww-image-optimizer' )
					);
				}
			} else {
				$link = sprintf( '<a class="ewww-manual-optimize" data-id="%1$d" data-nonce="%2$s" href="admin.php?action=ewww_ngg_manual&amp;ewww_manual_nonce=%2$s&amp;ewww_attachment_ID=%1$d">%3$s</a>',
					$id,
					$ewww_manual_nonce,
					esc_html__( 'Optimize now!', 'ewww-image-optimizer' )
				);
			}
			return $link;
		}

		// append our action link to the list
		function ewww_manage_images_row_actions( $actions ) {
			$actions['optimize'] = array( &$this, 'ewww_render_optimize_action_link' );
			return $actions;
		}

		/* output the html for the bulk optimize page */
		function ewww_ngg_bulk_preview() {
			if ( ! empty( $_REQUEST['doaction'] ) ) {
						// if there is no requested bulk action, do nothing
				if ( empty( $_REQUEST['bulkaction'] ) ) {
					return;
				}
						// if there is no media to optimize, do nothing
				if ( empty( $_REQUEST['doaction'] ) || ! is_array( $_REQUEST['doaction'] ) ) {
					return;
				}
			}
			list($fullsize_count, $unoptimized_count, $resize_count, $unoptimized_resize_count) = ewww_image_optimizer_count_optimized( 'ngg' );
			// make sure there are some attachments to process
			if ( $fullsize_count < 1 ) {
				echo '<p>' . esc_html__( 'You do not appear to have uploaded any images yet.', 'ewww-image-optimizer' ) . '</p>';
				return;
			}
				?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Bulk Optimize', 'ewww-image-optimizer' );
				if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) ) {
					ewww_image_optimizer_cloud_verify();
					echo '<a id="ewww-bulk-credits-available" target="_blank" class="page-title-action" style="float:right;" href="https://ewww.io/my-account/">' . esc_html__( 'Image credits available:', 'ewww-image-optimizer' ) . ' ' . ewww_image_optimizer_cloud_quota() . '</a>';
				}
				echo '</h1>';
				// Retrieve the value of the 'bulk resume' option and set the button text for the form to use
				$resume = get_option( 'ewww_image_optimizer_bulk_ngg_resume' );
				if ( empty( $resume ) ) {
						$button_text = esc_attr__( 'Start optimizing', 'ewww-image-optimizer' );
				} else {
					$button_text = esc_attr__( 'Resume previous bulk operation', 'ewww-image-optimizer' );
				}
					?>
					<div id="ewww-bulk-loading"></div>
					<div id="ewww-bulk-progressbar"></div>
					<div id="ewww-bulk-counter"></div>
			<form id="ewww-bulk-stop" style="display:none;" method="post" action="">
				<br /><input type="submit" class="button-secondary action" value="<?php esc_attr_e( 'Stop Optimizing', 'ewww-image-optimizer' ); ?>" />
			</form>
			<div id="ewww-bulk-widgets" class="metabox-holder" style="display:none">
				<div class="meta-box-sortables">
				<div id="ewww-bulk-last" class="postbox">
					<button type="button" class="handlediv button-link" aria-expanded="true">
						<span class="screen-reader-text"><?php esc_html_e( 'Click to toggle', 'ewww-image-optimizer' ) ?></span>
						<span class="toggle-indicator" aria-hidden="true"></span>
					</button>
					<h2 class="hndle"><span><?php esc_html_e( 'Last Image Optimized', 'ewww-image-optimizer' ) ?></span></h2>
					<div class="inside"></div>
				</div>
				</div>
				<div class="meta-box-sortables">
				<div id="ewww-bulk-status" class="postbox">
					<button type="button" class="handlediv button-link" aria-expanded="true">
						<span class="screen-reader-text"><?php esc_html_e( 'Click to toggle', 'ewww-image-optimizer' ) ?></span>
						<span class="toggle-indicator" aria-hidden="true"></span>
					</button>
					<h2 class="hndle"><span><?php esc_html_e( 'Optimization Log', 'ewww-image-optimizer' ) ?></span></h2>
					<div class="inside"></div>
				</div>
				</div>
			</div>
			<form class="ewww-bulk-form">
				<p><label for="ewww-force" style="font-weight: bold"><?php esc_html_e( 'Force re-optimize', 'ewww-image-optimizer' ); ?></label>&emsp;<input type="checkbox" id="ewww-force" name="ewww-force"></p>
				<p><label for="ewww-delay" style="font-weight: bold"><?php esc_html_e( 'Choose how long to pause between images (in seconds, 0 = disabled)', 'ewww-image-optimizer' ); ?></label>&emsp;<input type="text" id="ewww-delay" name="ewww-delay" value="<?php if ( $delay = ewww_image_optimizer_get_option( 'ewww_image_optimizer_delay' ) ) { echo $delay; } else { echo 0; } ?>"></p>
				<div id="ewww-delay-slider" style="width:50%"></div>
			</form>
				<div id="ewww-bulk-forms">
			<p class="ewww-bulk-info"><?php printf( esc_html__( '%1$d images have been selected (%2$d unoptimized), with %3$d resizes (%4$d unoptimized).', 'ewww-image-optimizer' ), $fullsize_count, $unoptimized_count, $resize_count, $unoptimized_resize_count ); ?><br />
			<?php esc_html_e( 'Previously optimized images will be skipped by default.', 'ewww-image-optimizer' ); ?></p>
				<form id="ewww-bulk-start" class="ewww-bulk-form" method="post" action="">
						<input type="submit" class="button-secondary action" value="<?php echo $button_text; ?>" />
				</form>
				<?php
				// if there is a previous bulk operation to resume, give the user the option to reset the resume flag
				if ( ! empty( $resume ) ) { ?>
						<p class="ewww-bulk-info"><?php esc_html_e( 'If you would like to start over again, press the Reset Status button to reset the bulk operation status.', 'ewww-image-optimizer' ); ?></p>
						<form id="ewww-bulk-reset" class="ewww-bulk-form" method="post" action="">
								<?php wp_nonce_field( 'ewww-image-optimizer-bulk-reset', 'ewww_wpnonce' ); ?>
								<input type="hidden" name="ewww_reset" value="1">
								<input type="submit" class="button-secondary action" value="<?php esc_attr_e( 'Reset Status', 'ewww-image-optimizer' ); ?>" />
						</form>
<?php           }
				echo '</div></div>';
				if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_debug' ) ) {
					global $ewww_debug;
					echo '<div style="background-color:#ffff99;">' . $ewww_debug . '</div>';
				}
				if ( ! empty( $_REQUEST['ewww_inline'] ) ) {
					die();
				}
				return;
		}

		function ewww_ngg_style_remove() {
			wp_deregister_style( 'jquery-ui-nextgen' );
		}

		/* prepares the javascript for a bulk operation */
		function ewww_ngg_bulk_script( $hook ) {
			if ( strpos( $hook, 'ewww-ngg-bulk' ) === false && strpos( $hook, 'nggallery-manage-gallery' ) === false ) {
				return;
			}
			if ( strpos( $hook, 'nggallery-manage-gallery' ) && ( empty( $_REQUEST['bulkaction'] ) || $_REQUEST['bulkaction'] != 'bulk_optimize') ) {
				return;
			}
			if ( strpos( $hook, 'nggallery-manage-gallery' ) && ( empty( $_REQUEST['doaction'] ) || ! is_array( $_REQUEST['doaction'] ) ) ) {
				return;
			}
			$images = null;
			// see if the user wants to reset the previous bulk status
			if ( ! empty( $_REQUEST['ewww_reset'] ) && wp_verify_nonce( $_REQUEST['ewww_wpnonce'], 'ewww-image-optimizer-bulk-reset' ) ) {
				update_option( 'ewww_image_optimizer_bulk_ngg_resume', '' );
			}
			// see if there is a previous operation to resume
			$resume = get_option( 'ewww_image_optimizer_bulk_ngg_resume' );
			// if we've been given a bulk action to perform
			if ( ! empty( $_REQUEST['doaction'] ) ) {

				// if we are optimizing a specific group of images
				if ( $_REQUEST['page'] == 'manage-images' && $_REQUEST['bulkaction'] == 'bulk_optimize' ) {
					check_admin_referer( 'ngg_updategallery' );
					// reset the resume status, not allowed here
					update_option( 'ewww_image_optimizer_bulk_ngg_resume', '' );
					// retrieve the image IDs from POST
					$images = array_map( 'intval', $_REQUEST['doaction'] );
				}
				// if we are optimizing a specific group of galleries
				if ( $_REQUEST['page'] == 'manage-galleries' && $_REQUEST['bulkaction'] == 'bulk_optimize' ) {
					check_admin_referer( 'ngg_bulkgallery' );
					global $nggdb;
					// reset the resume status, not allowed here
					update_option( 'ewww_image_optimizer_bulk_ngg_resume', '' );
					$ids = array();
					$gids = array_map( 'intval', $_REQUEST['doaction'] );
					// for each gallery we are given
					foreach ( $gids as $gid ) {
						// get a list of IDs
						$gallery_list = $nggdb->get_gallery( $gid );
						// for each ID
						foreach ( $gallery_list as $image ) {
							// add it to the array
							$images[] = $image->pid;
						}
					}
				}
				// otherwise, if we have an operation to resume
			} elseif ( ! empty( $resume ) ) {
				// get the list of attachment IDs from the db
				$images = get_option( 'ewww_image_optimizer_bulk_ngg_attachments' );
				// otherwise, if we are on the standard bulk page, get all the images in the db
			} elseif ( strpos( $hook, '_page_ewww-ngg-bulk' ) ) {
				global $wpdb;
				$images = $wpdb->get_col( "SELECT pid FROM $wpdb->nggpictures ORDER BY sortorder ASC" );
			}
			// store the image IDs to process in the db
			update_option( 'ewww_image_optimizer_bulk_ngg_attachments', $images, false );
			// add the EWWW IO script
			wp_enqueue_script( 'ewwwbulkscript', plugins_url( '/includes/eio.js', __FILE__ ), array( 'jquery', 'jquery-ui-progressbar', 'jquery-ui-slider', 'postbox', 'dashboard' ), EWWW_IMAGE_OPTIMIZER_VERSION );
			// replacing the built-in nextgen styling rules for progressbar, partially because the bulk optimize page doesn't work without them
			wp_deregister_style( 'ngg-jqueryui' );
			wp_deregister_style( 'ngg-jquery-ui' );
			add_action( 'admin_head', array( &$this, 'ewww_ngg_style_remove' ) );
			wp_register_style( 'jquery-ui-nextgen', plugins_url( '/includes/jquery-ui-1.10.1.custom.css', __FILE__ ) );
			// enqueue the progressbar styling
			wp_enqueue_style( 'jquery-ui-nextgen' ); // , plugins_url('jquery-ui-1.10.1.custom.css', __FILE__));
			// include all the vars we need for javascript
			wp_localize_script( 'ewwwbulkscript', 'ewww_vars', array(
				'_wpnonce' => wp_create_nonce( 'ewww-image-optimizer-bulk' ),
				'gallery' => 'nextgen',
				'attachments' => count( $images ),
				'operation_stopped' => esc_html__( 'Optimization stopped, reload page to resume.', 'ewww-image-optimizer' ),
				'operation_interrupted' => esc_html__( 'Operation Interrupted', 'ewww-image-optimizer' ),
				'temporary_failure' => esc_html__( 'Temporary failure, seconds left to retry:', 'ewww-image-optimizer' ),
				'remove_failed' => esc_html__( 'Could not remove image from table.', 'ewww-image-optimizer' ),
				'optimized' => esc_html__( 'Optimized', 'ewww-image-optimizer' ),
				)
			);
		}

		/* start the bulk operation */
		function ewww_ngg_bulk_init() {
			$permissions = apply_filters( 'ewww_image_optimizer_bulk_permissions', '' );
			if ( ! wp_verify_nonce( $_REQUEST['ewww_wpnonce'], 'ewww-image-optimizer-bulk' ) || ! current_user_can( $permissions ) ) {
				wp_die( esc_html__( 'Access denied.', 'ewww-image-optimizer' ) );
			}
			$output = array();
			// toggle the resume flag to indicate an operation is in progress
			update_option( 'ewww_image_optimizer_bulk_ngg_resume', 'true' );
			$attachments = get_option( 'ewww_image_optimizer_bulk_ngg_attachments' );
			if ( ! is_array( $attachments ) && ! empty( $attachments ) ) {
				$attachments = unserialize( $attachments );
			}
			if ( ! is_array( $attachments ) ) {
				$output['error'] = esc_html__( 'Error retrieving list of images' );
				echo json_encode( $output );
				die();
			}
			$id = array_shift( $attachments );
			$file = $this->ewww_ngg_bulk_filename( $id );
			// let the user know we are starting
			$loading_image = plugins_url( '/images/wpspin.gif', __FILE__ );
			if ( empty( $file ) ) {
				$output['results'] = '<p>' . esc_html__( 'Optimizing', 'ewww-image-optimizer' ) . "&nbsp;<img src='$loading_image' alt='loading'/></p>";
			} else {
				$output['results'] = '<p>' . esc_html__( 'Optimizing', 'ewww-image-optimizer' ) . " <b>$file</b>&nbsp;<img src='$loading_image' alt='loading'/></p>";
			}
			echo json_encode( $output );
			die();
		}

		/* output the filename of the image being optimized */
		function ewww_ngg_bulk_filename( $id ) {
			// creating the 'registry' object for working with nextgen
			$registry = C_Component_Registry::get_instance();
			// creating a database storage object from the 'registry' object
			$storage  = $registry->get_utility( 'I_Gallery_Storage' );
			// get an image object
			$image = $storage->object->_image_mapper->find( $id );
			// get the filename for the image, and output our current status
			$file_path = esc_html( $storage->get_image_abspath( $image, 'full' ) );
			if ( ! empty( $file_path ) ) {
				return $file_path;
			} else {
				return false;
			}
		}

		/* process each image in the bulk loop */
		function ewww_ngg_bulk_loop() {
			global $ewww_defer;
			$ewww_defer = false;
			$output = array();
			$permissions = apply_filters( 'ewww_image_optimizer_bulk_permissions', '' );
			if ( ! wp_verify_nonce( $_REQUEST['ewww_wpnonce'], 'ewww-image-optimizer-bulk' ) || ! current_user_can( $permissions ) ) {
				$output['error'] = esc_html__( 'Access token has expired, please reload the page.', 'ewww-image-optimizer' );
				echo json_encode( $output );
				die();
			}
			session_write_close();
			// find out if our nonce is on it's last leg/tick
			$tick = wp_verify_nonce( $_REQUEST['ewww_wpnonce'], 'ewww-image-optimizer-bulk' );
			if ( $tick === 2 ) {
				$output['new_nonce'] = wp_create_nonce( 'ewww-image-optimizer-bulk' );
			} else {
				$output['new_nonce'] = '';
			}
			// find out what time we started, in microseconds
			$started = microtime( true );
			// get the list of attachments remaining from the db
			$attachments = get_option( 'ewww_image_optimizer_bulk_ngg_attachments' );
			$id = array_shift( $attachments );
			// creating the 'registry' object for working with nextgen
			$registry = C_Component_Registry::get_instance();
			// creating a database storage object from the 'registry' object
			$storage  = $registry->get_utility( 'I_Gallery_Storage' );
			// get an image object
			$image = $storage->object->_image_mapper->find( $id );
			$image = $this->ewww_added_new_image( $image, $storage );
			$ewww_status = get_transient( 'ewww_image_optimizer_cloud_status' );
			if ( ! empty( $ewww_status ) && preg_match( '/exceeded/', $ewww_status ) ) {
				$output['error'] = esc_html__( 'License Exceeded', 'ewww-image-optimizer' );
				echo json_encode( $output );
				die();
			}
			// output the results of the optimization
			$output['results'] = sprintf( '<p>' . esc_html__( 'Optimized image:', 'ewww-image-optimizer' ) . ' <strong>%s</strong><br>', esc_html( basename( $storage->object->get_image_abspath( $image, 'full' ) ) ) );
			// get an array of sizes available for the $image
			$sizes = $storage->get_image_sizes();
			// run the optimizer on the image for each $size
			if ( ewww_image_optimizer_iterable( $sizes ) ) {
				foreach ( $sizes as $size ) {
					if ( $size === 'full' ) {
						$output['results'] .= sprintf( esc_html__( 'Full size - %s', 'ewww-image-optimizer' ) . '<br>', esc_html( $image->meta_data['ewww_image_optimizer'] ) );
					} elseif ( $size === 'thumbnail' ) {
						// output the results of the thumb optimization
						$output['results'] .= sprintf( esc_html__( 'Thumbnail - %s', 'ewww-image-optimizer' ) . '<br>', esc_html( $image->meta_data[ $size ]['ewww_image_optimizer'] ) );
					} else {
						// output savings for any other sizes, if they ever exist...
						$output['results'] .= ucfirst( $size ) . ' - ' . esc_html( $image->meta_data[ $size ]['ewww_image_optimizer'] ) . '<br>';
					}
				}
			}
			// outupt how much time we spent
			$elapsed = microtime( true ) - $started;
			$output['results'] .= sprintf( esc_html( _n( 'Elapsed: %s second', 'Elapsed: %s seconds', $elapsed, 'ewww-image-optimizer' ) ) . '</p>', number_format_i18n( $elapsed ) );
			// $output['results'] .= sprintf( esc_html__( 'Elapsed: %.3f seconds', 'ewww-image-optimizer' ) . "</p>", $elapsed );
			$output['completed'] = 1;
			// store the list back in the db
			update_option( 'ewww_image_optimizer_bulk_ngg_attachments', $attachments, false );
			if ( ! empty( $attachments ) ) {
				$next_attachment = array_shift( $attachments );
				$next_file = $this->ewww_ngg_bulk_filename( $next_attachment );
				$loading_image = plugins_url( '/images/wpspin.gif', __FILE__ );
				if ( $next_file ) {
					$output['next_file'] = '<p>' . esc_html__( 'Optimizing', 'ewww-image-optimizer' ) . " <b>$next_file</b>&nbsp;<img src='$loading_image' alt='loading'/></p>";
				} else {
					$output['next_file'] = '<p>' . esc_html__( 'Optimizing', 'ewww-image-optimizer' ) . "&nbsp;<img src='$loading_image' alt='loading'/></p>";
				}
			} else {
				$output['done'] = 1;
			}
			die( json_encode( $output ) );
		}

		/* finish the bulk operation */
		function ewww_ngg_bulk_cleanup() {
			$permissions = apply_filters( 'ewww_image_optimizer_bulk_permissions', '' );
			if ( ! wp_verify_nonce( $_REQUEST['ewww_wpnonce'], 'ewww-image-optimizer-bulk' ) || ! current_user_can( $permissions ) ) {
				wp_die( esc_html__( 'Access token has expired, please reload the page.', 'ewww-image-optimizer' ) );
			}
			// reset all the bulk options in the db
			update_option( 'ewww_image_optimizer_bulk_ngg_resume', '' );
			update_option( 'ewww_image_optimizer_bulk_ngg_attachments', '', false );
			// and let the user know we are done
			echo '<p><b>' . esc_html__( 'Finished Optimization!', 'ewww-image-optimizer' ) . '</b></p>';
			die();
		}

		// insert a bulk optimize option in the actions list for the gallery and image management pages (via javascript, since we have no hooks)
		function ewww_ngg_bulk_actions_script() {
			global $current_screen;
			if ( ( strpos( $current_screen->id, 'nggallery-manage-images' ) === false && strpos( $current_screen->id, 'nggallery-manage-gallery' ) === false ) || ! current_user_can( apply_filters( 'ewww_image_optimizer_bulk_permissions', '' ) ) ) {
				return;
			}
	?>		<script type="text/javascript">
			jQuery(document).ready(function($){
				$('select[name^="bulkaction"] option:last-child').after('<option value="bulk_optimize"><?php esc_html_e( 'Bulk Optimize', 'ewww-image-optimizer' ); ?></option>');
			});
		</script>
<?php		}
	}
	// initialize the plugin and the class
	global $ewwwngg;
	$ewwwngg = new EWWW_Nextgen();
}

if ( ! empty( $_REQUEST['page'] ) && $_REQUEST['page'] !== 'ngg_other_options' && ! class_exists( 'EWWWIO_Gallery_Storage' ) && class_exists( 'Mixin' ) && class_exists( 'C_Gallery_Storage' ) ) {
	class EWWWIO_Gallery_Storage extends Mixin {
		function generate_image_size( $image, $size, $params = null, $skip_defaults = false ) {
			ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
			if ( ! defined( 'EWWW_IMAGE_OPTIMIZER_CLOUD' ) ) {
				ewww_image_optimizer_cloud_init();
			}
			$success = $this->call_parent( 'generate_image_size', $image, $size, $params, $skip_defaults );
			if ( $success ) {
				$filename = $success->fileName;
				ewww_image_optimizer( $filename );
				ewwwio_debug_message( "nextgen dynamic thumb saved via extension: $filename" );
				$image_size = ewww_image_optimizer_filesize( $filename );
				ewwwio_debug_message( "optimized size: $image_size" );
			}
			ewww_image_optimizer_debug_log();
			ewwwio_memory( __FUNCTION__ );
			return $success;
		}
	}
	$storage = C_Gallery_Storage::get_instance();
	$storage->get_wrapped_instance()->add_mixin( 'EWWWIO_Gallery_Storage' );
}