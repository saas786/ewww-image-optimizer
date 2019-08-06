<?php
/**
 * Implements basic and common utility functions for all sub-classes.
 *
 * @link https://ewww.io
 * @package EIO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'EIO_Base' ) ) {
	/**
	 * HTML element and attribute parsing, replacing, etc.
	 */
	class EIO_Base {

		/**
		 * Content directory (URL) for the plugin to use.
		 *
		 * @access protected
		 * @var string $content_url
		 */
		protected $content_url = WP_CONTENT_URL . 'eio/';

		/**
		 * Content directory (path) for the plugin to use.
		 *
		 * @access protected
		 * @var string $content_dir
		 */
		protected $content_dir = WP_CONTENT_DIR . '/eio\/';

		/**
		 * Plugin version for the plugin.
		 *
		 * @access protected
		 * @var float $version
		 */
		protected $version = 1.1;

		/**
		 * Set class properties for children.
		 */
		function __construct() {
			if ( defined( 'EWWW_IMAGE_OPTIMIZER_VERSION' ) ) {
				$this->content_url = content_url( 'ewww/' );
				$this->content_dir = WP_CONTENT_DIR . '/ewww\/';
				$this->version     = EWWW_IMAGE_OPTIMIZER_VERSION;
			} elseif ( defined( 'EASYIO_VERSION' ) ) {
				$this->content_url = content_url( 'easyio/' );
				$this->content_dir = WP_CONTENT_DIR . '/easyio\/';
				$this->version     = EASYIO_VERSION;
			} else {
				$this->content_url = content_url( 'eio/' );
				$this->content_dir = WP_CONTENT_DIR . '/eio\/';
			}
		}
		/**
		 * Saves the in-memory debug log to a logfile in the plugin folder.
		 *
		 * @global string $eio_debug The in-memory debug log.
		 */
		function debug_log() {
			global $eio_debug;
			global $eio_temp_debug;
			$debug_log = $this->content_dir . 'debug.log';
			if ( is_writable( WP_CONTENT_DIR ) && ! is_dir( $this->content_dir ) ) {
				mkdir( $this->content_dir );
			}
			$debug_enabled = defined( 'EWWW_IMAGE_OPTIMIZER_VERSION' ) ? $this->get_option( 'ewww_image_optimizer_debug' ) : $this->get_option( 'easyio_debug' );
			if ( ! empty( $eio_debug ) && empty( $eio_temp_debug ) && $debug_enabled && is_writable( $this->content_dir ) ) {
				$memory_limit = $this->memory_limit();
				clearstatcache();
				$timestamp = date( 'Y-m-d H:i:s' ) . "\n";
				if ( ! file_exists( $debug_log ) ) {
					touch( $debug_log );
				} else {
					if ( filesize( $debug_log ) + 4000000 + memory_get_usage( true ) > $memory_limit ) {
						unlink( $debug_log );
						touch( $debug_log );
					}
				}
				if ( filesize( $debug_log ) + strlen( $eio_debug ) + 4000000 + memory_get_usage( true ) <= $memory_limit && is_writable( $debug_log ) ) {
					$eio_debug = str_replace( '<br>', "\n", $eio_debug );
					file_put_contents( $debug_log, $timestamp . $eio_debug, FILE_APPEND );
				}
			}
			$eio_debug = '';
		}

		/**
		 * Adds information to the in-memory debug log.
		 *
		 * @global string $eio_debug The in-memory debug log.
		 * @global bool   $eio_temp_debug Indicator that we are temporarily debugging on the wp-admin.
		 *
		 * @param string $message Debug information to add to the log.
		 */
		function debug_message( $message ) {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				WP_CLI::debug( $message );
				return;
			}
			global $eio_temp_debug;
			if ( $eio_temp_debug || $this->get_option( 'ewww_image_optimizer_debug' ) || $this->get_option( 'easyio_debug' ) ) {
				$memory_limit = $this->memory_limit();
				if ( strlen( $message ) + 4000000 + memory_get_usage( true ) <= $memory_limit ) {
					global $eio_debug;
					$message    = str_replace( "\n\n\n", '<br>', $message );
					$message    = str_replace( "\n\n", '<br>', $message );
					$message    = str_replace( "\n", '<br>', $message );
					$eio_debug .= "$message<br>";
				} else {
					global $eio_debug;
					$eio_debug = "not logging message, memory limit is $memory_limit";
				}
			}
		}

		/**
		 * Check for GD support.
		 *
		 * @return bool Debug True if GD support detected.
		 */
		function gd_support() {
			$this->debug_message( '<b>' . __FUNCTION__ . '()</b>' );
			if ( function_exists( 'gd_info' ) ) {
				$gd_support = gd_info();
				$this->debug_message( 'GD found, supports:' );
				if ( $this->is_iterable( $gd_support ) ) {
					foreach ( $gd_support as $supports => $supported ) {
						$this->debug_message( "$supports: $supported" );
					}
					if ( ( ! empty( $gd_support['JPEG Support'] ) || ! empty( $gd_support['JPG Support'] ) ) && ! empty( $gd_support['PNG Support'] ) ) {
						return true;
					}
				}
			}
			return false;
		}

		/**
		 * Retrieve option: use 'site' setting if plugin is network activated, otherwise use 'blog' setting.
		 *
		 * Retrieves multi-site and single-site options as appropriate as well as allowing overrides with
		 * same-named constant. Overrides are only available for integer and boolean options.
		 *
		 * @param string $option_name The name of the option to retrieve.
		 * @return mixed The value of the option.
		 */
		function get_option( $option_name ) {
			$option_name   = defined( 'EWWW_IMAGE_OPTIMIZER_VERSION' ) ? $option_name : str_replace( 'ewww_image_optimizer', 'easyio', $option_name );
			$constant_name = strtoupper( $option_name );
			if ( defined( $constant_name ) && ( is_int( constant( $constant_name ) ) || is_bool( constant( $constant_name ) ) ) ) {
				return constant( $constant_name );
			}
			if ( ! function_exists( 'is_plugin_active_for_network' ) && is_multisite() ) {
				// Need to include the plugin library for the is_plugin_active function.
				require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
			}
			if (
				is_multisite() &&
				is_plugin_active_for_network( basename( plugin_dir_path( __FILE__ ) ) . '/' . basename( __FILE__ ) ) &&
				! get_site_option( 'ewww_image_optimizer_allow_multisite_override' ) &&
				! get_site_option( 'easyio_allow_multisite_override' )
			) {
				$option_value = get_site_option( $option_name );
			} else {
				$option_value = get_option( $option_name );
			}
			return $option_value;
		}

		/**
		 * Checks to see if the current page being output is an AMP page.
		 *
		 * @return bool True for an AMP endpoint, false otherwise.
		 */
		function is_amp() {
			if ( function_exists( 'is_amp_endpoint' ) && is_amp_endpoint() ) {
				return true;
			}
			if ( function_exists( 'ampforwp_is_amp_endpoint' ) && ampforwp_is_amp_endpoint() ) {
				return true;
			}
			return false;
		}

		/**
		 * Make sure an array/object can be parsed by a foreach().
		 *
		 * @param mixed $var A variable to test for iteration ability.
		 * @return bool True if the variable is iterable.
		 */
		function is_iterable( $var ) {
			return ! empty( $var ) && ( is_array( $var ) || $var instanceof Traversable );
		}

		/**
		 * Finds the current PHP memory limit or a reasonable default.
		 *
		 * @return int The memory limit in bytes.
		 */
		function memory_limit() {
			if ( defined( 'EIO_MEMORY_LIMIT' ) && EIO_MEMORY_LIMIT ) {
				$memory_limit = EIO_MEMORY_LIMIT;
			} elseif ( function_exists( 'ini_get' ) ) {
				$memory_limit = ini_get( 'memory_limit' );
			} else {
				if ( ! defined( 'EIO_MEMORY_LIMIT' ) ) {
					// Conservative default, current usage + 16M.
					$current_memory = memory_get_usage( true );
					$memory_limit   = round( $current_memory / ( 1024 * 1024 ) ) + 16;
					define( 'EIO_MEMORY_LIMIT', $memory_limit );
				}
			}
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				WP_CLI::debug( "memory limit is set at $memory_limit" );
			}
			if ( ! $memory_limit || -1 === intval( $memory_limit ) ) {
				// Unlimited, set to 32GB.
				$memory_limit = '32000M';
			}
			if ( strpos( $memory_limit, 'G' ) ) {
				$memory_limit = intval( $memory_limit ) * 1024 * 1024 * 1024;
			} else {
				$memory_limit = intval( $memory_limit ) * 1024 * 1024;
			}
			return $memory_limit;
		}
	}
}
