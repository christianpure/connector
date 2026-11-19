<?php
/**
 * The connector's tools, defined once.
 *
 * The REST routes, the MCP server, the OpenAPI document and the docs page are
 * all generated from this list, so they cannot drift apart.
 */

defined( 'ABSPATH' ) || exit;

final class CP_Connector_Tools {

	/** Tool name => REST route. Kept apart from the definitions so route registration stays cheap. */
	const ROUTES = array(
		'search_articles'   => '/search',
		'find_prayers'      => '/prayers',
		'find_bible_verses' => '/bible-verses',
		'get_article'       => '/article',
		'list_topics'       => '/topics',
		'list_courses'      => '/courses',
		'get_newsletter'    => '/newsletter',
	);

	/** Tools whose results the picture-card widget renders. */
	const CARD_TOOLS = array( 'search_articles', 'find_prayers', 'find_bible_verses', 'get_article' );

	/** @var array[]|null */
	private static $tools = null;

	/**
	 * Every tool, keyed by name.
	 *
	 * @return array[]
	 */
	public static function all() {
		if ( null !== self::$tools ) {
			return self::$tools;
		}

		$count  = CP_Connector_Content::article_count();
		$topics = CP_Connector_Content::topic_slugs();

		$topic_param = array(
			'type'        => 'string',
			'description' => 'Optional. Limit results to one subject, by slug (list_topics gives every slug and its article count).',
		);
		if ( $topics ) {
			$topic_param['enum'] = $topics;
		}

		$limit_param = array(
			'type'        => 'integer',
			'description' => 'How many articles to return, 1 to 10.',
			'minimum'     => 1,
			'maximum'     => 10,
			'default'     => 5,
		);

		$tools = array(
			array(
				'name'        => 'search_articles',
				'title'       => 'Search articles',
				'description' => sprintf( 'Search Christian Pure\'s library of more than %s articles on prayer, Bible verses, Bible study, the meaning of names in the Bible, church history, marriage, parenting and questions of faith. Pass a few plain keywords, for example "anxiety", "Nathan name meaning" or "unforgivable sin". Returns up to 10 articles, each with a title, a short excerpt, summary points, an image and a link to the full article on christianpure.com.', number_format( $count ) ),
				'params'      => array(
					'query' => array(
						'type'        => 'string',
						'description' => 'Keywords to search for.',
						'minLength'   => 2,
						'maxLength'   => 100,
					),
					'topic' => $topic_param,
					'limit' => $limit_param,
				),
				'required'    => array( 'query' ),
				'response'    => 'SearchResponse',
				'handler'     => static function ( $args ) {
					return CP_Connector_Content::search( $args['query'], isset( $args['topic'] ) ? $args['topic'] : '', $args['limit'] );
				},
			),
			array(
				'name'        => 'find_prayers',
				'title'       => 'Find prayers',
				'description' => 'Find written prayers for a need or an occasion: illness, grief, a job interview, a court case, a marriage, a child, sleep, protection, thanksgiving. Returns Christian Pure prayer articles, each with an excerpt, summary points and a link to the full set of prayers.',
				'params'      => array(
					'need'  => array(
						'type'        => 'string',
						'description' => 'What the prayer is for, in a few words, for example "court case" or "sick mother".',
						'minLength'   => 2,
						'maxLength'   => 100,
					),
					'limit' => $limit_param,
				),
				'required'    => array( 'need' ),
				'response'    => 'SearchResponse',
				'handler'     => static function ( $args ) {
					return CP_Connector_Content::search( $args['need'], 'prayers', $args['limit'] );
				},
			),
			array(
				'name'        => 'find_bible_verses',
				'title'       => 'Find Bible verses',
				'description' => 'Find Bible verses on a subject, such as anxiety, hope, forgiveness, marriage, strength or loss. Returns Christian Pure articles that gather and explain the verses on that subject, each with an excerpt, summary points and a link.',
				'params'      => array(
					'subject' => array(
						'type'        => 'string',
						'description' => 'The subject, in a few words, for example "anxiety" or "losing a parent".',
						'minLength'   => 2,
						'maxLength'   => 100,
					),
					'limit'   => $limit_param,
				),
				'required'    => array( 'subject' ),
				'response'    => 'SearchResponse',
				'handler'     => static function ( $args ) {
					return CP_Connector_Content::search( $args['subject'], 'bible-verses', $args['limit'] );
				},
			),
			array(
				'name'        => 'get_article',
				'title'       => 'Get an article',
				'description' => 'Get one Christian Pure article by its link or slug: title, excerpt, summary points, topics, image and dates. Use it when someone shares a christianpure.com link, or to look closer at a search result. Pass url or slug. The full text is on christianpure.com.',
				'params'      => array(
					'url'  => array(
						'type'        => 'string',
						'description' => 'A christianpure.com article link, for example https://christianpure.com/learn/bible-verses-about-the-sunset/.',
						'minLength'   => 10,
						'maxLength'   => 300,
					),
					'slug' => array(
						'type'        => 'string',
						'description' => 'The article slug, the last part of its link, for example "bible-verses-about-the-sunset".',
						'minLength'   => 1,
						'maxLength'   => 200,
					),
				),
				'required'    => array(),
				'response'    => 'ArticleResponse',
				'handler'     => static function ( $args ) {
					return CP_Connector_Content::article( $args );
				},
			),
			array(
				'name'        => 'list_topics',
				'title'       => 'List topics',
				'description' => 'List the subjects Christian Pure writes about, with the number of articles in each and a link to browse them. The slugs work as the topic filter in search_articles.',
				'params'      => array(),
				'required'    => array(),
				'response'    => 'TopicsResponse',
				'handler'     => static function () {
					return CP_Connector_Content::topics();
				},
			),
			array(
				'name'        => 'list_courses',
				'title'       => 'List courses',
				'description' => 'List Christian Pure\'s online courses, with a description, the number of lessons and a link to each course.',
				'params'      => array(),
				'required'    => array(),
				'response'    => 'CoursesResponse',
				'handler'     => static function () {
					return CP_Connector_Content::courses();
				},
			),
			array(
				'name'        => 'get_newsletter',
				'title'       => 'Get the Sunday Letter',
				'description' => 'Describe The Sunday Letter, Christian Pure\'s free weekly email, and give the link to sign up.',
				'params'      => array(),
				'required'    => array(),
				'response'    => 'NewsletterResponse',
				'handler'     => static function () {
					return CP_Connector_Content::newsletter();
				},
			),
		);

		self::$tools = array();
		foreach ( $tools as $tool ) {
			$tool['route']                = self::ROUTES[ $tool['name'] ];
			self::$tools[ $tool['name'] ] = $tool;
		}
		return self::$tools;
	}

	/**
	 * One tool by name.
	 *
	 * @param string $name Tool name.
	 * @return array|null
	 */
	public static function get( $name ) {
		$tools = self::all();
		return isset( $tools[ $name ] ) ? $tools[ $name ] : null;
	}

	/**
	 * Validates arguments, applies defaults and runs the tool.
	 *
	 * Unknown arguments are ignored so that a client adding a field does not
	 * break the call.
	 *
	 * @param string $name  Tool name.
	 * @param array  $input Raw arguments.
	 * @return array|WP_Error
	 */
	public static function call( $name, $input ) {
		$tool = self::get( $name );
		if ( ! $tool ) {
			return new WP_Error( 'cp_connector_unknown_tool', sprintf( 'There is no tool called "%s".', $name ), array( 'status' => 404 ) );
		}

		$args = self::prepare_args( $tool, is_array( $input ) ? $input : array() );
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		return call_user_func( $tool['handler'], $args );
	}

	/**
	 * Checks each argument against its schema.
	 *
	 * @param array $tool  Tool definition.
	 * @param array $input Raw arguments.
	 * @return array|WP_Error
	 */
	public static function prepare_args( $tool, $input ) {
		$args = array();

		foreach ( $tool['params'] as $key => $schema ) {
			$present = array_key_exists( $key, $input ) && null !== $input[ $key ] && '' !== $input[ $key ];

			if ( ! $present ) {
				if ( in_array( $key, $tool['required'], true ) ) {
					return new WP_Error( 'cp_connector_missing_param', sprintf( 'The "%s" argument is required.', $key ), array( 'status' => 400 ) );
				}
				if ( array_key_exists( 'default', $schema ) ) {
					$args[ $key ] = $schema['default'];
				}
				continue;
			}

			$value = $input[ $key ];
			if ( 'string' === $schema['type'] && is_string( $value ) ) {
				$value = trim( $value );
			}

			$valid = rest_validate_value_from_schema( $value, $schema, $key );
			if ( is_wp_error( $valid ) ) {
				return new WP_Error( 'cp_connector_invalid_param', $valid->get_error_message(), array( 'status' => 400 ) );
			}

			$args[ $key ] = rest_sanitize_value_from_schema( $value, $schema, $key );
		}

		return $args;
	}

	/**
	 * JSON Schema for a tool's input.
	 *
	 * @param array $tool Tool definition.
	 * @return array
	 */
	public static function input_schema( $tool ) {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $tool['params'] ? $tool['params'] : new stdClass(),
			'additionalProperties' => false,
		);
		if ( $tool['required'] ) {
			$schema['required'] = $tool['required'];
		}
		return $schema;
	}
}
