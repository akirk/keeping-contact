/**
 * Draft message page functions for Keeping Contact
 */

(function() {
	'use strict';

	// Config is set by inline PHP via window.kcDraftConfig
	var config = window.kcDraftConfig || {};

	// Style examples - persisted in localStorage
	var styleStorageKey = 'kc_style_examples_' + config.personUsername;

	window.loadStyleExampleSelections = function() {
		var saved = localStorage.getItem(styleStorageKey);
		if (saved) {
			try {
				var deselected = JSON.parse(saved);
				document.querySelectorAll('.style-example').forEach(function(el) {
					var idx = parseInt(el.dataset.idx, 10);
					if (deselected.includes(idx)) {
						el.dataset.selected = 'false';
					}
				});
			} catch (e) {}
		}
	};

	window.saveStyleExampleSelections = function() {
		var deselected = [];
		document.querySelectorAll('.style-example[data-selected="false"]').forEach(function(el) {
			deselected.push(parseInt(el.dataset.idx, 10));
		});
		localStorage.setItem(styleStorageKey, JSON.stringify(deselected));
	};

	window.toggleAllStyleExamples = function(selected) {
		document.querySelectorAll('.style-example').forEach(function(el) {
			el.dataset.selected = selected;
		});
		saveStyleExampleSelections();
		updateStyleCount();
	};

	window.updateStyleCount = function() {
		var total = document.querySelectorAll('.style-example').length;
		var selected = document.querySelectorAll('.style-example[data-selected="true"]').length;
		var countEl = document.getElementById('styleCount');
		if (countEl) {
			countEl.textContent = selected + '/' + total;
		}
	};

	function getSelectedStyleExamples() {
		var selected = [];
		document.querySelectorAll('.style-example[data-selected="true"]').forEach(function(el) {
			var idx = parseInt(el.dataset.idx, 10);
			if (config.userMessageSamples[idx]) {
				selected.push(config.userMessageSamples[idx]);
			}
		});
		return selected;
	}

	// Conversation context functions
	window.toggleAllContext = function(selected) {
		document.querySelectorAll('.conversation-group').forEach(function(group) {
			group.dataset.selected = selected;
			var btn = group.querySelector('.btn-toggle-group');
			if (btn) {
				btn.textContent = selected ? '✓' : '+';
			}
			var label = group.querySelector('.toggle-label');
			if (label) {
				label.textContent = selected ? 'Included' : 'Excluded';
			}
		});
		updateConversationCount();
	};

	window.updateConversationCount = function() {
		var total = 0;
		var selected = 0;
		document.querySelectorAll('.conversation-group').forEach(function(group) {
			var count = group.dataset.indices.split(',').length;
			total += count;
			if (group.dataset.selected === 'true') {
				selected += count;
			}
		});
		var countEl = document.getElementById('conversationCount');
		if (countEl) {
			countEl.textContent = selected + '/' + total;
		}
	};

	function getSelectedConversationContext() {
		var selected = [];
		document.querySelectorAll('.conversation-group[data-selected="true"]').forEach(function(group) {
			var indices = group.dataset.indices.split(',').map(function(i) { return parseInt(i, 10); });
			indices.forEach(function(idx) {
				if (config.conversationContext[idx]) {
					selected.push(config.conversationContext[idx]);
				}
			});
		});
		return selected;
	}

	// Load more messages
	window.loadMoreMessages = async function() {
		var btn = document.getElementById('loadMoreBtn');
		var cursor = btn.dataset.cursor;
		var chatId = btn.dataset.chat;

		btn.disabled = true;
		btn.textContent = 'Loading...';

		try {
			var response = await fetch(config.ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=kc_beeper_messages&chat_id=' + encodeURIComponent(chatId) + '&cursor=' + encodeURIComponent(cursor) + '&_wpnonce=' + config.nonce
			});

			var data = await response.json();

			if (data.success && data.data.messages) {
				var messages = data.data.messages;
				var groups = groupMessagesByTime(messages);
				var container = document.getElementById('conversationGroups');
				var newMsgCount = messages.length;

				// Shift existing group indices to make room for new messages at the beginning
				document.querySelectorAll('.conversation-group').forEach(function(group) {
					var oldIndices = group.dataset.indices.split(',').map(function(i) { return parseInt(i, 10); });
					var newIndices = oldIndices.map(function(i) { return i + newMsgCount; });
					group.dataset.indices = newIndices.join(',');
				});

				// Prepend new messages to conversationContext array
				var newContextMessages = messages.map(function(msg) {
					return {
						sender: msg.is_sender ? 'Me' : 'Them',
						text: msg.text
					};
				});
				config.conversationContext.unshift.apply(config.conversationContext, newContextMessages);

				// Measure scroll position before inserting
				var scrollContainer = document.querySelector('.conversation-groups-scroll');
				var oldScrollHeight = scrollContainer.scrollHeight;

				// Create HTML for new groups (indices start at 0 since we prepended)
				var currentIdx = 0;
				groups.forEach(function(group) {
					var groupEl = createConversationGroupElement(group, currentIdx);
					container.insertBefore(groupEl, container.firstChild);
					currentIdx += group.length;
				});

				// Restore scroll position so user stays in the same place
				var newScrollHeight = scrollContainer.scrollHeight;
				scrollContainer.scrollTop += (newScrollHeight - oldScrollHeight);

				// Update count
				updateConversationCount();

				// Update cursor or hide button
				if (data.data.has_more && data.data.next_cursor) {
					btn.dataset.cursor = data.data.next_cursor;
					btn.disabled = false;
					btn.textContent = 'Load older messages';
				} else {
					document.getElementById('loadMoreContainer').style.display = 'none';
				}
			} else {
				btn.textContent = 'Failed to load';
				setTimeout(function() {
					btn.disabled = false;
					btn.textContent = 'Load older messages';
				}, 2000);
			}
		} catch (error) {
			btn.textContent = 'Error loading';
			setTimeout(function() {
				btn.disabled = false;
				btn.textContent = 'Load older messages';
			}, 2000);
		}
	};

	function groupMessagesByTime(messages) {
		var groups = [];
		var currentGroup = [];
		var lastTime = null;
		var gapThreshold = 4 * 60 * 60 * 1000; // 4 hours in ms

		messages.forEach(function(msg) {
			var msgTime = new Date(msg.date).getTime();
			if (lastTime !== null && (msgTime - lastTime) > gapThreshold) {
				if (currentGroup.length > 0) {
					groups.push(currentGroup);
				}
				currentGroup = [];
			}
			currentGroup.push(msg);
			lastTime = msgTime;
		});

		if (currentGroup.length > 0) {
			groups.push(currentGroup);
		}

		return groups;
	}

	function createConversationGroupElement(group, startIdx) {
		var div = document.createElement('div');
		div.className = 'conversation-group';
		div.dataset.selected = 'true';

		var indices = group.map(function(_, i) { return startIdx + i; });
		div.dataset.indices = indices.join(',');

		var firstDate = new Date(group[0].date);
		var dateStr = firstDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });

		// Build header
		var header = document.createElement('div');
		header.className = 'conversation-group-header';

		var dateSpan = document.createElement('span');
		dateSpan.className = 'conversation-group-date';
		dateSpan.textContent = dateStr;

		var countSpan = document.createElement('span');
		countSpan.className = 'conversation-group-count';
		countSpan.textContent = group.length + ' messages';

		header.appendChild(dateSpan);
		header.appendChild(countSpan);
		div.appendChild(header);

		// Build message list
		var messageList = document.createElement('div');
		messageList.className = 'message-list';

		group.forEach(function(msg) {
			var msgItem = document.createElement('div');
			msgItem.className = 'message-item ' + (msg.is_sender ? 'sent' : 'received');

			var meta = document.createElement('div');
			meta.className = 'message-meta';

			var sender = document.createElement('span');
			sender.className = 'message-sender';
			sender.textContent = msg.sender;

			var date = document.createElement('span');
			date.className = 'message-date';
			date.textContent = new Date(msg.date).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });

			meta.appendChild(sender);
			meta.appendChild(date);

			var text = document.createElement('div');
			text.className = 'message-text';
			text.textContent = msg.text;

			msgItem.appendChild(meta);
			msgItem.appendChild(text);
			messageList.appendChild(msgItem);
		});

		div.appendChild(messageList);

		// Build footer with toggle
		var footer = document.createElement('div');
		footer.className = 'conversation-group-footer';

		var toggleBtn = document.createElement('button');
		toggleBtn.type = 'button';
		toggleBtn.className = 'btn-toggle-group';
		toggleBtn.title = 'Toggle inclusion';
		toggleBtn.textContent = '✓';

		var toggleLabel = document.createElement('span');
		toggleLabel.className = 'toggle-label';
		toggleLabel.textContent = 'Included';

		footer.appendChild(toggleBtn);
		footer.appendChild(toggleLabel);
		div.appendChild(footer);

		// Add click handler for toggle button
		toggleBtn.addEventListener('click', function(e) {
			e.stopPropagation();
			var isSelected = div.dataset.selected === 'true';
			div.dataset.selected = !isSelected;
			this.textContent = isSelected ? '+' : '✓';
			toggleLabel.textContent = isSelected ? 'Excluded' : 'Included';
			updateConversationCount();
		});

		return div;
	}

	// Draft generation
	var draftCounter = 0;

	window.loadOllamaModels = async function() {
		var select = document.getElementById('modelSelect');
		var currentValue = select.value;

		try {
			var response = await fetch('http://localhost:11434/api/tags');
			if (response.ok) {
				var data = await response.json();
				if (data.models && data.models.length > 0) {
					select.textContent = '';
					data.models.forEach(function(model) {
						var option = document.createElement('option');
						option.value = model.name;
						option.textContent = model.name;
						if (model.name === currentValue) {
							option.selected = true;
						}
						select.appendChild(option);
					});
				}
			}
		} catch (e) {
			console.log('Could not load Ollama models');
		}
	};

	function createDraftCard(intentText, modelName, systemPrompt, userPrompt) {
		draftCounter++;
		var draftsContainer = document.getElementById('aiDrafts');

		var card = document.createElement('div');
		card.className = 'ai-draft-card';
		card.id = 'draft-' + draftCounter;

		var header = document.createElement('div');
		header.className = 'ai-draft-card-header';

		var meta = document.createElement('div');
		meta.className = 'ai-draft-card-meta';

		var intentSpan = document.createElement('span');
		intentSpan.className = 'ai-draft-intent';
		intentSpan.textContent = intentText;

		var modelSpan = document.createElement('span');
		modelSpan.className = 'ai-draft-model';
		modelSpan.textContent = modelName;

		meta.appendChild(intentSpan);
		meta.appendChild(modelSpan);

		var actions = document.createElement('div');
		actions.className = 'ai-draft-card-actions';

		var useBtn = document.createElement('button');
		useBtn.type = 'button';
		useBtn.className = 'btn-use-draft';
		useBtn.textContent = 'Use';
		useBtn.onclick = function() {
			var content = card.querySelector('.ai-draft-card-content');
			document.getElementById('draftMessage').value = content.textContent;
			document.getElementById('draftMessage').focus();
		};

		var removeBtn = document.createElement('button');
		removeBtn.type = 'button';
		removeBtn.className = 'btn-remove-draft';
		removeBtn.textContent = '×';
		removeBtn.onclick = function() {
			card.remove();
		};

		actions.appendChild(useBtn);
		actions.appendChild(removeBtn);

		header.appendChild(meta);
		header.appendChild(actions);

		var content = document.createElement('div');
		content.className = 'ai-draft-card-content';

		// Prompt details
		var details = document.createElement('details');
		details.className = 'ai-draft-prompt-details';

		var summary = document.createElement('summary');
		summary.textContent = 'View prompt';

		var promptContent = document.createElement('div');
		promptContent.className = 'ai-draft-prompt-content';

		var systemSection = document.createElement('div');
		systemSection.className = 'prompt-section';
		var systemLabel = document.createElement('strong');
		systemLabel.textContent = 'System:';
		var systemPre = document.createElement('pre');
		systemPre.textContent = systemPrompt;
		systemSection.appendChild(systemLabel);
		systemSection.appendChild(systemPre);

		var userSection = document.createElement('div');
		userSection.className = 'prompt-section';
		var userLabel = document.createElement('strong');
		userLabel.textContent = 'User:';
		var userPre = document.createElement('pre');
		userPre.textContent = userPrompt;
		userSection.appendChild(userLabel);
		userSection.appendChild(userPre);

		promptContent.appendChild(systemSection);
		promptContent.appendChild(userSection);
		details.appendChild(summary);
		details.appendChild(promptContent);

		card.appendChild(header);
		card.appendChild(content);
		card.appendChild(details);

		draftsContainer.insertBefore(card, draftsContainer.firstChild);

		return content;
	}

	window.generateDraft = async function() {
		var prompt = document.getElementById('aiPrompt').value.trim();
		if (!prompt) {
			alert('Please describe what you want to say');
			return;
		}

		var btn = document.getElementById('generateBtn');
		var selectedModel = document.getElementById('modelSelect').value;

		btn.disabled = true;
		btn.textContent = 'Generating...';

		var systemPrompt = buildSystemPrompt();
		var userMessage = buildUserMessage(prompt);

		var contentDiv = createDraftCard(prompt, selectedModel, systemPrompt, userMessage);
		contentDiv.textContent = 'Thinking...';
		contentDiv.classList.add('ai-thinking');

		var ollama = new PersonalCrmOllama({ model: selectedModel });
		var messages = [
			{ role: 'system', content: systemPrompt },
			{ role: 'user', content: userMessage }
		];

		var result = await ollama.chatStream(messages, {
			onChunk: function(chunk) {
				if (contentDiv.classList.contains('ai-thinking')) {
					contentDiv.classList.remove('ai-thinking');
					contentDiv.textContent = '';
				}
				contentDiv.textContent += chunk;
			},
			onError: function(errorInfo) {
				contentDiv.classList.remove('ai-thinking');
				contentDiv.classList.add('ai-error');
				contentDiv.textContent = '';
				var errorMsg = document.createElement('p');
				errorMsg.textContent = 'Error: ' + errorInfo.message;
				contentDiv.appendChild(errorMsg);
				ollama.renderInstructions(contentDiv, errorInfo);
			},
			onComplete: function() {
				btn.disabled = false;
				btn.textContent = 'Generate';
			}
		});

		if (!result.success) {
			btn.disabled = false;
			btn.textContent = 'Generate';
		}
	};

	function buildSystemPrompt() {
		var prompt = 'You are a helpful assistant that drafts personal messages. Your task is to write a message from me to ' + config.personName + '.\n\n' +
			'Important guidelines:\n' +
			'- Write ONLY the message text, nothing else. No explanations, no "Here\'s a draft:", no quotes around the message.\n' +
			'- Be conversational and natural, not formal or stiff.\n' +
			'- Keep messages concise - typically 1-3 sentences unless more detail is needed.\n' +
			'- Use the same tone, style, and language patterns as my previous messages.\n' +
			'- Address them by their first name (' + config.personFirstName + ') if appropriate.';

		var selectedExamples = getSelectedStyleExamples();
		if (selectedExamples.length > 0) {
			prompt += '\n\nHere are examples of how I typically write messages to learn my style:\n' +
				selectedExamples.map(function(m) { return '- "' + m + '"'; }).join('\n');
		}

		return prompt;
	}

	function formatTimeSince(days) {
		if (days <= 1) return 'a day';
		if (days <= 3) return 'a few days';
		if (days <= 10) return 'about a week';
		if (days <= 20) return 'a couple of weeks';
		if (days <= 45) return 'a few weeks';
		if (days <= 75) return 'about two months';
		if (days <= 120) return 'a few months';
		if (days <= 200) return 'several months';
		if (days <= 400) return 'about a year';
		return 'over a year';
	}

	function formatTimeUntil(days) {
		if (days === 0) return 'today';
		if (days === 1) return 'tomorrow';
		if (days <= 3) return 'in a few days';
		if (days <= 10) return 'in about a week';
		if (days <= 14) return 'in a couple of weeks';
		return 'coming up';
	}

	function buildUserMessage(intent) {
		var message = 'Write a message with this intent: ' + intent;

		var selectedContext = getSelectedConversationContext();
		if (selectedContext.length > 0) {
			message += '\n\nRecent conversation for context:\n' +
				selectedContext.map(function(m) { return m.sender + ': ' + m.text; }).join('\n');
		}

		if (config.daysSinceContact !== null) {
			message += '\n\nIt\'s been ' + formatTimeSince(config.daysSinceContact) + ' since we last communicated.';
		}

		if (config.scheduleNotes) {
			message += '\n\nNotes about this person: ' + config.scheduleNotes;
		}

		if (config.eventsContext && config.eventsContext.length > 0) {
			var relevantEvents = config.eventsContext.filter(function(e) { return e.days_until <= 14; });
			if (relevantEvents.length > 0) {
				message += '\n\nUpcoming events to potentially mention:';
				relevantEvents.forEach(function(e) {
					message += '\n- ' + e.description + ' on ' + e.date + ' (' + formatTimeUntil(e.days_until) + ')';
				});
			}
		}

		return message;
	}

	window.copyDraft = function() {
		var textarea = document.getElementById('draftMessage');
		textarea.select();
		document.execCommand('copy');

		var btn = event.target;
		var originalText = btn.textContent;
		btn.textContent = 'Copied!';
		setTimeout(function() { btn.textContent = originalText; }, 2000);
	};

	window.sendViaBeeper = function() {
		var text = document.getElementById('draftMessage').value.trim();
		if (!text) {
			alert('Please write a message first');
			return;
		}

		if (!confirm('Send this message via Beeper?')) {
			return;
		}

		fetch(config.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'action=kc_beeper_send&chat_id=' + encodeURIComponent(config.activeChatId) + '&text=' + encodeURIComponent(text) + '&username=' + encodeURIComponent(config.personUsername) + '&_wpnonce=' + config.nonce
		})
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (data.success) {
				document.getElementById('draftMessage').value = '';
				alert('Message sent!');
				location.reload();
			} else {
				alert('Error: ' + (data.data || 'Failed to send'));
			}
		})
		.catch(function(err) {
			alert('Error: ' + err.message);
		});
	};

	// AI Assistant toggle
	window.toggleAiAssistant = function() {
		var main = document.querySelector('.draft-main');
		if (main) {
			main.classList.toggle('ai-assistant-visible');
		}
	};

	// Initialize on DOM ready
	document.addEventListener('DOMContentLoaded', function() {
		// Style example click handlers
		document.querySelectorAll('.style-example').forEach(function(el) {
			el.addEventListener('click', function() {
				var isSelected = this.dataset.selected === 'true';
				this.dataset.selected = !isSelected;
				saveStyleExampleSelections();
				updateStyleCount();
			});
		});

		// Load saved selections
		loadStyleExampleSelections();
		updateStyleCount();

		// Scroll conversation list to bottom
		var conversationScroll = document.querySelector('.conversation-groups-scroll');
		if (conversationScroll) {
			conversationScroll.scrollTop = conversationScroll.scrollHeight;
		}

		// Conversation group toggle handlers
		document.querySelectorAll('.conversation-group .btn-toggle-group').forEach(function(btn) {
			btn.addEventListener('click', function(e) {
				e.stopPropagation();
				var group = this.closest('.conversation-group');
				var isSelected = group.dataset.selected === 'true';
				group.dataset.selected = !isSelected;
				this.textContent = isSelected ? '+' : '✓';
				var label = group.querySelector('.toggle-label');
				if (label) {
					label.textContent = isSelected ? 'Excluded' : 'Included';
				}
				updateConversationCount();
			});
		});

		// Initial count
		updateConversationCount();

		// Load more button
		var loadMoreBtn = document.getElementById('loadMoreBtn');
		if (loadMoreBtn) {
			loadMoreBtn.addEventListener('click', loadMoreMessages);
		}

		// Load models
		loadOllamaModels();

		// Enter key to generate
		var aiPrompt = document.getElementById('aiPrompt');
		if (aiPrompt) {
			aiPrompt.addEventListener('keypress', function(e) {
				if (e.key === 'Enter') {
					generateDraft();
				}
			});
		}
	});
})();
