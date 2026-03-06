<?php

namespace KeepingContact;

/**
 * Register WordPress Abilities API abilities for Keeping Contact.
 *
 * Requires the WordPress Abilities API plugin to be installed.
 * Silently no-ops if it is not available.
 */
function register_abilities() {
	if ( ! function_exists( 'wp_register_ability_category' ) || ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\register_ability_categories' );
	add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\register_kc_abilities' );
}

function register_ability_categories() {
	wp_register_ability_category( 'keeping-contact', array(
		'label'       => __( 'Keeping Contact', 'keeping-contact' ),
		'description' => __( 'Abilities for logging meetings and contact history.', 'keeping-contact' ),
	) );
}

function register_kc_abilities() {
	wp_register_ability( 'keeping-contact/log-contact', array(
		'label'       => __( 'Log Contact', 'keeping-contact' ),
		'description' => __( 'Record that you were in contact with a person on a given date.', 'keeping-contact' ),
		'category'    => 'keeping-contact',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'username'     => array( 'type' => 'string', 'description' => 'Username of the person', 'minLength' => 1 ),
				'date'         => array( 'type' => 'string', 'description' => 'Date of contact in YYYY-MM-DD format', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
				'contact_type' => array(
					'type'        => 'string',
					'description' => 'Type of contact',
					'enum'        => array( 'meeting', 'call', 'message', 'email', 'general' ),
					'default'     => 'meeting',
				),
				'notes'        => array( 'type' => 'string', 'description' => 'Optional notes about the contact' ),
			),
			'required'             => array( 'username', 'date' ),
			'additionalProperties' => false,
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'id'      => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => __NAMESPACE__ . '\ability_log_contact',
		'permission_callback' => __NAMESPACE__ . '\ability_permission_write',
		'meta' => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'instructions' => 'Use to record a meeting or interaction with an existing person. For bulk trip notes use import-meetings instead. The date is stored in the contact log and used to calculate when you are next due to reach out.',
				'readonly'     => false,
				'destructive'  => false,
				'idempotent'   => false,
			),
		),
	) );

	wp_register_ability( 'keeping-contact/import-meetings', array(
		'label'       => __( 'Import Meetings', 'keeping-contact' ),
		'description' => __( 'Batch-import meetings from trip notes in a single call. Creates new contacts as needed and logs a contact entry for each person. Returns a per-entry summary.', 'keeping-contact' ),
		'category'    => 'keeping-contact',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'meetings' => array(
					'type'     => 'array',
					'minItems' => 1,
					'items'    => array(
						'type'       => 'object',
						'properties' => array(
							'username'     => array( 'type' => 'string', 'description' => 'Username of existing person. Provide this OR name, not both.' ),
							'name'         => array( 'type' => 'string', 'description' => 'Full name for a new person (when username is absent).' ),
							'email'        => array( 'type' => 'string', 'description' => 'Email for new person (optional)' ),
							'role'         => array( 'type' => 'string', 'description' => 'Role/title for new person (optional)' ),
							'location'     => array( 'type' => 'string', 'description' => 'Location for new person (optional)' ),
							'groups'       => array(
								'type'        => 'array',
								'description' => 'Group slugs to assign a new person to (optional)',
								'items'       => array( 'type' => 'string' ),
							),
							'date'         => array( 'type' => 'string', 'description' => 'Meeting date in YYYY-MM-DD format', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
							'contact_type' => array(
								'type'    => 'string',
								'enum'    => array( 'meeting', 'call', 'message', 'email', 'general' ),
								'default' => 'meeting',
							),
							'notes'        => array( 'type' => 'string', 'description' => 'Notes about the contact (optional)' ),
						),
						'required' => array( 'date' ),
					),
				),
			),
			'required'             => array( 'meetings' ),
			'additionalProperties' => false,
		),
		'output_schema' => array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'username' => array( 'type' => 'string' ),
					'name'     => array( 'type' => 'string' ),
					'action'   => array( 'type' => 'string', 'description' => 'created, updated, or error' ),
					'error'    => array( 'type' => 'string' ),
				),
			),
		),
		'execute_callback'    => __NAMESPACE__ . '\ability_import_meetings',
		'permission_callback' => __NAMESPACE__ . '\ability_permission_write',
		'meta' => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'instructions' => 'Use after resolving names via personal-crm/search-people. For each entry: provide "username" if the person exists, or "name" (plus optional email/role/groups) for new contacts. All entries are processed in one call. Check the returned "action" per entry — "error" entries were not saved.',
				'readonly'     => false,
				'destructive'  => false,
				'idempotent'   => false,
			),
		),
	) );
}

function ability_permission_write() {
	return current_user_can( 'edit_posts' );
}

function ability_log_contact( $input ) {
	$kc      = KeepingContact::get_instance();
	$storage = $kc->storage;

	$crm_storage = \PersonalCRM\PersonalCrm::get_instance()->storage;
	if ( ! $crm_storage->get_person_id( $input['username'] ) ) {
		return new \WP_Error( 'person_not_found', sprintf( __( 'No person found with username "%s".', 'keeping-contact' ), $input['username'] ) );
	}

	$result = $storage->log_contact( $input['username'], array(
		'contact_date' => $input['date'],
		'contact_type' => $input['contact_type'] ?? 'meeting',
		'notes'        => sanitize_textarea_field( $input['notes'] ?? '' ),
	) );

	if ( $result === false ) {
		return new \WP_Error( 'save_failed', __( 'Failed to log contact.', 'keeping-contact' ) );
	}

	global $wpdb;
	return array( 'success' => true, 'id' => $wpdb->insert_id );
}

function ability_import_meetings( $input ) {
	$kc          = KeepingContact::get_instance();
	$kc_storage  = $kc->storage;
	$crm_storage = \PersonalCRM\PersonalCrm::get_instance()->storage;
	$results     = array();

	foreach ( $input['meetings'] as $entry ) {
		$username = ! empty( $entry['username'] ) ? sanitize_text_field( $entry['username'] ) : null;
		$action   = 'updated';

		if ( ! $username ) {
			if ( empty( $entry['name'] ) ) {
				$results[] = array( 'username' => '', 'name' => '', 'action' => 'error', 'error' => 'Each entry must have either "username" or "name".' );
				continue;
			}

			$name     = sanitize_text_field( $entry['name'] );
			$username = generate_username( $name, $crm_storage );

			if ( ! $crm_storage->get_person( $username ) ) {
				$person_data = build_person_data( $name, $entry );
				if ( $crm_storage->save_person( $username, $person_data ) === false ) {
					$results[] = array( 'username' => $username, 'name' => $name, 'action' => 'error', 'error' => 'Failed to create person.' );
					continue;
				}

				$person_id = $crm_storage->get_person_id( $username );
				if ( $person_id && ! empty( $entry['groups'] ) ) {
					foreach ( $entry['groups'] as $group_slug ) {
						$group = $crm_storage->get_group( sanitize_text_field( $group_slug ) );
						if ( $group ) {
							$crm_storage->add_person_to_group( $person_id, $group->id, 'default' );
						}
					}
				}

				$action = 'created';
			}
		}

		$person = $crm_storage->get_person( $username );
		if ( ! $person ) {
			$results[] = array( 'username' => $username, 'name' => $entry['name'] ?? '', 'action' => 'error', 'error' => sprintf( 'No person found with username "%s".', $username ) );
			continue;
		}

		$kc_storage->log_contact( $username, array(
			'contact_date' => $entry['date'],
			'contact_type' => $entry['contact_type'] ?? 'meeting',
			'notes'        => sanitize_textarea_field( $entry['notes'] ?? '' ),
		) );

		$results[] = array( 'username' => $username, 'name' => $person->name, 'action' => $action, 'error' => '' );
	}

	return $results;
}

function generate_username( $name, $storage ) {
	$base = strtolower( $name );

	if ( class_exists( 'Transliterator' ) ) {
		$transliterator = \Transliterator::create( 'Any-Latin; Latin-ASCII' );
		if ( $transliterator ) {
			$base = $transliterator->transliterate( $base );
		}
	}

	$base = preg_replace( '/[^a-z0-9\s-]/', '', $base );
	$base = preg_replace( '/\s+/', '-', trim( $base ) );
	$base = preg_replace( '/-+/', '-', $base );

	$username = $base;
	$suffix   = 2;
	while ( $storage->get_person_id( $username ) ) {
		$username = $base . '-' . $suffix;
		$suffix++;
	}

	return $username;
}

function build_person_data( $name, $input ) {
	return array(
		'name'                => $name,
		'nickname'            => '',
		'role'                => sanitize_text_field( $input['role'] ?? '' ),
		'email'               => sanitize_email( $input['email'] ?? '' ),
		'location'            => sanitize_text_field( $input['location'] ?? '' ),
		'timezone'            => '',
		'birthday'            => '',
		'company_anniversary' => '',
		'partner'             => '',
		'partner_birthday'    => '',
		'github'              => '',
		'linear'              => '',
		'wordpress'           => '',
		'linkedin'            => '',
		'website'             => '',
		'new_company'         => '',
		'new_company_website' => '',
		'deceased_date'       => '',
		'left_company'        => 0,
		'deceased'            => 0,
		'kids'                => array(),
		'github_repos'        => array(),
		'personal_events'     => array(),
		'links'               => array(),
	);
}
