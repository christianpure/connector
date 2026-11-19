<?php
/**
 * The human-readable documentation page, built from the tool list.
 */

defined( 'ABSPATH' ) || exit;

final class CP_Connector_Docs {

	/** Requests a reader might make of an assistant using the connector. */
	const EXAMPLE_PROMPTS = array(
		'Find me a prayer for my mother\'s surgery tomorrow.',
		'What does the Bible say about anxiety? Give me a few verses.',
		'Is Nathan a biblical name, and what does it mean?',
		'Is there a free course on what Christians believe?',
		'How many Christian Pure articles can I read without a membership?',
	);

	/**
	 * The page.
	 *
	 * @return string HTML.
	 */
	public static function html() {
		$site  = CP_Connector_Content::site();
		$count = number_format( $site['article_count'] );
		$read  = CP_Connector_Content::reading();

		ob_start();
		?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Christian Pure connector</title>
<style>
:root{--ink:#002159;--muted:#4a5a78;--page:#fbf8f3;--band:#f8f2e8;--raised:#fff;--line:rgba(171,127,54,.32);--code:#f5ede1}
@media (prefers-color-scheme:dark){:root{--ink:#fffdf8;--muted:#c3cbe0;--page:#0b1733;--band:#10204a;--raised:#132656;--line:rgba(197,140,35,.4);--code:#1a2d5e}}
*{box-sizing:border-box}
body{margin:0;background:var(--page);color:var(--ink);font:17px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
main{max-width:760px;margin:0 auto;padding:48px 20px 80px}
h1,h2,h3{font-family:Georgia,"Times New Roman",serif;font-weight:500;line-height:1.25}
h1{font-size:2.1rem;margin:0 0 .5rem}
h2{font-size:1.45rem;margin:2.75rem 0 .75rem;padding-top:1.5rem;border-top:1px solid var(--line)}
h3{font-size:1.15rem;margin:2rem 0 .4rem}
p,li{max-width:66ch}
a{color:inherit;text-underline-offset:3px}
code{font:.88em ui-monospace,SFMono-Regular,Menlo,monospace;background:var(--code);padding:.1em .35em;border-radius:3px;overflow-wrap:anywhere}
.lede{font-size:1.1rem;color:var(--muted)}
table{width:100%;border-collapse:collapse;margin:.75rem 0 1rem;font-size:.95rem}
th,td{text-align:left;vertical-align:top;padding:.5rem .6rem;border-bottom:1px solid var(--line)}
td:last-child{overflow-wrap:anywhere}
.tool th,.tool td:not(:last-child){white-space:nowrap}
th{font-weight:600}
.endpoints th{white-space:nowrap;width:1%}
.tool{background:var(--raised);border:1px solid var(--line);border-radius:4px;padding:.25rem 1.1rem 1rem;margin:1rem 0}
.tool h3{margin-top:1rem}
.req{color:var(--muted);font-size:.85rem}
footer{margin-top:3rem;color:var(--muted);font-size:.9rem}
@media (max-width:600px){main{padding:32px 16px 64px}h1{font-size:1.7rem}th,td{padding:.5rem .4rem}}
</style>
</head>
<body>
<main>
<h1>Christian Pure connector</h1>
<p class="lede"><?php echo esc_html( sprintf( 'Christian Pure publishes more than %s articles on prayer, Bible verses, Bible study, names in the Bible, church history and questions of faith. This connector lets an AI assistant search that library and send readers to it. It is read-only, free, and needs no account or key.', $count ) ); ?></p>

<h2>Connect</h2>
<table class="endpoints">
<tr><th>MCP server</th><td><code><?php echo esc_html( CP_Connector_REST::url( '/mcp' ) ); ?></code><br><span class="req">Streamable HTTP, stateless. POST one JSON-RPC message per request.</span></td></tr>
<tr><th>REST API</th><td><code><?php echo esc_html( CP_Connector_REST::url( '' ) ); ?></code></td></tr>
<tr><th>OpenAPI</th><td><a href="<?php echo esc_url( CP_Connector_REST::url( '/openapi.json' ) ); ?>"><code><?php echo esc_html( CP_Connector_REST::url( '/openapi.json' ) ); ?></code></a></td></tr>
</table>

<h2>Access</h2>
<ul>
<li>No account, sign-in or API key. Every route is public and read-only.</li>
<li><?php echo esc_html( sprintf( 'Up to %d requests a minute from one address. Past that the API answers 429 with a Retry-After header.', CP_CONNECTOR_RATE_LIMIT ) ); ?></li>
<li>Results give each article's title, public excerpt, summary points, topics, dates, image and link. They never include the full text.</li>
<li><?php echo esc_html( $read['note'] ); ?></li>
<li>Articles are in English. Links also resolve in the translated forms the site offers, such as <code>/es/learn/…</code>.</li>
</ul>

<h2>Tools</h2>
<p>The same tools are offered over MCP and as GET routes on the REST API. Arguments go in the query string for REST and in <code>arguments</code> for MCP.</p>
		<?php foreach ( CP_Connector_Tools::all() as $tool ) : ?>
<section class="tool" id="<?php echo esc_attr( $tool['name'] ); ?>">
<h3><?php echo esc_html( $tool['title'] ); ?></h3>
<p><code><?php echo esc_html( $tool['name'] ); ?></code> · <code>GET <?php echo esc_html( $tool['route'] ); ?></code></p>
<p><?php echo esc_html( $tool['description'] ); ?></p>
			<?php if ( $tool['params'] ) : ?>
<table>
<tr><th>Argument</th><th>Type</th><th>Meaning</th></tr>
				<?php foreach ( $tool['params'] as $key => $schema ) : ?>
<tr><td><code><?php echo esc_html( $key ); ?></code><?php echo in_array( $key, $tool['required'], true ) ? ' <span class="req">required</span>' : ''; ?></td><td><?php echo esc_html( $schema['type'] ); ?></td><td><?php echo esc_html( self::param_note( $schema ) ); ?></td></tr>
				<?php endforeach; ?>
</table>
			<?php endif; ?>
<p class="req">Example: <code><?php echo esc_html( self::example_url( $tool ) ); ?></code></p>
</section>
		<?php endforeach; ?>

<h2>What people ask</h2>
<ul>
		<?php foreach ( self::EXAMPLE_PROMPTS as $prompt ) : ?>
<li><?php echo esc_html( $prompt ); ?></li>
		<?php endforeach; ?>
</ul>

<h2>Contact and policies</h2>
<ul>
<li><a href="<?php echo esc_url( $site['contact_url'] ); ?>">Contact Christian Pure</a></li>
<li><a href="<?php echo esc_url( $site['privacy_url'] ); ?>">Privacy policy</a></li>
<li><a href="<?php echo esc_url( $site['terms_url'] ); ?>">Terms of service</a></li>
<li><a href="<?php echo esc_url( $site['editorial_standards'] ); ?>">Editorial standards</a></li>
</ul>
<footer><?php echo esc_html( sprintf( 'Christian Pure connector %s', CP_CONNECTOR_VERSION ) ); ?> · <a href="<?php echo esc_url( $site['url'] ); ?>">christianpure.com</a></footer>
</main>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * A parameter's description plus its limits.
	 *
	 * @param array $schema Parameter schema.
	 * @return string
	 */
	private static function param_note( $schema ) {
		$note = isset( $schema['description'] ) ? $schema['description'] : '';
		if ( isset( $schema['default'] ) ) {
			$note .= sprintf( ' Default %s.', $schema['default'] );
		}
		return trim( $note );
	}

	/**
	 * A sample REST request for a tool.
	 *
	 * @param array $tool Tool definition.
	 * @return string
	 */
	private static function example_url( $tool ) {
		$samples = array(
			'search_articles'   => array( 'query' => 'unforgivable sin' ),
			'find_prayers'      => array( 'need' => 'court case' ),
			'find_bible_verses' => array( 'subject' => 'anxiety' ),
			'get_article'       => array( 'slug' => 'bible-verses-about-the-sunset' ),
		);
		$url = CP_Connector_REST::url( $tool['route'] );
		return isset( $samples[ $tool['name'] ] ) ? add_query_arg( array_map( 'rawurlencode', $samples[ $tool['name'] ] ), $url ) : $url;
	}
}
