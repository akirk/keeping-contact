<?php
/**
 * Group Relationship Analysis Page
 *
 * Analyzes relationship patterns across all people in a group with Beeper chats.
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

$group_slug = $_GET['group'] ?? '';
if ( empty( $group_slug ) && function_exists( 'get_query_var' ) ) {
	$group_slug = get_query_var( 'group' );
}
if ( empty( $group_slug ) ) {
	header( 'Location: ' . $crm->build_url( 'index.php' ) );
	exit;
}

$group = $crm->storage->get_group( $group_slug );
if ( ! $group ) {
	header( 'Location: ' . $crm->build_url( 'index.php' ) );
	exit;
}

$beeper_configured = $beeper->is_configured();

$people_with_chats = [];
if ( $beeper_configured ) {
	$members = $group->get_members();

	foreach ( $group->get_child_groups() as $child_group ) {
		$child_members = $child_group->get_members();
		foreach ( $child_members as $username => $person ) {
			if ( ! isset( $members[ $username ] ) ) {
				$members[ $username ] = $person;
			}
		}
	}

	foreach ( $members as $username => $person ) {
		$chat_ids = $kc->storage->get_beeper_chats( $username );
		if ( ! empty( $chat_ids ) ) {
			$people_with_chats[] = [
				'username'  => $username,
				'name'      => $person->get_display_name_with_nickname(),
				'chatId'    => $chat_ids[0],
				'profileUrl' => $person->get_profile_url(),
				'analysisUrl' => $crm->build_url( 'analysis', [ 'person' => $username ] ),
			];
		}
	}
}

?>
<!DOCTYPE html>
<html <?php echo function_exists( 'wp_app_language_attributes' ) ? wp_app_language_attributes() : 'lang="en"'; ?>>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="color-scheme" content="light dark">
	<title><?php echo function_exists( 'wp_app_title' ) ? wp_app_title( 'Group Analysis: ' . $group->group_name ) : 'Group Analysis: ' . $group->group_name; ?></title>
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
				<h1>Group Relationship Analysis</h1>
				<div class="back-nav">
					<a href="<?php echo esc_url( $crm->build_url( 'group.php', [ 'group' => $group_slug ] ) ); ?>">← Back to <?php echo esc_html( $group->group_name ); ?></a>
				</div>
			</div>
		</div>

		<?php if ( ! $beeper_configured ) : ?>
			<div class="beeper-error-notice">
				<strong>Beeper:</strong> API token not configured. Add it in Settings.
			</div>
		<?php elseif ( empty( $people_with_chats ) ) : ?>
			<div class="beeper-error-notice">
				<strong>No Beeper chats:</strong> No members in this group have Beeper chats linked.
			</div>
		<?php else : ?>
			<div class="analysis-container">
				<div class="analysis-loading" id="analysisLoading">
					<div class="analysis-loading-spinner"></div>
					<p>Analyzing relationships...</p>
					<p class="analysis-loading-progress" id="loadingProgress">0 of <?php echo count( $people_with_chats ); ?> people</p>
					<p class="analysis-loading-current" id="loadingCurrent"></p>
				</div>

				<div class="analysis-content" id="analysisContent" style="display: none;">
					<p class="analysis-timeframe" id="analysisTimeframe"></p>
					<div class="analysis-stats">
						<div class="stat-card">
							<div class="stat-card-title">People Analyzed</div>
							<div class="stat-card-value" id="statPeople">-</div>
						</div>
						<div class="stat-card">
							<div class="stat-card-title">Total Messages</div>
							<div class="stat-card-value" id="statMessages">-</div>
						</div>
						<div class="stat-card">
							<div class="stat-card-title">Avg per Person</div>
							<div class="stat-card-value" id="statAvg">-</div>
						</div>
						<div class="stat-card">
							<div class="stat-card-title">Most Active</div>
							<div class="stat-card-value" id="statMostActive">-</div>
						</div>
					</div>

					<div class="analysis-section">
						<h3>Insights</h3>
						<div class="insights-grid" id="insightsGrid"></div>
					</div>

					<div class="analysis-section">
						<h3>All Relationships</h3>
						<table class="analysis-table" id="analysisTable">
							<thead>
								<tr>
									<th class="sortable" data-sort="name">Name</th>
									<th class="sortable" data-sort="total">Messages</th>
									<th class="sortable" data-sort="balance">Balance</th>
									<th class="sortable" data-sort="initiator">Initiator</th>
									<th class="sortable" data-sort="responseTime">Response</th>
									<th class="sortable" data-sort="gap">Longest Gap</th>
								</tr>
							</thead>
							<tbody id="analysisTableBody"></tbody>
						</table>
					</div>
				</div>
			</div>
		<?php endif; ?>
	</div>

	<script>
	window.kcGroupAnalysisConfig = {
		ajaxUrl: <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
		nonce: <?php echo json_encode( wp_create_nonce( 'kc_beeper' ) ); ?>,
		people: <?php echo json_encode( $people_with_chats ); ?>,
		groupName: <?php echo json_encode( $group->group_name ); ?>,
		beeperToken: <?php echo json_encode( $beeper->get_token() ); ?>,
		beeperApiBase: 'http://localhost:23373/v1'
	};
	</script>
	<?php
	if ( function_exists( 'wp_app_enqueue_script' ) ) {
		wp_app_enqueue_script( 'kc-beeper-client', plugin_dir_url( __FILE__ ) . 'assets/beeper-client.js', [], '1.0', true );
		wp_localize_script( 'kc-beeper-client', 'BeeperClientConfig', KeepingContact::get_beeper_client_config() );
		wp_app_enqueue_script( 'kc-analysis-group', plugin_dir_url( __FILE__ ) . 'assets/analysis-group.js', [ 'kc-beeper-client' ], '1.0', true );
	} else {
		echo '<script>var BeeperClientConfig = ' . wp_json_encode( KeepingContact::get_beeper_client_config() ) . ';</script>';
		echo '<script src="' . esc_url( plugin_dir_url( __FILE__ ) . 'assets/beeper-client.js' ) . '"></script>';
		echo '<script src="' . esc_url( plugin_dir_url( __FILE__ ) . 'assets/analysis-group.js' ) . '"></script>';
	}
	?>

	<?php $crm->init_cmd_k_js(); ?>
	<?php if ( function_exists( 'wp_app_body_close' ) ) wp_app_body_close(); ?>
</body>
</html>
