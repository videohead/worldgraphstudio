/**
 * Guided Story Graph representative-media generation controls.
 */
( function () {
	'use strict';

	var settings = window.worldgraphAssetGenerator || {};
	var strings = settings.i18n || {};

	function setStatus( panel, message, isError ) {
		var status = panel.querySelector( '.worldgraph-generate-asset__status' );
		status.textContent = message || '';
		status.classList.toggle( 'is-error', !! isError );
	}

	function request( url, options ) {
		return fetch( url, Object.assign( {
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': settings.nonce
			}
		}, options || {} ) ).then( function ( response ) {
			return response.json().then( function ( body ) {
				if ( ! response.ok ) {
					throw new Error( ( body && body.message ) || strings.error );
				}
				return body;
			} );
		} );
	}

	function uuid() {
		if ( window.crypto && 'function' === typeof window.crypto.randomUUID ) {
			return window.crypto.randomUUID();
		}
		return Date.now().toString( 36 ) + '-' + Math.random().toString( 36 ).slice( 2 );
	}

	function isTerminal( status ) {
		return [ 'completed', 'completed_with_errors', 'cancelled', 'failed' ].indexOf( status ) !== -1;
	}

	function isSingleTerminal( status ) {
		return [ 'completed', 'cancelled', 'failed' ].indexOf( status ) !== -1;
	}

	function generationStatusBaseUrl() {
		if ( settings.generationRestUrl ) {
			return settings.generationRestUrl;
		}
		return settings.restUrl.replace( /\/assets\/generate$/, '/generation' );
	}

	function clearSinglePoll( panel ) {
		if ( panel._worldgraphSinglePollTimer ) {
			window.clearTimeout( panel._worldgraphSinglePollTimer );
		}
		panel._worldgraphSinglePollTimer = null;
		panel._worldgraphSingleWatchToken = ( panel._worldgraphSingleWatchToken || 0 ) + 1;
	}

	function watchSingleJob( panel, generationId, type ) {
		clearSinglePoll( panel );
		var watchToken = panel._worldgraphSingleWatchToken;
		var queued = 'video' === type ? strings.queuedVideo : ( 'audio' === type ? strings.queuedAudio : strings.queuedImage );

		function poll() {
			if ( watchToken !== panel._worldgraphSingleWatchToken ) {
				return;
			}

			request( generationStatusBaseUrl() + '/' + encodeURIComponent( generationId ) )
				.then( function ( body ) {
					if ( watchToken !== panel._worldgraphSingleWatchToken ) {
						return;
					}

					var status = body.status || 'unknown';
					if ( 'completed' === status ) {
						setStatus( panel, '' );
						renderResult( panel, body, type );
						clearSinglePoll( panel );
						return;
					}

					if ( isSingleTerminal( status ) ) {
						setStatus( panel, body.error || strings.error, true );
						clearSinglePoll( panel );
						return;
					}

					setStatus( panel, queued + ' (' + status + ')' + ' (' + strings.job + ' #' + generationId + ')' );
					panel._worldgraphSinglePollTimer = window.setTimeout( poll, settings.pollIntervalMs || 15000 );
				} )
				.catch( function ( error ) {
					if ( watchToken !== panel._worldgraphSingleWatchToken ) {
						return;
					}
					setStatus( panel, error.message, true );
					panel._worldgraphSinglePollTimer = window.setTimeout( poll, Math.max( 30000, settings.pollIntervalMs || 15000 ) );
				} );
		}

		panel._worldgraphSinglePollTimer = window.setTimeout( poll, settings.pollIntervalMs || 15000 );
	}

	function targetInfo( value ) {
		if ( 0 === value.indexOf( 'single:' ) ) {
			return { kind: 'single', intent: value.slice( 7 ) };
		}
		if ( 'workflow:item' === value ) {
			return { kind: 'workflow', scope: 'item' };
		}
		if ( 'workflow:project' === value ) {
			return { kind: 'workflow', scope: 'project' };
		}
		if ( 'workflow:demonstration' === value ) {
			return { kind: 'workflow', scope: 'demonstration' };
		}
		return { kind: '' };
	}

	function currentTarget( panel ) {
		return panel.querySelector( '.worldgraph-generate-asset__action-select' ).value || '';
	}

	function currentMode( panel ) {
		var selected = panel.querySelector( '.worldgraph-generate-asset__modes input:checked' );
		return selected ? selected.value : '';
	}

	function actionForIntent( panel, intent ) {
		var match = null;
		( panel._worldgraphActions || [] ).some( function ( action ) {
			if ( intent === action.intent ) {
				match = action;
				return true;
			}
			return false;
		} );
		return match;
	}

	function selectHasValue( select, value ) {
		var found = false;
		Array.prototype.some.call( select.options, function ( option ) {
			if ( String( value ) === option.value ) {
				found = true;
				return true;
			}
			return false;
		} );
		return found;
	}

	function selectHasEnabledOption( select ) {
		return Array.prototype.some.call( select.options, function ( option ) {
			return ! option.disabled;
		} );
	}

	function countedLabel( count, singular, plural ) {
		return count + ' ' + ( 1 === parseInt( count, 10 ) ? singular : plural );
	}

	function templateSelect( panel, type ) {
		var selectors = {
			image: '.worldgraph-generate-asset__template',
			video: '.worldgraph-generate-asset__video-template',
			audio: '.worldgraph-generate-asset__audio-template'
		};
		return panel.querySelector( selectors[ type ] || selectors.image );
	}

	function templateContainer( panel, type ) {
		var selectors = {
			image: '.worldgraph-generate-asset__image-template-option',
			video: '.worldgraph-generate-asset__video-template-option',
			audio: '.worldgraph-generate-asset__audio-template-option'
		};
		return panel.querySelector( selectors[ type ] || selectors.image );
	}

	function setTemplateVisibility( panel, type, visible, help ) {
		var container = templateContainer( panel, type );
		var select = templateSelect( panel, type );
		container.hidden = ! visible;
		select.disabled = ! visible;
		container.querySelector( 'p' ).textContent = visible ? help : '';
	}

	function fillTemplateSelect( select, templates, defaultTemplateId, allowConfigured, savedValue, type ) {
		select.textContent = '';
		var placeholder = document.createElement( 'option' );
		placeholder.value = allowConfigured ? '0' : '';
		placeholder.textContent = allowConfigured ? strings.configuredPerItem : ( 'audio' === type ? strings.chooseAudio : ( 'video' === type ? strings.chooseVideo : strings.chooseImage ) );
		placeholder.disabled = ! allowConfigured;
		select.appendChild( placeholder );

		( templates || [] ).forEach( function ( template ) {
			var option = document.createElement( 'option' );
			option.value = String( template.id );
			option.textContent = template.name + ( template.modality ? ' (' + template.modality + ')' : '' );
			option._worldgraphTemplate = template;
			select.appendChild( option );
		} );

		var desired = null !== savedValue && 'undefined' !== typeof savedValue ? String( savedValue ) : String( defaultTemplateId || '' );
		if ( desired && selectHasValue( select, desired ) ) {
			select.value = desired;
		} else if ( defaultTemplateId && selectHasValue( select, String( defaultTemplateId ) ) ) {
			select.value = String( defaultTemplateId );
		} else {
			select.value = allowConfigured ? '0' : '';
		}
	}

	function rememberTemplateSelection( panel, type ) {
		var target = panel._worldgraphRenderedTarget;
		if ( ! target ) {
			return;
		}
		panel._worldgraphTemplateSelections = panel._worldgraphTemplateSelections || {};
		panel._worldgraphTemplateSelections[ target ] = panel._worldgraphTemplateSelections[ target ] || {};
		panel._worldgraphTemplateSelections[ target ][ type ] = templateSelect( panel, type ).value;
	}

	function savedTemplate( panel, target, type ) {
		var selections = ( panel._worldgraphTemplateSelections || {} )[ target ] || {};
		return Object.prototype.hasOwnProperty.call( selections, type ) ? selections[ type ] : null;
	}

	var runControlGroups = [ 'conditioning', 'sampling', 'output', 'advanced' ];
	var runControlTypes = [ 'string', 'textarea', 'integer', 'number', 'boolean', 'select' ];

	function safeRunControlKey( key ) {
		key = String( key || '' );
		return /^[A-Za-z_][A-Za-z0-9_.-]{0,127}$/.test( key ) && [ '__proto__', 'prototype', 'constructor' ].indexOf( key ) === -1 ? key : '';
	}

	function runControlFields( template ) {
		var controls = template && template.run_controls;
		if ( ! controls || 1 !== parseInt( controls.version, 10 ) || 'string' !== typeof controls.fingerprint || ! Array.isArray( controls.fields ) ) {
			return [];
		}

		var seen = {};
		return controls.fields.filter( function ( field ) {
			var key = field && safeRunControlKey( field.key );
			var type = field && String( field.type || '' );
			if ( ! key || seen[ key ] || ! String( field.label || '' ).trim() || runControlTypes.indexOf( type ) === -1 ) {
				return false;
			}
			if ( 'select' === type && ( ! Array.isArray( field.options ) || ! validRunControlOptions( field ).length ) ) {
				return false;
			}
			seen[ key ] = true;
			return true;
		} );
	}

	function validRunControlOptions( field ) {
		return ( field && Array.isArray( field.options ) ? field.options : [] ).filter( function ( option ) {
			return option && Object.prototype.hasOwnProperty.call( option, 'value' );
		} );
	}

	function runControlFingerprint( template ) {
		var controls = template && template.run_controls;
		return controls && 'string' === typeof controls.fingerprint ? controls.fingerprint : '';
	}

	function templateForId( panel, type, templateId ) {
		var match = null;
		Array.prototype.some.call( templateSelect( panel, type ).options, function ( option ) {
			if ( parseInt( option.value, 10 ) === parseInt( templateId, 10 ) && option._worldgraphTemplate ) {
				match = option._worldgraphTemplate;
				return true;
			}
			return false;
		} );
		if ( match ) {
			return match;
		}
		( ( panel._worldgraphTemplates || {} )[ type ] || [] ).some( function ( template ) {
			if ( parseInt( template.id, 10 ) === parseInt( templateId, 10 ) ) {
				match = template;
				return true;
			}
			return false;
		} );
		return match;
	}

	function runControlState( panel, target, template, create ) {
		panel._worldgraphRunValues = panel._worldgraphRunValues || {};
		var targetState = panel._worldgraphRunValues[ target ];
		var templateId = String( parseInt( template.id, 10 ) || 0 );
		if ( ! targetState && create ) {
			panel._worldgraphRunValues[ target ] = {};
			targetState = panel._worldgraphRunValues[ target ];
		}
		var state = targetState && targetState[ templateId ];
		var fingerprint = runControlFingerprint( template );
		if ( state && state.fingerprint !== fingerprint ) {
			delete targetState[ templateId ];
			state = null;
		}
		if ( ! state && create ) {
			state = { fingerprint: fingerprint, values: {} };
			targetState[ templateId ] = state;
		}
		return state || null;
	}

	function finiteRunControlNumber( value ) {
		if ( null === value || '' === value || 'undefined' === typeof value ) {
			return null;
		}
		var number = Number( value );
		return isFinite( number ) ? number : null;
	}

	function booleanRunControlValue( value ) {
		return true === value || 1 === value || '1' === String( value ) || 'true' === String( value ).toLowerCase();
	}

	function optionRunControlValue( field, value ) {
		var selected;
		validRunControlOptions( field ).some( function ( option ) {
			if ( String( option.value ) === String( value ) ) {
				selected = option.value;
				return true;
			}
			return false;
		} );
		return selected;
	}

	function runControlSemantic( key ) {
		key = String( key || '' )
			.replace( /([a-z0-9])([A-Z])/g, '$1_$2' )
			.replace( /[^A-Za-z0-9]+/g, '_' )
			.replace( /^_+|_+$/g, '' )
			.toLowerCase();
		var aliases = {
			width: 'width',
			height: 'height',
			aspect_ratio: 'aspect_ratio',
			fps: 'fps',
			frame_rate: 'fps'
		};
		return aliases[ key ] || '';
	}

	function normalizedRunControlDefault( field, value ) {
		if ( 'select' === field.type ) {
			return optionRunControlValue( field, value );
		}
		if ( 'boolean' === field.type ) {
			return booleanRunControlValue( value );
		}
		if ( [ 'integer', 'number' ].indexOf( field.type ) !== -1 ) {
			var number = finiteRunControlNumber( value );
			var minimum = finiteRunControlNumber( field.min );
			var maximum = finiteRunControlNumber( field.max );
			var step = finiteRunControlNumber( field.step );
			if ( null === number || ( 'integer' === field.type && Math.floor( number ) !== number ) ) {
				return undefined;
			}
			if ( ( null !== minimum && number < minimum ) || ( null !== maximum && number > maximum ) ) {
				return undefined;
			}
			if ( null !== step && step > 0 ) {
				var quotient = ( number - ( null === minimum ? 0 : minimum ) ) / step;
				if ( Math.abs( quotient - Math.round( quotient ) ) > Math.max( 0.000000001, Math.abs( quotient ) * 0.000000000001 ) ) {
					return undefined;
				}
			}
			return number;
		}
		if ( [ 'string', 'textarea' ].indexOf( field.type ) !== -1 && null !== value && 'undefined' !== typeof value ) {
			var stringValue = String( value );
			if ( 'aspect_ratio' === runControlSemantic( field.key ) ) {
				stringValue = stringValue.trim();
				if ( ! /^(?:\d{1,4}(?:\.\d+)?:\d{1,4}(?:\.\d+)?|auto|square|portrait|landscape)$/i.test( stringValue ) ) {
					return undefined;
				}
			}
			return stringValue;
		}
		return undefined;
	}

	function effectiveRunControlDefault( panel, field, template ) {
		var repository = template && template.run_defaults;
		if ( repository && 1 === parseInt( repository.version, 10 ) && repository.effective && Object.prototype.hasOwnProperty.call( repository.effective, field.key ) ) {
			var repositoryValue = normalizedRunControlDefault( field, repository.effective[ field.key ] );
			if ( 'undefined' !== typeof repositoryValue ) {
				return {
					hasValue: true,
					value: repositoryValue,
					source: String( ( repository.sources || {} )[ field.key ] || 'template' )
				};
			}
		}

		var semantic = runControlSemantic( field.key );
		var profile = panel._worldgraphPromptBody && panel._worldgraphPromptBody.profile;
		var profileKey = {
			width: 'width',
			height: 'height',
			aspect_ratio: 'aspect_ratio',
			fps: 'frame_rate'
		}[ semantic ];
		if ( profile && profileKey && Object.prototype.hasOwnProperty.call( profile, profileKey ) ) {
			var projectValue = normalizedRunControlDefault( field, profile[ profileKey ] );
			if ( 'undefined' !== typeof projectValue ) {
				return { hasValue: true, value: projectValue, source: 'project_profile' };
			}
		}

		if ( Object.prototype.hasOwnProperty.call( field, 'default' ) ) {
			var templateValue = normalizedRunControlDefault( field, field.default );
			if ( 'undefined' !== typeof templateValue ) {
				return { hasValue: true, value: templateValue, source: 'template' };
			}
		}

		return { hasValue: false, value: '', source: '' };
	}

	function sameRunControlValue( left, right ) {
		return left === right || ( 'number' === typeof left && 'number' === typeof right && isFinite( left ) && isFinite( right ) && left === right );
	}

	function readRunControlValue( input, field ) {
		if ( 'boolean' === field.type ) {
			if ( input._worldgraphBooleanSelect ) {
				return '' === input.value ? undefined : booleanRunControlValue( input.value );
			}
			return !! input.checked;
		}
		if ( 'integer' === field.type ) {
			return '' === input.value || ! isFinite( Number( input.value ) ) ? undefined : parseInt( input.value, 10 );
		}
		if ( 'number' === field.type ) {
			return '' === input.value || ! isFinite( Number( input.value ) ) ? undefined : Number( input.value );
		}
		if ( 'select' === field.type ) {
			return optionRunControlValue( field, input.value );
		}
		return input.value;
	}

	function rememberRunControls( panel ) {
		var target = panel._worldgraphRunControlsTarget;
		if ( ! target ) {
			return;
		}

		Array.prototype.forEach.call( panel.querySelectorAll( '.worldgraph-generate-asset__run-control-template' ), function ( templatePanel ) {
			var template = templatePanel._worldgraphRunTemplate;
			if ( ! template ) {
				return;
			}
			var state = runControlState( panel, target, template, true );
			var values = {};
			Array.prototype.forEach.call( templatePanel.querySelectorAll( '[data-worldgraph-run-control]' ), function ( input ) {
				var field = input._worldgraphRunField;
				if ( ! field ) {
					return;
				}
				var value = readRunControlValue( input, field );
				if (
					'undefined' !== typeof value &&
					( input._worldgraphRunHasDefault
						? ! sameRunControlValue( value, input._worldgraphRunDefault )
						: input._worldgraphRunDirty )
				) {
					values[ safeRunControlKey( field.key ) ] = value;
				}
			} );
			state.values = values;
		} );
	}

	function nextRunControlId( panel, templateId ) {
		panel._worldgraphRunControlSequence = ( panel._worldgraphRunControlSequence || 0 ) + 1;
		return 'worldgraph-run-control-' + ( parseInt( panel.dataset.postId, 10 ) || 0 ) + '-' + ( parseInt( templateId, 10 ) || 0 ) + '-' + panel._worldgraphRunControlSequence;
	}

	function applyNumericRunControlAttributes( input, field ) {
		[ 'min', 'max', 'step' ].forEach( function ( attribute ) {
			var value = finiteRunControlNumber( field[ attribute ] );
			if ( null !== value ) {
				input.setAttribute( attribute, String( value ) );
			}
		} );
		if ( 'integer' === field.type && ! input.hasAttribute( 'step' ) ) {
			input.step = '1';
		} else if ( 'number' === field.type && ! input.hasAttribute( 'step' ) ) {
			input.step = 'any';
		}
	}

	function createRunControlInput( panel, field, template, savedValues ) {
		var wrapper = document.createElement( 'div' );
		var inputId = nextRunControlId( panel, template.id );
		var hasSaved = savedValues && Object.prototype.hasOwnProperty.call( savedValues, field.key );
		var effectiveDefault = effectiveRunControlDefault( panel, field, template );
		var value = hasSaved ? savedValues[ field.key ] : effectiveDefault.value;
		var input;
		var label = document.createElement( 'label' );

		wrapper.className = 'worldgraph-generate-asset__run-control-field worldgraph-generate-asset__run-control-field--' + field.type;
		wrapper.dataset.defaultSource = effectiveDefault.source;
		label.htmlFor = inputId;

		if ( 'textarea' === field.type ) {
			input = document.createElement( 'textarea' );
			input.rows = 3;
			input.value = null === value || 'undefined' === typeof value ? '' : String( value );
		} else if ( 'select' === field.type ) {
			input = document.createElement( 'select' );
			if ( ! effectiveDefault.hasValue ) {
				var defaultOption = document.createElement( 'option' );
				defaultOption.value = '';
				defaultOption.textContent = strings.useTemplateDefault || 'Use Template default';
				input.appendChild( defaultOption );
			}
			validRunControlOptions( field ).forEach( function ( optionDefinition ) {
				var option = document.createElement( 'option' );
				option.value = String( optionDefinition.value );
				option.textContent = String( Object.prototype.hasOwnProperty.call( optionDefinition, 'label' ) ? optionDefinition.label : optionDefinition.value );
				input.appendChild( option );
			} );
			input.value = null === value || 'undefined' === typeof value ? '' : String( value );
			if ( input.selectedIndex < 0 ) {
				input.selectedIndex = 0;
			}
		} else if ( 'boolean' === field.type && ! effectiveDefault.hasValue ) {
			input = document.createElement( 'select' );
			input._worldgraphBooleanSelect = true;
			[ [ '', strings.useTemplateDefault || 'Use Template default' ], [ 'true', strings.enabled || 'Enabled' ], [ 'false', strings.disabled || 'Disabled' ] ].forEach( function ( definition ) {
				var booleanOption = document.createElement( 'option' );
				booleanOption.value = definition[0];
				booleanOption.textContent = definition[1];
				input.appendChild( booleanOption );
			} );
			input.value = hasSaved ? String( booleanRunControlValue( value ) ) : '';
		} else {
			input = document.createElement( 'input' );
			input.type = 'boolean' === field.type ? 'checkbox' : ( [ 'integer', 'number' ].indexOf( field.type ) !== -1 ? 'number' : 'text' );
			if ( 'boolean' === field.type ) {
				input.checked = booleanRunControlValue( value );
			} else {
				input.value = null === value || 'undefined' === typeof value ? '' : String( value );
			}
		}

		input.id = inputId;
		input.className = 'worldgraph-generate-asset__run-control-input';
		input.setAttribute( 'data-worldgraph-run-control', '' );
		input._worldgraphRunField = field;
		input._worldgraphRunHasDefault = effectiveDefault.hasValue;
		input._worldgraphRunDefault = effectiveDefault.value;
		input._worldgraphRunDirty = !! hasSaved;
		input.addEventListener( 'input', function () {
			input._worldgraphRunDirty = true;
		} );
		input.addEventListener( 'change', function () {
			input._worldgraphRunDirty = true;
		} );
		if ( [ 'integer', 'number' ].indexOf( field.type ) !== -1 ) {
			applyNumericRunControlAttributes( input, field );
		}

		if ( 'boolean' === field.type && ! input._worldgraphBooleanSelect ) {
			label.className = 'worldgraph-generate-asset__run-control-checkbox';
			label.appendChild( input );
			var labelText = document.createElement( 'span' );
			labelText.textContent = String( field.label );
			label.appendChild( labelText );
			wrapper.appendChild( label );
		} else {
			label.textContent = String( field.label );
			wrapper.appendChild( label );
			wrapper.appendChild( input );
		}

		if ( field.description ) {
			var description = document.createElement( 'p' );
			description.id = inputId + '-description';
			description.className = 'description';
			description.textContent = String( field.description );
			input.setAttribute( 'aria-describedby', description.id );
			wrapper.appendChild( description );
		}
		if ( effectiveDefault.source ) {
			var source = document.createElement( 'small' );
			var sourceLabels = {
				template: strings.templateDefaultSource || 'Template default',
				project_profile: strings.projectProfileSource || 'Project media profile',
				project: strings.projectDefaultSource || 'Project default',
				item: strings.itemDefaultSource || 'Item default'
			};
			source.className = 'worldgraph-generate-asset__run-control-source';
			source.textContent = sourceLabels[ effectiveDefault.source ] || effectiveDefault.source;
			wrapper.appendChild( source );
		}

		return wrapper;
	}

	function completeRunControlValues( templatePanel ) {
		var values = {};
		Array.prototype.forEach.call( templatePanel.querySelectorAll( '[data-worldgraph-run-control]' ), function ( input ) {
			var field = input._worldgraphRunField;
			var value = field ? readRunControlValue( input, field ) : undefined;
			if ( field && 'undefined' !== typeof value && ( input._worldgraphRunHasDefault || input._worldgraphRunDirty ) ) {
				values[ safeRunControlKey( field.key ) ] = value;
			}
		} );
		return values;
	}

	function clearRunControlState( panel, templateId ) {
		var targetState = ( panel._worldgraphRunValues || {} )[ currentTarget( panel ) ];
		if ( targetState ) {
			delete targetState[ String( parseInt( templateId, 10 ) || 0 ) ];
		}
	}

	function persistRunDefaults( panel, templatePanel, target, reset ) {
		var template = templatePanel._worldgraphRunTemplate;
		var defaults = template && template.run_defaults;
		if ( ! template || ! defaults || ! defaults.fingerprint || panel._worldgraphBusy ) {
			return;
		}
		if ( reset && ! window.confirm( strings.confirmResetDefaults || 'Reset this saved default layer and inherit from the layer above?' ) ) {
			return;
		}

		rememberRunControls( panel );
		var payload = {
			post_id: parseInt( panel.dataset.postId, 10 ),
			template_id: parseInt( template.id, 10 ),
			scope: String( target.scope ),
			fingerprint: String( defaults.fingerprint )
		};
		if ( ! reset ) {
			payload.values = completeRunControlValues( templatePanel );
		}
		panel._worldgraphBusy = true;
		updatePrimaryState( panel );
		setStatus( panel, reset ? ( strings.resettingDefaults || 'Resetting saved defaults…' ) : ( strings.savingDefaults || 'Saving defaults…' ) );
		request( settings.restUrl + '/defaults', { method: reset ? 'DELETE' : 'POST', body: JSON.stringify( payload ) } )
			.then( function () {
				clearRunControlState( panel, template.id );
				return loadPrompt( panel, true );
			} )
			.then( function () {
				setStatus( panel, reset ? ( strings.defaultsReset || 'Saved defaults reset.' ) : ( strings.defaultsSaved || 'Defaults saved.' ) );
			} )
			.catch( function ( error ) {
				setStatus( panel, error.message, true );
			} )
			.then( function () {
				panel._worldgraphBusy = false;
				updatePrimaryState( panel );
			} );
	}

	function renderRunDefaultActions( panel, templatePanel, template ) {
		var defaults = template && template.run_defaults;
		var targets = defaults && Array.isArray( defaults.targets ) ? defaults.targets.filter( function ( target ) {
			return target && target.editable && [ 'project', 'item' ].indexOf( String( target.scope ) ) !== -1;
		} ) : [];
		if ( ! targets.length ) {
			return;
		}

		var editor = document.createElement( 'div' );
		var help = document.createElement( 'p' );
		editor.className = 'worldgraph-generate-asset__run-default-editor';
		help.className = 'description';
		help.textContent = strings.defaultLayersHelp || 'Template → Project → item → this run. Save only when these values should become reusable defaults.';
		editor.appendChild( help );
		targets.forEach( function ( target ) {
			var actions = document.createElement( 'div' );
			var save = document.createElement( 'button' );
			actions.className = 'worldgraph-generate-asset__run-default-actions';
			save.type = 'button';
			save.className = 'button button-small';
			save.setAttribute( 'data-worldgraph-default-action', '' );
			save.textContent = 'project' === target.scope
				? ( strings.saveProjectDefaults || 'Save current as Project defaults' )
				: ( strings.saveItemDefaults || 'Save current as item defaults' );
			save.addEventListener( 'click', function () {
				persistRunDefaults( panel, templatePanel, target, false );
			} );
			actions.appendChild( save );
			if ( target.has_overrides ) {
				var reset = document.createElement( 'button' );
				reset.type = 'button';
				reset.className = 'button-link';
				reset.setAttribute( 'data-worldgraph-default-action', '' );
				reset.textContent = 'project' === target.scope
					? ( strings.resetProjectDefaults || 'Reset Project defaults' )
					: ( strings.resetItemDefaults || 'Reset item defaults' );
				reset.addEventListener( 'click', function () {
					persistRunDefaults( panel, templatePanel, target, true );
				} );
				actions.appendChild( reset );
			}
			editor.appendChild( actions );
		} );
		templatePanel.appendChild( editor );
	}

	function runControlGroupLabel( group ) {
		var labels = {
			conditioning: strings.conditioningGroup || 'Conditioning',
			sampling: strings.samplingGroup || 'Sampling',
			output: strings.outputGroup || 'Output',
			advanced: strings.advancedGroup || 'Advanced'
		};
		return labels[ group ] || group;
	}

	function renderRunControlTemplate( panel, parent, selection, target ) {
		var template = selection.template;
		var fields = runControlFields( template );
		var state = runControlState( panel, target, template, false );
		var savedValues = state ? state.values : null;
		var templatePanel = document.createElement( 'section' );
		var heading = document.createElement( 'h6' );
		var headingId = nextRunControlId( panel, template.id ) + '-heading';
		var grouped = {};

		templatePanel.className = 'worldgraph-generate-asset__run-control-template';
		templatePanel.dataset.templateId = String( template.id );
		templatePanel.dataset.outputType = selection.type;
		templatePanel._worldgraphRunTemplate = template;
		templatePanel.setAttribute( 'aria-labelledby', headingId );
		heading.id = headingId;
		heading.textContent = ( 'audio' === selection.type
			? ( strings.audioRunControls || 'Audio Template controls' )
			: ( 'video' === selection.type ? ( strings.videoRunControls || 'Video Template controls' ) : ( strings.imageRunControls || 'Image Template controls' ) ) ) + ': ' + String( template.name || template.id );
		templatePanel.appendChild( heading );

		runControlGroups.forEach( function ( group ) {
			grouped[ group ] = [];
		} );
		fields.forEach( function ( field ) {
			var group = runControlGroups.indexOf( String( field.group || '' ) ) === -1 ? 'advanced' : String( field.group );
			grouped[ group ].push( field );
		} );

		runControlGroups.forEach( function ( group ) {
			if ( ! grouped[ group ].length ) {
				return;
			}
			var fieldset = document.createElement( 'fieldset' );
			var legend = document.createElement( 'legend' );
			var grid = document.createElement( 'div' );
			fieldset.className = 'worldgraph-generate-asset__run-control-group worldgraph-generate-asset__run-control-group--' + group;
			legend.textContent = runControlGroupLabel( group );
			grid.className = 'worldgraph-generate-asset__run-control-grid';
			grouped[ group ].forEach( function ( field ) {
				grid.appendChild( createRunControlInput( panel, field, template, savedValues ) );
			} );
			fieldset.appendChild( legend );
			fieldset.appendChild( grid );
			templatePanel.appendChild( fieldset );
		} );

		renderRunDefaultActions( panel, templatePanel, template );
		parent.appendChild( templatePanel );
	}

	function renderRunControls( panel, selections, target ) {
		var controls = panel.querySelector( '.worldgraph-generate-asset__run-controls' );
		var parent = panel.querySelector( '.worldgraph-generate-asset__run-control-panels' );
		var runnable = ( selections || [] ).filter( function ( selection ) {
			return selection.template && runControlFields( selection.template ).length > 0;
		} );

		clearElement( parent );
		panel._worldgraphRunControlsTarget = target || currentTarget( panel );
		runnable.forEach( function ( selection ) {
			renderRunControlTemplate( panel, parent, selection, panel._worldgraphRunControlsTarget );
		} );
		controls.hidden = ! runnable.length;
	}

	function selectedRunControlTemplates( panel ) {
		var info = targetInfo( currentTarget( panel ) );
		var selections = [];
		if ( 'single' === info.kind ) {
			var action = actionForIntent( panel, info.intent );
			var templateId = action ? parseInt( templateSelect( panel, action.type ).value, 10 ) || 0 : 0;
			var template = action && templateId ? templateForId( panel, action.type, templateId ) : null;
			if ( template ) {
				selections.push( { type: action.type, template: template } );
			}
		} else if ( 'workflow' === info.kind ) {
			[ 'image', 'video', 'audio' ].forEach( function ( type ) {
				var templateId = templateContainer( panel, type ).hidden ? 0 : parseInt( templateSelect( panel, type ).value, 10 ) || 0;
				var template = templateId ? templateForId( panel, type, templateId ) : null;
				if ( template ) {
					selections.push( { type: type, template: template } );
				}
			} );
		}
		return selections;
	}

	function renderRunControlsForSelection( panel ) {
		renderRunControls( panel, selectedRunControlTemplates( panel ), currentTarget( panel ) );
	}

	function copyRunControlValues( values ) {
		var copy = {};
		Object.keys( values || {} ).forEach( function ( key ) {
			if ( safeRunControlKey( key ) ) {
				copy[ key ] = values[ key ];
			}
		} );
		return copy;
	}

	function runValuesForTemplate( panel, type, templateId ) {
		var template = templateForId( panel, type, templateId );
		if ( ! template || ! runControlFields( template ).length ) {
			return null;
		}
		rememberRunControls( panel );
		var state = runControlState( panel, currentTarget( panel ), template, false );
		return state ? copyRunControlValues( state.values ) : {};
	}

	function runControlsAreValid( panel ) {
		var valid = true;
		Array.prototype.some.call( panel.querySelectorAll( '.worldgraph-generate-asset__run-controls [data-worldgraph-run-control]' ), function ( input ) {
			if ( ! input.disabled && 'function' === typeof input.checkValidity && ! input.checkValidity() ) {
				valid = false;
				return true;
			}
			return false;
		} );
		return valid;
	}

	function reportRunControlValidity( panel ) {
		var invalid = panel.querySelector( '.worldgraph-generate-asset__run-controls [data-worldgraph-run-control]:invalid' );
		if ( ! invalid ) {
			return true;
		}
		if ( 'function' === typeof invalid.reportValidity ) {
			invalid.reportValidity();
		} else {
			invalid.focus();
		}
		return false;
	}

	function rememberDirectOptions( panel ) {
		var target = panel._worldgraphRenderedTarget || '';
		if ( 0 !== target.indexOf( 'single:' ) ) {
			return;
		}
		panel._worldgraphDirectOptions = panel._worldgraphDirectOptions || {};
		panel._worldgraphDirectOptions[ target ] = {
			featured: panel.querySelector( '.worldgraph-generate-asset__featured' ).checked,
			createAsset: panel.querySelector( '.worldgraph-generate-asset__create' ).checked
		};
	}

	function appendOption( group, value, label ) {
		var option = document.createElement( 'option' );
		option.value = value;
		option.textContent = label;
		group.appendChild( option );
	}

	function buildModes( panel, body, preferredMode ) {
		var actions = panel._worldgraphActions || [];
		var availability = {
			image: actions.some( function ( action ) { return 'image' === action.type; } ),
			sequence: ( parseInt( body.total_jobs, 10 ) || 0 ) > 1 || '1' === panel.dataset.isProject,
			video: actions.some( function ( action ) { return 'video' === action.type; } ),
			audio: actions.some( function ( action ) { return 'audio' === action.type; } ),
			demonstration: '1' === panel.dataset.isProject
		};
		var firstAvailable = '';
		Array.prototype.forEach.call( panel.querySelectorAll( '.worldgraph-generate-asset__modes input' ), function ( input ) {
			var available = !! availability[ input.value ];
			var note = input.closest( '.worldgraph-generate-asset__mode' ).querySelector( 'small' );
			note.dataset.availableText = note.dataset.availableText || note.textContent;
			note.textContent = available ? note.dataset.availableText : strings.notAvailable;
			input.dataset.available = available ? '1' : '0';
			input.disabled = ! available;
			input.closest( '.worldgraph-generate-asset__mode' ).classList.toggle( 'is-unavailable', ! available );
			if ( available && ! firstAvailable ) {
				firstAvailable = input.value;
			}
		} );
		var selectedMode = preferredMode && availability[ preferredMode ] ? preferredMode : firstAvailable;
		if ( selectedMode ) {
			panel.querySelector( '.worldgraph-generate-asset__modes input[value="' + selectedMode + '"]' ).checked = true;
		}
		return selectedMode;
	}

	function buildActionOptions( panel, body, mode, preferredTarget ) {
		var select = panel.querySelector( '.worldgraph-generate-asset__action-select' );
		var label = panel.querySelector( '.worldgraph-generate-asset__selection-label strong' );
		var actions = panel._worldgraphActions || [];
		select.textContent = '';

		if ( 'image' === mode || 'video' === mode || 'audio' === mode ) {
			label.textContent = 'video' === mode ? strings.videoSelection : ( 'audio' === mode ? strings.audioSelection : strings.imageSelection );
			actions.filter( function ( action ) {
				return mode === action.type;
			} ).forEach( function ( action ) {
				appendOption( select, 'single:' + action.intent, action.label );
			} );
		} else if ( 'sequence' === mode ) {
			label.textContent = strings.sequenceSelection;
			if ( ( parseInt( body.total_jobs, 10 ) || 0 ) > 1 ) {
				appendOption( select, 'workflow:item', body.workflow.label + ' (' + body.total_jobs + ' ' + strings.outputs + ')' );
			}
			if ( '1' === panel.dataset.isProject ) {
				appendOption( select, 'workflow:project', strings.allProjectMedia );
			}
		} else if ( 'demonstration' === mode && '1' === panel.dataset.isProject ) {
			label.textContent = strings.demonstrationSelection;
			appendOption( select, 'workflow:demonstration', strings.wholeStoryDemo );
		}

		if ( ! select.options.length ) {
			appendOption( select, '', strings.notAvailable );
			select.disabled = true;
			return;
		}
		select.disabled = false;
		select.value = preferredTarget && selectHasValue( select, preferredTarget ) ? preferredTarget : select.options[0].value;
	}

	function clearElement( element ) {
		while ( element.firstChild ) {
			element.removeChild( element.firstChild );
		}
	}

	function clearResult( panel ) {
		var result = panel.querySelector( '.worldgraph-generate-asset__result' );
		clearElement( result );
		delete result.dataset.resultKind;
		result.hidden = true;
	}

	function renderAssemblyResult( panel, assembly ) {
		var result = panel.querySelector( '.worldgraph-generate-asset__result' );
		clearElement( result );
		var video = document.createElement( 'video' );
		video.controls = true;
		video.preload = 'metadata';
		video.src = assembly.url;
		result.appendChild( video );
		var caption = document.createElement( 'p' );
		caption.className = 'description';
		caption.textContent = strings.roughCutReady;
		result.appendChild( caption );
		result.dataset.resultKind = 'assembly';
		result.hidden = false;
	}

	function renderSingleSummary( panel, action ) {
		var workflow = panel.querySelector( '.worldgraph-generate-asset__workflow' );
		var definition = panel._worldgraphPromptBody.workflow || {};
		clearElement( workflow );
		var strong = document.createElement( 'strong' );
		strong.textContent = action.label + ' — ' + ( 'video' === action.type ? strings.video : strings.stillImage );
		workflow.appendChild( strong );
		if ( definition.description ) {
			var detail = document.createElement( 'span' );
			detail.textContent = ' — ' + definition.description;
			workflow.appendChild( detail );
		}
	}

	function renderPlanSummary( panel, body ) {
		var workflow = panel.querySelector( '.worldgraph-generate-asset__workflow' );
		var counts = body.counts || {};
		clearElement( workflow );
		var strong = document.createElement( 'strong' );
		strong.textContent = 'demonstration' === body.scope ? strings.wholeStoryDemo : ( 'project' === body.scope ? strings.allProjectMedia : ( body.workflow.label || strings.workflow ) );
		workflow.appendChild( strong );
		var detail = document.createElement( 'span' );
		detail.textContent = ' — ' + countedLabel( body.total_jobs, strings.jobSingular, strings.jobs ) + ': ' + countedLabel( counts.image || 0, strings.image, strings.images ) + ', ' + countedLabel( counts.video || 0, strings.video, strings.videos ) + ( ( parseInt( counts.audio, 10 ) || 0 ) > 0 ? ', ' + countedLabel( counts.audio || 0, strings.audio, strings.audios ) : '' ) + ( 'item' !== body.scope ? '; ' + body.sources + ' ' + strings.sources : '' );
		workflow.appendChild( detail );
	}

	function renderPlanPreview( panel, body ) {
		var lines = [ strings.workflowPrompts ];
		var tasks = body.tasks || [];
		tasks.slice( 0, 24 ).forEach( function ( task ) {
			var source = 'item' !== body.scope ? task.source_title + ' — ' : '';
			var typeLabel = 'video' === task.type ? strings.video : ( 'audio' === task.type ? strings.audio : strings.stillImage );
			lines.push( '• ' + source + task.label + ' (' + typeLabel + ( task.optional ? '; optional fallback available' : '' ) + ')' );
		} );
		if ( tasks.length > 24 ) {
			lines.push( '… ' + ( tasks.length - 24 ) + ' ' + strings.moreOutputs );
		}
		panel.querySelector( '.worldgraph-generate-asset__context-preview' ).textContent = lines.join( '\n' );
	}

	function activeBatch( panel ) {
		if ( panel._worldgraphActiveBatch && ! isTerminal( panel._worldgraphActiveBatch.status ) ) {
			return panel._worldgraphActiveBatch;
		}
		if ( panel._worldgraphKnownBatches && panel._worldgraphKnownBatches.length ) {
			return panel._worldgraphKnownBatches[0];
		}
		return panel._worldgraphBatchTransition ? { status: 'checking' } : null;
	}

	function rememberBatch( panel, body ) {
		if ( ! body || ! body.batch_id || isTerminal( body.status ) ) {
			return;
		}
		panel._worldgraphKnownBatches = panel._worldgraphKnownBatches || [];
		panel._worldgraphKnownBatches = panel._worldgraphKnownBatches.filter( function ( batch ) {
			return String( batch.batch_id ) !== String( body.batch_id );
		} );
		panel._worldgraphKnownBatches.push( body );
		panel._worldgraphKnownBatches.sort( function ( left, right ) {
			return parseInt( right.batch_id, 10 ) - parseInt( left.batch_id, 10 );
		} );
	}

	function forgetBatch( panel, batchId ) {
		panel._worldgraphKnownBatches = ( panel._worldgraphKnownBatches || [] ).filter( function ( batch ) {
			return String( batch.batch_id ) !== String( batchId );
		} );
	}

	function updatePrimaryState( panel ) {
		var button = panel.querySelector( '.worldgraph-generate-asset__run' );
		var select = panel.querySelector( '.worldgraph-generate-asset__action-select' );
		var info = targetInfo( currentTarget( panel ) );
		var enabled = ! panel._worldgraphLoading && ! panel._worldgraphBusy;

		var controlsLocked = !! panel._worldgraphLoading || !! panel._worldgraphBusy;
		select.disabled = controlsLocked || ! select.options.length;
		Array.prototype.forEach.call( panel.querySelectorAll( '.worldgraph-generate-asset__modes input' ), function ( input ) {
			input.disabled = controlsLocked || '1' !== input.dataset.available;
		} );
		[ 'image', 'video', 'audio' ].forEach( function ( type ) {
			var template = templateSelect( panel, type );
			template.disabled = controlsLocked || templateContainer( panel, type ).hidden || ! selectHasEnabledOption( template );
		} );
		panel.querySelector( '.worldgraph-generate-asset__prompt' ).disabled = controlsLocked;
		panel.querySelector( '.worldgraph-generate-asset__refresh-context' ).disabled = controlsLocked;
		Array.prototype.forEach.call( panel.querySelectorAll( '.worldgraph-generate-asset__run-controls [data-worldgraph-run-control]' ), function ( input ) {
			input.disabled = controlsLocked;
		} );
		Array.prototype.forEach.call( panel.querySelectorAll( '[data-worldgraph-default-action]' ), function ( input ) {
			input.disabled = controlsLocked;
		} );
		var directOptions = panel.querySelector( '.worldgraph-generate-asset__direct-options' );
		panel.querySelector( '.worldgraph-generate-asset__create' ).disabled = controlsLocked || directOptions.hidden;
		var action = 'single' === info.kind ? actionForIntent( panel, info.intent ) : null;
		panel.querySelector( '.worldgraph-generate-asset__featured' ).disabled = controlsLocked || directOptions.hidden || ! action || 'image' !== action.type;
		if ( 'single' === info.kind ) {
			action = actionForIntent( panel, info.intent );
			enabled = enabled && !! action && parseInt( templateSelect( panel, action ? action.type : 'image' ).value, 10 ) > 0;
		} else if ( 'workflow' === info.kind ) {
			enabled = enabled && ! activeBatch( panel ) && panel._worldgraphDisplayedPlanScope === info.scope && panel._worldgraphDisplayedPlan && !! panel._worldgraphDisplayedPlan.ready;
		} else {
			enabled = false;
		}
		enabled = enabled && runControlsAreValid( panel );
		button.disabled = ! enabled;
	}

	function selectionStatus( panel, message, isError ) {
		if ( ! activeBatch( panel ) ) {
			setStatus( panel, message, isError );
		}
	}

	function refreshSinglePromptPreview( panel ) {
		var info = targetInfo( currentTarget( panel ) );
		var action = 'single' === info.kind ? actionForIntent( panel, info.intent ) : null;
		var templateId = action ? parseInt( templateSelect( panel, action.type ).value, 10 ) || 0 : 0;
		if ( ! action || ! templateId ) {
			return;
		}

		var token = ( panel._worldgraphPreviewToken || 0 ) + 1;
		var target = currentTarget( panel );
		var preview = panel.querySelector( '.worldgraph-generate-asset__context-preview' );
		panel._worldgraphPreviewToken = token;
		preview.textContent = strings.previewingPrompt || 'Composing the selected Template prompt…';
		request( settings.restUrl + '/prompt-preview', {
			method: 'POST',
			body: JSON.stringify( {
				post_id: parseInt( panel.dataset.postId, 10 ),
				type: action.type,
				intent: action.intent,
				template_id: templateId,
				prompt: panel.querySelector( '.worldgraph-generate-asset__prompt' ).value.trim()
			} )
		} ).then( function ( body ) {
			if ( token !== panel._worldgraphPreviewToken || target !== currentTarget( panel ) || templateId !== ( parseInt( templateSelect( panel, action.type ).value, 10 ) || 0 ) ) {
				return;
			}
			preview.textContent = body.prompt || '';
			if ( body.prompt_policy ) {
				preview.dataset.promptWords = String( body.prompt_policy.word_count || 0 );
				preview.dataset.promptProfile = String( body.prompt_policy.profile || '' );
			}
		} ).catch( function ( error ) {
			if ( token === panel._worldgraphPreviewToken && target === currentTarget( panel ) ) {
				preview.textContent = ( strings.promptPreviewError || 'The selected Template prompt could not be previewed.' ) + ' ' + error.message;
			}
		} );
	}

	function scheduleSinglePromptPreview( panel ) {
		if ( panel._worldgraphPreviewTimer ) {
			window.clearTimeout( panel._worldgraphPreviewTimer );
		}
		panel._worldgraphPreviewTimer = window.setTimeout( function () {
			panel._worldgraphPreviewTimer = null;
			refreshSinglePromptPreview( panel );
		}, 300 );
	}

	function renderSingle( panel, action, target ) {
		var type = action.type;
		var templates = ( panel._worldgraphTemplates || {} )[ type ] || [];
		var select = templateSelect( panel, type );
		var savedOptions = ( panel._worldgraphDirectOptions || {} )[ target ];
		panel._worldgraphDisplayedPlan = null;
		panel._worldgraphDisplayedPlanScope = '';

		setTemplateVisibility( panel, 'image', 'image' === type, strings.singleTemplateHelp );
		setTemplateVisibility( panel, 'video', 'video' === type, strings.singleTemplateHelp );
		setTemplateVisibility( panel, 'audio', 'audio' === type, strings.singleTemplateHelp );
		fillTemplateSelect( select, templates, action.default_template_id || 0, false, savedTemplate( panel, target, type ), type );
		renderRunControlsForSelection( panel );

		var directOptions = panel.querySelector( '.worldgraph-generate-asset__direct-options' );
		directOptions.hidden = false;
		var featuredOption = panel.querySelector( '.worldgraph-generate-asset__featured-option' );
		featuredOption.hidden = 'image' !== type;
		panel.querySelector( '.worldgraph-generate-asset__featured' ).disabled = 'image' !== type;
		panel.querySelector( '.worldgraph-generate-asset__featured' ).checked = savedOptions ? savedOptions.featured : !! action.featured;
		panel.querySelector( '.worldgraph-generate-asset__create' ).checked = savedOptions ? savedOptions.createAsset : true;
		panel.querySelector( '.worldgraph-generate-asset__prompt-help' ).textContent = strings.singlePromptHelp;
		panel.querySelector( '.worldgraph-generate-asset__choice-description' ).textContent = strings.singleChoiceHelp;
		panel.querySelector( '.worldgraph-generate-asset__context-preview' ).textContent = action.prompt || '';
		panel.querySelector( '.worldgraph-generate-asset__run' ).textContent = ( 'video' === type ? strings.createVideo : ( 'audio' === type ? strings.createAudio : strings.createImage ) ) + ' ' + action.label;
		renderSingleSummary( panel, action );
		selectionStatus( panel, action.configured ? '' : ( 'video' === type ? strings.unconfiguredVideo : ( 'audio' === type ? strings.unconfiguredAudio : strings.unconfiguredImage ) ), ! action.configured );
		updatePrimaryState( panel );
		scheduleSinglePromptPreview( panel );
	}

	function renderPlanLoading( panel, scope ) {
		panel._worldgraphDisplayedPlan = null;
		panel._worldgraphDisplayedPlanScope = '';
		[ 'image', 'video', 'audio' ].forEach( function ( type ) {
			var select = templateSelect( panel, type );
			select.textContent = '';
			var loading = document.createElement( 'option' );
			loading.value = '';
			loading.textContent = strings.planning;
			loading.disabled = true;
			select.appendChild( loading );
		} );
		setTemplateVisibility( panel, 'image', false, '' );
		setTemplateVisibility( panel, 'video', false, '' );
		setTemplateVisibility( panel, 'audio', false, '' );
		renderRunControls( panel, [], currentTarget( panel ) );
		panel.querySelector( '.worldgraph-generate-asset__direct-options' ).hidden = true;
		panel.querySelector( '.worldgraph-generate-asset__prompt-help' ).textContent = strings.batchPromptHelp;
		panel.querySelector( '.worldgraph-generate-asset__choice-description' ).textContent = 'demonstration' === scope ? strings.demoChoiceHelp : ( 'project' === scope ? strings.projectChoiceHelp : strings.itemChoiceHelp );
		panel.querySelector( '.worldgraph-generate-asset__context-preview' ).textContent = strings.planning;
		panel.querySelector( '.worldgraph-generate-asset__run' ).textContent = 'demonstration' === scope ? strings.reviewDemonstration : ( 'project' === scope ? strings.reviewProject : strings.reviewQueue );
		selectionStatus( panel, strings.planning );
		updatePrimaryState( panel );
	}

	function renderPlan( panel, body, target ) {
		var counts = body.counts || {};
		var defaults = body.default_template_ids || {};
		var imageVisible = ( parseInt( counts.image, 10 ) || 0 ) > 0;
		var videoVisible = ( parseInt( counts.video, 10 ) || 0 ) > 0;
		var audioVisible = 'demonstration' === body.scope && ( body.tasks || [] ).some( function ( task ) {
			return 'audio' === task.type && false !== task.generation_required;
		} );
		panel._worldgraphDisplayedPlan = body;
		panel._worldgraphDisplayedPlanScope = body.scope;

		setTemplateVisibility( panel, 'image', imageVisible, strings.batchTemplateHelp );
		setTemplateVisibility( panel, 'video', videoVisible, strings.batchTemplateHelp );
		setTemplateVisibility( panel, 'audio', audioVisible, strings.batchTemplateHelp );
		if ( imageVisible ) {
			fillTemplateSelect( templateSelect( panel, 'image' ), body.image_templates || [], defaults.image || 0, !! body.ready, savedTemplate( panel, target, 'image' ), 'image' );
		}
		if ( videoVisible ) {
			fillTemplateSelect( templateSelect( panel, 'video' ), body.video_templates || [], defaults.video || 0, !! body.ready, savedTemplate( panel, target, 'video' ), 'video' );
		}
		if ( audioVisible ) {
			fillTemplateSelect( templateSelect( panel, 'audio' ), body.audio_templates || [], defaults.audio || 0, !! body.ready, savedTemplate( panel, target, 'audio' ), 'audio' );
		}
		renderRunControlsForSelection( panel );

		panel.querySelector( '.worldgraph-generate-asset__direct-options' ).hidden = true;
		panel.querySelector( '.worldgraph-generate-asset__prompt-help' ).textContent = strings.batchPromptHelp;
		panel.querySelector( '.worldgraph-generate-asset__choice-description' ).textContent = 'demonstration' === body.scope ? strings.demoChoiceHelp : ( 'project' === body.scope ? strings.projectChoiceHelp : strings.itemChoiceHelp );
		panel.querySelector( '.worldgraph-generate-asset__run' ).textContent = 'demonstration' === body.scope
			? strings.reviewDemonstration + ' (' + body.total_jobs + ' ' + strings.jobs + ')'
			: ( 'project' === body.scope
				? strings.reviewProject + ' (' + body.total_jobs + ' ' + strings.jobs + ')'
				: strings.reviewQueue + ' ' + body.workflow.label + ' (' + body.total_jobs + ' ' + strings.jobs + ')' );
		renderPlanSummary( panel, body );
		renderPlanPreview( panel, body );
		if ( body.ready ) {
			selectionStatus( panel, '' );
		} else {
			selectionStatus( panel, ( body.blockers || [] ).length + ' ' + strings.missingTemplates, true );
		}
		updatePrimaryState( panel );
	}

	function ensurePlan( panel, scope, force ) {
		panel._worldgraphPlanCache = panel._worldgraphPlanCache || {};
		panel._worldgraphPlanRequests = panel._worldgraphPlanRequests || {};
		if ( force ) {
			delete panel._worldgraphPlanCache[ scope ];
			delete panel._worldgraphPlanRequests[ scope ];
		}
		if ( panel._worldgraphPlanCache[ scope ] ) {
			return Promise.resolve( panel._worldgraphPlanCache[ scope ] );
		}
		if ( panel._worldgraphPlanRequests[ scope ] ) {
			return panel._worldgraphPlanRequests[ scope ];
		}

		var epoch = panel._worldgraphPlanEpoch || 0;
		var planRequest = request( settings.restUrl + '/plan?post_id=' + encodeURIComponent( panel.dataset.postId ) + '&scope=' + encodeURIComponent( scope ) )
			.then( function ( body ) {
				if ( epoch === panel._worldgraphPlanEpoch ) {
					panel._worldgraphPlanCache[ scope ] = body;
				}
				if ( panel._worldgraphPlanRequests[ scope ] === planRequest ) {
					delete panel._worldgraphPlanRequests[ scope ];
				}
				return body;
			} )
			.catch( function ( error ) {
				if ( panel._worldgraphPlanRequests[ scope ] === planRequest ) {
					delete panel._worldgraphPlanRequests[ scope ];
				}
				throw error;
			} );
		panel._worldgraphPlanRequests[ scope ] = planRequest;
		return planRequest;
	}

	function renderTarget( panel ) {
		rememberRunControls( panel );
		rememberDirectOptions( panel );
		panel._worldgraphPreviewToken = ( panel._worldgraphPreviewToken || 0 ) + 1;
		var target = currentTarget( panel );
		var info = targetInfo( target );
		var token = ( panel._worldgraphSelectionToken || 0 ) + 1;
		panel._worldgraphSelectionToken = token;
		panel._worldgraphRenderedTarget = target;

		if ( 'single' === info.kind ) {
			var action = actionForIntent( panel, info.intent );
			if ( action ) {
				renderSingle( panel, action, target );
			} else {
				renderRunControls( panel, [], target );
				updatePrimaryState( panel );
			}
			return;
		}

		if ( 'workflow' === info.kind ) {
			renderPlanLoading( panel, info.scope );
			ensurePlan( panel, info.scope, false )
				.then( function ( body ) {
					if ( token !== panel._worldgraphSelectionToken || target !== currentTarget( panel ) ) {
						return;
					}
					renderPlan( panel, body, target );
				} )
				.catch( function ( error ) {
					if ( token === panel._worldgraphSelectionToken && target === currentTarget( panel ) ) {
						setStatus( panel, error.message, true );
						updatePrimaryState( panel );
					}
				} );
			return;
		}

		renderRunControls( panel, [], target );
		updatePrimaryState( panel );
	}

	function legacyActions( body ) {
		var actions = [];
		Object.keys( body.outputs || {} ).forEach( function ( type ) {
			actions.push( body.outputs[ type ] );
		} );
		if ( ! actions.length && body.intent ) {
			actions.push( {
				type: 'image',
				intent: body.intent,
				label: body.workflow && body.workflow.label ? body.workflow.label : strings.stillImage,
				prompt: body.prompt || '',
				configured: !! body.configured,
				default_template_id: body.default_template_id || 0
			} );
		}
		return actions;
	}

	function activeBatchesFromPrompt( body ) {
		return [ body.latest_batch, body.latest_project_batch, body.latest_demonstration_batch ].filter( function ( batch ) {
			return batch && batch.batch_id && ! isTerminal( batch.status );
		} ).sort( function ( left, right ) {
			return parseInt( right.batch_id, 10 ) - parseInt( left.batch_id, 10 );
		} );
	}

	function loadPrompt( panel, force ) {
		if ( ! force && panel._worldgraphPromptBody ) {
			return Promise.resolve( panel._worldgraphPromptBody );
		}

		var preferredMode = currentMode( panel );
		var preferredTarget = currentTarget( panel );
		panel._worldgraphLoading = true;
		panel._worldgraphSelectionToken = ( panel._worldgraphSelectionToken || 0 ) + 1;
		panel.querySelector( '.worldgraph-generate-asset__refresh-context' ).disabled = true;
		setStatus( panel, strings.loading );
		updatePrimaryState( panel );

		return request( settings.restUrl + '/prompt?post_id=' + encodeURIComponent( panel.dataset.postId ) )
			.then( function ( body ) {
				panel._worldgraphPromptBody = body;
				panel._worldgraphActions = body.actions || legacyActions( body );
				panel._worldgraphTemplates = {
					image: body.image_templates || body.templates || [],
					video: body.video_templates || [],
					audio: body.audio_templates || []
				};
				panel._worldgraphPlanEpoch = ( panel._worldgraphPlanEpoch || 0 ) + 1;
				panel._worldgraphPlanCache = {};
				panel._worldgraphPlanRequests = {};
				var selectedMode = buildModes( panel, body, preferredMode );
				buildActionOptions( panel, body, selectedMode, selectedMode === preferredMode ? preferredTarget : '' );
				panel._worldgraphRenderedMode = selectedMode;
				if ( panel._worldgraphPollTimer ) {
					window.clearTimeout( panel._worldgraphPollTimer );
				}
				panel._worldgraphWatchToken = ( panel._worldgraphWatchToken || 0 ) + 1;
				panel._worldgraphActiveBatch = null;
				panel._worldgraphKnownBatches = activeBatchesFromPrompt( body );
				delete panel.dataset.batchId;
				panel.querySelector( '.worldgraph-generate-asset__cancel' ).hidden = true;
				panel._worldgraphLoading = false;
				panel.querySelector( '.worldgraph-generate-asset__refresh-context' ).disabled = false;
				renderTarget( panel );
				if ( panel._worldgraphKnownBatches.length ) {
					watchBatch( panel, panel._worldgraphKnownBatches[0] );
				} else if ( body.latest_demonstration_batch && isTerminal( body.latest_demonstration_batch.status ) && body.latest_demonstration_batch.assembly && body.latest_demonstration_batch.assembly.url ) {
					if ( 'direct' !== panel.querySelector( '.worldgraph-generate-asset__result' ).dataset.resultKind ) {
						renderAssemblyResult( panel, body.latest_demonstration_batch.assembly );
					}
				} else if ( 'direct' !== panel.querySelector( '.worldgraph-generate-asset__result' ).dataset.resultKind ) {
					clearResult( panel );
				}
				return body;
			} )
			.catch( function ( error ) {
				panel._worldgraphLoading = false;
				panel.querySelector( '.worldgraph-generate-asset__refresh-context' ).disabled = false;
				setStatus( panel, error.message, true );
				updatePrimaryState( panel );
				throw error;
			} );
	}

	function renderResult( panel, body, type ) {
		var result = panel.querySelector( '.worldgraph-generate-asset__result' );
		var messages = [ 'video' === type ? strings.doneVideo : ( 'audio' === type ? strings.doneAudio : strings.done ) ];
		if ( body.featured ) {
			messages.push( strings.featured );
		}
		if ( body.asset_id ) {
			messages.push( strings.assetCreated );
		}
		messages.push( strings.reloadHint );
		clearElement( result );
		if ( body.thumbnail_url || body.url ) {
			var media;
			if ( 'video' === type ) {
				media = document.createElement( 'video' );
				media.controls = true;
				media.src = body.url;
			} else if ( 'audio' === type ) {
				media = document.createElement( 'audio' );
				media.controls = true;
				media.src = body.url;
			} else {
				media = document.createElement( 'img' );
				media.src = body.thumbnail_url || body.url;
				media.alt = '';
			}
			media.width = 150;
			result.appendChild( media );
		}
		var caption = document.createElement( 'p' );
		caption.className = 'description';
		caption.textContent = messages.join( ' ' );
		result.appendChild( caption );
		result.dataset.resultKind = 'direct';
		result.hidden = false;
	}

	function generateSingle( panel, action ) {
		clearSinglePoll( panel );
		var templateId = parseInt( templateSelect( panel, action.type ).value, 10 ) || 0;
		if ( ! templateId ) {
			setStatus( panel, 'video' === action.type ? strings.unconfiguredVideo : ( 'audio' === action.type ? strings.unconfiguredAudio : strings.unconfiguredImage ), true );
			return;
		}

		var payload = {
			post_id: parseInt( panel.dataset.postId, 10 ),
			type: action.type,
			intent: action.intent,
			prompt: panel.querySelector( '.worldgraph-generate-asset__prompt' ).value.trim(),
			set_featured: 'image' === action.type && panel.querySelector( '.worldgraph-generate-asset__featured' ).checked,
			create_asset: panel.querySelector( '.worldgraph-generate-asset__create' ).checked,
			template_id: templateId
		};
		var runValues = runValuesForTemplate( panel, action.type, templateId );
		if ( null !== runValues ) {
			payload.run_values = runValues;
		}
		clearResult( panel );
		panel._worldgraphBusy = true;
		updatePrimaryState( panel );
		setStatus( panel, 'video' === action.type ? strings.generatingVideo : ( 'audio' === action.type ? strings.generatingAudio : strings.generatingImage ) );
		request( settings.restUrl, { method: 'POST', body: JSON.stringify( payload ) } )
			.then( function ( body ) {
				if ( 'queued' === body.status ) {
					var queued = 'video' === action.type ? strings.queuedVideo : ( 'audio' === action.type ? strings.queuedAudio : strings.queuedImage );
					setStatus( panel, queued + ( body.generation_id ? ' (' + strings.job + ' #' + body.generation_id + ')' : '' ) );
					if ( body.generation_id ) {
						watchSingleJob( panel, body.generation_id, action.type );
					}
					return;
				}
				clearSinglePoll( panel );
				setStatus( panel, '' );
				renderResult( panel, body, action.type );
			} )
			.catch( function ( error ) {
				setStatus( panel, error.message, true );
			} )
			.then( function () {
				panel._worldgraphBusy = false;
				updatePrimaryState( panel );
			} );
	}

	function startBatch( panel, scope ) {
		var body = panel._worldgraphPlanCache && panel._worldgraphPlanCache[ scope ];
		if ( ! body || ! body.ready || activeBatch( panel ) ) {
			updatePrimaryState( panel );
			return;
		}
		var summary = countedLabel( body.total_jobs, strings.jobSingular, strings.jobs ) + ' (' + countedLabel( body.counts.image || 0, strings.image, strings.images ) + ', ' + countedLabel( body.counts.video || 0, strings.video, strings.videos ) + ( ( parseInt( body.counts.audio, 10 ) || 0 ) > 0 ? ', ' + countedLabel( body.counts.audio || 0, strings.audio, strings.audios ) : '' ) + ').\n\n';
		var confirmation = 'demonstration' === scope ? strings.confirmDemonstration : ( 'project' === scope ? strings.confirmProject : strings.confirmItem );
		if ( ! window.confirm( summary + confirmation ) ) {
			return;
		}

		var idempotencyProperty = 'demonstration' === scope ? '_worldgraphDemonstrationBatchKey' : ( 'project' === scope ? '_worldgraphProjectBatchKey' : '_worldgraphItemBatchKey' );
		panel[ idempotencyProperty ] = panel[ idempotencyProperty ] || uuid();
		var payload = {
			post_id: parseInt( panel.dataset.postId, 10 ),
			scope: scope,
			base_prompt: panel.querySelector( '.worldgraph-generate-asset__prompt' ).value.trim(),
			image_template_id: ( parseInt( body.counts.image, 10 ) || 0 ) > 0 ? parseInt( templateSelect( panel, 'image' ).value, 10 ) || 0 : 0,
			video_template_id: ( parseInt( body.counts.video, 10 ) || 0 ) > 0 ? parseInt( templateSelect( panel, 'video' ).value, 10 ) || 0 : 0,
			audio_template_id: 'demonstration' === scope && ! templateContainer( panel, 'audio' ).hidden ? parseInt( templateSelect( panel, 'audio' ).value, 10 ) || 0 : 0,
			idempotency_key: panel[ idempotencyProperty ]
		};
		var imageRunValues = payload.image_template_id > 0 ? runValuesForTemplate( panel, 'image', payload.image_template_id ) : null;
		var videoRunValues = payload.video_template_id > 0 ? runValuesForTemplate( panel, 'video', payload.video_template_id ) : null;
		var audioRunValues = payload.audio_template_id > 0 ? runValuesForTemplate( panel, 'audio', payload.audio_template_id ) : null;
		if ( null !== imageRunValues ) {
			payload.image_run_values = imageRunValues;
		}
		if ( null !== videoRunValues ) {
			payload.video_run_values = videoRunValues;
		}
		if ( null !== audioRunValues ) {
			payload.audio_run_values = audioRunValues;
		}
		clearResult( panel );
		panel._worldgraphBusy = true;
		updatePrimaryState( panel );
		setStatus( panel, strings.starting );
		request( settings.restUrl + '/batches', { method: 'POST', body: JSON.stringify( payload ) } )
			.then( function ( response ) {
				panel[ idempotencyProperty ] = '';
				setStatus( panel, strings.batchQueued + ' #' + response.batch_id );
				watchBatch( panel, response );
			} )
			.catch( function ( error ) {
				setStatus( panel, error.message, true );
			} )
			.then( function () {
				panel._worldgraphBusy = false;
				updatePrimaryState( panel );
			} );
	}

	function runSelection( panel ) {
		if ( ! reportRunControlValidity( panel ) ) {
			return;
		}
		var info = targetInfo( currentTarget( panel ) );
		if ( 'single' === info.kind ) {
			var action = actionForIntent( panel, info.intent );
			if ( action ) {
				generateSingle( panel, action );
			}
		} else if ( 'workflow' === info.kind ) {
			startBatch( panel, info.scope );
		}
	}

	function renderBatch( panel, body ) {
		var progress = panel.querySelector( '.worldgraph-generate-asset__progress' );
		var progressBar = progress.querySelector( 'progress' );
		var label = progress.querySelector( 'span' );
		var percent = parseInt( body.progress_percent, 10 ) || 0;
		var terminal = isTerminal( body.status );
		progress.hidden = false;
		progressBar.value = percent;
		label.textContent = strings.batchProgress + ': ' + percent + '% — ' + ( body.completed || 0 ) + '/' + ( body.total || 0 ) + ' completed, ' + ( body.active || 0 ) + ' active, ' + ( body.skipped || 0 ) + ' fallback, ' + ( body.failed || 0 ) + ' failed, ' + ( body.cancelled || 0 ) + ' cancelled.';
		if ( body.assembly && 'pending' === body.assembly.status ) {
			var assemblyPercent = parseInt( body.assembly.progress_percent, 10 );
			if ( Number.isFinite( assemblyPercent ) ) {
				label.textContent += ' ' + strings.roughCutProgress + ': ' + assemblyPercent + '%.';
			}
		}
		var statusText = strings.batchQueued + ' #' + body.batch_id + ' — ' + body.status;
		if ( body.error ) {
			statusText += ': ' + body.error;
		} else if ( body.assembly && 'pending' === body.assembly.status && body.assembly.message ) {
			statusText += ' — ' + body.assembly.message;
		}
		setStatus( panel, statusText, 'failed' === body.status || 'completed_with_errors' === body.status );
		if ( body.assembly && body.assembly.url ) {
			renderAssemblyResult( panel, body.assembly );
		} else {
			clearResult( panel );
		}
		panel.querySelector( '.worldgraph-generate-asset__cancel' ).hidden = terminal || 'cancelling' === body.status;
		if ( terminal ) {
			forgetBatch( panel, body.batch_id );
			panel._worldgraphActiveBatch = null;
			delete panel.dataset.batchId;
		} else {
			rememberBatch( panel, body );
			panel._worldgraphActiveBatch = body;
			panel.dataset.batchId = body.batch_id;
		}
		updatePrimaryState( panel );
	}

	function resumeKnownBatch( panel ) {
		var next = ( panel._worldgraphKnownBatches || [] )[0];
		if ( ! next || panel._worldgraphBatchTransition ) {
			updatePrimaryState( panel );
			return;
		}
		panel._worldgraphBatchTransition = true;
		updatePrimaryState( panel );
		request( settings.restUrl + '/batches/' + encodeURIComponent( next.batch_id ) )
			.then( function ( body ) {
				panel._worldgraphBatchTransition = false;
				if ( isTerminal( body.status ) ) {
					forgetBatch( panel, body.batch_id );
					resumeKnownBatch( panel );
				} else {
					watchBatch( panel, body );
				}
			} )
			.catch( function ( error ) {
				panel._worldgraphBatchTransition = false;
				setStatus( panel, error.message, true );
				updatePrimaryState( panel );
				panel._worldgraphPollTimer = window.setTimeout( function () {
					resumeKnownBatch( panel );
				}, Math.max( 30000, settings.pollIntervalMs || 15000 ) );
			} );
	}

	function watchBatch( panel, initial ) {
		if ( panel._worldgraphPollTimer ) {
			window.clearTimeout( panel._worldgraphPollTimer );
		}
		var watchToken = ( panel._worldgraphWatchToken || 0 ) + 1;
		panel._worldgraphWatchToken = watchToken;
		rememberBatch( panel, initial );
		renderBatch( panel, initial );
		if ( isTerminal( initial.status ) ) {
			window.setTimeout( function () {
				resumeKnownBatch( panel );
			}, 0 );
			return;
		}

		panel._worldgraphPollTimer = window.setTimeout( function poll() {
			if ( watchToken !== panel._worldgraphWatchToken || ! panel.dataset.batchId ) {
				return;
			}
			request( settings.restUrl + '/batches/' + encodeURIComponent( panel.dataset.batchId ) )
				.then( function ( body ) {
					if ( watchToken !== panel._worldgraphWatchToken ) {
						return;
					}
					renderBatch( panel, body );
					if ( ! isTerminal( body.status ) ) {
						panel._worldgraphPollTimer = window.setTimeout( poll, settings.pollIntervalMs || 15000 );
					} else {
						resumeKnownBatch( panel );
					}
				} )
				.catch( function ( error ) {
					setStatus( panel, error.message, true );
					panel._worldgraphPollTimer = window.setTimeout( poll, Math.max( 30000, settings.pollIntervalMs || 15000 ) );
				} );
		}, settings.pollIntervalMs || 15000 );
	}

	function cancelBatch( panel ) {
		if ( ! panel.dataset.batchId || panel._worldgraphBusy || ! window.confirm( strings.cancelBatch ) ) {
			return;
		}
		panel._worldgraphBusy = true;
		updatePrimaryState( panel );
		request( settings.restUrl + '/batches/' + encodeURIComponent( panel.dataset.batchId ) + '/cancel', { method: 'POST', body: '{}' } )
			.then( function ( body ) {
				setStatus( panel, strings.cancelled );
				watchBatch( panel, body );
			} )
			.catch( function ( error ) {
				setStatus( panel, error.message, true );
			} )
			.then( function () {
				panel._worldgraphBusy = false;
				updatePrimaryState( panel );
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.forEach.call( document.querySelectorAll( '.worldgraph-generate-asset' ), function ( panel ) {
			panel._worldgraphTemplateSelections = {};
			panel._worldgraphModeTargets = {};
			panel._worldgraphRunValues = {};
			Array.prototype.forEach.call( panel.querySelectorAll( '.worldgraph-generate-asset__modes input' ), function ( input ) {
				input.addEventListener( 'change', function () {
					if ( ! input.checked || ! panel._worldgraphPromptBody ) {
						return;
					}
					if ( panel._worldgraphRenderedMode ) {
						panel._worldgraphModeTargets[ panel._worldgraphRenderedMode ] = currentTarget( panel );
					}
					panel._worldgraphRenderedMode = input.value;
					buildActionOptions( panel, panel._worldgraphPromptBody, input.value, panel._worldgraphModeTargets[ input.value ] || '' );
					renderTarget( panel );
				} );
			} );
			panel.querySelector( '.worldgraph-generate-asset__action-select' ).addEventListener( 'change', function () {
				panel._worldgraphModeTargets[ currentMode( panel ) ] = currentTarget( panel );
				renderTarget( panel );
			} );
			panel.querySelector( '.worldgraph-generate-asset__template' ).addEventListener( 'change', function () {
				rememberRunControls( panel );
				rememberTemplateSelection( panel, 'image' );
				renderRunControlsForSelection( panel );
				scheduleSinglePromptPreview( panel );
				updatePrimaryState( panel );
			} );
			panel.querySelector( '.worldgraph-generate-asset__video-template' ).addEventListener( 'change', function () {
				rememberRunControls( panel );
				rememberTemplateSelection( panel, 'video' );
				renderRunControlsForSelection( panel );
				scheduleSinglePromptPreview( panel );
				updatePrimaryState( panel );
			} );
			panel.querySelector( '.worldgraph-generate-asset__audio-template' ).addEventListener( 'change', function () {
				rememberRunControls( panel );
				rememberTemplateSelection( panel, 'audio' );
				renderRunControlsForSelection( panel );
				scheduleSinglePromptPreview( panel );
				updatePrimaryState( panel );
			} );
			panel.querySelector( '.worldgraph-generate-asset__prompt' ).addEventListener( 'input', function () {
				if ( 'single' === targetInfo( currentTarget( panel ) ).kind ) {
					scheduleSinglePromptPreview( panel );
				}
			} );
			panel.querySelector( '.worldgraph-generate-asset__run-control-panels' ).addEventListener( 'input', function () {
				rememberRunControls( panel );
				updatePrimaryState( panel );
			} );
			panel.querySelector( '.worldgraph-generate-asset__run-control-panels' ).addEventListener( 'change', function () {
				rememberRunControls( panel );
				updatePrimaryState( panel );
			} );
			panel.querySelector( '.worldgraph-generate-asset__refresh-context' ).addEventListener( 'click', function () {
				loadPrompt( panel, true ).catch( function () {} );
			} );
			panel.querySelector( '.worldgraph-generate-asset__run' ).addEventListener( 'click', function () {
				runSelection( panel );
			} );
			panel.querySelector( '.worldgraph-generate-asset__cancel' ).addEventListener( 'click', function () {
				cancelBatch( panel );
			} );
			loadPrompt( panel, false ).catch( function () {} );
		} );
	} );
}() );
