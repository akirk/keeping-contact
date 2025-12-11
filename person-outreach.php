<?php
/**
 * Person Outreach History
 *
 * Shows contact history and allows logging new contacts for a specific person.
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

$username = $_GET['person'] ?? '';
if ( empty( $username ) && function_exists( 'get_query_var' ) ) {
	$username = get_query_var( 'person' );
}
if ( empty( $username ) ) {
	header( 'Location: ' . $crm->build_url( 'outreach' ) );
	exit;
}

$person = $crm->storage->get_person( $username );
if ( ! $person ) {
	header( 'Location: ' . $crm->build_url( 'outreach' ) );
	exit;
}

$stats = $kc_storage->get_contact_stats( $username );
$log = $kc_storage->get_contact_log( $username, 50 );

$type_labels = [
	'general' => 'General',
	'email'   => 'Email',
	'call'    => 'Call',
	'meeting' => 'Meeting',
	'message' => 'Message',
];

$status_colors = [
	'overdue'         => '#dc3545',
	'due_soon'        => '#fd7e14',
	'on_track'        => '#28a745',
	'never_contacted' => '#dc3545',
	'paused'          => '#6c757d',
	'no_schedule'     => '#adb5bd',
];

$status_labels = [
	'overdue'         => 'Overdue',
	'due_soon'        => 'Due Soon',
	'on_track'        => 'On Track',
	'never_contacted' => 'Never Contacted',
	'paused'          => 'Paused',
	'no_schedule'     => 'No Schedule',
];

$frequency_labels = [
	7   => 'Weekly',
	14  => 'Every 2 weeks',
	30  => 'Monthly',
	60  => 'Every 2 months',
	90  => 'Quarterly',
	180 => 'Every 6 months',
	365 => 'Yearly',
];

?>
<!DOCTYPE html>
<html <?php echo function_exists( 'wp_app_language_attributes' ) ? wp_app_language_attributes() : 'lang="en"'; ?>>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="color-scheme" content="light dark">
	<title><?php echo function_exists( 'wp_app_title' ) ? wp_app_title( 'Outreach: ' . $person->get_display_name_with_nickname() ) : 'Outreach: ' . $person->get_display_name_with_nickname(); ?></title>
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
				<h1>Outreach History</h1>
				<div class="header-subtitle">
					<a href="<?php echo esc_url( $crm->build_url( 'outreach' ) ); ?>" class="back-link">← Back to Outreach Dashboard</a>
				</div>
			</div>
		</div>

		<div class="outreach-layout">
			<div class="outreach-sidebar">
				<div class="person-link">
					<?php if ( $person->email ) : ?>
						<img src="<?php echo esc_url( $person->get_gravatar_url( 60 ) ); ?>" alt="" class="person-avatar">
					<?php endif; ?>
					<div class="person-info">
						<h2><?php echo esc_html( $person->get_display_name_with_nickname() ); ?></h2>
						<a href="<?php echo esc_url( $person->get_profile_url() ); ?>">View Profile</a>
					</div>
				</div>

				<div class="header-subtitle">
					<span class="status-badge <?php echo esc_attr( str_replace( '_', '-', $stats['status'] ) ); ?>">
						<?php echo esc_html( $status_labels[ $stats['status'] ] ); ?>
					</span>
				</div>

				<?php if ( $stats['schedule'] ) : ?>
					<div class="stat-row">
						<span class="stat-label">Frequency</span>
						<span class="stat-value">
							<?php
							$freq = $stats['schedule']['frequency_days'];
							echo esc_html( $frequency_labels[ $freq ] ?? "Every {$freq} days" );
							?>
						</span>
					</div>
					<div class="stat-row">
						<span class="stat-label">Priority</span>
						<span class="stat-value"><?php echo esc_html( ucfirst( $stats['schedule']['priority'] ) ); ?></span>
					</div>
				<?php endif; ?>

				<?php if ( $stats['last_contact'] ) : ?>
					<div class="stat-row">
						<span class="stat-label">Last Contact</span>
						<span class="stat-value">
							<?php echo esc_html( date( 'M j, Y', strtotime( $stats['last_contact']['contact_date'] ) ) ); ?>
						</span>
					</div>
					<div class="stat-row">
						<span class="stat-label">Days Since</span>
						<span class="stat-value"><?php echo esc_html( $stats['days_since_contact'] ); ?></span>
					</div>
				<?php else : ?>
					<div class="stat-row">
						<span class="stat-label">Last Contact</span>
						<span class="stat-value">Never</span>
					</div>
				<?php endif; ?>

				<?php if ( $stats['days_until_due'] !== null && $stats['status'] !== 'paused' ) : ?>
					<div class="stat-row">
						<span class="stat-label">Next Due</span>
						<span class="stat-value <?php echo $stats['days_until_due'] < 0 ? 'text-overdue' : ''; ?>">
							<?php
							if ( $stats['days_until_due'] < 0 ) {
								echo abs( $stats['days_until_due'] ) . ' days overdue';
							} elseif ( $stats['days_until_due'] == 0 ) {
								echo 'Today';
							} else {
								echo $stats['days_until_due'] . ' days';
							}
							?>
						</span>
					</div>
				<?php endif; ?>

				<div class="log-form">
					<h4>Log New Contact</h4>
					<form id="logContactForm" method="post">
						<input type="hidden" name="action" value="keeping_contact_log">
						<input type="hidden" name="username" value="<?php echo esc_attr( $username ); ?>">
						<?php wp_nonce_field( 'keeping_contact_log', 'nonce' ); ?>

						<div class="form-row">
							<div class="form-group">
								<label for="contact_type">Type</label>
								<select name="contact_type" id="contact_type">
									<option value="general">General</option>
									<option value="email">Email</option>
									<option value="call">Call</option>
									<option value="meeting">Meeting</option>
									<option value="message">Message</option>
								</select>
							</div>

							<div class="form-group">
								<label for="contact_date">Date</label>
								<input type="date" name="contact_date" id="contact_date" value="<?php echo date( 'Y-m-d' ); ?>">
							</div>
						</div>

						<div class="form-group">
							<label for="notes">Notes</label>
							<textarea name="notes" id="notes" rows="3" placeholder="What did you discuss?"></textarea>
						</div>

						<button type="submit" class="btn-submit">Log Contact</button>
					</form>
				</div>
			</div>

			<div class="outreach-main">
				<h3>Contact History</h3>

				<?php if ( ! empty( $log ) ) : ?>
					<?php foreach ( $log as $entry ) : ?>
						<div class="contact-log-item">
							<div class="contact-log-header">
								<span class="contact-log-date">
									<?php echo esc_html( date( 'F j, Y', strtotime( $entry['contact_date'] ) ) ); ?>
								</span>
								<span class="contact-log-type">
									<?php echo esc_html( $type_labels[ $entry['contact_type'] ] ?? 'Contact' ); ?>
								</span>
							</div>
							<?php if ( ! empty( $entry['notes'] ) ) : ?>
								<div class="contact-log-notes">
									<?php echo nl2br( esc_html( $entry['notes'] ) ); ?>
								</div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
					<div class="empty-log">
						<p>No contacts logged yet.</p>
						<p>Use the form to log your first contact.</p>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<script>
	document.getElementById('logContactForm').addEventListener('submit', function(e) {
		e.preventDefault();

		const formData = new FormData(this);

		fetch('<?php echo admin_url( 'admin-ajax.php' ); ?>', {
			method: 'POST',
			body: formData
		})
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				location.reload();
			} else {
				alert('Error: ' + (data.data || 'Failed to log contact'));
			}
		})
		.catch(error => {
			alert('Error: ' + error.message);
		});
	});
	</script>

	<?php if ( function_exists( 'wp_app_footer' ) ) wp_app_footer(); ?>
</body>
</html>
