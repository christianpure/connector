<?php
/**
 * A stateless MCP server over the Streamable HTTP transport, dual-era.
 *
 * Each POST carries one JSON-RPC message and gets one JSON answer. The server
 * never opens an SSE stream, never sends requests to the client and keeps no
 * session, which the transport allows for a server with no server-initiated
 * messages.
 *
 * Two eras on the one endpoint (since 0.2.7, 18 Nov 2026):
 * - Legacy (2025-11-25 and older): the client opens with initialize. handle()
 *   answers exactly as 0.2.6 did.
 * - Modern (2026-07-28): no initialize; every request carries its version in
 *   the MCP-Protocol-Version header and in params._meta. handle_modern() checks
 *   the headers against the body, answers server/discover, adds resultType (and
 *   ttlMs and cacheScope where the schema requires them) to every result, and
 *   answers an unknown method with HTTP 404.
 * CP_Connector_REST::mcp() picks the era from the header and the method.
 *
 * Spec: https://modelcontextprotocol.io/specification/2025-11-25/basic/transports
 *       https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning
 */

defined( 'ABSPATH' ) || exit;

final class CP_Connector_MCP {

	/** Every version this server speaks, newest first: the server card and server/discover list these, and an unknown version is refused with this list. */
	const PROTOCOL_VERSIONS = array( '2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );

	/** Versions with per-request metadata and no initialize, newest first. */
	const MODERN_VERSIONS = array( '2026-07-28' );

	/** Versions that open with initialize, newest first. The first answers an initialize that asks for a version we do not know. */
	const LEGACY_VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );

	/** 2026-07-28 error codes. */
	const HEADER_MISMATCH              = -32020;
	const UNSUPPORTED_PROTOCOL_VERSION = -32022;

	/** How long a modern client may keep a list, a read or the discover answer: an hour, like the server card. Nothing in them is per person. */
	const TTL_MS = 3600000;

	/** Request metadata keys of 2026-07-28. */
	const META_VERSION      = 'io.modelcontextprotocol/protocolVersion';
	const META_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
	const META_SERVER       = 'io.modelcontextprotocol/serverInfo';

	/** @var bool Whether a tool threw while answering this request, for the usage count. */
	private static $failed = false;

	/**
	 * Whether a tool threw while answering this request.
	 *
	 * @return bool
	 */
	public static function had_failure() {
		return self::$failed;
	}

	/**
	 * Answers a decoded POST body.
	 *
	 * One JSON-RPC message per request, as the Streamable HTTP transport
	 * requires. Batches (JSON arrays) are refused: they would let one request
	 * carry any number of searches past the rate limit.
	 *
	 * @param mixed $payload Decoded JSON.
	 * @return array{status:int, body:mixed} A null body means 202 Accepted with no content.
	 */
	public static function handle( $payload ) {
		if ( is_array( $payload ) && $payload && array_keys( $payload ) === range( 0, count( $payload ) - 1 ) ) {
			return array(
				'status' => 400,
				'body'   => self::error( null, -32600, 'Invalid Request: batches are not supported. Send one JSON-RPC message per request.' ),
			);
		}

		$response = self::handle_message( $payload );
		return null === $response ? array(
			'status' => 202,
			'body'   => null,
		) : array(
			'status' => 200,
			'body'   => $response,
		);
	}

	/**
	 * Answers one JSON-RPC message, or returns null for notifications and responses.
	 *
	 * @param mixed $message Decoded message.
	 * @return array|null
	 */
	private static function handle_message( $message ) {
		if ( ! is_array( $message ) || ! isset( $message['jsonrpc'] ) || '2.0' !== $message['jsonrpc'] ) {
			return self::error( null, -32600, 'Invalid Request: expected a JSON-RPC 2.0 message.' );
		}

		$has_id = array_key_exists( 'id', $message );
		$method = isset( $message['method'] ) ? $message['method'] : null;

		if ( ! is_string( $method ) ) {
			if ( $has_id && ( array_key_exists( 'result', $message ) || array_key_exists( 'error', $message ) ) ) {
				return null; // A response to a request we never sent.
			}
			return self::error( $has_id ? $message['id'] : null, -32600, 'Invalid Request: missing method.' );
		}

		if ( ! $has_id ) {
			return null; // Notifications such as notifications/initialized need no answer.
		}

		$id = $message['id'];
		if ( ! is_string( $id ) && ! is_int( $id ) ) {
			return self::error( null, -32600, 'Invalid Request: id must be a string or an integer.' );
		}

		$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();

		if ( 'initialize' === $method ) {
			return self::result( $id, self::initialize( $params ) );
		}
		$answer = self::answer( $id, $method, $params );
		return null === $answer ? self::error( $id, -32601, sprintf( 'Method not found: %s', $method ) ) : $answer;
	}

	/**
	 * Answers a method both eras share, with the same result in each.
	 *
	 * @param string|int $id     Request id.
	 * @param string     $method Method.
	 * @param array      $params Params.
	 * @return array|null A JSON-RPC response, or null when the method is not one of these.
	 */
	private static function answer( $id, $method, $params ) {
		switch ( $method ) {
			case 'ping':
				return self::result( $id, new stdClass() );
			case 'tools/list':
				return self::result( $id, array( 'tools' => self::tool_list() ) );
			case 'tools/call':
				return self::call_tool( $id, $params );
			case 'resources/list':
				return self::result( $id, array( 'resources' => self::has_widget() ? array( CP_Connector_Widget::descriptor() ) : array() ) );
			case 'resources/templates/list':
				return self::result( $id, array( 'resourceTemplates' => array() ) );
			case 'resources/read':
				$uri = isset( $params['uri'] ) && is_string( $params['uri'] ) ? $params['uri'] : '';
				if ( ! self::has_widget() || CP_Connector_Widget::URI !== $uri ) {
					return self::error( $id, -32602, sprintf( 'Unknown resource: %s', $uri ) );
				}
				return self::result( $id, array( 'contents' => CP_Connector_Widget::contents() ) );
			default:
				return null;
		}
	}

	/**
	 * Answers a 2026-07-28 request: one JSON-RPC message whose MCP-Protocol-Version
	 * header named a modern version (the caller has checked it is one we speak).
	 *
	 * @param mixed       $payload Decoded JSON body.
	 * @param string      $version The MCP-Protocol-Version header.
	 * @param string|null $method  The Mcp-Method header, or null when absent.
	 * @param string|null $name    The Mcp-Name header, or null when absent.
	 * @return array{status:int, body:mixed} A null body means 202 Accepted with no content.
	 */
	public static function handle_modern( $payload, $version, $method, $name ) {
		if ( is_array( $payload ) && $payload && array_keys( $payload ) === range( 0, count( $payload ) - 1 ) ) {
			return self::status( 400, self::error( null, -32600, 'Invalid Request: batches are not supported. Send one JSON-RPC message per request.' ) );
		}
		if ( ! is_array( $payload ) || ! isset( $payload['jsonrpc'] ) || '2.0' !== $payload['jsonrpc'] ) {
			return self::status( 400, self::error( null, -32600, 'Invalid Request: expected a JSON-RPC 2.0 message.' ) );
		}

		$has_id      = array_key_exists( 'id', $payload );
		$body_method = isset( $payload['method'] ) ? $payload['method'] : null;
		if ( ! is_string( $body_method ) ) {
			if ( $has_id && ( array_key_exists( 'result', $payload ) || array_key_exists( 'error', $payload ) ) ) {
				return self::status( 202, null ); // A response to a request we never sent.
			}
			return self::status( 400, self::error( $has_id ? $payload['id'] : null, -32600, 'Invalid Request: missing method.' ) );
		}
		if ( ! $has_id ) {
			return self::status( 202, null ); // 2026-07-28 defines no client notifications over HTTP and no headers for them.
		}
		$id = $payload['id'];
		if ( ! is_string( $id ) && ! is_int( $id ) ) {
			return self::status( 400, self::error( null, -32600, 'Invalid Request: id must be a string or an integer.' ) );
		}

		$params = isset( $payload['params'] ) && is_array( $payload['params'] ) ? $payload['params'] : array();

		// The headers mirror the body so that a gateway can route on them; a mismatch is refused.
		if ( null === $method || '' === $method ) {
			return self::mismatch( $id, 'Header mismatch: the Mcp-Method header is missing.' );
		}
		if ( $method !== $body_method ) {
			return self::mismatch( $id, sprintf( "Header mismatch: Mcp-Method header value '%s' does not match body value '%s'.", self::clip( $method ), self::clip( $body_method ) ) );
		}
		$meta = isset( $params['_meta'] ) && is_array( $params['_meta'] ) ? $params['_meta'] : array();
		if ( isset( $meta[ self::META_VERSION ] ) && $meta[ self::META_VERSION ] !== $version ) {
			return self::mismatch( $id, sprintf( "Header mismatch: MCP-Protocol-Version header value '%s' does not match body value '%s'.", self::clip( $version ), self::clip( is_string( $meta[ self::META_VERSION ] ) ? $meta[ self::META_VERSION ] : wp_json_encode( $meta[ self::META_VERSION ] ) ) ) );
		}
		$name_field = in_array( $body_method, array( 'tools/call', 'prompts/get' ), true ) ? 'name' : ( 'resources/read' === $body_method ? 'uri' : '' );
		if ( '' !== $name_field && isset( $params[ $name_field ] ) && is_string( $params[ $name_field ] ) ) {
			if ( null === $name || '' === $name ) {
				return self::mismatch( $id, sprintf( 'Header mismatch: the Mcp-Name header is missing; %s needs it.', $body_method ) );
			}
			$decoded = self::header_value( $name );
			if ( null === $decoded ) {
				return self::mismatch( $id, 'Header mismatch: the Mcp-Name header is malformed.' );
			}
			if ( $decoded !== $params[ $name_field ] ) {
				return self::mismatch( $id, sprintf( "Header mismatch: Mcp-Name header value '%s' does not match body value '%s'.", self::clip( $decoded ), self::clip( $params[ $name_field ] ) ) );
			}
		}

		// Required per-request metadata. A request without it is malformed: -32602 with a 400.
		if ( ! isset( $meta[ self::META_VERSION ] ) ) {
			return self::status( 400, self::error( $id, -32602, sprintf( 'Invalid params: _meta["%s"] is required.', self::META_VERSION ) ) );
		}
		if ( ! array_key_exists( self::META_CAPABILITIES, $meta ) || ! is_array( $meta[ self::META_CAPABILITIES ] ) ) {
			return self::status( 400, self::error( $id, -32602, sprintf( 'Invalid params: _meta["%s"] is required (an empty object declares none).', self::META_CAPABILITIES ) ) );
		}

		if ( 'server/discover' === $body_method ) {
			return self::status( 200, self::result( $id, self::discover() ) );
		}
		$answer = self::answer( $id, $body_method, $params );
		if ( null === $answer ) {
			return self::status( 404, self::error( $id, -32601, sprintf( 'Method not found: %s', $body_method ) ) );
		}
		$cacheable = in_array( $body_method, array( 'tools/list', 'resources/list', 'resources/templates/list', 'resources/read' ), true );
		return self::status( 200, self::modern_result( $answer, $cacheable ) );
	}

	/**
	 * The answer to a request whose MCP-Protocol-Version header names a version this server does not speak.
	 *
	 * @param mixed  $payload Decoded body, for its id.
	 * @param string $version The requested version.
	 * @return array{status:int, body:mixed}
	 */
	public static function unsupported_version( $payload, $version ) {
		$id = is_array( $payload ) && isset( $payload['id'] ) && ( is_string( $payload['id'] ) || is_int( $payload['id'] ) ) ? $payload['id'] : null;
		$error = self::error( $id, self::UNSUPPORTED_PROTOCOL_VERSION, 'Unsupported protocol version' );
		$error['error']['data'] = array(
			'supported' => self::PROTOCOL_VERSIONS,
			'requested' => $version,
		);
		return self::status( 400, $error );
	}

	/**
	 * Adds the fields 2026-07-28 requires on a result: resultType, the server's
	 * name in _meta, and caching hints on lists and reads. Errors pass unchanged.
	 *
	 * @param array $response  JSON-RPC response.
	 * @param bool  $cacheable Whether the result is a list or a read.
	 * @return array
	 */
	private static function modern_result( $response, $cacheable ) {
		if ( ! isset( $response['result'] ) ) {
			return $response;
		}
		$result = array( 'resultType' => 'complete' ) + (array) $response['result'];
		if ( $cacheable ) {
			$result['ttlMs']      = self::TTL_MS;
			$result['cacheScope'] = 'public';
		}
		$meta                       = isset( $result['_meta'] ) && is_array( $result['_meta'] ) ? $result['_meta'] : array();
		$meta[ self::META_SERVER ]  = self::server_info();
		$result['_meta']            = $meta;
		$response['result']         = $result;
		return $response;
	}

	/**
	 * The server/discover result.
	 *
	 * @return array
	 */
	private static function discover() {
		return array(
			'resultType'        => 'complete',
			'supportedVersions' => self::PROTOCOL_VERSIONS,
			'capabilities'      => self::capabilities(),
			'instructions'      => self::instructions(),
			'ttlMs'             => self::TTL_MS,
			'cacheScope'        => 'public',
			'_meta'             => array( self::META_SERVER => self::server_info() ),
		);
	}

	/**
	 * Decodes a header value that may use the Base64 sentinel =?base64?...?=.
	 *
	 * @param string $value Header value.
	 * @return string|null The value, or null when it is malformed.
	 */
	private static function header_value( $value ) {
		if ( preg_match( '/^=\?base64\?(.*)\?=$/s', $value, $m ) ) {
			$decoded = base64_decode( $m[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the transport's own encoding.
			return false === $decoded ? null : $decoded;
		}
		// A plain value is visible ASCII, spaces and tabs only.
		return preg_match( '/^[\x20-\x7E\t]*$/', $value ) ? $value : null;
	}

	/**
	 * A 400 HeaderMismatch answer.
	 *
	 * @param string|int $id      Request id.
	 * @param string     $message Message.
	 * @return array{status:int, body:mixed}
	 */
	private static function mismatch( $id, $message ) {
		return self::status( 400, self::error( $id, self::HEADER_MISMATCH, $message ) );
	}

	/**
	 * @param int   $status HTTP status.
	 * @param mixed $body   Body, or null for none.
	 * @return array{status:int, body:mixed}
	 */
	private static function status( $status, $body ) {
		return array(
			'status' => $status,
			'body'   => $body,
		);
	}

	/**
	 * A value shortened for an error message.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function clip( $value ) {
		$value = (string) $value;
		return strlen( $value ) > 120 ? substr( $value, 0, 120 ) . '...' : $value;
	}

	/**
	 * Whether the picture-card view is loaded. During a deploy an old loader can
	 * briefly run the new code without it, so every use checks first.
	 *
	 * @return bool
	 */
	private static function has_widget() {
		return class_exists( 'CP_Connector_Widget', false );
	}

	/**
	 * The initialize result.
	 *
	 * @param array $params Client params.
	 * @return array
	 */
	private static function initialize( $params ) {
		$requested = isset( $params['protocolVersion'] ) && is_string( $params['protocolVersion'] ) ? $params['protocolVersion'] : '';
		$version   = in_array( $requested, self::LEGACY_VERSIONS, true ) ? $requested : self::LEGACY_VERSIONS[0];

		return array(
			'protocolVersion' => $version,
			'capabilities'    => self::capabilities(),
			'serverInfo'      => self::server_info(),
			'instructions'    => self::instructions(),
		);
	}

	/**
	 * What the server offers, the same in both eras.
	 *
	 * @return array
	 */
	private static function capabilities() {
		return array(
			'tools'     => array( 'listChanged' => false ),
			'resources' => array( 'listChanged' => false ),
		);
	}

	/**
	 * The server's name and version: serverInfo at initialize, _meta serverInfo on every modern result.
	 *
	 * @return array
	 */
	private static function server_info() {
		return array(
			'name'       => 'christianpure',
			'title'      => 'Christian Pure',
			'version'    => CP_CONNECTOR_VERSION,
			'websiteUrl' => home_url( '/' ),
		);
	}

	/**
	 * What the server and its results contain, sent at initialize and in server/discover.
	 *
	 * It describes and never instructs: Anthropic's directory review (24 Oct
	 * 2026) flagged "credit Christian Pure" and "quote Scripture only as the
	 * results give them" as imperatives aimed at the assistant, which can
	 * conflict with the user's own requests. Kept under 512 characters, the
	 * length OpenAI asks for.
	 *
	 * @return string
	 */
	public static function instructions() {
		return 'Christian Pure (christianpure.com) publishes Christian articles on prayer, Bible verses, Bible study, names in the Bible and church history, plus free courses and a weekly email. Article results give each article\'s title, link, excerpt, summary points, topics, image and dates, not the full text, which is on christianpure.com. Excerpts and summary points are the article\'s own published words, so any Scripture in them appears as the article quotes it. Every tool is read-only and needs no account.';
	}

	/**
	 * The tools/list entries.
	 *
	 * @return array[]
	 */
	private static function tool_list() {
		$list = array();
		foreach ( CP_Connector_Tools::all() as $tool ) {
			$entry = array(
				'name'        => $tool['name'],
				'title'       => $tool['title'],
				'description' => $tool['description'],
				'inputSchema' => CP_Connector_Tools::input_schema( $tool ),
				'annotations' => array(
					'title'           => $tool['title'],
					'readOnlyHint'    => true,
					'destructiveHint' => false,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			);
			if ( self::has_widget() && in_array( $tool['name'], CP_Connector_Tools::CARD_TOOLS, true ) ) {
				$entry['_meta'] = CP_Connector_Widget::tool_meta();
			}
			$list[] = $entry;
		}
		return $list;
	}

	/**
	 * Runs a tool. Bad arguments come back as a tool error the model can read and correct.
	 *
	 * @param string|int $id     Request id.
	 * @param array      $params Call params.
	 * @return array
	 */
	private static function call_tool( $id, $params ) {
		$name = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';
		if ( '' === $name || ! CP_Connector_Tools::get( $name ) ) {
			return self::error( $id, -32602, sprintf( 'Unknown tool: %s', $name ) );
		}

		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		try {
			$output = CP_Connector_Tools::call( $name, $arguments );
		} catch ( Throwable $e ) {
			self::$failed = true;
			error_log( sprintf( 'christianpure-connector: %s failed: %s', $name, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$output = new WP_Error( 'cp_connector_failed', 'The tool failed. Try again, or send the reader to christianpure.com.' );
		}

		if ( is_wp_error( $output ) ) {
			return self::result(
				$id,
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => $output->get_error_message(),
						),
					),
					'isError' => true,
				)
			);
		}

		return self::result(
			$id,
			array(
				'content'           => array(
					array(
						'type' => 'text',
						'text' => wp_json_encode( $output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
					),
				),
				'structuredContent' => $output,
				'isError'           => false,
			)
		);
	}

	/**
	 * @param string|int $id     Request id.
	 * @param mixed      $result Result.
	 * @return array
	 */
	private static function result( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * @param string|int|null $id      Request id.
	 * @param int             $code    JSON-RPC error code.
	 * @param string          $message Message.
	 * @return array
	 */
	public static function error( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
