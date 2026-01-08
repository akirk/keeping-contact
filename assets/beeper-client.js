/**
 * Beeper API Client for browser-side communication
 *
 * Makes direct requests to Beeper Desktop API (localhost:23373)
 */

class BeeperClient {
	constructor(token, apiBase = 'http://localhost:23373/v1') {
		this.token = token;
		this.apiBase = apiBase;
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
				chats.push(chat);
				if (chats.length >= limit) break;
			}
		}

		return { success: true, data: { items: chats } };
	}

	async getChat(chatId) {
		return this.request('/chats/' + encodeURIComponent(chatId));
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

		return { success: true, data: { items: chats } };
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

			context.push({
				date: msg.timestamp,
				sender: msg.isSender ? 'You' : (msg.senderName || 'Them'),
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
