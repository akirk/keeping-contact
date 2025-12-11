/**
 * Beeper integration functions for Keeping Contact
 */

(function() {
	'use strict';

	// Config is set by inline PHP
	var config = window.kcBeeperConfig || {};

	window.kcBeeperSearch = function(username, name) {
		document.getElementById('kcBeeperUsername').value = username;
		document.getElementById('kcBeeperModal').style.display = 'flex';
		document.getElementById('kcBeeperSearching').style.display = 'block';
		var resultsDiv = document.getElementById('kcBeeperResults');
		while (resultsDiv.firstChild) resultsDiv.removeChild(resultsDiv.firstChild);

		fetch(config.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'action=kc_beeper_search&name=' + encodeURIComponent(name) + '&username=' + encodeURIComponent(username) + '&_wpnonce=' + config.nonce
		})
		.then(function(r) { return r.json(); })
		.then(function(data) {
			document.getElementById('kcBeeperSearching').style.display = 'none';
			kcBeeperRenderResults(data, name);
		})
		.catch(function(err) {
			document.getElementById('kcBeeperSearching').style.display = 'none';
			kcBeeperShowError(err.message);
		});
	};

	function kcBeeperRenderResults(data, name) {
		var resultsDiv = document.getElementById('kcBeeperResults');
		while (resultsDiv.firstChild) resultsDiv.removeChild(resultsDiv.firstChild);

		// Show what queries were tried
		if (data.data && data.data.queries && data.data.queries.length > 0) {
			var queriesDiv = document.createElement('div');
			queriesDiv.className = 'kc-search-queries';
			queriesDiv.textContent = 'Searched for: ' + data.data.queries.join(', ');
			resultsDiv.appendChild(queriesDiv);
		}

		if (data.success && data.data.chats && data.data.chats.length > 0) {
			// Auto-connect if there's exactly one result
			if (data.data.chats.length === 1) {
				var chat = data.data.chats[0];
				var chatName = chat.title || 'Unknown';
				if (chat.participants && chat.participants.items) {
					var other = chat.participants.items.find(function(p) { return !p.isSelf; });
					if (other && other.fullName) {
						chatName = other.fullName;
					}
				}

				var autoConnectDiv = document.createElement('div');
				autoConnectDiv.className = 'kc-auto-connect';
				autoConnectDiv.textContent = 'Found 1 match: ' + chatName + '. Connecting...';
				resultsDiv.appendChild(autoConnectDiv);

				// Auto-link after a short delay so user sees the message
				setTimeout(function() {
					kcBeeperLink(chat.id);
				}, 500);
				return;
			}

			var hint = document.createElement('div');
			hint.className = 'kc-results-hint';
			hint.textContent = 'Select a chat to link:';
			resultsDiv.appendChild(hint);

			data.data.chats.forEach(function(chat) {
				var item = document.createElement('div');
				item.className = 'kc-chat-result';
				item.onclick = function() { kcBeeperLink(chat.id); };

				// Get participant name (not self) for single chats
				var chatName = chat.title || 'Unknown';
				if (chat.participants && chat.participants.items) {
					var other = chat.participants.items.find(function(p) { return !p.isSelf; });
					if (other && other.fullName) {
						chatName = other.fullName;
					}
				}

				var info = document.createElement('div');
				var title = document.createElement('strong');
				title.textContent = chatName;
				info.appendChild(title);
				info.appendChild(document.createElement('br'));
				var network = document.createElement('span');
				network.className = 'kc-chat-network';
				network.textContent = chat.network || '';
				info.appendChild(network);
				item.appendChild(info);

				if (chat.lastActivity) {
					var date = document.createElement('span');
					date.className = 'kc-chat-date';
					date.textContent = 'Last msg: ' + new Date(chat.lastActivity).toLocaleDateString();
					item.appendChild(date);
				}
				resultsDiv.appendChild(item);
			});
		} else {
			var empty = document.createElement('div');
			empty.className = 'kc-no-results';
			empty.textContent = 'No chats found for "' + name + '"';
			resultsDiv.appendChild(empty);
		}
	}

	function kcBeeperShowError(msg) {
		var resultsDiv = document.getElementById('kcBeeperResults');
		while (resultsDiv.firstChild) resultsDiv.removeChild(resultsDiv.firstChild);
		var err = document.createElement('div');
		err.className = 'kc-error';
		err.textContent = 'Error: ' + msg;
		resultsDiv.appendChild(err);
	}

	window.kcBeeperLink = function(chatId) {
		var username = document.getElementById('kcBeeperUsername').value;
		fetch(config.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'action=kc_beeper_link&username=' + encodeURIComponent(username) + '&chat_id=' + encodeURIComponent(chatId) + '&_wpnonce=' + config.nonce
		})
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (data.success) {
				location.reload();
			} else {
				alert('Error: ' + (data.data || 'Failed to link'));
			}
		});
	};

	window.kcBeeperSync = function(username, chatId) {
		fetch(config.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'action=kc_beeper_sync&username=' + encodeURIComponent(username) + '&chat_id=' + encodeURIComponent(chatId) + '&_wpnonce=' + config.nonce
		})
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (data.success) {
				location.reload();
			} else {
				alert('Error: ' + (data.data || 'Failed to sync'));
			}
		});
	};

	window.kcBeeperUnlink = function(username, chatId) {
		if (!confirm('Remove this chat link?')) return;
		fetch(config.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'action=kc_beeper_unlink&username=' + encodeURIComponent(username) + '&chat_id=' + encodeURIComponent(chatId) + '&_wpnonce=' + config.nonce
		})
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (data.success) {
				location.reload();
			} else {
				alert('Error: ' + (data.data || 'Failed to unlink'));
			}
		});
	};

	window.kcBeeperModalClose = function() {
		document.getElementById('kcBeeperModal').style.display = 'none';
	};

	// Close modal when clicking outside
	document.addEventListener('DOMContentLoaded', function() {
		var modal = document.getElementById('kcBeeperModal');
		if (modal) {
			modal.addEventListener('click', function(e) {
				if (e.target === this) kcBeeperModalClose();
			});
		}
	});
})();
