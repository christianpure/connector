<?php
/**
 * Plugin Name: Christian Pure Connector
 * Description: Read-only public API and MCP server for AI assistants (Meta Muse and any MCP or OpenAPI client). Returns what a logged-out reader sees — titles, excerpts, summaries and links — and never a members-only article body.
 * Version: 0.2.7
 * Author: Christian Pure
 *
 * Endpoints, all under /wp-json/christianpure-connector/v1:
 *   GET  /                index of the connector
 *   GET  /search, /prayers, /bible-verses, /article, /topics, /courses, /newsletter
 *   GET  /openapi.json    OpenAPI 3.1 description of the GET routes
 *   GET  /docs            human-readable documentation (HTML)
 *   POST /mcp             MCP server, Streamable HTTP transport, stateless; article tools
 *                         link the picture-card view ui://christianpure/cards-v1.html (MCP Apps).
 *                         Dual-era: MCP 2026-07-28 (per-request metadata, server/discover)
 *                         and 2025-11-25 and older (initialize), on the same endpoint
 *   GET  /mcp/server-card  the MCP Server Card (SEP-2127)
 * Also: /.well-known/openai-apps-challenge answers the option cp_connector_openai_challenge;
 * /.well-known/ai-catalog.json lists the server card and /.well-known/mcp/server-card.json
 * serves it at the 2025 draft's address.
 *
 * Usage counts (no personal data): `wp cp-connector usage [--days=7]`.
 * Off switch for the counts alone: `wp option update cp_connector_usage_off 1`.
 *
 * Kill switch: `wp option update cp_connector_disabled 1` unregisters every route.
 * Rollback: delete this file and the christianpure-connector/ directory beside it.
 */

defined( 'ABSPATH' ) || exit;

define( 'CP_CONNECTOR_VERSION', '0.2.7' );
define( 'CP_CONNECTOR_NAMESPACE', 'christianpure-connector/v1' );

if ( ! defined( 'CP_CONNECTOR_RATE_LIMIT' ) ) {
	// Requests per minute per client address, across every connector route.
	define( 'CP_CONNECTOR_RATE_LIMIT', 120 );
}

// mu-plugins load on every request, so a missing class file must switch the
// connector off, not take the site down. On 21 Sep 2026 an upload that wrote
// this file before its folder caused eight fatal errors in five seconds.
$cp_connector_files = array();
foreach ( array( 'content', 'tools', 'widget', 'mcp', 'openapi', 'docs', 'rest', 'usage', 'card' ) as $cp_connector_part ) {
	$cp_connector_file = __DIR__ . '/christianpure-connector/class-cp-connector-' . $cp_connector_part . '.php';
	if ( ! is_readable( $cp_connector_file ) ) {
		unset( $cp_connector_files, $cp_connector_part, $cp_connector_file );
		return;
	}
	$cp_connector_files[] = $cp_connector_file;
}
foreach ( $cp_connector_files as $cp_connector_file ) {
	require_once $cp_connector_file;
}
unset( $cp_connector_files, $cp_connector_part, $cp_connector_file );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	CP_Connector_Usage::cli();
}

if ( ! get_option( 'cp_connector_disabled' ) ) {
	CP_Connector_REST::init();
	CP_Connector_Card::init();
}
