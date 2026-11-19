<?php
/**
 * Counts of connector use: how often each tool is called, by which kind of
 * client, and how each request ended.
 *
 * Nothing about the person is kept: no address, no query, no argument. A count
 * is the UTC day, a client family read from the User-Agent (claude, chatgpt,
 * robot, test, other), the channel (mcp or rest), the tool or method name and
 * the outcome. Each count is one wp_options row named
 *
 *   cp_conn_use_<YYYYMMDD>|<client>|<channel>|<name>|<outcome>
 *
 * raised by a single INSERT ... ON DUPLICATE KEY UPDATE, so two requests at the
 * same moment both count. Every value comes from a fixed list, so a client
 * cannot create rows of its choosing. Rows older than KEEP_DAYS are deleted
 * once a day. Read them with `wp cp-connector usage`.
 *
 * Only requests that reach PHP are counted. The edge caches the public GET
 * routes for five minutes, so a repeated REST lookup can be answered without
 * being counted; MCP requests are POSTs and are never cached.
 *
 * Off switch: `wp option update cp_connector_usage_off 1` stops counting.
 */

defined( 'ABSPATH' ) || exit;

final class CP_Connector_Usage {

	const PREFIX = 'cp_conn_use_';

	/** Days of counts kept. */
	const KEEP_DAYS = 400;

	/** Holds the day of the last clean-up. Outside PREFIX, so reports never read it. */
	const PRUNED_OPTION = 'cp_connector_usage_pruned';

	const OFF_OPTION = 'cp_connector_usage_off';

	const CLIENTS = array( 'claude', 'chatgpt', 'robot', 'test', 'other' );

	const CHANNELS = array( 'mcp', 'rest' );

	/** MCP methods counted by name, in either era. tools/call is counted under the tool's own name. */
	const METHODS = array( 'initialize', 'server/discover', 'ping', 'tools/list', 'resources/list', 'resources/templates/list', 'resources/read' );

	/** Names that are neither a tool nor a method above. */
	const OTHER_NAMES = array( 'notification', 'unknown_tool', 'other_method', 'batch', '(none)', '(unread)', '(any)' );

	/**
	 * How a request ended.
	 *
	 * ok               answered normally
	 * tool_error       the tool answered with an error the assistant can read (a bad argument, an article not found)
	 * failed           the tool threw and the connector answered "The tool failed"
	 * rpc_error        a JSON-RPC error: unknown tool or method, or a malformed message
	 * refused_header   400: a 2026-07-28 request whose Mcp-Method, Mcp-Name or MCP-Protocol-Version header did not match its body (-32020)
	 * refused_version  400: the client asked for an MCP version this server does not speak (-32022; until 0.2.7, also 2026-07-28 itself)
	 * refused_parse    400: the body was not JSON
	 * refused_batch    400: several messages in one request
	 * refused_origin   403: a malformed Origin header
	 * refused_size     413: the body was too large
	 * rate_limited     429 from the connector's own per-address limit
	 */
	const OUTCOMES = array( 'ok', 'tool_error', 'failed', 'rpc_error', 'refused_header', 'refused_version', 'refused_parse', 'refused_batch', 'refused_origin', 'refused_size', 'rate_limited' );

	/** Outcomes that are not failures: a version refusal is the answer the MCP specification prescribes, and the client retries with a version from its list. */
	const NOT_ERRORS = array( 'ok', 'refused_version' );

	/**
	 * Versions newer than this server speaks that are named in full when a client asks for one; any other refused
	 * version counts as v-newer or v-other. Empty since 0.2.7, which speaks 2026-07-28: until then each Claude
	 * conversation began with one refused v2026-07-28 request, and those rows stay readable in reports.
	 */
	const KNOWN_NEWER_VERSIONS = array();

	/**
	 * Adds one to a count.
	 *
	 * @param string $channel mcp or rest.
	 * @param string $name    Tool, method or one of OTHER_NAMES, or a version name for refused_version.
	 * @param string $outcome One of OUTCOMES.
	 */
	public static function record( $channel, $name, $outcome ) {
		if ( get_option( self::OFF_OPTION ) ) {
			return;
		}
		if ( ! in_array( $channel, self::CHANNELS, true ) || ! in_array( $outcome, self::OUTCOMES, true ) || ! self::is_known_name( $name ) ) {
			return;
		}

		global $wpdb;
		$key = self::PREFIX . gmdate( 'Ymd' ) . '|' . self::client() . '|' . $channel . '|' . $name . '|' . $outcome;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'off') ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				$key
			)
		);
		self::maybe_prune();
	}

	/**
	 * Counts one MCP request once its answer is known.
	 *
	 * @param mixed $payload Decoded request body.
	 * @param array $answer  CP_Connector_MCP::handle() result: status and body.
	 * @param bool  $failed  Whether a tool threw while answering.
	 */
	public static function record_mcp( $payload, $answer, $failed ) {
		$body = isset( $answer['body'] ) ? $answer['body'] : null;

		if ( is_array( $payload ) && $payload && array_keys( $payload ) === range( 0, count( $payload ) - 1 ) ) {
			self::record( 'mcp', 'batch', 'refused_batch' );
			return;
		}

		$method = is_array( $payload ) && isset( $payload['method'] ) && is_string( $payload['method'] ) ? $payload['method'] : '';
		if ( 'tools/call' === $method ) {
			$tool = isset( $payload['params']['name'] ) ? $payload['params']['name'] : '';
			$name = is_string( $tool ) && isset( CP_Connector_Tools::ROUTES[ $tool ] ) ? $tool : 'unknown_tool';
		} elseif ( 0 === strpos( $method, 'notifications/' ) ) {
			$name = 'notification';
		} elseif ( in_array( $method, self::METHODS, true ) ) {
			$name = $method;
		} else {
			$name = '' === $method ? '(none)' : 'other_method';
		}

		// A result can be an object (ping answers {}), so test it is an array before reading it.
		if ( is_array( $body ) && isset( $body['error'] ) ) {
			$code    = is_array( $body['error'] ) && isset( $body['error']['code'] ) ? $body['error']['code'] : 0;
			$outcome = CP_Connector_MCP::HEADER_MISMATCH === $code ? 'refused_header' : 'rpc_error';
		} elseif ( is_array( $body ) && isset( $body['result'] ) && is_array( $body['result'] ) && ! empty( $body['result']['isError'] ) ) {
			$outcome = $failed ? 'failed' : 'tool_error';
		} else {
			$outcome = 'ok';
		}
		self::record( 'mcp', $name, $outcome );
	}

	/**
	 * The name under which a refused protocol version is counted.
	 *
	 * @param string $version MCP-Protocol-Version header.
	 * @return string
	 */
	public static function version_name( $version ) {
		if ( in_array( $version, self::KNOWN_NEWER_VERSIONS, true ) ) {
			return 'v' . $version;
		}
		$newest = CP_Connector_MCP::PROTOCOL_VERSIONS[0];
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $version ) && strcmp( $version, $newest ) > 0 ? 'v-newer' : 'v-other';
	}

	/**
	 * The client family, from the User-Agent. Never stored as written.
	 *
	 * @return string One of CLIENTS.
	 */
	public static function client() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 0 === strpos( $ua, 'christianpure-connector-tests' ) ) {
			return 'test';
		}
		if ( preg_match( '/claude/i', $ua ) ) {
			return 'claude';
		}
		if ( preg_match( '/openai|chatgpt/i', $ua ) ) {
			return 'chatgpt';
		}
		if ( preg_match( '/bot\b|bot\/|crawler|spider|census|scanner/i', $ua ) ) {
			return 'robot';
		}
		return 'other';
	}

	/**
	 * @param string $name Name to check.
	 * @return bool
	 */
	private static function is_known_name( $name ) {
		if ( isset( CP_Connector_Tools::ROUTES[ $name ] ) || in_array( $name, self::METHODS, true ) || in_array( $name, self::OTHER_NAMES, true ) ) {
			return true;
		}
		if ( in_array( $name, array( 'v-newer', 'v-other' ), true ) ) {
			return true;
		}
		return 0 === strpos( $name, 'v' ) && in_array( substr( $name, 1 ), self::KNOWN_NEWER_VERSIONS, true );
	}

	/** Deletes counts older than KEEP_DAYS, at most once a day. */
	private static function maybe_prune() {
		$today = gmdate( 'Ymd' );
		if ( get_option( self::PRUNED_OPTION ) === $today ) {
			return;
		}
		update_option( self::PRUNED_OPTION, $today, false );

		global $wpdb;
		$cutoff = self::PREFIX . gmdate( 'Ymd', time() - self::KEEP_DAYS * DAY_IN_SECONDS );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s",
				$wpdb->esc_like( self::PREFIX ) . '%',
				$cutoff
			)
		);
	}

	/**
	 * Every count for whole UTC days in a range.
	 *
	 * @param string $from First day, Y-m-d.
	 * @param string $to   Last day, Y-m-d.
	 * @return array[] Rows of day, client, channel, name, outcome, n.
	 */
	public static function rows( $from, $to ) {
		global $wpdb;
		// Bounds a day either side, compared on the date digits alone, so the
		// table's collation cannot drop a row; the exact range is kept below.
		$lo   = self::PREFIX . gmdate( 'Ymd', strtotime( $from . ' 00:00:00 UTC' ) - DAY_IN_SECONDS );
		$hi   = self::PREFIX . gmdate( 'Ymd', strtotime( $to . ' 00:00:00 UTC' ) + DAY_IN_SECONDS );
		$raw  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s AND option_name < %s",
				$wpdb->esc_like( self::PREFIX ) . '%',
				$lo,
				$hi
			),
			ARRAY_A
		);
		$rows = array();
		foreach ( (array) $raw as $r ) {
			$parts = explode( '|', substr( $r['option_name'], strlen( self::PREFIX ) ) );
			if ( 5 !== count( $parts ) || ! preg_match( '/^\d{8}$/', $parts[0] ) ) {
				continue;
			}
			list( $day, $client, $channel, $name, $outcome ) = $parts;
			$day = substr( $day, 0, 4 ) . '-' . substr( $day, 4, 2 ) . '-' . substr( $day, 6, 2 );
			if ( $day < $from || $day > $to ) {
				continue;
			}
			$rows[] = array(
				'day'     => $day,
				'client'  => $client,
				'channel' => $channel,
				'name'    => $name,
				'outcome' => $outcome,
				'n'       => (int) $r['option_value'],
			);
		}
		return $rows;
	}

	/**
	 * The weekly summary the Monday reading prints.
	 *
	 * Tool calls are MCP tools/call requests (including calls to a tool that does
	 * not exist) plus REST lookups. A failed call is one that ended in tool_error,
	 * failed, rpc_error or refused_header. The request error rate covers every request except the
	 * test client's and the version probes, which are counted on their own.
	 *
	 * @param string $from First day, Y-m-d.
	 * @param string $to   Last day, Y-m-d.
	 * @return array
	 */
	public static function report( $from, $to ) {
		global $wpdb;
		$first = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(option_name) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::PREFIX ) . '%'
			)
		);

		$tools = array();
		foreach ( array_keys( CP_Connector_Tools::ROUTES ) as $tool ) {
			$tools[ $tool ] = array(
				'calls'     => 0,
				'failed'    => 0,
				'mcp'       => 0,
				'rest'      => 0,
				'by_client' => array(),
			);
		}
		$out = array(
			'from'                   => $from,
			'to'                     => $to,
			'counting_since'         => $first ? self::row_day( $first ) : null,
			'requests'               => 0,
			'requests_with_error'    => 0,
			'request_error_pct'      => null,
			'tool_calls'             => 0,
			'tool_calls_failed'      => 0,
			'tool_failure_pct'       => null,
			'tools'                  => $tools,
			'clients'                => array(),
			'methods'                => array(),
			'outcomes'               => array(),
			'version_probes'         => array(),
			'errors'                 => array(),
			'by_day'                 => array(),
			'test_requests'          => 0,
		);

		foreach ( self::rows( $from, $to ) as $r ) {
			$n = $r['n'];
			if ( 'test' === $r['client'] ) {
				$out['test_requests'] += $n;
				continue;
			}
			$out['requests']                          += $n;
			$out['outcomes'][ $r['outcome'] ]          = ( isset( $out['outcomes'][ $r['outcome'] ] ) ? $out['outcomes'][ $r['outcome'] ] : 0 ) + $n;
			$out['clients'][ $r['client'] ]            = ( isset( $out['clients'][ $r['client'] ] ) ? $out['clients'][ $r['client'] ] : 0 ) + $n;
			$day                                       = isset( $out['by_day'][ $r['day'] ] ) ? $out['by_day'][ $r['day'] ] : array( 'requests' => 0, 'tool_calls' => 0 );
			$day['requests']                          += $n;

			if ( ! in_array( $r['outcome'], self::NOT_ERRORS, true ) ) {
				$out['requests_with_error'] += $n;
				$what                        = $r['channel'] . ' ' . $r['name'] . ' ' . $r['outcome'];
				$out['errors'][ $what ]      = ( isset( $out['errors'][ $what ] ) ? $out['errors'][ $what ] : 0 ) + $n;
			}
			if ( 'refused_version' === $r['outcome'] ) {
				$out['version_probes'][ $r['name'] ] = ( isset( $out['version_probes'][ $r['name'] ] ) ? $out['version_probes'][ $r['name'] ] : 0 ) + $n;
			}

			if ( isset( $out['tools'][ $r['name'] ] ) ) {
				$t                              = &$out['tools'][ $r['name'] ];
				$t['calls']                    += $n;
				$t[ $r['channel'] ]            += $n;
				$t['by_client'][ $r['client'] ] = ( isset( $t['by_client'][ $r['client'] ] ) ? $t['by_client'][ $r['client'] ] : 0 ) + $n;
				if ( in_array( $r['outcome'], array( 'tool_error', 'failed', 'rpc_error', 'refused_header' ), true ) ) {
					$t['failed']               += $n;
					$out['tool_calls_failed']  += $n;
				}
				unset( $t );
				$out['tool_calls'] += $n;
				$day['tool_calls'] += $n;
			} elseif ( 'unknown_tool' === $r['name'] ) {
				// A call to a tool this server does not have still counts as a failed call.
				$out['tool_calls']        += $n;
				$out['tool_calls_failed'] += $n;
				$day['tool_calls']        += $n;
			} elseif ( 'mcp' === $r['channel'] && 'refused_version' !== $r['outcome'] ) {
				$out['methods'][ $r['name'] ] = ( isset( $out['methods'][ $r['name'] ] ) ? $out['methods'][ $r['name'] ] : 0 ) + $n;
			}
			$out['by_day'][ $r['day'] ] = $day;
		}

		$probes = array_sum( $out['version_probes'] );
		if ( $out['requests'] - $probes > 0 ) {
			$out['request_error_pct'] = round( 100 * $out['requests_with_error'] / ( $out['requests'] - $probes ), 1 );
		}
		if ( $out['tool_calls'] > 0 ) {
			$out['tool_failure_pct'] = round( 100 * $out['tool_calls_failed'] / $out['tool_calls'], 1 );
		}
		arsort( $out['clients'] );
		arsort( $out['methods'] );
		arsort( $out['errors'] );
		ksort( $out['by_day'] );
		uasort(
			$out['tools'],
			static function ( $a, $b ) {
				return $b['calls'] <=> $a['calls'];
			}
		);

		// Empty maps print as {} rather than [], so every map reads the same in JSON.
		foreach ( array( 'clients', 'methods', 'outcomes', 'version_probes', 'errors', 'by_day' ) as $map ) {
			$out[ $map ] = (object) $out[ $map ];
		}
		foreach ( $out['tools'] as $tool => $t ) {
			$out['tools'][ $tool ]['by_client'] = (object) $t['by_client'];
		}
		return $out;
	}

	/**
	 * @param string $option_name A count's option name.
	 * @return string Y-m-d.
	 */
	private static function row_day( $option_name ) {
		$day = substr( $option_name, strlen( self::PREFIX ), 8 );
		return substr( $day, 0, 4 ) . '-' . substr( $day, 4, 2 ) . '-' . substr( $day, 6, 2 );
	}

	/** Registers `wp cp-connector usage`. */
	public static function cli() {
		WP_CLI::add_command(
			'cp-connector usage',
			static function ( $args, $assoc ) {
				$days  = isset( $assoc['days'] ) ? max( 1, (int) $assoc['days'] ) : 7;
				$today = isset( $assoc['include-today'] );
				$end   = $today ? time() : time() - DAY_IN_SECONDS;
				$to    = gmdate( 'Y-m-d', $end );
				$from  = gmdate( 'Y-m-d', $end - ( $days - 1 ) * DAY_IN_SECONDS );
				WP_CLI::line( wp_json_encode( self::report( $from, $to ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			},
			array(
				'shortdesc' => 'How often each connector tool was called and how often a request failed, as JSON.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'days',
						'optional'    => true,
						'description' => 'Whole UTC days to read, ending yesterday. Default 7.',
					),
					array(
						'type'        => 'flag',
						'name'        => 'include-today',
						'optional'    => true,
						'description' => 'End the range today instead of yesterday.',
					),
				),
			)
		);
	}
}
