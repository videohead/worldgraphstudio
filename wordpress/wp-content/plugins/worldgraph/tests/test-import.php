<?php
/**
 * Tests for World Graph Studio file-based import flow.
 *
 * @package WorldGraph
 */

use PHPUnit\Framework\TestCase;

/**
 * Class Test_WorldGraph_Import
 */
class Test_WorldGraph_Import extends TestCase {

	/**
	 * The import admin page should use a file input and not require pasted JSON.
	 */
	public function test_import_admin_page_uses_file_upload() {
		$root = dirname( __DIR__ ) . '/plugins/story-import-export/';
		$path = $root . 'includes/class-import-admin.php';
		$this->assertFileExists( $path );

		$source = file_get_contents( $path );
		$this->assertNotFalse( $source );
		$this->assertStringContainsString( 'type="file"', $source );
		$this->assertStringContainsString( 'worldgraph_json_file', $source );
		$this->assertStringNotContainsString( 'textarea name="worldgraph_json"', $source );
		$this->assertStringContainsString( "'worldgraph_create_preview'", $source );

		$script = file_get_contents( $root . 'assets/import.js' );
		$this->assertNotFalse( $script );
		$this->assertStringContainsString( 'HTMLFormElement.prototype.submit.call(form)', $script );
		$this->assertStringNotContainsString( 'form.submit()', $script );
	}

	/**
	 * The example document should only use resolvable external-ID references.
	 */
	public function test_example_document_relationship_references_resolve() {
		$project_root = dirname( dirname( __DIR__ ), 4 );
		$path         = $project_root . '/about/example-workflow/little-red-riding-hood.worldgraph.json';
		$document     = json_decode( file_get_contents( $path ), true );

		$this->assertIsArray( $document );

		$ids = [];
		foreach ( [ 'characters', 'locations', 'props', 'scenes', 'shots', 'sounds' ] as $section ) {
			$ids[ $section ] = array_column( $document[ $section ], 'id' );
		}

		foreach ( $document['props'] as $prop ) {
			$this->assertContains( $prop['owner_character'], $ids['characters'] );
		}

		foreach ( $document['scenes'] as $scene ) {
			$this->assertContains( $scene['location'], $ids['locations'] );
			foreach ( $scene['characters'] as $character_id ) {
				$this->assertContains( $character_id, $ids['characters'] );
			}
			foreach ( $scene['props'] as $prop_id ) {
				$this->assertContains( $prop_id, $ids['props'] );
			}
		}

		foreach ( $document['shots'] as $shot ) {
			$this->assertContains( $shot['scene'], $ids['scenes'] );
		}

		$shot_scenes = array_column( $document['shots'], 'scene', 'id' );
		$this->assertSame(
			[
				'shot_1' => 'scene_1',
				'shot_2' => 'scene_1',
				'shot_3' => 'scene_1',
				'shot_4' => 'scene_2',
				'shot_5' => 'scene_2',
				'shot_6' => 'scene_2',
				'shot_7' => 'scene_3',
				'shot_8' => 'scene_3',
				'shot_9' => 'scene_3',
			],
			$shot_scenes
		);
		foreach ( $document['sounds'] as $sound ) {
			$this->assertContains( $sound['scene'], $ids['scenes'] );
			if ( ! empty( $sound['shot'] ) ) {
				$this->assertContains( $sound['shot'], $ids['shots'] );
				$this->assertSame( $sound['scene'], $shot_scenes[ $sound['shot'] ] );
			}
			if ( ! empty( $sound['character'] ) ) {
				$this->assertContains( $sound['character'], $ids['characters'] );
			}
		}

		foreach ( $document['sequence']['order'] as $scene_id ) {
			$this->assertContains( $scene_id, $ids['scenes'] );
		}
	}

	/**
	 * The importer should consume the structured prop-owner field.
	 */
	public function test_importer_maps_prop_ownership() {
		$path   = dirname( __DIR__ ) . '/plugins/story-import-export/includes/class-worldgraph-importer.php';
		$source = file_get_contents( $path );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( "\$prop['owner_character']", $source );
		$this->assertStringContainsString( "worldgraph_update_field_value( \$prop_id, 'owner_character', \$char_id )", $source );
		$this->assertStringContainsString(
			"relationship_field_targets_match( \$prop_id, 'worldgraph_prop', 'owner_character', array_filter( [ \$character_id ] ), 'worldgraph_character' )",
			$source
		);
		$this->assertStringContainsString( 'validate_references', $source );
	}

	/**
	 * Imported shots should retain their required scalar Scene link.
	 */
	public function test_importer_persists_shot_scene_relationships() {
		$path   = dirname( __DIR__ ) . '/plugins/story-import-export/includes/class-worldgraph-importer.php';
		$source = file_get_contents( $path );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( "worldgraph_update_field_value( \$shot_id, 'scene', \$scene_id )", $source );
		$this->assertStringContainsString( "relationship_slot_matches( \$shot_id, 'worldgraph_shot', 'scene'", $source );
		$this->assertStringContainsString(
			"remove_relationship( \$scene_id, \$shot_id, 'worldgraph_scene', 'worldgraph_shot', 'contains' )",
			$source
		);
		$this->assertStringContainsString( 'Shot %s did not retain its required Scene relationship.', $source );

		$scenes_controller = file_get_contents( dirname( __DIR__ ) . '/includes/rest-api/scenes-controller.php' );
		$this->assertNotFalse( $scenes_controller );
		$this->assertStringContainsString( "get_relationships( \$post_id, \$from_cpt, 'incoming' )", $scenes_controller );
	}

	/** Inserted Sequence term IDs must be normalized before taxonomy assignment. */
	public function test_importer_normalizes_sequence_term_ids_to_integers() {
		$source = file_get_contents( dirname( __DIR__ ) . '/plugins/story-import-export/includes/class-worldgraph-importer.php' );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString(
			"\$term_id = is_array( \$term ) ? (int) \$term['term_id'] : (int) \$term;",
			$source
		);
		$this->assertStringContainsString(
			"worldgraph_update_field_value( (int) \$scene_post_id, 'sequence', \$term_id )",
			$source
		);
		$this->assertStringContainsString(
			"worldgraph_update_field_value( (int) \$shot_post_id, 'sequence', \$term_id )",
			$source
		);
	}

	/** Validation rejects values that cannot round-trip through canonical fields. */
	public function test_importer_rejects_lossy_v12_scalar_values() {
		$source = file_get_contents( dirname( __DIR__ ) . '/plugins/story-import-export/includes/class-worldgraph-importer.php' );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( "! is_string( \$data[ \$section ]['id'] )", $source );
		$this->assertStringContainsString( "! is_string( \$entity['id'] )", $source );
		$this->assertStringContainsString( "! is_string( \$data['sequence']['id'] )", $source );
		$this->assertStringContainsString( '$raw_data = json_decode( $json );', $source );
		$this->assertStringContainsString( 'validate_field_values( $data, $raw_data )', $source );
		$this->assertStringContainsString(
			"property_exists( \$raw_asset, 'generation_parameters' )",
			$source
		);
		$this->assertStringContainsString(
			'! is_object( $raw_asset->generation_parameters )',
			$source
		);
		$this->assertStringContainsString(
			"\$asset['generation_parameters'] = \$raw_asset->generation_parameters;",
			$source
		);
		$this->assertStringNotContainsString(
			"array_key_exists( 'generation_parameters', \$asset ) && ! is_array( \$asset['generation_parameters'] )",
			$source
		);
		$this->assertStringContainsString(
			"sanitize_title( (string) \$asset['type'] ) !== sanitize_title( (string) \$asset['asset_type'] )",
			$source
		);
		$this->assertStringContainsString( "! is_numeric( \$shot['take_number'] )", $source );
		$this->assertStringContainsString( 'take_number must be at least 1.', $source );
	}

	/** Project, Scene, and Shot visual direction must round-trip through the portable mapping. */
	public function test_importer_and_exporter_map_portable_visual_direction_fields() {
		$root     = dirname( __DIR__ ) . '/plugins/story-import-export/includes/';
		$importer = file_get_contents( $root . 'class-worldgraph-importer.php' );
		$exporter = file_get_contents( $root . 'class-worldgraph-json-exporter.php' );

		$this->assertNotFalse( $importer );
		$this->assertNotFalse( $exporter );
		$this->assertStringContainsString( "'generation_prompt' => 'generation_prompt'", $importer );
		$this->assertStringContainsString( "'camera_movement'   => 'camera_movement'", $importer );
		$this->assertStringContainsString( "'motion_direction'  => 'motion_direction'", $importer );
		$this->assertSame( 3, substr_count( $exporter, "'generation_prompt' => \$this->scalar_field(" ) );
		$this->assertStringContainsString( "scalar_field( \$scene->ID, 'lens' )", $exporter );
		$this->assertStringContainsString( "scalar_field( \$scene->ID, 'camera_movement' )", $exporter );
		$this->assertStringContainsString( "'camera_movement'   => \$this->scalar_field( \$shot->ID, 'camera_movement' )", $exporter );
		$this->assertStringContainsString( "'motion_direction'  => \$this->scalar_field( \$shot->ID, 'motion_direction' )", $exporter );
	}

	/** Portable taxonomy fields accept only nonempty canonical slug strings. */
	public function test_importer_enforces_canonical_taxonomy_slug_values() {
		$source = file_get_contents( dirname( __DIR__ ) . '/plugins/story-import-export/includes/class-worldgraph-importer.php' );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString(
			'private function validate_taxonomy_slug( $value, string $context, array &$errors ): void',
			$source
		);
		$this->assertStringContainsString(
			"! is_string( \$value ) || '' === \$value || is_numeric( \$value ) || sanitize_title( \$value ) !== \$value",
			$source
		);
		$this->assertStringContainsString( 'values must be non-empty lowercase taxonomy slugs.', $source );
		$this->assertStringContainsString(
			"\$term = get_term_by( 'slug', (string) \$raw_term, \$taxonomy );",
			$source
		);
		$this->assertStringNotContainsString( 'is_numeric( $raw_term )', $source );
		$this->assertStringNotContainsString( 'get_term( absint( $raw_term ), $taxonomy )', $source );

		foreach (
			[
				"[ \$data['project']['genres'] ?? [], 'Project genres' ]",
				"\$character['roles'] ?? []",
				"\$character['relations'] ?? []",
				"\$scene['tags'] ?? []",
				"\$data['project']['production_status']",
				"\$episode['production_status']",
				"\$sound['type'] ?? ''",
				"\$sound['production_status']",
				"[ 'type', 'asset_type' ]",
			] as $taxonomy_validation
		) {
			$this->assertStringContainsString( $taxonomy_validation, $source );
		}
	}

	/** Editorial records must explicitly name the document Project. */
	public function test_importer_requires_editorial_project_reference() {
		$source = file_get_contents( dirname( __DIR__ ) . '/plugins/story-import-export/includes/class-worldgraph-importer.php' );

		$this->assertNotFalse( $source );
		$references_start = strpos( $source, 'private function validate_references( array $data )' );
		$references_end   = strpos( $source, 'private function validate_reference(', $references_start );
		$this->assertNotFalse( $references_start );
		$this->assertNotFalse( $references_end );
		$references = substr( $source, $references_start, $references_end - $references_start );

		$editorial_start = strpos( $references, "foreach ( \$data['editorial_artifacts'] as \$artifact )" );
		$editorial_end   = strpos( $references, "foreach ( \$data['sounds'] as \$sound )", $editorial_start );
		$this->assertNotFalse( $editorial_start );
		$this->assertNotFalse( $editorial_end );
		$editorial_validation = substr( $references, $editorial_start, $editorial_end - $editorial_start );

		$this->assertStringContainsString( "if ( empty( \$artifact['project'] ) )", $editorial_validation );
		$this->assertStringContainsString( "\$errors[] = \$context . ' must reference its Project.';", $editorial_validation );
		$this->assertStringContainsString(
			"(string) \$artifact['project'] !== (string) \$data['project']['id']",
			$editorial_validation
		);
	}

	/** Episode Scene lists are ordered sets and may not repeat an external ID. */
	public function test_importer_rejects_duplicate_episode_scene_ids() {
		$source = file_get_contents( dirname( __DIR__ ) . '/plugins/story-import-export/includes/class-worldgraph-importer.php' );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( '$seen_scene_ids = [];', $source );
		$this->assertStringContainsString( 'isset( $seen_scene_ids[ $scene_id ] )', $source );
		$this->assertStringContainsString( '%s lists Scene "%s" more than once.', $source );
		$this->assertStringContainsString( '$seen_scene_ids[ $scene_id ] = true;', $source );
	}

	/** Verification must resolve Asset references that were not imported in this run. */
	public function test_import_verification_resolves_existing_library_assets() {
		$source = file_get_contents( dirname( __DIR__ ) . '/plugins/story-import-export/includes/class-worldgraph-importer.php' );

		$this->assertNotFalse( $source );
		$verification_start = strpos( $source, 'private function verify_import(): void' );
		$verification_end   = strpos( $source, 'private function relationship_slot_matches(', $verification_start );
		$this->assertNotFalse( $verification_start );
		$this->assertNotFalse( $verification_end );
		$verification = substr( $source, $verification_start, $verification_end - $verification_start );

		$this->assertStringContainsString(
			"? \$this->resolve_external_id( 'worldgraph_asset', (string) \$sound[ \$field ] )",
			$verification
		);
	}

	/**
	 * The 1.1 example and importer preserve Sound cues without duplicating dialogue.
	 */
	public function test_sound_import_contract() {
		$project_root = dirname( dirname( __DIR__ ), 4 );
		$document     = json_decode( file_get_contents( $project_root . '/about/example-workflow/little-red-riding-hood.worldgraph.json' ), true );
		$types        = array_column( $document['sounds'], 'type' );

		$this->assertSame( '1.1', $document['worldgraph_version'] );
		$this->assertCount( 7, $document['sounds'] );
		$this->assertNotContains( 'dialogue', $types );
		$this->assertContains( 'narration', $types );
		$this->assertContains( 'voiceover', $types );
		$this->assertContains( 'music', $types );

		$music = current( array_filter( $document['sounds'], static fn( array $sound ): bool => 'music' === $sound['type'] ) );
		$this->assertIsArray( $music );
		$this->assertNotEmpty( $music['lyrics'] );
		$this->assertStringContainsString( "\n", $music['lyrics'] );

		$dialogue = [];
		foreach ( $document['scenes'] as $scene ) {
			$dialogue = array_merge( $dialogue, (array) ( $scene['dialogue'] ?? [] ) );
		}
		$this->assertCount( 13, $dialogue );
		$this->assertEmpty(
			array_intersect(
				array_filter( array_column( $document['sounds'], 'spoken_text' ) ),
				array_column( $dialogue, 'text' )
			)
		);

		$importer = file_get_contents( dirname( __DIR__ ) . '/plugins/story-import-export/includes/class-worldgraph-importer.php' );
		$this->assertStringContainsString( 'private function import_sounds()', $importer );
		$this->assertStringContainsString( "'worldgraph_sound_type'", $importer );
		$this->assertStringContainsString( "worldgraph_update_field_value( \$sound_id, 'scene', \$scene_id )", $importer );
		$this->assertStringContainsString( "relationship_slot_matches( \$sound_id, 'worldgraph_sound', 'scene'", $importer );
		$this->assertStringContainsString( 'Ordinary dialogue remains', $importer );
		$this->assertStringContainsString( "! empty( \$options['dry_run'] )", $importer );
		$this->assertStringContainsString( 'worldgraph_is_reserved_sound_type', $importer );
		$this->assertStringContainsString( 'private function validate_asset_reference(', $importer );
		$this->assertStringContainsString(
			"validate_asset_reference( \$sound['asset'], \$context . ' asset', \$id_sets, \$errors, \$document_assets, [ 'audio' ] )",
			$importer
		);
		$this->assertStringContainsString( "has_term( \$allowed_types, 'worldgraph_asset_type', \$existing_asset_id )", $importer );
		$this->assertStringContainsString( "\$existing_asset_id && ( ! \$in_document || ! \$this->overwrite )", $importer );
	}

	/**
	 * The comprehensive example should exercise the complete portable 1.2 field surface.
	 */
	public function test_full_featured_document_covers_v12_sections_and_fields() {
		$document = $this->full_featured_document();

		$this->assertSame( '1.2', $document['worldgraph_version'] );

		$expected_counts = [
			'characters'           => 4,
			'locations'            => 3,
			'props'                => 4,
			'organizations'        => 1,
			'episodes'             => 1,
			'scenes'               => 4,
			'shots'                => 12,
			'sounds'               => 9,
			'assets'               => 6,
			'editorial_artifacts'  => 1,
		];
		foreach ( $expected_counts as $section => $expected_count ) {
			$this->assertArrayHasKey( $section, $document );
			$this->assertIsArray( $document[ $section ] );
			$this->assertCount( $expected_count, $document[ $section ], "Unexpected {$section} fixture count." );
		}

		$expected_fields = [
			'project' => [
				'id', 'title', 'project_slug', 'description', 'generation_prompt', 'genres', 'target_medium',
				'production_status', 'start_date', 'end_date', 'associates',
				'production_stage', 'frame_width', 'frame_height', 'aspect_ratio', 'frame_rate',
			],
			'world' => [
				'id', 'name', 'description', 'timeline', 'rules', 'themes', 'geography',
				'references', 'project',
			],
			'characters' => [
				'id', 'name', 'description', 'age', 'appearance', 'personality', 'motivation',
				'backstory', 'voice_profile', 'roles', 'relations', 'avatar_asset', 'story_world',
			],
			'locations' => [
				'id', 'name', 'description', 'environment_type', 'geography', 'mood',
				'visual_reference', 'story_world',
			],
			'props' => [
				'id', 'name', 'description', 'purpose', 'owner_character', 'notes',
			],
			'organizations' => [
				'id', 'name', 'organization_type', 'description', 'leadership', 'goals',
				'story_world', 'members',
			],
			'episodes' => [
				'id', 'episode_number', 'title', 'synopsis', 'production_status', 'project', 'scenes',
			],
			'scenes' => [
				'id', 'scene_number', 'title', 'location', 'characters', 'props', 'summary',
				'script_content', 'dialogue', 'time_of_day', 'emotional_tone',
				'lens', 'camera_movement', 'generation_prompt', 'production_notes', 'tags',
				'sequence', 'episode',
			],
			'shots' => [
				'id', 'shot_number', 'title', 'scene', 'sequence', 'type', 'camera_angle',
				'lens', 'camera_movement', 'motion_direction', 'duration', 'take_number',
				'slate_id', 'description', 'generation_prompt', 'editorial_notes',
			],
			'sounds' => [
				'id', 'title', 'type', 'production_status', 'description', 'spoken_text',
				'lyrics', 'start_timecode', 'duration', 'diegetic', 'production_notes',
				'scene', 'shot', 'character', 'asset',
			],
			'assets' => [
				'id', 'title', 'asset_type', 'workflow_name', 'prompt', 'model_name', 'seed',
				'generation_parameters', 'version', 'status', 'storage_uri', 'project',
				'character', 'location', 'scene',
			],
			'editorial_artifacts' => [
				'id', 'title', 'artifact_type', 'export_format', 'generated_date', 'source_scene',
				'source_shot', 'notes', 'project',
			],
			'sequence' => [
				'id', 'title', 'sequence_order', 'order',
			],
		];

		foreach ( $expected_fields as $section => $fields ) {
			$records = in_array( $section, [ 'project', 'world', 'sequence' ], true )
				? [ $document[ $section ] ]
				: $document[ $section ];
			$available_fields = [];
			foreach ( $records as $record ) {
				$this->assertIsArray( $record );
				$available_fields = array_merge( $available_fields, array_keys( $record ) );
			}

			$this->assertSame(
				[],
				array_values( array_diff( $fields, array_unique( $available_fields ) ) ),
				"The {$section} fixture does not cover every documented field."
			);
		}

		$dialogue_fields = [];
		foreach ( $document['scenes'] as $scene ) {
			foreach ( $scene['dialogue'] as $line ) {
				$dialogue_fields = array_merge( $dialogue_fields, array_keys( $line ) );
			}
		}
		$this->assertSame(
			[],
			array_values( array_diff( [ 'speaker', 'line', 'description', 'sequence' ], array_unique( $dialogue_fields ) ) )
		);
	}

	/**
	 * External IDs must be unique across every entity kind in one document.
	 */
	public function test_full_featured_document_external_ids_are_globally_unique() {
		$document = $this->full_featured_document();
		$seen     = [];

		foreach ( [ 'project', 'world', 'sequence' ] as $section ) {
			$external_id = $document[ $section ]['id'];
			$this->assertIsString( $external_id );
			$this->assertNotSame( '', trim( $external_id ) );
			$this->assertArrayNotHasKey( $external_id, $seen, "Duplicate external ID {$external_id}." );
			$seen[ $external_id ] = $section;
		}

		foreach ( $this->full_featured_entity_sections() as $section ) {
			foreach ( $document[ $section ] as $entity ) {
				$this->assertArrayHasKey( 'id', $entity );
				$external_id = $entity['id'];
				$this->assertIsString( $external_id );
				$this->assertNotSame( '', trim( $external_id ) );
				$this->assertArrayNotHasKey(
					$external_id,
					$seen,
					"External ID {$external_id} is reused in {$section}."
				);
				$seen[ $external_id ] = $section;
			}
		}
	}

	/**
	 * The compatibility and comprehensive fixtures must be safe to import together.
	 */
	public function test_example_documents_do_not_share_external_ids() {
		$project_root = dirname( dirname( __DIR__ ), 4 );
		$legacy_path  = $project_root . '/about/example-workflow/little-red-riding-hood.worldgraph.json';

		$this->assertFileExists( $legacy_path );
		$legacy_json = file_get_contents( $legacy_path );
		$this->assertNotFalse( $legacy_json );
		$legacy = json_decode( $legacy_json, true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error(), json_last_error_msg() );
		$this->assertIsArray( $legacy );

		$shared_ids = array_values(
			array_intersect(
				$this->portable_external_ids( $legacy ),
				$this->portable_external_ids( $this->full_featured_document() )
			)
		);
		$this->assertSame( [], $shared_ids, 'The two example imports must not resolve to the same persisted entities.' );
	}

	/**
	 * Every portable relationship must resolve to an ID in its declared section.
	 */
	public function test_full_featured_document_relationship_references_resolve_by_type() {
		$document = $this->full_featured_document();
		$ids      = $this->full_featured_id_sets( $document );

		$this->assert_reference( $document['world']['project'], $ids['project'], 'World project' );
		foreach ( $document['project']['associates'] as $character_id ) {
			$this->assert_reference( $character_id, $ids['characters'], 'Project team member' );
		}

		foreach ( $document['characters'] as $character ) {
			$this->assert_reference( $character['story_world'], $ids['world'], "Character {$character['id']} story_world" );
			if ( ! empty( $character['avatar_asset'] ) ) {
				$this->assert_reference( $character['avatar_asset'], $ids['assets'], "Character {$character['id']} avatar_asset" );
			}
		}

		foreach ( $document['locations'] as $location ) {
			$this->assert_reference( $location['story_world'], $ids['world'], "Location {$location['id']} story_world" );
			if ( ! empty( $location['visual_reference'] ) ) {
				$this->assert_reference( $location['visual_reference'], $ids['assets'], "Location {$location['id']} visual_reference" );
			}
		}

		foreach ( $document['props'] as $prop ) {
			$this->assert_reference( $prop['owner_character'], $ids['characters'], "Prop {$prop['id']} owner_character" );
		}

		foreach ( $document['organizations'] as $organization ) {
			$this->assert_reference( $organization['leadership'], $ids['characters'], "Organization {$organization['id']} leadership" );
			$this->assert_reference( $organization['story_world'], $ids['world'], "Organization {$organization['id']} story_world" );
			foreach ( $organization['members'] as $character_id ) {
				$this->assert_reference( $character_id, $ids['characters'], "Organization {$organization['id']} member" );
			}
		}

		foreach ( $document['episodes'] as $episode ) {
			$this->assert_reference( $episode['project'], $ids['project'], "Episode {$episode['id']} project" );
			foreach ( $episode['scenes'] as $scene_id ) {
				$this->assert_reference( $scene_id, $ids['scenes'], "Episode {$episode['id']} scene" );
			}
		}

		foreach ( $document['scenes'] as $scene ) {
			$this->assert_reference( $scene['location'], $ids['locations'], "Scene {$scene['id']} location" );
			$this->assert_reference( $scene['sequence'], $ids['sequence'], "Scene {$scene['id']} sequence" );
			$this->assert_reference( $scene['episode'], $ids['episodes'], "Scene {$scene['id']} episode" );
			foreach ( $scene['characters'] as $character_id ) {
				$this->assert_reference( $character_id, $ids['characters'], "Scene {$scene['id']} character" );
			}
			foreach ( $scene['props'] as $prop_id ) {
				$this->assert_reference( $prop_id, $ids['props'], "Scene {$scene['id']} prop" );
			}
		}

		foreach ( $document['shots'] as $shot ) {
			$this->assert_reference( $shot['scene'], $ids['scenes'], "Shot {$shot['id']} scene" );
			$this->assert_reference( $shot['sequence'], $ids['sequence'], "Shot {$shot['id']} sequence" );
		}

		foreach ( $document['sounds'] as $sound ) {
			$this->assert_reference( $sound['scene'], $ids['scenes'], "Sound {$sound['id']} scene" );
			foreach ( [ 'shot' => 'shots', 'character' => 'characters', 'asset' => 'assets' ] as $field => $target_section ) {
				if ( ! empty( $sound[ $field ] ) ) {
					$this->assert_reference( $sound[ $field ], $ids[ $target_section ], "Sound {$sound['id']} {$field}" );
				}
			}
		}

		foreach ( $document['assets'] as $asset ) {
			$this->assert_reference( $asset['project'], $ids['project'], "Asset {$asset['id']} project" );
			foreach ( [ 'character' => 'characters', 'location' => 'locations', 'scene' => 'scenes' ] as $field => $target_section ) {
				if ( ! empty( $asset[ $field ] ) ) {
					$this->assert_reference( $asset[ $field ], $ids[ $target_section ], "Asset {$asset['id']} {$field}" );
				}
			}
		}

		foreach ( $document['editorial_artifacts'] as $artifact ) {
			$this->assert_reference( $artifact['project'], $ids['project'], "Editorial Artifact {$artifact['id']} project" );
			$this->assert_reference( $artifact['source_scene'], $ids['scenes'], "Editorial Artifact {$artifact['id']} source_scene" );
			$this->assert_reference( $artifact['source_shot'], $ids['shots'], "Editorial Artifact {$artifact['id']} source_shot" );
		}

		foreach ( $document['sequence']['order'] as $scene_id ) {
			$this->assert_reference( $scene_id, $ids['scenes'], 'Sequence scene' );
		}
	}

	/**
	 * Sequence order must be complete, unique, and agree with explicit Scene numbers.
	 */
	public function test_full_featured_sequence_order_matches_scene_numbers() {
		$document = $this->full_featured_document();
		$scenes   = $document['scenes'];

		usort(
			$scenes,
			static fn( array $left, array $right ): int => $left['scene_number'] <=> $right['scene_number']
		);
		$numbered_order = array_column( $scenes, 'id' );

		$this->assertSame( 1, $document['sequence']['sequence_order'] );
		$this->assertSame( [ 1, 2, 3, 4 ], array_column( $scenes, 'scene_number' ) );
		$this->assertSame( $numbered_order, $document['sequence']['order'] );
		$this->assertCount( count( $numbered_order ), array_unique( $document['sequence']['order'] ) );

		foreach ( $document['scenes'] as $scene ) {
			$this->assertSame( $document['sequence']['id'], $scene['sequence'] );
		}
		foreach ( $document['shots'] as $shot ) {
			$this->assertSame( $document['sequence']['id'], $shot['sequence'] );
		}
		foreach ( $document['episodes'] as $episode ) {
			$this->assertSame( $document['sequence']['order'], $episode['scenes'] );
		}
	}

	/**
	 * A Sound may only reference an Asset classified as Audio.
	 */
	public function test_full_featured_sound_assets_are_audio() {
		$document      = $this->full_featured_document();
		$asset_types   = array_column( $document['assets'], 'asset_type', 'id' );
		$linked_assets = 0;

		foreach ( $document['sounds'] as $sound ) {
			if ( empty( $sound['asset'] ) ) {
				continue;
			}

			$linked_assets++;
			$this->assertArrayHasKey( $sound['asset'], $asset_types );
			$this->assertSame( 'audio', $asset_types[ $sound['asset'] ], "Sound {$sound['id']} references a non-Audio Asset." );
		}

		$this->assertGreaterThan( 0, $linked_assets, 'The fixture must exercise the Sound-to-Audio-Asset contract.' );
	}

	/**
	 * Dialogue, props, sounds, and editorial pointers should agree with Scene membership.
	 */
	public function test_full_featured_document_preserves_narrative_consistency() {
		$document           = $this->full_featured_document();
		$characters_by_name = array_column( $document['characters'], 'id', 'name' );
		$props_by_id        = [];
		$scenes_by_id       = [];
		$shots_by_id        = [];

		foreach ( $document['props'] as $prop ) {
			$props_by_id[ $prop['id'] ] = $prop;
		}
		foreach ( $document['scenes'] as $scene ) {
			$scenes_by_id[ $scene['id'] ] = $scene;
		}
		foreach ( $document['shots'] as $shot ) {
			$shots_by_id[ $shot['id'] ] = $shot;
		}

		$dialogue_lines = [];
		foreach ( $document['scenes'] as $scene ) {
			$this->assertCount( count( $scene['characters'] ), array_unique( $scene['characters'] ) );
			$this->assertCount( count( $scene['props'] ), array_unique( $scene['props'] ) );
			$this->assertSame( range( 1, count( $scene['dialogue'] ) ), array_column( $scene['dialogue'], 'sequence' ) );

			foreach ( $scene['dialogue'] as $line ) {
				$this->assertArrayHasKey( $line['speaker'], $characters_by_name, "Unknown dialogue speaker {$line['speaker']}." );
				$this->assertContains( $characters_by_name[ $line['speaker'] ], $scene['characters'] );
				$this->assertNotSame( '', trim( $line['line'] ) );
				$this->assertStringContainsString( $line['line'], $scene['script_content'] );
				$dialogue_lines[] = $line['line'];
			}

			foreach ( $scene['props'] as $prop_id ) {
				$this->assertArrayHasKey( $prop_id, $props_by_id );
				$this->assertContains(
					$props_by_id[ $prop_id ]['owner_character'],
					$scene['characters'],
					"The owner of {$prop_id} is absent from Scene {$scene['id']}."
				);
			}
		}

		$spoken_sound_text = [];
		foreach ( $document['sounds'] as $sound ) {
			$scene = $scenes_by_id[ $sound['scene'] ];
			if ( ! empty( $sound['shot'] ) ) {
				$this->assertSame( $sound['scene'], $shots_by_id[ $sound['shot'] ]['scene'] );
			}
			if ( ! empty( $sound['character'] ) ) {
				$this->assertContains( $sound['character'], $scene['characters'] );
			}
			if ( ! empty( $sound['lyrics'] ) ) {
				$this->assertSame( 'music', $sound['type'] );
			}
			if ( ! empty( $sound['spoken_text'] ) ) {
				$spoken_sound_text[] = $sound['spoken_text'];
			}
			$this->assertNotSame( 'dialogue', $sound['type'] );
		}
		$this->assertSame( [], array_values( array_intersect( $dialogue_lines, $spoken_sound_text ) ) );

		foreach ( $document['organizations'] as $organization ) {
			$this->assertContains( $organization['leadership'], $organization['members'] );
		}
		foreach ( $document['editorial_artifacts'] as $artifact ) {
			$this->assertSame( $artifact['source_scene'], $shots_by_id[ $artifact['source_shot'] ]['scene'] );
		}
	}

	/**
	 * Load the comprehensive import fixture.
	 *
	 * @return array
	 */
	private function full_featured_document(): array {
		$project_root = dirname( dirname( __DIR__ ), 4 );
		$path         = $project_root . '/about/example-workflow/little-red-riding-hood-full-featured.worldgraph.json';

		$this->assertFileExists( $path );
		$json = file_get_contents( $path );
		$this->assertNotFalse( $json );
		$document = json_decode( $json, true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error(), json_last_error_msg() );
		$this->assertIsArray( $document );

		return $document;
	}

	/**
	 * Array-valued entity sections in the comprehensive fixture.
	 *
	 * @return array<int, string>
	 */
	private function full_featured_entity_sections(): array {
		return [
			'characters',
			'locations',
			'props',
			'organizations',
			'episodes',
			'scenes',
			'shots',
			'sounds',
			'assets',
			'editorial_artifacts',
		];
	}

	/**
	 * Collect the external IDs that identify persisted entities in a document.
	 *
	 * @param array $document Parsed fixture.
	 * @return array<int, string>
	 */
	private function portable_external_ids( array $document ): array {
		$external_ids = [];
		foreach ( [ 'project', 'world', 'sequence' ] as $section ) {
			if ( isset( $document[ $section ]['id'] ) ) {
				$external_ids[] = $document[ $section ]['id'];
			}
		}

		foreach ( $this->full_featured_entity_sections() as $section ) {
			if ( isset( $document[ $section ] ) && is_array( $document[ $section ] ) ) {
				$external_ids = array_merge( $external_ids, array_column( $document[ $section ], 'id' ) );
			}
		}

		return $external_ids;
	}

	/**
	 * Build typed external-ID sets for fixture reference checks.
	 *
	 * @param array $document Parsed fixture.
	 * @return array<string, array<int, string>>
	 */
	private function full_featured_id_sets( array $document ): array {
		$ids = [
			'project'  => [ $document['project']['id'] ],
			'world'    => [ $document['world']['id'] ],
			'sequence' => [ $document['sequence']['id'] ],
		];

		foreach ( $this->full_featured_entity_sections() as $section ) {
			$ids[ $section ] = array_column( $document[ $section ], 'id' );
		}

		return $ids;
	}

	/**
	 * Assert one external-ID reference against its declared entity section.
	 *
	 * @param mixed  $reference Reference value.
	 * @param array  $target_ids Valid external IDs.
	 * @param string $context Human-readable assertion context.
	 */
	private function assert_reference( $reference, array $target_ids, string $context ): void {
		$this->assertIsString( $reference, "{$context} must be a string external ID." );
		$this->assertContains( $reference, $target_ids, "{$context} does not resolve to the declared entity type." );
	}
}
