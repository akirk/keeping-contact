/**
 * Beeper integration functions for Keeping Contact
 */

(function() {
	'use strict';

	var config = window.kcBeeperConfig || {};
	var beeper = null;
	var searchDebounceTimer = null;

	function getBeeperClient() {
		if (!beeper && config.beeperToken) {
			beeper = new BeeperClient(config.beeperToken, config.beeperApiBase);
		}
		return beeper;
	}

	window.kcBeeperSearch = async function(username, name) {
		document.getElementById('kcBeeperUsername').value = username;
		document.getElementById('kcBeeperModal').style.display = 'flex';

		var resultsDiv = document.getElementById('kcBeeperResults');
		while (resultsDiv.firstChild) resultsDiv.removeChild(resultsDiv.firstChild);

		var searchInput = document.createElement('input');
		searchInput.type = 'text';
		searchInput.id = 'kcBeeperSearchInput';
		searchInput.className = 'kc-search-input';
		searchInput.placeholder = 'Search by name or phone...';
		searchInput.value = name || '';

		searchInput.addEventListener('input', function() {
			var query = searchInput.value.trim();
			clearTimeout(searchDebounceTimer);
			if (query) {
				searchDebounceTimer = setTimeout(function() {
					kcBeeperPerformSearch(query);
				}, 300);
			}
		});

		resultsDiv.appendChild(searchInput);

		var resultsContainer = document.createElement('div');
		resultsContainer.id = 'kcBeeperResultsList';
		resultsDiv.appendChild(resultsContainer);

		searchInput.focus();

		kcBeeperPerformSearch(name);
	};

	async function kcBeeperPerformSearch(query) {
		var resultsContainer = document.getElementById('kcBeeperResultsList');
		if (!resultsContainer) return;

		while (resultsContainer.firstChild) resultsContainer.removeChild(resultsContainer.firstChild);

		var searchingDiv = document.createElement('div');
		searchingDiv.className = 'kc-searching';
		searchingDiv.style.display = 'block';
		searchingDiv.textContent = 'Searching...';
		resultsContainer.appendChild(searchingDiv);

		var client = getBeeperClient();
		if (!client) {
			kcBeeperShowError('Beeper not configured');
			return;
		}

		var result = await client.searchChats(query, 10);

		if (result.success) {
			kcBeeperRenderResults(result.data.items || []);
		} else {
			kcBeeperShowError(result.error || 'Search failed');
		}
	}

	function kcBeeperRenderResults(chats) {
		var resultsContainer = document.getElementById('kcBeeperResultsList');
		if (!resultsContainer) return;

		while (resultsContainer.firstChild) resultsContainer.removeChild(resultsContainer.firstChild);

		if (chats && chats.length > 0) {
			if (chats.length === 1) {
				var chat = chats[0];
				var chatName = getChatName(chat);
				var chatPhone = getChatPhone(chat);

				var autoConnectDiv = document.createElement('div');
				autoConnectDiv.className = 'kc-auto-connect';
				autoConnectDiv.textContent = 'Found 1 match: ' + chatName + '. Connecting...';
				resultsContainer.appendChild(autoConnectDiv);

				setTimeout(function() {
					kcBeeperLink(chat.id, chatPhone);
				}, 500);
				return;
			}

			chats.forEach(function(chat) {
				var chatPhone = getChatPhone(chat);
				var item = document.createElement('div');
				item.className = 'kc-chat-result';
				item.onclick = function() { kcBeeperLink(chat.id, chatPhone); };

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
				resultsContainer.appendChild(item);
			});
		} else {
			var empty = document.createElement('div');
			empty.className = 'kc-no-results';
			empty.textContent = 'No chats found';
			resultsContainer.appendChild(empty);
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

	function getChatPhone(chat) {
		if (chat.participants && chat.participants.items) {
			var other = chat.participants.items.find(function(p) { return !p.isSelf; });
			if (other) {
				return other.identifier || other.phoneNumber || '';
			}
		}
		return '';
	}

	function kcBeeperShowError(msg) {
		var resultsContainer = document.getElementById('kcBeeperResultsList');
		if (!resultsContainer) return;
		while (resultsContainer.firstChild) resultsContainer.removeChild(resultsContainer.firstChild);
		var err = document.createElement('div');
		err.className = 'kc-error';
		err.textContent = 'Error: ' + msg;
		resultsContainer.appendChild(err);
	}

	window.kcBeeperLink = function(chatId, phone) {
		var username = document.getElementById('kcBeeperUsername').value;
		var body = 'action=kc_beeper_link&username=' + encodeURIComponent(username) +
			'&chat_id=' + encodeURIComponent(chatId) +
			'&_wpnonce=' + config.nonce;

		if (phone) {
			body += '&phone=' + encodeURIComponent(phone);
		}

		fetch(config.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body
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
		var chatNetworks = {};

		for (var i = 0; i < chatIds.length; i++) {
			var chatId = chatIds[i];
			var lastDate = await client.getLastContactDate(chatId);
			if (lastDate && (!latestDate || lastDate > latestDate)) {
				latestDate = lastDate;
			}

			var chatInfo = await client.getChat(chatId);
			if (chatInfo.success) {
				chatNetworks[chatId] = chatInfo.data.network || 'Beeper';
			}
		}

		function updateChatTitles() {
			for (var chatId in chatNetworks) {
				var chatEl = document.querySelector('.kc-beeper-chat[data-chat-id="' + CSS.escape(chatId) + '"]');
				if (chatEl) {
					var titleEl = chatEl.querySelector('.kc-chat-title');
					if (titleEl) {
						titleEl.textContent = chatNetworks[chatId];
					}
				}
			}
		}

		updateChatTitles();

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
							updateChatTitles();
						}
					}
				}
			});
		}
	};

	document.addEventListener('DOMContentLoaded', function() {
		var modal = document.getElementById('kcBeeperModal');
		if (modal) {
			document.body.appendChild(modal);
			modal.addEventListener('click', function(e) {
				if (e.target === this) kcBeeperModalClose();
			});
		}
	});
})();
