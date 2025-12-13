/**
 * Relationship Analysis JavaScript
 */

(function() {
	'use strict';

	var config = window.kcAnalysisConfig || {};
	var allMessages = [];
	var lastCursor = null;
	var hasMoreMessages = false;
	var isLoading = false;

	function getDateLimit() {
		var limit = new Date();
		limit.setFullYear(limit.getFullYear() - 1);
		return limit;
	}

	async function fetchMessages(dateLimit, updateProgress) {
		var cursor = lastCursor;
		var hasMore = true;
		var loadedCount = allMessages.length;

		while (hasMore) {
			var body = 'action=kc_beeper_messages&chat_id=' + encodeURIComponent(config.chatId) + '&_wpnonce=' + config.nonce;
			if (cursor) {
				body += '&cursor=' + encodeURIComponent(cursor);
			}

			var response = await fetch(config.ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body
			});

			var data = await response.json();

			if (!data.success || !data.data.messages) {
				break;
			}

			var messages = data.data.messages;
			var reachedLimit = false;

			for (var i = 0; i < messages.length; i++) {
				var msg = messages[i];
				var msgDate = new Date(msg.date);

				if (dateLimit && msgDate < dateLimit) {
					reachedLimit = true;
					continue;
				}

				allMessages.push(msg);
				loadedCount++;
			}

			if (updateProgress) {
				updateProgress(loadedCount);
			}

			hasMore = !reachedLimit && data.data.has_more;
			cursor = data.data.next_cursor;
			lastCursor = cursor;
			hasMoreMessages = data.data.has_more && !reachedLimit;

			if (!cursor) {
				hasMoreMessages = false;
				break;
			}
		}

		return allMessages;
	}

	function analyzeMessages(messages) {
		if (messages.length === 0) {
			return null;
		}

		var youCount = 0;
		var themCount = 0;
		var youInitiations = 0;
		var themInitiations = 0;
		var youResponseTimes = [];
		var themResponseTimes = [];
		var gaps = [];
		var sessions = [];
		var currentSession = [];
		var lastTime = null;
		var lastSender = null;
		var gapThreshold = 4 * 60 * 60 * 1000; // 4 hours

		messages.sort(function(a, b) {
			return new Date(a.date) - new Date(b.date);
		});

		for (var i = 0; i < messages.length; i++) {
			var msg = messages[i];
			var msgTime = new Date(msg.date).getTime();
			var isSender = msg.is_sender;

			if (isSender) {
				youCount++;
			} else {
				themCount++;
			}

			if (lastTime !== null) {
				var timeDiff = msgTime - lastTime;

				if (timeDiff > gapThreshold) {
					gaps.push(timeDiff);

					if (currentSession.length > 0) {
						sessions.push(currentSession);
					}
					currentSession = [];

					if (isSender) {
						youInitiations++;
					} else {
						themInitiations++;
					}
				} else if (lastSender !== null && lastSender !== isSender) {
					if (isSender) {
						youResponseTimes.push(timeDiff);
					} else {
						themResponseTimes.push(timeDiff);
					}
				}
			} else {
				if (isSender) {
					youInitiations++;
				} else {
					themInitiations++;
				}
			}

			currentSession.push(msg);
			lastTime = msgTime;
			lastSender = isSender;
		}

		if (currentSession.length > 0) {
			sessions.push(currentSession);
		}

		var avgYouResponse = youResponseTimes.length > 0
			? youResponseTimes.reduce(function(a, b) { return a + b; }, 0) / youResponseTimes.length
			: 0;
		var avgThemResponse = themResponseTimes.length > 0
			? themResponseTimes.reduce(function(a, b) { return a + b; }, 0) / themResponseTimes.length
			: 0;

		var longestGap = gaps.length > 0 ? Math.max.apply(null, gaps) : 0;
		var avgGap = gaps.length > 0
			? gaps.reduce(function(a, b) { return a + b; }, 0) / gaps.length
			: 0;

		var avgSessionLength = sessions.length > 0
			? messages.length / sessions.length
			: messages.length;

		return {
			total: messages.length,
			youCount: youCount,
			themCount: themCount,
			youInitiations: youInitiations,
			themInitiations: themInitiations,
			avgYouResponse: avgYouResponse,
			avgThemResponse: avgThemResponse,
			longestGap: longestGap,
			avgGap: avgGap,
			sessions: sessions.length,
			avgSessionLength: avgSessionLength,
			messages: messages
		};
	}

	function formatDuration(ms) {
		var seconds = Math.floor(ms / 1000);
		var minutes = Math.floor(seconds / 60);
		var hours = Math.floor(minutes / 60);
		var days = Math.floor(hours / 24);

		if (days > 0) {
			return days + ' day' + (days > 1 ? 's' : '');
		}
		if (hours > 0) {
			return hours + ' hour' + (hours > 1 ? 's' : '');
		}
		if (minutes > 0) {
			return minutes + ' min';
		}
		return '< 1 min';
	}

	function renderStats(stats) {
		document.getElementById('statTotal').textContent = stats.total;
		document.getElementById('statTotalDetail').textContent =
			'You: ' + stats.youCount + ' / Them: ' + stats.themCount;

		var totalInitiations = stats.youInitiations + stats.themInitiations;
		if (totalInitiations > 0) {
			var youInitPct = Math.round((stats.youInitiations / totalInitiations) * 100);
			var themInitPct = 100 - youInitPct;

			if (youInitPct > themInitPct) {
				document.getElementById('statInitiator').textContent = 'You';
				document.getElementById('statInitiatorDetail').textContent =
					youInitPct + '% of the time';
			} else if (themInitPct > youInitPct) {
				document.getElementById('statInitiator').textContent = 'Them';
				document.getElementById('statInitiatorDetail').textContent =
					themInitPct + '% of the time';
			} else {
				document.getElementById('statInitiator').textContent = 'Equal';
				document.getElementById('statInitiatorDetail').textContent = '50/50 split';
			}
		}

		var avgResponse = (stats.avgYouResponse + stats.avgThemResponse) / 2;
		document.getElementById('statResponseTime').textContent = formatDuration(avgResponse);
		document.getElementById('statResponseTimeDetail').textContent =
			'You: ' + formatDuration(stats.avgYouResponse) + ' / Them: ' + formatDuration(stats.avgThemResponse);

		document.getElementById('statLongestGap').textContent = formatDuration(stats.longestGap);

		var total = stats.youCount + stats.themCount;
		var youPct = total > 0 ? Math.round((stats.youCount / total) * 100) : 50;
		var themPct = 100 - youPct;

		document.getElementById('balanceBarYou').style.width = youPct + '%';
		document.getElementById('balanceBarThem').style.width = themPct + '%';
		document.getElementById('balanceLabelYou').textContent = 'You: ' + youPct + '%';
		document.getElementById('balanceLabelThem').textContent = 'Them: ' + themPct + '%';

		document.getElementById('statSessions').textContent = stats.sessions;
		document.getElementById('statAvgLength').textContent = Math.round(stats.avgSessionLength);
		document.getElementById('statFrequency').textContent =
			stats.avgGap > 0 ? Math.round(stats.avgGap / (24 * 60 * 60 * 1000)) : '-';
	}

	function renderTimeline(messages) {
		var container = document.getElementById('timelineContainer');
		while (container.firstChild) {
			container.removeChild(container.firstChild);
		}

		if (messages.length === 0) return;

		var weeks = {};

		messages.forEach(function(msg) {
			var date = new Date(msg.date);
			var weekStart = new Date(date);
			weekStart.setHours(0, 0, 0, 0);
			weekStart.setDate(weekStart.getDate() - weekStart.getDay());
			var weekKey = weekStart.toISOString().split('T')[0];

			if (!weeks[weekKey]) {
				weeks[weekKey] = { you: 0, them: 0, date: weekStart };
			}

			if (msg.is_sender) {
				weeks[weekKey].you++;
			} else {
				weeks[weekKey].them++;
			}
		});

		var weekKeys = Object.keys(weeks).sort();
		var maxMessages = 0;
		weekKeys.forEach(function(key) {
			var total = weeks[key].you + weeks[key].them;
			if (total > maxMessages) maxMessages = total;
		});

		weekKeys.forEach(function(key) {
			var week = weeks[key];
			var total = week.you + week.them;
			var height = maxMessages > 0 ? Math.max(4, (total / maxMessages) * 60) : 4;
			var youHeight = total > 0 ? (week.you / total) * height : 0;
			var themHeight = total > 0 ? (week.them / total) * height : 0;

			var bar = document.createElement('div');
			bar.className = 'timeline-bar';
			bar.title = week.date.toLocaleDateString() + ': ' + total + ' messages';

			var youBar = document.createElement('div');
			youBar.className = 'timeline-bar-you';
			youBar.style.height = youHeight + 'px';

			var themBar = document.createElement('div');
			themBar.className = 'timeline-bar-them';
			themBar.style.height = themHeight + 'px';

			bar.appendChild(themBar);
			bar.appendChild(youBar);
			container.appendChild(bar);
		});
	}

	function showLoadingError(message) {
		var loading = document.getElementById('analysisLoading');
		while (loading.firstChild) {
			loading.removeChild(loading.firstChild);
		}
		var p = document.createElement('p');
		p.textContent = message;
		loading.appendChild(p);
	}

	function updateLoadMoreButton() {
		var container = document.getElementById('loadMoreContainer');
		var hint = document.getElementById('loadMoreHint');

		if (hasMoreMessages) {
			container.style.display = 'flex';
			if (allMessages.length > 0) {
				var oldest = new Date(allMessages[allMessages.length - 1].date);
				hint.textContent = 'Currently showing back to ' + oldest.toLocaleDateString();
			}
		} else {
			container.style.display = 'none';
		}
	}

	function refreshAnalysis() {
		var stats = analyzeMessages(allMessages);
		if (stats) {
			renderStats(stats);
			renderTimeline(stats.messages);
		}
		updateLoadMoreButton();
	}

	async function loadMore() {
		if (isLoading || !hasMoreMessages) return;

		isLoading = true;
		var btn = document.getElementById('loadMoreBtn');
		btn.disabled = true;
		btn.textContent = 'Loading...';

		try {
			await fetchMessages(null, function(count) {
				btn.textContent = 'Loading... (' + count + ' messages)';
			});

			refreshAnalysis();
		} catch (error) {
			console.error('Error loading more:', error);
		}

		isLoading = false;
		btn.disabled = false;
		btn.textContent = 'Load older history';
	}

	async function init() {
		if (!config.chatId) return;

		try {
			var dateLimit = getDateLimit();
			var messages = await fetchMessages(dateLimit, function(count) {
				document.getElementById('loadingProgress').textContent = count + ' messages loaded';
			});

			if (messages.length === 0) {
				showLoadingError('No messages found in the past year.');
				return;
			}

			var stats = analyzeMessages(messages);

			if (stats) {
				renderStats(stats);
				renderTimeline(stats.messages);

				document.getElementById('analysisLoading').style.display = 'none';
				document.getElementById('analysisContent').style.display = 'block';

				updateLoadMoreButton();
			}
		} catch (error) {
			showLoadingError('Error loading messages: ' + error.message);
		}

		document.getElementById('loadMoreBtn').addEventListener('click', loadMore);
	}

	document.addEventListener('DOMContentLoaded', init);
})();
