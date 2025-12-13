<?php
/**
 * Relationship Analysis Page
 *
 * Visualizes conversation patterns and communication dynamics.
 */

namespace KeepingContact;

require_once __DIR__ . '/../personal-crm/personal-crm.php';
require_once __DIR__ . '/keeping-contact.php';

use PersonalCRM\PersonalCrm;

$crm = PersonalCrm::get_instance();
extract( PersonalCrm::get_globals() );

KeepingContact::init( $crm );
$kc = KeepingContact::get_instance();
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

$chat_ids = get_option( 'kc_beeper_chats_' . $username, [] );
if ( ! is_array( $chat_ids ) ) {
	$chat_ids = [];
}

$active_chat = ! empty( $chat_ids ) ? $chat_ids[0] : null;
$beeper_configured = $beeper->is_configured();

?>
<!DOCTYPE html>
<html <?php echo function_exists( 'wp_app_language_attributes' ) ? wp_app_language_attributes() : 'lang="en"'; ?>>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="color-scheme" content="light dark">
	<title><?php echo function_exists( 'wp_app_title' ) ? wp_app_title( 'Analysis: ' . $person->get_display_name_with_nickname() ) : 'Analysis: ' . $person->get_display_name_with_nickname(); ?></title>
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
				<h1>Relationship Analysis</h1>
				<div class="header-subtitle">
					<a href="<?php echo esc_url( $crm->build_url( 'conversations', array( 'person' => $username ) ) ); ?>" class="back-link">← Back to conversation</a>
				</div>
			</div>
			<?php if ( $person->email ) : ?>
				<a href="<?php echo esc_url( $person->get_profile_url() ); ?>">
					<img src="<?php echo esc_url( $person->get_gravatar_url( 60 ) ); ?>" alt="" class="header-avatar">
				</a>
			<?php endif; ?>
		</div>

		<?php if ( ! $beeper_configured ) : ?>
			<div class="beeper-error-notice">
				<strong>Beeper:</strong> API token not configured. Add it in Settings.
			</div>
		<?php elseif ( ! $active_chat ) : ?>
			<div class="beeper-error-notice">
				<strong>Beeper:</strong> No chats linked to this person.
			</div>
		<?php else : ?>
			<div class="analysis-container">
				<div class="analysis-loading" id="analysisLoading">
					<div class="analysis-loading-spinner"></div>
					<p>Loading conversation history...</p>
					<p class="analysis-loading-progress" id="loadingProgress">0 messages loaded</p>
				</div>

				<div class="analysis-load-more" id="loadMoreContainer" style="display: none;">
					<button type="button" class="btn btn-secondary" id="loadMoreBtn">Load older history</button>
					<span class="analysis-load-more-hint" id="loadMoreHint"></span>
				</div>

				<div class="analysis-content" id="analysisContent" style="display: none;">
					<div class="analysis-stats">
						<div class="stat-card">
							<div class="stat-card-title">Total Messages</div>
							<div class="stat-card-value" id="statTotal">-</div>
							<div class="stat-card-detail" id="statTotalDetail"></div>
						</div>
						<div class="stat-card">
							<div class="stat-card-title">Who Initiates More</div>
							<div class="stat-card-value" id="statInitiator">-</div>
							<div class="stat-card-detail" id="statInitiatorDetail"></div>
						</div>
						<div class="stat-card">
							<div class="stat-card-title">Avg Response Time</div>
							<div class="stat-card-value" id="statResponseTime">-</div>
							<div class="stat-card-detail" id="statResponseTimeDetail"></div>
						</div>
						<div class="stat-card">
							<div class="stat-card-title">Longest Gap</div>
							<div class="stat-card-value" id="statLongestGap">-</div>
							<div class="stat-card-detail" id="statLongestGapDetail"></div>
						</div>
					</div>

					<div class="analysis-section">
						<h3>Message Balance</h3>
						<div class="balance-bar-container">
							<div class="balance-bar" id="balanceBar">
								<div class="balance-bar-you" id="balanceBarYou"></div>
								<div class="balance-bar-them" id="balanceBarThem"></div>
							</div>
							<div class="balance-labels">
								<span id="balanceLabelYou">You: 0%</span>
								<span id="balanceLabelThem">Them: 0%</span>
							</div>
						</div>
					</div>

					<div class="analysis-section">
						<h3>Conversation Timeline</h3>
						<p class="analysis-hint">Activity over the past year</p>
						<div class="timeline-container" id="timelineContainer"></div>
						<div class="timeline-legend">
							<span class="timeline-legend-item"><span class="timeline-legend-color you"></span> You</span>
							<span class="timeline-legend-item"><span class="timeline-legend-color them"></span> Them</span>
						</div>
					</div>

					<div class="analysis-section">
						<h3>Conversation Sessions</h3>
						<div class="sessions-stats">
							<div class="sessions-stat">
								<span class="sessions-stat-value" id="statSessions">-</span>
								<span class="sessions-stat-label">conversations</span>
							</div>
							<div class="sessions-stat">
								<span class="sessions-stat-value" id="statAvgLength">-</span>
								<span class="sessions-stat-label">avg messages per conversation</span>
							</div>
							<div class="sessions-stat">
								<span class="sessions-stat-value" id="statFrequency">-</span>
								<span class="sessions-stat-label">avg days between conversations</span>
							</div>
						</div>
					</div>
				</div>
			</div>
		<?php endif; ?>
	</div>

	<script>
	window.kcAnalysisConfig = {
		ajaxUrl: <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
		nonce: <?php echo json_encode( wp_create_nonce( 'kc_beeper' ) ); ?>,
		chatId: <?php echo json_encode( $active_chat ); ?>,
		personName: <?php echo json_encode( $person->name ); ?>
	};
	</script>
	<?php
	if ( function_exists( 'wp_app_enqueue_script' ) ) {
		wp_app_enqueue_script( 'kc-analysis', plugin_dir_url( __FILE__ ) . 'assets/analysis.js', [], '1.0', true );
	} else {
		echo '<script src="' . esc_url( plugin_dir_url( __FILE__ ) . 'assets/analysis.js' ) . '"></script>';
	}
	?>

	<?php $crm->init_cmd_k_js(); ?>
	<?php if ( function_exists( 'wp_app_body_close' ) ) wp_app_body_close(); ?>
</body>
</html>
