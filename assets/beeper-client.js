/**
 * Beeper API Client for browser-side communication
 *
 * Makes direct requests to Beeper Desktop API (localhost:23373)
 */

const BEEPER_DEMO_FIRST_NAMES = ['Alice', 'Bob', 'Carol', 'David', 'Emma', 'Frank', 'Grace', 'Henry', 'Isabel', 'James', 'Kate', 'Liam', 'Maya', 'Noah', 'Olivia', 'Peter', 'Quinn', 'Rachel', 'Sam', 'Tara'];
const BEEPER_DEMO_LAST_NAMES  = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Wilson', 'Moore', 'Taylor', 'Anderson', 'Thomas', 'Jackson', 'White', 'Harris', 'Martin', 'Thompson', 'Young', 'Clark'];

class BeeperClient {
	constructor(token, apiBase = 'http://localhost:23373/v1') {
		this.token = token;
		this.apiBase = apiBase;
		const cfg = window.BeeperClientConfig || {};
		this.demoMode       = cfg.demoMode || false;
		this.fakeFirstNames = (cfg.fakeNames && cfg.fakeNames.first && cfg.fakeNames.first.length) ? cfg.fakeNames.first : BEEPER_DEMO_FIRST_NAMES;
		this.fakeLastNames  = (cfg.fakeNames && cfg.fakeNames.last  && cfg.fakeNames.last.length)  ? cfg.fakeNames.last  : BEEPER_DEMO_LAST_NAMES;
	}

	_fakeName(name, type = 'person') {
		if (!name) return name;
		if (this.demoMode) {
			this._recordSeenName(name, type);
			const override = this._getNameOverride(name);
			if (override !== undefined && override !== '') return override;
		}
		let sum = 0;
		for (let i = 0; i < name.length; i++) sum += name.charCodeAt(i);
		const first = this.fakeFirstNames[sum % this.fakeFirstNames.length];
		if (name.trim().includes(' ')) {
			return first + ' ' + this.fakeLastNames[(sum * 7 + 3) % this.fakeLastNames.length];
		}
		return first;
	}

	_getNameOverride(name) {
		try {
			const o = JSON.parse(localStorage.getItem('bdm_name_overrides') || '{}');
			return o[name];
		} catch(e) { return undefined; }
	}

	_recordSeenName(name, type) {
		if (!name) return;
		try {
			const seen = JSON.parse(localStorage.getItem('bdm_seen_names') || '[]');
			if (!seen.some(n => n.name === name)) {
				seen.push({ name, type });
				localStorage.setItem('bdm_seen_names', JSON.stringify(seen));
			}
		} catch(e) {}
	}

	_anonymizeChat(chat) {
		if (!chat) return chat;
		const c = Object.assign({}, chat);
		if (c.title) c.title = this._fakeName(c.title, c.type || 'group');
		if (c.participants && c.participants.items) {
			c.participants = Object.assign({}, c.participants, {
				items: c.participants.items.map(p => p.isSelf ? p : Object.assign({}, p, {
					fullName: p.fullName ? this._fakeName(p.fullName, 'person') : p.fullName
				}))
			});
		}
		return c;
	}

	isConfigured() {
		return !!this.token;
	}

	async request(endpoint, options = {}) {
		if (!this.isConfigured()) {
			return { success: false, error: 'Beeper API token not configured' };
		}

		const url = this.apiBase + endpoint + (options.params ? '?' + new URLSearchParams(options.params) : '');

		try {
			const response = await fetch(url, {
				method: options.method || 'GET',
				headers: {
					'Authorization': 'Bearer ' + this.token,
					'Content-Type': 'application/json'
				},
				body: options.body ? JSON.stringify(options.body) : undefined
			});

			const data = await response.json();

			if (!response.ok) {
				return {
					success: false,
					error: data.message || 'Beeper API error',
					status: response.status
				};
			}

			return { success: true, data: data };
		} catch (error) {
			return {
				success: false,
				error: error.message || 'Failed to connect to Beeper'
			};
		}
	}

	async getAccounts() {
		return this.request('/accounts');
	}

	async searchChats(query, limit = 10) {
		const result = await this.request('/chats/search', { params: { query: query } });

		if (!result.success) {
			return result;
		}

		const allChats = result.data.items || [];
		const chats = [];

		for (const chat of allChats) {
			if (chat.type === 'single') {
				chats.push(this.demoMode ? this._anonymizeChat(chat) : chat);
				if (chats.length >= limit) break;
			}
		}

		return { success: true, data: { items: chats } };
	}

	async getChat(chatId) {
		const result = await this.request('/chats/' + encodeURIComponent(chatId));
		if (result.success && this.demoMode) {
			return Object.assign({}, result, { data: this._anonymizeChat(result.data) });
		}
		return result;
	}

	async getAllChats(limit = 200) {
		const result = await this.request('/chats', { params: { limit: limit } });

		if (!result.success) {
			return result;
		}

		const items = result.data.items || result.data;
		if (!Array.isArray(items)) {
			return { success: true, data: { items: [] } };
		}

		const chats = items.filter(chat => chat.type === 'single');
		chats.sort((a, b) => {
			const aTime = a.lastActivity || '';
			const bTime = b.lastActivity || '';
			return bTime.localeCompare(aTime);
		});

		return { success: true, data: { items: this.demoMode ? chats.map(c => this._anonymizeChat(c)) : chats } };
	}

	async loadAllChats() {
		const allChats = [];
		let cursor = null;
		let pageCount = 0;

		while (true) {
			pageCount++;
			const params = cursor ? { cursor: cursor, direction: 'before' } : {};
			const result = await this.request('/chats', { params });

			if (!result.success) {
				return result;
			}

			const items = result.data.items || [];
			for (const chat of items) {
				allChats.push(this.demoMode ? this._anonymizeChat(chat) : chat);
			}

			console.log('Page ' + pageCount + ': ' + items.length + ' chats, total: ' + allChats.length);

			if (!result.data.hasMore || !result.data.oldestCursor) {
				break;
			}
			cursor = result.data.oldestCursor;
		}

		return { success: true, data: { items: allChats } };
	}

	async getChatMessages(chatId, limit = 20, cursor = null, direction = 'before') {
		const params = { limit: limit };
		if (cursor) {
			params.cursor = cursor;
			params.direction = direction;
		}

		return this.request('/chats/' + encodeURIComponent(chatId) + '/messages', { params: params });
	}

	async sendMessage(chatId, text) {
		return this.request('/messages', {
			method: 'POST',
			body: { chatID: chatId, text: text }
		});
	}

	async testConnection() {
		const result = await this.getAccounts();

		if (!result.success) {
			return result;
		}

		const accounts = result.data;
		return {
			success: true,
			data: {
				accounts: accounts.length,
				networks: [...new Set(accounts.map(a => a.network))]
			}
		};
	}

	async getLastContactDate(chatId) {
		const result = await this.getChatMessages(chatId, 1);

		if (!result.success || !result.data.items || result.data.items.length === 0) {
			return null;
		}

		return result.data.items[0].timestamp || null;
	}

	async getRecentContext(chatId, limit = 5, cursor = null) {
		const result = await this.getChatMessages(chatId, limit, cursor, 'before');

		if (!result.success) {
			return {
				messages: [],
				has_more: false,
				next_cursor: null,
				error: result.error
			};
		}

		if (!result.data.items || result.data.items.length === 0) {
			return {
				messages: [],
				has_more: false,
				next_cursor: null
			};
		}

		const context = [];
		let lastSortKey = null;

		for (const msg of result.data.items) {
			let text = msg.text || '[attachment]';
			text = text.replace(/https?:\/\/\S+/gi, '');
			text = text.replace(/\s+/g, ' ').trim();

			const rawSender = msg.isSender ? 'You' : (msg.senderName || 'Them');
			const sender = (this.demoMode && !msg.isSender && msg.senderName)
				? this._fakeName(msg.senderName)
				: rawSender;

			context.push({
				date: msg.timestamp,
				sender: sender,
				text: text || '[link]',
				is_sender: msg.isSender,
				sort_key: msg.sortKey || null
			});

			lastSortKey = msg.sortKey || null;
		}

		return {
			messages: context.reverse(),
			has_more: result.data.hasMore || false,
			next_cursor: lastSortKey
		};
	}
}

window.BeeperClient = BeeperClient;
