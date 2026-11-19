<?php
/**
 * The picture-card view for article results, as an MCP App.
 *
 * Hosts that support MCP Apps (ChatGPT, Claude and others) render search and
 * article results as the design system's featured cards: the article's 16:9
 * painting with its title in an 85% navy panel, bottom left, and the
 * Jerusalem-cross medallion. At 16 px the mark is the site's tab icon (the
 * owner's pick C1, 9 Nov 2026), christianpure-tab.svg in the design system.
 * The whole card opens the article. Hosts without MCP Apps ignore the link and show the text result.
 *
 * Spec: https://github.com/modelcontextprotocol/ext-apps/blob/main/specification/2026-01-26/apps.mdx
 */

defined( 'ABSPATH' ) || exit;

final class CP_Connector_Widget {

	const URI  = 'ui://christianpure/cards-v1.html';
	const MIME = 'text/html;profile=mcp-app';

	/** Where the card images come from: the site and WordPress.com's image service. */
	const IMAGE_ORIGINS = array( 'https://christianpure.com', 'https://i0.wp.com', 'https://i1.wp.com', 'https://i2.wp.com', 'https://i3.wp.com' );

	/**
	 * The resource as resources/list shows it.
	 *
	 * @return array
	 */
	public static function descriptor() {
		return array(
			'uri'         => self::URI,
			'name'        => 'christianpure_cards',
			'title'       => 'Christian Pure article cards',
			'description' => 'Shows Christian Pure articles as picture cards that open the article.',
			'mimeType'    => self::MIME,
		);
	}

	/**
	 * The resources/read contents.
	 *
	 * @return array
	 */
	public static function contents() {
		$origins = self::image_origins();

		return array(
			array(
				'uri'      => self::URI,
				'mimeType' => self::MIME,
				'text'     => self::html(),
				'_meta'    => array(
					'ui'                         => array(
						'csp'           => array(
							'connectDomains'  => array(),
							'resourceDomains' => $origins,
						),
						'prefersBorder' => false,
					),
					'openai/widgetCSP'           => array(
						'connect_domains'  => array(),
						'resource_domains' => $origins,
					),
					'openai/widgetPrefersBorder' => false,
					'openai/widgetDescription'   => 'Picture cards for the Christian Pure articles in the result. Each card opens the article on christianpure.com.',
				),
			),
		);
	}

	/**
	 * The _meta a card tool carries in tools/list.
	 *
	 * @return array
	 */
	public static function tool_meta() {
		return array(
			'ui'                             => array( 'resourceUri' => self::URI ),
			'openai/outputTemplate'          => self::URI,
			'openai/toolInvocation/invoking' => 'Searching Christian Pure',
			'openai/toolInvocation/invoked'  => 'Found Christian Pure articles',
		);
	}

	/**
	 * Image origins, including this site when it is not christianpure.com (staging).
	 *
	 * @return string[]
	 */
	private static function image_origins() {
		$origins = self::IMAGE_ORIGINS;
		$home    = wp_parse_url( home_url() );
		if ( ! empty( $home['host'] ) ) {
			$origins[] = 'https://' . strtolower( $home['host'] );
		}
		return array_values( array_unique( $origins ) );
	}

	/**
	 * The view: one self-contained HTML document.
	 *
	 * @return string
	 */
	public static function html() {
		$home = wp_json_encode( untrailingslashit( home_url() ) );

		return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Christian Pure articles</title>
<style>
:root{--cp-scrim:rgba(0,33,89,.85);--cp-on-inverse:#fffdf8;--cp-navy:#002159;--cp-gold:#c58c23;--cp-radius:8px;--cp-label:"FK Roman Text","Source Serif 4",Georgia,serif;color-scheme:light}
:root[data-theme="dark"]{color-scheme:dark}
html,body{margin:0;padding:0;background:transparent}
body{color:var(--color-text-primary,CanvasText);font:15px/1.5 var(--font-sans,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif)}
.cp{padding:2px 0 6px}
.track{display:grid;grid-auto-flow:column;grid-auto-columns:82%;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:thin;padding:2px 2px 8px}
@media (min-width:560px){.track{grid-auto-columns:calc(50% - 6px)}}
.single .track{grid-auto-columns:100%;overflow:visible}
.card{position:relative;display:block;width:100%;aspect-ratio:16/9;border:0;padding:0;margin:0;border-radius:var(--cp-radius);overflow:hidden;background:var(--cp-navy);cursor:pointer;scroll-snap-align:start;text-align:left;font:inherit;color:inherit}
.card img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block}
.card:hover .panel{background:rgba(0,33,89,.92)}
.card:focus-visible{outline:2px solid var(--cp-gold);outline-offset:2px}
.panel{position:absolute;left:0;bottom:0;max-width:75%;box-sizing:border-box;padding:10px 14px 11px 12px;background:var(--cp-scrim);color:var(--cp-on-inverse);display:flex;gap:9px;align-items:flex-start;border-top-right-radius:var(--cp-radius)}
.panel .mark{flex:0 0 16px;width:16px;height:16px;margin-top:2px;display:block}
.title{font-family:var(--cp-label);font-weight:500;font-size:14px;line-height:1.4;letter-spacing:.01em;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.points{margin:12px 2px 0;padding-left:20px}
.points li{margin:0 0 6px}
.source{margin:6px 2px 0;font-size:13px;color:var(--color-text-secondary,GrayText)}
.source button{border:0;background:none;padding:0;font:inherit;color:inherit;text-decoration:underline;text-underline-offset:3px;cursor:pointer}
.empty{font-size:14px;color:var(--color-text-secondary,GrayText);margin:4px 2px}
@media (max-width:420px){.title{font-size:13px;line-height:1.35}.panel{padding:8px 12px 9px 10px;gap:8px}}
</style>
</head>
<body>
<div class="cp" id="root" aria-live="polite"></div>
<script>
(function () {
  var HOME = {$home};
  var MARK = '<svg class="mark" viewBox="0 0 512 512" width="16" height="16" aria-hidden="true" focusable="false"><path fill="#f5ede1" d="M0 256A256 256 0 1 1 512 256A256 256 0 1 1 0 256Z"/><path fill="#002159" d="M224 224L224 116.75L198.64 108.12L198.64 92.12L313.36 92.12L313.36 108.12L288 116.75L288 224L395.25 224L403.88 198.64L419.88 198.64L419.88 313.36L403.88 313.36L395.25 288L288 288L288 395.25L313.36 403.88L313.36 419.88L198.64 419.88L198.64 403.88L224 395.25L224 288L116.75 288L108.12 313.36L92.12 313.36L92.12 198.64L108.12 198.64L116.75 224ZM143.9 143.9L143.9 126.54L164.9 126.54L164.9 143.9L182.26 143.9L182.26 164.9L164.9 164.9L164.9 182.26L143.9 182.26L143.9 164.9L126.54 164.9L126.54 143.9ZM143.9 347.1L143.9 329.74L164.9 329.74L164.9 347.1L182.26 347.1L182.26 368.1L164.9 368.1L164.9 385.46L143.9 385.46L143.9 368.1L126.54 368.1L126.54 347.1ZM347.1 143.9L347.1 126.54L368.1 126.54L368.1 143.9L385.46 143.9L385.46 164.9L368.1 164.9L368.1 182.26L347.1 182.26L347.1 164.9L329.74 164.9L329.74 143.9ZM347.1 347.1L347.1 329.74L368.1 329.74L368.1 347.1L385.46 347.1L385.46 368.1L368.1 368.1L368.1 385.46L347.1 385.46L347.1 368.1L329.74 368.1L329.74 347.1Z"/></svg>';
  var root = document.getElementById('root');
  var nextId = 1, pending = {}, lastKey = '';

  function send(msg) { window.parent.postMessage(msg, '*'); }
  function request(method, params) {
    var id = nextId++;
    send({ jsonrpc: '2.0', id: id, method: method, params: params || {} });
    return new Promise(function (resolve) {
      pending[id] = resolve;
      setTimeout(function () { if (pending[id]) { delete pending[id]; resolve(null); } }, 4000);
    });
  }
  function notify(method, params) { send({ jsonrpc: '2.0', method: method, params: params || {} }); }

  function applyContext(ctx) {
    if (!ctx) { return; }
    if (ctx.theme) { document.documentElement.setAttribute('data-theme', ctx.theme); }
    var vars = ctx.styles && ctx.styles.variables;
    if (vars) { Object.keys(vars).forEach(function (k) { if (vars[k]) { document.documentElement.style.setProperty(k, vars[k]); } }); }
  }

  function isOwn(url) { return typeof url === 'string' && (url === HOME || url.indexOf(HOME + '/') === 0); }
  function openLink(url) {
    if (!isOwn(url)) { return; }
    if (window.openai && typeof window.openai.openExternal === 'function') {
      try { window.openai.openExternal({ href: url }); return; } catch (e) {}
    }
    request('ui/open-link', { url: url }).then(function (reply) {
      if (!reply || reply.error) { window.open(url, '_blank', 'noopener'); }
    });
  }

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) { node.className = cls; }
    if (text != null) { node.textContent = text; }
    return node;
  }

  function card(item, index) {
    var button = el('button', 'card');
    button.type = 'button';
    button.setAttribute('aria-label', (item.title || 'Christian Pure article') + ', opens on christianpure.com');
    if (item.image && /^https:\/\//.test(item.image)) {
      var img = el('img');
      img.src = item.image; img.alt = ''; img.decoding = 'async';
      img.loading = index < 2 ? 'eager' : 'lazy';
      if (index === 0) { img.setAttribute('fetchpriority', 'high'); }
      img.onerror = function () { img.remove(); };
      button.appendChild(img);
    }
    var panel = el('span', 'panel');
    panel.insertAdjacentHTML('afterbegin', MARK);
    panel.appendChild(el('span', 'title', item.title || ''));
    button.appendChild(panel);
    button.addEventListener('click', function () { openLink(item.url); });
    return button;
  }

  function render(data) {
    if (!data || typeof data !== 'object') { return; }
    var key = JSON.stringify(data);
    if (key === lastKey) { return; }
    lastKey = key;
    root.textContent = '';

    var items = Array.isArray(data.results) ? data.results : (data.article ? [data.article] : []);
    items = items.filter(function (i) { return i && isOwn(i.url); });

    if (!items.length) {
      root.appendChild(el('p', 'empty', 'No Christian Pure articles matched.'));
      resize();
      return;
    }

    var single = !Array.isArray(data.results);
    root.className = 'cp' + (single ? ' single' : '');
    var track = el('div', 'track');
    items.forEach(function (i, n) { track.appendChild(card(i, n)); });
    root.appendChild(track);

    if (single && Array.isArray(items[0].summary) && items[0].summary.length) {
      var list = el('ul', 'points');
      items[0].summary.slice(0, 5).forEach(function (p) { list.appendChild(el('li', null, p)); });
      root.appendChild(list);
    }

    var source = el('p', 'source');
    source.appendChild(document.createTextNode('From Christian Pure · '));
    var more = el('button', null, single ? 'Read the full article' : 'Visit christianpure.com');
    more.type = 'button';
    more.addEventListener('click', function () { openLink(single ? items[0].url : HOME + '/'); });
    source.appendChild(more);
    root.appendChild(source);
    resize();
  }

  var lastHeight = 0;
  function resize() {
    var height = Math.ceil(document.documentElement.getBoundingClientRect().height);
    if (height && height !== lastHeight) { lastHeight = height; notify('ui/notifications/size-changed', { height: height }); }
  }
  if (window.ResizeObserver) { new ResizeObserver(resize).observe(document.documentElement); }

  window.addEventListener('message', function (event) {
    var m = event.data;
    if (!m || m.jsonrpc !== '2.0') { return; }
    if (m.id != null && pending[m.id] && (m.result !== undefined || m.error !== undefined)) {
      var done = pending[m.id]; delete pending[m.id]; done(m); return;
    }
    if (m.method === 'ui/notifications/tool-result') { render(m.params && m.params.structuredContent); }
    else if (m.method === 'ui/notifications/host-context-changed') { applyContext(m.params); }
    else if (m.method === 'ping' && m.id != null) { send({ jsonrpc: '2.0', id: m.id, result: {} }); }
  });

  if (window.openai) {
    if (window.openai.theme) { document.documentElement.setAttribute('data-theme', window.openai.theme); }
    if (window.openai.toolOutput) { render(window.openai.toolOutput); }
    window.addEventListener('openai:set_globals', function (e) {
      var g = e.detail && e.detail.globals;
      if (!g) { return; }
      if (g.theme) { document.documentElement.setAttribute('data-theme', g.theme); }
      if (g.toolOutput) { render(g.toolOutput); }
    });
  }

  request('ui/initialize', { appInfo: { name: 'christianpure-cards', version: '1.0.0' }, appCapabilities: {}, protocolVersion: '2026-01-26' })
    .then(function (reply) {
      if (reply && reply.result) {
        applyContext(reply.result.hostContext);
        notify('ui/notifications/initialized', {});
      }
    });
})();
</script>
</body>
</html>
HTML;
	}
}
