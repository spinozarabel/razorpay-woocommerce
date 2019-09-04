<?php

require_once __DIR__.'/../woo-razorpay.php';
require_once __DIR__.'/../razorpay-sdk/Razorpay.php';


use Razorpay\Api\Api;
use Razorpay\Api\Errors;

class RZP_Webhook_ma extends RZP_Webhook
{
    /**
     * Event constants
     */
	 
	const VA_CREDITED     	 		= "virtual_account.credited";	// MA
	const VERBOSE			 		= true;							// MA
	const TIMEZONE					= "Asia/Kolkata";				// MA

    function __construct()
    {
		parent::__construct();
		
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
	 * - We are overriding this function in the parent class by adding case for virtual_account.credited
     */
    public function process()		// override function in parent class
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

    
    protected function vaCredited(array $data)		// new function defined for this child class
    {
		
		$payment_obj				= (object) $data['payload']['payment']['entity'];			// from webhook
		$va_obj						= (object) $data['payload']['virtual_account']['entity'];	// from webhook
		
		// get payment details based on payment ID extracted
		$details_obj		= (object) $this->getVaPaymentEntity($payment_obj->id, $data);

		$payment_datetime	= new DateTime('@' . $payment_obj->created_at);
		$payment_datetime->setTimezone($this->timezone);

		// get the user details based on WP username that should be same as Moodle userid (not ID Number)
		$wp_user 			= get_user_by('login', $va_obj->notes['id']);		// get user by login (same as Moodle userid in User tables)
		$wp_userid 			= $wp_user->ID ?? "web_hook_wpuser_not_found";	// get WP user ID
		
		// log all extracted data if verbose
		($this->verbose ? $this->logData($payment_obj, $va_obj, $details_obj, $payment_datetime, $wp_userid) : false);

		// Is this payment already reconciled?	If so webhook redundant, exit	
		if ( $this->anyReconciledOrders($payment_obj->id, $wp_userid) == true )
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
		
		// we do have open orders for this user, so lets see if we can reconcile the webhook payment to one of these open orders
		$reconciledOrder	= $this->reconcileOrder($open_orders, $payment_obj, $va_obj, $details_obj, $payment_datetime, $wp_userid);
		// if reconciled order is null then exit
		if ( $reconciledOrder == null )
			{
				return;
			}
		// Update reconciled order's meta with webhook payment details and change order status to completed
		$this->orderUpdateMetaSetCompleted($reconciledOrder, $payment_obj, $va_obj, $details_obj, $payment_datetime, $wp_userid);

	return;

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
     */
    protected function anyReconciledOrders($payment_id, $wp_userid)
    {
        $args = array(
						'status' 			=> array(
														'processing',
														'completed',
													),
						'payment_method' 	=> 'vabacs',
						'customer_id'		=> $wp_userid,
						'meta_value'		=> $payment_id,
					 );
		$orders_completed = wc_get_orders( $args ); // these are orders for this user either processing or completed
		
		if (!empty($orders_completed))
		{	// we already have completed orders that reconcile this payment ID so this webhook must be old or redundant, so quit
			if ($this->verbose)
			{
				error_log(print_r('orders already completed using this payment_id:' . $payment_id, true));
				error_log(print_r($orders_completed, true));
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

		$transaction_id = $payment_obj->id . "," . $payment_datetime->format('Y-m-d H:i:s') . "," . $va_obj->id .
						"," . $details_obj->bank_reference;
		$order->payment_complete($transaction_id);
		
		if ($this->verbose) {
			error_log(print_r('Order completed after meta updated with Webhook payment:' . $transaction_id, true));
		}
		
        return true;
    }
	
}
