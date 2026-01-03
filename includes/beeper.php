<?php
/**
 * Beeper API Client
 *
 * Integrates with Beeper Desktop API to fetch messaging history.
 */

namespace KeepingContact;

class Beeper {
	private $api_base = 'http://localhost:23373/v1';
	private $token;

	public function __construct() {
		$this->token = get_option( 'keeping_contact_beeper_token', '' );
	}

	public function is_configured() {
		return ! empty( $this->token );
	}

	public function set_token( $token ) {
		$this->token = $token;
		update_option( 'keeping_contact_beeper_token', $token );
	}

	private function request( $endpoint, $params = [] ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'no_token', 'Beeper API token not configured' );
		}

		$url = $this->api_base . $endpoint;
		if ( ! empty( $params ) ) {
			$url .= '?' . http_build_query( $params );
		}

		$response = wp_remote_get( $url, [
			'headers'   => [
				'Authorization' => 'Bearer ' . $this->token,
				'Content-Type'  => 'application/json',
			],
			'timeout'   => 15,
			'sslverify' => false,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code >= 400 ) {
			return new \WP_Error(
				'beeper_api_error',
				$data['message'] ?? 'Beeper API error',
				[ 'status' => $code, 'response' => $data ]
			);
		}

		return $data;
	}

	/**
	 * Get all connected accounts
	 */
	public function get_accounts() {
		return $this->request( '/accounts' );
	}

	/**
	 * Search chats by title/participant name or phone number
	 */
	public function search_chats( $query, $limit = 10 ) {
		$result = $this->request( '/search', [
			'query' => $query,
		] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$chats = [];
		$in_groups = $result['results']['in_groups'] ?? [];

		foreach ( $in_groups as $chat ) {
			if ( ( $chat['type'] ?? '' ) === 'single' ) {
				$chats[] = $chat;
				if ( count( $chats ) >= $limit ) {
					break;
				}
			}
		}

		return [ 'items' => $chats ];
	}

	/**
	 * Get a specific chat by ID
	 */
	public function get_chat( $chat_id ) {
		return $this->request( '/chats/' . urlencode( $chat_id ) );
	}

	/**
	 * Get all chats (1:1 conversations only)
	 */
	public function get_all_chats( $limit = 200 ) {
		$result = $this->request( '/chats', [ 'limit' => $limit ] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$chats = [];
		$items = $result['items'] ?? $result;

		if ( ! is_array( $items ) ) {
			return [ 'items' => [] ];
		}

		foreach ( $items as $chat ) {
			if ( ( $chat['type'] ?? '' ) === 'single' ) {
				$chats[] = $chat;
			}
		}

		usort( $chats, function( $a, $b ) {
			$a_time = $a['lastActivity'] ?? '';
			$b_time = $b['lastActivity'] ?? '';
			return strcmp( $b_time, $a_time );
		} );

		return [ 'items' => $chats ];
	}

	/**
	 * Get messages from a chat with cursor-based pagination
	 *
	 * @param string $chat_id Chat ID
	 * @param int    $limit   Number of messages to fetch
	 * @param string $cursor  Optional cursor (message sortKey) for pagination
	 * @param string $direction 'before' for older messages, 'after' for newer
	 * @return array|WP_Error Response with 'items', 'hasMore', and last item's 'sortKey' for cursor
	 */
	public function get_chat_messages( $chat_id, $limit = 20, $cursor = null, $direction = 'before' ) {
		$params = [ 'limit' => $limit ];

		if ( $cursor ) {
			$params['cursor'] = $cursor;
			$params['direction'] = $direction;
		}

		return $this->request( '/chats/' . urlencode( $chat_id ) . '/messages', $params );
	}

	/**
	 * Search messages across all chats
	 */
	public function search_messages( $query, $params = [] ) {
		$defaults = [
			'q'     => $query,
			'limit' => 20,
		];
		return $this->request( '/messages', array_merge( $defaults, $params ) );
	}

	/**
	 * Find chats that might match a person from the CRM
	 */
	public function find_person_chats( $person ) {
		$search_terms = [];

		if ( ! empty( $person->name ) ) {
			$search_terms[] = $person->name;
		}

		if ( ! empty( $person->nickname ) ) {
			$search_terms[] = $person->nickname;
		}

		$all_chats = [];

		foreach ( $search_terms as $term ) {
			$result = $this->search_chats( $term, 5 );
			if ( ! is_wp_error( $result ) && ! empty( $result['items'] ) ) {
				foreach ( $result['items'] as $chat ) {
					$all_chats[ $chat['id'] ] = $chat;
				}
			}
		}

		return array_values( $all_chats );
	}

	/**
	 * Get last message date from a chat
	 */
	public function get_last_contact_date( $chat_id ) {
		$messages = $this->get_chat_messages( $chat_id, 1 );

		if ( is_wp_error( $messages ) || empty( $messages['items'] ) ) {
			return null;
		}

		$last_message = $messages['items'][0];
		return $last_message['timestamp'] ?? null;
	}

	/**
	 * Get recent messages with a person for context
	 *
	 * @param string $chat_id Chat ID
	 * @param int    $limit   Number of messages to fetch
	 * @param string $cursor  Optional cursor for pagination
	 * @return array Array with 'messages', 'has_more', and 'next_cursor' keys
	 */
	public function get_recent_context( $chat_id, $limit = 5, $cursor = null ) {
		$response = $this->get_chat_messages( $chat_id, $limit, $cursor, 'before' );

		if ( is_wp_error( $response ) || empty( $response['items'] ) ) {
			return [
				'messages'    => [],
				'has_more'    => false,
				'next_cursor' => null,
			];
		}

		$context = [];
		$last_sort_key = null;

		foreach ( $response['items'] as $msg ) {
			$text = $msg['text'] ?? '[attachment]';
			// Remove URLs from message text
			$text = preg_replace( '/https?:\/\/\S+/i', '', $text );
			$text = trim( preg_replace( '/\s+/', ' ', $text ) );

			$context[] = [
				'date'      => $msg['timestamp'],
				'sender'    => $msg['isSender'] ? 'You' : ( $msg['senderName'] ?? 'Them' ),
				'text'      => $text ?: '[link]',
				'is_sender' => $msg['isSender'],
				'sort_key'  => $msg['sortKey'] ?? null,
			];

			$last_sort_key = $msg['sortKey'] ?? null;
		}

		return [
			'messages'    => array_reverse( $context ),
			'has_more'    => $response['hasMore'] ?? false,
			'next_cursor' => $last_sort_key,
		];
	}

	/**
	 * Send a message via Beeper
	 */
	public function send_message( $chat_id, $text ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'no_token', 'Beeper API token not configured' );
		}

		$response = wp_remote_post( $this->api_base . '/messages', [
			'headers'   => [
				'Authorization' => 'Bearer ' . $this->token,
				'Content-Type'  => 'application/json',
			],
			'body'      => json_encode( [
				'chatID' => $chat_id,
				'text'   => $text,
			] ),
			'timeout'   => 15,
			'sslverify' => false,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code >= 400 ) {
			return new \WP_Error(
				'beeper_api_error',
				$data['message'] ?? 'Failed to send message',
				[ 'status' => $code ]
			);
		}

		return $data;
	}

	/**
	 * Test the API connection
	 */
	public function test_connection() {
		$accounts = $this->get_accounts();

		if ( is_wp_error( $accounts ) ) {
			return $accounts;
		}

		return [
			'success'  => true,
			'accounts' => count( $accounts ),
			'networks' => array_unique( array_column( $accounts, 'network' ) ),
		];
	}
}
