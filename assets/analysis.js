/**
 * Relationship Analysis JavaScript
 */

(function() {
	'use strict';

	var config = window.kcAnalysisConfig || {};
	var allMessages = [];
	var chatStates = {}; // Track cursor and hasMore per chat
	var hasMoreMessages = false;
	var isLoading = false;
	var INITIAL_LIMIT = 5000;
	var LOAD_MORE_BATCH = 1000;

	function getDateLimit() {
		var limit = new Date();
		limit.setFullYear(limit.getFullYear() - 1);
		return limit;
	}

	function initChatStates() {
		if (!config.chatIds) return;
		config.chatIds.forEach(function(chatId) {
			if (!chatStates[chatId]) {
				chatStates[chatId] = {
					cursor: null,
					hasMore: true,
					hitDateLimit: false,
					oldestFetched: null,
					pendingMessages: []
				};
			}
		});
	}

	async function fetchMessagesFromChat(chatId, dateLimit) {
		var state = chatStates[chatId];
		if (!state.hasMore || state.hitDateLimit) return [];

		var messages = [];
		var body = 'action=kc_beeper_messages&chat_id=' + encodeURIComponent(chatId) + '&_wpnonce=' + config.nonce;
		if (state.cursor) {
			body += '&cursor=' + encodeURIComponent(state.cursor);
		}

		var response = await fetch(config.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body
		});

		var data = await response.json();

		if (!data.success || !data.data.messages) {
			state.hasMore = false;
			return [];
		}

		var oldestInBatch = null;
		for (var i = 0; i < data.data.messages.length; i++) {
			var msg = data.data.messages[i];
			var msgDate = new Date(msg.date);

			if (dateLimit && msgDate < dateLimit) {
				state.hitDateLimit = true;
				continue;
			}

			msg._chatId = chatId;
			messages.push(msg);

			if (oldestInBatch === null || msgDate < oldestInBatch) {
				oldestInBatch = msgDate;
			}
		}

		if (oldestInBatch) {
			state.oldestFetched = oldestInBatch;
		}

		state.cursor = data.data.next_cursor;
		state.hasMore = data.data.has_more && !state.hitDateLimit;

		return messages;
	}

	async function fetchMessages(dateLimit, updateProgress) {
		if (!config.chatIds || config.chatIds.length === 0) {
			return allMessages;
		}

		var loadedCount = allMessages.length;
		var youCount = 0;
		var themCount = 0;

		allMessages.forEach(function(msg) {
			if (msg.is_sender) youCount++;
			else themCount++;
		});

		var keepFetching = true;
		var syncWindow = 14 * 24 * 60 * 60 * 1000; // 14 days
		var noProgressCount = 0;
		var maxNoProgress = 10; // Break if no progress after this many iterations

		while (keepFetching && allMessages.length < INITIAL_LIMIT) {
			var countAtStartOfIteration = allMessages.length;

			// Find the frontier: most recent "oldest fetched" date across all chats
			var frontier = null;
			config.chatIds.forEach(function(chatId) {
				var state = chatStates[chatId];
				if (state.oldestFetched && (frontier === null || state.oldestFetched > frontier)) {
					frontier = state.oldestFetched;
				}
			});

			// Fetch from chats that are at or near the frontier
			var fetchPromises = [];
			config.chatIds.forEach(function(chatId) {
				var state = chatStates[chatId];
				if (!state.hasMore || state.hitDateLimit) return;

				// Don't fetch more if there are pending messages waiting
				if (state.pendingMessages.length > 0) return;

				// Eligible if: hasn't fetched yet, or is within sync window of frontier
				var eligible = !state.oldestFetched ||
					!frontier ||
					(frontier.getTime() - state.oldestFetched.getTime()) < syncWindow;

				if (eligible) {
					fetchPromises.push(fetchMessagesFromChat(chatId, dateLimit));
				}
			});

			// Fetch if we have eligible chats, otherwise yield to event loop
			if (fetchPromises.length > 0) {
				var results = await Promise.all(fetchPromises);

				// Add fetched messages to each chat's pending buffer
				results.forEach(function(msgs) {
					msgs.forEach(function(msg) {
						var state = chatStates[msg._chatId];
						if (state) {
							state.pendingMessages.push(msg);
						}
					});
				});
			} else {
				// Yield to event loop to prevent blocking the browser
				await new Promise(function(resolve) { setTimeout(resolve, 0); });
			}

			// Check if any chat has pending messages
			var anyPending = config.chatIds.some(function(chatId) {
				return chatStates[chatId].pendingMessages.length > 0;
			});

			// If no fetches and no pending, check for paused chats
			if (fetchPromises.length === 0 && !anyPending) {
				var anyPaused = config.chatIds.some(function(chatId) {
					var state = chatStates[chatId];
					return state.hasMore && !state.hitDateLimit;
				});

				if (anyPaused) {
					// Force fetch from paused chats
					config.chatIds.forEach(function(chatId) {
						var state = chatStates[chatId];
						if (state.hasMore && !state.hitDateLimit) {
							fetchPromises.push(fetchMessagesFromChat(chatId, dateLimit));
						}
					});

					if (fetchPromises.length > 0) {
						var forcedResults = await Promise.all(fetchPromises);
						forcedResults.forEach(function(msgs) {
							msgs.forEach(function(msg) {
								var state = chatStates[msg._chatId];
								if (state) {
									state.pendingMessages.push(msg);
								}
							});
						});
					}
				}
			}

			// Re-check pending after potential forced fetch
			anyPending = config.chatIds.some(function(chatId) {
				return chatStates[chatId].pendingMessages.length > 0;
			});

			if (!anyPending) {
				// Nothing to process
				var anyHasMore = config.chatIds.some(function(chatId) {
					var state = chatStates[chatId];
					return state.hasMore && !state.hitDateLimit;
				});
				if (!anyHasMore) {
					keepFetching = false;
					break;
				}
			}

			// Find the current frontier (oldest message already added, or now if empty)
			var currentOldest = allMessages.length > 0
				? new Date(allMessages[allMessages.length - 1].date)
				: new Date();

			// Process pending messages: only add those within sync window
			var addedAny = false;
			config.chatIds.forEach(function(chatId) {
				var state = chatStates[chatId];
				var stillPending = [];

				state.pendingMessages.forEach(function(msg) {
					var msgDate = new Date(msg.date);
					var withinWindow = (currentOldest.getTime() - msgDate.getTime()) < syncWindow;

					if (withinWindow && allMessages.length < INITIAL_LIMIT) {
						allMessages.push(msg);
						loadedCount++;
						if (msg.is_sender) youCount++;
						else themCount++;
						addedAny = true;
					} else {
						stillPending.push(msg);
					}
				});

				state.pendingMessages = stillPending;
			});

			// Sort by date after each batch
			allMessages.sort(function(a, b) {
				return new Date(b.date) - new Date(a.date);
			});

			// Check if any chat still has more messages or pending messages
			var anyHasMore = config.chatIds.some(function(chatId) {
				var state = chatStates[chatId];
				return (state.hasMore && !state.hitDateLimit) || state.pendingMessages.length > 0;
			});

			if (!anyHasMore) {
				keepFetching = false;
			}

			// Force advance if we can't make progress any other way:
			// - Nothing was added this round
			// - All chats are either exhausted OR blocked by pending messages
			var allChatsBlocked = config.chatIds.every(function(chatId) {
				var state = chatStates[chatId];
				var exhausted = !state.hasMore || state.hitDateLimit;
				var blockedByPending = state.pendingMessages.length > 0;
				return exhausted || blockedByPending;
			});

			var hasPendingMessages = config.chatIds.some(function(chatId) {
				return chatStates[chatId].pendingMessages.length > 0;
			});

			if (!addedAny && allChatsBlocked && hasPendingMessages) {
				// Force add the most recent pending message to advance the frontier
				var newestPending = null;
				var newestPendingChat = null;
				config.chatIds.forEach(function(chatId) {
					var state = chatStates[chatId];
					state.pendingMessages.forEach(function(msg) {
						var msgDate = new Date(msg.date);
						if (!newestPending || msgDate > newestPending.date) {
							newestPending = { msg: msg, date: msgDate };
							newestPendingChat = chatId;
						}
					});
				});

				if (newestPending && allMessages.length < INITIAL_LIMIT) {
					allMessages.push(newestPending.msg);
					loadedCount++;
					if (newestPending.msg.is_sender) youCount++;
					else themCount++;

					// Remove from pending
					var state = chatStates[newestPendingChat];
					state.pendingMessages = state.pendingMessages.filter(function(m) {
						return m !== newestPending.msg;
					});

					allMessages.sort(function(a, b) {
						return new Date(b.date) - new Date(a.date);
					});
				}
			}

			// Track progress to prevent infinite loops
			if (allMessages.length === countAtStartOfIteration && fetchPromises.length === 0) {
				noProgressCount++;
				if (noProgressCount >= maxNoProgress) {
					console.warn('Breaking out of fetch loop - no progress after', maxNoProgress, 'iterations');
					keepFetching = false;
				}
			} else {
				noProgressCount = 0;
			}

			if (updateProgress) {
				var oldest = allMessages.length > 0 ? new Date(allMessages[allMessages.length - 1].date) : null;
				var newest = allMessages.length > 0 ? new Date(allMessages[0].date) : null;
				updateProgress({
					count: loadedCount,
					oldestDate: oldest,
					newestDate: newest,
					youCount: youCount,
					themCount: themCount,
					hitLimit: allMessages.length >= INITIAL_LIMIT
				});
			}
		}

		// Update hasMoreMessages for "load more" button
		hasMoreMessages = config.chatIds.some(function(chatId) {
			var state = chatStates[chatId];
			return (state.hasMore && !state.hitDateLimit) || state.pendingMessages.length > 0;
		});

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
		var dayOfWeek = [0, 0, 0, 0, 0, 0, 0]; // Sun-Sat
		var hourOfDay = new Array(24).fill(0);

		messages.sort(function(a, b) {
			return new Date(a.date) - new Date(b.date);
		});

		for (var i = 0; i < messages.length; i++) {
			var msg = messages[i];
			var msgDate = new Date(msg.date);
			var msgTime = msgDate.getTime();
			var isSender = msg.is_sender;

			dayOfWeek[msgDate.getDay()]++;
			hourOfDay[msgDate.getHours()]++;

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
			dayOfWeek: dayOfWeek,
			hourOfDay: hourOfDay,
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

	function renderTimeframe(messages) {
		if (messages.length === 0) return;

		var sorted = messages.slice().sort(function(a, b) {
			return new Date(a.date) - new Date(b.date);
		});
		var oldest = new Date(sorted[0].date);
		var newest = new Date(sorted[sorted.length - 1].date);

		var opts = { month: 'short', day: 'numeric', year: 'numeric' };
		var text = oldest.toLocaleDateString('en-US', opts) + ' — ' + newest.toLocaleDateString('en-US', opts);
		text += ' · ' + messages.length.toLocaleString() + ' messages';

		document.getElementById('analysisTimeframe').textContent = text;
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

		var opts = { month: 'short', day: 'numeric' };
		weekKeys.forEach(function(key) {
			var week = weeks[key];
			var total = week.you + week.them;
			var height = maxMessages > 0 ? Math.max(4, (total / maxMessages) * 60) : 4;
			var youHeight = total > 0 ? (week.you / total) * height : 0;
			var themHeight = total > 0 ? (week.them / total) * height : 0;

			var weekEnd = new Date(week.date);
			weekEnd.setDate(weekEnd.getDate() + 6);
			var dateRange = week.date.toLocaleDateString('en-US', opts) + ' – ' + weekEnd.toLocaleDateString('en-US', opts);
			var tooltip = dateRange + '\n' + total + ' messages\nYou: ' + week.you + ' · Them: ' + week.them;

			var bar = document.createElement('div');
			bar.className = 'timeline-bar';
			bar.setAttribute('data-tooltip', tooltip);

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

	function renderWeekFrequency(stats) {
		var container = document.getElementById('weekFrequencyContainer');
		if (!container) return;

		while (container.firstChild) {
			container.removeChild(container.firstChild);
		}

		var days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
		var max = Math.max.apply(null, stats.dayOfWeek);

		stats.dayOfWeek.forEach(function(count, idx) {
			var bar = document.createElement('div');
			bar.className = 'frequency-bar';
			bar.setAttribute('data-tooltip', count.toLocaleString() + ' messages');

			var fill = document.createElement('div');
			fill.className = 'frequency-bar-fill';
			fill.style.height = max > 0 ? Math.max(4, (count / max) * 50) + 'px' : '4px';

			var label = document.createElement('div');
			label.className = 'frequency-bar-label';
			label.textContent = days[idx];

			bar.appendChild(fill);
			bar.appendChild(label);
			container.appendChild(bar);
		});

		// Find busiest day
		var busiestIdx = stats.dayOfWeek.indexOf(max);
		document.getElementById('busiestDay').textContent = days[busiestIdx];
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
		var btn = document.getElementById('loadMoreBtn');
		if (hasMoreMessages) {
			btn.classList.remove('hidden');
		} else {
			btn.classList.add('hidden');
		}
	}

	function refreshAnalysis() {
		var stats = analyzeMessages(allMessages);
		if (stats) {
			renderTimeframe(stats.messages);
			renderStats(stats);
			renderTimeline(stats.messages);
			renderWeekFrequency(stats);
		}
		updateLoadMoreButton();
	}

	async function loadMore() {
		if (isLoading || !hasMoreMessages) return;

		isLoading = true;
		var btn = document.getElementById('loadMoreBtn');
		btn.textContent = '· Loading...';
		btn.classList.add('loading');

		// Increase limit to load more messages
		INITIAL_LIMIT += LOAD_MORE_BATCH;

		try {
			await fetchMessages(null, null);
			refreshAnalysis();
		} catch (error) {
			console.error('Error loading more:', error);
		}

		isLoading = false;
		btn.classList.remove('loading');
		btn.textContent = '· Load more';
	}

	async function init() {
		if (!config.chatIds || config.chatIds.length === 0) return;

		initChatStates();

		try {
			var dateLimit = getDateLimit();
			var messages = await fetchMessages(dateLimit, function(progress) {
				// Message count with date range
				var opts = { month: 'short', day: 'numeric' };
				var text = progress.count.toLocaleString() + ' messages';
				if (progress.oldestDate && progress.newestDate) {
					text += ' · ' + progress.oldestDate.toLocaleDateString('en-US', opts) +
						' — ' + progress.newestDate.toLocaleDateString('en-US', opts);
				}
				document.getElementById('loadingProgress').textContent = text;

				// Limit indicator
				document.getElementById('loadingRange').textContent = progress.hitLimit ? 'Limit reached' : '';

				// Balance bar
				var total = progress.youCount + progress.themCount;
				var youPct = total > 0 ? Math.round((progress.youCount / total) * 100) : 50;
				var themPct = 100 - youPct;

				document.getElementById('loadingBarYou').style.width = youPct + '%';
				document.getElementById('loadingBarThem').style.width = themPct + '%';
				document.getElementById('loadingLabelYou').textContent = 'You: ' + youPct + '%';
				document.getElementById('loadingLabelThem').textContent = 'Them: ' + themPct + '%';
			});

			if (messages.length === 0) {
				showLoadingError('No messages found in the past year.');
				return;
			}

			var stats = analyzeMessages(messages);

			if (stats) {
				renderTimeframe(stats.messages);
				renderStats(stats);
				renderTimeline(stats.messages);
				renderWeekFrequency(stats);

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
