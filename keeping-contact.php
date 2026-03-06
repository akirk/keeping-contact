<?php
/**
 * Plugin Name: Keeping Contact
 * Description: Track contact frequency and outreach with your network. Integrates with Personal CRM.
 * Version: 0.9.0
 * Author: Alex Kirk
 * Author URI: https://alex.kirk.at/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace KeepingContact;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KEEPING_CONTACT_PATH', plugin_dir_path( __FILE__ ) );
define( 'KEEPING_CONTACT_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( WP_PLUGIN_DIR . '/personal-crm/vendor/autoload.php' ) ) {
	require_once WP_PLUGIN_DIR . '/personal-crm/vendor/autoload.php';
}

if ( class_exists( '\WpApp\BaseStorage' ) ) {
	require_once KEEPING_CONTACT_PATH . 'includes/storage.php';
	require_once KEEPING_CONTACT_PATH . 'includes/beeper.php';
	require_once KEEPING_CONTACT_PATH . 'includes/keeping-contact.php';
	require_once KEEPING_CONTACT_PATH . 'includes/abilities.php';
}

register_activation_hook( __FILE__, function() {
	if ( ! class_exists( '\WpApp\BaseStorage' ) ) {
		return;
	}
	global $wpdb;
	$storage = new Storage( $wpdb );
	$storage->create_tables();
} );

// Check if Personal CRM is available.
// We use class_exists() instead of the "Requires Plugins" header because when
// Personal CRM is loaded from GitHub in WordPress Playground, the plugin slug
// becomes incorrect (e.g., "personal-crm-tree-main" instead of "personal-crm").
add_action( 'plugins_loaded', function() {
	if ( ! class_exists( 'PersonalCRM\PersonalCrm' ) ) {
		add_action( 'admin_notices', function() {
			echo '<div class="notice notice-error"><p><strong>Keeping Contact</strong> requires the Personal CRM plugin to be installed and activated.</p></div>';
		} );
		return;
	}
	KeepingContact::register_ajax_handlers();
	register_abilities();
}, 5 );

add_action( 'personal_crm_loaded', function( $crm ) {
	if ( ! class_exists( 'PersonalCRM\PersonalCrm' ) ) {
		return;
	}
	// Register tables for export/import
	$crm->register_export_table( 'keeping_contact_schedules', array(
		'unique_key' => 'username',
	) );
	$crm->register_export_table( 'keeping_contact_log' );
	$crm->register_export_table( 'keeping_contact_beeper_chats', array(
		'unique_key' => 'chat_id',
	) );

	// Add export option checkbox for contact log data
	add_filter( 'personal_crm_export_options', function( $options ) {
		$options['include_contact_log'] = array(
			'label'   => 'Include contact log and chat data',
			'default' => true,
		);
		return $options;
	} );

	// Skip contact log and beeper chats unless the option is checked
	add_filter( 'personal_crm_export_skip_table', function( $skip, $table_name, $plugin_options, $exclude_personal ) {
		$personal_tables = array( 'keeping_contact_log', 'keeping_contact_beeper_chats' );
		if ( in_array( $table_name, $personal_tables, true ) ) {
			// Skip only if the include_contact_log option is not checked
			// The plugin-specific checkbox takes precedence over exclude_personal
			if ( empty( $plugin_options['include_contact_log'] ) ) {
				return true;
			}
		}
		return $skip;
	}, 10, 4 );

	KeepingContact::register_routes( $crm );
	KeepingContact::init( $crm );
} );
