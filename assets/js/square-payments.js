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
	 *		formValidationNotice: string,
	 * }}
	 */
	function EeaSquarePayments() {
		this.submitButtonId = '#eea-square-pay-button';
		this.paymentFormId = '#eea-square-pm-form-div';
		this.billingFormId = '#square-onsite-billing-form';
		this.googlePayButtonId = '#eea-square-pm-google-pay';
		this.applePayButtonId = '#apple-pay-button';
		this.offsetFromTopModifier = -400;
		this.paymentMethodSelector = {};
		this.paymentMethodInfoDiv = {};
		this.paymentFormDiv = {};
		this.submitPaymentButton = {};
		this.paymentNonceInput = {};
		this.paymentVerificationInput = {};
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
		this.card = {};
		this.applePay = {};
		this.googlePay = {};
		this.paymentMethod = {};
		this.darkModeCardStyle = {};
		this.googlePayButton = {};
		this.applePayButton = {};
		this.doSca = false;
		this.squarePayments = {};
		this.sqCardContainer = {};


		/**
		 * @function initialize
		 * Initialize the payment method.
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
			if (typeof Square === 'undefined' || ! eeaSquareParameters.appId) {
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
				// Re-build the PM form in case SPCO disassembled the PM form.
				if (! this.sqCardContainer.length) {
					this.buildSquarePaymentForm();
				}
				return;
			}

			this.getTransactionData();
			this.setListenerForPaymentAmountChange();
			this.setListenerForSubmitPaymentButton();

			this.initialized = true;
		};


		/**
		 * @function initializeObjects
		 * Initializes all the required objects.
		 */
		this.initializeObjects = function() {
			this.submitPaymentButton = $(this.submitButtonId);
			this.paymentForm = this.submitPaymentButton.parents('form:first');
			this.paymentFormDiv = $(this.paymentFormId);
			this.paymentMethodSelector = $('#ee-available-payment-method-inputs-squareonsite-lbl');
			this.paymentMethodInfoDiv = $('#spco-payment-method-info-squareonsite');
			this.paymentNonceInput = $('#eea-square-nonce');
			this.paymentVerificationInput = $('#eea-square-sca');
			this.sqCardContainer = $('.sq-card-iframe-container');
			this.txnId = eeaSquareParameters.txnId;
			this.billingForm = $(this.billingFormId);
			this.googlePayButton = this.paymentForm.find(this.googlePayButtonId).first();
			this.applePayButton = this.paymentForm.find(this.applePayButtonId).first();
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

			// Card styling.
			this.darkModeCardStyle = {
				'.input-container.is-focus': {
					borderColor: '#006AFF',
				},
				'.input-container.is-error': {
					borderColor: '#ef2410',
				},
				'.message-text': {
					color: '#999999',
				},
				'.message-icon': {
					color: '#999999',
				},
				'.message-text.is-error': {
					color: '#ef2410',
				},
				'.message-icon.is-error': {
					color: '#ef2410',
				},
				input: {
					backgroundColor: '#2D2D2D',
					color: '#FFFFFF',
					fontFamily: 'helvetica neue, sans-serif',
				},
				'input::placeholder': {
					color: '#A5A5A5',
				},
				'input.is-error': {
					color: '#ef2410',
				},
			};
		};


		/**
		 * @function getTransactionData
		 * Get the transaction data.
		 */
		this.getTransactionData = function() {
			const squareInstance = this;
			var reqData = {
				step: 'payment_options',
				action: 'get_transaction_details_for_gateways',
				selected_method_of_payment: eeaSquareParameters.paymentMethodSlug,
				generate_reg_form: false,
				process_form_submission: false,
				noheader: true,
				ee_front_ajax: true,
				EESID: eei18n.EESID,
				revisit: eei18n.revisit,
				e_reg_url_link: eei18n.e_reg_url_link
			};

			$.ajax({
				type: "POST",
				url: eei18n.ajax_url,
				data: reqData,
				dataType: "json",
				beforeSend: function() {
					squareInstance.spco.do_before_sending_ajax();
				},
				success: function(response) {
					// If we can't get a transaction data we can't set up a checkout.
					if (response['error'] || typeof response['TXN_ID'] == 'undefined' || response['TXN_ID'] == null) {
						return squareInstance.spco.submit_reg_form_server_error();
					}
					// Save transaction data.
					squareInstance.txnData = response;
					// Set the payment amount.
					squareInstance.payAmount = squareInstance.txnData.payment_amount.toFixed(eeaSquareParameters.decimalPlaces);
					// Set the right amount on the button.
					$(squareInstance.submitButtonId).val(
						eeaSquareParameters.payButtonText
						+ ' ' + eeaSquareParameters.currencySign
						+ squareInstance.payAmount
					);

					// Now build the PM form.
					squareInstance.buildSquarePaymentForm();
				},
				error: function() {
					squareInstance.spco.end_ajax();
					return squareInstance.spco.submit_reg_form_server_error();
				}
			});
		};


		/**
		 * @function buildSquarePaymentForm
		 * Sets up the payment for itself, creating the card form etc.
		 */
		this.buildSquarePaymentForm = async function() {
			// Enable the loader.
			this.spco.do_before_sending_ajax();

			// Square "Web Payments" payment method input.
			this.squarePayments = Square.payments(
				eeaSquareParameters.appId,
				eeaSquareParameters.locationId
			);
			// The default payment method.
			try {
				// Initialize the payment method form.
				// The card.
				this.card = await this.initializeCard(this.squarePayments);
				// Card as the default payment method.
				this.paymentMethod = this.card;
			} catch (error) {
				// Got an error. Display and continue.
				this.paymentError(error);
			}

			// Digital Wallet.
			if (eeaSquareParameters.useDigitalWallet === '1') {
				// Using separate 'try' blocks to give both methods a chance to load.
				try {
					// Apple Pay.
					this.applePay = await this.initializeApplePay(this.squarePayments);
					this.applePayButton.style.display = 'inline-block';
				} catch (error) {
					this.paymentError(error);
				}
				try {
					// Google Pay.
					this.googlePay = await this.initializeGooglePay(this.squarePayments);
				} catch (error) {
					this.paymentError(error);
				}
			}

			// Show the Pay button if the payment form generated ok.
			this.submitPaymentButton.show();
			this.spco.end_ajax();
		};


		/**
		 * @function initializeCard
		 * Initializes a Card object for Square payments.
		 */
		this.initializeCard = async function (payments) {
			const card = await payments.card({
				style: this.darkModeCardStyle,
			});
			await card.attach('#sq-card-se');
			return card;
		};


		/**
		 * @function initializeGooglePay
		 * Initializes the Apple Pay object for Square payments.
		 * @param  {object} payments
		 */
		this.initializeGooglePay = async function (payments) {
			const paymentRequest = this.buildPaymentRequest(payments);
			const googlePay = await payments.googlePay(paymentRequest);
			await googlePay.attach(this.googlePayButtonId);
			return googlePay;
		};


		/**
		 * @function initializeApplePay
		 * Initializes the Apple Pay object for Square payments.
		 * @param  {object} payments
		 */
		this.initializeApplePay = async function (payments) {
			const paymentRequest = this.buildPaymentRequest(payments);
			const applePay = await payments.applePay(paymentRequest);
			return applePay;
		};


		/**
		 * @function tokenizePayment
		 * Creates a token for the payment and submits to the server.
		 * @param  {object} paymentMethod
		 */
		this.tokenizePayment = async function(paymentMethod) {
			const tokenResult = await paymentMethod.tokenize();
			let verificationToken;
			if (tokenResult.status === 'OK') {
				// If this is a card payment, do a SCA.
				if (this.doSca) {
					verificationToken = await this.verifyBuyer(this.squarePayments, tokenResult.token);
				}
				this.paymentSuccess(tokenResult.token, tokenResult, verificationToken);
			} else {
				this.paymentError(tokenResult.status);
			}
		};


		/**
		 * @function buildPaymentRequest
		 * Creates a token for the payment and submits to the server.
		 * @param  {object} payments
		 */
		this.buildPaymentRequest = function(payments) {
			return payments.paymentRequest({
				countryCode: eeaSquareParameters.orgCountry,
				currencyCode: eeaSquareParameters.paymentCurrency,
				requestShippingContact: false,
				requestBillingContact: false,
				total: {
					amount: this.payAmount,
					label: eeaSquareParameters.siteName,
					pending: false,
				},
			});
		};


		/**
		 * @function setListenerForPaymentAmountChange
		 * Adds a listener for the payment amount change.
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
		 * Adds a listener for the payment buttons/options.
		 */
		this.setListenerForSubmitPaymentButton = function() {
			const squareInstance = this;
			// Card payment event.
			this.paymentForm.on('submit.eeaSquare', (event) => {
				event.preventDefault();
				this.paymentMethod = this.card;
				this.doSca = true;
				squareInstance.processPayment(event);
			});
			// Google Pay button event.
			this.googlePayButton.on('click.eeaSquare', (event) => {
				event.preventDefault();
				this.paymentMethod = this.googlePay;
				squareInstance.processPayment(event);
			});
			// Apple Pay button event.
			this.applePayButton.on('click.eeaSquare', (event) => {
				event.preventDefault();
				this.paymentMethod = this.applePay;
				squareInstance.processPayment(event);
			});
		};


		/**
		 * @function processPayment
		 * Payment method on click callback.
		 * @param {object} event
		 */
		this.processPayment = function(event) {
			// First validate the form, so that the payment flow is not broken by SPCO form validation.
			if (this.paymentForm.valid()) {
				this.submitPaymentButton.prop('disabled', true).addClass('spco-disabled-submit-btn');
				// In case this was disabled.
				this.spco.allow_enable_submit_buttons = true;
				// Tokenize the payment method.
				this.tokenizePayment(this.paymentMethod);
			} else {
				// Don't enable the SPCO submit button after this message.
				this.spco.allow_enable_submit_buttons = false;
				let notification = this.spco.generate_message_object('', '', eeaSquareParameters.formValidationNotice);
				this.spco.scroll_to_top_and_display_messages(this.paymentMethodInfoDiv, notification, true);
			}
		};


		/**
		 * @function verifyBuyer
		 * Strong customer authentication.
		 * @param {object} payments
		 * @param {string} token
		 */
		this.verifyBuyer = async function(payments, token) {
			const verificationDetails = {
				amount: this.payAmount,
				currencyCode: eeaSquareParameters.paymentCurrency,
				intent: 'CHARGE',
				// Buyer billing details.
				billingContact: {
					addressLines: [
						this.billAddress.val(),
						this.billAddress2.val()
					],
					familyName: this.billLastName.val(),
					givenName: this.billFirstName.val(),
					email: this.billEmail.val(),
					country: this.billCountry.val(),
					phone: this.billPhone.val(),
					region: this.billState.val(),
					city: this.billCity.val(),
				},
			};

			const verificationResults = await payments.verifyBuyer(
				token,
				verificationDetails
			);
			return verificationResults.token;
		}


		/**
		 * @function paymentSuccess
		 * Submits the form and adjusts all the "submit" button properties.
		 * @param  {string} nonce
		 * @param  {object} cardData
		 * @param  {string} verificationToken
		 */
		this.paymentSuccess = function(nonce, cardData, verificationToken) {
			// Enable SPCO submit buttons.
			this.spco.enable_submit_buttons();
			// Save the payment nonce.
			this.paymentNonceInput.val(nonce);
			this.paymentVerificationInput.val(verificationToken);
			this.spco.offset_from_top_modifier = this.offsetFromTopModifier;
			// Hide any return to cart buttons, etc.
			$('.hide-me-after-successful-payment-js').hide();
			// Trigger click event on SPCO "Proceed to Next Step" button.
			this.submitPaymentButton.parents('form:first').find('.spco-next-step-btn').trigger('click');

			// Further actions needed ? Maybe got an error ?
			this.spco.main_container.on('spco_process_response', (event, nextStep, response) => {
				if (! response.success) {
					this.submitPaymentButton.prop('disabled', false).removeClass('spco-disabled-submit-btn');
					this.spco.disable_submit_buttons();
					// Do not enable the submit button.
					this.spco.allow_enable_submit_buttons = false;
				}
			});
		};


		/**
		 * @function paymentError
		 * This re-enables the Pay button, displays and logs the error.
		 * @param  errors
		 */
		this.paymentError = function(errors) {
			let errorsMessage = '';
			// Re-enable the payment button.
			this.submitPaymentButton.prop('disabled', false).removeClass('spco-disabled-submit-btn');
			// Show error in payment form.
			if ($.isArray(errors)) {
				errors.forEach(function (error) {
					errorsMessage += ' ' + error.message;
				});
			} else {
				errorsMessage = errors.toString();
			}
			this.notification = this.spco.generate_message_object(
				'',
				'',
				this.spco.tag_message_for_debugging('squareResponseHandler error', errorsMessage)
			);
			this.logError(errorsMessage);
		};


		/**
		 * @function hideSquare
		 * Simply hides Square PM selector.
		 */
		this.hideSquare = function() {
			this.paymentMethodSelector.hide();
			this.paymentMethodInfoDiv.hide();
		};


		/**
		 * @function disableSPCOSubmitButtonsIfSquareSelected
		 * Deactivate SPCO submit buttons to prevent submitting with no Square token.
		 */
		this.disableSPCOSubmitButtonsIfSquareSelected = function() {
			if (this.selected && this.submitPaymentButton.length > 0) {
				this.spco.disable_submit_buttons();
			}
		};


		/**
		 * @function displayError
		 * This displays and logs the error message.
		 * @param  {string} msg
		 */
		this.displayError = function(msg) {
			// center notices on screen
			$('#espresso-ajax-notices').eeCenter('fixed');
			// target parent container
			const espressoAjaxMsg = $('#espresso-ajax-notices-error');
			//  actual message container
			espressoAjaxMsg.children('.espresso-notices-msg').html(msg);
			// bye bye spinner
			$('#espresso-ajax-loading').fadeOut('fast');
			// display message
			espressoAjaxMsg.removeClass('hidden').show().delay(10000).fadeOut();
			// Log the Error.
			this.logError(msg);
		};


		/**
		 * @function logError
		 * Logs the error in the EE payment method system (Payment Methods >> Logs).
		 * @param  {string} msg
		 */
		this.logError = function(msg) {
			const ajaxUrl = typeof ajaxurl === 'undefined' ? eei18n.ajax_url : ajaxurl;
			console.error(msg);
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
		 * This tears down the current payment methods objects/forms.
		 */
		this.tearDown = function() {
			// Head out if this PM is not initialized anymore.
			if (! this.initialized) {
				return;
			}
			// Also reset the parameters.
			this.initialized = false;
			this.squarePaymentForm = this.card = this.googlePay = this.applePay = {};
			this.doSca = false;
			// Unhook the Square submission hooks, if they were set previously.
			this.removeHook(this.paymentForm, 'submit.eeaSquare');
			this.removeHook(this.googlePayButton, 'click.eeaSquare');
			this.removeHook(this.applePayButton, 'click.eeaSquare');
			if (typeof this.squarePaymentForm === 'object') {
				this.submitPaymentButton.hide();
				// Tear down the form.
				this.destroyElement(this.squarePaymentForm);
				// Destroy the card input.
				this.destroyElement(this.card);
				// Also disassemble the Digital Wallet.
				this.destroyElement(this.googlePay);
				this.destroyElement(this.applePay);
			}
		};


		/**
		 * @function destroyElement
		 * Destroys the provided object, if a destroy() method exists.
		 * @param  {object} element
		 */
		this.destroyElement = function(element) {
			if (typeof element.destroy === 'function') {
				element.destroy();
			}
		}


		/**
		 * @function removeHook
		 * Removes the hook from the provided element.
		 * @param  {object} element
		 * @param  {string} hookName
		 */
		this.removeHook = function(element, hookName) {
			if (typeof element.off === 'function') {
				element.off(hookName);
			}
		}


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
