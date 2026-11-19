<?php
/**
 * OpenAPI 3.1 description of the connector's GET routes, built from the tool list.
 */

defined( 'ABSPATH' ) || exit;

final class CP_Connector_OpenAPI {

	/**
	 * The whole document.
	 *
	 * @return array
	 */
	public static function document() {
		$site  = CP_Connector_Content::site();
		$paths = array();

		foreach ( CP_Connector_Tools::all() as $tool ) {
			$paths[ $tool['route'] ] = array( 'get' => self::operation( $tool ) );
		}

		return array(
			'openapi'      => '3.1.0',
			'info'         => array(
				'title'          => 'Christian Pure API',
				'version'        => CP_CONNECTOR_VERSION,
				'summary'        => 'Search Christian Pure\'s articles, prayers, Bible verses and courses. Read-only, no account needed.',
				'description'    => CP_Connector_MCP::instructions(),
				'termsOfService' => $site['terms_url'],
				'contact'        => array(
					'name' => 'Christian Pure',
					'url'  => $site['contact_url'],
				),
			),
			'externalDocs' => array(
				'description' => 'Connector documentation',
				'url'         => CP_Connector_REST::url( '/docs' ),
			),
			'servers'      => array(
				array( 'url' => CP_Connector_REST::url( '' ) ),
			),
			'security'     => array(),
			'paths'        => $paths,
			'components'   => array(
				'schemas'   => self::schemas(),
				'responses' => array(
					'BadRequest'  => self::error_response( 'An argument is missing or invalid.' ),
					'NotFound'    => self::error_response( 'No published article matches.' ),
					'RateLimited' => self::error_response( 'Too many requests from this address. Wait for the number of seconds in Retry-After.' ),
				),
			),
		);
	}

	/**
	 * One GET operation.
	 *
	 * @param array $tool Tool definition.
	 * @return array
	 */
	private static function operation( $tool ) {
		$parameters = array();
		foreach ( $tool['params'] as $key => $schema ) {
			$description = isset( $schema['description'] ) ? $schema['description'] : '';
			unset( $schema['description'] );
			$parameters[] = array(
				'name'        => $key,
				'in'          => 'query',
				'required'    => in_array( $key, $tool['required'], true ),
				'description' => $description,
				'schema'      => $schema,
			);
		}

		$responses = array(
			'200' => array(
				'description' => 'OK',
				'content'     => array(
					'application/json' => array(
						'schema' => array( '$ref' => '#/components/schemas/' . $tool['response'] ),
					),
				),
			),
			'429' => array( '$ref' => '#/components/responses/RateLimited' ),
		);
		if ( $parameters ) {
			$responses['400'] = array( '$ref' => '#/components/responses/BadRequest' );
		}
		if ( 'get_article' === $tool['name'] ) {
			$responses['404'] = array( '$ref' => '#/components/responses/NotFound' );
		}
		ksort( $responses );

		$operation = array(
			'operationId' => $tool['name'],
			'summary'     => $tool['title'],
			'description' => $tool['description'],
			'responses'   => $responses,
		);
		if ( $parameters ) {
			$operation['parameters'] = $parameters;
		}
		return $operation;
	}

	/**
	 * @param string $description Description.
	 * @return array
	 */
	private static function error_response( $description ) {
		return array(
			'description' => $description,
			'content'     => array(
				'application/json' => array(
					'schema' => array( '$ref' => '#/components/schemas/Error' ),
				),
			),
		);
	}

	/**
	 * Response schemas.
	 *
	 * @return array
	 */
	private static function schemas() {
		$string   = array( 'type' => 'string' );
		$uri      = array(
			'type'   => 'string',
			'format' => 'uri',
		);
		$nullable = static function ( $type ) {
			return array( 'type' => array( $type, 'null' ) );
		};
		$strings  = array(
			'type'  => 'array',
			'items' => $string,
		);

		return array(
			'TopicRef'           => self::object(
				array(
					'slug' => $string,
					'name' => $string,
				)
			),
			'Article'            => self::object(
				array(
					'title'     => $string,
					'url'       => $uri,
					'slug'      => $string,
					'excerpt'   => array(
						'type'        => 'string',
						'description' => 'The public preview of the article.',
					),
					'summary'   => array(
						'type'        => 'array',
						'items'       => $string,
						'description' => 'The summary points printed above the article.',
					),
					'topics'    => array(
						'type'  => 'array',
						'items' => array( '$ref' => '#/components/schemas/TopicRef' ),
					),
					'image'     => $nullable( 'string' ),
					'published' => array(
						'type'   => 'string',
						'format' => 'date-time',
					),
					'updated'   => array(
						'type'   => 'string',
						'format' => 'date-time',
					),
				)
			),
			'Reading'            => self::object(
				array(
					'free_articles'  => array( 'type' => 'integer' ),
					'period'         => array(
						'type' => 'string',
						'enum' => array( 'day', 'week', 'month' ),
					),
					'note'           => $string,
					'membership_url' => $uri,
				)
			),
			'SearchResponse'     => self::object(
				array(
					'query'         => $string,
					'matched_query' => array(
						'type'        => array( 'string', 'null' ),
						'description' => 'The words that produced the results. It differs from query when the full phrase found nothing and a shorter search was tried; null when nothing matched.',
					),
					'topic'         => $nullable( 'string' ),
					'count'         => array( 'type' => 'integer' ),
					'results'       => array(
						'type'  => 'array',
						'items' => array( '$ref' => '#/components/schemas/Article' ),
					),
					'reading'       => array( '$ref' => '#/components/schemas/Reading' ),
				)
			),
			'ArticleResponse'    => self::object(
				array(
					'article' => array( '$ref' => '#/components/schemas/Article' ),
					'reading' => array( '$ref' => '#/components/schemas/Reading' ),
				)
			),
			'TopicsResponse'     => self::object(
				array(
					'topics' => array(
						'type'  => 'array',
						'items' => self::object(
							array(
								'slug'        => $string,
								'name'        => $string,
								'count'       => array( 'type' => 'integer' ),
								'url'         => $nullable( 'string' ),
								'description' => $string,
							)
						),
					),
				)
			),
			'CoursesResponse'    => self::object(
				array(
					'courses' => array(
						'type'  => 'array',
						'items' => self::object(
							array(
								'title'       => $string,
								'url'         => $uri,
								'description' => $string,
								'free'        => array( 'type' => 'boolean' ),
								'lessons'     => $nullable( 'integer' ),
							)
						),
					),
				)
			),
			'NewsletterResponse' => self::object(
				array(
					'name'        => $string,
					'price'       => $string,
					'frequency'   => $string,
					'description' => $string,
					'signup_url'  => $uri,
				)
			),
			'Error'              => self::object(
				array(
					'code'    => $string,
					'message' => $string,
					'data'    => array( 'type' => 'object' ),
				),
				array( 'code', 'message' )
			),
		);
	}

	/**
	 * An object schema whose properties are all required unless listed.
	 *
	 * @param array      $properties Properties.
	 * @param array|null $required   Required keys; every key when null.
	 * @return array
	 */
	private static function object( $properties, $required = null ) {
		return array(
			'type'       => 'object',
			'properties' => $properties,
			'required'   => null === $required ? array_keys( $properties ) : $required,
		);
	}
}
