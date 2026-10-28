<?php
/**
 * Reading post HTML as words and sentences.
 *
 * Works out which text can take a link (paragraphs and list items, never
 * headings, existing links, code or a shortcode's attributes), how words
 * compare (lowercase, plural folded, stopwords dropped), and where a phrase
 * sits in the raw HTML, so a link can be wrapped around it without touching
 * a single other byte.
 *
 * @package Internal_Link_Suggestions
 */

defined( 'ABSPATH' ) || exit;

/**
 * Text helpers. Everything is static and side-effect free.
 */
final class Internal_Link_Suggestions_Text {

	/**
	 * Elements whose text never takes a new link.
	 */
	const SKIP_TAGS = array( 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'code', 'pre', 'kbd', 'samp', 'var', 'script', 'style', 'textarea', 'button', 'select', 'option', 'label', 'svg', 'math', 'iframe', 'object', 'video', 'audio', 'noscript', 'template', 'cite', 'title', 'figcaption' );

	/**
	 * Elements that end one paragraph-level chunk and start the next.
	 */
	const BREAK_TAGS = array( 'p', 'div', 'li', 'ul', 'ol', 'dl', 'dt', 'dd', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'section', 'article', 'header', 'footer', 'aside', 'nav', 'main', 'figure', 'figcaption', 'pre', 'details', 'summary', 'hr', 'br', 'address', 'form', 'fieldset' );

	/**
	 * Most bytes of a post's HTML read for suggestions and the index (about
	 * 70,000 words), so a huge or malformed post can't eat a request's memory.
	 */
	const MAX_BYTES = 500000;

	/**
	 * Elements that never have a closing tag.
	 */
	const VOID_TAGS = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );

	/**
	 * Blocks whose saved HTML is where their text lives, so a link added to
	 * it keeps the block valid. Text in other blocks (custom HTML, buttons,
	 * third-party blocks that rebuild their HTML from attributes) is left
	 * alone. Content without blocks (the classic editor) is all fair game.
	 */
	const BLOCKS = array( 'core/paragraph', 'core/list', 'core/list-item', 'core/quote', 'core/freeform' );

	/**
	 * Words that never start, end or make a match on their own.
	 */
	const STOPWORDS = 'a about above after again against all almost also am among an and another any anyone anything are around as at away back be because been before being below between both but by can cannot could did do does doing done down during each either else enough etc even ever every few for from further get gets getting go goes going got had has have having he her here hers herself him himself his how however i if in into is it its itself just let lets like ll made make makes many may me might more most much must my myself near need needs neither never no nor not now of off often on once one only onto or other others otherwise our ours ourselves out over own per quite rather re really s same say says see seen she should since so some something soon still such t than that the their theirs them themselves then there these they thing things this those though through thus to too toward towards under until up upon us use used uses using ve very via was way ways we well were what whatever when where whether which while who whom whose why will with within without would yet you your yours yourself yourselves';

	/**
	 * Words that may sit inside an anchor but can't carry a title match by
	 * themselves ("ultimate guide" says nothing about the topic).
	 */
	const WEAK_WORDS = 'area beginner beginners best better big biggest case change check common complete day days definitive easiest easy end essential everything example examples fact fast faster first form free full good great guide guides hand help helpful home house how-to idea ideas important introduction key kind last level life little long lot lots month new next number part people person place point problem question quick real reason reasons result review reviews right side simple small sort start step steps sure thing time times tip tips today top tutorial type types ultimate updated way week work world year years';

	/**
	 * Cached word lists.
	 *
	 * @var array<string,array<string,bool>>
	 */
	private static $lists = array();

	// Words ------------------------------------------------------------------

	/**
	 * Stopwords as a lookup, including the site's own "words to ignore".
	 *
	 * @return array<string,bool>
	 */
	public static function stopwords() {
		if ( ! isset( self::$lists['stop'] ) ) {
			$words = explode( ' ', self::STOPWORDS );
			$extra = function_exists( 'internal_link_suggestions_plugin' ) ? internal_link_suggestions_plugin()->get_setting( 'ignore_words' ) : '';

			foreach ( preg_split( '/[\s,]+/u', (string) $extra, -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
				$words[] = self::key( $word );
			}

			/**
			 * Filters the words that never start, end or make a suggestion.
			 *
			 * @param string[] $words Lowercase words, plurals folded (see key()).
			 */
			$words = apply_filters( 'internal_link_suggestions_stopwords', $words );

			// Folded the same way as the words they're compared with.
			self::$lists['stop'] = array();
			foreach ( (array) $words as $word ) {
				self::$lists['stop'][ (string) $word ]              = true;
				self::$lists['stop'][ self::key( (string) $word ) ] = true;
			}
		}

		return self::$lists['stop'];
	}

	/**
	 * Forget cached word lists (after the settings change).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$lists = array();
	}

	/**
	 * Whether a word key is a stopword.
	 *
	 * @param string $key Word key.
	 * @return bool
	 */
	public static function is_stop( $key ) {
		$stop = self::stopwords();

		return isset( $stop[ $key ] ) || '' === $key || ( strlen( $key ) < 2 && ! preg_match( '/^\d$/', $key ) );
	}

	/**
	 * Whether a word key is weak: allowed inside a match, never its reason.
	 *
	 * @param string $key Word key.
	 * @return bool
	 */
	public static function is_weak( $key ) {
		if ( ! isset( self::$lists['weak'] ) ) {
			self::$lists['weak'] = array();
			foreach ( explode( ' ', self::WEAK_WORDS ) as $word ) {
				self::$lists['weak'][ $word ]              = true;
				self::$lists['weak'][ self::key( $word ) ] = true;
			}
		}

		return isset( self::$lists['weak'][ $key ] ) || (bool) preg_match( '/^\d+$/', $key );
	}

	/**
	 * A word as it's compared: lowercase, curly apostrophes straightened,
	 * possessive and plural endings folded ("Shoes" and "shoe's" both become
	 * "shoe"). Deliberately light, so it never merges unrelated words.
	 *
	 * @param string $word One word.
	 * @return string
	 */
	public static function key( $word ) {
		static $cache = array();

		$word = (string) $word;

		if ( isset( $cache[ $word ] ) ) {
			return $cache[ $word ];
		}

		if ( count( $cache ) > 50000 ) {
			$cache = array();
		}

		$cache[ $word ] = self::fold( $word );

		return $cache[ $word ];
	}

	/**
	 * The work behind key().
	 *
	 * @param string $word One word.
	 * @return string
	 */
	private static function fold( $word ) {
		$word = str_replace( array( "\xE2\x80\x99", "\xE2\x80\x98" ), "'", $word );
		$word = function_exists( 'mb_strtolower' ) ? mb_strtolower( $word, 'UTF-8' ) : strtolower( $word );

		if ( "'s" === substr( $word, -2 ) ) {
			$word = substr( $word, 0, -2 );
		}

		$word = rtrim( $word, "'" );

		if ( ! preg_match( '/^[a-z][a-z\-]{2,}$/', $word ) ) {
			return $word;
		}

		$len = strlen( $word );

		if ( $len > 4 && 'ies' === substr( $word, -3 ) ) {
			return substr( $word, 0, -3 ) . 'y';
		}

		if ( 'sses' === substr( $word, -4 ) || preg_match( '/(?:ches|shes|xes|zzes)$/', $word ) ) {
			return substr( $word, 0, -2 );
		}

		if ( $len > 3 && 's' === substr( $word, -1 ) && ! preg_match( '/(?:ss|us|is|ous|ics)$/', $word ) ) {
			return substr( $word, 0, -1 );
		}

		// "composting" and "compost", "watering" and "water". The rest must
		// still look like a word ("spring" and "thing" stay as they are).
		if ( $len > 5 && 'ing' === substr( $word, -3 ) ) {
			$stem = substr( $word, 0, -3 );

			if ( strlen( $stem ) >= 3 && preg_match( '/[aeiouy]/', $stem ) && ! preg_match( '/^(?:even|morn|ceil|wedd|pudd|earr)$/', $stem ) ) {
				// "running" to "run", but "falling" stays "fall".
				if ( preg_match( '/([bcdfghjkmnpqrtvwx])\1$/', $stem ) ) {
					$stem = substr( $stem, 0, -1 );
				}
				return $stem;
			}
		}

		return $word;
	}

	/**
	 * The words in a piece of plain text, with their byte offsets.
	 *
	 * @param string $text Plain text (UTF-8).
	 * @return array<int,array{0:string,1:int}> Each word and where it starts.
	 */
	public static function words( $text ) {
		if ( ! preg_match_all( '/[\p{L}\p{N}]+(?:[\'\x{2019}\-][\p{L}\p{N}]+)*/u', (string) $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		return $m[0];
	}

	/**
	 * The keys of a phrase's meaningful words (stopwords dropped).
	 *
	 * @param string $text Phrase, may hold HTML entities.
	 * @return string[]
	 */
	public static function phrase_keys( $text ) {
		$keys = array();

		foreach ( self::words( self::plain( $text ) ) as $word ) {
			$key = self::key( $word[0] );
			if ( ! self::is_stop( $key ) ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/**
	 * Plain text from a title or snippet: tags gone, entities decoded,
	 * whitespace collapsed, invalid UTF-8 dropped.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function plain( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return self::collapse( wp_check_invalid_utf8( $text, true ) );
	}

	/**
	 * Collapse runs of whitespace (no-break spaces too) to single spaces.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function collapse( $text ) {
		$out = preg_replace( '/[\s\x{00A0}]+/u', ' ', (string) $text );

		return trim( null === $out ? (string) $text : $out );
	}

	/**
	 * Cut text to a number of bytes on a character boundary.
	 *
	 * @param string $text  Text.
	 * @param int    $bytes Most bytes to keep.
	 * @return string
	 */
	public static function cut( $text, $bytes ) {
		$text = (string) $text;

		if ( strlen( $text ) <= $bytes ) {
			return $text;
		}

		$text = substr( $text, 0, $bytes );

		// Drop a broken multibyte character at the end.
		return (string) preg_replace( '/[\x80-\xBF]*[\xC0-\xFF]?$/', '', $text );
	}

	// HTML -------------------------------------------------------------------

	/**
	 * Split HTML into text, tag and comment tokens with their byte offsets.
	 *
	 * A single forward scan, so its time grows with the length of the HTML
	 * whatever the markup (a regex restarts at every "<" when a tag never
	 * closes, which takes minutes on broken input). It follows the HTML
	 * tokenizer where it matters: quotes only count right after "=", and a
	 * tag or comment that never ends runs to the end, as it does in browsers.
	 *
	 * @param string $html HTML.
	 * @return array<int,array{type:string,raw:string,start:int}>|false
	 */
	public static function tokenize( $html ) {
		$html   = (string) $html;
		$len    = strlen( $html );
		$tokens = array();
		$text   = 0;
		$pos    = 0;

		while ( $pos < $len ) {
			$lt = strpos( $html, '<', $pos );

			if ( false === $lt ) {
				break;
			}

			$next = $lt + 1 < $len ? $html[ $lt + 1 ] : '';
			$type = 'tag';
			$end  = null;

			if ( '!' === $next ) {
				$type = 'comment';

				if ( '<!--' === substr( $html, $lt, 4 ) ) {
					$close = strpos( $html, '-->', $lt + 4 );
					$end   = false === $close ? $len : $close + 3;
				} elseif ( '<![CDATA[' === substr( $html, $lt, 9 ) ) {
					$close = strpos( $html, ']]>', $lt + 9 );
					$end   = false === $close ? $len : $close + 3;
				} else {
					$close = strpos( $html, '>', $lt + 2 );
					$end   = false === $close ? $len : $close + 1;
				}
			} elseif ( '?' === $next ) {
				$type  = 'comment';
				$close = strpos( $html, '>', $lt + 2 );
				$end   = false === $close ? $len : $close + 1;
			} elseif ( self::is_letter( $next ) || ( '/' === $next && $lt + 2 < $len && self::is_letter( $html[ $lt + 2 ] ) ) ) {
				$end = self::tag_end( $html, $lt, $len );
			}

			if ( null === $end ) {
				// A "<" that starts nothing ("a < b") is text.
				$pos = $lt + 1;
				continue;
			}

			if ( $lt > $text ) {
				$tokens[] = array(
					'type'  => 'text',
					'raw'   => substr( $html, $text, $lt - $text ),
					'start' => $text,
				);
			}

			$tokens[] = array(
				'type'  => $type,
				'raw'   => substr( $html, $lt, $end - $lt ),
				'start' => $lt,
			);

			$pos  = $end;
			$text = $end;
		}

		if ( $text < $len ) {
			$tokens[] = array(
				'type'  => 'text',
				'raw'   => substr( $html, $text ),
				'start' => $text,
			);
		}

		return $tokens;
	}

	/**
	 * Where a tag that starts at $start ends (one past its ">").
	 *
	 * @param string $html  HTML.
	 * @param int    $start Offset of the tag's "<".
	 * @param int    $len   Length of the HTML.
	 * @return int
	 */
	private static function tag_end( $html, $start, $len ) {
		$i = $start + 1;

		while ( $i < $len ) {
			// Attribute names and spaces up to the next "=" or ">".
			$i += strcspn( $html, '>=', $i );

			if ( $i >= $len ) {
				break;
			}

			if ( '>' === $html[ $i ] ) {
				return $i + 1;
			}

			// "=": a value follows, after optional spaces.
			++$i;
			$i += strspn( $html, " \t\n\r\f", $i );

			if ( $i >= $len ) {
				break;
			}

			$c = $html[ $i ];

			if ( '"' === $c || "'" === $c ) {
				$close = strpos( $html, $c, $i + 1 );

				if ( false === $close ) {
					return $len;
				}

				$i = $close + 1;
				continue;
			}

			if ( '>' === $c ) {
				return $i + 1;
			}

			// An unquoted value runs to a space or ">"; quotes in it are characters.
			$i += strcspn( $html, " \t\n\r\f>", $i );
		}

		return $len;
	}

	/**
	 * Whether a character is an ASCII letter.
	 *
	 * @param string $c One character.
	 * @return bool
	 */
	private static function is_letter( $c ) {
		return ( $c >= 'a' && $c <= 'z' ) || ( $c >= 'A' && $c <= 'Z' );
	}

	/**
	 * A tag's name and kind.
	 *
	 * @param string $raw Tag markup.
	 * @return array{name:string,close:bool,self:bool}
	 */
	public static function tag( $raw ) {
		preg_match( '/^<(\/?)([a-zA-Z][a-zA-Z0-9:-]*)/', $raw, $m );

		return array(
			'name'  => isset( $m[2] ) ? strtolower( $m[2] ) : '',
			'close' => ! empty( $m[1] ),
			'self'  => (bool) preg_match( '#/\s*>$#', $raw ),
		);
	}

	/**
	 * A block delimiter comment, if that's what this is.
	 *
	 * @param string $raw Comment markup.
	 * @return array{name:string,close:bool,self:bool}|null
	 */
	public static function block( $raw ) {
		if ( ! preg_match( '#^<!--\s+(/)?wp:([a-z][a-z0-9_-]*(?:/[a-z][a-z0-9_-]*)?)(?:\s+\{.*\})?\s+(/)?-->$#s', $raw, $m ) ) {
			return null;
		}

		return array(
			'name'  => false === strpos( $m[2], '/' ) ? 'core/' . $m[2] : $m[2],
			'close' => ! empty( $m[1] ),
			'self'  => ! empty( $m[3] ),
		);
	}

	/**
	 * The value of an attribute in a tag.
	 *
	 * @param string $raw  Tag markup.
	 * @param string $name Attribute name.
	 * @return string|null Raw value (entities not decoded), or null if absent.
	 */
	public static function attr( $raw, $name ) {
		$pattern = '/[\s"\'\/]' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i';

		if ( ! preg_match( $pattern, $raw, $m ) ) {
			return null;
		}

		if ( isset( $m[3] ) && '' !== $m[3] ) {
			return $m[3];
		}

		return isset( $m[2] ) && '' !== $m[2] ? $m[2] : $m[1];
	}

	/**
	 * Walk the HTML's text in order.
	 *
	 * Calls $visit( $raw, $start, $linkable, $chunk, $markup ) for each piece:
	 * $raw is the piece as it is in the HTML, $start its byte offset,
	 * $linkable whether a link may be added inside it, $chunk a counter that
	 * goes up at every paragraph-level boundary, and $markup true for a
	 * shortcode tag (never text, never linkable).
	 *
	 * @param string   $html  HTML.
	 * @param callable $visit Callback.
	 * @param callable $on_tag Optional callback( $raw, $tag, $start ) for each tag.
	 * @return bool False when the HTML couldn't be read.
	 */
	public static function walk( $html, $visit, $on_tag = null ) {
		$tokens = self::tokenize( $html );

		if ( false === $tokens ) {
			return false;
		}

		$has_blocks = false !== strpos( (string) $html, '<!-- wp:' );
		$blocks     = array();
		$skipping   = array();
		$chunk      = 0;

		foreach ( $tokens as $token ) {
			if ( 'comment' === $token['type'] ) {
				$block = self::block( $token['raw'] );

				if ( $block ) {
					++$chunk;
					$skipping = array();

					if ( $block['close'] ) {
						$at = array_search( $block['name'], array_reverse( $blocks, true ), true );
						if ( false !== $at ) {
							$blocks = array_slice( $blocks, 0, $at );
						}
					} elseif ( ! $block['self'] ) {
						$blocks[] = $block['name'];
					}
				}
				continue;
			}

			if ( 'tag' === $token['type'] ) {
				$tag = self::tag( $token['raw'] );

				if ( $on_tag ) {
					call_user_func( $on_tag, $token['raw'], $tag, $token['start'] );
				}

				if ( in_array( $tag['name'], self::BREAK_TAGS, true ) ) {
					++$chunk;
				}

				if ( in_array( $tag['name'], self::SKIP_TAGS, true ) ) {
					if ( $tag['close'] ) {
						$at = array_search( $tag['name'], array_reverse( $skipping, true ), true );
						if ( false !== $at ) {
							$skipping = array_slice( $skipping, 0, $at );
						}
					} elseif ( ! $tag['self'] && ! in_array( $tag['name'], self::VOID_TAGS, true ) ) {
						$skipping[] = $tag['name'];
					}
				}
				continue;
			}

			$innermost = $blocks ? end( $blocks ) : 'core/freeform';
			$linkable  = ! $skipping && ( ! $has_blocks || in_array( $innermost, self::blocks(), true ) );

			// Shortcode tags ([gallery ids="1,2"], [/caption]) are markup, not words.
			$parts = preg_split( '/(\[\/?[a-zA-Z_][\w-]*(?:[\s\/][^\[\]]*)?\])/', $token['raw'], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE );

			foreach ( (array) $parts as $i => $part ) {
				if ( '' === $part[0] ) {
					continue;
				}

				$markup = 1 === $i % 2;
				call_user_func( $visit, $part[0], $token['start'] + $part[1], $linkable && ! $markup, $chunk, $markup );
			}
		}

		return true;
	}

	/**
	 * Blocks whose text may take a link.
	 *
	 * @return string[]
	 */
	public static function blocks() {
		if ( ! isset( self::$lists['blocks'] ) ) {
			/**
			 * Filters the blocks whose text may get links.
			 *
			 * Only add blocks whose text is saved as HTML in the post (a
			 * RichText "html" source). A block that rebuilds its HTML from
			 * attributes would show "unexpected content" after a link is added.
			 *
			 * @param string[] $blocks Block names, e.g. "core/paragraph".
			 */
			self::$lists['blocks'] = array_values( array_map( 'strval', (array) apply_filters( 'internal_link_suggestions_blocks', self::BLOCKS ) ) );
		}

		return self::$lists['blocks'];
	}

	/**
	 * The HTML as paragraph-level chunks of plain text.
	 *
	 * Each chunk is array( 'text' => string, 'runs' => array ), where text is
	 * the decoded text (whitespace not collapsed, so offsets hold) and each
	 * run is array( 'from', 'to', 'linkable' ): one text node's place in it.
	 * A link can only be added inside one linkable run.
	 *
	 * @param string $html HTML.
	 * @return array<int,array> Keyed by chunk number.
	 */
	public static function chunks( $html ) {
		$chunks = array();

		$ok = self::walk(
			$html,
			function ( $raw, $start, $linkable, $chunk, $markup ) use ( &$chunks ) {
				$text = $markup ? ' ' : self::decode( $raw );

				if ( ! isset( $chunks[ $chunk ] ) ) {
					$chunks[ $chunk ] = array(
						'text' => '',
						'runs' => array(),
					);
				}

				$from                       = strlen( $chunks[ $chunk ]['text'] );
				$chunks[ $chunk ]['text']  .= $text;
				$chunks[ $chunk ]['runs'][] = array(
					'from'     => $from,
					'to'       => $from + strlen( $text ),
					'linkable' => $linkable,
				);
			}
		);

		if ( ! $ok ) {
			return array();
		}

		return array_filter(
			$chunks,
			function ( $chunk ) {
				return '' !== trim( $chunk['text'] );
			}
		);
	}

	/**
	 * Decode a piece of HTML text: entities to characters, no-break spaces
	 * to spaces, invalid UTF-8 dropped. Lengths may change.
	 *
	 * @param string $raw Raw text.
	 * @return string
	 */
	public static function decode( $raw ) {
		$text = html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text );

		return wp_check_invalid_utf8( $text, true );
	}

	/**
	 * Decode a piece of HTML text and remember where each decoded byte came
	 * from, so a match in the decoded text can be found in the raw HTML.
	 *
	 * @param string $raw Raw text (no tags).
	 * @return array{0:string,1:int[]} Decoded text, and for each of its byte
	 *         offsets (plus one past the end) the raw offset it starts at.
	 */
	public static function decode_map( $raw ) {
		$raw  = (string) $raw;
		$out  = '';
		$map  = array();
		$pos  = 0;
		$len  = strlen( $raw );
		$copy = function ( $until ) use ( $raw, &$out, &$map, &$pos ) {
			if ( $until > $pos ) {
				$out .= substr( $raw, $pos, $until - $pos );
				$map  = array_merge( $map, range( $pos, $until - 1 ) );
				$pos  = $until;
			}
		};

		if ( preg_match_all( '/&(?:#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[a-zA-Z][a-zA-Z0-9]{1,31});/', $raw, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $entity ) {
				$decoded = html_entity_decode( $entity[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );

				if ( $decoded === $entity[0] || $entity[1] < $pos ) {
					continue;
				}

				$copy( $entity[1] );
				$out .= $decoded;
				$map  = array_merge( $map, array_fill( 0, strlen( $decoded ), $entity[1] ) );
				$pos  = $entity[1] + strlen( $entity[0] );
			}
		}

		$copy( $len );
		$map[] = $len;

		return array( $out, $map );
	}

	/**
	 * A regex that finds an anchor's words in decoded text, as whole words,
	 * with any whitespace between them.
	 *
	 * @param string $anchor Anchor text.
	 * @return string|null Null for an empty anchor.
	 */
	public static function anchor_pattern( $anchor ) {
		$words = preg_split( '/[\s\x{00A0}]+/u', trim( (string) $anchor ), -1, PREG_SPLIT_NO_EMPTY );

		if ( ! $words ) {
			return null;
		}

		$quoted = array_map(
			function ( $word ) {
				return preg_quote( $word, '/' );
			},
			$words
		);

		return '/(?<![\p{L}\p{N}])' . implode( '[\s\x{00A0}]+', $quoted ) . '(?![\p{L}\p{N}])/u';
	}

	/**
	 * The sentence around a span of chunk text, split for display.
	 *
	 * @param string $text  Chunk text.
	 * @param int    $start Span start (bytes).
	 * @param int    $end   Span end (bytes).
	 * @return array{before:string,anchor:string,after:string,context:string}
	 */
	public static function sentence( $text, $start, $end ) {
		$head  = substr( $text, 0, $start );
		$tail  = substr( $text, $end );
		$begin = 0;
		$stop  = strlen( $text );

		if ( preg_match_all( '/[.!?\x{2026}]["\x{201D}\x{2019})\]]*[\s\x{00A0}]+/u', $head, $m, PREG_OFFSET_CAPTURE ) ) {
			$last  = end( $m[0] );
			$begin = $last[1] + strlen( $last[0] );
		}

		if ( preg_match( '/[.!?\x{2026}]["\x{201D}\x{2019})\]]*(?=[\s\x{00A0}]|$)/u', $tail, $m, PREG_OFFSET_CAPTURE ) ) {
			$stop = $end + $m[0][1] + strlen( $m[0][0] );
		}

		$before = self::collapse( substr( $text, $begin, $start - $begin ) );
		$anchor = self::collapse( substr( $text, $start, $end - $start ) );
		$after  = self::collapse( substr( $text, $end, $stop - $end ) );

		$context = self::cut( self::collapse( substr( $text, $begin, $stop - $begin ) ), 400 );

		// Keep the snippet short: about a dozen words either side.
		$before_words = preg_split( '/ /', $before, -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $before_words ) > 12 ) {
			$before = "\xE2\x80\xA6" . implode( ' ', array_slice( $before_words, -12 ) );
		}

		$after_words = preg_split( '/ /', $after, -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $after_words ) > 12 ) {
			$after = implode( ' ', array_slice( $after_words, 0, 12 ) ) . "\xE2\x80\xA6";
		}

		// The anchor's own spacing: a space either side unless punctuation touches it.
		$gap_before = $start > $begin && preg_match( '/[\s\x{00A0}]$/u', substr( $text, $begin, $start - $begin ) ) ? ' ' : '';
		$gap_after  = $stop > $end && preg_match( '/^[\s\x{00A0}]/u', substr( $text, $end, $stop - $end ) ) ? ' ' : '';

		return array(
			'before'  => '' === $before ? '' : $before . $gap_before,
			'anchor'  => $anchor,
			'after'   => '' === $after ? '' : $gap_after . $after,
			'context' => $context,
		);
	}
}
