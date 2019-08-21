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
    const PAYMENT_AUTHORIZED = 'payment.authorized';
    const PAYMENT_FAILED     = 'payment.failed';
	const VA_CREDITED     	 = "virtual_account.credited";	// MA
	const VERBOSE			 = true;

    function __construct()
    {
        $this->razorpay = new WC_Razorpay(false);

        $this->api = $this->razorpay->getRazorpayApiInstance();
		
		$this->verbose = self::VERBOSE;
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
		// 08/21/2019 added break on line 264
		// see if payment contains order number. If so get that order using the order ID.
		// get the details of the payment, virtual account and user associated with that order.
		// If no order mentioned get all orders on-hold va-bacs for this user associated with this VA
		// for each order check payment amounts and dates. If they tally, set status to processing
		
		//( $data['payload']['payment']['entity']['refund_status'] 	? return : false);
		//( $data['payload']['payment']['entity']['error_code'] 		? return : false);
		//(!$data['payload']['payment']['entity']['captured'] 		? return : false);
		
		$razorpayPaymentId	= $data['payload']['payment']['entity']['id'];
		if ($this->verbose) {
							error_log(print_r('webhook payment_ID: ' , true));
							error_log(print_r($razorpayPaymentId , true));
						}
		
		$payment_amount_p	= $data['payload']['payment']['entity']['amount']; // in paisa
		if ($this->verbose) {
							error_log(print_r('webhook payment amount in Paise: ' , true));
							error_log(print_r($payment_amount_p , true));
						}
		
		$payment_timestamp	= $data['payload']['payment']['entity']['created_at'];
		if ($this->verbose) {
							error_log(print_r('webhook payment timestamp: ' , true));
							error_log(print_r($payment_timestamp , true));
						}
		
		$payment_date		= date('Y/m/d H:i:s', $payment_timestamp);
		// with this payment ID get the VA payment
		
		$payment_description	= $data['payload']['payment']['entity']['description'];
		if ($this->verbose) {
							error_log(print_r('webhook payment description: ' , true));
							error_log(print_r($payment_description , true));
						}
		
		$va_payment 		= $this->getVaPaymentEntity($razorpayPaymentId, $data);
		if ($this->verbose) {
							error_log(print_r('webhook payment object: ' , true));
							error_log(print_r($va_payment , true));
						}
		// with this payment entity, get the associated VA ID
		$va_id				= $va_payment['virtual_account']['id'];
		if ($this->verbose) {
							error_log(print_r('webhook payment VA ID: ' , true));
							error_log(print_r($va_id , true));
						}
		
		$sritoni_id			= $va_payment['virtual_account']['notes']['idnumber'];
		if ($this->verbose) {
							error_log(print_r('webhook payment sritoni ID: ' , true));
							error_log(print_r($sritoni_id , true));
						}
		// get the WP userid derived from the VA of the webhook				
		$webhook_derived_userid			= $va_payment['virtual_account']['notes']['id'];
		if ($this->verbose) {
							error_log(print_r('webhook payment WP user ID: ' , true));
							error_log(print_r($webhook_derived_userid , true));
						}
		
		$bank_reference		= $va_payment['bank_reference'];
		if ($this->verbose) {
							error_log(print_r('webhook payment bank reference: ' , true));
							error_log(print_r($bank_reference , true));
						}
		
		// added this segment 08/21/2019----------------------------------------------
		// get all orders not 'on-hold' with payment method va-bacs, to check for webhook payment_id already accounted for or not
		$args = array(
						'status' 			=> array(
														'processing',
														'complete',
													),
						'payment_method' 	=> 'vabacs',
						'customer_id'		=> $webhook_derived_userid,
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
		// get all orders on-hold using va-bacs payment method
		$args = array(
						'status' 			=> 'on-hold',
						'payment_method' 	=> 'vabacs',
					 );
		$orders = wc_get_orders( $args );
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
					
					if ($this->verbose) {
						error_log(print_r('payment date is after order date:' . ( strtotime($payment_date) > strtotime($order_creation_date) ), true));
					}
					
					if ( strtotime($payment_date) > strtotime($order_creation_date) )
					{
						// we have folowing conditions satisfied for a valid match of payment to order
						// 1. sritoni_id's match between webhood order and user's order on-hold
						// 2. Order amounts match
						// 3. Order is on-hold pending payment
						// 4. Payment method is vabacs
						// 5. Payment received only after order placed
						// TODO check that this payment ID has not already been reconciled against an order
						$order_note = 'Payment received by Razorpay Virtual Account ID: ' . $va_id .
					              ' Payment ID: ' . $razorpayPaymentId . '  on: ' . date('Y/m/d', $payment_timestamp) .
					              ' Payment description: ' . $payment_description . ' bank reference: ' . $bank_reference;
						// we found a payment that matches our condition so we add an order note so store manager can see
						$order->add_order_note($order_note);
						// we append the VA payment ID to the payment method of the order


						$order->update_meta_data('va_payment_id', $razorpayPaymentId);
						$order->update_meta_data('amount_paid_by_va_payment', $payment_amount_p * 0.01);  // in Rs
						$order->update_meta_data('bank_reference', $bank_reference);
						$order->update_meta_data('payment_notes_by_customer', $payment_description);
						$order->save;

						$transaction_id = $razorpayPaymentId . "," . $payment_date . "," . $va_id . 
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
