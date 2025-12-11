<?php
/**
 * Keeping Contact Settings
 *
 * Configure Beeper integration and other settings.
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

$beeper_configured = $beeper->is_configured();
$beeper_token = get_option( 'keeping_contact_beeper_token', '' );

?>
<!DOCTYPE html>
<html <?php echo function_exists( 'wp_app_language_attributes' ) ? wp_app_language_attributes() : 'lang="en"'; ?>>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="color-scheme" content="light dark">
	<title><?php echo function_exists( 'wp_app_title' ) ? wp_app_title( 'Outreach Settings' ) : 'Outreach Settings'; ?></title>
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
				<h1>Outreach Settings</h1>
				<div class="header-subtitle">
					<a href="<?php echo esc_url( $crm->build_url( 'outreach' ) ); ?>" class="back-link">← Back to Outreach</a>
					<span class="nav-separator">|</span>
					<a href="<?php echo esc_url( $crm->build_url( 'outreach-beeper' ) ); ?>" class="back-link">Beeper Connections</a>
				</div>
			</div>
		</div>

		<div class="settings-card">
			<h3>
				💬 Beeper Integration
				<?php if ( $beeper_configured ) : ?>
					<span class="status-badge status-connected">Connected</span>
				<?php else : ?>
					<span class="status-badge status-disconnected">Not configured</span>
				<?php endif; ?>
			</h3>

			<p>Connect to Beeper to automatically sync your messaging history and see recent conversations on contact pages.</p>

			<form id="beeperSettingsForm">
				<?php wp_nonce_field( 'kc_beeper', '_wpnonce' ); ?>

				<div class="form-group">
					<label for="beeper_token">API Token</label>
					<input type="password" id="beeper_token" name="token"
					       value="<?php echo esc_attr( $beeper_token ); ?>"
					       placeholder="Enter your Beeper API token">
					<p class="help-text">
						To get a token: Open Beeper Desktop → Settings → Developer → Create API Token
					</p>
				</div>

				<div class="settings-actions">
					<button type="submit" class="btn btn-primary">Save Token</button>
					<button type="button" class="btn btn-secondary" onclick="testBeeperConnection()">Test Connection</button>
				</div>

				<div id="connectionStatus"></div>
			</form>
		</div>

		<div class="settings-card">
			<h3>About Keeping Contact</h3>
			<p>This plugin helps you maintain relationships by:</p>
			<ul>
				<li>Setting contact frequency for each person (weekly, monthly, quarterly, etc.)</li>
				<li>Tracking when you last reached out</li>
				<li>Showing who's overdue for contact</li>
				<li>Syncing with Beeper to see recent conversations</li>
			</ul>
			<p class="context-notes">
				Inspired by Derek Sivers' "Why You Need a Database"
			</p>
		</div>
	</div>

	<script>
	document.getElementById('beeperSettingsForm').addEventListener('submit', function(e) {
		e.preventDefault();
		var token = document.getElementById('beeper_token').value;
		var statusDiv = document.getElementById('connectionStatus');

		while (statusDiv.firstChild) statusDiv.removeChild(statusDiv.firstChild);

		var saving = document.createElement('div');
		saving.style.cssText = 'padding: 12px; color: #666;';
		saving.textContent = 'Saving...';
		statusDiv.appendChild(saving);

		fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'action=kc_beeper_save_token&token=' + encodeURIComponent(token) + '&_wpnonce=<?php echo wp_create_nonce( 'kc_beeper' ); ?>'
		})
		.then(function(r) { return r.json(); })
		.then(function(data) {
			while (statusDiv.firstChild) statusDiv.removeChild(statusDiv.firstChild);

			var result = document.createElement('div');
			result.style.cssText = 'padding: 12px; border-radius: 4px; margin-top: 12px;';

			if (data.success) {
				if (data.data.connected) {
					result.style.background = '#d4edda';
					result.style.color = '#155724';
					result.textContent = 'Connected! Found ' + data.data.accounts + ' account(s).';
					setTimeout(function() { location.reload(); }, 1500);
				} else {
					result.style.background = '#fff3cd';
					result.style.color = '#856404';
					result.textContent = 'Token cleared.';
					setTimeout(function() { location.reload(); }, 1000);
				}
			} else {
				result.style.background = '#f8d7da';
				result.style.color = '#721c24';
				result.textContent = 'Error: ' + (data.data || 'Unknown error');
			}
			statusDiv.appendChild(result);
		})
		.catch(function(err) {
			while (statusDiv.firstChild) statusDiv.removeChild(statusDiv.firstChild);
			var errDiv = document.createElement('div');
			errDiv.style.cssText = 'padding: 12px; background: #f8d7da; color: #721c24; border-radius: 4px;';
			errDiv.textContent = 'Error: ' + err.message;
			statusDiv.appendChild(errDiv);
		});
	});

	function testBeeperConnection() {
		var statusDiv = document.getElementById('connectionStatus');
		while (statusDiv.firstChild) statusDiv.removeChild(statusDiv.firstChild);

		var testing = document.createElement('div');
		testing.style.cssText = 'padding: 12px; color: #666;';
		testing.textContent = 'Testing connection...';
		statusDiv.appendChild(testing);

		fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'action=kc_beeper_test&_wpnonce=<?php echo wp_create_nonce( 'kc_beeper' ); ?>'
		})
		.then(function(r) { return r.json(); })
		.then(function(data) {
			while (statusDiv.firstChild) statusDiv.removeChild(statusDiv.firstChild);

			var result = document.createElement('div');
			result.className = 'accounts-list';

			if (data.success) {
				var title = document.createElement('strong');
				title.textContent = 'Connection successful!';
				title.style.color = '#155724';
				result.appendChild(title);

				var info = document.createElement('div');
				info.textContent = 'Found ' + data.data.accounts + ' connected account(s):';
				result.appendChild(info);

				if (data.data.networks && data.data.networks.length > 0) {
					var list = document.createElement('ul');
					data.data.networks.forEach(function(network) {
						var li = document.createElement('li');
						li.textContent = network;
						list.appendChild(li);
					});
					result.appendChild(list);
				}
			} else {
				result.style.background = '#f8d7da';
				var errText = document.createElement('span');
				errText.style.color = '#721c24';
				errText.textContent = 'Connection failed: ' + (data.data || 'Unknown error');
				result.appendChild(errText);
			}
			statusDiv.appendChild(result);
		})
		.catch(function(err) {
			while (statusDiv.firstChild) statusDiv.removeChild(statusDiv.firstChild);
			var errDiv = document.createElement('div');
			errDiv.style.cssText = 'padding: 12px; background: #f8d7da; color: #721c24; border-radius: 4px;';
			errDiv.textContent = 'Error: ' + err.message;
			statusDiv.appendChild(errDiv);
		});
	}
	</script>

	<?php if ( function_exists( 'wp_app_footer' ) ) wp_app_footer(); ?>
</body>
</html>
