<?php
/**
 * A minimal WP_Error for the unit tests, which don't load WordPress.
 *
 * @package WP_Privacy\WP_API_Privacy
 */

/**
 * Holds an error code and message, like WordPress's WP_Error.
 */
class WP_Error {

	/**
	 * The error code.
	 *
	 * @var string
	 */
	public $code;

	/**
	 * The error message.
	 *
	 * @var string
	 */
	public $message;

	/**
	 * Creates the error.
	 *
	 * @param string $code    The error code.
	 * @param string $message The error message.
	 */
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
}
