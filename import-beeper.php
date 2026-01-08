<?php
/**
 * Import Contacts from Beeper
 *
 * Browse all Beeper chats and bulk import them as new CRM contacts.
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

if ( ! $beeper->is_configured() ) {
	$settings_url = $crm->build_url( 'outreach-settings' );
	?>
	<!DOCTYPE html>
	<html <?php echo function_exists( 'wp_app_language_attributes' ) ? wp_app_language_attributes() : 'lang="en"'; ?>>
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title><?php echo function_exists( 'wp_app_title' ) ? wp_app_title( 'Import from Beeper' ) : 'Import from Beeper'; ?></title>
		<?php if ( function_exists( 'wp_app_head' ) ) wp_app_head(); ?>
	</head>
	<body class="wp-app-body">
		<?php if ( function_exists( 'wp_app_body_open' ) ) wp_app_body_open(); ?>
		<div class="container">
			<div class="header">
				<h1>Import from Beeper</h1>
			</div>
			<div class="message message-warning">
				Beeper is not configured. <a href="<?php echo esc_url( $settings_url ); ?>">Configure Beeper</a> first.
			</div>
		</div>
		<?php if ( function_exists( 'wp_app_footer' ) ) wp_app_footer(); ?>
	</body>
	</html>
	<?php
	exit;
}

$chat_to_username = $kc->storage->get_all_beeper_chat_mappings();

?>
<!DOCTYPE html>
<html <?php echo function_exists( 'wp_app_language_attributes' ) ? wp_app_language_attributes() : 'lang="en"'; ?>>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="color-scheme" content="light dark">
	<title><?php echo function_exists( 'wp_app_title' ) ? wp_app_title( 'Import from Beeper' ) : 'Import from Beeper'; ?></title>
	<?php if ( function_exists( 'wp_app_head' ) ) wp_app_head(); ?>
	<style>
		.import-toolbar {
			display: flex;
			gap: 1rem;
			align-items: center;
			margin-bottom: 1.5rem;
			flex-wrap: wrap;
		}
		.filter-input {
			padding: 0.6rem 1rem;
			font-size: 1rem;
			border: 1px solid var(--border-color, #ddd);
			border-radius: 6px;
			flex: 1;
			min-width: 200px;
			max-width: 400px;
			background: var(--input-bg, #fff);
			color: var(--text-color, #333);
		}
		.btn-import-selected {
			padding: 0.6rem 1.5rem;
			font-size: 1rem;
			background: var(--success-color, #46b450);
			color: #fff;
			border: none;
			border-radius: 6px;
			cursor: pointer;
		}
		.btn-import-selected:hover {
			opacity: 0.9;
		}
		.btn-import-selected:disabled {
			opacity: 0.5;
			cursor: not-allowed;
		}
		.selection-info {
			color: var(--text-muted, #666);
			font-size: 0.9rem;
		}
		.select-actions {
			display: flex;
			gap: 0.5rem;
		}
		.select-actions button {
			padding: 0.4rem 0.8rem;
			font-size: 0.85rem;
			background: var(--secondary-bg, #f0f0f0);
			color: var(--text-color, #333);
			border: 1px solid var(--border-color, #ddd);
			border-radius: 4px;
			cursor: pointer;
		}
		.select-actions button:hover {
			background: var(--hover-bg, #e5e5e5);
		}
		.chat-list {
			display: grid;
			gap: 0.5rem;
		}
		.chat-item {
			display: flex;
			align-items: center;
			gap: 1rem;
			padding: 0.75rem 1rem;
			background: var(--card-bg, #fff);
			border: 1px solid var(--border-color, #ddd);
			border-radius: 6px;
			cursor: pointer;
		}
		.chat-item:hover {
			background: var(--hover-bg, #f5f5f5);
		}
		.chat-item.is-linked {
			opacity: 0.5;
			cursor: default;
		}
		.chat-item.is-selected {
			background: var(--selected-bg, #e8f4fd);
			border-color: var(--primary-color, #0073aa);
		}
		.chat-item.is-imported {
			background: var(--success-bg, #d4edda);
			border-color: var(--success-color, #46b450);
		}
		.chat-item input[type="checkbox"] {
			width: 18px;
			height: 18px;
			cursor: pointer;
		}
		.chat-item.is-linked input[type="checkbox"] {
			cursor: default;
		}
		.chat-info {
			flex: 1;
			min-width: 0;
		}
		.chat-name {
			font-weight: 500;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
			color: var(--text-color, #333);
		}
		.chat-name a {
			color: var(--link-color, #0073aa);
		}
		.is-imported .chat-name a {
			color: var(--success-color, #46b450);
		}
		.chat-meta {
			font-size: 0.8rem;
			color: var(--text-muted, #666);
		}
		.chat-meta span {
			margin-right: 0.75rem;
		}
		.chat-status {
			font-size: 0.8rem;
			color: var(--text-muted, #666);
		}
		a.chat-status {
			color: var(--link-color, #0073aa);
			padding: 0.4rem 0.75rem;
			border-radius: 4px;
			background: var(--secondary-bg, #f0f0f0);
		}
		a.chat-status:hover {
			background: var(--hover-bg, #e5e5e5);
		}
		.import-progress {
			margin-bottom: 1rem;
			padding: 1rem;
			background: var(--card-bg, #fff);
			border: 1px solid var(--border-color, #ddd);
			border-radius: 6px;
		}
		.progress-bar {
			height: 8px;
			background: var(--secondary-bg, #e0e0e0);
			border-radius: 4px;
			overflow: hidden;
			margin-bottom: 0.5rem;
		}
		.progress-fill {
			height: 100%;
			background: var(--success-color, #46b450);
			transition: width 0.2s;
		}
		.progress-text {
			font-size: 0.9rem;
			color: var(--text-muted, #666);
		}
		.hidden {
			display: none !important;
		}
		.empty-state {
			padding: 2rem;
			text-align: center;
			color: var(--text-muted, #666);
		}
		.stats {
			margin-bottom: 1rem;
			font-size: 0.9rem;
			color: var(--text-muted, #666);
		}
		.hide-linked-toggle {
			display: flex;
			align-items: center;
			gap: 0.4rem;
			font-size: 0.9rem;
			color: var(--text-muted, #666);
			cursor: pointer;
		}
		.hide-linked-toggle input {
			cursor: pointer;
		}
		.assign-groups-bar {
			display: flex;
			align-items: center;
			justify-content: space-between;
			padding: 1rem;
			margin-bottom: 1rem;
			background: var(--success-bg, #d4edda);
			border: 1px solid var(--success-color, #46b450);
			border-radius: 6px;
		}
		.assign-groups-bar span {
			color: var(--text-color, #333);
			font-weight: 500;
		}
		.btn-assign-groups {
			padding: 0.5rem 1rem;
			background: var(--success-color, #46b450);
			color: #fff;
			text-decoration: none;
			border-radius: 4px;
			font-weight: 500;
		}
		.btn-assign-groups:hover {
			opacity: 0.9;
		}
	</style>
</head>
<body class="wp-app-body">
	<?php if ( function_exists( 'wp_app_body_open' ) ) wp_app_body_open(); ?>
	<?php $crm->render_cmd_k_panel(); ?>

	<div class="container">
		<div class="header">
			<div class="header-content">
				<h1>Import from Beeper</h1>
				<div class="back-nav">
					<a href="<?php echo $crm->build_url( 'outreach' ); ?>">← Back to Outreach</a>
				</div>
			</div>
		</div>

		<div class="stats" id="stats"></div>

		<div class="import-toolbar">
			<input type="text" class="filter-input" id="filterInput" placeholder="Filter by name..." autofocus>
			<label class="hide-linked-toggle">
				<input type="checkbox" id="hideLinkedCheckbox" checked onchange="toggleHideLinked()">
				Hide linked
			</label>
			<div class="select-actions">
				<button type="button" onclick="selectAllVisible()">Select visible</button>
				<button type="button" onclick="selectNone()">Select none</button>
			</div>
			<span class="selection-info" id="selectionInfo">0 selected</span>
			<button type="button" class="btn-import-selected" id="importBtn" onclick="importSelected()" disabled>Import Selected</button>
		</div>

		<div class="assign-groups-bar hidden" id="assignGroupsBar">
			<span id="assignGroupsText">0 people imported</span>
			<a href="#" class="btn-assign-groups" id="assignGroupsBtn">Assign groups →</a>
		</div>

		<div class="import-progress hidden" id="importProgress">
			<div class="progress-bar">
				<div class="progress-fill" id="progressFill" style="width: 0%"></div>
			</div>
			<div class="progress-text" id="progressText">Importing...</div>
		</div>

		<div class="chat-list" id="chatList"></div>
	</div>

	<script>
	var kcConfig = {
		ajaxUrl: <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
		nonce: <?php echo json_encode( wp_create_nonce( 'kc_beeper' ) ); ?>,
		chats: [],
		linkedChats: <?php echo json_encode( $chat_to_username ); ?>,
		personUrlBase: <?php echo json_encode( home_url( '/crm/person/' ) ); ?>,
		assignGroupsUrl: <?php echo json_encode( home_url( '/crm/assign-groups' ) ); ?>,
		beeperToken: <?php echo json_encode( $beeper->get_token() ); ?>
	};

	var selectedIds = new Set();
	var importedUsernames = [];
	var hideLinked = true;

	async function init() {
		document.getElementById('filterInput').addEventListener('input', function() {
			applyFilters();
		});

		// Load chats directly from Beeper API
		await loadAllChatsFromBeeper();
	}

	async function loadAllChatsFromBeeper() {
		var loadingEl = document.getElementById('chatList');
		loadingEl.innerHTML = '<div class="empty-state">Loading chats from Beeper...</div>';

		var allChats = [];
		var cursor = null;
		var pageCount = 0;

		try {
			while (true) {
				pageCount++;
				var url = 'http://localhost:23373/v1/chats';
				if (cursor) {
					url += '?cursor=' + encodeURIComponent(cursor) + '&direction=before';
				}

				var response = await fetch(url, {
					headers: { 'Authorization': 'Bearer ' + kcConfig.beeperToken }
				});
				var data = await response.json();

				var items = data.items || [];
				allChats = allChats.concat(items);

				console.log('Page ' + pageCount + ': ' + items.length + ' chats, total: ' + allChats.length + ', hasMore: ' + data.hasMore);

				if (!data.hasMore || !data.oldestCursor) {
					break;
				}
				cursor = data.oldestCursor;
			}
		} catch (err) {
			console.error('Error loading chats:', err);
			loadingEl.innerHTML = '<div class="empty-state">Error loading chats from Beeper</div>';
			return;
		}

		// Filter to single chats and group by phone/identifier
		var chatsByIdentifier = {};

		allChats.forEach(function(chat) {
			if (chat.type !== 'single') return;

			var name = chat.title || 'Unknown';
			var identifier = null;

			if (chat.participants && chat.participants.items) {
				for (var i = 0; i < chat.participants.items.length; i++) {
					var p = chat.participants.items[i];
					if (!p.isSelf) {
						if (p.fullName) name = p.fullName;
						identifier = p.identifier || p.phoneNumber || null;
						break;
					}
				}
			}

			// Use identifier as key, or fall back to chat.id if no identifier
			var key = identifier || chat.id;
			var linkedUsername = kcConfig.linkedChats[chat.id] || null;

			if (!chatsByIdentifier[key]) {
				chatsByIdentifier[key] = {
					ids: [chat.id],
					name: name,
					networks: [chat.network || 'Unknown'],
					lastActivity: chat.lastActivity || '',
					isLinked: linkedUsername !== null,
					linkedUsername: linkedUsername,
					identifier: identifier
				};
			} else {
				// Merge: add this chat's ID and network
				chatsByIdentifier[key].ids.push(chat.id);
				var network = chat.network || 'Unknown';
				if (chatsByIdentifier[key].networks.indexOf(network) === -1) {
					chatsByIdentifier[key].networks.push(network);
				}
				// Use most recent activity
				if (chat.lastActivity > chatsByIdentifier[key].lastActivity) {
					chatsByIdentifier[key].lastActivity = chat.lastActivity;
				}
				// If any chat is linked, mark as linked (check this chat ID too)
				if (linkedUsername && !chatsByIdentifier[key].isLinked) {
					chatsByIdentifier[key].isLinked = true;
					chatsByIdentifier[key].linkedUsername = linkedUsername;
				}
				// Prefer a real name over title
				if (name && name !== chat.title) {
					chatsByIdentifier[key].name = name;
				}
			}
		});

		// Convert to array
		kcConfig.chats = Object.values(chatsByIdentifier);

		// Sort by last activity
		kcConfig.chats.sort(function(a, b) {
			return (b.lastActivity || '').localeCompare(a.lastActivity || '');
		});

		console.log('Total single chats after dedup:', kcConfig.chats.length);

		renderChats();
		updateStats();
		applyFilters();
	}

	function renderChats() {
		var listEl = document.getElementById('chatList');
		while (listEl.firstChild) listEl.removeChild(listEl.firstChild);

		if (kcConfig.chats.length === 0) {
			var empty = document.createElement('div');
			empty.className = 'empty-state';
			empty.textContent = 'No chats found in Beeper';
			listEl.appendChild(empty);
			return;
		}

		kcConfig.chats.forEach(function(chat, index) {
			var chatKey = 'chat-' + index;
			var div = document.createElement('div');
			div.className = 'chat-item' + (chat.isLinked ? ' is-linked' : '');
			div.dataset.chatKey = chatKey;
			div.dataset.chatIds = JSON.stringify(chat.ids);
			div.dataset.name = chat.name.toLowerCase();

			var checkbox = document.createElement('input');
			checkbox.type = 'checkbox';
			checkbox.disabled = chat.isLinked;
			checkbox.checked = selectedIds.has(chatKey);
			checkbox.addEventListener('change', function() {
				toggleSelection(chatKey, this.checked);
			});
			div.appendChild(checkbox);

			var infoDiv = document.createElement('div');
			infoDiv.className = 'chat-info';

			var nameDiv = document.createElement('div');
			nameDiv.className = 'chat-name';
			if (chat.isLinked) {
				var nameLink = document.createElement('a');
				nameLink.href = kcConfig.personUrlBase + chat.linkedUsername;
				nameLink.textContent = chat.name;
				nameDiv.appendChild(nameLink);
			} else {
				nameDiv.textContent = chat.name;
			}
			infoDiv.appendChild(nameDiv);

			var metaDiv = document.createElement('div');
			metaDiv.className = 'chat-meta';
			if (chat.networks && chat.networks.length > 0) {
				var networkSpan = document.createElement('span');
				networkSpan.textContent = chat.networks.join(', ');
				metaDiv.appendChild(networkSpan);
			}
			if (chat.lastActivity) {
				var dateSpan = document.createElement('span');
				dateSpan.textContent = formatDate(chat.lastActivity);
				metaDiv.appendChild(dateSpan);
			}
			infoDiv.appendChild(metaDiv);

			div.appendChild(infoDiv);

			if (chat.isLinked) {
				var statusLink = document.createElement('a');
				statusLink.className = 'chat-status';
				statusLink.href = kcConfig.personUrlBase + chat.linkedUsername;
				statusLink.textContent = 'View →';
				statusLink.style.textDecoration = 'none';
				div.appendChild(statusLink);
			}

			if (!chat.isLinked) {
				div.addEventListener('click', function(e) {
					if (e.target.type !== 'checkbox') {
						checkbox.checked = !checkbox.checked;
						toggleSelection(chatKey, checkbox.checked);
					}
				});
			}

			listEl.appendChild(div);
		});
	}

	function formatDate(isoDate) {
		if (!isoDate) return '';
		var d = new Date(isoDate);
		var now = new Date();
		var diffDays = Math.floor((now - d) / (1000 * 60 * 60 * 24));
		if (diffDays === 0) return 'Today';
		if (diffDays === 1) return 'Yesterday';
		if (diffDays < 7) return diffDays + ' days ago';
		if (diffDays < 30) return Math.floor(diffDays / 7) + 'w ago';
		if (diffDays < 365) return Math.floor(diffDays / 30) + 'mo ago';
		return Math.floor(diffDays / 365) + 'y ago';
	}

	function toggleSelection(chatId, isSelected) {
		if (isSelected) {
			selectedIds.add(chatId);
		} else {
			selectedIds.delete(chatId);
		}
		updateSelectionUI();
	}

	function updateSelectionUI() {
		document.getElementById('selectionInfo').textContent = selectedIds.size + ' selected';
		document.getElementById('importBtn').disabled = selectedIds.size === 0;

		document.querySelectorAll('.chat-item').forEach(function(el) {
			var chatKey = el.dataset.chatKey;
			if (selectedIds.has(chatKey)) {
				el.classList.add('is-selected');
			} else {
				el.classList.remove('is-selected');
			}
		});
	}

	function updateStats() {
		var total = kcConfig.chats.length;
		var linked = kcConfig.chats.filter(function(c) { return c.isLinked; }).length;
		var available = total - linked;
		document.getElementById('stats').textContent = total + ' people total, ' + linked + ' already linked, ' + available + ' available to import';
	}

	function applyFilters() {
		var query = document.getElementById('filterInput').value.toLowerCase().trim();
		document.querySelectorAll('.chat-item').forEach(function(el) {
			var name = el.dataset.name;
			var isLinked = el.classList.contains('is-linked');
			var matchesQuery = !query || name.indexOf(query) !== -1;
			var matchesLinked = !hideLinked || !isLinked;

			if (matchesQuery && matchesLinked) {
				el.classList.remove('hidden');
			} else {
				el.classList.add('hidden');
			}
		});
	}

	function toggleHideLinked() {
		hideLinked = document.getElementById('hideLinkedCheckbox').checked;
		applyFilters();
	}

	function selectAllVisible() {
		document.querySelectorAll('.chat-item:not(.hidden):not(.is-linked)').forEach(function(el) {
			var chatKey = el.dataset.chatKey;
			selectedIds.add(chatKey);
			el.querySelector('input[type="checkbox"]').checked = true;
		});
		updateSelectionUI();
	}

	function selectNone() {
		selectedIds.clear();
		document.querySelectorAll('.chat-item input[type="checkbox"]').forEach(function(cb) {
			cb.checked = false;
		});
		updateSelectionUI();
	}

	async function importSelected() {
		if (selectedIds.size === 0) return;

		var keys = Array.from(selectedIds);
		var btn = document.getElementById('importBtn');
		var progressDiv = document.getElementById('importProgress');
		var progressFill = document.getElementById('progressFill');
		var progressText = document.getElementById('progressText');

		btn.disabled = true;
		progressDiv.classList.remove('hidden');

		var imported = 0;
		var failed = 0;

		for (var i = 0; i < keys.length; i++) {
			var chatKey = keys[i];
			var el = document.querySelector('.chat-item[data-chat-key="' + chatKey + '"]');
			if (!el) {
				failed++;
				continue;
			}

			var chatIds = JSON.parse(el.dataset.chatIds);
			var chatIndex = parseInt(chatKey.replace('chat-', ''), 10);
			var chat = kcConfig.chats[chatIndex];
			var progress = Math.round(((i + 1) / keys.length) * 100);
			progressFill.style.width = progress + '%';
			progressText.textContent = 'Importing ' + (i + 1) + ' of ' + keys.length + '...';

			try {
				var body = 'action=kc_beeper_import' +
					'&chat_ids=' + encodeURIComponent(JSON.stringify(chatIds)) +
					'&name=' + encodeURIComponent(chat.name || '') +
					'&phone=' + encodeURIComponent(chat.identifier || '') +
					'&_wpnonce=' + kcConfig.nonce;

				var response = await fetch(kcConfig.ajaxUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body
				});

				var data = await response.json();

				if (data.success) {
					imported++;
					importedUsernames.push(data.data.username);
					markAsImported(chatKey, data.data);
					updateAssignGroupsBar();
				} else {
					failed++;
				}
			} catch (err) {
				failed++;
			}

			await new Promise(function(r) { setTimeout(r, 100); });
		}

		selectedIds.clear();
		updateSelectionUI();
		updateStats();
		applyFilters();

		if (imported > 0) {
			progressText.textContent = 'Done! Imported ' + imported + ' contact' + (imported !== 1 ? 's' : '') + '.';
		} else {
			progressText.textContent = 'No contacts imported' + (failed > 0 ? ', ' + failed + ' failed' : '');
		}

		btn.disabled = false;
		setTimeout(function() {
			progressDiv.classList.add('hidden');
		}, 2000);
	}

	function updateAssignGroupsBar() {
		var bar = document.getElementById('assignGroupsBar');
		var text = document.getElementById('assignGroupsText');
		var btn = document.getElementById('assignGroupsBtn');

		if (importedUsernames.length === 0) {
			bar.classList.add('hidden');
			return;
		}

		bar.classList.remove('hidden');
		text.textContent = importedUsernames.length + ' ' + (importedUsernames.length === 1 ? 'person' : 'people') + ' imported';
		btn.href = kcConfig.assignGroupsUrl + '?' + importedUsernames.map(function(u) { return 'person[]=' + encodeURIComponent(u); }).join('&');
	}

	function markAsImported(chatKey, data) {
		var el = document.querySelector('.chat-item[data-chat-key="' + chatKey + '"]');
		if (el) {
			el.classList.remove('is-selected');
			el.classList.add('is-linked', 'is-imported');
			el.querySelector('input[type="checkbox"]').disabled = true;
			el.querySelector('input[type="checkbox"]').checked = false;

			var nameDiv = el.querySelector('.chat-name');
			while (nameDiv.firstChild) nameDiv.removeChild(nameDiv.firstChild);
			var link = document.createElement('a');
			link.href = data.url;
			link.textContent = data.name;
			nameDiv.appendChild(link);

			var oldStatus = el.querySelector('.chat-status');
			if (oldStatus) oldStatus.remove();

			var statusSpan = document.createElement('span');
			statusSpan.className = 'chat-status';
			statusSpan.textContent = 'Imported';
			el.appendChild(statusSpan);
		}
	}

	document.addEventListener('DOMContentLoaded', init);
	</script>

	<?php if ( function_exists( 'wp_app_body_close' ) ) wp_app_body_close(); ?>
	<?php $crm->init_cmd_k_js(); ?>
	<?php if ( function_exists( 'wp_app_footer' ) ) wp_app_footer(); ?>
</body>
</html>
