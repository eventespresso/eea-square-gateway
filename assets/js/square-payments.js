jQuery(document).ready(function($) {

	/**
	 * @constructor
	 * Our main object for this PM.
	 *
	 * @namespace eeaSquareParameters
	 * @type {{
	 *		appId: string,
	 *		accessToken: string,
	 *		locationId: string,
	 *		useDigitalWallet: string,
	 *		paymentMethodSlug: string,
	 *		paymentCurrency: string,
	 *		payButtonText: string,
	 *		orgCountry: string,
	 *		currencySign: string,
	 *		txnId: int,
     *		noSPCOError: string
	 *		noSquareError: string,
	 *		browserNotSupported: string,
     *		getTokenError: string,
	 * }}
	 */
	function EeaSquarePayments() {
		this.submitButtonId = '#eea-square-pay-button';
		this.paymentFormId = '#eea-square-pm-form-div';
		this.billingFormId = '#square-onsite-billing-form';
		this.offsetFromTopModifier = -400;
		this.paymentMethodSelector = {};
		this.paymentMethodInfoDiv = {};
		this.paymentFormDiv = {};
		this.submitPaymentButton = {};
		this.paymentNonceInput = {};
		this.notification = '';
		this.initialized = false;
		this.selected = false;
		this.square = {};
		this.squarePayments = {};
		this.txnId = 0;
		this.txnData = {};
		this.spco = window.SPCO || null;
		this.paymentForm = {};
		this.squarePaymentForm = {};
		this.squareDigitalWallet = {};
		this.billingForm = {};
		this.billFirstName = {};
		this.billLastName = {};
		this.billEmail = {};
		this.billAddress = {};
		this.billAddress2 = {};
		this.billCity = {};
		this.billState = {};
		this.billCountry = {};
		this.billZip = {};
		this.billPhone = {};
		this.payAmount = 0;

		/**
		 * @function initialize
		 */
		this.initialize = function() {
			this.initializeObjects();
			// Ensure that the SPCO js class is loaded.
			if (typeof this.spco === 'undefined') {
				this.hideSquare();
				this.displayError(eeaSquareParameters.noSPCOError);
				return;
			}
			// Ensure that the Square js class is loaded.
			if (typeof SqPaymentForm === 'undefined' || ! eeaSquareParameters.appId) {
				this.spco.offset_from_top_modifier = this.offsetFromTopModifier;
				this.notification = this.spco.generate_message_object(
					'',
					this.spco.tag_message_for_debugging('EE_SQUARE.init() error', eeaSquareParameters.noSquareError),
					''
				);
				this.spco.scroll_to_top_and_display_messages(this.paymentFormDiv, this.notification, true);
				return;
			}

			// Are the needed inputs available ?
			if (! $(this.paymentFormId) || ! this.submitPaymentButton.length) {
				return;
			}
			this.selected = true;
			this.disableSPCOSubmitButtonsIfSquareSelected();

			// Has the Square gateway has been selected ? Or already initialized ?
			if (this.initialized) {
				return;
			}

			this.getTransactionData();
			this.setListenerForPaymentAmountChange();
			this.setListenerForSubmitPaymentButton();

			this.initialized = true;
		};

		/**
		 * @function initializeObjects
		 */
		this.initializeObjects = function() {
			this.submitPaymentButton = $(this.submitButtonId);
			this.paymentFormDiv = $(this.paymentFormId);
			this.paymentMethodSelector = $('#ee-available-payment-method-inputs-squareonsite-lbl');
			this.paymentMethodInfoDiv = $('#spco-payment-method-info-squareonsite');
			this.paymentNonceInput = $('#eea-square-nonce');
			this.txnId = eeaSquareParameters.txnId;
			this.billingForm = $(this.billingFormId);
			// Billing data.
			if (typeof this.billingForm !== 'undefined') {
				this.billFirstName = this.billingForm.find(
					'input[id*="billing-form-first-name"]:visible');
				this.billLastName = this.billingForm.find(
					'input[id*="billing-form-last-name"]:visible');
				this.billEmail = this.billingForm.find(
					'input[id*="billing-form-email"]:visible');
				this.billAddress = this.billingForm.find(
					'input[id*="billing-form-address"]:visible');
				this.billAddress2 = this.billingForm.find(
					'input[id*="billing-form-address2"]:visible');
				this.billCity = this.billingForm.find(
					'input[id*="billing-form-city"]:visible');
				this.billState = this.billingForm.find(
					'input[id*="billing-form-state"]:visible');
				this.billCountry = this.billingForm.find(
					'input[id*="billing-form-country"]:visible');
				this.billZip = this.billingForm.find(
					'input[id*="billing-form-zip"]:visible');
				this.billPhone = this.billingForm.find(
					'input[id*="billing-form-phone"]:visible');
			}
		};

		/**
		 * @function buildSquarePaymentForm
		 */
		this.buildSquarePaymentForm = function() {
			// Enable the loader.
			this.spco.do_before_sending_ajax();
			// Create and initialize a payment form object.
			if (SqPaymentForm.isSupportedBrowser()) {
				let squareInstance = this;
				/**
				 * Single-element payment form.
 				 */
				this.squarePaymentForm = new SqPaymentForm({
					// Initialize the payment form elements.
					applicationId: eeaSquareParameters.appId,
					autoBuild: false,
					// Initialize the credit card placeholders
					card: {
						elementId: 'sq-card-se',
						inputStyle: {
							// Set font attributes on card entry fields.
							fontSize: '16px',
							fontWeight: 500,
							placeholderFontWeight: 300,
							autoFillColor: '#FFFFFF',    // Sets color of card nbr & exp. date.
							color: '#FFFFFF',            // Sets color of CVV & Zip.
							placeholderColor: '#ccc',    // Sets placeholder text color.
							backgroundColor: '#121212',
							cardIconColor: '#ccc',
							borderRadius: '10px',
							boxShadow: "0px 2px 6px rgba(0,0,0,.02)," +
								"0px 4px 8px rgba(0,0,0, 0.04), 0px 8px 30px " +
								"rgba(0,0,0, 0.04), 0px 1px 2px rgba(0,0,0, 0.08)",
							// Set form appearance in error state.
							error: {
								cardIconColor: '#f504b1', // Sets color of card icon.
								color: '#f81eba',         // Sets color of card entry text.
								backgroundColor: '#121212',  // Card entry background color.
								fontWeight: 500,
							},
							// Set appearance of hint text below form.
							details: {
								hidden: false,    // Shows or hides hint text.
								color: '#A5A5A5', // Sets hint text color.
								fontSize: '12px',
								fontWeight: 500,
								// Sets attributes of hint text in when form.
								error: {
									color: '#f81eba',
									fontSize: '12px'
								}
							}
						}
					},
					callbacks: {
						// Triggered when: squarePaymentForm completes a card nonce request.
						cardNonceResponseReceived: squareInstance.handleSquareResponse.bind(squareInstance),
						// Invoked when the payment form is hosted in an unsupported browser.
						unsupportedBrowserDetected: squareInstance.unsupportedBrowser.bind(squareInstance),
					}
				});
				// Build the single-element form.
				this.squarePaymentForm.build((error, result) => {
					if (error) {
						this.paymentError(error);
					}
				});


				/**
				 * The Digital Wallet buttons.
				 * Do check if this option is enabled.
 				 */
 				if (eeaSquareParameters.useDigitalWallet === '1') {
					this.squareDigitalWallet = new SqPaymentForm({
						applicationId: eeaSquareParameters.appId,
						locationId: eeaSquareParameters.locationId,
						inputClass: 'sq-input',
						autoBuild: false,
						// Initialize Google Pay button ID.
						googlePay: {
							elementId: 'eea-square-pm-google-pay'
						},
						// Initialize Apple Pay placeholder ID.
						applePay: {
							elementId: 'eea-square-pm-apple-pay'
						},
						// Call back functions.
						callbacks: {
							// Customize the createPaymentRequest callback function.
							createPaymentRequest: squareInstance.createWalletPayment.bind(squareInstance),
							// Enable the buttons if supported.
							methodsSupported: squareInstance.enableDigitalWallet.bind(squareInstance),
							// Triggered when: squarePaymentForm completes a card
							// nonce request through Google Pay or Apple Pay.
							cardNonceResponseReceived: squareInstance.handleSquareResponse.bind(squareInstance),
						}
					});
					// Build the Digital Wallet form.
					this.squareDigitalWallet.build((error, result) => {
						if (error) {
							this.paymentError(error);
						}
					});
				}

				// Set the right amount on the button.
				$(this.submitButtonId).val(
					eeaSquareParameters.payButtonText
					+ ' ' + eeaSquareParameters.currencySign
					+ this.payAmount
				);

				// Show the Pay button if the payment form generated ok.
				this.submitPaymentButton.show();
				this.spco.end_ajax();
			} else {
				this.hideSquare();
				this.displayError(eeaSquareParameters.browserNotSupported);
			}
		};

		/**
		 * @function enableDigitalWallet
		 */
		this.enableDigitalWallet = function(methods, unsupportedReason) {
			const googlePayBtn = document.getElementById('eea-square-pm-google-pay');
			const applePayBtn = document.getElementById('eea-square-pm-apple-pay');

			// Only show the button if Google Pay on the Web is enabled.
			if (methods.googlePay === true) {
				googlePayBtn.style.display = 'inline-block';
			}
			// else if(unsupportedReason) {
			// 	console.log('Google Pay not supported:', unsupportedReason);
			// }
			// Same for ApplePay.
			if (methods.applePay === true) {
				applePayBtn.style.display = 'inline-block';
			}
			// else if(unsupportedReason) {
			// 	console.log('Apple Pay not supported:', unsupportedReason);
			// }
		};

		/**
		 * @function setListenerForPaymentAmountChange
		 */
		this.setListenerForPaymentAmountChange = function() {
			this.spco.main_container.on('spco_payment_amount', (event, paymentAmount) => {
				if (typeof paymentAmount !== 'undefined' && parseInt(paymentAmount) !== 0) {
					// Update the Pay button with an amount.
					this.submitPaymentButton.val(
						eeaSquareParameters.payButtonText
						+ ' ' + eeaSquareParameters.currencySign
						+ paymentAmount.toFixed(eeaSquareParameters.decimalPlaces)
					);
					// Also update the variable holding the amount.
					this.payAmount = paymentAmount;
				}
			} );
		};

		/**
		 * @function setListenerForSubmitPaymentButton
		 */
		this.setListenerForSubmitPaymentButton = function() {
			this.paymentForm = this.submitPaymentButton.parents('form:first');
			this.paymentForm.on('submit.eeaSquare', (e) => {
				e.preventDefault();
				// First validate the form, so that the payment flow is not broken by SPCO form validation.
				if (this.paymentForm.valid()) {
					this.submitPaymentButton.prop('disabled', true).addClass('spco-disabled-submit-btn');
					// Request a nonce from the squarePaymentForm object
					this.squarePaymentForm.requestCardNonce();
				}
			});
		};

		/**
		 * Get the transaction data.
		 */
		this.getTransactionData = function() {
			var reqData = {};
			const squareInstance = this;
			reqData.step = 'payment_options';
			reqData.action = 'get_transaction_details_for_gateways';
			reqData.selected_method_of_payment = eeaSquareParameters.paymentMethodSlug;
			reqData.generate_reg_form = false;
			reqData.process_form_submission = false;
			reqData.noheader = true;
			reqData.ee_front_ajax = true;
			reqData.EESID = eei18n.EESID;
			reqData.revisit = eei18n.revisit;
			reqData.e_reg_url_link = eei18n.e_reg_url_link;

			$.ajax({
				type: "POST",
				url: eei18n.ajax_url,
				data: reqData,
				dataType: "json",
				beforeSend: function() {
					SPCO.do_before_sending_ajax();
				},
				success: function(response) {
					// If we can't get a transaction data we can't set up a checkout.
					if (response['error'] || typeof response['TXN_ID'] == 'undefined' || response['TXN_ID'] == null) {
						return SPCO.submit_reg_form_server_error();
					}
					// Save transaction data.
					squareInstance.txnData = response;
					// Set the payment amount.
					squareInstance.payAmount = squareInstance.txnData.payment_amount.toFixed(eeaSquareParameters.decimalPlaces);

					// Now build the PM form.
					squareInstance.buildSquarePaymentForm();
				},
				error: function() {
					SPCO.end_ajax();
					return SPCO.submit_reg_form_server_error();
				}
			});
		};

		/**
		 * @function handleSquareResponse
		 * @param  {object} errors
		 * @param  {string} nonce
		 * @param  {object} cardData
		 */
		this.handleSquareResponse = function(errors, nonce, cardData) {
			if (errors) {
				this.paymentError(errors);
			} else {
				this.paymentSuccess(nonce, cardData);
			}
		};

		/**
		 * @function createWalletPayment
		 * @return object
		 */
		this.createWalletPayment = function() {
			// Check the form before proceeding.
			if (! this.paymentForm.valid()) {
				return false;
			}
			// Ok, now create the payment for the Apple/Google Pay.
			const paymentRequestJson = {
				requestShippingAddress: false,
				requestBillingInfo: true,
				shippingContact: {
					familyName: this.billLastName.val(),
					givenName: this.billFirstName.val(),
					email: this.billEmail.val(),
					country: this.billCountry.val(),
					region: this.billState.val(),
					city: this.billCity.val(),
					addressLines: [
						this.billAddress.val(),
						this.billAddress2.val()
					],
					postalCode: this.billZip.val(),
					phone: this.billPhone.val()
				},
				currencyCode: eeaSquareParameters.paymentCurrency,
				countryCode: eeaSquareParameters.orgCountry,
				total: {
					label: eeaSquareParameters.siteName,
					amount: this.payAmount,
					pending: false
				}
			};

			return paymentRequestJson;
		};

		/**
		 * @function unsupportedBrowser
		 */
		this.unsupportedBrowser = function() {
			this.hideSquare();
			this.displayError(eeaSquareParameters.browserNotSupported);
		};

		/**
		 * @function paymentSuccess
		 * @param  {object} nonce
		 * @param  {object} cardData
		 */
		this.paymentSuccess = function(nonce, cardData) {
			// Enable SPCO submit buttons.
			this.spco.enable_submit_buttons();
			// Save the payment nonce.
			this.paymentNonceInput.val(nonce);
			this.spco.offset_from_top_modifier = this.offsetFromTopModifier;
			// Hide any return to cart buttons, etc.
			$('.hide-me-after-successful-payment-js').hide();
			// Trigger click event on SPCO "Proceed to Next Step" button.
			this.submitPaymentButton.parents('form:first').find('.spco-next-step-btn').trigger('click');
			// Further verification is needed ?
			this.spco.main_container.on('spco_process_response', (event, nextStep, response) => {
				if (! response.success) {
					this.submitPaymentButton.prop('disabled', false).removeClass('spco-disabled-submit-btn');
					this.spco.disable_submit_buttons();
					// tell SPCO to not enable the submit button.
					this.spco.allow_enable_submit_buttons = false;
				}
			});
		};

		/**
		 * @function checkout_error
		 * @param  {object} response
		 */
		this.paymentError = function(errors) {
			let errorsMessage = '';
			// Re-enable the payment button.
			this.submitPaymentButton.prop('disabled', false).removeClass('spco-disabled-submit-btn');
			// Show error in payment form.
			errors.forEach(function (error) {
				errorsMessage += ' ' + error.message;
			});
			this.notification = this.spco.generate_message_object(
				'',
				'',
				this.spco.tag_message_for_debugging('squareResponseHandler error', errorsMessage)
			);
			this.logError(errorsMessage);
		};

		/**
		 * @function hideSquare
		 */
		this.hideSquare = function() {
			this.paymentMethodSelector.hide();
			this.paymentMethodInfoDiv.hide();
		};

		/**
		 * Deactivate SPCO submit buttons to prevent submitting with no Square token.
		 * @function disableSPCOSubmitButtonsIfSquareSelected
		 */
		this.disableSPCOSubmitButtonsIfSquareSelected = function() {
			if (this.selected && this.submitPaymentButton.length > 0) {
				this.spco.disable_submit_buttons();
			}
		};

		/**
		 * @function displayError
		 * @param  {string} msg
		 */
		this.displayError = function(msg) {
			// center notices on screen
			$('#espresso-ajax-notices').eeCenter('fixed');
			// target parent container
			const espressoJjaxMsg = $('#espresso-ajax-notices-error');
			//  actual message container
			espressoJjaxMsg.children('.espresso-notices-msg').html(msg);
			// bye bye spinner
			$('#espresso-ajax-loading').fadeOut('fast');
			// display message
			espressoJjaxMsg.removeClass('hidden').show().delay(10000).fadeOut();
			// Log the Error.
			this.logError(msg);
		};

		/**
		 * @function logError
		 * @param  {string} msg
		 */
		this.logError = function(msg) {
			const ajaxUrl = typeof ajaxurl === 'undefined' ? eei18n.ajax_url : ajaxurl;
			$.ajax({
				type: 'POST',
				dataType: 'json',
				url: ajaxUrl,
				data: {
					action: 'eeaSquareLogError',
					txn_id: this.txnData['TXN_ID'],
					message: msg,
				},
			});
		};

		/**
		 * @function tearDown
		 */
		this.tearDown = function() {
			// Head out if this PM is not initialized anymore.
			if (! this.initialized) {
				return;
			}
			// Unhook the Square submission hooks, if they were set previously.
			if (typeof this.paymentForm.off === 'function') {
				this.paymentForm.off('submit.eeaSquare');
			}
			if (typeof this.squarePaymentForm === 'object') {
				this.submitPaymentButton.hide();
				this.initialized = false;
				if (typeof this.squarePaymentForm.destroy === 'function') {
					this.squarePaymentForm.destroy();
				}
			}
		};

		// Initialize Square Payments if the SPCO reg step changes to "payment_options".
		this.spco.main_container.on('spco_display_step', (event, stepToShow) => {
			if (typeof stepToShow !== 'undefined' && stepToShow === 'payment_options') {
				this.initialize();
			}
		});

		// also initialize Square Payments if the selected method of payment changes
		this.spco.main_container.on('spco_switch_payment_methods', (event, paymentMethod) => {
			//SPCO.console_log( 'paymentMethod', paymentMethod, false );
			if (typeof paymentMethod !== 'undefined' && paymentMethod === eeaSquareParameters.paymentMethodSlug) {
				this.selected = true;
				this.initialize();
			} else {
				this.selected = false;
				this.tearDown();
			}
		});
	}
	// End of EeaSquarePayments object.

	const squarePaymentsInstance = new EeaSquarePayments();
	// Also initialize Square Payments if the page just happens to load on the "payment_options" step with Square already selected!
	squarePaymentsInstance.initialize();
});
