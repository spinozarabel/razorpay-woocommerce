<?php

require_once __DIR__.'/../woo-razorpay.php';
require_once __DIR__.'/../razorpay-sdk/Razorpay.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

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
	const VA_CREDITED     	 = "virtual_account.credited";  		// MA

    function __construct()
    {
        $this->razorpay = new WC_Razorpay(false);

        $this->api = $this->razorpay->getRazorpayApiInstance();
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
                        return $this->paymentAuthorized($data);

                    case self::PAYMENT_FAILED:
                        return $this->paymentFailed($data);
						
					case self::VA_CREDITED:
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
		// see if payment contains order number. If so get that order using the order ID.
		// get the details of the payment, virtual account and user associated with that order.
		// If no order mentioned get all orders on-hold va-bacs for this user associated with this VA
		// for each order check payment amounts and dates. If they tally, set status to processing
		
		( $data['payload']['payment']['entity']['refund_status'] 	? return : false);
		( $data['payload']['payment']['entity']['error_code'] 		? return : false);
		(!$data['payload']['payment']['entity']['captured'] 		? return : false);
		
		$razorpayPaymentId	= $data['payload']['payment']['entity']['id'];
		error_log(print_r('webhook payment_ID:' . $razorpayPaymentId, true));
		
		$payment_amount_p	= $data['payload']['payment']['entity']['amount']; // in paisa
		error_log(print_r('webhook payment amount in Paise:' . $payment_amount_p, true));
		
		$payment_timestamp	= $data['payload']['payment']['entity']['created_at'];
		error_log(print_r('webhook payment timestamp:' . $payment_timestamp, true));
		
		$payment_date		= date('Y/m/d H:i:s', $payment_timestamp);
		// with this payment ID get the VA payment
		
		$payment_description	= $data['payload']['payment']['entity']['description'];
		error_log(print_r('webhook payment description:' . $payment_description, true));
		
		$va_payment 		= $this->getVaPaymentEntity($razorpayPaymentId, $data);
		error_log(print_r($va_payment, true));
		// with this payment entity, get the associated VA ID
		$va_id				= $va_payment['virtual_account']['id'];
		error_log(print_r('webhook payment VA ID:' . $va_id, true));
		
		$sritoni_id			= $va_payment['virtual_account']['notes']['idnumber'];
		error_log(print_r('webhook payment sritoni ID:' . $sritoni_id, true));
		
		$bank_reference		= $va_payment['bank_reference'];
		error_log(print_r('webhook payment bank reference:' . $bank_reference, true));
		
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
			error_log(print_r('webhook order sritoni_id:' . get_user_meta( $user_id, 'nickname', true ), true));
			
			error_log(print_r('webhook order amount in paise:' . round($order->get_total() * 100), true));
			
			// get user meta nickname holding our sritoni ID
			if ( $sritoni_id == get_user_meta( $user_id, 'nickname', true ) )
			{
				// this is a plausible order. Still, do the amounts match?
				if ( $payment_amount_p == round($order->get_total() * 100) )
				{
					// one last check, is payment date after order date?
					$order_creation_date = $order->get_date_created();
					
					error_log(print_r('payment date is after order date:' . ( strtotime($payment_date) > strtotime($order_creation_date) ), true));
					
					if ( strtotime($payment_date) > strtotime($order_creation_date) )
					{
						// we have folowing conditions satisfied here:
						// 1. sritoni_id's match
						// 2. Odre amounts match
						// 3. Order is on-hold pending payment
						// 4. Payment method is vabacs
						$order_note = 'Payment received by Razorpay Virtual Account ID: ' . $va_id .
					              ' Payment ID: ' . $razorpayPaymentId . '  on: ' . date('Y/m/d', $payment_timestamp) .
					              ' Payment description: ' . $payment_description . ' bank reference: ' . $bank_reference;
						// we found a payment that matches our condition so we add an order note so store manager can see
						$order->add_order_note($order_note);
						// we append the VA payment ID to the payment method of the order


						$order->update_meta_data('va_payment_id', $last_payment_id);
						$order->save;

						$transaction_id = $razorpayPaymentId . "," . $payment_date . "," . $va_id . 
										"," . $bank_reference;
						$order->payment_complete($transaction_id);
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
            $payment = $this->api->payment->bankTransfer($razorpayPaymentId);
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
