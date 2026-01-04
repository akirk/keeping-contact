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
		add_filter( 'personal_crm_person_quick_links', [ $this, 'render_quick_links' ], 10, 4 );
		add_action( 'personal_crm_admin_person_form_fields', [ $this, 'render_admin_fields' ], 10, 2 );
		add_action( 'personal_crm_admin_person_form_fields', [ $this, 'render_beeper_admin_fields' ], 15, 2 );
		add_action( 'personal_crm_admin_person_save', [ $this, 'save_person_data' ], 10, 3 );
		add_action( 'personal_crm_admin_person_save', [ $this, 'save_beeper_data' ], 10, 3 );
		add_action( 'personal_crm_dashboard_sidebar', [ $this, 'render_dashboard_sidebar' ], 20, 2 );
		add_filter( 'personal_crm_build_url', [ $this, 'build_clean_urls' ], 10, 2 );
		add_action( 'wp_app_admin_bar_menu', [ $this, 'add_masterbar_menu' ] );
		add_action( 'personal_crm_footer_links', [ $this, 'render_group_analysis_link' ], 10, 2 );
		add_filter( 'personal_crm_welcome_sections', [ $this, 'register_welcome_section' ], 10, 2 );
	}

	/**
	 * Enqueue keeping-contact styles
	 */
	private function enqueue_styles() {
		if ( function_exists( 'wp_app_enqueue_style' ) ) {
			wp_app_enqueue_style( 'keeping-contact', plugin_dir_url( __DIR__ ) . 'assets/style.css' );
		}
	}

	/**
	 * Register welcome page section for Beeper import
	 */
	public function register_welcome_section( $sections, $crm ) {
		if ( ! $this->beeper->is_configured() ) {
			return $sections;
		}

		$sections[] = array(
			'id'          => 'beeper-import',
			'title'       => 'Import from Beeper',
			'description' => 'Import your messaging conversations as contacts.',
			'callback'    => [ $this, 'render_welcome_beeper_section' ],
			'priority'    => 15,
			'icon'        => '💬',
		);

		return $sections;
	}

	/**
	 * Render Beeper import section on welcome page
	 */
	public function render_welcome_beeper_section( $crm ) {
		$import_url = $crm->build_url( 'import-beeper' );
		?>
		<div class="welcome-section-content">
			<p>Connect your Beeper conversations to automatically track contact history with your network.</p>
			<a href="<?php echo esc_url( $import_url ); ?>" class="btn btn-secondary">
				Browse Beeper Chats
			</a>
		</div>
		<?php
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
		add_action( 'wp_ajax_kc_render_sidebar', [ __CLASS__, 'static_ajax_render_sidebar' ] );
		add_action( 'wp_ajax_kc_set_schedule', [ __CLASS__, 'static_ajax_set_schedule' ] );
		add_action( 'wp_ajax_kc_beeper_import', [ __CLASS__, 'static_ajax_beeper_import' ] );
		add_action( 'wp_ajax_kc_beeper_load_chats', [ __CLASS__, 'static_ajax_beeper_load_chats' ] );
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

		$storage = self::get_storage();
		$storage->link_beeper_chat( $username, $chat_id );

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

		$storage = self::get_storage();
		$storage->unlink_beeper_chat( $username, $chat_id );

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

	public static function static_ajax_render_sidebar() {
		check_ajax_referer( 'kc_beeper', '_wpnonce' );

		$username = sanitize_text_field( $_POST['username'] ?? '' );

		if ( empty( $username ) ) {
			wp_send_json_error( 'Username required' );
		}

		$instance = self::get_instance();
		if ( ! $instance ) {
			wp_send_json_error( 'Plugin not initialized' );
		}

		$person = $instance->crm->storage->get_person( $username );
		if ( ! $person ) {
			wp_send_json_error( 'Person not found' );
		}

		ob_start();
		$instance->render_person_sidebar( $person, false );
		$html = ob_get_clean();

		wp_send_json_success( [ 'html' => $html ] );
	}

	public static function static_ajax_set_schedule() {
		check_ajax_referer( 'kc_beeper', '_wpnonce' );

		$username = sanitize_text_field( $_POST['username'] ?? '' );
		$frequency = intval( $_POST['frequency'] ?? 0 );

		if ( empty( $username ) || $frequency <= 0 ) {
			wp_send_json_error( 'Username and frequency required' );
		}

		$storage = self::get_storage();
		$storage->save_schedule( $username, [
			'frequency_days' => $frequency,
			'priority'       => 'normal',
			'paused'         => 0,
			'notes'          => '',
		] );

		wp_send_json_success( [ 'saved' => true ] );
	}

	public static function static_ajax_beeper_import() {
		check_ajax_referer( 'kc_beeper', '_wpnonce' );

		// Accept either single chat_id or array of chat_ids
		$chat_ids_json = $_POST['chat_ids'] ?? '';
		$chat_ids = [];

		if ( ! empty( $chat_ids_json ) ) {
			$chat_ids = json_decode( stripslashes( $chat_ids_json ), true );
			if ( ! is_array( $chat_ids ) ) {
				$chat_ids = [];
			}
		}

		// Fallback to single chat_id for backwards compatibility
		if ( empty( $chat_ids ) ) {
			$single_id = sanitize_text_field( $_POST['chat_id'] ?? '' );
			if ( ! empty( $single_id ) ) {
				$chat_ids = [ $single_id ];
			}
		}

		if ( empty( $chat_ids ) ) {
			wp_send_json_error( 'Chat ID required' );
		}

		$name_override = sanitize_text_field( $_POST['name'] ?? '' );
		$beeper = self::get_beeper();

		// Get info from first chat
		$chat = $beeper->get_chat( $chat_ids[0] );

		if ( is_wp_error( $chat ) ) {
			wp_send_json_error( 'Failed to get chat info: ' . $chat->get_error_message() );
		}

		$name = $name_override;
		$phone = '';

		if ( ! empty( $chat['participants']['items'] ) ) {
			foreach ( $chat['participants']['items'] as $participant ) {
				if ( empty( $participant['isSelf'] ) ) {
					if ( empty( $name ) && ! empty( $participant['fullName'] ) ) {
						$name = $participant['fullName'];
					}
					if ( ! empty( $participant['identifier'] ) ) {
						$phone = $participant['identifier'];
					} elseif ( ! empty( $participant['phoneNumber'] ) ) {
						$phone = $participant['phoneNumber'];
					}
					break;
				}
			}
		}

		if ( empty( $name ) ) {
			$name = $chat['title'] ?? 'Unknown Contact';
		}

		$base_username = sanitize_title( $name );
		if ( empty( $base_username ) ) {
			$base_username = 'contact';
		}

		$crm = \PersonalCRM\PersonalCrm::get_instance();
		$username = $base_username;
		$counter = 1;
		while ( $crm->storage->get_person( $username ) ) {
			$counter++;
			$username = $base_username . '-' . $counter;
		}

		$person_data = [
			'name' => $name,
		];

		$result = $crm->storage->save_person( $username, $person_data, [] );

		if ( ! $result ) {
			wp_send_json_error( 'Failed to create contact' );
		}

		if ( ! empty( $phone ) && class_exists( '\ContactSyncPersonalCRM\CardDAVStorage' ) ) {
			$person = $crm->storage->get_person( $username );
			if ( $person && $person->id ) {
				global $wpdb;
				$carddav_storage = new \ContactSyncPersonalCRM\CardDAVStorage( $wpdb );
				$carddav_storage->save_phone( $person->id, $phone, 'mobile', true );
			}
		}

		$storage = self::get_storage();

		// Link ALL chat IDs to this person
		foreach ( $chat_ids as $chat_id ) {
			$storage->link_beeper_chat( $username, sanitize_text_field( $chat_id ) );
		}

		// Sync from first chat
		self::static_sync_beeper_contact( $username, $chat_ids[0] );

		wp_send_json_success( [
			'username' => $username,
			'name'     => $name,
			'url'      => home_url( '/crm/person/' . $username ),
		] );
	}

	public static function static_ajax_beeper_load_chats() {
		check_ajax_referer( 'kc_beeper', '_wpnonce' );

		$limit = intval( $_POST['limit'] ?? 100 );
		$limit = max( 50, min( 1000, $limit ) );

		$beeper = self::get_beeper();
		$storage = self::get_storage();

		$all_chats_result = $beeper->get_all_chats( $limit );
		$all_chats = [];
		$has_more = false;

		if ( ! is_wp_error( $all_chats_result ) ) {
			$all_chats = $all_chats_result['items'] ?? [];
			$has_more = count( $all_chats ) >= $limit;
		}

		$chat_to_username = $storage->get_all_beeper_chat_mappings();

		$chats_for_js = [];
		foreach ( $all_chats as $chat ) {
			$name = $chat['title'] ?? 'Unknown';
			if ( ! empty( $chat['participants']['items'] ) ) {
				foreach ( $chat['participants']['items'] as $p ) {
					if ( empty( $p['isSelf'] ) && ! empty( $p['fullName'] ) ) {
						$name = $p['fullName'];
						break;
					}
				}
			}

			$linked_username = $chat_to_username[ $chat['id'] ] ?? null;

			$chats_for_js[] = [
				'id'             => $chat['id'],
				'name'           => $name,
				'network'        => $chat['network'] ?? '',
				'lastActivity'   => $chat['lastActivity'] ?? '',
				'isLinked'       => $linked_username !== null,
				'linkedUsername' => $linked_username,
			];
		}

		wp_send_json_success( [
			'chats'   => $chats_for_js,
			'hasMore' => $has_more,
		] );
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
		$app->route( 'import-beeper', KEEPING_CONTACT_PATH . 'import-beeper.php' );
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

		$status_labels = [
			'overdue'         => 'Overdue',
			'due_soon'        => 'Due Soon',
			'on_track'        => 'On Track',
			'never_contacted' => 'Never Contacted',
			'paused'          => 'Paused',
			'no_schedule'     => 'No Schedule',
		];

		// Get Beeper chat IDs
		$chat_ids = [];
		if ( $this->beeper->is_configured() ) {
			$chat_ids = $this->storage->get_beeper_chats( $person->username );
		}

		$status_class = str_replace( '_', '-', $stats['status'] );
		?>
		<div class="kc-sidebar-section">
			<div class="kc-sidebar-header">
				<h3><a href="<?php echo esc_url( $this->crm->build_url( 'outreach' ) ); ?>">Keeping Contact</a></h3>
				<span class="kc-sidebar-badge status-badge <?php echo esc_attr( $status_class ); ?>">
					<?php echo esc_html( $status_labels[ $stats['status'] ] ); ?>
				</span>
			</div>

				<div class="kc-sidebar-content">
				<?php if ( $stats['status'] !== 'no_schedule' ) : ?>
				<div class="kc-sidebar-row"><strong>Frequency:</strong> <?php echo esc_html( $this->format_frequency( $stats['schedule']['frequency_days'] ) ); ?></div>
				<?php endif; ?>

				<?php if ( $stats['last_contact'] ) :
					$days_ago = floor( ( time() - strtotime( $stats['last_contact']['contact_date'] ) ) / 86400 );
				?>
					<div class="kc-sidebar-row">
						<strong>Last contact:</strong>
						<?php echo esc_html( date( 'M j, Y', strtotime( $stats['last_contact']['contact_date'] ) ) ); ?>
						<span class="context-muted">(<?php echo esc_html( $days_ago ); ?> days ago)</span>
					</div>
				<?php else : ?>
					<div class="kc-sidebar-row"><strong>Last contact:</strong> <em>Never</em></div>
				<?php endif; ?>

				<?php if ( $stats['status'] !== 'no_schedule' && $stats['status'] !== 'paused' && $stats['days_until_due'] !== null ) : ?>
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

				<?php if ( $stats['status'] !== 'no_schedule' && $stats['schedule']['priority'] !== 'normal' ) : ?>
					<div class="kc-sidebar-row">
						<strong>Priority:</strong>
						<?php echo esc_html( ucfirst( $stats['schedule']['priority'] ) ); ?>
					</div>
				<?php endif; ?>
			</div>

			<?php $this->render_contact_log( $person->username ); ?>

			<?php if ( $this->beeper->is_configured() ) : ?>
			<div class="kc-beeper-subsection">
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
						<a href="<?php echo esc_url( $this->crm->build_url( 'conversations', [ 'person' => $person->username ] ) ); ?>" class="kc-beeper-chat-info">
							💬 <?php echo esc_html( $title ); ?>
							<span class="kc-beeper-chat-network">(<?php echo esc_html( $network ); ?>)</span>
						</a>
						<button type="button" class="btn-sm btn-danger" onclick="kcBeeperUnlink('<?php echo esc_js( $person->username ); ?>', '<?php echo esc_js( $chat_id ); ?>')" title="Disconnect chat">×</button>
					</div>
				<?php endforeach; ?>

				<button type="button" class="kc-beeper-add" onclick="kcBeeperSearch('<?php echo esc_js( $person->username ); ?>', '<?php echo esc_js( $person->name ); ?>')">
					<?php echo empty( $chat_ids ) ? '+ Connect Beeper chat' : '+ Add another chat'; ?>
				</button>

				<?php if ( ! empty( $chat_ids ) ) : ?>
				<a href="<?php echo esc_url( $this->crm->build_url( 'analysis', [ 'person' => $person->username ] ) ); ?>" class="kc-beeper-analysis-link">
					📊 Relationship Analysis
				</a>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
		$this->render_beeper_modal();
		$this->render_sidebar_scripts( $person->username, $chat_ids );
	}

	/**
	 * Render sidebar scripts for auto-sync
	 */
	private function render_sidebar_scripts( $username, $chat_ids ) {
		if ( empty( $chat_ids ) ) {
			return;
		}
		?>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			if (typeof kcBeeperAutoSync === 'function') {
				kcBeeperAutoSync('<?php echo esc_js( $username ); ?>', <?php echo wp_json_encode( $chat_ids ); ?>);
			}
		});
		</script>
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
	 * Add quick link to send message
	 */
	public function render_quick_links( $quick_links, $person, $is_team_member, $group_data ) {
		$quick_links['send-message'] = array(
			'url'   => $this->crm->build_url( 'conversations', array( 'person' => $person->username ) ),
			'label' => 'Send message',
			'icon'  => '✏️',
			'title' => 'Send a message',
		);
		return $quick_links;
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
		$chat_mappings = $this->storage->get_all_beeper_chat_mappings();
		$usernames_with_chats = array_unique( array_values( $chat_mappings ) );
		foreach ( $usernames_with_chats as $username ) {
			if ( ! isset( $all_schedules[ $username ] ) ) {
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
		<div class="sidebar-section" id="kc-needs-schedule">
			<h3>
				<a href="<?php echo esc_url( $this->crm->build_url( 'outreach' ) ); ?>">
					Needs Schedule
				</a>
			</h3>
			<ul class="sidebar-list kc-schedule-list">
				<?php foreach ( array_slice( $beeper_without_schedule, 0, 5 ) as $username ) :
					$person = $this->crm->storage->get_person( $username );
					if ( ! $person ) continue;
				?>
					<li data-username="<?php echo esc_attr( $username ); ?>">
						<a href="<?php echo esc_url( $person->get_profile_url() ); ?>">
							<?php echo esc_html( $person->get_display_name_with_nickname() ); ?>
						</a>
						<span class="kc-quick-schedule">
							<button type="button" data-freq="7" title="Weekly">1w</button>
							<button type="button" data-freq="14" title="Every 2 weeks">2w</button>
							<button type="button" data-freq="30" title="Monthly">1m</button>
							<button type="button" data-freq="90" title="Quarterly">3m</button>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<script>
		(function() {
			var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
			var nonce = '<?php echo wp_create_nonce( 'kc_beeper' ); ?>';

			document.getElementById('kc-needs-schedule').addEventListener('click', function(e) {
				var btn = e.target.closest('button[data-freq]');
				if (!btn) return;

				var li = btn.closest('li');
				var username = li.dataset.username;
				var freq = btn.dataset.freq;

				btn.disabled = true;
				btn.textContent = '...';

				fetch(ajaxUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: 'action=kc_set_schedule&username=' + encodeURIComponent(username) + '&frequency=' + freq + '&_wpnonce=' + nonce
				})
				.then(function(r) { return r.json(); })
				.then(function(data) {
					if (data.success) {
						li.remove();
						var list = document.querySelector('#kc-needs-schedule .sidebar-list');
						if (!list || !list.children.length) {
							document.getElementById('kc-needs-schedule').remove();
						}
					} else {
						btn.disabled = false;
						btn.textContent = btn.dataset.freq === '7' ? '1w' : btn.dataset.freq === '14' ? '2w' : btn.dataset.freq === '30' ? '1m' : '3m';
					}
				});
			});
		})();
		</script>
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
		<a href="<?php echo esc_url( $url ); ?>" class="footer-link">📊 Relationship Analysis</a>
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
	}

	/**
	 * Render Beeper fields in admin form
	 */
	public function render_beeper_admin_fields( $edit_data, $is_editing ) {
		if ( ! $is_editing ) return;

		$username = $edit_data->username ?? '';
		$chat_ids = $this->storage->get_beeper_chats( $username );
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
