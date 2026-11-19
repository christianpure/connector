<?php
/**
 * Reads Christian Pure content for the connector.
 *
 * Every method returns only what a logged-out reader can already see on the
 * site: titles, the public excerpt, the summary points printed above each
 * article, topics, dates, images and links. An article body never enters a
 * response. The excerpt comes from the paywall's own teaser function, the one
 * that feeds blocked readers, RSS and oEmbed; if that function is missing the
 * connector falls back to the hand-written excerpt alone.
 */

defined( 'ABSPATH' ) || exit;

final class CP_Connector_Content {

	const CACHE_GROUP = 'cp_connector';
	const CACHE_TTL   = 600;

	/** Categories whose posts never appear in results (domain-acquisition notices, quiz output). */
	const EXCLUDED_POST_CATEGORIES = array( 'press', 'quiz-results' );

	/** Categories left out of the topic list: housekeeping, not subjects. */
	const HIDDEN_TOPICS = array( 'press', 'quiz-results', 'uncategorized', 'free', 'paid', 'general' );

	/** Words that carry no weight in ranking: they appear in most titles or say nothing. */
	const FILLER_WORDS = array(
		'a', 'about', 'after', 'all', 'also', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'before', 'best', 'bible',
		'biblical', 'by', 'can', 'christian', 'christianity', 'could', 'do', 'does', 'during', 'find', 'for',
		'from', 'get', 'give', 'god', 'good', 'has', 'have', 'help', 'how', 'i', 'if', 'in', 'into', 'is', 'it',
		'me', 'my', 'need', 'now', 'of', 'on', 'or', 'our', 'please', 'pray', 'prayer', 'prayers', 'say', 'says',
		'scripture', 'scriptures', 'short', 'should', 'simple', 'so', 'some', 'soon', 'that', 'the', 'their',
		'there', 'these', 'they', 'this', 'to', 'today', 'tomorrow', 'tonight', 'us', 'verse', 'verses', 'very',
		'want', 'was', 'we', 'what', 'when', 'who', 'why', 'will', 'with', 'would', 'you', 'your',
	);

	const NEWSLETTER_SIGNUP_URL = 'https://christian-pure.kit.com/f6c8330c6d';

	/**
	 * Searches published articles, optionally inside one topic.
	 *
	 * WordPress search alone ranks badly here: it requires every word to
	 * match, and among the matches it puts any title holding any query word
	 * first, newest first, so "Elijah name meaning" returns articles whose
	 * titles hold "name". So the connector gathers candidates from WordPress's
	 * full search and from a title search on each word, then ranks them by
	 * where the words appear, weighting each word by how rare it is in titles.
	 * When no title holds any word, it looks for each word in the text.
	 * `matched_query` reports what matched: the query itself, or the words
	 * joined by OR when only some of them did.
	 *
	 * @param string $query Search words.
	 * @param string $topic Category slug, or ''.
	 * @param int    $limit Results wanted, 1-10.
	 * @return array
	 */
	public static function search( $query, $topic = '', $limit = 5 ) {
		$query = self::clean_text( $query, 100 );
		$limit = max( 1, min( 10, (int) $limit ) );
		$topic = (string) $topic;

		$key   = 'search:' . md5( wp_json_encode( array( $query, $topic, $limit, CP_CONNECTOR_VERSION ) ) );
		$found = wp_cache_get( $key, self::CACHE_GROUP );
		if ( ! is_array( $found ) ) {
			$found = '' === $query ? array(
				'results' => array(),
				'matched' => null,
			) : self::find( $query, $topic, $limit );
			wp_cache_set( $key, $found, self::CACHE_GROUP, self::CACHE_TTL );
		}

		return array(
			'query'         => $query,
			'matched_query' => $found['matched'],
			'topic'         => '' === $topic ? null : $topic,
			'count'         => count( $found['results'] ),
			'results'       => $found['results'],
			'reading'       => self::reading(),
		);
	}

	/**
	 * Returns one article by link or slug.
	 *
	 * @param array $args One of `url`, `slug`.
	 * @return array|WP_Error
	 */
	public static function article( $args ) {
		$post = null;

		if ( ! empty( $args['url'] ) ) {
			$slug = self::slug_from_url( (string) $args['url'] );
			if ( is_wp_error( $slug ) ) {
				return $slug;
			}
			$post = self::post_by_slug( $slug );
		} elseif ( ! empty( $args['slug'] ) ) {
			$post = self::post_by_slug( sanitize_title( (string) $args['slug'] ) );
		} else {
			return new WP_Error( 'cp_connector_missing_article', 'Pass the article link (url) or its slug.', array( 'status' => 400 ) );
		}

		if ( ! $post || ! self::is_listable( $post ) ) {
			return new WP_Error( 'cp_connector_not_found', 'No published Christian Pure article matches that link or slug.', array( 'status' => 404 ) );
		}

		return array(
			'article' => self::card( $post ),
			'reading' => self::reading(),
		);
	}

	/**
	 * Lists the subjects the site writes about.
	 *
	 * @return array
	 */
	public static function topics() {
		return array( 'topics' => self::topic_rows() );
	}

	/**
	 * Slugs accepted by the `topic` parameter.
	 *
	 * @return string[]
	 */
	public static function topic_slugs() {
		return wp_list_pluck( self::topic_rows(), 'slug' );
	}

	/**
	 * Lists published LearnDash courses.
	 *
	 * @return array
	 */
	public static function courses() {
		if ( ! post_type_exists( 'sfwd-courses' ) ) {
			return array( 'courses' => array() );
		}

		$posts = get_posts(
			array(
				'post_type'        => 'sfwd-courses',
				'post_status'      => 'publish',
				'numberposts'      => 20,
				'orderby'          => array(
					'menu_order' => 'ASC',
					'date'       => 'ASC',
				),
				'has_password'     => false,
				'suppress_filters' => true,
			)
		);

		$courses = array();
		foreach ( $posts as $course ) {
			$price_type = function_exists( 'learndash_get_setting' ) ? (string) learndash_get_setting( $course->ID, 'course_price_type' ) : '';
			$lessons    = function_exists( 'learndash_get_course_lessons_list' ) ? count( (array) learndash_get_course_lessons_list( $course->ID ) ) : null;
			$free       = in_array( $price_type, array( 'free', 'open', '' ), true );

			$courses[] = array(
				'title'       => self::plain( get_the_title( $course ) ),
				'url'         => get_permalink( $course ),
				'description' => self::course_description( $course ),
				'free'        => $free,
				'lessons'     => $lessons,
			);
		}

		return array( 'courses' => $courses );
	}

	/**
	 * Describes The Sunday Letter.
	 *
	 * @return array
	 */
	public static function newsletter() {
		return array(
			'name'        => 'The Sunday Letter',
			'price'       => 'Free',
			'frequency'   => 'Weekly, on Sunday evening',
			'description' => 'A short weekly letter from Christian Pure with a verse from the King James Bible, a closing prayer, and a selection of articles to read.',
			'signup_url'  => (string) apply_filters( 'cp_connector_newsletter_signup_url', self::NEWSLETTER_SIGNUP_URL ),
		);
	}

	/**
	 * Site facts shared by the index, the docs and the MCP instructions.
	 *
	 * @return array
	 */
	public static function site() {
		return array(
			'name'                => 'Christian Pure',
			'url'                 => home_url( '/' ),
			'about_url'           => self::page_url( 'about' ),
			'editorial_standards' => self::page_url( 'editorial-standards' ),
			'contact_url'         => self::page_url( 'contact' ),
			'privacy_url'         => get_privacy_policy_url() ? get_privacy_policy_url() : self::page_url( 'privacy-policy' ),
			'terms_url'           => self::page_url( 'terms-of-service' ),
			'article_count'       => self::article_count(),
		);
	}

	/**
	 * How much of the site a non-member can read, from the paywall settings.
	 *
	 * @return array
	 */
	public static function reading() {
		$free   = 5;
		$period = 'monthly';
		if ( class_exists( 'CPPW_Settings' ) && method_exists( 'CPPW_Settings', 'get' ) ) {
			$settings = (array) CPPW_Settings::get();
			$free     = isset( $settings['free_articles_per_month'] ) ? (int) $settings['free_articles_per_month'] : $free;
			$period   = isset( $settings['meter_reset_period'] ) ? (string) $settings['meter_reset_period'] : $period;
		}
		$per = array(
			'daily'   => 'day',
			'weekly'  => 'week',
			'monthly' => 'month',
		);
		$unit = isset( $per[ $period ] ) ? $per[ $period ] : 'month';

		return array(
			'free_articles'  => $free,
			'period'         => $unit,
			'note'           => sprintf( 'Full articles are on christianpure.com. Anyone can read %d a %s free; members read without limits or ads.', $free, $unit ),
			'membership_url' => self::page_url( 'become-a-member' ),
		);
	}

	/**
	 * Rounded article count for descriptions ("more than 3,600").
	 *
	 * @return int
	 */
	public static function article_count() {
		$counts = wp_count_posts( 'post' );
		$total  = isset( $counts->publish ) ? (int) $counts->publish : 0;
		return $total >= 100 ? (int) ( floor( $total / 100 ) * 100 ) : $total;
	}

	/**
	 * The public card for one article. It carries the slug, not the post ID:
	 * directories such as ChatGPT's bar internal identifiers in tool output.
	 *
	 * @param WP_Post $post Published post.
	 * @return array
	 */
	public static function card( WP_Post $post ) {
		$topics = array();
		foreach ( (array) get_the_category( $post->ID ) as $term ) {
			if ( in_array( $term->slug, self::HIDDEN_TOPICS, true ) ) {
				continue;
			}
			$topics[] = array(
				'slug' => $term->slug,
				'name' => self::plain( $term->name ),
			);
		}

		$image = get_the_post_thumbnail_url( $post, 'large' );

		return array(
			'title'     => self::plain( get_the_title( $post ) ),
			'url'       => get_permalink( $post ),
			'slug'      => $post->post_name,
			'excerpt'   => self::excerpt( $post ),
			'summary'   => self::summary_points( $post->ID ),
			'topics'    => $topics,
			'image'     => $image ? $image : null,
			'published' => get_post_time( 'c', true, $post ),
			'updated'   => get_post_modified_time( 'c', true, $post ),
		);
	}

	/**
	 * The teaser a blocked reader sees, from the paywall's own function.
	 *
	 * If that function is unavailable (the paywall deactivated or failing to
	 * load), the connector fails closed: it uses the hand-written excerpt and
	 * never derives one from the article body.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function excerpt( WP_Post $post ) {
		if ( class_exists( 'CPPW_Content_Protection' ) && is_callable( array( 'CPPW_Content_Protection', 'safe_teaser_for_post' ) ) ) {
			return self::plain( (string) CPPW_Content_Protection::safe_teaser_for_post( $post, '' ) );
		}

		return self::plain( (string) $post->post_excerpt );
	}

	/**
	 * Summary points from the `article_summary` field (a `<ul>` of `<li>`).
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public static function summary_points( $post_id ) {
		$raw = get_post_meta( $post_id, 'article_summary', true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}

		$items = preg_match_all( '#<li\b[^>]*>(.*?)</li>#is', $raw, $matches ) ? $matches[1] : array( $raw );
		$items = array_values( array_filter( array_map( array( __CLASS__, 'plain' ), $items ), 'strlen' ) );

		return array_slice( $items, 0, 8 );
	}

	/**
	 * Whether a post may appear in connector output.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function is_listable( WP_Post $post ) {
		if ( 'post' !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return false;
		}
		if ( self::is_retired( $post->post_name ) ) {
			return false;
		}
		$excluded = self::excluded_category_ids();
		if ( $excluded && array_intersect( $excluded, wp_get_post_categories( $post->ID ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * The words of a query worth searching for, longest first.
	 *
	 * @param string $query       Cleaned query.
	 * @param bool   $drop_filler Whether to drop words such as "prayer" and "bible".
	 * @return string[]
	 */
	public static function useful_words( $query, $drop_filler = true ) {
		$words  = preg_split( '/\s+/u', mb_strtolower( $query ), -1, PREG_SPLIT_NO_EMPTY );
		$useful = array();
		foreach ( $words as $word ) {
			$word = trim( $word, " \t\n\r\0\x0B.,;:!?\"'()[]" );
			$word = preg_replace( "/(?:'|\x{2019})s$/u", '', $word );
			if ( mb_strlen( $word ) < 2 || in_array( $word, $useful, true ) ) {
				continue;
			}
			if ( $drop_filler && in_array( $word, self::FILLER_WORDS, true ) ) {
				continue;
			}
			$useful[] = $word;
		}

		usort(
			$useful,
			static function ( $a, $b ) {
				return mb_strlen( $b ) - mb_strlen( $a );
			}
		);

		return array_slice( $useful, 0, 5 );
	}

	/**
	 * A crude stem, so that "meaning" finds "Mean" and "mysteries" finds "Mystery".
	 *
	 * @param string $word Lower-case word.
	 * @return string
	 */
	public static function stem( $word ) {
		$length = mb_strlen( $word );
		foreach ( array(
			'ies' => 5,
			'ing' => 6,
			'es'  => 5,
			'ed'  => 5,
			's'   => 4,
		) as $suffix => $min ) {
			$cut = mb_strlen( $suffix );
			if ( $length >= $min && mb_substr( $word, -$cut ) === $suffix ) {
				return mb_substr( $word, 0, $length - $cut );
			}
		}
		return $word;
	}

	/**
	 * Candidate articles for a query, ranked.
	 *
	 * @param string $query Cleaned query.
	 * @param string $topic Category slug or ''.
	 * @param int    $limit Results wanted.
	 * @return array{results: array[], matched: string|null}
	 */
	private static function find( $query, $topic, $limit ) {
		$weights = self::word_weights( $query );
		$pool    = array();
		$matched = null;

		$all = self::query_posts( $query, $topic, 40, false );
		self::add_to_pool( $pool, $all, 'all', '' );
		if ( $all ) {
			$matched = $query;
		}

		$title_words = array();
		foreach ( array_slice( $weights, 0, 4, true ) as $word => $weight ) {
			$found = self::query_posts( self::stem( $word ), $topic, 20, true );
			if ( $found ) {
				$title_words[] = $word;
			}
			self::add_to_pool( $pool, $found, 'title', $word );
		}
		if ( null === $matched && $title_words ) {
			$matched = implode( ' OR ', $title_words );
		}

		if ( ! $pool ) {
			$text_words = array();
			foreach ( array_slice( $weights, 0, 4, true ) as $word => $weight ) {
				$found = self::query_posts( $word, $topic, 15, false );
				if ( $found ) {
					$text_words[] = $word;
				}
				self::add_to_pool( $pool, $found, 'text', $word );
			}
			$matched = $text_words ? implode( ' OR ', $text_words ) : null;
		}

		$results = self::rank( $pool, $weights, $limit );

		return array(
			'results' => $results,
			'matched' => $results ? $matched : null,
		);
	}

	/**
	 * Each useful word with its weight, rarest first.
	 *
	 * The weight is an inverse document frequency over published titles, so
	 * a name that heads four articles counts for more than "name", which
	 * heads hundreds.
	 *
	 * @param string $query Cleaned query.
	 * @return array<string, float>
	 */
	private static function word_weights( $query ) {
		$words = self::useful_words( $query );
		if ( ! $words ) {
			$words = self::useful_words( $query, false );
		}

		$total   = max( 1, self::published_total() );
		$weights = array();
		foreach ( $words as $word ) {
			$weights[ $word ] = log( ( $total + 1 ) / ( self::title_frequency( self::stem( $word ) ) + 1 ) ) + 1;
		}
		arsort( $weights );
		return $weights;
	}

	/**
	 * How many published article titles contain a stem, cached for twelve hours.
	 *
	 * @param string $stem Stem.
	 * @return int
	 */
	private static function title_frequency( $stem ) {
		$key   = 'df:' . md5( $stem );
		$count = wp_cache_get( $key, self::CACHE_GROUP );
		if ( false === $count ) {
			global $wpdb;
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_title LIKE %s",
					'%' . $wpdb->esc_like( $stem ) . '%'
				)
			);
			wp_cache_set( $key, $count, self::CACHE_GROUP, 12 * HOUR_IN_SECONDS );
		}
		return (int) $count;
	}

	/**
	 * Published article count.
	 *
	 * @return int
	 */
	private static function published_total() {
		$counts = wp_count_posts( 'post' );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * One WordPress search for published articles.
	 *
	 * @param string $search     Search words.
	 * @param string $topic      Category slug or ''.
	 * @param int    $count      Posts wanted.
	 * @param bool   $title_only Whether to search titles only.
	 * @return WP_Post[]
	 */
	private static function query_posts( $search, $topic, $count, $title_only ) {
		$args = array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			's'                      => $search,
			'posts_per_page'         => $count,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'has_password'           => false,
			'suppress_filters'       => true,
			'update_post_meta_cache' => false,
		);
		if ( $title_only ) {
			$args['search_columns'] = array( 'post_title' );
		}
		$excluded = self::excluded_category_ids();
		if ( $excluded ) {
			$args['category__not_in'] = $excluded;
		}
		if ( '' !== $topic ) {
			$args['category_name'] = $topic;
		}

		return array_values(
			array_filter(
				( new WP_Query( $args ) )->posts,
				static function ( $post ) {
					return $post instanceof WP_Post;
				}
			)
		);
	}

	/**
	 * Adds posts to the candidate pool, remembering how each was found.
	 *
	 * @param array     $pool   Pool, by post ID.
	 * @param WP_Post[] $posts  Posts found.
	 * @param string    $source 'all', 'title' or 'text'.
	 * @param string    $word   The word searched for, for 'text'.
	 */
	private static function add_to_pool( &$pool, $posts, $source, $word ) {
		foreach ( $posts as $post ) {
			if ( ! isset( $pool[ $post->ID ] ) ) {
				$pool[ $post->ID ] = array(
					'post'  => $post,
					'order' => count( $pool ),
					'all'   => false,
					'text'  => array(),
				);
			}
			if ( 'all' === $source ) {
				$pool[ $post->ID ]['all'] = true;
			} elseif ( 'text' === $source ) {
				$pool[ $post->ID ]['text'][] = $word;
			}
		}
	}

	/**
	 * Scores the pool and returns the best cards.
	 *
	 * A word in the title scores twice its weight, in the excerpt half, and
	 * found only in the text a quarter. Matching the whole query anywhere adds
	 * one. Ties keep the order the candidates were found in.
	 *
	 * Relevance floor: when any candidate has a query word in its title or
	 * excerpt, only those are kept. When none does, a single-word query keeps
	 * its text matches (a rare name can live in an article body) and a longer
	 * query returns nothing, so "stock market today" finds no articles rather
	 * than three that mention "stock" in passing.
	 *
	 * @param array                $pool    Candidate pool.
	 * @param array<string, float> $weights Word weights.
	 * @param int                  $limit   Results wanted.
	 * @return array[] Cards.
	 */
	private static function rank( $pool, $weights, $limit ) {
		foreach ( $pool as $id => $row ) {
			$title   = mb_strtolower( $row['post']->post_title );
			$excerpt = mb_strtolower( $row['post']->post_excerpt );
			$score   = $row['all'] ? 1.0 : 0.0;
			$strong  = false;
			foreach ( $weights as $word => $weight ) {
				$stem = self::stem( $word );
				if ( false !== mb_strpos( $title, $stem ) ) {
					$score += 2 * $weight;
					$strong = true;
				} elseif ( false !== mb_strpos( $excerpt, $stem ) ) {
					$score += 0.5 * $weight;
					$strong = true;
				} elseif ( in_array( $word, $row['text'], true ) ) {
					$score += 0.25 * $weight;
				}
			}
			$pool[ $id ]['score']  = $score;
			$pool[ $id ]['strong'] = $strong;
		}

		$strong = array_filter(
			$pool,
			static function ( $row ) {
				return $row['strong'];
			}
		);
		if ( $strong ) {
			$pool = $strong;
		} elseif ( count( $weights ) > 1 ) {
			return array();
		}

		uasort(
			$pool,
			static function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return $a['order'] - $b['order'];
				}
				return $a['score'] < $b['score'] ? 1 : -1;
			}
		);

		$posts = array();
		foreach ( $pool as $row ) {
			if ( count( $posts ) >= $limit ) {
				break;
			}
			if ( self::is_listable( $row['post'] ) ) {
				$posts[] = $row['post'];
			}
		}

		update_meta_cache( 'post', wp_list_pluck( $posts, 'ID' ) );
		return array_map( array( __CLASS__, 'card' ), $posts );
	}

	/**
	 * Visible categories with published posts, largest first.
	 *
	 * @return array[]
	 */
	private static function topic_rows() {
		static $rows = null;
		if ( null !== $rows ) {
			return $rows;
		}

		$rows  = array();
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => true,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $rows;
		}

		foreach ( $terms as $term ) {
			if ( in_array( $term->slug, self::HIDDEN_TOPICS, true ) || $term->count < 1 ) {
				continue;
			}
			$link   = get_term_link( $term );
			$rows[] = array(
				'slug'        => $term->slug,
				'name'        => self::plain( $term->name ),
				'count'       => (int) $term->count,
				'url'         => is_wp_error( $link ) ? null : $link,
				'description' => self::plain( $term->description ),
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);

		return $rows;
	}

	/**
	 * IDs of categories whose posts are excluded.
	 *
	 * @return int[]
	 */
	private static function excluded_category_ids() {
		static $ids = null;
		if ( null === $ids ) {
			$ids = array();
			foreach ( self::EXCLUDED_POST_CATEGORIES as $slug ) {
				$term = get_category_by_slug( $slug );
				if ( $term ) {
					$ids[] = (int) $term->term_id;
				}
			}
		}
		return $ids;
	}

	/**
	 * Whether a slug belongs to a retired news article (redirected to the archive).
	 *
	 * @param string $slug Post slug.
	 * @return bool
	 */
	private static function is_retired( $slug ) {
		if ( ! function_exists( 'christianpure_news_retirement_slugs' ) ) {
			return false;
		}
		$retired = christianpure_news_retirement_slugs();
		return isset( $retired[ $slug ] );
	}

	/**
	 * Slug from a christianpure.com article link, with or without a language prefix.
	 *
	 * @param string $url Link.
	 * @return string|WP_Error
	 */
	private static function slug_from_url( $url ) {
		$parts = wp_parse_url( trim( $url ) );
		$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$home  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( '' !== $host && $host !== $home && 'www.' . $home !== $host ) {
			return new WP_Error( 'cp_connector_foreign_url', 'Only christianpure.com article links can be looked up.', array( 'status' => 400 ) );
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		if ( ! preg_match( '#^/(?:[a-z]{2}(?:-[a-z]{2,4})?/)?(?:learn|blog)/([a-z0-9-]+)/?$#i', $path, $matches ) ) {
			return new WP_Error( 'cp_connector_not_article_url', 'That link is not a Christian Pure article. Article links look like https://christianpure.com/learn/{slug}/.', array( 'status' => 400 ) );
		}

		return strtolower( $matches[1] );
	}

	/**
	 * Published post for a slug, following renamed slugs when the redirect plugin knows them.
	 *
	 * @param string $slug Slug.
	 * @return WP_Post|null
	 */
	private static function post_by_slug( $slug ) {
		if ( '' === $slug ) {
			return null;
		}

		$post = get_page_by_path( $slug, OBJECT, 'post' );
		if ( ( ! $post || 'publish' !== $post->post_status ) && function_exists( 'christianpure_legacy_canonical_slug' ) ) {
			$canonical = (string) christianpure_legacy_canonical_slug( $slug );
			if ( '' !== $canonical && $canonical !== $slug ) {
				$post = get_page_by_path( $canonical, OBJECT, 'post' );
			}
		}

		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Short course description: the hand-written excerpt, or the SEO description.
	 *
	 * Never taken from the course page's own content.
	 *
	 * @param WP_Post $course Course.
	 * @return string
	 */
	private static function course_description( WP_Post $course ) {
		$text = self::plain( (string) $course->post_excerpt );
		if ( '' === $text ) {
			$text = self::plain( (string) get_post_meta( $course->ID, '_genesis_description', true ) );
		}
		return $text;
	}

	/**
	 * Permalink of a published page by path, or the home URL plus that path.
	 *
	 * @param string $path Page path.
	 * @return string
	 */
	private static function page_url( $path ) {
		$page = get_page_by_path( $path );
		if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
			return get_permalink( $page );
		}
		return home_url( '/' . trim( $path, '/' ) . '/' );
	}

	/**
	 * Plain text: no tags, entities decoded, whitespace collapsed.
	 *
	 * @param string $text Text or HTML.
	 * @return string
	 */
	public static function plain( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Trimmed, tag-free text capped at a length.
	 *
	 * @param string $text  Input.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	public static function clean_text( $text, $limit ) {
		return mb_substr( self::plain( sanitize_text_field( (string) $text ) ), 0, $limit );
	}
}
