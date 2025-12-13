<?php
/**
 * Keeping Contact Main Class
 *
 * Integrates with Personal CRM to track contact frequency and outreach.
 */

namespace KeepingContact;

class KeepingContact {
	private $crm;
	private static $instance;
	public $storage;
	public $beeper;

	public function __construct( $crm ) {
		$this->crm = $crm;

		global $wpdb;
		$this->storage = new Storage( $wpdb );
		$this->beeper = new Beeper();

		// Enqueue styles early
		$this->enqueue_styles();

		add_action( 'personal_crm_person_sidebar', [ $this, 'render_person_sidebar' ], 20, 2 );
		add_action( 'personal_crm_person_sidebar', [ $this, 'render_beeper_sidebar' ], 25, 2 );
		add_action( 'personal_crm_person_quick_links', [ $this, 'render_quick_links' ], 10, 3 );
		add_action( 'personal_crm_admin_person_form_fields', [ $this, 'render_admin_fields' ], 10, 2 );
		add_action( 'personal_crm_admin_person_form_fields', [ $this, 'render_beeper_admin_fields' ], 15, 2 );
		add_action( 'personal_crm_admin_person_save', [ $this, 'save_person_data' ], 10, 3 );
		add_action( 'personal_crm_admin_person_save', [ $this, 'save_beeper_data' ], 10, 3 );
		add_action( 'personal_crm_dashboard_sidebar', [ $this, 'render_dashboard_sidebar' ], 20, 2 );
		add_filter( 'personal_crm_build_url', [ $this, 'build_clean_urls' ], 10, 2 );
		add_action( 'wp_app_admin_bar_menu', [ $this, 'add_masterbar_menu' ] );
		add_action( 'personal_crm_footer_links', [ $this, 'render_group_analysis_link' ], 10, 2 );
	}

	/**
	 * Enqueue keeping-contact styles
	 */
	private function enqueue_styles() {
		if ( function_exists( 'wp_app_enqueue_style' ) ) {
			wp_app_enqueue_style( 'keeping-contact', plugin_dir_url( __DIR__ ) . 'assets/style.css' );
		}
	}

	public static function register_ajax_handlers() {
		add_action( 'wp_ajax_keeping_contact_log', [ __CLASS__, 'static_ajax_log_contact' ] );
		add_action( 'wp_ajax_kc_beeper_search', [ __CLASS__, 'static_ajax_beeper_search' ] );
		add_action( 'wp_ajax_kc_beeper_link', [ __CLASS__, 'static_ajax_beeper_link' ] );
		add_action( 'wp_ajax_kc_beeper_unlink', [ __CLASS__, 'static_ajax_beeper_unlink' ] );
		add_action( 'wp_ajax_kc_beeper_sync', [ __CLASS__, 'static_ajax_beeper_sync' ] );
		add_action( 'wp_ajax_kc_beeper_test', [ __CLASS__, 'static_ajax_beeper_test' ] );
		add_action( 'wp_ajax_kc_beeper_save_token', [ __CLASS__, 'static_ajax_beeper_save_token' ] );
		add_action( 'wp_ajax_kc_beeper_send', [ __CLASS__, 'static_ajax_beeper_send' ] );
		add_action( 'wp_ajax_kc_beeper_messages', [ __CLASS__, 'static_ajax_beeper_messages' ] );
	}

	private static function get_beeper() {
		return new Beeper();
	}

	private static function get_storage() {
		global $wpdb;
		return new Storage( $wpdb );
	}

	public static function static_ajax_log_contact() {
		check_ajax_referer( 'keeping_contact_log', 'nonce' );

		$username = sanitize_text_field( $_POST['username'] ?? '' );
		$contact_type = sanitize_text_field( $_POST['contact_type'] ?? 'general' );
		$contact_date = sanitize_text_field( $_POST['contact_date'] ?? current_time( 'Y-m-d' ) );
		$notes = sanitize_textarea_field( $_POST['notes'] ?? '' );

		if ( empty( $username ) ) {
			wp_send_json_error( 'Username required' );
		}

		$storage = self::get_storage();
		$result = $storage->log_contact( $username, [
			'contact_type' => $contact_type,
			'contact_date' => $contact_date,
			'notes'        => $notes,
		] );

		if ( $result ) {
			wp_send_json_success( [ 'message' => 'Contact logged' ] );
		} else {
			wp_send_json_error( 'Failed to log contact' );
		}
	}

	public static function static_ajax_beeper_search() {
		check_ajax_referer( 'kc_beeper', '_wpnonce' );

		$username = sanitize_text_field( $_POST['username'] ?? '' );
		$name = sanitize_text_field( $_POST['name'] ?? '' );

		if ( empty( $username ) ) {
			wp_send_json_error( 'Username required' );
		}

		$beeper = self::get_beeper();
		$all_chats = [];
		$queries_tried = [];

		// First try phone numbers
		$phone_numbers = self::get_person_phone_numbers( $username );
		foreach ( $phone_numbers as $phone ) {
			$queries_tried[] = $phone;
			$chats = $beeper->search_chats( $phone, 10 );
			if ( ! is_wp_error( $chats ) && ! empty( $chats['items'] ) ) {
				foreach ( $chats['items'] as $chat ) {
					$all_chats[ $chat['id'] ] = $chat;
				}
			}
		}

		// Fall back to name search if no phone results
		if ( empty( $all_chats ) && ! empty( $name ) ) {
			$queries_tried[] = $name;
			$chats = $beeper->search_chats( $name, 10 );
			if ( ! is_wp_error( $chats ) && ! empty( $chats['items'] ) ) {
				foreach ( $chats['items'] as $chat ) {
					$all_chats[ $chat['id'] ] = $chat;
				}
			}
		}

		wp_send_json_success( [
			'chats' => array_values( $all_chats ),
			'queries' => $queries_tried,
		] );
	}

	private static function get_person_phone_numbers( $username ) {
		$raw_phones = apply_filters( 'personal_crm_person_phone_numbers', [], $username );

		$phones = [];
		foreach ( $raw_phones as $raw ) {
			// Just clean up the number, keep original format
			$clean = preg_replace( '/[^0-9+]/', '', $raw );
			if ( ! empty( $clean ) ) {
				$phones[] = $clean;
			}
		}

		return array_unique( $phones );
	}

	public static function static_ajax_beeper_link() {
		check_ajax_referer( 'kc_beeper', '_wpnonce' );

		$username = sanitize_text_field( $_POST['username'] ?? '' );
		$chat_id = sanitize_text_field( $_POST['chat_id'] ?? '' );

		if ( empty( $username ) || empty( $chat_id ) ) {
			wp_send_json_error( 'Username and chat_id required' );
		}

		$chat_ids = get_option( 'kc_beeper_chats_' . $username, [] );
		if ( ! is_array( $chat_ids ) ) {
			$chat_ids = ! empty( $chat_ids ) ? [ $chat_ids ] : [];
		}

		if ( ! in_array( $chat_id, $chat_ids ) ) {
			$chat_ids[] = $chat_id;
			update_option( 'kc_beeper_chats_' . $username, $chat_ids );
		}

		self::static_sync_beeper_contact( $username, $chat_id );

		wp_send_json_success( [ 'linked' => true ] );
	}

	public static function static_ajax_beeper_unlink() {
		check_ajax_referer( 'kc_beeper', '_wpnonce' );

		$username = sanitize_text_field( $_POST['username'] ?? '' );
		$chat_id = sanitize_text_field( $_POST['chat_id'] ?? '' );

		if ( empty( $username ) || empty( $chat_id ) ) {
			wp_send_json_error( 'Username and chat_id required' );
		}

		$chat_ids = get_option( 'kc_beeper_chats_' . $username, [] );
		if ( ! is_array( $chat_ids ) ) {
			$chat_ids = [];
		}

		$chat_ids = array_values( array_diff( $chat_ids, [ $chat_id ] ) );
		update_option( 'kc_beeper_chats_' . $username, $chat_ids );

		wp_send_json_success( [ 'unlinked' => true ] );
	}

	public static function static_ajax_beeper_sync() {
		check_ajax_referer( 'kc_beeper', '_wpnonce' );

		$username = sanitize_text_field( $_POST['username'] ?? '' );
		$chat_id = sanitize_text_field( $_POST['chat_id'] ?? '' );

		if ( empty( $username ) || empty( $chat_id ) ) {
			wp_send_json_error( 'Username and chat_id required' );
		}

		$result = self::static_sync_beeper_contact( $username, $chat_id );

		if ( $result ) {
			wp_send_json_success( [ 'synced' => true, 'date' => $result ] );
		} else {
			wp_send_json_error( 'No messages found' );
		}
	}

	private static function static_sync_beeper_contact( $username, $chat_id ) {
		$beeper = self::get_beeper();
		$storage = self::get_storage();

		$last_date = $beeper->get_last_contact_date( $chat_id );

		if ( ! $last_date ) {
			return false;
		}

		$contact_date = date( 'Y-m-d', strtotime( $last_date ) );

		$last_contact = $storage->get_last_contact( $username );
		if ( ! $last_contact || $last_contact['contact_date'] < $contact_date ) {
			$storage->log_contact( $username, [
				'contact_type' => 'message',
				'contact_date' => $contact_date,
				'notes'        => 'Synced from Beeper',
			] );
		}

		return $contact_date;
	}

	public static function static_ajax_beeper_test() {
		check_ajax_referer( 'kc_beeper', '_wpnonce' );

		$beeper = self::get_beeper();
		$result = $beeper->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	public static function static_ajax_beeper_save_token() {
		check_ajax_referer( 'kc_beeper', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$token = sanitize_text_field( $_POST['token'] ?? '' );
		$beeper = self::get_beeper();
		$beeper->set_token( $token );

		if ( ! empty( $token ) ) {
			$test = $beeper->test_connection();
			if ( is_wp_error( $test ) ) {
				wp_send_json_error( 'Token saved but connection failed: ' . $test->get_error_message() );
			}
			wp_send_json_success( [ 'connected' => true, 'accounts' => $test['accounts'] ] );
		}

		wp_send_json_success( [ 'cleared' => true ] );
	}

	public static function static_ajax_beeper_send() {
		check_ajax_referer( 'kc_beeper', '_wpnonce' );

		$chat_id = sanitize_text_field( $_POST['chat_id'] ?? '' );
		$text = sanitize_textarea_field( $_POST['text'] ?? '' );
		$username = sanitize_text_field( $_POST['username'] ?? '' );

		if ( empty( $chat_id ) || empty( $text ) ) {
			wp_send_json_error( 'Chat ID and message text required' );
		}

		$beeper = self::get_beeper();
		$result = $beeper->send_message( $chat_id, $text );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		// Log the contact
		if ( ! empty( $username ) ) {
			$storage = self::get_storage();
			$storage->log_contact( $username, [
				'contact_type' => 'message',
				'contact_date' => current_time( 'Y-m-d' ),
				'notes'        => 'Sent via Beeper: ' . wp_trim_words( $text, 20 ),
			] );
		}

		wp_send_json_success( [ 'sent' => true ] );
	}

	public static function static_ajax_beeper_messages() {
		check_ajax_referer( 'kc_beeper', '_wpnonce' );

		$chat_id = sanitize_text_field( $_POST['chat_id'] ?? '' );
		$cursor = sanitize_text_field( $_POST['cursor'] ?? '' );

		if ( empty( $chat_id ) ) {
			wp_send_json_error( 'Chat ID required' );
		}

		$beeper = self::get_beeper();
		$result = $beeper->get_recent_context( $chat_id, 30, $cursor ?: null );

		if ( empty( $result['messages'] ) && ! $cursor ) {
			wp_send_json_error( 'Could not load messages' );
		}

		wp_send_json_success( $result );
	}

	public static function get_instance() {
		return self::$instance;
	}

	public static function init( $crm ) {
		if ( ! self::$instance ) {
			self::$instance = new self( $crm );
		}
	}

	public static function register_routes( $crm ) {
		$app = $crm->app;
		$app->route( 'outreach', KEEPING_CONTACT_PATH . 'outreach-dashboard.php' );
		$app->route( 'outreach/{person}', KEEPING_CONTACT_PATH . 'person-outreach.php' );
		$app->route( 'outreach-settings', KEEPING_CONTACT_PATH . 'settings.php' );
		$app->route( 'outreach-beeper', KEEPING_CONTACT_PATH . 'beeper-audit.php' );
		$app->route( 'conversations/{person}', KEEPING_CONTACT_PATH . 'conversation.php' );
		$app->route( 'analysis/{person}', KEEPING_CONTACT_PATH . 'analysis.php' );
		$app->route( 'analysis-group/{group}', KEEPING_CONTACT_PATH . 'analysis-group.php' );
	}

	public static function get_globals() {
		$instance = self::get_instance();
		if ( ! $instance ) {
			return array();
		}

		return array(
			'keeping_contact' => $instance,
			'kc_storage'      => $instance->storage,
		);
	}

	/**
	 * Render contact status in person sidebar
	 */
	public function render_person_sidebar( $person, $is_team_member ) {
		$stats = $this->storage->get_contact_stats( $person->username );

		if ( $stats['status'] === 'no_schedule' ) {
			return;
		}

		$status_colors = [
			'overdue'         => '#dc3545',
			'due_soon'        => '#fd7e14',
			'on_track'        => '#28a745',
			'never_contacted' => '#dc3545',
			'paused'          => '#6c757d',
		];

		$status_labels = [
			'overdue'         => 'Overdue',
			'due_soon'        => 'Due Soon',
			'on_track'        => 'On Track',
			'never_contacted' => 'Never Contacted',
			'paused'          => 'Paused',
		];

		$frequency_label = $this->format_frequency( $stats['schedule']['frequency_days'] );
		$status_class = str_replace( '_', '-', $stats['status'] );
		?>
		<div class="kc-sidebar-section">
			<div class="kc-sidebar-header">
				<h3>Keeping Contact</h3>
				<span class="kc-sidebar-badge status-badge <?php echo esc_attr( $status_class ); ?>">
					<?php echo esc_html( $status_labels[ $stats['status'] ] ); ?>
				</span>
			</div>

			<div class="kc-sidebar-content">
				<div class="kc-sidebar-row"><strong>Frequency:</strong> <?php echo esc_html( $frequency_label ); ?></div>

				<?php if ( $stats['last_contact'] ) : ?>
					<div class="kc-sidebar-row">
						<strong>Last contact:</strong>
						<?php echo esc_html( date( 'M j, Y', strtotime( $stats['last_contact']['contact_date'] ) ) ); ?>
						<span class="context-muted">(<?php echo esc_html( $stats['days_since_contact'] ); ?> days ago)</span>
					</div>
				<?php else : ?>
					<div class="kc-sidebar-row"><strong>Last contact:</strong> <em>Never</em></div>
				<?php endif; ?>

				<?php if ( $stats['status'] !== 'paused' && $stats['days_until_due'] !== null ) : ?>
					<div class="kc-sidebar-row">
						<strong>Next due:</strong>
						<?php if ( $stats['days_until_due'] < 0 ) : ?>
							<span class="text-overdue"><?php echo abs( $stats['days_until_due'] ); ?> days overdue</span>
						<?php elseif ( $stats['days_until_due'] == 0 ) : ?>
							<span class="text-warning">Today</span>
						<?php else : ?>
							<?php echo esc_html( $stats['days_until_due'] ); ?> days
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( $stats['schedule']['priority'] !== 'normal' ) : ?>
					<div class="kc-sidebar-row">
						<strong>Priority:</strong>
						<?php echo esc_html( ucfirst( $stats['schedule']['priority'] ) ); ?>
					</div>
				<?php endif; ?>
			</div>

			<?php $this->render_contact_log( $person->username ); ?>
		</div>
		<?php
	}

	/**
	 * Render recent contact log
	 */
	private function render_contact_log( $username ) {
		$log = $this->storage->get_contact_log( $username, 5 );

		if ( empty( $log ) ) {
			return;
		}

		$type_labels = [
			'general' => 'Contact',
			'email'   => 'Email',
			'call'    => 'Call',
			'meeting' => 'Meeting',
			'message' => 'Message',
		];
		?>
		<div class="kc-recent-contacts">
			<h4>Recent Contacts</h4>
			<ul class="kc-recent-list">
				<?php foreach ( $log as $entry ) : ?>
					<li>
						<span class="kc-recent-date"><?php echo esc_html( date( 'M j', strtotime( $entry['contact_date'] ) ) ); ?></span>
						<span class="kc-recent-type"><?php echo esc_html( $type_labels[ $entry['contact_type'] ] ?? 'Contact' ); ?></span>
						<?php if ( ! empty( $entry['notes'] ) ) : ?>
							<span class="kc-recent-notes">— <?php echo esc_html( wp_trim_words( $entry['notes'], 10 ) ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render quick link to draft message
	 */
	public function render_quick_links( $person, $is_team_member, $group_data ) {
		?>
		<a href="<?php echo esc_url( $this->crm->build_url( 'conversations', array( 'person' => $person->username ) ) ); ?>"
		   class="quick-link"
		   title="Send a message">
			✏️ Send message
		</a>
		<?php
	}

	/**
	 * Render admin form fields for contact schedule
	 */
	public function render_admin_fields( $edit_data, $is_editing ) {
		$username = $edit_data->username ?? '';
		$schedule = null;

		if ( $is_editing && ! empty( $username ) ) {
			$schedule = $this->storage->get_schedule( $username );
		}

		$frequency = $schedule['frequency_days'] ?? '';
		$priority = $schedule['priority'] ?? 'normal';
		$paused = $schedule['paused'] ?? 0;
		$notes = $schedule['notes'] ?? '';

		$frequency_options = [
			''    => '-- No Schedule --',
			'7'   => 'Weekly',
			'14'  => 'Every 2 weeks',
			'30'  => 'Monthly',
			'60'  => 'Every 2 months',
			'90'  => 'Quarterly',
			'180' => 'Every 6 months',
			'365' => 'Yearly',
		];
		?>
		<div class="divider-section">
			<h4 class="section-heading">Keeping Contact</h4>
			<div class="form-group">
				<label for="kc_frequency">Contact Frequency <small class="optional-label">(optional)</small></label>
				<select id="kc_frequency" name="kc_frequency">
					<?php foreach ( $frequency_options as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $frequency, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="form-group" id="kc_extra_fields" style="<?php echo empty( $frequency ) ? 'display:none;' : ''; ?>">
				<label for="kc_priority">Priority</label>
				<select id="kc_priority" name="kc_priority">
					<option value="low" <?php selected( $priority, 'low' ); ?>>Low</option>
					<option value="normal" <?php selected( $priority, 'normal' ); ?>>Normal</option>
					<option value="high" <?php selected( $priority, 'high' ); ?>>High</option>
				</select>

				<div class="form-inline">
					<label>
						<input type="checkbox" name="kc_paused" value="1" <?php checked( $paused, 1 ); ?>>
						Pause contact reminders
					</label>
				</div>

				<div class="form-inline">
					<label for="kc_notes">Schedule Notes <small class="optional-label">(optional)</small></label>
					<textarea id="kc_notes" name="kc_notes" rows="2" placeholder="e.g., Prefers email, best time is mornings"><?php echo esc_textarea( $notes ); ?></textarea>
				</div>
			</div>
		</div>

		<script>
		document.getElementById('kc_frequency').addEventListener('change', function() {
			document.getElementById('kc_extra_fields').style.display = this.value ? '' : 'none';
		});
		</script>
		<?php
	}

	/**
	 * Save contact schedule data
	 */
	public function save_person_data( $username, $post_data, $section_key ) {
		$frequency = intval( $post_data['kc_frequency'] ?? 0 );

		if ( $frequency > 0 ) {
			$this->storage->save_schedule( $username, [
				'frequency_days' => $frequency,
				'priority'       => sanitize_text_field( $post_data['kc_priority'] ?? 'normal' ),
				'paused'         => ! empty( $post_data['kc_paused'] ) ? 1 : 0,
				'notes'          => sanitize_textarea_field( $post_data['kc_notes'] ?? '' ),
			] );
		} else {
			$this->storage->delete_schedule( $username );
		}
	}

	/**
	 * Render dashboard sidebar with overdue contacts
	 */
	public function render_dashboard_sidebar( $group_data, $current_group ) {
		global $wpdb;

		$overdue = $this->storage->get_overdue_contacts();

		$members = [];
		if ( $group_data && method_exists( $group_data, 'get_members' ) ) {
			foreach ( $group_data->get_members() as $member ) {
				$members[ $member->username ] = $member;
			}
		}

		$relevant_overdue = array_filter( $overdue, function( $item ) use ( $members, $current_group ) {
			if ( ! empty( $current_group ) && ! empty( $members ) ) {
				return isset( $members[ $item['username'] ] );
			}
			return true;
		} );

		// Get people mapped to Beeper but without a schedule
		$beeper_without_schedule = [];
		$all_schedules = $this->storage->get_all_schedules();
		$beeper_mappings = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'kc_beeper_chats_%'",
			ARRAY_A
		);
		foreach ( $beeper_mappings as $mapping ) {
			$username = str_replace( 'kc_beeper_chats_', '', $mapping['option_name'] );
			$chat_ids = maybe_unserialize( $mapping['option_value'] );
			if ( ! empty( $chat_ids ) && ! isset( $all_schedules[ $username ] ) ) {
				if ( empty( $current_group ) || empty( $members ) || isset( $members[ $username ] ) ) {
					$beeper_without_schedule[] = $username;
				}
			}
		}

		if ( empty( $relevant_overdue ) && empty( $beeper_without_schedule ) ) {
			return;
		}

		$relevant_overdue = array_slice( $relevant_overdue, 0, 10 );
		?>
		<?php if ( ! empty( $relevant_overdue ) ) : ?>
		<div class="sidebar-section">
			<h3>
				<a href="<?php echo esc_url( $this->crm->build_url( 'outreach' ) ); ?>">
					Overdue Contacts
				</a>
			</h3>
			<ul class="sidebar-list">
				<?php foreach ( $relevant_overdue as $item ) :
					$person = $this->crm->storage->get_person( $item['username'] );
					if ( ! $person ) continue;

					$days_text = $item['last_contact_date']
						? $item['days_since_contact'] . 'd ago'
						: 'Never';
				?>
					<li>
						<a href="<?php echo esc_url( $person->get_profile_url() ); ?>">
							<?php echo esc_html( $person->get_display_name_with_nickname() ); ?>
						</a>
						<span class="sidebar-meta overdue">
							<?php echo esc_html( $days_text ); ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $beeper_without_schedule ) ) : ?>
		<div class="sidebar-section">
			<h3>
				<a href="<?php echo esc_url( $this->crm->build_url( 'outreach' ) ); ?>">
					Needs Schedule
				</a>
			</h3>
			<ul class="sidebar-list">
				<?php foreach ( array_slice( $beeper_without_schedule, 0, 5 ) as $username ) :
					$person = $this->crm->storage->get_person( $username );
					if ( ! $person ) continue;
				?>
					<li>
						<a href="<?php echo esc_url( $this->crm->build_url( 'outreach', [ 'person' => $username ] ) ); ?>">
							<?php echo esc_html( $person->get_display_name_with_nickname() ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Add Outreach menu item to masterbar
	 */
	public function add_masterbar_menu( $wp_admin_bar ) {
		$wp_admin_bar->add_node( [
			'id'     => 'keeping-contact-outreach',
			'parent' => 'wp-app-crm',
			'title'  => 'Outreach',
			'href'   => $this->crm->build_url( 'outreach' ),
		] );
	}

	/**
	 * Render Group Analysis link in group page footer
	 */
	public function render_group_analysis_link( $group_data, $current_group ) {
		if ( ! $this->beeper->is_configured() || empty( $current_group ) ) {
			return;
		}

		$url = $this->crm->build_url( 'analysis-group', [ 'group' => $current_group ] );
		?>
		<a href="<?php echo esc_url( $url ); ?>" class="footer-link">📊 Group Analysis</a>
		<?php
	}

	/**
	 * Build clean URLs for outreach and draft routes
	 */
	public function build_clean_urls( $url_data, $base_url ) {
		$url = $url_data['url'];
		$params = $url_data['params'];

		if ( str_contains( $base_url, 'outreach' ) && isset( $params['person'] ) ) {
			$url = home_url( '/crm/outreach/' . $params['person'] );
			unset( $params['person'] );
		}

		if ( str_contains( $base_url, 'conversations' ) && isset( $params['person'] ) ) {
			$url = home_url( '/crm/conversations/' . $params['person'] );
			unset( $params['person'] );
		}

		if ( str_contains( $base_url, 'analysis-group' ) && isset( $params['group'] ) ) {
			$url = home_url( '/crm/analysis-group/' . $params['group'] );
			unset( $params['group'] );
		} elseif ( str_contains( $base_url, 'analysis' ) && isset( $params['person'] ) ) {
			$url = home_url( '/crm/analysis/' . $params['person'] );
			unset( $params['person'] );
		}

		return [ 'url' => $url, 'params' => $params ];
	}

	/**
	 * Format frequency days into readable label
	 */
	private function format_frequency( $days ) {
		$labels = [
			7   => 'Weekly',
			14  => 'Every 2 weeks',
			30  => 'Monthly',
			60  => 'Every 2 months',
			90  => 'Quarterly',
			180 => 'Every 6 months',
			365 => 'Yearly',
		];

		return $labels[ $days ] ?? "Every {$days} days";
	}

	/**
	 * Render Beeper section in person sidebar
	 */
	public function render_beeper_sidebar( $person, $is_team_member ) {
		if ( ! $this->beeper->is_configured() ) {
			return;
		}

		// Get chat IDs (handle migration from old single-value option)
		$chat_ids = get_option( 'kc_beeper_chats_' . $person->username, [] );
		if ( ! is_array( $chat_ids ) ) {
			$chat_ids = [];
		}
		// Migrate from old single-value option
		$old_chat_id = get_option( 'kc_beeper_chat_' . $person->username, '' );
		if ( ! empty( $old_chat_id ) && ! in_array( $old_chat_id, $chat_ids ) ) {
			$chat_ids[] = $old_chat_id;
			update_option( 'kc_beeper_chats_' . $person->username, $chat_ids );
			delete_option( 'kc_beeper_chat_' . $person->username );
		}

		// Only show if there's a schedule or already connected
		$schedule = $this->storage->get_schedule( $person->username );
		if ( empty( $schedule ) && empty( $chat_ids ) ) {
			return;
		}
		?>
		<div style="margin-top: 30px">
			<h3 class="sidebar-section-heading">💬 Beeper</h3>

			<?php foreach ( $chat_ids as $chat_id ) :
				$chat = $this->beeper->get_chat( $chat_id );
				$network = is_wp_error( $chat ) ? 'Unknown' : ( $chat['network'] ?? 'Connected' );
				$title = $chat_id;
				if ( ! is_wp_error( $chat ) ) {
					$participants = $chat['participants']['items'] ?? [];
					foreach ( $participants as $p ) {
						if ( empty( $p['isSelf'] ) && ! empty( $p['fullName'] ) ) {
							$title = $p['fullName'];
							break;
						}
					}
					if ( $title === $chat_id ) {
						$title = $chat['title'] ?? $chat['name'] ?? $network;
					}
				}
			?>
				<div class="kc-beeper-chat">
					<span class="kc-beeper-chat-info">
						<?php echo esc_html( $title ); ?>
						<span class="kc-beeper-chat-network">(<?php echo esc_html( $network ); ?>)</span>
					</span>
					<span class="kc-beeper-actions">
						<button type="button" class="btn-sm btn-sync" onclick="kcBeeperSync('<?php echo esc_js( $person->username ); ?>', '<?php echo esc_js( $chat_id ); ?>')">
							Sync
						</button>
						<button type="button" class="btn-sm btn-danger" onclick="kcBeeperUnlink('<?php echo esc_js( $person->username ); ?>', '<?php echo esc_js( $chat_id ); ?>')">
							×
						</button>
					</span>
				</div>
			<?php endforeach; ?>

			<?php if ( empty( $chat_ids ) ) : ?>
			<button type="button" class="kc-beeper-add" onclick="kcBeeperSearch('<?php echo esc_js( $person->username ); ?>', '<?php echo esc_js( $person->name ); ?>')">
				+ Connect Beeper
			</button>
		<?php else : ?>
			<button type="button" class="kc-beeper-add" onclick="kcBeeperSearch('<?php echo esc_js( $person->username ); ?>', '<?php echo esc_js( $person->name ); ?>')">
				+ Add Chat
			</button>
		<?php endif; ?>
		</div>
		<?php
		$this->render_beeper_modal();
	}

	/**
	 * Render Beeper search modal (once per page)
	 */
	private function render_beeper_modal() {
		static $rendered = false;
		if ( $rendered ) return;
		$rendered = true;
		?>
		<div id="kcBeeperModal" class="kc-modal">
			<div class="kc-modal-content">
				<h3>Find in Beeper</h3>
				<input type="hidden" id="kcBeeperUsername">
				<div id="kcBeeperSearching" class="kc-searching">
					Searching...
				</div>
				<div id="kcBeeperResults"></div>
				<div class="kc-modal-footer">
					<button type="button" class="btn btn-secondary" onclick="kcBeeperModalClose()">
						Cancel
					</button>
				</div>
			</div>
		</div>
		<script>
		window.kcBeeperConfig = {
			ajaxUrl: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
			nonce: '<?php echo wp_create_nonce( 'kc_beeper' ); ?>'
		};
		</script>
		<?php
		if ( function_exists( 'wp_app_enqueue_script' ) ) {
			wp_app_enqueue_script( 'kc-beeper', plugin_dir_url( __DIR__ ) . 'assets/beeper.js', [], '1.0', true );
		} else {
			echo '<script src="' . esc_url( plugin_dir_url( __DIR__ ) . 'assets/beeper.js' ) . '"></script>';
		}
		?>
		<?php
	}

	/**
	 * Render Beeper fields in admin form
	 */
	public function render_beeper_admin_fields( $edit_data, $is_editing ) {
		if ( ! $is_editing ) return;

		$username = $edit_data->username ?? '';
		$chat_ids = get_option( 'kc_beeper_chats_' . $username, [] );
		if ( ! is_array( $chat_ids ) ) {
			$chat_ids = [];
		}
		?>
		<div class="divider-section">
			<h4 class="section-heading">Beeper Integration</h4>
			<?php if ( ! $this->beeper->is_configured() ) : ?>
				<p class="context-notes">
					Beeper not configured. <a href="<?php echo esc_url( $this->crm->build_url( 'outreach-settings' ) ); ?>">Add API token</a>
				</p>
			<?php else : ?>
				<div class="form-group">
					<label>Linked Beeper Chats <small class="optional-label">(optional)</small></label>
					<?php if ( ! empty( $chat_ids ) ) : ?>
						<ul class="kc-recent-list">
							<?php foreach ( $chat_ids as $chat_id ) : ?>
								<li><?php echo esc_html( $chat_id ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<p class="context-notes">
						Use "Add Chat" on person page to link Beeper conversations.
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save Beeper chat link (no longer used - managed via AJAX)
	 */
	public function save_beeper_data( $username, $post_data, $section_key ) {
		// Chat links are now managed via AJAX on the person page
	}
}
