<?php
/**
 * Keeping Contact Storage Class
 *
 * Database storage for contact schedules and outreach logs.
 */

namespace KeepingContact;

class Storage extends \WpApp\BaseStorage {

	public function create_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $this->wpdb->get_charset_collate();
		$schema = $this->get_schema();
		$sql = array();

		foreach ( $schema as $table_name => $columns ) {
			$full_table_name = $this->wpdb->prefix . $table_name;
			$sql[] = "CREATE TABLE $full_table_name (\n$columns\n) $charset_collate;";
		}

		return dbDelta( $sql );
	}

	public function get_schema() {
		return array(
			'keeping_contact_schedules' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				username varchar(100) NOT NULL,
				frequency_days int(11) NOT NULL DEFAULT 90,
				priority varchar(20) DEFAULT 'normal',
				paused tinyint(1) DEFAULT 0,
				notes text DEFAULT '',
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY unique_username (username),
				KEY idx_frequency (frequency_days),
				KEY idx_priority (priority)
			",
			'keeping_contact_log' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				username varchar(100) NOT NULL,
				contact_date date NOT NULL,
				contact_type varchar(50) DEFAULT 'general',
				notes text DEFAULT '',
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_username (username),
				KEY idx_contact_date (contact_date),
				KEY idx_username_date (username, contact_date)
			"
		);
	}

	/**
	 * Get contact schedule for a person
	 */
	public function get_schedule( $username ) {
		return $this->wpdb->get_row( $this->wpdb->prepare(
			"SELECT * FROM {$this->wpdb->prefix}keeping_contact_schedules WHERE username = %s",
			$username
		), ARRAY_A );
	}

	/**
	 * Get all schedules
	 */
	public function get_all_schedules() {
		$results = $this->wpdb->get_results(
			"SELECT * FROM {$this->wpdb->prefix}keeping_contact_schedules ORDER BY frequency_days ASC",
			ARRAY_A
		);

		$schedules = array();
		foreach ( $results as $row ) {
			$schedules[ $row['username'] ] = $row;
		}
		return $schedules;
	}

	/**
	 * Save contact schedule for a person
	 */
	public function save_schedule( $username, $data ) {
		$schedule_data = array(
			'username'       => $username,
			'frequency_days' => $data['frequency_days'] ?? 90,
			'priority'       => $data['priority'] ?? 'normal',
			'paused'         => $data['paused'] ?? 0,
			'notes'          => $data['notes'] ?? '',
			'updated_at'     => current_time( 'mysql' ),
		);

		$existing = $this->wpdb->get_var( $this->wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->wpdb->prefix}keeping_contact_schedules WHERE username = %s",
			$username
		) );

		if ( $existing ) {
			return $this->wpdb->update(
				$this->wpdb->prefix . 'keeping_contact_schedules',
				$schedule_data,
				array( 'username' => $username ),
				array( '%s', '%d', '%s', '%d', '%s', '%s' ),
				array( '%s' )
			);
		} else {
			$schedule_data['created_at'] = current_time( 'mysql' );
			return $this->wpdb->insert(
				$this->wpdb->prefix . 'keeping_contact_schedules',
				$schedule_data,
				array( '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Delete contact schedule
	 */
	public function delete_schedule( $username ) {
		return $this->wpdb->delete(
			$this->wpdb->prefix . 'keeping_contact_schedules',
			array( 'username' => $username ),
			array( '%s' )
		);
	}

	/**
	 * Log a contact with a person
	 */
	public function log_contact( $username, $data ) {
		return $this->wpdb->insert(
			$this->wpdb->prefix . 'keeping_contact_log',
			array(
				'username'     => $username,
				'contact_date' => $data['contact_date'] ?? current_time( 'Y-m-d' ),
				'contact_type' => $data['contact_type'] ?? 'general',
				'notes'        => $data['notes'] ?? '',
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get contact log for a person
	 */
	public function get_contact_log( $username, $limit = 10 ) {
		return $this->wpdb->get_results( $this->wpdb->prepare(
			"SELECT * FROM {$this->wpdb->prefix}keeping_contact_log
			 WHERE username = %s
			 ORDER BY contact_date DESC, created_at DESC
			 LIMIT %d",
			$username, $limit
		), ARRAY_A );
	}

	/**
	 * Get last contact date for a person
	 */
	public function get_last_contact( $username ) {
		return $this->wpdb->get_row( $this->wpdb->prepare(
			"SELECT * FROM {$this->wpdb->prefix}keeping_contact_log
			 WHERE username = %s
			 ORDER BY contact_date DESC, created_at DESC
			 LIMIT 1",
			$username
		), ARRAY_A );
	}

	/**
	 * Get last contact dates for multiple people (bulk query)
	 */
	public function get_last_contacts_bulk( $usernames ) {
		if ( empty( $usernames ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $usernames ), '%s' ) );
		$query = $this->wpdb->prepare(
			"SELECT l1.* FROM {$this->wpdb->prefix}keeping_contact_log l1
			 INNER JOIN (
			 	SELECT username, MAX(contact_date) as max_date
			 	FROM {$this->wpdb->prefix}keeping_contact_log
			 	WHERE username IN ($placeholders)
			 	GROUP BY username
			 ) l2 ON l1.username = l2.username AND l1.contact_date = l2.max_date
			 ORDER BY l1.contact_date DESC",
			...$usernames
		);

		$results = $this->wpdb->get_results( $query, ARRAY_A );

		$contacts = array();
		foreach ( $results as $row ) {
			$contacts[ $row['username'] ] = $row;
		}
		return $contacts;
	}

	/**
	 * Delete a contact log entry
	 */
	public function delete_contact_log( $id ) {
		return $this->wpdb->delete(
			$this->wpdb->prefix . 'keeping_contact_log',
			array( 'id' => $id ),
			array( '%d' )
		);
	}

	/**
	 * Get people who are overdue for contact
	 */
	public function get_overdue_contacts() {
		$query = "
			SELECT
				s.username,
				s.frequency_days,
				s.priority,
				s.paused,
				s.notes as schedule_notes,
				MAX(l.contact_date) as last_contact_date,
				DATEDIFF(CURDATE(), MAX(l.contact_date)) as days_since_contact,
				DATEDIFF(CURDATE(), MAX(l.contact_date)) - s.frequency_days as days_overdue
			FROM {$this->wpdb->prefix}keeping_contact_schedules s
			LEFT JOIN {$this->wpdb->prefix}keeping_contact_log l ON s.username = l.username
			WHERE s.paused = 0
			GROUP BY s.username, s.frequency_days, s.priority, s.paused, s.notes
			HAVING last_contact_date IS NULL
			   OR DATEDIFF(CURDATE(), last_contact_date) > s.frequency_days
			ORDER BY
				CASE s.priority
					WHEN 'high' THEN 1
					WHEN 'normal' THEN 2
					WHEN 'low' THEN 3
				END,
				days_overdue DESC
		";

		return $this->wpdb->get_results( $query, ARRAY_A );
	}

	/**
	 * Get upcoming contacts (due within X days)
	 */
	public function get_upcoming_contacts( $within_days = 7 ) {
		$query = $this->wpdb->prepare( "
			SELECT
				s.username,
				s.frequency_days,
				s.priority,
				s.notes as schedule_notes,
				MAX(l.contact_date) as last_contact_date,
				DATEDIFF(CURDATE(), MAX(l.contact_date)) as days_since_contact,
				s.frequency_days - DATEDIFF(CURDATE(), MAX(l.contact_date)) as days_until_due
			FROM {$this->wpdb->prefix}keeping_contact_schedules s
			LEFT JOIN {$this->wpdb->prefix}keeping_contact_log l ON s.username = l.username
			WHERE s.paused = 0
			GROUP BY s.username, s.frequency_days, s.priority, s.notes
			HAVING last_contact_date IS NOT NULL
			   AND DATEDIFF(CURDATE(), last_contact_date) <= s.frequency_days
			   AND s.frequency_days - DATEDIFF(CURDATE(), last_contact_date) <= %d
			ORDER BY days_until_due ASC
		", $within_days );

		return $this->wpdb->get_results( $query, ARRAY_A );
	}

	/**
	 * Get contact statistics for a person
	 */
	public function get_contact_stats( $username ) {
		$schedule = $this->get_schedule( $username );
		$last_contact = $this->get_last_contact( $username );

		$stats = array(
			'schedule'           => $schedule,
			'last_contact'       => $last_contact,
			'days_since_contact' => null,
			'days_until_due'     => null,
			'is_overdue'         => false,
			'status'             => 'no_schedule',
		);

		if ( ! $schedule ) {
			return $stats;
		}

		if ( $schedule['paused'] ) {
			$stats['status'] = 'paused';
			return $stats;
		}

		if ( ! $last_contact ) {
			$stats['status'] = 'never_contacted';
			$stats['is_overdue'] = true;
			return $stats;
		}

		$last_date = new \DateTime( $last_contact['contact_date'] );
		$today = new \DateTime( current_time( 'Y-m-d' ) );
		$days_since = $today->diff( $last_date )->days;

		$stats['days_since_contact'] = $days_since;
		$stats['days_until_due'] = $schedule['frequency_days'] - $days_since;
		$stats['is_overdue'] = $days_since > $schedule['frequency_days'];

		if ( $stats['is_overdue'] ) {
			$stats['status'] = 'overdue';
		} elseif ( $stats['days_until_due'] <= 7 ) {
			$stats['status'] = 'due_soon';
		} else {
			$stats['status'] = 'on_track';
		}

		return $stats;
	}
}
