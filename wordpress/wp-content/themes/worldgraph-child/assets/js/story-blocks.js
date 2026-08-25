( function ( blocks, blockEditor, components, element, i18n, ServerSideRender ) {
	'use strict';

	if ( ! blocks || ! blockEditor || ! components || ! element || ! i18n || ! ServerSideRender ) {
		return;
	}

	var el = element.createElement;
	var Fragment = element.Fragment;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var RangeControl = components.RangeControl;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
	var __ = i18n.__;

	function EditorPreview( props ) {
		var blockProps = blockEditor.useBlockProps ? blockEditor.useBlockProps() : { className: 'worldgraph-story-block-preview' };

		return el(
			'div',
			blockProps,
			el( ServerSideRender, {
				block: props.blockName,
				attributes: props.attributes,
				httpMethod: 'POST'
			} )
		);
	}

	blocks.registerBlockType( 'worldgraph/story-item', {
		apiVersion: 3,
		title: __( 'Story Item', 'worldgraph-child' ),
		description: __( 'Display a Project, World, Character, Scene, Prop, or Sound using its purpose-built card.', 'worldgraph-child' ),
		category: 'widgets',
		icon: 'book-alt',
		attributes: {
			postId: {
				type: 'integer',
				default: 0
			},
			display: {
				type: 'string',
				default: 'auto'
			}
		},
		usesContext: [ 'postId' ],
		supports: {
			align: [ 'wide', 'full' ],
			html: false
		},
		edit: function ( props ) {
			var attributes = props.attributes;
			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{
							title: __( 'Story display', 'worldgraph-child' ),
							initialOpen: true
						},
						el( TextControl, {
							label: __( 'Post ID', 'worldgraph-child' ),
							type: 'number',
							min: 0,
							value: attributes.postId || '',
							help: __( 'Leave at 0 to use the current story item.', 'worldgraph-child' ),
							onChange: function ( value ) {
								props.setAttributes( { postId: Math.max( 0, parseInt( value, 10 ) || 0 ) } );
							}
						} ),
						el( SelectControl, {
							label: __( 'Display', 'worldgraph-child' ),
							value: attributes.display,
							options: [
								{ label: __( 'Automatic', 'worldgraph-child' ), value: 'auto' },
								{ label: __( 'Card', 'worldgraph-child' ), value: 'card' },
								{ label: __( 'Detail', 'worldgraph-child' ), value: 'detail' }
							],
							onChange: function ( value ) {
								props.setAttributes( { display: value } );
							}
						} )
					)
				),
				el( EditorPreview, { blockName: 'worldgraph/story-item', attributes: attributes } )
			);
		},
		save: function () {
			return null;
		}
	} );

	blocks.registerBlockType( 'worldgraph/story-collection', {
		apiVersion: 3,
		title: __( 'Story Collection', 'worldgraph-child' ),
		description: __( 'Display a paginated collection of purpose-built Story Graph cards.', 'worldgraph-child' ),
		category: 'widgets',
		icon: 'screenoptions',
		attributes: {
			postType: {
				type: 'string',
				default: ''
			},
			postsPerPage: {
				type: 'integer',
				default: 12
			},
			title: {
				type: 'string',
				default: ''
			},
			showHeading: {
				type: 'boolean',
				default: true
			}
		},
		supports: {
			align: [ 'wide', 'full' ],
			html: false
		},
		edit: function ( props ) {
			var attributes = props.attributes;
			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{
							title: __( 'Story collection', 'worldgraph-child' ),
							initialOpen: true
						},
						el( SelectControl, {
							label: __( 'Content type', 'worldgraph-child' ),
							value: attributes.postType,
							options: [
								{ label: __( 'Use current archive', 'worldgraph-child' ), value: '' },
								{ label: __( 'Projects', 'worldgraph-child' ), value: 'worldgraph_project' },
								{ label: __( 'Worlds', 'worldgraph-child' ), value: 'worldgraph_world' },
								{ label: __( 'Characters', 'worldgraph-child' ), value: 'worldgraph_character' },
								{ label: __( 'Scenes', 'worldgraph-child' ), value: 'worldgraph_scene' },
								{ label: __( 'Props', 'worldgraph-child' ), value: 'worldgraph_prop' },
								{ label: __( 'Sounds & Songs', 'worldgraph-child' ), value: 'worldgraph_sound' }
							],
							onChange: function ( value ) {
								props.setAttributes( { postType: value } );
							}
						} ),
						el( RangeControl, {
							label: __( 'Items per page', 'worldgraph-child' ),
							value: attributes.postsPerPage,
							min: 1,
							max: 48,
							onChange: function ( value ) {
								props.setAttributes( { postsPerPage: value } );
							}
						} ),
						el( TextControl, {
							label: __( 'Custom heading', 'worldgraph-child' ),
							value: attributes.title,
							onChange: function ( value ) {
								props.setAttributes( { title: value } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show collection heading', 'worldgraph-child' ),
							checked: attributes.showHeading,
							onChange: function ( value ) {
								props.setAttributes( { showHeading: value } );
							}
						} )
					)
				),
				el( EditorPreview, { blockName: 'worldgraph/story-collection', attributes: attributes } )
			);
		},
		save: function () {
			return null;
		}
	} );

	blocks.registerBlockType( 'worldgraph/storyboard', {
		apiVersion: 3,
		title: __( 'Storyboard', 'worldgraph-child' ),
		description: __( 'Display every published Shot as a visual storyboard panel.', 'worldgraph-child' ),
		category: 'widgets',
		icon: 'format-gallery',
		supports: { align: [ 'wide', 'full' ], html: false },
		edit: function ( props ) {
			return el( EditorPreview, { blockName: 'worldgraph/storyboard', attributes: props.attributes } );
		},
		save: function () {
			return null;
		}
	} );
}( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.i18n, window.wp.serverSideRender ) );
