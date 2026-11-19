<?php
/**
 * The connector's MCP Server Card: a small public file that tells agents and
 * directories the connector exists, where it answers and which MCP versions it
 * speaks. It lists no tools; clients read those from the server itself.
 *
 * Spec: SEP-2127, MCP Server Cards (Final), and the extension's discovery
 * rules, https://github.com/modelcontextprotocol/experimental-ext-server-card
 * (schema v1, read 18 Nov 2026). Served at three addresses:
 *
 *   /wp-json/christianpure-connector/v1/mcp/server-card   the card, at the place the spec reserves
 *                                                         (<streamable-http-url>/server-card)
 *   /.well-known/ai-catalog.json                          the site's AI Catalog, which points to it
 *   /.well-known/mcp/server-card.json                     the 2025 draft's address (SEP-1649), still read
 *                                                         by Cloudflare's agent-readiness scanner; the same
 *                                                         card plus the draft's serverInfo, transport and
 *                                                         capabilities fields
 *
 * The description is the one in the official MCP registry entry
 * (submission/mcp-registry/server.json); keep the two the same.
 */

defined( 'ABSPATH' ) || exit;

final class CP_Connector_Card {

	const NAME        = 'com.christianpure/connector';
	const TITLE       = 'Christian Pure';
	const DESCRIPTION = 'Read-only lookup of articles, prayers, Bible verses and courses. No account. Run by Christian Pure.';

	const SCHEMA       = 'https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json';
	const CARD_TYPE    = 'application/mcp-server-card+json';
	const CATALOG_TYPE = 'application/ai-catalog+json';

	const CATALOG_PATH     = '/.well-known/ai-catalog.json';
	const LEGACY_CARD_PATH = '/.well-known/mcp/server-card.json';

	/** Seconds a client or the edge may keep a copy, as the discovery rules suggest. */
	const MAX_AGE = 3600;

	public static function init() {
		// At init, not earlier: rest_url() needs the rewrite rules object.
		add_action( 'init', array( __CLASS__, 'serve_well_known' ), 0 );
	}

	/**
	 * The card.
	 *
	 * @param bool $legacy Add the 2025 draft's fields for its old address.
	 * @return array
	 */
	public static function card( $legacy = false ) {
		$mcp  = CP_Connector_REST::url( '/mcp' );
		$card = array(
			'$schema'     => self::SCHEMA,
			'name'        => self::NAME,
			'version'     => CP_CONNECTOR_VERSION,
			'title'       => self::TITLE,
			'description' => self::DESCRIPTION,
			'websiteUrl'  => home_url( '/' ),
			'remotes'     => array(
				array(
					'type'                      => 'streamable-http',
					'url'                       => $mcp,
					'supportedProtocolVersions' => CP_Connector_MCP::PROTOCOL_VERSIONS,
				),
			),
		);

		if ( $legacy ) {
			$card['protocolVersion'] = CP_Connector_MCP::PROTOCOL_VERSIONS[0];
			$card['serverInfo']      = array(
				'name'    => 'christianpure',
				'title'   => self::TITLE,
				'version' => CP_CONNECTOR_VERSION,
			);
			$card['transport']       = array(
				'type'     => 'streamable-http',
				'endpoint' => $mcp,
			);
			$card['capabilities']    = array(
				'tools'     => array( 'listChanged' => false ),
				'resources' => array( 'listChanged' => false ),
			);
			$card['authentication']  = array(
				'required' => false,
				'schemes'  => array(),
			);
		}

		return $card;
	}

	/**
	 * The site's AI Catalog, with the card as its one entry.
	 *
	 * @return array
	 */
	public static function catalog() {
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return array(
			'specVersion' => '1.0',
			'entries'     => array(
				array(
					'identifier' => 'urn:air:' . $host . ':mcp:connector',
					'type'       => self::CARD_TYPE,
					'url'        => CP_Connector_REST::url( '/mcp/server-card' ),
				),
			),
		);
	}

	/** Answers the two .well-known addresses. Every other request passes through. */
	public static function serve_well_known() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( self::CATALOG_PATH === $path ) {
			self::send( self::catalog(), self::CATALOG_TYPE );
		} elseif ( self::LEGACY_CARD_PATH === $path ) {
			self::send( self::card( true ), 'application/json' );
		}
	}

	/**
	 * Sends a document outside the REST API, with the CORS and caching headers
	 * the discovery rules ask for, and ends the request.
	 *
	 * @param array  $document Document.
	 * @param string $type     Media type.
	 */
	private static function send( $document, $type ) {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$body   = wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$etag   = self::etag( $body );

		foreach ( self::headers( $etag ) as $name => $value ) {
			header( $name . ': ' . $value );
		}

		if ( 'OPTIONS' === $method ) {
			status_header( 204 );
			exit;
		}
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			status_header( 405 );
			header( 'Allow: GET, HEAD, OPTIONS' );
			exit;
		}
		if ( self::client_has( $etag ) ) {
			status_header( 304 );
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: ' . $type . '; charset=utf-8' );
		if ( 'HEAD' !== $method ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON built from our own constants and URLs.
		}
		exit;
	}

	/**
	 * The card for the REST route /mcp/server-card.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest( WP_REST_Request $request ) {
		$card = self::card();
		$etag = self::etag( wp_json_encode( $card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		$response = new WP_REST_Response( self::client_has( $etag ) ? null : $card, self::client_has( $etag ) ? 304 : 200 );
		foreach ( self::headers( $etag ) as $name => $value ) {
			$response->header( $name, $value );
		}
		$response->header( 'Content-Type', self::CARD_TYPE . '; charset=utf-8' );
		return $response;
	}

	/**
	 * @param string $etag Entity tag.
	 * @return array<string,string>
	 */
	public static function headers( $etag ) {
		return array(
			'Access-Control-Allow-Origin'   => '*',
			'Access-Control-Allow-Methods'  => 'GET',
			'Access-Control-Allow-Headers'  => 'Content-Type, If-None-Match',
			'Access-Control-Expose-Headers' => 'ETag',
			'Cache-Control'                 => 'public, max-age=' . self::MAX_AGE,
			'ETag'                          => $etag,
			'X-Content-Type-Options'        => 'nosniff',
		);
	}

	/**
	 * @param string $body Document as sent.
	 * @return string Quoted entity tag.
	 */
	private static function etag( $body ) {
		return '"' . substr( hash( 'sha256', $body ), 0, 32 ) . '"';
	}

	/**
	 * Whether the request's If-None-Match names this entity tag.
	 *
	 * @param string $etag Entity tag.
	 * @return bool
	 */
	private static function client_has( $etag ) {
		if ( empty( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) {
			return false;
		}
		$sent = array_map( 'trim', explode( ',', (string) wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return in_array( $etag, $sent, true ) || in_array( 'W/' . $etag, $sent, true ) || in_array( '*', $sent, true );
	}
}
