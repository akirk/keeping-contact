/**
 * Group Relationship Analysis JavaScript
 */

(function() {
	'use strict';

	var config = window.kcGroupAnalysisConfig || {};
	var results = {};
	var currentSort = { column: 'total', ascending: false };

	function getDateLimit() {
		var limit = new Date();
		limit.setFullYear(limit.getFullYear() - 1);
		return limit;
	}

	async function fetchMessages(chatId, dateLimit) {
		var allMessages = [];
		var cursor = null;
		var hasMore = true;

		while (hasMore) {
			var body = 'action=kc_beeper_messages&chat_id=' + encodeURIComponent(chatId) + '&_wpnonce=' + config.nonce;
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
			}

			hasMore = !reachedLimit && data.data.has_more;
			cursor = data.data.next_cursor;

			if (!cursor) break;
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
		var lastTime = null;
		var lastSender = null;
		var lastMessageDate = null;
		var gapThreshold = 4 * 60 * 60 * 1000;

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

			lastTime = msgTime;
			lastSender = isSender;
			lastMessageDate = msg.date;
		}

		var avgYouResponse = youResponseTimes.length > 0
			? youResponseTimes.reduce(function(a, b) { return a + b; }, 0) / youResponseTimes.length
			: 0;
		var avgThemResponse = themResponseTimes.length > 0
			? themResponseTimes.reduce(function(a, b) { return a + b; }, 0) / themResponseTimes.length
			: 0;

		var longestGap = gaps.length > 0 ? Math.max.apply(null, gaps) : 0;

		return {
			total: messages.length,
			youCount: youCount,
			themCount: themCount,
			youInitiations: youInitiations,
			themInitiations: themInitiations,
			avgYouResponse: avgYouResponse,
			avgThemResponse: avgThemResponse,
			longestGap: longestGap,
			lastMessageDate: lastMessageDate
		};
	}

	function formatDuration(ms) {
		var seconds = Math.floor(ms / 1000);
		var minutes = Math.floor(seconds / 60);
		var hours = Math.floor(minutes / 60);
		var days = Math.floor(hours / 24);

		if (days > 0) {
			return days + 'd';
		}
		if (hours > 0) {
			return hours + 'h';
		}
		if (minutes > 0) {
			return minutes + 'm';
		}
		return '< 1m';
	}

	function getBalance(stats) {
		var total = stats.youCount + stats.themCount;
		if (total === 0) return 50;
		return Math.round((stats.youCount / total) * 100);
	}

	function getInitiator(stats) {
		var total = stats.youInitiations + stats.themInitiations;
		if (total === 0) return 'Equal';
		var youPct = (stats.youInitiations / total) * 100;
		if (youPct > 60) return 'You';
		if (youPct < 40) return 'Them';
		return 'Equal';
	}

	function renderTable() {
		var tbody = document.getElementById('analysisTableBody');
		while (tbody.firstChild) {
			tbody.removeChild(tbody.firstChild);
		}

		var rows = Object.keys(results).map(function(username) {
			var r = results[username];
			return {
				username: username,
				name: r.name,
				total: r.stats ? r.stats.total : 0,
				balance: r.stats ? getBalance(r.stats) : 50,
				initiator: r.stats ? getInitiator(r.stats) : 'N/A',
				responseTime: r.stats ? (r.stats.avgYouResponse + r.stats.avgThemResponse) / 2 : 0,
				gap: r.stats ? r.stats.longestGap : 0,
				analysisUrl: r.analysisUrl
			};
		});

		rows.sort(function(a, b) {
			var aVal = a[currentSort.column];
			var bVal = b[currentSort.column];

			if (typeof aVal === 'string') {
				aVal = aVal.toLowerCase();
				bVal = bVal.toLowerCase();
			}

			if (currentSort.ascending) {
				return aVal > bVal ? 1 : -1;
			} else {
				return aVal < bVal ? 1 : -1;
			}
		});

		rows.forEach(function(row) {
			var tr = document.createElement('tr');
			tr.onclick = function() {
				window.location.href = row.analysisUrl;
			};
			tr.style.cursor = 'pointer';

			var tdName = document.createElement('td');
			tdName.textContent = row.name;
			tr.appendChild(tdName);

			var tdTotal = document.createElement('td');
			tdTotal.textContent = row.total;
			tr.appendChild(tdTotal);

			var tdBalance = document.createElement('td');
			var balanceBar = document.createElement('div');
			balanceBar.className = 'mini-balance-bar';
			var youBar = document.createElement('div');
			youBar.className = 'mini-balance-you';
			youBar.style.width = row.balance + '%';
			balanceBar.appendChild(youBar);
			tdBalance.appendChild(balanceBar);
			var balanceText = document.createElement('span');
			balanceText.className = 'balance-text';
			balanceText.textContent = row.balance + '/' + (100 - row.balance);
			tdBalance.appendChild(balanceText);
			tr.appendChild(tdBalance);

			var tdInit = document.createElement('td');
			tdInit.textContent = row.initiator;
			tr.appendChild(tdInit);

			var tdResponse = document.createElement('td');
			tdResponse.textContent = row.responseTime > 0 ? formatDuration(row.responseTime) : '-';
			tr.appendChild(tdResponse);

			var tdGap = document.createElement('td');
			tdGap.textContent = row.gap > 0 ? formatDuration(row.gap) : '-';
			tr.appendChild(tdGap);

			tbody.appendChild(tr);
		});
	}

	function renderSummary() {
		var totalPeople = Object.keys(results).length;
		var totalMessages = 0;
		var mostActive = { name: '-', count: 0 };

		Object.keys(results).forEach(function(username) {
			var r = results[username];
			if (r.stats) {
				totalMessages += r.stats.total;
				if (r.stats.total > mostActive.count) {
					mostActive = { name: r.name, count: r.stats.total };
				}
			}
		});

		document.getElementById('statPeople').textContent = totalPeople;
		document.getElementById('statMessages').textContent = totalMessages;
		document.getElementById('statAvg').textContent = totalPeople > 0 ? Math.round(totalMessages / totalPeople) : 0;
		document.getElementById('statMostActive').textContent = mostActive.name.split(' ')[0];
	}

	function renderInsights() {
		var grid = document.getElementById('insightsGrid');
		while (grid.firstChild) {
			grid.removeChild(grid.firstChild);
		}

		var rows = Object.keys(results).map(function(username) {
			var r = results[username];
			return {
				username: username,
				name: r.name,
				stats: r.stats
			};
		}).filter(function(r) { return r.stats; });

		var longestGaps = rows.slice().sort(function(a, b) {
			return b.stats.longestGap - a.stats.longestGap;
		}).slice(0, 3);

		if (longestGaps.length > 0) {
			var gapCard = document.createElement('div');
			gapCard.className = 'insight-card';

			var gapTitle = document.createElement('h4');
			gapTitle.textContent = 'Needs Attention';
			gapCard.appendChild(gapTitle);

			var gapDesc = document.createElement('p');
			gapDesc.textContent = 'Longest gaps without contact:';
			gapCard.appendChild(gapDesc);

			var gapList = document.createElement('ul');
			longestGaps.forEach(function(r) {
				var li = document.createElement('li');
				li.textContent = r.name.split(' ')[0] + ': ' + formatDuration(r.stats.longestGap);
				gapList.appendChild(li);
			});
			gapCard.appendChild(gapList);
			grid.appendChild(gapCard);
		}

		var oneSided = rows.filter(function(r) {
			var balance = getBalance(r.stats);
			return balance > 70 || balance < 30;
		}).slice(0, 3);

		if (oneSided.length > 0) {
			var sideCard = document.createElement('div');
			sideCard.className = 'insight-card';

			var sideTitle = document.createElement('h4');
			sideTitle.textContent = 'One-Sided';
			sideCard.appendChild(sideTitle);

			var sideDesc = document.createElement('p');
			sideDesc.textContent = 'Unbalanced message ratios:';
			sideCard.appendChild(sideDesc);

			var sideList = document.createElement('ul');
			oneSided.forEach(function(r) {
				var balance = getBalance(r.stats);
				var li = document.createElement('li');
				li.textContent = r.name.split(' ')[0] + ': ' + balance + '% you';
				sideList.appendChild(li);
			});
			sideCard.appendChild(sideList);
			grid.appendChild(sideCard);
		}

		var youInitiate = rows.filter(function(r) {
			return getInitiator(r.stats) === 'You';
		});
		var theyInitiate = rows.filter(function(r) {
			return getInitiator(r.stats) === 'Them';
		});

		var initCard = document.createElement('div');
		initCard.className = 'insight-card';

		var initTitle = document.createElement('h4');
		initTitle.textContent = 'Who Starts';
		initCard.appendChild(initTitle);

		var initDesc = document.createElement('p');
		initDesc.textContent = 'Conversation initiators:';
		initCard.appendChild(initDesc);

		var initList = document.createElement('ul');
		var liYou = document.createElement('li');
		liYou.textContent = 'You start more: ' + youInitiate.length + ' people';
		initList.appendChild(liYou);
		var liThem = document.createElement('li');
		liThem.textContent = 'They start more: ' + theyInitiate.length + ' people';
		initList.appendChild(liThem);
		initCard.appendChild(initList);
		grid.appendChild(initCard);
	}

	function setupSorting() {
		document.querySelectorAll('.sortable').forEach(function(th) {
			th.addEventListener('click', function() {
				var column = this.dataset.sort;
				if (currentSort.column === column) {
					currentSort.ascending = !currentSort.ascending;
				} else {
					currentSort.column = column;
					currentSort.ascending = column === 'name';
				}

				document.querySelectorAll('.sortable').forEach(function(el) {
					el.classList.remove('sort-asc', 'sort-desc');
				});
				this.classList.add(currentSort.ascending ? 'sort-asc' : 'sort-desc');

				renderTable();
			});
		});
	}

	async function analyzePerson(person) {
		var dateLimit = getDateLimit();
		var messages = await fetchMessages(person.chatId, dateLimit);
		var stats = analyzeMessages(messages);

		results[person.username] = {
			name: person.name,
			stats: stats,
			analysisUrl: person.analysisUrl
		};
	}

	async function init() {
		if (!config.people || config.people.length === 0) return;

		setupSorting();

		var total = config.people.length;
		var completed = 0;

		for (var i = 0; i < config.people.length; i++) {
			var person = config.people[i];

			document.getElementById('loadingCurrent').textContent = 'Analyzing ' + person.name + '...';

			try {
				await analyzePerson(person);
			} catch (error) {
				console.error('Error analyzing ' + person.username + ':', error);
			}

			completed++;
			document.getElementById('loadingProgress').textContent = completed + ' of ' + total + ' people';

			renderSummary();
			renderTable();
		}

		renderInsights();

		document.getElementById('analysisLoading').style.display = 'none';
		document.getElementById('analysisContent').style.display = 'block';
	}

	document.addEventListener('DOMContentLoaded', init);
})();
