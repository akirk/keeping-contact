<?php
/**
 * Outreach Dashboard
 *
 * Shows people who need to be contacted, organized by priority and overdue status.
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

$overdue = $kc_storage->get_overdue_contacts();
$upcoming = $kc_storage->get_upcoming_contacts( 14 );

// Get people mapped to Beeper but without a schedule
$beeper_without_schedule = [];
$all_schedules = $kc_storage->get_all_schedules();
$chat_mappings = $kc_storage->get_all_beeper_chat_mappings();
$beeper_connected = [];
foreach ( $chat_mappings as $chat_id => $username ) {
	$beeper_connected[ $username ] = true;
	if ( ! isset( $all_schedules[ $username ] ) && ! in_array( $username, $beeper_without_schedule ) ) {
		$beeper_without_schedule[] = $username;
	}
}

// Get all people from CRM who are not connected to Beeper
$not_connected_to_beeper = [];
$all_people = $crm->storage->get_all_people();
foreach ( $all_people as $username => $person ) {
	if ( ! isset( $beeper_connected[ $username ] ) ) {
		$not_connected_to_beeper[] = [
			'username' => $username,
			'name' => $person->get_display_name_with_nickname(),
		];
	}
}

$people_cache = [];
$get_person = function( $username ) use ( $crm, &$people_cache ) {
	if ( ! isset( $people_cache[ $username ] ) ) {
		$people_cache[ $username ] = $crm->storage->get_person( $username );
	}
	return $people_cache[ $username ];
};

$format_days = function( $days, $last_date ) {
	if ( $last_date === null ) {
		return 'Never contacted';
	}
	if ( $days == 0 ) {
		return 'Today';
	}
	if ( $days == 1 ) {
		return '1 day ago';
	}
	if ( $days < 30 ) {
		return $days . ' days ago';
	}
	if ( $days < 60 ) {
		return '1 month ago';
	}
	$months = floor( $days / 30 );
	return $months . ' months ago';
};

?>
<!DOCTYPE html>
<html <?php echo function_exists( 'wp_app_language_attributes' ) ? wp_app_language_attributes() : 'lang="en"'; ?>>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="color-scheme" content="light dark">
	<title><?php echo function_exists( 'wp_app_title' ) ? wp_app_title( 'Outreach Dashboard' ) : 'Outreach Dashboard'; ?></title>
	<?php if ( function_exists( 'wp_app_head' ) ) wp_app_head(); ?>
</head>
<body class="wp-app-body">
	<?php if ( function_exists( 'wp_app_body_open' ) ) wp_app_body_open(); ?>
	<?php $crm->render_cmd_k_panel(); ?>

	<div class="container">
		<div class="header">
			<div class="header-content">
				<h1>Keeping Contact</h1>
				<div class="back-nav">
					<a href="<?php echo $crm->build_url( 'index.php' ); ?>">← Back to Groups</a>
					<span class="nav-separator">|</span>
					<a href="<?php echo esc_url( $crm->build_url( 'outreach-beeper' ) ); ?>">Beeper</a>
					<span class="nav-separator">|</span>
					<a href="<?php echo esc_url( $crm->build_url( 'outreach-settings' ) ); ?>">Settings</a>
				</div>
			</div>
		</div>

		<?php if ( ! empty( $overdue ) ) : ?>
			<div class="section-header">
				<h2>Overdue</h2>
				<span class="section-count"><?php echo count( $overdue ); ?></span>
			</div>
			<div class="outreach-grid">
				<?php foreach ( $overdue as $item ) :
					$person = $get_person( $item['username'] );
					if ( ! $person ) continue;
				?>
					<div class="outreach-card overdue">
						<div class="outreach-card-header">
							<div class="outreach-card-name">
								<a href="<?php echo esc_url( $person->get_profile_url() ); ?>">
									<?php echo esc_html( $person->get_display_name_with_nickname() ); ?>
								</a>
							</div>
							<span class="outreach-card-priority priority-<?php echo esc_attr( $item['priority'] ); ?>">
								<?php echo esc_html( $item['priority'] ); ?>
							</span>
						</div>
						<div class="outreach-card-meta">
							<div>Last contact: <?php echo esc_html( $format_days( $item['days_since_contact'], $item['last_contact_date'] ) ); ?></div>
							<div>Schedule: Every <?php echo esc_html( $item['frequency_days'] ); ?> days</div>
							<?php if ( $item['days_overdue'] > 0 ) : ?>
								<div class="text-overdue">
									<?php echo esc_html( $item['days_overdue'] ); ?> days overdue
								</div>
							<?php endif; ?>
						</div>
						<div class="outreach-card-actions">
							<button class="btn-log" onclick="openLogModal('<?php echo esc_attr( $item['username'] ); ?>', '<?php echo esc_attr( $person->get_display_name_with_nickname() ); ?>')">
								Log Contact
							</button>
							<a href="<?php echo esc_url( $crm->build_url( 'outreach', array( 'person' => $item['username'] ) ) ); ?>" class="btn-log">
								View History
							</a>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $upcoming ) ) : ?>
			<div class="section-header">
				<h2>Due Soon (within 2 weeks)</h2>
				<span class="section-count"><?php echo count( $upcoming ); ?></span>
			</div>
			<div class="outreach-grid">
				<?php foreach ( $upcoming as $item ) :
					$person = $get_person( $item['username'] );
					if ( ! $person ) continue;
				?>
					<div class="outreach-card due-soon">
						<div class="outreach-card-header">
							<div class="outreach-card-name">
								<a href="<?php echo esc_url( $person->get_profile_url() ); ?>">
									<?php echo esc_html( $person->get_display_name_with_nickname() ); ?>
								</a>
							</div>
							<span class="outreach-card-priority priority-<?php echo esc_attr( $item['priority'] ); ?>">
								<?php echo esc_html( $item['priority'] ); ?>
							</span>
						</div>
						<div class="outreach-card-meta">
							<div>Last contact: <?php echo esc_html( $format_days( $item['days_since_contact'], $item['last_contact_date'] ) ); ?></div>
							<div>Due in: <?php echo esc_html( $item['days_until_due'] ); ?> days</div>
						</div>
						<div class="outreach-card-actions">
							<button class="btn-log" onclick="openLogModal('<?php echo esc_attr( $item['username'] ); ?>', '<?php echo esc_attr( $person->get_display_name_with_nickname() ); ?>')">
								Log Contact
							</button>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $not_connected_to_beeper ) ) : ?>
			<div class="section-header">
				<h2>Not Yet Connected</h2>
				<span class="section-count"><?php echo count( $not_connected_to_beeper ); ?></span>
				<button class="btn-bulk-connect" onclick="startBulkConnect()">Bulk Connect</button>
			</div>
			<div id="bulkConnectStatus" class="bulk-connect-status" style="display: none;">
				<div class="bulk-progress-bar">
					<div class="bulk-progress-fill" id="bulkProgressFill"></div>
				</div>
				<div class="bulk-progress-text">
					<span id="bulkProgressText">0 / <?php echo count( $not_connected_to_beeper ); ?></span>
					<span id="bulkCurrentName"></span>
				</div>
				<div class="bulk-results" id="bulkResults"></div>
			</div>
			<script>
			var bulkConnectPeople = <?php echo json_encode( $not_connected_to_beeper ); ?>;
			</script>
		<?php endif; ?>

		<?php if ( ! empty( $beeper_without_schedule ) ) : ?>
			<div class="section-header">
				<h2>Needs Schedule</h2>
				<span class="section-count"><?php echo count( $beeper_without_schedule ); ?></span>
			</div>
			<div class="outreach-grid">
				<?php foreach ( $beeper_without_schedule as $username ) :
					$person = $get_person( $username );
					if ( ! $person ) continue;
				?>
					<div class="outreach-card">
						<div class="outreach-card-header">
							<div class="outreach-card-name">
								<a href="<?php echo esc_url( $person->get_profile_url() ); ?>">
									<?php echo esc_html( $person->get_display_name_with_nickname() ); ?>
								</a>
							</div>
						</div>
						<div class="outreach-card-meta">
							<div>Linked to Beeper, but no schedule set</div>
						</div>
						<div class="outreach-card-actions">
							<a href="<?php echo esc_url( $crm->build_url( 'outreach', array( 'person' => $username ) ) ); ?>" class="btn-log">
								Set Schedule
							</a>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( empty( $overdue ) && empty( $upcoming ) && empty( $beeper_without_schedule ) ) : ?>
			<div class="empty-state">
				<p>No contacts due at this time.</p>
				<p>Add contact schedules to people in the CRM to start tracking outreach.</p>
			</div>
		<?php endif; ?>
	</div>

	<div id="logModal" class="log-modal">
		<div class="log-modal-content">
			<h3>Log Contact with <span id="modalPersonName"></span></h3>
			<form id="logContactForm" method="post">
				<input type="hidden" name="action" value="keeping_contact_log">
				<input type="hidden" name="username" id="modalUsername">
				<?php wp_nonce_field( 'keeping_contact_log', 'nonce' ); ?>

				<div class="form-group">
					<label for="contact_type">Contact Type</label>
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

				<div class="form-group">
					<label for="notes">Notes (optional)</label>
					<textarea name="notes" id="notes" rows="3" placeholder="What did you discuss?"></textarea>
				</div>

				<div class="modal-actions">
					<button type="button" class="btn-log" onclick="closeLogModal()">Cancel</button>
					<button type="submit" class="btn-submit">
						Save
					</button>
				</div>
			</form>
		</div>
	</div>

	<script>
	function openLogModal(username, name) {
		document.getElementById('modalUsername').value = username;
		document.getElementById('modalPersonName').textContent = name;
		document.getElementById('logModal').classList.add('active');
	}

	function closeLogModal() {
		document.getElementById('logModal').classList.remove('active');
		document.getElementById('logContactForm').reset();
		document.getElementById('contact_date').value = '<?php echo date( 'Y-m-d' ); ?>';
	}

	document.getElementById('logModal').addEventListener('click', function(e) {
		if (e.target === this) closeLogModal();
	});

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

	<?php if ( ! empty( $not_connected_to_beeper ) ) : ?>
	<script>
	var bulkConnectRunning = false;
	var bulkConnectResults = { connected: 0, notFound: 0, errors: 0 };
	var kcBeeperConfig = {
		ajaxUrl: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
		nonce: '<?php echo wp_create_nonce( 'kc_beeper' ); ?>'
	};

	function startBulkConnect() {
		if (bulkConnectRunning) return;
		bulkConnectRunning = true;

		document.getElementById('bulkConnectStatus').style.display = 'block';
		document.querySelector('.btn-bulk-connect').disabled = true;
		document.querySelector('.btn-bulk-connect').textContent = 'Running...';

		bulkConnectResults = { connected: 0, notFound: 0, errors: 0 };
		processBulkConnect(0);
	}

	async function processBulkConnect(index) {
		if (index >= bulkConnectPeople.length) {
			finishBulkConnect();
			return;
		}

		var person = bulkConnectPeople[index];
		var total = bulkConnectPeople.length;

		document.getElementById('bulkProgressText').textContent = (index + 1) + ' / ' + total;
		document.getElementById('bulkCurrentName').textContent = person.name;
		document.getElementById('bulkProgressFill').style.width = ((index + 1) / total * 100) + '%';

		try {
			var response = await fetch(kcBeeperConfig.ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=kc_beeper_search&name=' + encodeURIComponent(person.name) + '&username=' + encodeURIComponent(person.username) + '&_wpnonce=' + kcBeeperConfig.nonce
			});

			var data = await response.json();

			if (data.success && data.data.chats && data.data.chats.length >= 1) {
				// Pick the chat with most recent activity
				var chats = data.data.chats;
				var bestChat = chats[0];
				if (chats.length > 1) {
					chats.forEach(function(chat) {
						if (chat.lastActivity && (!bestChat.lastActivity || new Date(chat.lastActivity) > new Date(bestChat.lastActivity))) {
							bestChat = chat;
						}
					});
				}

				// Auto-connect to best match
				var linkResponse = await fetch(kcBeeperConfig.ajaxUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: 'action=kc_beeper_link&username=' + encodeURIComponent(person.username) + '&chat_id=' + encodeURIComponent(bestChat.id) + '&_wpnonce=' + kcBeeperConfig.nonce
				});

				var linkData = await linkResponse.json();
				if (linkData.success) {
					bulkConnectResults.connected++;
					var suffix = chats.length > 1 ? ' (best of ' + chats.length + ')' : '';
					addBulkResult(person.name, 'connected', '✓ Connected' + suffix);
				} else {
					bulkConnectResults.errors++;
					addBulkResult(person.name, 'error', '✗ Link failed');
				}
			} else {
				bulkConnectResults.notFound++;
				addBulkResult(person.name, 'not-found', '— Not found');
			}
		} catch (err) {
			bulkConnectResults.errors++;
			addBulkResult(person.name, 'error', '✗ Error');
		}

		// Process next after a small delay to avoid hammering the API
		setTimeout(function() {
			processBulkConnect(index + 1);
		}, 300);
	}

	function addBulkResult(name, type, status) {
		var resultsDiv = document.getElementById('bulkResults');
		var item = document.createElement('div');
		item.className = 'bulk-result-item bulk-result-' + type;
		item.textContent = status + ' ' + name;
		resultsDiv.appendChild(item);
		resultsDiv.scrollTop = resultsDiv.scrollHeight;
	}

	function finishBulkConnect() {
		bulkConnectRunning = false;
		document.getElementById('bulkCurrentName').textContent = 'Done!';
		document.querySelector('.btn-bulk-connect').textContent = 'Bulk Connect';
		document.querySelector('.btn-bulk-connect').disabled = false;

		var summary = document.createElement('div');
		summary.className = 'bulk-summary';
		var strong = document.createElement('strong');
		strong.textContent = 'Summary: ';
		summary.appendChild(strong);
		summary.appendChild(document.createTextNode(
			bulkConnectResults.connected + ' connected, ' +
			bulkConnectResults.notFound + ' not found' +
			(bulkConnectResults.errors > 0 ? ', ' + bulkConnectResults.errors + ' errors' : '')
		));
		document.getElementById('bulkResults').appendChild(summary);

		if (bulkConnectResults.connected > 0) {
			var reloadBtn = document.createElement('button');
			reloadBtn.className = 'btn-reload';
			reloadBtn.textContent = 'Reload Page';
			reloadBtn.onclick = function() { location.reload(); };
			document.getElementById('bulkResults').appendChild(reloadBtn);
		}
	}
	</script>
	<?php endif; ?>

	<?php if ( function_exists( 'wp_app_body_close' ) ) wp_app_body_close(); ?>
	<?php $crm->init_cmd_k_js(); ?>
	<?php if ( function_exists( 'wp_app_footer' ) ) wp_app_footer(); ?>
</body>
</html>
