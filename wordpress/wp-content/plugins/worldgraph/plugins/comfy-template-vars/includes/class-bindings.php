<?php
/**
 * Story Graph bindings for ComfyUI-TemplateVars variables.
 *
 * A Template's `input_bindings` JSON may contain a `template_variables` map
 * that fills named variables from the source Story Graph item:
 *
 *     {
 *       "template_variables": {
 *         "lighting": { "source": "time_of_day" },
 *         "subject":  "post_title"
 *       }
 *     }
 *
 * Sources are `post_title`, `post_excerpt`, `post_content`, or any World
 * Graph Studio Details / SCF field name. Resolved values become the lowest
 * run-value layer; Project, item, and one-off values override them.
 *
 * @package WorldGraphComfyTemplateVars
 */

namespace WorldGraphComfyTemplateVars;

use WorldGraph\Utils\Template_Run_Controls;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\\Bindings' ) ) {

	/**
	 * Resolve Template variable bindings against a Story Graph post.
	 */
	final class Bindings {

		/** Key inside `input_bindings` that holds variable bindings. */
		const BINDINGS_KEY = 'template_variables';

		/** Built-in post sources. */
		const POST_SOURCES = [
			'post_title'   => 'post_title',
			'post_excerpt' => 'post_excerpt',
			'post_content' => 'post_content',
		];

		/**
		 * Variable name => source for one Template.
		 *
		 * @param int $template_id Template post ID.
		 * @return array<string,string>
		 */
		public static function for_template( int $template_id ): array {
			$raw     = \WorldGraph\Utils\worldgraph_get_field_value( $template_id, 'input_bindings' );
			$decoded = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
			$map     = is_array( $decoded ) ? ( $decoded[ self::BINDINGS_KEY ] ?? null ) : null;

			return is_array( $map ) ? self::normalize( $map ) : [];
		}

		/**
		 * Normalize a raw `template_variables` map.
		 *
		 * @param array $map Raw map of name => `{source}` or source string.
		 * @return array<string,string>
		 */
		public static function normalize( array $map ): array {
			$bindings = [];
			foreach ( array_slice( $map, 0, Template_Vars::MAX_VARIABLES, true ) as $name => $binding ) {
				$name   = trim( (string) $name );
				$source = is_array( $binding ) ? ( $binding['source'] ?? '' ) : $binding;
				if ( ! is_string( $source ) || Template_Vars::PROMPT_VARIABLE === $name || null === Template_Vars::control_key( $name ) ) {
					continue;
				}
				$source = trim( $source );
				if ( '' !== $source && 1 === preg_match( '/^[A-Za-z0-9_:-]{1,64}$/D', $source ) ) {
					$bindings[ $name ] = $source;
				}
			}

			return $bindings;
		}

		/**
		 * Run values keyed by control key for a Template and source post.
		 *
		 * Unresolved or empty sources are omitted so the workflow value stays.
		 *
		 * @param int $template_id Template post ID.
		 * @param int $post_id     Source Story Graph post ID.
		 * @return array<string,string>
		 */
		public static function run_values( int $template_id, int $post_id ): array {
			$values = [];
			foreach ( self::for_template( $template_id ) as $name => $source ) {
				$value = self::plain_text( self::resolve_source( $source, $post_id ) );
				if ( '' !== $value ) {
					$values[ (string) Template_Vars::control_key( $name ) ] = $value;
				}
			}

			return $values;
		}

		/** Read one binding source from a post as a string. */
		private static function resolve_source( string $source, int $post_id ): string {
			if ( isset( self::POST_SOURCES[ $source ] ) ) {
				$post = get_post( $post_id );
				if ( ! $post ) {
					return '';
				}
				$field = self::POST_SOURCES[ $source ];
				return (string) $post->$field;
			}

			$value = \WorldGraph\Utils\worldgraph_get_field_value( $post_id, $source );
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_filter( array_map( static fn( $item ) => is_scalar( $item ) ? (string) $item : '', $value ) ) );
			}

			return is_scalar( $value ) ? (string) $value : '';
		}

		/** Strip markup, collapse whitespace, and bound to the run-control limit. */
		private static function plain_text( string $value ): string {
			$value = function_exists( 'strip_shortcodes' ) ? strip_shortcodes( $value ) : $value;
			$value = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value, true ) ) );
			$limit = Template_Run_Controls::MAX_TEXT_LENGTH;

			return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit, 'UTF-8' ) : substr( $value, 0, $limit );
		}
	}
}
