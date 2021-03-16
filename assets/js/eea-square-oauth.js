jQuery(document).ready(function($) {
	/**
	 * @namespace EeaSquareOAuth
	 * @type {{
	 *	slug: string,
	 *	oauthWindow: boolean,
	 *	initialized: boolean,
	 *	connectBtn: object,
	 *	disconnectBtn: object,
	 *	form: object,
	 *	connectBtnId: string,
	 *	disconnectBtnId: string,
	 *	appIdFieldId: string,
	 * 	accessTokenFieldId: string,
	 *	authenticationFieldId: string,
	 *	connectSection: string,
	 *	disconnectSection: string,
	 *	formId: string,
	 *	processingIconName: string,
	 *	debugModeInput: object,
	 *	submittedPm: string,
	 *	debugMode: string,
	 * }}
	 *
	 * @namespace eeaSquareOAuthParameters
	 * @type {{
	 *	requestConnectionNotice: string,
	 *	blockedPopupNotice: string,
	 *	debugIsOnNotice: string,
	 *	debugIsOffNotice: string,
	 *	errorResponse: string,
	 *	oauthRequestErrorText: string,
	 *	unknownContainer: string,
	 *	pmNiceName: string,
	 *	espressoDefaultStyles: string,
	 *	wpStylesheet: string,
	 *	connectBtnText: string,
	 *	connectBtnSandboxText: string,
	 *	connectedSandboxText: string,
	 *  canDisableInput: boolean,
	 * }}
	 */
	function EeaSquareOAuth(squareInstanceVars, squareParams) {
		this.slug = squareInstanceVars.pmSlug;
		this.oauthWindow = null;
		this.initialized = false;
		this.connectBtn = {};
		this.disconnectBtn = {};
		this.form = {};
		this.connectBtnId = '#eea_square_connect_btn_' + this.slug;
		this.disconnectBtnId = '#eea_square_disconnect_btn_' + this.slug;
		this.connectSectionId = '#eea_square_oauth_section_' + this.slug;
		this.appIdFieldId = '#' + this.slug + '-app-id';
		this.accessTokenFieldId = '#' + this.slug + '-access-token';
		this.locationIdFieldId = '#' + this.slug + '-location-id';
		this.useDwalletId = '#' + this.slug + '-use-dwallet';
		this.authenticationFieldId = '#' + this.slug + '-authentication';
		this.connectSection = 'eea-connect-section-' + this.slug;
		this.disconnectSection = 'eea-disconnect-section-' + this.slug;
		this.sandboxOauthedTextSection = 'eea_square_test_connected_txt_' + this.slug;
		this.formId = '#' + squareInstanceVars.formId;
		this.processingIconName = 'espresso-ajax-loading';
		this.debugModeInput = {};
		this.submittedPm = '';
		this.debugMode = 0;

		/**
		 * @function
		 */
		this.initialize = function() {
			this.initializeObjects();
			// Square selected (initialized) ?
			if (! this.connectBtn.length ||
				! this.disconnectBtn.length ||
				this.initialized
			) {
				return;
			}
			this.connectBtnListeners();
			if (this.debugModeInput.prop('disabled') && squareParams.canDisableInput) {
				this.debugModeInput.siblings('p.description').hide();
				this.debugModeInput.siblings('p.disabled-description').show();
			} else {
				this.debugModeInput.siblings('p.description').show();
				this.debugModeInput.siblings('p.disabled-description').hide();
			}

			this.initialized = true;
		};

		/**
		 * Initializes jQuery objects which point to various page elements.
		 * @function
		 */
		this.initializeObjects = function() {
			this.connectBtn = $(this.connectBtnId);
			this.disconnectBtn = $(this.disconnectBtnId);
			this.form = $(this.formId);
			this.debugModeInput = this.form.closest('form').find('select[name*='+this.slug+'][name*=PMD_debug_mode]');
		};

		/**
		 * Sets up listeners to listen for when the connect button is pressed.
		 * @function
		 */
		this.connectBtnListeners = function() {
			const squarePmInstance = this;
			const sandboxModeSelect = this.form.find('select[name*=square][name*=PMD_debug_mode]');

			// Update connect button text depending on the PM sandbox mode.
			sandboxModeSelect.each(function() {
				squarePmInstance.updateBtnText($(this), false);
			});

			// Listen for the sandbox mode change.
			sandboxModeSelect.on('change', function() {
				squarePmInstance.updateBtnText($(this), true);
			});

			// Connect with Square.
			this.connectBtn.on('click', function(event) {
				event.preventDefault();
				const buttonContainer = $(this).closest('tr');
				const submittingForm = $(this).parents('form:first')[0];
				if (buttonContainer && submittingForm) {
					// Check if window already open.
					if (squarePmInstance.oauthWindow &&
						 ! squarePmInstance.oauthWindow.closed
					) {
						squarePmInstance.oauthWindow.focus();
						return;
					}
					// Need to open the new window now to prevent browser pop-up blocking.
					let wHeight = screen.height / 2;
					wHeight = wHeight > 750 ? 750 : wHeight;
					wHeight = wHeight < 280 ? 280 : wHeight;
					let wWidth = screen.width / 2;
					wWidth = wWidth > 1200 ? 1200 : wWidth;
					wWidth = wWidth < 380 ? 380 : wWidth;
					const parameters = [
						'location=0',
						'height=' + wHeight,
						'width=' + wWidth,
						'top=' + (screen.height - wHeight) / 2,
						'left=' + (screen.width - wWidth) / 2,
						'centered=true'
					];
					squarePmInstance.oauthWindow = window.open('', 'SquareConnectPopupWindow', parameters.join());
					setTimeout(
						function() {
							$(squarePmInstance.oauthWindow.document.body).html(
								'<html><head>' +
								'<title>' + squareParams.pmNiceName + '</title>' +
								'<link rel="stylesheet" type="text/css" href="' +
									squareParams.espressoDefaultStyles + '">' +
								'<link rel="stylesheet" type="text/css" href="' +
									squareParams.wpStylesheet +
								'">' +
								'</head><body>' +
								'<div id="' + squarePmInstance.processingIconName + '" class="ajax-loading-grey">' +
								'<span class="ee-spinner ee-spin">' +
								'</div></body></html>'
							);
							const eeLoader = squarePmInstance.oauthWindow.document.getElementById(
								squarePmInstance.processingIconName
							);
							eeLoader.style.display = 'inline-block';
							eeLoader.style.top = '40%';
						},
						100
					);
					// Check in case the pop-up window was blocked.
					if (! squarePmInstance.oauthWindow ||
						typeof squarePmInstance.oauthWindow === 'undefined' ||
						typeof squarePmInstance.oauthWindow.closed === 'undefined' ||
						squarePmInstance.oauthWindow.closed
					) {
						squarePmInstance.oauthWindow = null;
						alert(squareParams.blockedPopupNotice);
						console.error(squareParams.blockedPopupNotice);
						return;
					}

					// Should we update the connected area text ?
					squarePmInstance.updateConnectionInfo(submittingForm);

					// Continue to the OAuth page.
					squarePmInstance.submittedPm = buttonContainer.attr('id').replace(
						/eea_square_connect_|eea_square_disconnect_/,
						''
					);
					squarePmInstance.debugMode = squarePmInstance.debugModeInput[0].value;
					squarePmInstance.oauthSendRequest('squareRequestConnectData');
				} else {
					console.error(squareParams.unknownContainer);
				}
			});

			// Disconnect from Square.
			$(this.disconnectBtnId).on('click', function(event) {
				event.preventDefault();
				const buttonContainer = $(this).closest('tr');
				const submittingForm = $(this).parents('form:first')[0];
				if (buttonContainer && submittingForm) {
					squarePmInstance.submittedPm = buttonContainer.attr('id').replace(
						/eea_square_connect_|eea_square_disconnect_/,
						''
					);
					squarePmInstance.debugMode = squarePmInstance.debugModeInput[0].value;
					squarePmInstance.oauthSendRequest('squareRequestDisconnect');
				} else {
					console.error(squareParams.unknownContainer);
				}
			});

			this.toggleNonApplicableInputs(this.form.parents('form').find(this.authenticationFieldId), false);
			// Change listener for the Authentication type select.
			$(this.authenticationFieldId).change(function(event) {
				squarePmInstance.toggleNonApplicableInputs($(event.target), true);
			});
			// Change listener for the Digital Wallet toggle.
			$(this.useDwalletId).change(function(event) {
				squarePmInstance.toggleDwalletInputs($(event.target));
			});
		};

		/**
		 * Show/Hide the non OAuth inputs.
		 * @function
		 */
		this.toggleNonApplicableInputs = function(target, toggleOauth) {
			const targetForm = target.parents('form');
			const appIdInput = targetForm.find(this.appIdFieldId).closest('tr');
			const accessTokenInput = targetForm.find(this.accessTokenFieldId).closest('tr');
			const locationIdInput = targetForm.find(this.locationIdFieldId).closest('tr');
			const authenticationInput = targetForm.find(this.connectSectionId).closest('tr');
			const digitalWalletToggle = targetForm.find(this.useDwalletId);

			if (target.val() === 'personal') {
				appIdInput.css('display', 'table-row');
				accessTokenInput.css('display', 'table-row');
				if (toggleOauth) {
					authenticationInput.css('display', 'none');
				}
				// Double check the Digital Wallet toggle.
				if (digitalWalletToggle.val() === '1') {
					locationIdInput.css('display', 'table-row');
				} else {
					locationIdInput.css('display', 'none');
				}
			} else {
				appIdInput.css('display', 'none');
				accessTokenInput.css('display', 'none');
				locationIdInput.css('display', 'none');
				if (toggleOauth) {
					authenticationInput.css('display', 'table-row');
				}
			}
		};

		/**
		 * Show/Hide the Digital Wallet inputs.
		 * @function
		 */
		this.toggleDwalletInputs = function(target) {
			const targetForm = target.parents('form');
			const oauthInput = targetForm.find(this.authenticationFieldId);
			const locationIdInput = targetForm.find(this.locationIdFieldId).closest('tr');

			// Hide the Digital Wallet required inputs.
			if (target.val() === '1' && oauthInput.val() === 'personal') {
				locationIdInput.css('display', 'table-row');
			} else {
				locationIdInput.css('display', 'none');
			}
		};

		/**
		 * Updates the "Connect with Square" button text.
		 * @function
		 */
		this.updateBtnText = function(caller, allowAlert) {
			const submittingForm = caller.parents('form:first')[0];
			if (submittingForm) {
				const squareConnectBtn = $(submittingForm).find(
					'a[id=' + this.connectBtnId.substr(1) + ']'
				)[0];
				if (squareConnectBtn) {
					const btnTextSpan = $(squareConnectBtn).find('span')[0];
					const pmDebugModeEnabled = caller[0].value;
					const disconnectSection = $(submittingForm).find(
						'tr[class="' + this.disconnectSection + '"]'
					)[0];

					// Change button text.
					if (btnTextSpan) {
						if (pmDebugModeEnabled === '1') {
							$(btnTextSpan).text(squareParams.connectBtnSandboxText);
						} else {
							$(btnTextSpan).text(squareParams.connectBtnText);
						}
					}

					// Maybe show an alert.
					if (allowAlert && disconnectSection && $(disconnectSection).is(':visible')) {
						const sandboxConnected = $(disconnectSection).find(
							'strong[class="' + this.connectSection + '"]'
						)[0];
						if (sandboxConnected && pmDebugModeEnabled === '0') {
							alert(squareParams.debugIsOnNotice);
						} else if (! sandboxConnected && pmDebugModeEnabled === '1') {
							alert(squareParams.debugIsOffNotice);
						}
					}
				}
			}
		};

		/**
		 * Updates the "Disconnect from Square" button
		 * @function
		 */
		this.updateConnectionInfo = function(submittingForm) {
			const disconnectSection = $(submittingForm).find(
				'tr[class="' + this.disconnectSection + '"]'
			)[0];
			if (disconnectSection && this.debugModeInput) {
				const pmDebugModeValue = this.debugModeInput[0].value;
				const sandboxConnectedText = $(disconnectSection).find(
					'strong[id="' + this.sandboxOauthedTextSection + '"]'
				)[0];
				if (sandboxConnectedText &&
					pmDebugModeValue === '0' &&
					$(sandboxConnectedText).html().length > 0
				) {
					// Remove the sandbox connection note.
					$(sandboxConnectedText).html('');
				} else if (sandboxConnectedText &&
					pmDebugModeValue === '1' &&
					$(sandboxConnectedText).html().length === 0
				) {
					// Add the sandbox connection note.
					$(sandboxConnectedText).html(
						squareParams.connectedSandboxText
					);
				}
			}
		};

		/**
		 * Sends the OAuth request and then sets up callbacks depending on how it goes
		 * @function
		 */
		this.oauthSendRequest = function(requestAction) {
			const requestData = {
				action: requestAction,
				submittedPm: this.submittedPm,
				debugMode: this.debugMode
			};
			const squarePmInstance = this;
			$.ajax({
				type: 'POST',
				url: eei18n.ajax_url,
				data: requestData,
				dataType: 'json',

				beforeSend: function() {
					window.do_before_admin_page_ajax();
				},
				success: function(response) {
					squarePmInstance.oauthRequestSuccess(response, requestAction);
				},
				error: squarePmInstance.oauthRequestError,
			});
		};

		/**
		 * How to handle a successful OAuth HTTP Request that was sent
		 * @param response
		 * @param requestAction string
		 * @return {boolean}
		 */
		this.oauthRequestSuccess = function(response, requestAction) {
			const processingIcon = $('#' + this.processingIconName);
			if (response === null ||
				response.squareError ||
				typeof response.squareSuccess === 'undefined' ||
				response.squareSuccess === null
			) {
				let squareError = squareParams.oauthRequestErrorText;
				processingIcon.fadeOut('fast');
				if (response.squareError) {
					squareError = response.squareError;
				}
				console.error(squareError);

				// Display the error in the pop-up.
				if (this.oauthWindow) {
					this.oauthWindow.document.getElementById(
						this.processingIconName
					).style.display = 'none';
					$(this.oauthWindow.document.body).html(squareError);
					this.oauthWindow = null;
				}
				return false;
			}
			processingIcon.fadeOut('fast');

			switch (requestAction) {
				// If all is fine open a new window for OAuth process.
				case 'squareRequestConnectData':
					this.openOauthWindow(response.requestUrl);
					break;
					// Disconnect.
				case 'squareRequestDisconnect':
					this.updateConnectionStatus();
					break;
			}
		};

		this.oauthRequestError = function(response, error, description) {
			let squareError = squareParams.errorResponse;
			if (description) {
				squareError = squareError + ': ' + description;
			}
			// Display the error in the pop-up.
			if (this.oauthWindow) {
				this.oauthWindow.document.getElementById(
					this.processingIconName
				).style.display = 'none';
				$(this.oauthWindow.document.body).html(squareError);
				this.oauthWindow = null;
			}
			$('#' + this.processingIconName).fadeOut('fast');
			console.error(squareError);
		};

		/**
		 * Opens the OAuth window, or focuses on it if it's already open.
		 * @function
		 */
		this.openOauthWindow = function(requestUrl) {
			if (this.oauthWindow &&
				this.oauthWindow.location.href.indexOf('about:blank') > -1
			) {
				this.oauthWindow.location = requestUrl;
				// Update the connection status if window was closed.
				const squarePmInstance = this;
				this.oauthWindowTimer = setInterval(
					function() {
						squarePmInstance.checkOauthWindow();
					},
					500
				);
			} else if (this.oauthWindow) {
				this.oauthWindow.focus();
			}
		};

		/**
		 * Checks if the OAuth window was closed.
		 * @function
		 */
		this.checkOauthWindow = function() {
			if (this.oauthWindow && this.oauthWindow.closed) {
				clearInterval(this.oauthWindowTimer);
				this.updateConnectionStatus();
				this.oauthWindow = false;
			}
		};

		/**
		 * Updates the UI to show if we've managed to get connected with Square.
		 * @function
		 */
		this.updateConnectionStatus = function() {
			const requestData = {
				action:      'squareUpdateConnectionStatus',
				submittedPm: this.submittedPm,
				debugMode:   this.debugMode
			};
			const squareInstance = this;
			$.ajax({
				type: 'POST',
				url: eei18n.ajax_url,
				data: requestData,
				dataType: 'json',

				beforeSend: function() {
					window.do_before_admin_page_ajax();
				},
				success: function(response) {
					const connectSection = $('.' + squareInstance.connectSection);
					const disconnectSection = $('.' + squareInstance.disconnectSection);
					const authenticationField = $(squareInstance.authenticationFieldId);
					$('#' + squareInstance.processingIconName).fadeOut('fast');
					if (response.connected === true) {
						connectSection.hide();
						disconnectSection.show();
						if (squareParams.canDisableInput) {
							// Disable the authentication type selector.
							authenticationField.prop('disabled', true);
							// Disable the debug mode selector.
							squareInstance.debugModeInput.prop('disabled', true);
							squareInstance.debugModeInput.siblings('p.description').hide();
							squareInstance.debugModeInput.siblings('p.disabled-description').show();
						}
					} else {
						connectSection.show();
						disconnectSection.hide();
						if (squareParams.canDisableInput) {
							// Enable the authentication type selector.
							authenticationField.prop('disabled', false);
							// Enable the debug mode selector.
							squareInstance.debugModeInput.prop('disabled', false);
							squareInstance.debugModeInput.siblings('p.description').show();
							squareInstance.debugModeInput.siblings('p.disabled-description').hide();
						}
					}
				},
			} );
		};
	}
	// End of EeaSquareOAuth object.

	// Initialize and run.
	const squarePms = {};
	for (const slug in ee_form_section_vars.squareGateway) {
		squarePms[ slug ] = new EeaSquareOAuth(ee_form_section_vars.squareGateway[ slug ], eeaSquareOAuthParameters);
		squarePms[ slug ].initialize();
	}
});
