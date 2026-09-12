/**
 * World Graph Studio connection setup form controller.
 */
( function () {
	'use strict';

	const config = window.worldgraphSetupWizard;

	if (
		! config ||
		! config.ajaxUrl ||
		! config.actions ||
		! config.nonces ||
		! config.i18n
	) {
		return;
	}

	const generationMode = document.getElementById( 'worldgraph_gen_connection_mode' );
	const generationCredentialFields = document.getElementById(
		'worldgraph-generation-credential-fields'
	);
	const generationMcpCredentialFields = document.getElementById(
		'worldgraph-generation-mcp-credential-fields'
	);
	const localApiFields = document.getElementById( 'worldgraph-comfy-local-api-fields' );
	const localMcpFields = document.getElementById( 'worldgraph-comfy-local-mcp-fields' );
	const generationTestButton = document.getElementById( 'worldgraph-test-comfy-connection' );

	/**
	 * Safely toggle one optional form section.
	 *
	 * @param {HTMLElement|null} element Form section.
	 * @param {boolean}          hidden  Whether to hide the section.
	 */
	function setHidden( element, hidden ) {
		if ( element ) {
			element.hidden = hidden;
		}
	}

	/** Update provider-specific fields for the selected generation mode. */
	function updateGenerationFields() {
		const mode = generationMode ? generationMode.value : 'none';

		setHidden( generationCredentialFields, 'none' === mode || 'local_mcp' === mode );
		setHidden( generationMcpCredentialFields, 'suno' !== mode );
		setHidden( localApiFields, 'local_mcp' !== mode );
		setHidden( localMcpFields, 'local_mcp' !== mode );
		setHidden( generationTestButton, 'none' === mode || 'cloud' === mode );
	}

	/**
	 * Post one setup test request to WordPress.
	 *
	 * @param {URLSearchParams} data Request payload.
	 * @return {Promise<object>} Parsed WordPress AJAX response.
	 */
	function postTest( data ) {
		return fetch( config.ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
			},
			body: data,
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	/**
	 * Read a response message with the localized fallback.
	 *
	 * @param {object} response WordPress AJAX response.
	 * @return {string} Response message.
	 */
	function responseMessage( response ) {
		const data = response && response.data && typeof response.data === 'object' ? response.data : {};

		return data.message || config.i18n.connectionTestFailed;
	}

	if ( generationMode ) {
		generationMode.addEventListener( 'change', updateGenerationFields );
		updateGenerationFields();
	}

	const llmButton = document.getElementById( 'worldgraph-test-llm-connection' );
	const llmResult = document.getElementById( 'worldgraph-llm-test-result' );
	const backendInput = document.getElementById( 'worldgraph_ai_backend' );
	const llmUrlInput = document.getElementById( 'worldgraph_ai_url' );
	const modelInput = document.getElementById( 'worldgraph_ai_model' );
	const apiKeyInput = document.getElementById( 'worldgraph_ai_api_key' );
	const loadModelsButton = document.getElementById( 'worldgraph-load-llm-models' );

	function populateModels( models ) {
		const selectedModel = modelInput.value;
		modelInput.replaceChildren( new Option( 'Select a model', '' ) );
		if ( selectedModel && ! models.includes( selectedModel ) ) {
			modelInput.append( new Option( selectedModel, selectedModel ) );
		}
		models.forEach( function ( model ) {
			modelInput.append( new Option( model, model ) );
		} );
		modelInput.value = selectedModel && Array.from( modelInput.options ).some( function ( option ) {
			return option.value === selectedModel;
		} ) ? selectedModel : '';
	}

	if (
		llmButton &&
		llmResult &&
		backendInput &&
		llmUrlInput &&
		modelInput &&
		apiKeyInput &&
		config.actions.testLlm &&
		config.nonces.testLlm
	) {
		llmButton.addEventListener( 'click', function () {
			const data = new URLSearchParams( {
				action: config.actions.testLlm,
				nonce: config.nonces.testLlm,
				backend: backendInput.value,
				url: llmUrlInput.value,
				model: modelInput.value,
				api_key: apiKeyInput.value,
			} );

			llmButton.disabled = true;
			llmResult.textContent = config.i18n.testing;

			postTest( data )
				.then( function ( response ) {
					const responseData = response.data && typeof response.data === 'object' ? response.data : {};
					const models = Array.isArray( responseData.models ) ? responseData.models : [];

					populateModels( models );

					if ( response.success && ! modelInput.value.trim() && 1 === models.length ) {
						modelInput.value = models[ 0 ];
					}

					llmResult.textContent = responseMessage( response );
					if ( response.success && ! modelInput.value.trim() && 1 < models.length ) {
						llmResult.textContent += ' ' + config.i18n.selectModel;
					}
					llmResult.style.color = response.success ? '#008a20' : '#b32d2e';
				} )
				.catch( function () {
					llmResult.textContent = config.i18n.connectionTestUnavailable;
					llmResult.style.color = '#b32d2e';
				} )
				.finally( function () {
					llmButton.disabled = false;
				} );
		} );
	}

	if ( loadModelsButton && backendInput && llmUrlInput && modelInput && apiKeyInput ) {
		loadModelsButton.addEventListener( 'click', function () {
			const data = new URLSearchParams( {
				action: config.actions.discoverModels,
				nonce: config.nonces.testLlm,
				backend: backendInput.value,
				url: llmUrlInput.value,
				model: '',
				api_key: apiKeyInput.value,
			} );

			loadModelsButton.disabled = true;
			llmResult.textContent = config.i18n.loadingModels;
			postTest( data )
				.then( function ( response ) {
					const models = Array.isArray( response.data && response.data.models ) ? response.data.models : [];
					populateModels( models );
					llmResult.textContent = responseMessage( response );
					llmResult.style.color = response.success ? '#008a20' : '#b32d2e';
				} )
				.catch( function () {
					llmResult.textContent = config.i18n.connectionTestUnavailable;
					llmResult.style.color = '#b32d2e';
				} )
				.finally( function () {
					loadModelsButton.disabled = false;
				} );
		} );
	}

	const generationResult = document.getElementById( 'worldgraph-comfy-test-result' );
	const generationUrlInput = document.getElementById( 'worldgraph_comfy_local_url' );
	const generationApiKeyInput = document.getElementById( 'worldgraph_gen_credential_reference' );
	const generationMcpApiKeyInput = document.getElementById(
		'worldgraph_gen_mcp_credential_reference'
	);

	if (
		generationTestButton &&
		generationResult &&
		generationMode &&
		generationUrlInput &&
		generationApiKeyInput &&
		generationMcpApiKeyInput &&
		config.actions.testGeneration &&
		config.nonces.testGeneration
	) {
		generationTestButton.addEventListener( 'click', function () {
			const data = new URLSearchParams( {
				action: config.actions.testGeneration,
				nonce: config.nonces.testGeneration,
				mode: generationMode.value || 'none',
				url: generationUrlInput.value,
				api_key: generationApiKeyInput.value,
				mcp_api_key: generationMcpApiKeyInput.value,
			} );

			generationTestButton.disabled = true;
			generationResult.textContent = config.i18n.testing;

			postTest( data )
				.then( function ( response ) {
					generationResult.textContent = responseMessage( response );
					generationResult.style.color = response.success ? '#008a20' : '#b32d2e';
				} )
				.catch( function () {
					generationResult.textContent = config.i18n.connectionTestUnavailable;
					generationResult.style.color = '#b32d2e';
				} )
				.finally( function () {
					generationTestButton.disabled = false;
				} );
		} );
	}
}() );
