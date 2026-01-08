/**
 * Beeper integration functions for Keeping Contact
 */

(function() {
	'use strict';

	var config = window.kcBeeperConfig || {};
	var beeper = null;

	function getBeeperClient() {
		if (!beeper && config.beeperToken) {
			beeper = new BeeperClient(config.beeperToken, config.beeperApiBase);
		}
		return beeper;
	}

	window.kcBeeperSearch = async function(username, name) {
		document.getElementById('kcBeeperUsername').value = username;
		document.getElementById('kcBeeperModal').style.display = 'flex';
		document.getElementById('kcBeeperSearching').style.display = 'block';
		var resultsDiv = document.getElementById('kcBeeperResults');
		while (resultsDiv.firstChild) resultsDiv.removeChild(resultsDiv.firstChild);

		var client = getBeeperClient();
		if (!client) {
			document.getElementById('kcBeeperSearching').style.display = 'none';
			kcBeeperShowError('Beeper not configured');
			return;
		}

		var result = await client.searchChats(name, 10);

		document.getElementById('kcBeeperSearching').style.display = 'none';

		if (result.success) {
			kcBeeperRenderResults({ success: true, data: { chats: result.data.items, queries: [name] } }, name);
		} else {
			kcBeeperShowError(result.error || 'Search failed');
		}
	};

	function kcBeeperRenderResults(data, name) {
		var resultsDiv = document.getElementById('kcBeeperResults');
		while (resultsDiv.firstChild) resultsDiv.removeChild(resultsDiv.firstChild);

		if (data.data && data.data.queries && data.data.queries.length > 0) {
			var queriesDiv = document.createElement('div');
			queriesDiv.className = 'kc-search-queries';
			queriesDiv.textContent = 'Searched for: ' + data.data.queries.join(', ');
			resultsDiv.appendChild(queriesDiv);
		}

		if (data.success && data.data.chats && data.data.chats.length > 0) {
			if (data.data.chats.length === 1) {
				var chat = data.data.chats[0];
				var chatName = getChatName(chat);

				var autoConnectDiv = document.createElement('div');
				autoConnectDiv.className = 'kc-auto-connect';
				autoConnectDiv.textContent = 'Found 1 match: ' + chatName + '. Connecting...';
				resultsDiv.appendChild(autoConnectDiv);

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

				var chatName = getChatName(chat);

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

	function getChatName(chat) {
		var chatName = chat.title || 'Unknown';
		if (chat.participants && chat.participants.items) {
			var other = chat.participants.items.find(function(p) { return !p.isSelf; });
			if (other && other.fullName) {
				chatName = other.fullName;
			}
		}
		return chatName;
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
				kcBeeperSyncAfterLink(username, chatId);
			} else {
				alert('Error: ' + (data.data || 'Failed to link'));
			}
		});
	};

	async function kcBeeperSyncAfterLink(username, chatId) {
		var client = getBeeperClient();
		if (client) {
			var lastDate = await client.getLastContactDate(chatId);
			if (lastDate) {
				await fetch(config.ajaxUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: 'action=kc_beeper_update_last_contact&username=' + encodeURIComponent(username) + '&last_date=' + encodeURIComponent(lastDate) + '&_wpnonce=' + config.nonce
				});
			}
		}
		location.reload();
	}

	window.kcBeeperSync = async function(username, chatId) {
		var client = getBeeperClient();
		if (!client) {
			alert('Beeper not configured');
			return;
		}

		var lastDate = await client.getLastContactDate(chatId);
		if (!lastDate) {
			alert('No messages found');
			return;
		}

		fetch(config.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'action=kc_beeper_update_last_contact&username=' + encodeURIComponent(username) + '&last_date=' + encodeURIComponent(lastDate) + '&_wpnonce=' + config.nonce
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

	window.kcBeeperAutoSync = async function(username, chatIds) {
		if (!chatIds || chatIds.length === 0) return;

		var client = getBeeperClient();
		if (!client) return;

		var latestDate = null;

		for (var i = 0; i < chatIds.length; i++) {
			var chatId = chatIds[i];
			var lastDate = await client.getLastContactDate(chatId);
			if (lastDate && (!latestDate || lastDate > latestDate)) {
				latestDate = lastDate;
			}

			var chatEl = document.querySelector('.kc-beeper-chat[data-chat-id="' + CSS.escape(chatId) + '"]');
			if (chatEl) {
				var chatInfo = await client.getChat(chatId);
				if (chatInfo.success) {
					var titleEl = chatEl.querySelector('.kc-chat-title');
					if (titleEl) {
						var name = getChatName(chatInfo.data);
						var network = chatInfo.data.network || 'Connected';
						titleEl.textContent = name;
						var networkSpan = document.createElement('span');
						networkSpan.className = 'kc-beeper-chat-network';
						networkSpan.textContent = ' (' + network + ')';
						titleEl.parentNode.appendChild(networkSpan);
					}
				}
			}
		}

		if (latestDate) {
			await fetch(config.ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=kc_beeper_update_last_contact&username=' + encodeURIComponent(username) + '&last_date=' + encodeURIComponent(latestDate) + '&_wpnonce=' + config.nonce
			});

			fetch(config.ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=kc_render_sidebar&username=' + encodeURIComponent(username) + '&_wpnonce=' + config.nonce
			}).then(function(r) {
				return r.json();
			}).then(function(data) {
				if (data.success && data.data.html) {
					var existing = document.querySelector('.kc-sidebar-section');
					if (existing) {
						var parser = new DOMParser();
						var doc = parser.parseFromString(data.data.html, 'text/html');
						var newSection = doc.querySelector('.kc-sidebar-section');
						if (newSection) {
							existing.replaceWith(newSection);
						}
					}
				}
			});
		}
	};

	document.addEventListener('DOMContentLoaded', function() {
		var modal = document.getElementById('kcBeeperModal');
		if (modal) {
			modal.addEventListener('click', function(e) {
				if (e.target === this) kcBeeperModalClose();
			});
		}
	});
})();
