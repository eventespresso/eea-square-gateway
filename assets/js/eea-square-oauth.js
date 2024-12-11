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
	 *	connectSection: string,
	 *	disconnectSection: string,
	 *	formId: string,
	 *	processingIconName: string,
	 *	debugModeInput: object,
	 *	submittedPm: string,
	 *	debugMode: string,
	 *	registerDomainBtn: object,
	 *	registerDomainBtnId: string,
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
	 *	refreshAlert: string,
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
		this.registerDomainBtn = {};
		this.form = {};
		this.connectBtnId = '#eea_square_connect_btn_' + this.slug;
		this.disconnectBtnId = '#eea_square_disconnect_btn_' + this.slug;
		this.registerDomainBtnId = '#eea_apple_register_domain_' + this.slug;
		this.connectSection = '.eea-connect-section-' + this.slug;
		this.disconnectSection = '.eea-disconnect-section-' + this.slug;
		this.locationsSelect = '.eea-locations-select-' + this.slug;
		this.sandboxOauthedTextSection = '#eea_square_test_connected_txt_' + this.slug;
		this.sandboxModeHelpText = '#' + this.slug + '_settings_form-payment-method-settings-pmd-debug-mode-help'
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
				this.debugModeInput.siblings(this.sandboxModeHelpText).hide();
				this.debugModeInput.siblings('#sandbox-mode-disabled-notice').show();
			} else {
				this.debugModeInput.siblings(this.sandboxModeHelpText).show();
				this.debugModeInput.siblings('#sandbox-mode-disabled-notice').hide();
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
			this.registerDomainBtn = $(this.registerDomainBtnId);
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

			// Hide dropdown inputs if there is no data in them.
			this.toggleNoDataInputs(this.form);

			// Connect with Square.
			this.connectBtn.on('click', function(event) {
				event.preventDefault();
				const buttonContainer = $(this).closest('.square-connections-section-div');
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
					let parameters = [
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
							let eeLoader = squarePmInstance.oauthWindow.document.getElementById(
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
				const buttonContainer = $(this).closest('.square-connections-section-div');
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

			$(this.registerDomainBtnId).on('click', function(event) {
				event.preventDefault();
				const buttonContainer = $(this).closest('tr');
				const submittingForm = $(this).parents('form:first')[0];
				if (buttonContainer && submittingForm) {
					squarePmInstance.submittedPm = 'squareonsite';
					squarePmInstance.debugMode = squarePmInstance.debugModeInput[0].value;
					const requestData = {
						action: 'squareRegisterDomain',
						submittedPm: squarePmInstance.submittedPm,
						debugMode: squarePmInstance.debugMode
					};
					$.ajax({
						type: 'POST',
						url: eei18n.ajax_url,
						data: requestData,
						dataType: 'json',
						beforeSend: function() {
							window.do_before_admin_page_ajax();
						},
						success: function(response) {
							$('#' + squarePmInstance.processingIconName).fadeOut('fast');
							console.log(response);
							if (typeof response.status !== 'undefined') {
							    // hide the button
							    const domainNameSection = squarePmInstance.registerDomainBtn.closest('tr');
							    domainNameSection.hide();
							    alert(response.status);
                            }
							if (typeof response.squareError !== 'undefined') {
							    alert(response.squareError);
                            }
						},
						error: function(response, error, description) {
							$('#' + squarePmInstance.processingIconName).fadeOut('fast');
							alert(description);
						}
					});
				} else {
					console.error(squareParams.unknownContainer);
				}
			});
		};

		/**
		 * Show/Hide no data inputs.
		 * @function
		 */
		this.toggleNoDataInputs = function(target) {
			const targetForm = target.parents('form');
			const locationsSelect = targetForm.find(this.locationsSelect).first();
			const locationsList = locationsSelect.find('option').length;
			const locationsSection = targetForm.find(this.locationsSelect).closest('tr');

			// Hide if no options in the dropdown select.
			if (locationsList < 1) {
				locationsSection.hide();
			} else if (! locationsSection.is(':visible')) {
				locationsSection.show();
			}
		};

		/**
		 * Updates the "Connect with Square" button text.
		 * @function
		 */
		this.updateBtnText = function(caller, allowAlert) {
			const submittingForm = caller.parents('form:first')[0];
			if (submittingForm) {
				const squareConnectBtn = $(submittingForm).find('#' + this.connectBtnId.substr(1));
				if (squareConnectBtn) {
					const btnTextSpan = $(squareConnectBtn).find('span')[0];
					const pmDebugModeEnabled = caller[0].value;
					const disconnectSection = $(submittingForm).find(this.disconnectSection);

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
						const sandboxConnected = $(disconnectSection).find(this.connectSection);
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
			const disconnectSection = $(submittingForm).find(this.disconnectSection);
			if (disconnectSection && this.debugModeInput) {
				const pmDebugModeValue = this.debugModeInput[0].value;
				const sandboxConnectedText = $(disconnectSection).find(this.sandboxOauthedTextSection);
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
				error: squarePmInstance.requestError,
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
				if (typeof response.alert !== 'undefined' && response.alert) {
					alert(squareParams.refreshAlert);
				}

				// Display the error in the pop-up.
				if (this.oauthWindow) {
					this.oauthWindow.document.getElementById(this.processingIconName).style.display = 'none';
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

		this.requestError = function(response, err, description) {
			let error = squareParams.errorResponse;
			if (description && typeof description === 'string') {
				error = error + ': ' + description;
			}
			$('#' + this.processingIconName).fadeOut('fast');
			console.error(error);
			// Display the error in the pop-up.
			if (this.oauthWindow) {
				this.oauthWindow.document.getElementById(this.processingIconName).style.display = 'none';
				$(this.oauthWindow.document.body).html(error);
				this.oauthWindow = null;
			}
			if (typeof response.alert !== 'undefined' && response.alert) {
				alert(squareParams.refreshAlert);
			}
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
					if (typeof response.connected !== 'undefined' && response.connected) {
						squareInstance.showDisconnectSection(squareInstance, response);
					} else {
						squareInstance.showConnectSection(squareInstance);
						if (typeof response.error !== 'undefined') {
							console.error(response.error);
							alert(response.error);
						}
					}
					$('#' + squareInstance.processingIconName).fadeOut('fast');
				},
			} );
		};

        /**
         * Show the Connect UI.
         * @function
         */
        this.showConnectSection = function (this_pm) {
            $(this_pm.disconnectSection).addClass('square-connect-hidden');
            $(this_pm.connectSection).removeClass('square-connect-hidden');
            $(this_pm.locationsSelect).closest('tr').hide();
            this_pm.registerDomainBtn.closest('tr').hide();
            if (squareParams.canDisableInput) {
                // Enable the debug mode selector.
                this_pm.debugModeInput.prop('disabled', false);
                this_pm.debugModeInput.siblings('p.description').show();
                this_pm.debugModeInput.siblings('p.disabled-description').hide();
            }
        };

        /**
         * Show the deauthorize UI.
         * @function
         */
        this.showDisconnectSection = function (this_pm, response) {
            $(this_pm.connectSection).addClass('square-connect-hidden');
            $(this_pm.disconnectSection).removeClass('square-connect-hidden');
            $(this_pm.locationsSelect).closest('tr').show();
            if (squareParams.canDisableInput) {
                // Disable the debug mode selector.
                this_pm.debugModeInput.prop('disabled', true);
                this_pm.debugModeInput.siblings('p.description').hide();
                this_pm.debugModeInput.siblings('p.disabled-description').show();
            }
            // Also show the locations dropdown.
            if (typeof response.location !== 'undefined' && response.location && Object.keys(response.locationList).length > 0) {
                let selected = '';
                $(this_pm.locationsSelect).empty();
                $.each(response.locationList, function(listId, listName) {
                    if (listId === response.location) {
                        selected = 'selected';
                    }
                    $(this_pm.locationsSelect).append(
                        '<option selected="' + selected + '" value="' + listId + '">' + listName + '</option>'
                    );
                    // Reset the 'selected' flag, just to be safe.
                    selected = '';
                });
                $(this_pm.locationsSelect).prop('disabled', false);
            }
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
