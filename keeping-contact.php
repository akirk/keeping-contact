<?php
/**
 * Plugin Name: Keeping Contact
 * Description: Track contact frequency and outreach with your network. Integrates with Personal CRM.
 * Version: 1.0.0
 * Author: Alex
 * Requires Plugins: personal-crm
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

require_once KEEPING_CONTACT_PATH . 'includes/storage.php';
require_once KEEPING_CONTACT_PATH . 'includes/beeper.php';
require_once KEEPING_CONTACT_PATH . 'includes/keeping-contact.php';

register_activation_hook( __FILE__, function() {
	global $wpdb;
	$storage = new Storage( $wpdb );
	$storage->create_tables();
} );

KeepingContact::register_ajax_handlers();

add_action( 'personal_crm_loaded', function( $crm ) {
	KeepingContact::register_routes( $crm );
	KeepingContact::init( $crm );
} );
