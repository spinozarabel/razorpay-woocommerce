<?php

require_once __DIR__.'/../woo-razorpay.php';
require_once __DIR__.'/../razorpay-sdk/Razorpay.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

$verbose = true;

class RZP_Webhook
{
    /**
     * Instance of the razorpay payments class
     * @var WC_Razorpay
     */
    protected $razorpay;

    /**
     * API client instance to communicate with Razorpay API
     * @var Razorpay\Api\Api
     */
    protected $api;

    /**
     * Event constants
     */
    const PAYMENT_AUTHORIZED 		= 'payment.authorized';
    const PAYMENT_FAILED     		= 'payment.failed';
	const SUBSCRIPTION_CANCELLED    = 'subscription.cancelled';
	const VA_CREDITED     	 		= "virtual_account.credited";	// MA
	const VERBOSE			 		= true;							// MA
	const TIMEZONE					= "Asia/Kolkata";				// MA

    function __construct()
    {
        $this->razorpay = new WC_Razorpay(false);

        $this->api 		= $this->razorpay->getRazorpayApiInstance();

		$this->verbose	= self::VERBOSE;
		
		$this->timezone = self::TIMEZONE;							// MA
    }

    /**
     * Process a Razorpay Webhook. We exit in the following cases:
     * - Successful processed
     * - Exception while fetching the payment
     *
     * It passes on the webhook in the following cases:
     * - invoice_id set in payment.authorized
     * - Invalid JSON
     * - Signature mismatch
     * - Secret isn't setup
     * - Event not recognized
     */
    public function process()
    {
        $post = file_get_contents('php://input');

        $data = json_decode($post, true);

        if (json_last_error() !== 0)
        {
            return;
        }

        $enabled = $this->razorpay->getSetting('enable_webhook');

        if (($enabled === 'yes') and
            (empty($data['event']) === false))
        {
            if (isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']) === true)
            {
                $razorpayWebhookSecret = $this->razorpay->getSetting('webhook_secret');

                //
                // If the webhook secret isn't set on wordpress, return
                //
                if (empty($razorpayWebhookSecret) === true)
                {
                    return;
                }

                try
                {
                    $this->api->utility->verifyWebhookSignature($post,
                                                                $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'],
                                                                $razorpayWebhookSecret);
                }
                catch (Errors\SignatureVerificationError $e)
                {
                    $log = array(
                        'message'   => $e->getMessage(),
                        'data'      => $data,
                        'event'     => 'razorpay.wc.signature.verify_failed'
                    );

                    error_log(json_encode($log));
                    return;
                }

                switch ($data['event'])
                {
                    case self::PAYMENT_AUTHORIZED:
                        if ($this->verbose) {
							error_log(print_r('webhook event type: ' , true));
							error_log(print_r($data['event'] , true));
						}
						return $this->paymentAuthorized($data);

                    case self::PAYMENT_FAILED:
						if ($this->verbose) {
							error_log(print_r('webhook event type: ' , true));
							error_log(print_r($data['event'] , true));
						}
                        return $this->paymentFailed($data);

					case self::VA_CREDITED:
                        if ($this->verbose) {
							error_log(print_r('webhook event type: ' , true));
							error_log(print_r($data['event'] , true));
						}
						return $this->vaCredited($data);  // new class function defined by MA

                    case self::SUBSCRIPTION_CANCELLED:
                        return $this->subscriptionCancelled($data);

                    default:
                        return;
                }
            }
        }
    }

    /**
     * Does nothing for the main payments flow currently
     * @param array $data Webook Data
     */
    protected function paymentFailed(array $data)
    {
        return;
    }

	/**
     * Handling the virtual acount credited webhook
     *
     * @param array $data Webook Data
     */
    protected function vaCredited(array $data)
    {
		$timezone 	= new DateTimeZone($this->timezone);
		
		$obj		= $data['payload']['payment']['entity'];	// convert associative array to object

		$payment_datetime	= new DateTime('@' . $data['payload']['payment']['entity']['created_at']);
		$payment_datetime->setTimezone($timezone);

		// get payment details using the webhook payment ID
		$va_payment 		= $this->getVaPaymentEntity($obj->id, $data);

		// with this payment entity, get the associated VA ID
		$va_id				= $va_payment['virtual_account']['id'];

		$sritoni_id			= $va_payment['virtual_account']['notes']['idnumber'];

		// get the WP username from the VA notes. Note that this is the Moodle ID, not the WP userid
		$webhook_wp_username = $va_payment['virtual_account']['notes']['id'];

		$bank_reference		= $va_payment['bank_reference'];

		// get the user details based on username
		$webhook_wp_user = get_user_by('login', $webhook_wp_username);
		$webhook_wp_userid = $webhook_wp_user->ID ?? "web_hook_user_not_found";
		// log all extracted data if verbose
		if ($this->verbose) {
							error_log(print_r('webhook payment_ID: ' , true));
							error_log(print_r($razorpayPaymentId , true));
							
							error_log(print_r('webhook payment amount in Paise: ' , true));
							error_log(print_r($payment_amount_p , true));
							
							error_log(print_r('webhook payment timestamp: ' , true));
							error_log(print_r($data['payload']['payment']['entity']['created_at'] , true));
							
							error_log(print_r('webhook payment date: ' , true));
							error_log(print_r($payment_datetime->format('Y-m-d H:i:s') , true));
							
							error_log(print_r('webhook payment description: ' , true));
							error_log(print_r($payment_description , true));
							
							error_log(print_r('webhook payment object: ' , true));
							error_log(print_r($va_payment , true));
							
							error_log(print_r('webhook payment VA ID: ' , true));
							error_log(print_r($va_id , true));
							
							error_log(print_r('webhook payment sritoni ID: ' , true));
							error_log(print_r($sritoni_id , true));
							
							error_log(print_r('webhook payment WP username: ' , true));
							error_log(print_r($webhook_wp_username , true));
							
							error_log(print_r('webhook payment bank reference: ' , true));
							error_log(print_r($bank_reference , true));
							
							error_log(print_r('webhook user WP userID ' , true));
							error_log(print_r($webhook_wp_userid , true));
						}
		// get all orders not 'on-hold' with payment method va-bacs, to check for webhook payment_id already accounted for or not
		$args = array(
						'status' 			=> array(
														'processing',
														'completed',
													),
						'payment_method' 	=> 'vabacs',
						'customer_id'		=> $webhook_wp_userid,
						'meta_value'		=> $razorpayPaymentId,
					 );
		$orders_completed = wc_get_orders( $args ); // these are orders for this user either processing or complete
		if (!empty($orders_completed))
		{	// we already have completed orders that reconcile this payment ID so this webhook must be old or redundant, so quit
			if ($this->verbose)
			{
				error_log(print_r('orders_completed using this payment_id:' . $razorpayPaymentId, true));
				error_log(print_r($orders_completed, true));
			}
			return;
		}
		// end of segment added 08/21/2019---------------------------------------------
		//
		// Now the webhook data is fresh and so let's reconcile against open on-hold vabacs order
		// get all orders on-hold using va-bacs payment method, for this wpuser whose ID is derived from webhook
		// At most there should be only a few orders
		$args = array(
						'status' 			=> 'on-hold',
						'payment_method' 	=> 'vabacs',
						'customer_id'		=> $webhook_wp_userid,
					 );
		$orders = wc_get_orders( $args );

		if (empty($orders))
		{	// No orders exist for this webhook payment, log that fact and exit
			if ($this->verbose)
			{
				error_log(print_r('No Orders on-hold for user with sritoniID : ' . $sritoni_id .
									'so cannot reconcile this payment_id:' . $razorpayPaymentId, true));
			}
			return;
		}
		// we do have open orders for this user, so lets see if we can reconcile the webhook payment to one of these open orders
		foreach ($orders as $key => $order)
		{
			// get WP user id associated with order
			$user_id = $order->get_user_id();
			if ($this->verbose) {
				error_log(print_r('webhook order sritoni_id:' . get_user_meta( $user_id, 'nickname', true ), true));
				error_log(print_r('webhook order amount in paise:' . round($order->get_total() * 100), true));
			}

			// get user meta nickname holding our sritoni ID
			if ( $sritoni_id == get_user_meta( $user_id, 'nickname', true ) )
			{
				// Users match. Still, do the amounts match?
				if ( $payment_amount_p == round($order->get_total() * 100) )
				{
					// amounts match, is payment date after order date?
					$order_creation_date = $order->get_date_created();
					
					$order_creation_datetime		= new DateTime( '@' . $order->get_date_created()->getTimestamp());
					$order_creation_datetime->setTimezone($timezone);
					
					$order_number	= $order->get_order_number();
					// is this order number contained in the payment description? check?

					if ($this->verbose) {
						error_log(print_r('payment date is after order date:' . ($payment_datetime > $order_creation_datetime), true));
						
						error_log(print_r('orders_number:' . $order_number, true));
						error_log(print_r('strpos of order number in payment description:' . strpos($payment_description, $order_number), true));
					}

					if ($payment_datetime > $order_creation_datetime)
					{
						// we have folowing conditions satisfied for a valid match of payment to order
						// 1. sritoni_id's match between webhood order and user's order on-hold
						// 2. Order amounts match
						// 3. Order is on-hold pending payment
						// 4. Payment method is vabacs
						// 5. Payment received only after order placed
						// 
						
						$order_note = 'Payment received by Razorpay Virtual Account ID: ' . $va_id .
					              ' Payment ID: ' . $razorpayPaymentId . '  on: ' . $payment_datetime->format('Y-m-d H:i:s') .
					              ' Payment description: ' . $payment_description . ' bank reference: ' . $bank_reference;
						// we found a payment that matches our condition so we add an order note so store manager can see
						$order->add_order_note($order_note);
						// we append the VA payment ID to the payment method of the order


						$order->update_meta_data('va_payment_id', $razorpayPaymentId);
						$order->update_meta_data('amount_paid_by_va_payment', $payment_amount_p * 0.01);  // in Rs
						$order->update_meta_data('bank_reference', $bank_reference);
						$order->update_meta_data('payment_notes_by_customer', $payment_description);
						$order->save;

						$transaction_id = $razorpayPaymentId . "," . $payment_datetime->format('Y-m-d H:i:s') . "," . $va_id .
										"," . $bank_reference;
						$order->payment_complete($transaction_id);
						if ($this->verbose) {
							error_log(print_r('Order completed and updated meta:' . $transaction_id, true));
						}
						break;	// we have found our order and reconciled it against webhook. No need to look further, get out of loop
					}

				}
			}

		}

	return;

	}

    /**
     * Does nothing for the main payments flow currently
     * @param array $data Webook Data
     */
    protected function subscriptionCancelled(array $data)
    {
        return;
    }


    /**
     * Handling the payment authorized webhook
     *
     * @param array $data Webook Data
     */
    protected function paymentAuthorized(array $data)
    {
        // We don't process subscription/invoice payments here
        if (isset($data['payload']['payment']['entity']['invoice_id']) === true)
        {
            return;
        }

        //
        // Order entity should be sent as part of the webhook payload
        //
        $orderId = $data['payload']['payment']['entity']['notes']['woocommerce_order_id'];

        $order = new WC_Order($orderId);

        // If it is already marked as paid, ignore the event
        if ($order->needs_payment() === false)
        {
            return;
        }

        $razorpayPaymentId = $data['payload']['payment']['entity']['id'];

        $payment = $this->getPaymentEntity($razorpayPaymentId, $data);

        $amount = $this->getOrderAmountAsInteger($order);

        $success = false;
        $errorMessage = 'The payment has failed.';

        if ($payment['status'] === 'captured')
        {
            $success = true;
        }
        else if (($payment['status'] === 'authorized') and
                 ($this->razorpay->getSetting('payment_action') === WC_Razorpay::CAPTURE))
        {
            //
            // If the payment is only authorized, we capture it
            // If the merchant has enabled auto capture
            //
            try
            {
                $payment->capture(array('amount' => $amount));

                $success = true;
            }
            catch (Exception $e)
            {
                //
                // Capture will fail if the payment is already captured
                //
                $log = array(
                    'message'         => $e->getMessage(),
                    'payment_id'      => $razorpayPaymentId,
                    'event'           => $data['event']
                );

                error_log(json_encode($log));

                //
                // We re-fetch the payment entity and check if the payment is captured now
                //
                $payment = $this->getPaymentEntity($razorpayPaymentId, $data);

                if ($payment['status'] === 'captured')
                {
                    $success = true;
                }
            }
        }

        $this->razorpay->updateOrder($order, $success, $errorMessage, $razorpayPaymentId, true);

        // Graceful exit since payment is now processed.
        exit;
    }

    protected function getPaymentEntity($razorpayPaymentId, $data)
    {
        try
        {
            $payment = $this->api->payment->fetch($razorpayPaymentId);
        }
        catch (Exception $e)
        {
            $log = array(
                'message'         => $e->getMessage(),
                'payment_id'      => $razorpayPaymentId,
                'event'           => $data['event']
            );

            error_log(json_encode($log));

            exit;
        }

        return $payment;
    }

	protected function getVaPaymentEntity($razorpayPaymentId, $data)
    {
        try
        {
            $payment = $this->api->payment->fetch($razorpayPaymentId)->bankTransfer();
        }
        catch (Exception $e)
        {
            $log = array(
                'message'         => $e->getMessage(),
                'payment_id'      => $razorpayPaymentId,
                'event'           => $data['event']
            );

            error_log(json_encode($log));

            exit;
        }

        return $payment;
    }

    /**
     * Returns the order amount, rounded as integer
     * @param WC_Order $order WooCommerce Order instance
     * @return int Order Amount
     */
    public function getOrderAmountAsInteger($order)
    {
        if (version_compare(WOOCOMMERCE_VERSION, '3.0.0', '>='))
        {
            return (int) round($order->get_total() * 100);
        }

        return (int) round($order->order_total * 100);
    }
}
