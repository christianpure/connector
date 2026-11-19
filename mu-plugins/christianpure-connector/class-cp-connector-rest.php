<?php
/**
 * REST routes for the connector: one GET route per tool, the OpenAPI document,
 * the docs page and the MCP endpoint.
 */

defined( 'ABSPATH' ) || exit;

final class CP_Connector_REST {

	/** Largest MCP request body accepted, in bytes. */
	const MAX_MCP_BODY = 16384;

	/** Option holding the ChatGPT plugin directory's domain verification token. */
	const OPENAI_CHALLENGE_OPTION = 'cp_connector_openai_challenge';

	public static function init() {
		add_action( 'muplugins_loaded', array( __CLASS__, 'serve_openai_challenge' ), 0 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rate_limit' ), 10, 3 );
		// After core's rest_send_allow_header, which is added at 10 during rest_api_init.
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'headers' ), 11, 3 );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_raw' ), 5, 4 );
		add_filter( 'rest_namespace_index', array( __CLASS__, 'namespace_index' ), 10, 2 );
	}

	/**
	 * Answers https://christianpure.com/.well-known/openai-apps-challenge with the
	 * verification token OpenAI's plugin portal issues, and only that token.
	 *
	 * Set it with: wp option update cp_connector_openai_challenge <token>
	 * Until it is set the address stays a 404.
	 */
	public static function serve_openai_challenge() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '/.well-known/openai-apps-challenge' !== untrailingslashit( $path ) ) {
			return;
		}
		$token = trim( (string) get_option( self::OPENAI_CHALLENGE_OPTION, '' ) );
		if ( '' === $token || ! preg_match( '/^[\x21-\x7E]{1,512}$/', $token ) ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex' );
		echo $token; // phpcs:ignore WordPress.Security.EscapeOutput -- printable ASCII, no spaces, checked above.
		exit;
	}

	/**
	 * Public URL of a connector path ('' for the base).
	 *
	 * @param string $path Path starting with '/', or ''.
	 * @return string
	 */
	public static function url( $path ) {
		return untrailingslashit( rest_url( CP_CONNECTOR_NAMESPACE ) ) . $path;
	}

	/**
	 * Registers the routes. Tool definitions (which query the site) load only
	 * when a connector route runs, so other REST requests pay nothing.
	 */
	public static function routes() {
		foreach ( CP_Connector_Tools::ROUTES as $name => $route ) {
			register_rest_route(
				CP_CONNECTOR_NAMESPACE,
				$route,
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => static function ( WP_REST_Request $request ) use ( $name ) {
						return self::run_tool( $name, $request );
					},
					'permission_callback' => '__return_true',
				)
			);
		}

		register_rest_route(
			CP_CONNECTOR_NAMESPACE,
			'/openapi\.json',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function () {
					return rest_ensure_response( CP_Connector_OpenAPI::document() );
				},
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			CP_CONNECTOR_NAMESPACE,
			'/docs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function () {
					$response = new WP_REST_Response( CP_Connector_Docs::html(), 200 );
					$response->header( 'Content-Type', 'text/html; charset=UTF-8' );
					return $response;
				},
				'permission_callback' => '__return_true',
			)
		);

		if ( class_exists( 'CP_Connector_Card', false ) ) {
			// The MCP Server Card, at the place SEP-2127 reserves: <streamable-http-url>/server-card.
			register_rest_route(
				CP_CONNECTOR_NAMESPACE,
				'/mcp/server-card',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( 'CP_Connector_Card', 'rest' ),
					'permission_callback' => '__return_true',
				)
			);
		}

		register_rest_route(
			CP_CONNECTOR_NAMESPACE,
			'/mcp',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'mcp' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'GET, DELETE',
					'callback'            => static function () {
						$response = new WP_REST_Response(
							array(
								'code'    => 'cp_connector_method_not_allowed',
								'message' => 'This MCP server is stateless and offers no event stream. Send JSON-RPC messages with POST.',
								'data'    => array( 'status' => 405 ),
							),
							405
						);
						$response->header( 'Allow', 'POST' );
						return $response;
					},
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Runs a tool for a GET request.
	 *
	 * @param string          $name    Tool name.
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function run_tool( $name, WP_REST_Request $request ) {
		try {
			$output = CP_Connector_Tools::call( $name, $request->get_query_params() );
		} catch ( Throwable $e ) {
			self::count( 'rest', $name, 'failed' );
			throw $e;
		}
		self::count( 'rest', $name, is_wp_error( $output ) ? 'tool_error' : 'ok' );
		return is_wp_error( $output ) ? $output : rest_ensure_response( $output );
	}

	/**
	 * Adds one to a usage count, if the counter is loaded. During a deploy an old
	 * loader can briefly run this code without it, so every use checks first.
	 *
	 * @param string $channel mcp or rest.
	 * @param string $name    Tool, method or other counted name.
	 * @param string $outcome Outcome.
	 */
	private static function count( $channel, $name, $outcome ) {
		if ( ! class_exists( 'CP_Connector_Usage', false ) ) {
			return;
		}
		try {
			CP_Connector_Usage::record( $channel, $name, $outcome );
		} catch ( Throwable $e ) {
			error_log( 'christianpure-connector: usage count failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * The MCP endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function mcp( WP_REST_Request $request ) {
		$origin = (string) $request->get_header( 'origin' );
		if ( '' !== $origin && ! self::is_valid_origin( $origin ) ) {
			self::count( 'mcp', '(unread)', 'refused_origin' );
			return self::mcp_refusal( 403, 'Forbidden: the Origin header is not a valid web origin.' );
		}

		if ( strlen( (string) $request->get_body() ) > self::MAX_MCP_BODY ) {
			self::count( 'mcp', '(unread)', 'refused_size' );
			return self::mcp_refusal( 413, sprintf( 'Request too large: the limit is %d bytes.', self::MAX_MCP_BODY ) );
		}

		$payload = $request->get_json_params();
		if ( null === $payload ) {
			$payload = json_decode( (string) $request->get_body(), true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$payload = null;
			}
		}

		// A version this server does not speak gets 2026-07-28's UnsupportedProtocolVersionError,
		// which lists every version it does speak, newest first, so the client can pick one.
		$version = (string) $request->get_header( 'mcp_protocol_version' );
		if ( '' !== $version && ! in_array( $version, CP_Connector_MCP::PROTOCOL_VERSIONS, true ) ) {
			if ( class_exists( 'CP_Connector_Usage', false ) ) {
				self::count( 'mcp', CP_Connector_Usage::version_name( $version ), 'refused_version' );
			}
			$answer = CP_Connector_MCP::unsupported_version( $payload, $version );
			return new WP_REST_Response( $answer['body'], $answer['status'] );
		}

		if ( null === $payload ) {
			self::count( 'mcp', '(unread)', 'refused_parse' );
			return new WP_REST_Response( CP_Connector_MCP::error( null, -32700, 'Parse error: the body must be a JSON-RPC message.' ), 400 );
		}

		// The era. A modern version in the header means 2026-07-28, served statelessly from
		// each request's own metadata; initialize, or a legacy version, or no header at all,
		// means the initialize-based versions, answered exactly as before 0.2.7.
		$method = is_array( $payload ) && isset( $payload['method'] ) && is_string( $payload['method'] ) ? $payload['method'] : '';
		if ( in_array( $version, CP_Connector_MCP::MODERN_VERSIONS, true ) && 'initialize' !== $method && method_exists( 'CP_Connector_MCP', 'handle_modern' ) ) {
			$answer = CP_Connector_MCP::handle_modern( $payload, $version, $request->get_header( 'mcp_method' ), $request->get_header( 'mcp_name' ) );
		} else {
			$answer = CP_Connector_MCP::handle( $payload );
		}

		if ( class_exists( 'CP_Connector_Usage', false ) ) {
			// Counting must never cost the client its answer.
			try {
				$failed = method_exists( 'CP_Connector_MCP', 'had_failure' ) && CP_Connector_MCP::had_failure();
				CP_Connector_Usage::record_mcp( $payload, $answer, $failed );
			} catch ( Throwable $e ) {
				error_log( 'christianpure-connector: usage count failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
		return new WP_REST_Response( $answer['body'], $answer['status'] );
	}

	/**
	 * A JSON-RPC error with an HTTP status, for requests refused before parsing.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Message.
	 * @return WP_REST_Response
	 */
	private static function mcp_refusal( $status, $message ) {
		return new WP_REST_Response( CP_Connector_MCP::error( null, -32600, $message ), $status );
	}

	/**
	 * Whether an Origin header names a real web origin (scheme and host).
	 *
	 * The server is public and read-only and sends no credentials, so any
	 * well-formed origin is allowed; "null" and malformed values are not.
	 *
	 * @param string $origin Origin header.
	 * @return bool
	 */
	private static function is_valid_origin( $origin ) {
		$parts = wp_parse_url( $origin );
		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			&& '' !== $parts['host'];
	}

	/**
	 * Per-address rate limit across the connector's routes.
	 *
	 * The address is hashed before it is used as a cache key, so no address is stored.
	 *
	 * @param mixed           $result  Response so far.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function rate_limit( $result, $server, $request ) {
		if ( null !== $result || ! self::is_connector( $request ) ) {
			return $result;
		}

		$limit = (int) apply_filters( 'cp_connector_rate_limit', CP_CONNECTOR_RATE_LIMIT );
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( $limit < 1 || '' === $ip ) {
			return $result;
		}

		$window = (int) floor( time() / 60 );
		$key    = 'rate:' . md5( $ip ) . ':' . $window;
		wp_cache_add( $key, 0, CP_Connector_Content::CACHE_GROUP, 120 );
		$count = wp_cache_incr( $key, 1, CP_Connector_Content::CACHE_GROUP );

		if ( false !== $count && $count > $limit ) {
			$route = untrailingslashit( $request->get_route() );
			self::count( '/' . CP_CONNECTOR_NAMESPACE . '/mcp' === $route ? 'mcp' : 'rest', '(any)', 'rate_limited' );
			$response = new WP_REST_Response(
				array(
					'code'    => 'cp_connector_rate_limited',
					'message' => 'Too many requests from this address. Try again in a minute.',
					'data'    => array( 'status' => 429 ),
				),
				429
			);
			$response->header( 'Retry-After', (string) ( 60 - time() % 60 ) );
			return $response;
		}

		return $result;
	}

	/**
	 * Caching and safety headers on connector responses.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_REST_Response
	 */
	public static function headers( $response, $server, $request ) {
		if ( ! self::is_connector( $request ) || ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		$response->header( 'X-Content-Type-Options', 'nosniff' );
		$response->header( 'X-Robots-Tag', 'noindex' );

		// A route that sets its own caching (the server card) keeps it.
		$own_headers = $response->get_headers();
		if ( ! isset( $own_headers['Cache-Control'] ) ) {
			$cacheable = in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) && 200 === $response->get_status();
			$response->header( 'Cache-Control', $cacheable ? 'public, max-age=300' : 'no-store' );
		}

		// Core's rest_send_allow_header lists every registered method, including the
		// GET and DELETE handlers that exist only to refuse. MCP accepts POST alone.
		if ( 405 === $response->get_status() && '/' . CP_CONNECTOR_NAMESPACE . '/mcp' === untrailingslashit( $request->get_route() ) ) {
			$response->header( 'Allow', 'POST' );
		}

		return $response;
	}

	/**
	 * Sends the docs page as HTML and MCP notifications as an empty 202.
	 *
	 * Status and headers are already sent when this filter runs.
	 *
	 * @param bool             $served  Whether the request was served.
	 * @param WP_REST_Response $result  Response.
	 * @param WP_REST_Request  $request Request.
	 * @param WP_REST_Server   $server  Server.
	 * @return bool
	 */
	public static function serve_raw( $served, $result, $request, $server ) {
		if ( $served || ! self::is_connector( $request ) || ! $result instanceof WP_REST_Response ) {
			return $served;
		}

		$route = untrailingslashit( $request->get_route() );

		if ( '/' . CP_CONNECTOR_NAMESPACE . '/docs' === $route && 200 === $result->get_status() && is_string( $result->get_data() ) ) {
			if ( 'HEAD' !== $request->get_method() ) {
				echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts in CP_Connector_Docs.
			}
			return true;
		}

		if ( '/' . CP_CONNECTOR_NAMESPACE . '/mcp' === $route && 202 === $result->get_status() ) {
			return true;
		}

		if ( '/' . CP_CONNECTOR_NAMESPACE . '/mcp/server-card' === $route && 304 === $result->get_status() ) {
			return true; // Not Modified carries no body.
		}

		return $served;
	}

	/**
	 * Adds links to the namespace index at /wp-json/christianpure-connector/v1.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_REST_Response
	 */
	public static function namespace_index( $response, $request ) {
		$data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['namespace'] ) || CP_CONNECTOR_NAMESPACE !== $data['namespace'] ) {
			return $response;
		}

		$response->set_data(
			array_merge(
				array(
					'name'        => 'Christian Pure API',
					'version'     => CP_CONNECTOR_VERSION,
					'description' => 'Read-only access to Christian Pure articles, prayers, Bible verses and courses. No account or key needed.',
					'docs'        => self::url( '/docs' ),
					'openapi'     => self::url( '/openapi.json' ),
					'mcp'         => self::url( '/mcp' ),
					'server_card' => self::url( '/mcp/server-card' ),
				),
				$data
			)
		);
		return $response;
	}

	/**
	 * Whether a request is for one of the connector's routes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private static function is_connector( $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return false;
		}
		$route = $request->get_route();
		$base  = '/' . CP_CONNECTOR_NAMESPACE;
		return $route === $base || 0 === strpos( $route, $base . '/' );
	}
}
