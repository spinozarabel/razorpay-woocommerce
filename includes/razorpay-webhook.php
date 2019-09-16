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
	const VA_CREDITED     	 		= 'virtual_account.credited';	// MA
	const VERBOSE			 		= true;							// MA
	const TIMEZONE					= 'Asia/Kolkata';				// MA

    function __construct()
    {
        $this->razorpay = new WC_Razorpay(false);

        $this->api 		= $this->razorpay->getRazorpayApiInstance();

		$this->verbose	= self::VERBOSE;

		$this->timezone =  new DateTimeZone(self::TIMEZONE);		// MA returns 'Asia/Kolkata'
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

		$payment_obj				= (object) $data['payload']['payment']['entity'];			// from webhook
		$va_obj						= (object) $data['payload']['virtual_account']['entity'];	// from webhook

		// get payment details based on payment ID extracted
		$details_obj		= (object) $this->getVaPaymentEntity($payment_obj->id, $data);

		$payment_datetime	= new DateTime('@' . $payment_obj->created_at);
		$payment_datetime->setTimezone($this->timezone);

		// get the user details based on WP username that should be same as Moodle userid (not ID Number)
		$wp_user 			= get_user_by('login', $va_obj->notes['id']);       // get user by login (same as Moodle userid in User tables)
		$wp_userid 			= $wp_user->ID ?? "web_hook_wpuser_not_found";      // get WP user ID

		// log all extracted data if verbose
		($this->verbose ? $this->logData($payment_obj, $va_obj, $details_obj, $payment_datetime, $wp_userid) : false);

		// try reconciliation using any order information in payment description entered by customer
		$reconciledOrder = $this->reconcileOrderUsingPaymentInfo($payment_obj, $wp_userid, $payment_datetime);

		if ( !empty($reconciledOrder) )
			{
				$this->orderUpdateMetaSetCompleted($reconciledOrder, $payment_obj, $va_obj, $details_obj, $payment_datetime, $wp_userid);
				return; // done, exit out of webhook processing
			}
		// if not we follow the old method of reconciliation by checking all open orders
		// Is this payment already reconciled?	If so webhook redundant, exit
		if ( $this->anyReconciledOrders($payment_obj->id, $wp_userid)  )
			{
				return;
			}

		// If we have reached this far, webhook data is fresh and so let's reconcile webhook payment against any valid open on-hold vabacs order
		$open_orders = $this->getOpenOrders($wp_userid);

		// if null exit, there are no open orders for this user to reconcile
		if ( $open_orders == null )
			{
				return;
			}
		else
			{
				// we do have open orders for this user, so lets see if we can reconcile the webhook payment to one of these open orders
				$reconciledOrder = $this->reconcileOrder($open_orders, $payment_obj, $va_obj, $details_obj, $payment_datetime, $wp_userid);
				// if reconciled order is null then exit
				if ( $reconciledOrder == null )
					{
						return;
					}
				// If we got here, we must have a $reconcileOrder, lets update the meta and status
				$this->orderUpdateMetaSetCompleted($reconciledOrder, $payment_obj, $va_obj, $details_obj, $payment_datetime, $wp_userid);
				return;
			}

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

	/**
     * Log data extracted from webhook
     */
    protected function logData($payment_obj, $va_obj, $details_obj, $payment_datetime, $wp_userid)
    {
        error_log(print_r('webhook payment object: ' , true));
		error_log(print_r($payment_obj , true));

		error_log(print_r('webhook VA object: ' , true));
		error_log(print_r($va_obj , true));

		error_log(print_r('webhook Payment Details Object: ' , true));
		error_log(print_r($details_obj , true));

		error_log(print_r('webhook payment_ID: ' , true));
		error_log(print_r($payment_obj->id , true));

		error_log(print_r('webhook payment amount in Paise: ' , true));
		error_log(print_r($payment_obj->amount , true));

		error_log(print_r('webhook payment timestamp: ' , true));
		error_log(print_r($payment_obj->created_at , true));

		error_log(print_r('webhook payment date(IST): ' , true));
		error_log(print_r($payment_datetime->format('Y-m-d H:i:s') , true));

		error_log(print_r('webhook payment user entered description: ' , true));
		error_log(print_r($payment_obj->description , true));

		error_log(print_r('webhook payment VA ID: ' , true));
		error_log(print_r($va_obj->id , true));

		error_log(print_r('webhook payment sritoni ID: ' , true));
		error_log(print_r($va_obj->notes['idnumber'] , true));

		error_log(print_r('webhook payment WP username: ' , true));
		error_log(print_r($va_obj->notes['id'] , true));

		error_log(print_r('webhook user WP userID ' , true));
		error_log(print_r($wp_userid , true));

		error_log(print_r('webhook payment bank reference: ' , true));
		error_log(print_r($details_obj->bank_reference , true));

		return;
    }

	/**
     * Gets any orders that maybe already reconciled with this payment
	 * return false if if no reconciled orders already exist for this webhook payment
	 * return true if this payment is alreay present in an existing completed / processsing order
     */
    protected function anyReconciledOrders($payment_id, $wp_userid)
    {
        $args = array(
						'status' 			=> array(
														'processing',
														'completed',
													),
						'limit'				=> 1,			// at least one order exists for this payment?
						'payment_method' 	=> 'vabacs',
						'customer_id'		=> $wp_userid,
						'meta-key'			=> "va_payment_id",
						'meta_value'		=> $payment_id,
					 );
		$orders_completed = wc_get_orders( $args ); // these are orders for this user either processing or completed

		if (!empty($orders_completed))
		{	// we already have completed orders that reconcile this payment ID so this webhook must be old or redundant, so quit
			if ($this->verbose)
			{
				error_log(print_r('Following orders already completed using this payment_id:' . $payment_id, true));
				foreach ($orders_completed as $order)
						{
							error_log(print_r('Order No: ' . $order->get_id() , true));
						}
			}
			// true, reconciled eorders exist
			return true;
		}
		// false, reconciled orders don't exist for this payment
		return false;
    }

	/**
     * returns any open orders for this user
     */
    protected function getOpenOrders($wp_userid)
    {
        $args = array(
						'status' 			=> 'on-hold',
						'payment_method' 	=> 'vabacs',
						'customer_id'		=> $wp_userid,
					 );
		$orders = wc_get_orders( $args );

		if (empty($orders))
			{	// No orders exist for this webhook payment ID, log that fact and return null
				if ($this->verbose)
					{
						error_log(print_r('No Orders on-hold for this user so cannot reconcile this webhook payment', true));
					}
				return null;
			}
		// we have valid open orders for this user
		if ($this->verbose)
					{
						foreach ($orders as $order)
						{
							error_log(print_r('Order No: ' . $order->get_id() . ' Open for this user', true));
                            error_log(print_r('Lets see if the above order can be reconciled with this webhook payment', true));
						}
					}
		return $orders;
    }

	/**
     * take all open orders and see if they can be reconciled against webhook payment
	 * Order is reconciled if:
	 * 1. Payments are same
	 * 2. Payment date is after Order creation date
	 * 3. Order user is same as user associated with payment: (This was already in the query for wc_get_orders)
	 * 4. Order is on-hold: (This was already in the query for wc_get_orders)
	 * 5. Payment method is VABACS: (This was already in the query for wc_get_orders)
	 * return null or reconciled order object
     */
    protected function reconcileOrder($orders, $payment_obj, $va_obj, $details_obj, $payment_datetime, $wp_userid)
    {
        foreach ($orders as $key => $order)
		{
			$order_creation_datetime		= new DateTime( '@' . $order->get_date_created()->getTimestamp());
			$order_creation_datetime->setTimezone($this->timezone);

			if 	(
					( $payment_obj->amount == round($order->get_total() * 100) ) 	&&		// payment amount matches order amount in paise
					( $payment_datetime > $order_creation_datetime )						// payment is after order creation
				)
			{
				// we satisfy all conditions, this order reconciles with the webhook payment
                if ($this->verbose)
                {
                    error_log(print_r('Order No: ' . $order->get_id() . ' is reconcilable with webhook payment', true));
                }
				return $order;
			}
			else
			{
				// This order does not reconcile with our webhook payment so check for next order in loop`
				continue;
			}

		}
		// we have checked all orders and none can be reconciled with our webhook payment
		return null;
    }

	/**
     * Updates Meta of Reconciled Order and changes its status to completed
     */
    protected function orderUpdateMetaSetCompleted($order, $payment_obj, $va_obj, $details_obj, $payment_datetime, $wp_userid)
    {
		$order_note = 'Payment received by Razorpay Virtual Account ID: ' . $va_obj->id .
					              ' Payment ID: ' . $payment_obj->id . '  on: ' . $payment_datetime->format('Y-m-d H:i:s') .
					              ' Payment description: ' . $payment_obj->description . ' bank reference: ' . $details_obj->bank_reference;

		$order->add_order_note($order_note);

		$order->update_meta_data('va_payment_id', $payment_obj->id);
		$order->update_meta_data('amount_paid_by_va_payment', $payment_obj->amount * 0.01);  // in Rs
		$order->update_meta_data('bank_reference', $details_obj->bank_reference);
		$order->update_meta_data('payment_notes_by_customer', $payment_obj->description);
		$order->save;

		$transaction_arr	= array(
										'payment_id'		=> $payment_obj->id,
										'payment_date'		=> $payment_datetime->format('Y-m-d H:i:s'),
										'va_id'				=> $va_obj->id,
										'bank_reference'	=> $details_obj->bank_reference,
									);

		$transaction_id = json_encode($transaction_arr);

		$order->payment_complete($transaction_id);

		if ($this->verbose) {
			error_log(print_r('Order:' . $order->get_id() .
                    ' status payment complete, meta updated with Webhook payment:' . $transaction_id, true));
		}

        return true;
    }

	/*
	*  extracts order no if any, present on the payment description as entered by payer
	*  If the payment is valid and amounts and dates are reconciled then this order is considered reconcilable
	   The function either returns null or the the reconciled order
	*/
	protected function reconcileOrderUsingPaymentInfo($payment_obj, $wp_userid, $payment_datetime)
	{
		// extract payment information from payment object
		$str = $payment_obj->description;
		if ( empty($str)  )
			{
				error_log(print_r('payment description: ' . $str , true));
				return null;
			}
		$str = str_replace(array('+','-'), '', $str);
		$orderIdInPayment = abs((int) filter_var($str, FILTER_SANITIZE_NUMBER_INT));

		// see if an order exists with this order number and with necessary other details
		$order = wc_get_order($orderIdInPayment);
		// return if order doesn't exist and reconcilde using usual way
		if ( empty($order) )
			{
				error_log(print_r('Extracted order ID from payment description: ' . $orderIdInPayment , true));
				error_log(print_r('wc_get_order object was empty, so returning' , true));
				return null;
			}
		// so we ow have a valid order although we don;t know if amounts and dates are compatible so lets check.
		$order_creation_datetime		= new DateTime( '@' . $order->get_date_created()->getTimestamp());
		$order_creation_datetime->setTimezone($this->timezone);

		if 	(
					( $payment_obj->amount == round($order->get_total() * 100) ) 	&&		// payment amount matches order amount in paise
					( $payment_datetime > $order_creation_datetime )						// payment is after order creation
																						)
			{
				// we satisfy all conditions, this order reconciles with the webhook payment
				if ($this->verbose)
					{
						error_log(print_r('Reconciled order No: ' . $order->get_id() . ' using Order number in payment description', true));
						error_log(print_r($payment_obj , true));
					}
				return $order;
			}
		// even though we could get some order based on payment description of payer, this is  not reconcilable so return null
		return null;

	}

}
