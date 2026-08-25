<?php
/**
 * Generation modality registry.
 *
 * Describes provider-neutral generation shapes. ComfyUI supplies local graph
 * workflows while API-native adapters such as ElevenLabs execute their own
 * endpoint-specific Templates.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generation modality registry.
 */
class Generation_Modality {

	const TEXT_TO_IMAGE       = 'text_to_image';
	const IMAGE_TO_IMAGE      = 'image_to_image';
	const IMAGE_TEXT_TO_IMAGE = 'image_text_to_image';
	const TEXT_TO_VIDEO       = 'text_to_video';
	const TEXT_IMAGE_TO_VIDEO = 'text_image_to_video';
	const VIDEO_TO_VIDEO      = 'video_to_video';
	const VIDEO_WITH_AUDIO    = 'video_with_audio';
	const TEXT_TO_SPEECH      = 'text_to_speech';
	const TEXT_TO_DIALOGUE    = 'text_to_dialogue';
	const TEXT_TO_SOUND_EFFECT = 'text_to_sound_effect';
	const TEXT_TO_MUSIC       = 'text_to_music';
	const TEXT_TO_VOICE       = 'text_to_voice';
	const TEXT_TO_LYRICS      = 'text_to_lyrics';

	/**
	 * Modality assumed for Templates saved before modalities existed.
	 */
	const FALLBACK = self::TEXT_TO_IMAGE;

	/**
	 * Input slots whose value is a media file that must be uploaded to the
	 * generation provider before the workflow runs.
	 *
	 * @var array<int, string>
	 */
	const MEDIA_SLOTS = [ 'image', 'start_frame', 'end_frame', 'video', 'audio' ];

	/**
	 * The full modality registry.
	 *
	 * @return array<string, array>
	 */
	public static function all(): array {
		$image_models = [
			'checkpoint' => [
				'label'      => 'Checkpoint',
				'node_class' => 'CheckpointLoaderSimple',
				'field'      => 'ckpt_name',
				'folder'     => 'checkpoints',
				'required'   => true,
			],
			'lora'       => [
				'label'      => 'LoRA',
				'node_class' => 'LoraLoader',
				'field'      => 'lora_name',
				'folder'     => 'loras',
				'required'   => false,
			],
		];

		$image_nodes = [ 'CheckpointLoaderSimple', 'CLIPTextEncode', 'KSampler', 'VAEDecode', 'SaveImage' ];

		$prompt_input   = [ 'type' => 'text', 'required' => true, 'label' => 'Prompt' ];
		$optional_prompt_input = [ 'type' => 'text', 'required' => false, 'label' => 'Prompt' ];
		$negative_input = [ 'type' => 'text', 'required' => false, 'label' => 'Negative prompt' ];
		$image_input    = [ 'type' => 'media', 'required' => true, 'label' => 'Reference image' ];
		$audio_input    = [ 'type' => 'media', 'required' => true, 'label' => 'Source audio' ];

		$modalities = [
			self::TEXT_TO_IMAGE       => [
				'label'       => 'Text to image',
				'description' => 'A single end-to-end still image generated from a text prompt.',
				'output_type' => 'image',
				'task_type'   => 'txt2img',
				'inputs'      => [
					'prompt'          => $prompt_input,
					'negative_prompt' => $negative_input,
				],
				'nodes'       => array_merge( $image_nodes, [ 'EmptyLatentImage' ] ),
				'models'      => $image_models,
			],
			self::IMAGE_TO_IMAGE      => [
				'label'       => 'Image to image',
				'description' => 'A still image transformed from a reference image.',
				'output_type' => 'image',
				'task_type'   => 'img2img',
				'inputs'      => [ 'image' => $image_input, 'prompt' => $optional_prompt_input, 'negative_prompt' => $negative_input ],
				'nodes'       => array_merge( $image_nodes, [ 'LoadImage', 'VAEEncode' ] ),
				'models'      => $image_models,
			],
			self::IMAGE_TEXT_TO_IMAGE => [
				'label'       => 'Image and text to image',
				'description' => 'A still image guided by both a reference image and a text prompt.',
				'output_type' => 'image',
				'task_type'   => 'image-text-to-image',
				'inputs'      => [ 'image' => $image_input, 'prompt' => $prompt_input, 'negative_prompt' => $negative_input ],
				'nodes'       => array_merge( $image_nodes, [ 'LoadImage', 'VAEEncode' ] ),
				'models'      => $image_models,
			],
			self::TEXT_TO_VIDEO       => [
				'label'       => 'Text to video',
				'description' => 'A video generated from a text prompt.',
				'output_type' => 'video',
				'task_type'   => 'text-to-video',
				'inputs'      => [ 'prompt' => $prompt_input, 'negative_prompt' => $negative_input ],
				'nodes'       => [ 'CheckpointLoaderSimple', 'CLIPTextEncode', 'KSampler', 'VAEDecode', 'EmptyLTXVLatentVideo', 'LTXVConditioning', 'CreateVideo', 'SaveVideo' ],
				'models'      => [],
			],
			self::TEXT_IMAGE_TO_VIDEO => [
				'label'       => 'Text and image to video',
				'description' => 'A video generated from a prompt and a starting image.',
				'output_type' => 'video',
				'task_type'   => 'image-to-video',
				'inputs'      => [ 'prompt' => $prompt_input, 'negative_prompt' => $negative_input, 'image' => $image_input ],
				'nodes'       => [ 'CheckpointLoaderSimple', 'CLIPTextEncode', 'KSampler', 'VAEDecode', 'LoadImage', 'LTXVImgToVideo', 'LTXVConditioning', 'CreateVideo', 'SaveVideo' ],
				'models'      => [],
			],
			self::VIDEO_TO_VIDEO      => [
				'label'       => 'Video to video',
				'description' => 'A video transformed from source video with optional text guidance.',
				'output_type' => 'video',
				'task_type'   => 'video-to-video',
				'inputs'      => [
					'prompt'          => $optional_prompt_input,
					'negative_prompt' => $negative_input,
					'start_frame'     => [ 'type' => 'media', 'required' => true, 'label' => 'Start frame' ],
					'end_frame'       => [ 'type' => 'media', 'required' => false, 'label' => 'End frame' ],
				],
				'nodes'       => [ 'CheckpointLoaderSimple', 'CLIPTextEncode', 'KSampler', 'VAEDecode', 'EmptyLTXVLatentVideo', 'LoadImage', 'LTXVAddGuide', 'LTXVCropGuides', 'LTXVConditioning', 'CreateVideo', 'SaveVideo' ],
				'models'      => [],
			],
			self::VIDEO_WITH_AUDIO    => [
				'label'       => 'Video with audio',
				'description' => 'A video generated or transformed with a bound audio source.',
				'output_type' => 'video',
				'task_type'   => 'video-with-audio',
				'inputs'      => [
					'prompt'          => $optional_prompt_input,
					'negative_prompt' => $negative_input,
					'video'           => [ 'type' => 'media', 'required' => false, 'label' => 'Source video' ],
					'audio'           => $audio_input,
				],
				'nodes'       => [ 'CheckpointLoaderSimple', 'CLIPTextEncode', 'KSampler', 'VAEDecode', 'EmptyLTXVLatentVideo', 'LTXVConditioning', 'LoadAudio', 'CreateVideo', 'SaveVideo' ],
				'models'      => [],
			],
			self::TEXT_TO_SPEECH      => [
				'label'       => 'Text to speech',
				'description' => 'Spoken audio generated from a text prompt using a provider voice.',
				'output_type' => 'audio',
				'task_type'   => 'text-to-speech',
				'inputs'      => [ 'prompt' => $prompt_input ],
				'nodes'       => [],
				'models'      => [],
			],
			self::TEXT_TO_DIALOGUE    => [
				'label'       => 'Text to dialogue',
				'description' => 'Multi-speaker dialogue generated from text and voice assignments.',
				'output_type' => 'audio',
				'task_type'   => 'text-to-dialogue',
				'inputs'      => [ 'prompt' => $prompt_input ],
				'nodes'       => [],
				'models'      => [],
			],
			self::TEXT_TO_SOUND_EFFECT => [
				'label'       => 'Text to sound effect',
				'description' => 'A sound effect generated from a text description.',
				'output_type' => 'audio',
				'task_type'   => 'text-to-sound-effects',
				'inputs'      => [ 'prompt' => $prompt_input ],
				'nodes'       => [],
				'models'      => [],
			],
			self::TEXT_TO_MUSIC       => [
				'label'       => 'Text to music',
				'description' => 'Music generated from a prompt or composition plan.',
				'output_type' => 'audio',
				'task_type'   => 'text-to-music',
				'inputs'      => [ 'prompt' => $prompt_input ],
				'nodes'       => [],
				'models'      => [],
			],
			self::TEXT_TO_VOICE       => [
				'label'       => 'Text to voice design',
				'description' => 'Voice previews generated from a natural-language voice description.',
				'output_type' => 'audio',
				'task_type'   => 'text-to-voice',
				'inputs'      => [ 'prompt' => $prompt_input ],
				'nodes'       => [],
				'models'      => [],
			],
			self::TEXT_TO_LYRICS      => [
				'label'       => 'Text to lyrics',
				'description' => 'Structured song lyrics generated from a text description.',
				'output_type' => 'text',
				'task_type'   => 'text-to-lyrics',
				'inputs'      => [ 'prompt' => $prompt_input ],
				'nodes'       => [],
				'models'      => [],
			],
		];

		/**
		 * Filters provider-neutral generation modality definitions.
		 *
		 * Extensions may add a modality when a provider produces a genuinely new
		 * input/output shape. Invalid definitions are discarded so discovery data
		 * cannot turn an unknown operation into a text-to-image Template.
		 *
		 * @param array<string, array> $modalities Registered modality definitions.
		 */
		$filtered = apply_filters( 'worldgraph_generation_modalities', $modalities );
		if ( ! is_array( $filtered ) ) {
			return $modalities;
		}

		$normalized = [];
		foreach ( $filtered as $slug => $definition ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug || ! is_array( $definition ) ) {
				continue;
			}
			$output_type = sanitize_key( (string) ( $definition['output_type'] ?? '' ) );
			if ( ! in_array( $output_type, [ 'image', 'video', 'audio', 'text' ], true ) ) {
				continue;
			}
			$definition['label']       = sanitize_text_field( (string) ( $definition['label'] ?? $slug ) );
			$definition['output_type'] = $output_type;
			$definition['inputs']      = is_array( $definition['inputs'] ?? null ) ? $definition['inputs'] : [];
			$definition['nodes']       = is_array( $definition['nodes'] ?? null ) ? $definition['nodes'] : [];
			$definition['models']      = is_array( $definition['models'] ?? null ) ? $definition['models'] : [];
			$normalized[ $slug ]       = $definition;
		}

		return isset( $normalized[ self::FALLBACK ] ) ? $normalized : $modalities;
	}

	/**
	 * Registered modality slugs.
	 *
	 * @return array<int, string>
	 */
	public static function slugs(): array {
		return array_keys( self::all() );
	}

	/** Whether a modality slug is explicitly registered (without fallback). */
	public static function has( string $slug ): bool {
		return array_key_exists( sanitize_key( $slug ), self::all() );
	}

	/**
	 * Slug => label map, for admin select fields.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array_map(
			static function ( array $modality ): string {
				return (string) $modality['label'];
			},
			self::all()
		);
	}

	/**
	 * Look up a modality definition.
	 *
	 * @param string $slug Modality slug.
	 * @return array
	 */
	public static function get( string $slug ): array {
		$all = self::all();

		return $all[ self::sanitize( $slug ) ];
	}

	/**
	 * Reduce arbitrary input to a registered modality slug.
	 *
	 * @param string $slug Candidate slug.
	 * @return string
	 */
	public static function sanitize( string $slug ): string {
		$slug = sanitize_key( $slug );

		return array_key_exists( $slug, self::all() ) ? $slug : self::FALLBACK;
	}

	/**
	 * The output kind a modality produces: image, video, audio, or text.
	 *
	 * @param string $slug Modality slug.
	 * @return string
	 */
	public static function output_type( string $slug ): string {
		return (string) self::get( $slug )['output_type'];
	}

	/**
	 * Input slot definitions for a modality.
	 *
	 * @param string $slug Modality slug.
	 * @return array<string, array>
	 */
	public static function inputs( string $slug ): array {
		return (array) self::get( $slug )['inputs'];
	}

	/**
	 * Input slots that must be supplied before a job can be submitted.
	 *
	 * @param string $slug Modality slug.
	 * @return array<int, string>
	 */
	public static function required_inputs( string $slug ): array {
		$required = [];
		foreach ( self::inputs( $slug ) as $name => $input ) {
			if ( ! empty( $input['required'] ) ) {
				$required[] = $name;
			}
		}

		return $required;
	}

	/**
	 * Input slots of a modality that carry a media file.
	 *
	 * @param string $slug Modality slug.
	 * @return array<int, string>
	 */
	public static function media_inputs( string $slug ): array {
		return array_values( array_intersect( array_keys( self::inputs( $slug ) ), self::MEDIA_SLOTS ) );
	}

	/**
	 * ComfyUI node class types the built-in graph for a modality relies on.
	 *
	 * @param string $slug Modality slug.
	 * @return array<int, string>
	 */
	public static function required_nodes( string $slug ): array {
		return array_values( array_unique( (array) self::get( $slug )['nodes'] ) );
	}

	/**
	 * Model slots a modality loads, keyed by slot name.
	 *
	 * @param string $slug Modality slug.
	 * @return array<string, array>
	 */
	public static function model_slots( string $slug ): array {
		return (array) self::get( $slug )['models'];
	}

	/**
	 * Sampling and framing defaults for a modality, overridable per Template.
	 *
	 * @param string $slug Modality slug.
	 * @return array<string, mixed>
	 */
	public static function default_settings( string $slug ): array {
		if ( in_array( self::output_type( $slug ), [ 'audio', 'text' ], true ) ) {
			return [];
		}
		if ( 'video' === self::output_type( $slug ) ) {
			return [
				'width'      => 768,
				'height'     => 512,
				'length'     => 97,
				'frame_rate' => 25,
				'steps'      => 30,
				'cfg'        => 3.0,
				'denoise'    => 1.0,
				'sampler'    => 'euler',
				'scheduler'  => 'normal',
			];
		}

		// SDXL-class defaults: 1024x1024 native framing with the sampler and
		// scheduler pairing that model family is tuned for.
		return [
			'width'     => 1024,
			'height'    => 1024,
			'steps'     => 30,
			'cfg'       => 7.0,
			'denoise'   => self::TEXT_TO_IMAGE === self::sanitize( $slug ) ? 1.0 : 0.65,
			'sampler'   => 'dpmpp_2m',
			'scheduler' => 'karras',
		];
	}

	/**
	 * Build the built-in ComfyUI API-format graph for a modality.
	 *
	 * Media inputs are emitted as `{{slot}}` placeholders that the provider
	 * client replaces with the filename it uploaded to ComfyUI's input
	 * directory, so the same graph works for every job.
	 *
	 * @param string $slug     Modality slug.
	 * @param array  $settings Overrides for checkpoint and sampling defaults.
	 * @return array
	 */
	public static function default_workflow( string $slug, array $settings = [] ): array {
		$slug     = self::sanitize( $slug );
		if ( in_array( self::output_type( $slug ), [ 'audio', 'text' ], true ) ) {
			return [];
		}
		$settings = array_merge( self::default_settings( $slug ), array_filter( $settings, static function ( $value ) {
			return null !== $value && '' !== $value;
		} ) );

		$checkpoint = (string) ( $settings['checkpoint'] ?? '' );
		$seed       = isset( $settings['seed'] ) && preg_match( '/^\d+$/', (string) $settings['seed'] )
			? min( (int) $settings['seed'], 9007199254740991 )
			: wp_rand( 0, PHP_INT_MAX >> 1 );

		$graph = [
			'4' => [
				'class_type' => 'CheckpointLoaderSimple',
				'inputs'     => [ 'ckpt_name' => $checkpoint ],
			],
			'6' => [
				'class_type' => 'CLIPTextEncode',
				'inputs'     => [ 'text' => '{{prompt}}', 'clip' => [ '4', 1 ] ],
			],
			'7' => [
				'class_type' => 'CLIPTextEncode',
				'inputs'     => [ 'text' => '{{negative_prompt}}', 'clip' => [ '4', 1 ] ],
			],
			'3' => [
				'class_type' => 'KSampler',
				'inputs'     => [
					'seed'         => $seed,
					'steps'        => (int) $settings['steps'],
					'cfg'          => (float) $settings['cfg'],
					'sampler_name' => (string) $settings['sampler'],
					'scheduler'    => (string) $settings['scheduler'],
					'denoise'      => (float) $settings['denoise'],
					'model'        => [ '4', 0 ],
					'positive'     => [ '6', 0 ],
					'negative'     => [ '7', 0 ],
					'latent_image' => [ '5', 0 ],
				],
			],
			'8' => [
				'class_type' => 'VAEDecode',
				'inputs'     => [ 'samples' => [ '3', 0 ], 'vae' => [ '4', 2 ] ],
			],
		];

		$lora_name = trim( (string) ( $settings['lora_name'] ?? '' ) );
		if ( '' !== $lora_name ) {
			$strength = is_numeric( $settings['lora_strength'] ?? null ) ? (float) $settings['lora_strength'] : 1.0;

			$graph['11'] = [
				'class_type' => 'LoraLoader',
				'inputs'     => [
					'lora_name'      => $lora_name,
					'strength_model' => $strength,
					'strength_clip'  => $strength,
					'model'          => [ '4', 0 ],
					'clip'           => [ '4', 1 ],
				],
			];
			// Route the sampler and both encoders through the LoRA-patched model/CLIP.
			$graph['3']['inputs']['model'] = [ '11', 0 ];
			$graph['6']['inputs']['clip']  = [ '11', 1 ];
			$graph['7']['inputs']['clip']  = [ '11', 1 ];
		}

		return 'video' === self::output_type( $slug )
			? self::video_graph( $slug, $graph, $settings )
			: self::image_graph( $slug, $graph, $settings );
	}

	/**
	 * Finish an image graph: latent source plus SaveImage.
	 *
	 * @param string $slug     Modality slug.
	 * @param array  $graph    Shared graph scaffold.
	 * @param array  $settings Resolved settings.
	 * @return array
	 */
	private static function image_graph( string $slug, array $graph, array $settings ): array {
		if ( self::TEXT_TO_IMAGE === $slug ) {
			$graph['5'] = [
				'class_type' => 'EmptyLatentImage',
				'inputs'     => [
					'width'      => (int) $settings['width'],
					'height'     => (int) $settings['height'],
					'batch_size' => 1,
				],
			];
		} else {
			$graph['10'] = [
				'class_type' => 'LoadImage',
				'inputs'     => [ 'image' => '{{image}}' ],
			];
			$graph['5'] = [
				'class_type' => 'VAEEncode',
				'inputs'     => [ 'pixels' => [ '10', 0 ], 'vae' => [ '4', 2 ] ],
			];
		}

		$graph['9'] = [
			'class_type' => 'SaveImage',
			'inputs'     => [ 'filename_prefix' => 'worldgraph', 'images' => [ '8', 0 ] ],
		];

		return $graph;
	}

	/**
	 * Finish a video graph: latent source, LTX conditioning, and video muxing.
	 *
	 * @param string $slug     Modality slug.
	 * @param array  $graph    Shared graph scaffold.
	 * @param array  $settings Resolved settings.
	 * @return array
	 */
	private static function video_graph( string $slug, array $graph, array $settings ): array {
		$empty_latent = [
			'class_type' => 'EmptyLTXVLatentVideo',
			'inputs'     => [
				'width'      => (int) $settings['width'],
				'height'     => (int) $settings['height'],
				'length'     => (int) $settings['length'],
				'batch_size' => 1,
			],
		];

		$positive = [ '6', 0 ];
		$negative = [ '7', 0 ];
		$latent   = [ '5', 0 ];
		$decode   = [ '3', 0 ];

		if ( self::TEXT_IMAGE_TO_VIDEO === $slug ) {
			$graph['10'] = [
				'class_type' => 'LoadImage',
				'inputs'     => [ 'image' => '{{image}}' ],
			];
			$graph['5'] = [
				'class_type' => 'LTXVImgToVideo',
				'inputs'     => [
					'positive'   => [ '6', 0 ],
					'negative'   => [ '7', 0 ],
					'vae'        => [ '4', 2 ],
					'image'      => [ '10', 0 ],
					'width'      => (int) $settings['width'],
					'height'     => (int) $settings['height'],
					'length'     => (int) $settings['length'],
					'batch_size' => 1,
				],
			];
			$positive = [ '5', 0 ];
			$negative = [ '5', 1 ];
			$latent   = [ '5', 2 ];
		} elseif ( self::VIDEO_TO_VIDEO === $slug ) {
			$graph['5']  = $empty_latent;
			$graph['10'] = [
				'class_type' => 'LoadImage',
				'inputs'     => [ 'image' => '{{start_frame}}' ],
			];
			$graph['17'] = [
				'class_type' => 'LTXVAddGuide',
				'inputs'     => [
					'positive'  => [ '6', 0 ],
					'negative'  => [ '7', 0 ],
					'vae'       => [ '4', 2 ],
					'latent'    => [ '5', 0 ],
					'image'     => [ '10', 0 ],
					'frame_idx' => 0,
					'strength'  => 1.0,
				],
			];
			$guide_node = '17';

			// The ending frame is optional; only chain a second guide when one
			// was supplied, otherwise ComfyUI would fail on an empty LoadImage.
			if ( ! empty( $settings['has_end_frame'] ) ) {
				$graph['16'] = [
					'class_type' => 'LoadImage',
					'inputs'     => [ 'image' => '{{end_frame}}' ],
				];
				$graph['18'] = [
					'class_type' => 'LTXVAddGuide',
					'inputs'     => [
						'positive'  => [ '17', 0 ],
						'negative'  => [ '17', 1 ],
						'vae'       => [ '4', 2 ],
						'latent'    => [ '17', 2 ],
						'image'     => [ '16', 0 ],
						'frame_idx' => -1,
						'strength'  => 1.0,
					],
				];
				$guide_node = '18';
			}

			$positive = [ $guide_node, 0 ];
			$negative = [ $guide_node, 1 ];
			$latent   = [ $guide_node, 2 ];

			$graph['19'] = [
				'class_type' => 'LTXVCropGuides',
				'inputs'     => [
					'positive' => [ '12', 0 ],
					'negative' => [ '12', 1 ],
					'latent'   => [ '3', 0 ],
				],
			];
			$decode = [ '19', 2 ];
		} else {
			$graph['5'] = $empty_latent;
		}

		$graph['12'] = [
			'class_type' => 'LTXVConditioning',
			'inputs'     => [
				'positive'   => $positive,
				'negative'   => $negative,
				'frame_rate' => (float) $settings['frame_rate'],
			],
		];

		$graph['3']['inputs']['positive']     = [ '12', 0 ];
		$graph['3']['inputs']['negative']     = [ '12', 1 ];
		$graph['3']['inputs']['latent_image'] = $latent;
		$graph['8']['inputs']['samples']      = $decode;

		$graph['13'] = [
			'class_type' => 'CreateVideo',
			'inputs'     => [ 'images' => [ '8', 0 ], 'fps' => (float) $settings['frame_rate'] ],
		];

		if ( self::VIDEO_WITH_AUDIO === $slug ) {
			$graph['20'] = [
				'class_type' => 'LoadAudio',
				'inputs'     => [ 'audio' => '{{audio}}' ],
			];
			$graph['13']['inputs']['audio'] = [ '20', 0 ];
		}

		$graph['14'] = [
			'class_type' => 'SaveVideo',
			'inputs'     => [
				'video'           => [ '13', 0 ],
				'filename_prefix' => 'worldgraph',
				'format'          => 'auto',
				'codec'           => 'auto',
			],
		];

		return $graph;
	}
}
