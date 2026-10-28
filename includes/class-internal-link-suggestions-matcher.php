<?php
/**
 * Finds link suggestions in both directions.
 *
 * Outbound: posts related to a draft (shared rare words), kept only when the
 * draft has words that can carry the link: the post's keyword, its whole
 * title, or a run of its title's words (for "How to Choose Running Shoes for
 * Flat Feet", the draft's "running shoes" or "shoes for flat feet").
 *
 * Inbound: the same match turned around. Older posts that mention this
 * post's title or keywords in a paragraph, and don't link to it yet.
 *
 * @package Internal_Link_Suggestions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Suggestion engine.
 */
final class Internal_Link_Suggestions_Matcher {

	/**
	 * Related posts checked for anchor text per outbound request.
	 */
	const CANDIDATES = 150;

	/**
	 * Posts read per inbound request, from each way of finding them.
	 */
	const SOURCES = 120;

	/**
	 * Lowest score shown.
	 */
	const MIN_SCORE = 1.3;

	/**
	 * Plugin instance.
	 *
	 * @var Internal_Link_Suggestions_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Internal_Link_Suggestions_Plugin $plugin Plugin instance.
	 */
	public function __construct( Internal_Link_Suggestions_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	// Outbound -------------------------------------------------------------------

	/**
	 * Posts to link to from a draft.
	 *
	 * @param int      $post_id  The draft's post ID.
	 * @param string   $title    Its title as typed.
	 * @param string   $content  Its content (HTML) as typed.
	 * @param string[] $keywords Its link keywords.
	 * @return array{items:array,linked:int}
	 */
	public function outbound( $post_id, $title, $content, array $keywords = array() ) {
		$content = Internal_Link_Suggestions_Text::cut( (string) $content, Internal_Link_Suggestions_Text::MAX_BYTES );
		$doc     = $this->read( $content );
		$linked = array_keys( $this->plugin->indexer()->extract_links( $content, $post_id ) );
		$out    = array(
			'items'  => array(),
			'linked' => count( $linked ),
		);

		if ( ! $doc['tokens'] ) {
			return $out;
		}

		$title_keys = array_values( array_unique( Internal_Link_Suggestions_Text::phrase_keys( $title ) ) );
		$tf         = $doc['tf'];

		foreach ( $title_keys as $key ) {
			$tf[ $key ] = ( isset( $tf[ $key ] ) ? $tf[ $key ] : 0 ) + 4;
		}
		foreach ( $keywords as $keyword ) {
			foreach ( Internal_Link_Suggestions_Text::phrase_keys( $keyword ) as $key ) {
				$tf[ $key ] = ( isset( $tf[ $key ] ) ? $tf[ $key ] : 0 ) + 3;
			}
		}

		$stats   = $this->rarity( array_keys( $tf ) );
		$related = Internal_Link_Suggestions_DB::related( $this->weights( $tf, $stats ), array_merge( array( (int) $post_id ), $linked ), $this->plugin->post_types(), self::CANDIDATES );

		if ( ! $related ) {
			return $out;
		}

		$top        = max( $related );
		$rows       = Internal_Link_Suggestions_DB::get_posts( array_keys( $related ) );
		$max_words  = (int) $this->plugin->get_setting( 'max_words' );
		$candidates = array();
		$phrases    = array();

		foreach ( $rows as $target_id => $row ) {
			$phrases[ $target_id ] = $this->phrases( $row['title'], Internal_Link_Suggestions_Indexer::split_keywords( $row['keywords'] ) );
		}

		// The candidates' own words need their rarity too (a one-word match
		// is compared with the rest of its title).
		$stats = $this->rarity( array_keys( $tf ), $phrases, $stats );

		foreach ( $related as $target_id => $score ) {
			if ( ! isset( $rows[ $target_id ] ) ) {
				continue;
			}

			$best = $this->best_anchor( $doc, $phrases[ $target_id ], $stats['idf'], array(), $max_words, $title_keys );

			if ( ! $best ) {
				continue;
			}

			$candidates[] = array(
				'id'      => $target_id,
				'row'     => $rows[ $target_id ],
				'phrases' => $phrases[ $target_id ],
				'topic'   => $top > 0 ? $score / $top : 0,
				'best'    => $best,
				'final'   => $this->final_score( $best['score'], $top > 0 ? $score / $top : 0, (int) $rows[ $target_id ]['inbound'] ),
			);
		}

		usort(
			$candidates,
			function ( $a, $b ) {
				return $b['final'] <=> $a['final'];
			}
		);

		// Each place in the draft carries one link: a post whose best words
		// are taken gets its next best place, if it has one.
		$used  = array();
		$limit = (int) $this->plugin->get_setting( 'limit' );

		foreach ( $candidates as $candidate ) {
			$best  = $candidate['best'];
			$final = $candidate['final'];

			if ( isset( $used[ $best['span'] ] ) ) {
				$best = $this->best_anchor( $doc, $candidate['phrases'], $stats['idf'], $used, $max_words, $title_keys );
				if ( ! $best ) {
					continue;
				}
				$final = $this->final_score( $best['score'], $candidate['topic'], (int) $candidate['row']['inbound'] );
			}

			if ( $final < self::MIN_SCORE ) {
				continue;
			}

			$used[ $best['span'] ] = true;
			$out['items'][]        = $this->outbound_item( $candidate['id'], $candidate['row'], $best, $final, $doc );

			if ( count( $out['items'] ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * One outbound suggestion for the editor.
	 *
	 * @param int   $target_id Linked post.
	 * @param array $row       Its index row.
	 * @param array $best      Match, see best_anchor().
	 * @param float $final     Score.
	 * @param array $doc       The draft, see read().
	 * @return array
	 */
	private function outbound_item( $target_id, array $row, array $best, $final, array $doc ) {
		$snippet = $this->snippet( $doc, $best );

		return array(
			'id'       => (int) $target_id,
			'title'    => (string) $row['title'],
			'url'      => (string) get_permalink( $target_id ),
			'type'     => $this->type_label( $row['post_type'] ),
			'date'     => $row['published'] ? wp_date( get_option( 'date_format' ), (int) $row['published'] ) : '',
			'anchor'   => $snippet['anchor'],
			'before'   => $snippet['before'],
			'after'    => $snippet['after'],
			'context'  => $snippet['context'],
			'reason'   => $this->reason( $best ),
			'strength' => $this->strength( $final ),
			'orphaned' => 0 === (int) $row['inbound'],
		);
	}

	// Inbound --------------------------------------------------------------------

	/**
	 * Older posts that should link to a post.
	 *
	 * @param int         $post_id  Post to link to.
	 * @param string|null $title    Its title as typed (null: saved title).
	 * @param array|null  $keywords Its own link keywords as typed (null: saved).
	 * @param string|null $content  Its content as typed (null: saved).
	 * @return array{items:array,existing:int}
	 */
	public function inbound( $post_id, $title = null, $keywords = null, $content = null ) {
		global $wpdb;

		$post = get_post( $post_id );

		if ( ! $post ) {
			return array(
				'items'    => array(),
				'existing' => 0,
			);
		}

		$title    = null === $title ? $post->post_title : $title;
		$keywords = Internal_Link_Suggestions_Indexer::clean_keywords( array_merge( null === $keywords ? Internal_Link_Suggestions_Indexer::own_keywords( $post->ID ) : (array) $keywords, Internal_Link_Suggestions_Indexer::seo_keywords( $post->ID ) ) );
		$content  = null === $content ? $post->post_content : $content;
		$phrases  = $this->phrases( $title, $keywords );
		$existing = Internal_Link_Suggestions_DB::sources_of( $post->ID );
		$out      = array(
			'items'    => array(),
			'existing' => count( array_diff( $existing, array( $post->ID ) ) ),
		);

		if ( ! $phrases ) {
			return $out;
		}

		// What the post is about, to rank posts that mention it.
		$tf = $this->read( Internal_Link_Suggestions_Text::cut( $content, Internal_Link_Suggestions_Text::MAX_BYTES ) )['tf'];
		foreach ( $phrases as $phrase ) {
			foreach ( $phrase['keys'] as $key ) {
				$tf[ $key ] = ( isset( $tf[ $key ] ) ? $tf[ $key ] : 0 ) + ( 'title' === $phrase['type'] ? 4 : 3 );
			}
		}

		$stats   = $this->rarity( array_keys( $tf ) );
		$types   = $this->plugin->post_types();
		$skip    = array_merge( array( (int) $post->ID ), $existing );
		$related = Internal_Link_Suggestions_DB::related( $this->weights( $tf, $stats ), $skip, $types, self::SOURCES, false );
		$found   = Internal_Link_Suggestions_DB::containing( $this->surface_phrases( $title, $keywords ), $skip, $types, self::SOURCES );
		$ids     = array_values( array_unique( array_merge( array_keys( $related ), $found ) ) );
		$top     = $related ? max( $related ) : 0;

		if ( ! $ids ) {
			return $out;
		}

		$max_words  = (int) $this->plugin->get_setting( 'max_words' );
		$candidates = array();

		foreach ( array_chunk( $ids, 40 ) as $chunk ) {
			$posts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_content, post_type, post_password FROM {$wpdb->posts} WHERE post_status = 'publish' AND ID IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')', $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery

			foreach ( (array) $posts as $source ) {
				// Password-protected posts keep their words to themselves.
				if ( '' !== $source->post_password || ! $this->may_mention( $source->post_content, $phrases ) ) {
					continue;
				}

				$doc  = $this->read( Internal_Link_Suggestions_Text::cut( $source->post_content, Internal_Link_Suggestions_Text::MAX_BYTES ) );
				$best = $this->best_anchor( $doc, $phrases, $stats['idf'], array(), $max_words, Internal_Link_Suggestions_Text::phrase_keys( $source->post_title ), false );

				if ( ! $best ) {
					continue;
				}

				$topic = isset( $related[ (int) $source->ID ] ) && $top > 0 ? $related[ (int) $source->ID ] / $top : 0.1;
				$final = $this->final_score( $best['score'], $topic, 1 );

				if ( $final < self::MIN_SCORE ) {
					continue;
				}

				$snippet      = $this->snippet( $doc, $best );
				$candidates[] = array(
					'final'   => $final,
					'source'  => $source,
					'best'    => $best,
					'snippet' => $snippet,
				);
			}
		}

		usort(
			$candidates,
			function ( $a, $b ) {
				return $b['final'] <=> $a['final'];
			}
		);

		foreach ( array_slice( $candidates, 0, (int) $this->plugin->get_setting( 'limit' ) ) as $candidate ) {
			$out['items'][] = $this->inbound_item( $candidate['source'], $candidate['best'], $candidate['snippet'], $candidate['final'] );
		}

		return $out;
	}

	/**
	 * One inbound suggestion.
	 *
	 * @param object $source  Post row (ID, post_title, post_type).
	 * @param array  $best    Match.
	 * @param array  $snippet Sentence parts.
	 * @param float  $final   Score.
	 * @return array
	 */
	private function inbound_item( $source, array $best, array $snippet, $final ) {
		$source_id = (int) $source->ID;
		$blocked   = Internal_Link_Suggestions_Linker::blocked( $source_id );
		$edit      = current_user_can( 'edit_post', $source_id ) ? get_edit_post_link( $source_id, 'raw' ) : '';

		return array(
			'id'       => $source_id,
			'title'    => Internal_Link_Suggestions_Text::plain( '' === trim( $source->post_title ) ? __( '(no title)', 'internal-link-suggestions' ) : $source->post_title ),
			'url'      => (string) get_permalink( $source_id ),
			'edit'     => $edit ? (string) $edit : '',
			'type'     => $this->type_label( $source->post_type ),
			'anchor'   => $snippet['anchor'],
			'before'   => $snippet['before'],
			'after'    => $snippet['after'],
			'context'  => $snippet['context'],
			'reason'   => $this->reason( $best ),
			'strength' => $this->strength( $final ),
			'canAdd'   => '' === $blocked,
			'blocked'  => $blocked,
		);
	}

	/**
	 * Quick check before reading a whole post: does its HTML contain the
	 * start of enough of a phrase's words to possibly match?
	 *
	 * @param string $html    Post content.
	 * @param array  $phrases Phrases, see phrases().
	 * @return bool
	 */
	private function may_mention( $html, array $phrases ) {
		$html = (string) $html;

		foreach ( $phrases as $phrase ) {
			$need = 'keyword' === $phrase['type'] ? count( $phrase['keys'] ) : min( 2, count( $phrase['keys'] ) );
			$have = 0;

			foreach ( array_unique( $phrase['keys'] ) as $key ) {
				// "category" is folded from "categories": look for "categor".
				$stem = strlen( $key ) > 4 && 'y' === substr( $key, -1 ) ? substr( $key, 0, -1 ) : $key;
				if ( false !== stripos( $html, $stem ) ) {
					++$have;
				}
			}

			if ( $have >= $need ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Phrases for finding posts with LIKE: keywords, the title, and each
	 * pair of neighbouring title words ("raised bed", "flat feet").
	 *
	 * @param string   $title    Title.
	 * @param string[] $keywords Keywords.
	 * @return string[]
	 */
	private function surface_phrases( $title, array $keywords ) {
		$out   = array();
		$title = Internal_Link_Suggestions_Text::plain( $title );

		foreach ( $keywords as $keyword ) {
			if ( strlen( $keyword ) >= 3 ) {
				$out[] = $keyword;
			}
		}

		$sig = array();
		foreach ( Internal_Link_Suggestions_Text::words( $title ) as $word ) {
			$key = Internal_Link_Suggestions_Text::key( $word[0] );
			if ( ! Internal_Link_Suggestions_Text::is_stop( $key ) ) {
				$sig[] = array( $word, $key );
			}
		}

		if ( 1 === count( $sig ) && ! Internal_Link_Suggestions_Text::is_weak( $sig[0][1] ) ) {
			$out[] = $sig[0][0][0];
		}

		for ( $i = 0, $n = count( $sig ); $i + 1 < $n; $i++ ) {
			if ( Internal_Link_Suggestions_Text::is_weak( $sig[ $i ][1] ) && Internal_Link_Suggestions_Text::is_weak( $sig[ $i + 1 ][1] ) ) {
				continue;
			}

			$from   = $sig[ $i ][0][1];
			$phrase = substr( $title, $from, $sig[ $i + 1 ][0][1] + strlen( $sig[ $i + 1 ][0][0] ) - $from );

			if ( preg_match( '/[^\s\p{L}\p{N}\'\x{2019}\-]/u', $phrase ) ) {
				continue;
			}

			// "Raised Beds" also finds "raised bed".
			$out[] = 's' === substr( $phrase, -1 ) ? substr( $phrase, 0, -1 ) : $phrase;
		}

		$unique = array();
		foreach ( $out as $phrase ) {
			$unique[ strtolower( $phrase ) ] = $phrase;
		}

		return array_slice( array_values( $unique ), 0, 14 );
	}

	// Matching -------------------------------------------------------------------

	/**
	 * Read HTML into what matching needs.
	 *
	 * tokens: every meaningful word in a linkable run, in order, with its
	 * chunk and byte offsets, and "joined" when only spaces and stopwords
	 * separate it from the previous token (so the two can share an anchor).
	 * index: word key => token positions. tf: word key => count, from all
	 * text (headings too). chunks: from Text::chunks().
	 *
	 * @param string $html HTML.
	 * @return array{tokens:array,index:array,tf:array,chunks:array}
	 */
	public function read( $html ) {
		$chunks = Internal_Link_Suggestions_Text::chunks( $html );
		$tokens = array();
		$index  = array();
		$tf     = array();

		foreach ( $chunks as $c => $chunk ) {
			foreach ( $chunk['runs'] as $run ) {
				$text  = substr( $chunk['text'], $run['from'], $run['to'] - $run['from'] );
				$prev  = null;
				$stops = 0;

				foreach ( Internal_Link_Suggestions_Text::words( $text ) as $word ) {
					$key = Internal_Link_Suggestions_Text::key( $word[0] );

					if ( Internal_Link_Suggestions_Text::is_stop( $key ) ) {
						++$stops;
						continue;
					}

					$tf[ $key ] = isset( $tf[ $key ] ) ? $tf[ $key ] + 1 : 1;

					if ( ! $run['linkable'] ) {
						continue;
					}

					$start  = $run['from'] + $word[1];
					$joined = false;

					if ( null !== $prev && $stops <= 3 ) {
						$gap    = substr( $chunk['text'], $tokens[ $prev ]['end'], $start - $tokens[ $prev ]['end'] );
						$joined = (bool) preg_match( '/^[\s\x{00A0}&+\p{L}\'\x{2019}]*$/u', $gap );
					}

					$tokens[]        = array(
						'key'    => $key,
						'chunk'  => $c,
						'start'  => $start,
						'end'    => $start + strlen( $word[0] ),
						'joined' => $joined,
					);
					$prev            = count( $tokens ) - 1;
					$index[ $key ][] = $prev;
					$stops           = 0;
				}
			}
		}

		return array(
			'tokens' => $tokens,
			'index'  => $index,
			'tf'     => $tf,
			'chunks' => $chunks,
		);
	}

	/**
	 * A post's phrases: its title, then its keywords.
	 *
	 * @param string   $title    Title.
	 * @param string[] $keywords Keywords.
	 * @return array<int,array{type:string,keys:string[],text:string}>
	 */
	private function phrases( $title, array $keywords ) {
		$out  = array();
		$keys = Internal_Link_Suggestions_Text::phrase_keys( $title );

		if ( $keys ) {
			$out[] = array(
				'type' => 'title',
				'keys' => $keys,
				'text' => Internal_Link_Suggestions_Text::plain( $title ),
			);
		}

		foreach ( $keywords as $keyword ) {
			$keys = Internal_Link_Suggestions_Text::phrase_keys( $keyword );
			if ( $keys && count( $keys ) <= 8 ) {
				$out[] = array(
					'type' => 'keyword',
					'keys' => $keys,
					'text' => $keyword,
				);
			}
		}

		return $out;
	}

	/**
	 * The best place in a document for a link to a post with these phrases.
	 *
	 * @param array    $doc        Document, see read().
	 * @param array    $phrases    The linked post's phrases.
	 * @param array    $idf        Word key => rarity.
	 * @param array    $used       Spans already taken ("chunk:offset" => true).
	 * @param int      $max_words  Longest anchor, in words.
	 * @param string[] $topic_keys The document's own title words.
	 * @param bool     $single     Allow one-word matches (outbound only: the
	 *                             writer sees and decides on each one).
	 * @return array|null array( score, token, len, type, phrase, full, span ).
	 */
	private function best_anchor( array $doc, array $phrases, array $idf, array $used, $max_words, array $topic_keys, $single = true ) {
		$best = null;

		foreach ( $phrases as $phrase ) {
			$keys = $phrase['keys'];
			$n    = count( $keys );

			for ( $i = 0; $i < $n; $i++ ) {
				// A keyword only counts whole.
				if ( 'keyword' === $phrase['type'] && $i > 0 ) {
					break;
				}

				if ( empty( $doc['index'][ $keys[ $i ] ] ) ) {
					continue;
				}

				foreach ( $doc['index'][ $keys[ $i ] ] as $t ) {
					// Follow the document's next words through the phrase, in
					// order. A keyword must match word for word; a title may
					// skip up to two of its words ("watering in hot weather"
					// for "Watering Your Vegetable Garden in Hot Weather").
					$matched = array( $keys[ $i ] );
					$pos     = $i;
					$reach   = 'title' === $phrase['type'] ? 3 : 1;

					while ( isset( $doc['tokens'][ $t + count( $matched ) ] ) && $doc['tokens'][ $t + count( $matched ) ]['joined'] ) {
						$next  = $doc['tokens'][ $t + count( $matched ) ]['key'];
						$found = -1;

						for ( $p = $pos + 1; $p < $n && $p <= $pos + $reach; $p++ ) {
							if ( $keys[ $p ] === $next ) {
								$found = $p;
								break;
							}
						}

						if ( $found < 0 ) {
							break;
						}

						$matched[] = $next;
						$pos       = $found;
					}

					$len   = count( $matched );
					$score = $this->rate( $phrase, $i, $matched, $idf, $topic_keys, $single );

					if ( ! $score || ( $best && $score <= $best['score'] ) ) {
						continue;
					}

					$first = $doc['tokens'][ $t ];
					$last  = $doc['tokens'][ $t + $len - 1 ];
					$span  = $first['chunk'] . ':' . $first['start'];

					if ( isset( $used[ $span ] ) ) {
						continue;
					}

					$text = substr( $doc['chunks'][ $first['chunk'] ]['text'], $first['start'], $last['end'] - $first['start'] );
					if ( count( Internal_Link_Suggestions_Text::words( $text ) ) > $max_words ) {
						continue;
					}

					$best = array(
						'score'  => $score,
						'token'  => $t,
						'len'    => $len,
						'type'   => $phrase['type'],
						'phrase' => $phrase['text'],
						'full'   => $len === $n,
						'span'   => $span,
					);
				}
			}
		}

		return $best;
	}

	/**
	 * How good a match is, or 0 when it isn't one.
	 *
	 * @param array    $phrase     Phrase.
	 * @param int      $i          Where in the phrase the match starts.
	 * @param string[] $matched    The phrase's words that matched, in order.
	 * @param array    $idf        Word key => rarity.
	 * @param string[] $topic_keys The document's own title words.
	 * @param bool     $single     Allow one-word matches.
	 * @return float
	 */
	private function rate( array $phrase, $i, array $matched, array $idf, array $topic_keys, $single ) {
		$n      = count( $phrase['keys'] );
		$len    = count( $matched );
		$strong = 0;
		$info   = 0.0;

		foreach ( $matched as $key ) {
			if ( ! Internal_Link_Suggestions_Text::is_weak( $key ) ) {
				++$strong;
			}
			$info += isset( $idf[ $key ] ) ? $idf[ $key ] : $idf['*'];
		}

		if ( ! $strong ) {
			return 0.0;
		}

		if ( 'keyword' === $phrase['type'] ) {
			return 0 === $i && $len === $n ? 3.2 + 0.25 * $len : 0.0;
		}

		if ( $len === $n ) {
			// A one-word title ("Composting") needs to be a rare word.
			return 1 === $n && $info < 1.5 ? 0.0 : 3.0 + 0.25 * $len;
		}

		$own = $topic_keys && ! array_diff( $matched, $topic_keys );

		// Part of a title needs two meaningful words: "best tools" is one
		// ("best" says nothing) and doesn't fit "The 12 Best SEO Tools".
		if ( $strong < 2 ) {
			if ( ! $single || $own ) {
				return 0.0;
			}

			// One rare word that is clearly what a short title is about:
			// "mulch" for "The Best Mulch for Vegetable Gardens" on a garden
			// blog, "compost" for "How to Start Composting at Home". Not
			// "crop" for "Crop Rotation for Small Gardens" or "readers" for
			// "Why Readers Leave Before the End": their other words say as much.
			$word = '';
			foreach ( $matched as $key ) {
				if ( ! Internal_Link_Suggestions_Text::is_weak( $key ) ) {
					$word = $key;
				}
			}

			$rarity = isset( $idf[ $word ] ) ? $idf[ $word ] : $idf['*'];
			$others = array_diff(
				array_filter(
					array_unique( $phrase['keys'] ),
					function ( $key ) {
						return ! Internal_Link_Suggestions_Text::is_weak( $key );
					}
				),
				array( $word )
			);

			if ( $rarity < 2.5 || count( $others ) > 2 ) {
				return 0.0;
			}

			foreach ( $others as $other ) {
				if ( ( isset( $idf[ $other ] ) ? $idf[ $other ] : $idf['*'] ) > 0.8 * $rarity ) {
					return 0.0;
				}
			}

			return 1.3 + 1.5 / ( 1 + count( $others ) );
		}

		if ( $info < 2.5 ) {
			return 0.0;
		}

		$score = 1.0 + 2.0 * ( $len / $n ) + 0.15 * $len;

		// Words that are the document's own topic make a weaker case: they're
		// everywhere in it, and linking them away reads like a different subject.
		if ( $own ) {
			$score *= 0.65;
		}

		return $score;
	}

	/**
	 * Final ranking: match quality, nudged by how related the posts are, and
	 * a little extra for posts nothing links to yet.
	 *
	 * @param float $match   Match score.
	 * @param float $topic   Relatedness, 0 to 1.
	 * @param int   $inbound Links the target already has.
	 * @return float
	 */
	private function final_score( $match, $topic, $inbound ) {
		return $match * ( 0.55 + 0.45 * max( 0.0, min( 1.0, $topic ) ) ) * ( 0 === $inbound ? 1.1 : 1.0 );
	}

	/**
	 * How rare words are across the index.
	 *
	 * @param string[]   $keys     Word keys.
	 * @param array      $phrases  Optional: phrases (by post) whose words need
	 *                             their rarity too.
	 * @param array|null $previous Optional: an earlier result to add to.
	 * @return array{idf:array<string,float>,df:array<string,int>,n:int} idf
	 *         includes '*', the rarity of a word in no post.
	 */
	private function rarity( array $keys, array $phrases = array(), $previous = null ) {
		foreach ( $phrases as $list ) {
			foreach ( $list as $phrase ) {
				$keys = array_merge( $keys, $phrase['keys'] );
			}
		}

		$keys = array_values( array_unique( array_map( 'strval', $keys ) ) );
		$n    = $previous ? $previous['n'] : max( 1, Internal_Link_Suggestions_DB::count_posts() );
		$df   = $previous ? $previous['df'] : array();
		$idf  = $previous ? $previous['idf'] : array( '*' => log( ( $n + 1 ) / 0.5 ) );
		$new  = $previous ? array_values( array_diff( $keys, array_keys( $idf ) ) ) : $keys;

		if ( $new ) {
			$df = Internal_Link_Suggestions_DB::document_frequency( $new ) + $df;
		}

		foreach ( $keys as $key ) {
			if ( ! isset( $idf[ $key ] ) ) {
				$idf[ $key ] = log( ( $n + 1 ) / ( ( isset( $df[ $key ] ) ? $df[ $key ] : 0 ) + 0.5 ) );
			}
		}

		return array(
			'idf' => $idf,
			'df'  => $df,
			'n'   => $n,
		);
	}

	/**
	 * Word weights for finding related posts: frequent in the document, rare
	 * across the site. Words in a third of all posts say nothing.
	 *
	 * @param array<string,int> $tf    Word key => count.
	 * @param array             $stats See rarity().
	 * @return array<string,float>
	 */
	private function weights( array $tf, array $stats ) {
		$weights = array();

		foreach ( $tf as $key => $count ) {
			$df = isset( $stats['df'][ $key ] ) ? $stats['df'][ $key ] : 0;

			if ( ! $df || ( $stats['n'] >= 20 && $df > $stats['n'] / 3 ) ) {
				continue;
			}

			$weights[ (string) $key ] = min( $count, 6 ) * $stats['idf'][ $key ];
		}

		return $weights;
	}

	/**
	 * The sentence a match sits in.
	 *
	 * @param array $doc  Document.
	 * @param array $best Match.
	 * @return array{before:string,anchor:string,after:string,context:string}
	 */
	private function snippet( array $doc, array $best ) {
		$first = $doc['tokens'][ $best['token'] ];
		$last  = $doc['tokens'][ $best['token'] + $best['len'] - 1 ];

		return Internal_Link_Suggestions_Text::sentence( $doc['chunks'][ $first['chunk'] ]['text'], $first['start'], $last['end'] );
	}

	/**
	 * Why a suggestion was made, in words.
	 *
	 * @param array $best Match.
	 * @return string
	 */
	private function reason( array $best ) {
		if ( 'keyword' === $best['type'] ) {
			/* translators: %s: keyword. */
			return sprintf( __( 'Keyword: %s', 'internal-link-suggestions' ), $best['phrase'] );
		}

		return $best['full'] ? __( 'Matches its title', 'internal-link-suggestions' ) : __( 'Words from its title', 'internal-link-suggestions' );
	}

	/**
	 * A score as strong, good or fair.
	 *
	 * @param float $final Score.
	 * @return string
	 */
	private function strength( $final ) {
		if ( $final >= 2.9 ) {
			return 'strong';
		}

		return $final >= 2.0 ? 'good' : 'fair';
	}

	/**
	 * A post type's singular name.
	 *
	 * @param string $type Post type.
	 * @return string
	 */
	private function type_label( $type ) {
		$object = get_post_type_object( $type );

		return $object ? (string) $object->labels->singular_name : (string) $type;
	}
}
