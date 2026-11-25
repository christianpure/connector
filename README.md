<p align="center">
  <a href="https://christianpure.com/">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/christianpure/.github/main/assets/christianpure-logo-on-navy.png">
      <img src="https://raw.githubusercontent.com/christianpure/.github/main/assets/christianpure-logo-light.png" alt="Christian Pure" width="240">
    </picture>
  </a>
</p>

# Christian Pure connector (MCP server)

[![Christian Pure MCP connector](https://glama.ai/mcp/connectors/com.christianpure/connector/badges/score.svg)](https://glama.ai/mcp/connectors/com.christianpure/connector)

A free, read-only [Model Context Protocol](https://modelcontextprotocol.io/) server that lets AI assistants such as Claude and ChatGPT search [Christian Pure](https://christianpure.com/): written prayers for a need or an occasion, Bible verses gathered by subject, Bible study, the meaning of biblical names, church history and questions of faith. It needs no account and no key.

It is hosted, so most people only need its address:

```
https://christianpure.com/wp-json/christianpure-connector/v1/mcp
```

This repository holds the code that serves it, a WordPress mu-plugin, released under the GPL so that anyone running a WordPress site can see how it works or adapt it. It is listed in the official MCP registry as `com.christianpure/connector`.

## Connect

**Claude** (claude.ai or the desktop app): Settings, Connectors, Add custom connector, then paste the address above.

**Claude Code:**

```bash
claude mcp add --transport http christianpure https://christianpure.com/wp-json/christianpure-connector/v1/mcp
```

**Any client that reads an `mcpServers` file:**

```json
{
  "mcpServers": {
    "christianpure": {
      "type": "http",
      "url": "https://christianpure.com/wp-json/christianpure-connector/v1/mcp"
    }
  }
}
```

A client that only speaks stdio can reach it through [`mcp-remote`](https://www.npmjs.com/package/mcp-remote): `npx mcp-remote https://christianpure.com/wp-json/christianpure-connector/v1/mcp`.

## Tools

| Tool | What it does |
| --- | --- |
| `search_articles` | Searches the whole library by keyword, optionally within a topic. |
| `find_prayers` | Finds written prayers for a need, such as "court case", "sick mother" or "sleep". |
| `find_bible_verses` | Finds articles that gather and explain the Bible verses on a subject, such as "anxiety" or "losing a parent". |
| `get_article` | Gets one article by its christianpure.com link or slug: title, excerpt, summary points, topics, image and dates. |
| `list_topics` | Lists the subjects Christian Pure writes about, with the number of articles in each. |
| `list_courses` | Lists the online courses, with their lessons and links. |
| `get_newsletter` | Describes The Sunday Letter, the free weekly email, with its sign-up link. |

Every result links back to the article on christianpure.com. Hosts that support [MCP Apps](https://github.com/modelcontextprotocol/ext-apps), such as ChatGPT and Claude, show results from the four article tools as picture cards; other hosts get the same results as text.

The server speaks MCP 2026-07-28 and 2025-11-25 and older on the same address, over Streamable HTTP, statelessly.

## Plain web API

The same tools answer as ordinary GET requests under `https://christianpure.com/wp-json/christianpure-connector/v1`, for scripts and for clients that use OpenAPI:

| Route | Tool |
| --- | --- |
| `/search?query=&topic=&limit=` | `search_articles` |
| `/prayers?need=&limit=` | `find_prayers` |
| `/bible-verses?subject=&limit=` | `find_bible_verses` |
| `/article?url=` or `?slug=` | `get_article` |
| `/topics`, `/courses`, `/newsletter` | `list_topics`, `list_courses`, `get_newsletter` |

[`/openapi.json`](https://christianpure.com/wp-json/christianpure-connector/v1/openapi.json) describes them in OpenAPI 3.1, and [`/docs`](https://christianpure.com/wp-json/christianpure-connector/v1/docs) is a documentation page. The MCP server card is at [`/mcp/server-card`](https://christianpure.com/wp-json/christianpure-connector/v1/mcp/server-card) and at `/.well-known/mcp/server-card.json`.

## What it returns and what it keeps

It returns only what a signed-out reader already sees on the site: each article's title, its public excerpt, the summary points printed above it, topics, dates, image and link. It never returns an article's full text, a draft, a private or password-protected post, or any subscriber or member data. It cannot write, change or delete anything.

It keeps no record of who asked or what they asked. It counts calls per day by tool, by kind of client (read from the User-Agent) and by outcome, and nothing else; see the header of `class-cp-connector-usage.php`. Requests are limited to 120 a minute per client address.

## How the code is laid out

- `mu-plugins/christianpure-connector.php` loads the rest, holds the version and the kill switch, and switches the connector off rather than failing if a file is missing.
- `class-cp-connector-tools.php` is the one list of tools. The MCP tools, the REST routes, the OpenAPI document and the docs page are all generated from it.
- `class-cp-connector-content.php` runs the searches and builds the results, including a ranking that weights each search word by how rare it is in titles.
- `class-cp-connector-mcp.php` handles the MCP protocol; `class-cp-connector-rest.php` the routes; `class-cp-connector-card.php` the server card; `class-cp-connector-widget.php` the picture-card view; `class-cp-connector-usage.php` the counts.

The code is written for christianpure.com: it reads that site's categories and its paywall's public excerpt. To run it on another WordPress site you would change those parts in `class-cp-connector-content.php`. Copy the loader and the folder beside it into `wp-content/mu-plugins/`; WordPress loads them on its own. To switch it off, run `wp option update cp_connector_disabled 1`.

## Licence

The code is released under the GNU General Public License, version 2 or (at your option) any later version; see [`LICENSE`](LICENSE). The articles the connector returns are Christian Pure's and are not covered by this licence.

Christian Pure also publishes open Bible and church data, such as Paul's journeys, saints and Easter dates, at [christianpure/bible-data](https://github.com/christianpure/bible-data).

Questions and bug reports are welcome as [issues](https://github.com/christianpure/connector/issues).
