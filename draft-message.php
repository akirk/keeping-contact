<?php
/**
 * Draft Message Page
 *
 * Helps draft a message to a person with conversation context.
 */

namespace KeepingContact;

require_once __DIR__ . '/../personal-crm/personal-crm.php';
require_once __DIR__ . '/keeping-contact.php';

use PersonalCRM\PersonalCrm;

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
$chat_ids = get_option( 'kc_beeper_chats_' . $username, [] );
if ( ! is_array( $chat_ids ) ) {
	$chat_ids = [];
}

// Get recent conversation context from first linked chat
$recent_messages = [];
$active_chat = null;
$user_message_samples = [];
$beeper_error = null;
$has_more_messages = false;
$next_cursor = null;

if ( ! $beeper->is_configured() ) {
	$beeper_error = 'Beeper API token not configured. Add it in Settings.';
} elseif ( empty( $chat_ids ) ) {
	$beeper_error = 'No Beeper chats linked to this person.';
} else {
	$active_chat = $chat_ids[0];
	$cursor = isset( $_GET['cursor'] ) ? sanitize_text_field( $_GET['cursor'] ) : null;

	// Fetch messages with cursor-based pagination
	$context_result = $beeper->get_recent_context( $active_chat, 30, $cursor );
	$recent_messages = $context_result['messages'];
	$has_more_messages = $context_result['has_more'];
	$next_cursor = $context_result['next_cursor'];

	if ( empty( $recent_messages ) && ! $cursor ) {
		$beeper_error = 'Could not load messages. Make sure Beeper is running.';
	} else {
		// Get more messages for style analysis (user's own messages)
		$style_result = $beeper->get_recent_context( $active_chat, 50 );
		foreach ( $style_result['messages'] as $msg ) {
			if ( $msg['is_sender'] && ! empty( $msg['text'] ) && $msg['text'] !== '[attachment]' && $msg['text'] !== '[link]' ) {
				$user_message_samples[] = $msg['text'];
			}
		}
	}
}

// Calculate days since last message
$days_since_last_message = null;
if ( ! empty( $recent_messages ) ) {
	$last_msg_date = end( $recent_messages )['date'];
	if ( $last_msg_date ) {
		$last_date = new \DateTime( $last_msg_date );
		$today = new \DateTime();
		$days_since_last_message = $today->diff( $last_date )->days;
	}
}

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

// Ollama model
$ollama_model = PersonalCrm::get_ollama_model();

?>
<!DOCTYPE html>
<html <?php echo function_exists( 'wp_app_language_attributes' ) ? wp_app_language_attributes() : 'lang="en"'; ?>>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="color-scheme" content="light dark">
	<title><?php echo function_exists( 'wp_app_title' ) ? wp_app_title( 'Draft: ' . $person->get_display_name_with_nickname() ) : 'Draft: ' . $person->get_display_name_with_nickname(); ?></title>
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
				<h1>Draft Message</h1>
				<div class="header-subtitle">
					<a href="<?php echo esc_url( $person->get_profile_url() ); ?>" class="back-link">← Back to <?php echo esc_html( $person->get_display_name_with_nickname() ); ?></a>
				</div>
			</div>
		</div>

		<div class="draft-layout">
			<div class="draft-sidebar">
				<div class="person-link">
					<?php if ( $person->email ) : ?>
						<img src="<?php echo esc_url( $person->get_gravatar_url( 60 ) ); ?>" alt="" class="person-avatar">
					<?php endif; ?>
					<div class="person-info">
						<h2><?php echo esc_html( $person->get_display_name_with_nickname() ); ?></h2>
						<a href="<?php echo esc_url( $person->get_profile_url() ); ?>">View Profile</a>
					</div>
				</div>

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
			</div>

			<div class="draft-main">
				<?php if ( $beeper_error ) : ?>
					<div class="beeper-error-notice">
						<strong>Beeper:</strong> <?php echo esc_html( $beeper_error ); ?>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $user_message_samples ) ) : ?>
					<div class="style-examples-section">
						<div class="style-examples-header">
							<h4>My Writing Style</h4>
							<div class="context-controls">
								<span class="context-count" id="styleCount"></span>
								<button type="button" class="btn-context-toggle" onclick="toggleAllStyleExamples(true)">All</button>
								<button type="button" class="btn-context-toggle" onclick="toggleAllStyleExamples(false)">None</button>
							</div>
						</div>
						<p class="context-hint">Select messages that represent your typical writing style</p>
						<div class="style-examples-list">
							<?php foreach ( $user_message_samples as $idx => $sample ) : ?>
								<div class="style-example" data-idx="<?php echo $idx; ?>" data-selected="true">
									<?php echo esc_html( $sample ); ?>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $recent_messages ) ) :
					// Group messages into conversations based on time gaps (4+ hours = new conversation)
					$conversations = [];
					$current_group = [];
					$last_time = null;
					$gap_threshold = 4 * 60 * 60; // 4 hours in seconds

					foreach ( $recent_messages as $idx => $msg ) {
						$msg_time = strtotime( $msg['date'] );
						if ( $last_time !== null && ( $msg_time - $last_time ) > $gap_threshold ) {
							if ( ! empty( $current_group ) ) {
								$conversations[] = $current_group;
							}
							$current_group = [];
						}
						$msg['_idx'] = $idx;
						$current_group[] = $msg;
						$last_time = $msg_time;
					}
					if ( ! empty( $current_group ) ) {
						$conversations[] = $current_group;
					}
				?>
					<div class="conversation-context">
						<div class="conversation-context-header">
							<h4>Conversation Context</h4>
							<div class="context-controls">
								<span class="context-count" id="conversationCount"></span>
								<button type="button" class="btn-context-toggle" onclick="toggleAllContext(true)">All</button>
								<button type="button" class="btn-context-toggle" onclick="toggleAllContext(false)">None</button>
							</div>
						</div>
						<p class="context-hint">Click to include/exclude what to talk about</p>
						<div class="conversation-groups-scroll">
							<?php if ( $has_more_messages && $next_cursor ) : ?>
								<div class="load-more-container" id="loadMoreContainer">
									<button type="button" class="btn-load-more" id="loadMoreBtn" data-cursor="<?php echo esc_attr( $next_cursor ); ?>" data-chat="<?php echo esc_attr( $active_chat ); ?>">Load older messages</button>
								</div>
							<?php endif; ?>
							<div class="conversation-groups" id="conversationGroups">
								<?php foreach ( $conversations as $group_idx => $group ) :
									$first_date = date( 'M j', strtotime( $group[0]['date'] ) );
									$indices = array_map( function( $m ) { return $m['_idx']; }, $group );
								?>
									<div class="conversation-group" data-indices="<?php echo esc_attr( implode( ',', $indices ) ); ?>" data-selected="true">
										<div class="conversation-group-header">
											<span class="conversation-group-date"><?php echo esc_html( $first_date ); ?></span>
											<span class="conversation-group-count"><?php echo count( $group ); ?> messages</span>
										</div>
										<div class="message-list">
											<?php foreach ( $group as $msg ) : ?>
												<div class="message-item <?php echo $msg['is_sender'] ? 'sent' : 'received'; ?>">
													<div class="message-meta">
														<span class="message-sender"><?php echo esc_html( $msg['sender'] ); ?></span>
														<span class="message-date"><?php echo esc_html( date( 'g:i a', strtotime( $msg['date'] ) ) ); ?></span>
													</div>
													<div class="message-text"><?php echo esc_html( $msg['text'] ); ?></div>
												</div>
											<?php endforeach; ?>
										</div>
										<div class="conversation-group-footer">
											<button type="button" class="btn-toggle-group" title="Toggle inclusion">✓</button>
											<span class="toggle-label">Included</span>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
				<?php endif; ?>

				<div class="ai-draft-section">
					<div class="ai-draft-header">
						<h4>AI Draft Assistant</h4>
						<div class="ai-model-selector">
							<select id="modelSelect">
								<option value="<?php echo esc_attr( $ollama_model ); ?>"><?php echo esc_html( $ollama_model ); ?></option>
							</select>
							<button type="button" class="btn-refresh-models" onclick="loadOllamaModels()" title="Refresh models">↻</button>
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
						<button type="button" class="btn btn-secondary" onclick="copyDraft()">Copy to Clipboard</button>
						<?php if ( $active_chat && $beeper->is_configured() ) : ?>
							<button type="button" class="btn btn-primary" onclick="sendViaBeeper()">Send via Beeper</button>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
	</div>

	<script>
	window.kcDraftConfig = {
		ollamaModel: <?php echo json_encode( $ollama_model ); ?>,
		personName: <?php echo json_encode( $person->name ); ?>,
		personFirstName: <?php echo json_encode( explode( ' ', $person->name )[0] ); ?>,
		personUsername: <?php echo json_encode( $username ); ?>,
		conversationContext: <?php echo json_encode( array_map( function( $msg ) {
			return [
				'sender' => $msg['is_sender'] ? 'Me' : 'Them',
				'text'   => $msg['text'],
			];
		}, $recent_messages ) ); ?>,
		userMessageSamples: <?php echo json_encode( array_slice( $user_message_samples, 0, 15 ) ); ?>,
		eventsContext: <?php echo json_encode( $events_context ); ?>,
		scheduleNotes: <?php echo json_encode( $schedule['notes'] ?? '' ); ?>,
		daysSinceContact: <?php echo json_encode( $days_since_last_message ); ?>,
		ajaxUrl: <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
		nonce: <?php echo json_encode( wp_create_nonce( 'kc_beeper' ) ); ?>,
		activeChatId: <?php echo json_encode( $active_chat ); ?>
	};
	</script>
	<?php
	if ( function_exists( 'wp_app_enqueue_script' ) ) {
		wp_app_enqueue_script( 'kc-draft-message', plugin_dir_url( __FILE__ ) . 'assets/draft-message.js', [], '1.0', true );
	} else {
		echo '<script src="' . esc_url( plugin_dir_url( __FILE__ ) . 'assets/draft-message.js' ) . '"></script>';
	}
	?>

	<?php $crm->init_cmd_k_js(); ?>
	<?php if ( function_exists( 'wp_app_body_close' ) ) wp_app_body_close(); ?>
</body>
</html>
