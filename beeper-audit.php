<?php
/**
 * Beeper Audit Page
 *
 * Shows all people with linked Beeper chats.
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

// Get all beeper chat mappings
$chat_mappings = $kc->storage->get_all_beeper_chat_mappings();

// Group by username
$chats_by_username = [];
foreach ( $chat_mappings as $chat_id => $username ) {
	if ( ! isset( $chats_by_username[ $username ] ) ) {
		$chats_by_username[ $username ] = [];
	}
	$chats_by_username[ $username ][] = $chat_id;
}

$linked_people = [];
foreach ( $chats_by_username as $username => $chat_ids ) {
	$person = $crm->storage->get_person( $username );
	if ( $person ) {
		$linked_people[] = [
			'username' => $username,
			'person' => $person,
			'chat_ids' => $chat_ids,
		];
	}
}

// Sort by name
usort( $linked_people, function( $a, $b ) {
	return strcasecmp( $a['person']->name, $b['person']->name );
} );

?>
<!DOCTYPE html>
<html <?php echo function_exists( 'wp_app_language_attributes' ) ? wp_app_language_attributes() : 'lang="en"'; ?>>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="color-scheme" content="light dark">
	<title><?php echo function_exists( 'wp_app_title' ) ? wp_app_title( 'Beeper Connections' ) : 'Beeper Connections'; ?></title>
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
				<h1>Beeper Connections</h1>
				<div class="back-nav">
					<a href="<?php echo esc_url( $crm->build_url( 'outreach' ) ); ?>">← Back to Outreach</a>
					<span class="nav-separator">|</span>
					<a href="<?php echo esc_url( $crm->build_url( 'outreach-settings' ) ); ?>">Settings</a>
				</div>
			</div>
		</div>

		<?php
		$total_chats = 0;
		foreach ( $linked_people as $item ) {
			$total_chats += count( $item['chat_ids'] );
		}
		?>
		<div class="stats">
			<div class="stat-item">
				<div class="stat-value"><?php echo count( $linked_people ); ?></div>
				<div class="stat-label">People Connected</div>
			</div>
			<div class="stat-item">
				<div class="stat-value"><?php echo $total_chats; ?></div>
				<div class="stat-label">Total Chats Linked</div>
			</div>
		</div>

		<?php if ( ! empty( $linked_people ) ) : ?>
			<div class="audit-grid">
				<?php foreach ( $linked_people as $item ) :
					$person = $item['person'];
				?>
					<div class="audit-card">
						<div class="audit-card-header">
							<div class="audit-card-name">
								<a href="<?php echo esc_url( $person->get_profile_url() ); ?>">
									<?php echo esc_html( $person->get_display_name_with_nickname() ); ?>
								</a>
							</div>
							<span class="audit-card-count">
								<?php echo count( $item['chat_ids'] ); ?> chat<?php echo count( $item['chat_ids'] ) !== 1 ? 's' : ''; ?>
							</span>
						</div>
						<div class="chat-list">
							<?php foreach ( $item['chat_ids'] as $chat_id ) :
								$chat = $beeper->get_chat( $chat_id );
								$network = is_wp_error( $chat ) ? 'Unknown' : ( $chat['network'] ?? 'Unknown' );
								$chat_name = $chat_id;
								if ( ! is_wp_error( $chat ) ) {
									$participants = $chat['participants']['items'] ?? [];
									foreach ( $participants as $p ) {
										if ( empty( $p['isSelf'] ) && ! empty( $p['fullName'] ) ) {
											$chat_name = $p['fullName'];
											break;
										}
									}
									if ( $chat_name === $chat_id ) {
										$chat_name = $chat['title'] ?? $network;
									}
								}
							?>
								<div class="chat-item">
									<span><?php echo esc_html( $chat_name ); ?></span>
									<span class="chat-network"><?php echo esc_html( $network ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<div class="empty-state">
				<p>No Beeper connections yet.</p>
				<p>Visit a person's profile and click "Add Chat" to link their Beeper conversations.</p>
			</div>
		<?php endif; ?>
	</div>

	<?php $crm->init_cmd_k_js(); ?>
	<?php if ( function_exists( 'wp_app_body_close' ) ) wp_app_body_close(); ?>
</body>
</html>
