<?php
/**
 * Beeper Token Manager
 *
 * Manages Beeper API token storage per user.
 * All API communication happens client-side via JavaScript.
 */

namespace KeepingContact;

class Beeper {
	private $token;

	public function __construct() {
		$this->token = get_user_meta( get_current_user_id(), 'keeping_contact_beeper_token', true );

		if ( empty( $this->token ) ) {
			$legacy_token = get_option( 'keeping_contact_beeper_token' );
			if ( $legacy_token ) {
				$this->set_token( $legacy_token );
				delete_option( 'keeping_contact_beeper_token' );
			}
		}
	}

	public function is_configured() {
		return ! empty( $this->token );
	}

	public function get_token() {
		return $this->token;
	}

	public function set_token( $token ) {
		$this->token = $token;
		update_user_meta( get_current_user_id(), 'keeping_contact_beeper_token', $token );
	}
}
