<?php
/**
 * Draft Message Page
 *
 * Helps draft a message to a person with conversation context.
 */

namespace KeepingContact;

require_once __DIR__ . '/../personal-crm/personal-crm.php';
require_once __DIR__ . '/../personal-crm/includes/local-llm.php';
require_once __DIR__ . '/keeping-contact.php';

use PersonalCRM\PersonalCrm;
use PersonalCRM\LocalLLM;

$crm = PersonalCrm::get_instance();
extract( PersonalCrm::get_globals() );

KeepingContact::init( $crm );
$kc = KeepingContact::get_instance();
$kc_storage = $kc->storage;
$beeper = $kc->beeper;

$username = $_GET['person'] ?? '';
if ( empty( $username ) && function_exists( 'get_query_var' ) ) {
	$username = get_query_var( 'person' );
}
if ( empty( $username ) ) {
	header( 'Location: ' . $crm->build_url( 'index.php' ) );
	exit;
}

$person = $crm->storage->get_person( $username );
if ( ! $person ) {
	header( 'Location: ' . $crm->build_url( 'index.php' ) );
	exit;
}

$schedule = $kc_storage->get_schedule( $username );
$stats = $kc_storage->get_contact_stats( $username );

// Get linked Beeper chats
$chat_ids = $kc_storage->get_beeper_chats( $username );

// Get linked Beeper chat for JS to load messages
$active_chat = null;
$beeper_error = null;

if ( ! $beeper->is_configured() ) {
	$beeper_error = 'Beeper API token not configured. Add it in Settings.';
} elseif ( empty( $chat_ids ) ) {
	$beeper_error = 'No Beeper chats linked to this person.';
} else {
	$active_chat = $chat_ids[0];
}

// Days since last contact from schedule stats
$days_since_last_message = $stats['days_since_contact'] ?? null;

// Get upcoming events for this person (birthdays, anniversaries, etc.)
$upcoming_events = $person->get_upcoming_events();
$events_context = [];
foreach ( $upcoming_events as $event ) {
	$days_until = ( new \DateTime() )->diff( $event->date )->days;
	$events_context[] = [
		'type'        => $event->type,
		'description' => $event->get_title_without_person_name(),
		'date'        => $event->date->format( 'F j, Y' ),
		'days_until'  => $days_until,
	];
}

// Get person's email and phone from links
$email = $person->email ?? '';
$phone_numbers = apply_filters( 'personal_crm_person_phone_numbers', [], $username );

$local_model = LocalLLM::get_model();
$local_provider = LocalLLM::get_provider_key();
$local_base_url = LocalLLM::get_base_url();

?>
<!DOCTYPE html>
<html <?php echo function_exists( 'wp_app_language_attributes' ) ? wp_app_language_attributes() : 'lang="en"'; ?>>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="color-scheme" content="light dark">
	<title><?php echo function_exists( 'wp_app_title' ) ? wp_app_title( 'Conversation with ' . $person->get_display_name_with_nickname() ) : 'Conversation with ' . $person->get_display_name_with_nickname(); ?></title>
	<?php
	if ( function_exists( 'wp_app_enqueue_style' ) ) {
		wp_app_enqueue_style( 'personal-crm-style', plugin_dir_url( __DIR__ . '/../personal-crm/personal-crm.php' ) . 'assets/style.css' );
		wp_app_enqueue_style( 'personal-crm-cmd-k', plugin_dir_url( __DIR__ . '/../personal-crm/personal-crm.php' ) . 'assets/cmd-k.css' );
		wp_app_enqueue_style( 'keeping-contact', plugin_dir_url( __FILE__ ) . 'assets/style.css' );
	}
	?>
	<?php if ( function_exists( 'wp_app_head' ) ) wp_app_head(); ?>
</head>
<body class="wp-app-body">
	<?php if ( function_exists( 'wp_app_body_open' ) ) wp_app_body_open(); ?>
	<?php $crm->render_cmd_k_panel(); ?>

	<div class="container">
		<div class="header">
			<div class="header-content">
				<h1>Conversation with <?php echo esc_html( $person->get_display_name_with_nickname() ); ?></h1>
				<div class="back-nav">
					<a href="<?php echo esc_url( $person->get_profile_url() ); ?>">← Back to <?php echo esc_html( $person->get_display_name_with_nickname() ); ?></a>
				</div>
			</div>
			<?php if ( $person->email ) : ?>
				<a href="<?php echo esc_url( $person->get_profile_url() ); ?>">
					<img src="<?php echo esc_url( $person->get_gravatar_url( 60 ) ); ?>" alt="" class="header-avatar">
				</a>
			<?php endif; ?>
		</div>

		<div class="draft-layout">
			<div class="draft-sidebar">
				<?php if ( $schedule && ! empty( $schedule['notes'] ) ) : ?>
					<div class="context-section">
						<h4>Notes</h4>
						<p><?php echo esc_html( $schedule['notes'] ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( $stats['last_contact'] ) : ?>
					<div class="context-section">
						<h4>Last Contact</h4>
						<p>
							<?php echo esc_html( date( 'M j, Y', strtotime( $stats['last_contact']['contact_date'] ) ) ); ?>
							<span class="context-muted">(<?php echo esc_html( $stats['days_since_contact'] ); ?> days ago)</span>
						</p>
						<?php if ( ! empty( $stats['last_contact']['notes'] ) ) : ?>
							<p class="context-notes"><?php echo esc_html( $stats['last_contact']['notes'] ); ?></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<div class="context-section">
					<h4>Send Via</h4>
					<div class="send-options">
						<?php if ( ! empty( $email ) ) : ?>
							<a href="mailto:<?php echo esc_attr( $email ); ?>" class="send-option">
								<span class="send-icon">📧</span>
								<span>Email</span>
							</a>
						<?php endif; ?>
						<?php if ( ! empty( $phone_numbers ) ) : ?>
							<a href="sms:<?php echo esc_attr( $phone_numbers[0] ); ?>" class="send-option">
								<span class="send-icon">💬</span>
								<span>SMS</span>
							</a>
						<?php endif; ?>
						<?php if ( $active_chat && $beeper->is_configured() ) : ?>
							<button type="button" class="send-option" onclick="sendViaBeeper()">
								<span class="send-icon">🐝</span>
								<span>Beeper</span>
							</button>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( $active_chat && $beeper->is_configured() ) : ?>
				<div class="context-section">
					<a href="<?php echo esc_url( $crm->build_url( 'analysis', array( 'person' => $username ) ) ); ?>" class="sidebar-link">
						Relationship Analysis
					</a>
				</div>
				<?php endif; ?>
			</div>

			<div class="draft-main">
				<?php if ( $beeper_error ) : ?>
					<div class="beeper-error-notice" id="beeperError">
						<strong>Beeper:</strong> <?php echo esc_html( $beeper_error ); ?>
					</div>
				<?php endif; ?>

				<?php if ( $active_chat ) : ?>
					<div class="style-examples-section ai-assistant-section" id="styleExamplesSection" style="display: none;">
						<div class="style-examples-header">
							<h4>My Writing Style</h4>
							<div class="context-controls">
								<span class="context-count" id="styleCount"></span>
								<button type="button" class="btn-context-toggle" onclick="toggleAllStyleExamples(true)">All</button>
								<button type="button" class="btn-context-toggle" onclick="toggleAllStyleExamples(false)">None</button>
							</div>
						</div>
						<p class="context-hint">Select messages that represent your typical writing style</p>
						<div class="style-examples-list" id="styleExamplesList"></div>
					</div>

					<div class="conversation-context" id="conversationContextSection" style="display: none;">
						<div class="conversation-context-header ai-assistant-section">
							<div>
								<h4>Conversation Context</h4>
								<p class="context-hint">Click to include/exclude what to talk about</p>
							</div>
							<div class="context-controls">
								<span class="context-count" id="conversationCount"></span>
								<button type="button" class="btn-context-toggle" onclick="toggleAllContext(true)">All</button>
								<button type="button" class="btn-context-toggle" onclick="toggleAllContext(false)">None</button>
							</div>
						</div>
						<div class="conversation-groups-scroll">
							<div class="load-more-container" id="loadMoreContainer" style="display: none;">
								<button type="button" class="btn-load-more" id="loadMoreBtn" data-chat="<?php echo esc_attr( $active_chat ); ?>">Load older messages</button>
							</div>
							<div class="conversation-groups" id="conversationGroups"></div>
						</div>
					</div>

					<div class="loading-messages" id="loadingMessages">Loading messages...</div>
				<?php endif; ?>

				<div class="ai-draft-section ai-assistant-section">
					<div class="ai-draft-header">
						<h4>AI Draft Assistant</h4>
						<div class="ai-model-selector">
							<select id="modelSelect">
								<option value="<?php echo esc_attr( $local_model ); ?>"><?php echo esc_html( $local_model ); ?></option>
							</select>
							<button type="button" class="btn-refresh-models" onclick="loadLocalModels()" title="Refresh models">↻</button>
						</div>
					</div>
					<div class="ai-draft-prompt">
						<input type="text" id="aiPrompt" placeholder="What would you like to say? (e.g., 'check in and ask about their project')" class="ai-prompt-input">
						<button type="button" class="btn btn-primary" onclick="generateDraft()" id="generateBtn">Generate</button>
					</div>
					<div class="ai-event-suggestions">
						<?php
						$event_suggestions = [];
						foreach ( $events_context as $event ) :
							if ( count( $event_suggestions ) >= 2 ) {
								break;
							}
							$suggestion_text = '';
							if ( $event['type'] === 'birthday' ) {
								if ( $event['days_until'] <= 7 ) {
									$suggestion_text = 'Wish happy birthday';
								}
							} elseif ( $event['type'] === 'anniversary' ) {
								if ( $event['days_until'] <= 7 ) {
									$suggestion_text = 'Congratulate on anniversary';
								}
							}
							if ( $suggestion_text ) {
								$event_suggestions[] = $suggestion_text;
							}
						endforeach;

						$default_suggestions = [
							'Say hi and see how things are going',
							'Check in after a while',
							'Follow up on our last conversation',
						];

						$all_suggestions = array_merge( $event_suggestions, $default_suggestions );
						$all_suggestions = array_slice( $all_suggestions, 0, 4 );

						foreach ( $all_suggestions as $suggestion_text ) :
						?>
							<button type="button" class="ai-suggestion-chip" onclick="document.getElementById('aiPrompt').value = '<?php echo esc_js( $suggestion_text ); ?>'; generateDraft();">
								<?php echo esc_html( $suggestion_text ); ?>
							</button>
						<?php endforeach; ?>
					</div>
					<div id="aiDrafts" class="ai-drafts"></div>
				</div>

				<div class="draft-form">
					<h4>Your Message</h4>
					<textarea id="draftMessage" rows="6" placeholder="Write your message here..."></textarea>
					<div class="draft-actions">
						<button type="button" class="btn-ai-toggle" onclick="toggleAiAssistant()">AI Assistant</button>
						<div class="draft-actions-right">
							<button type="button" class="btn btn-secondary" onclick="copyDraft()">Copy to Clipboard</button>
							<?php if ( $active_chat && $beeper->is_configured() ) : ?>
								<button type="button" class="btn btn-primary" onclick="sendViaBeeper()">Send via Beeper</button>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<script>
	window.kcDraftConfig = {
		localModel: <?php echo json_encode( $local_model ); ?>,
		localProvider: <?php echo json_encode( $local_provider ); ?>,
		localBaseUrl: <?php echo json_encode( $local_base_url ); ?>,
		personName: <?php echo json_encode( $person->name ); ?>,
		personFirstName: <?php echo json_encode( explode( ' ', $person->name )[0] ); ?>,
		personUsername: <?php echo json_encode( $username ); ?>,
		conversationContext: [],
		userMessageSamples: [],
		eventsContext: <?php echo json_encode( $events_context ); ?>,
		scheduleNotes: <?php echo json_encode( $schedule['notes'] ?? '' ); ?>,
		daysSinceContact: <?php echo json_encode( $days_since_last_message ); ?>,
		ajaxUrl: <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
		nonce: <?php echo json_encode( wp_create_nonce( 'kc_beeper' ) ); ?>,
		activeChatId: <?php echo json_encode( $active_chat ); ?>,
		beeperToken: <?php echo json_encode( $beeper->get_token() ); ?>,
		beeperApiBase: 'http://localhost:23373/v1'
	};
	</script>
	<?php
	if ( function_exists( 'wp_app_enqueue_script' ) ) {
		wp_app_enqueue_script( 'personal-crm-local-llm', plugin_dir_url( __DIR__ . '/../personal-crm/personal-crm.php' ) . 'assets/local-llm.js', [], '1.0', true );
		wp_app_enqueue_script( 'kc-beeper-client', plugin_dir_url( __FILE__ ) . 'assets/beeper-client.js', [], '1.0', true );
		wp_localize_script( 'kc-beeper-client', 'BeeperClientConfig', KeepingContact::get_beeper_client_config() );
		wp_app_enqueue_script( 'kc-conversation', plugin_dir_url( __FILE__ ) . 'assets/conversation.js', [ 'personal-crm-local-llm', 'kc-beeper-client' ], '1.0', true );
	} else {
		echo '<script>var BeeperClientConfig = ' . wp_json_encode( KeepingContact::get_beeper_client_config() ) . ';</script>';
		echo '<script src="' . esc_url( plugin_dir_url( __DIR__ . '/../personal-crm/personal-crm.php' ) . 'assets/local-llm.js' ) . '"></script>';
		echo '<script src="' . esc_url( plugin_dir_url( __FILE__ ) . 'assets/beeper-client.js' ) . '"></script>';
		echo '<script src="' . esc_url( plugin_dir_url( __FILE__ ) . 'assets/conversation.js' ) . '"></script>';
	}
	?>

	<?php $crm->init_cmd_k_js(); ?>
	<?php if ( function_exists( 'wp_app_body_close' ) ) wp_app_body_close(); ?>
</body>
</html>
