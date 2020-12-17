<?php
if(!defined('ABSPATH')) {die('You are not allowed to call this page directly.');}

require_once(ABSPATH . '/wp-content/plugins/fac-api/fac-api.php');

class MeprFirstAtlanticCommerceGateway extends MeprBaseRealGateway{
	
/** Used in the view to identify the gateway */
public function __construct() {
    $this->name = __("First Atlantic Commerce", 'memberpress');
    $this->has_spc_form = true;
    $this->set_defaults();

    $this->capabilities = array(
      'process-credit-cards',
      'process-payments',
      'create-subscriptions',
      'cancel-subscriptions',
      'update-subscriptions',
      //'send-cc-expirations'
    );

    // Setup the notification actions for this gateway
    $this->notifiers = array(
      'sp' => 'listener',
      'whk' => 'webhook_listener',
    );
    $this->message_pages = array();
  }

  // Useful for generation of test Order numbers
function msTimeStamp()
{
 return (string)round(microtime(1) * 1000);
}

  public function load($settings) {
    $this->settings = (object)$settings;
    $this->set_defaults();
  }

  public function set_defaults() {
    if(!isset($this->settings))
    $this->settings = array();

  $this->settings = (object)array_merge(
    array(
      'gateway' => get_class($this),
      'id' => $this->generate_id(),
      'label' => '',
      'use_label' => true,
      'icon' => MEPR_IMAGES_URL . '/checkout/cards.png',
      'use_icon' => true,
      'desc' => __('Pay with your credit card via First Atlantic Commerce', 'memberpress'),
      'use_desc' => true,
      'time_zone_gmt' => -1,
      'merchant_name' => '',
      'transaction_password' => '',
      'signature_key' => '',
      'force_ssl' => false,
      'debug' => false,
      'test_mode' => false,
	  'test_url' => '',
	  'live_url' => ''
    ),
    (array)$this->settings
  );

  $this->id    = "qkh4bw-1x7";
  $this->label = $this->settings->label;
  $this->use_label = $this->settings->use_label;
  $this->icon = $this->settings->icon;
  $this->use_icon = $this->settings->use_icon;
  $this->desc = $this->settings->desc;
  $this->use_desc = $this->settings->use_desc;
  //$this->recurrence_type = $this->settings->recurrence_type;
  $this->hash  = strtoupper(substr(md5($this->id),0,20)); // MD5 hashes used for Silent posts can only be 20 chars long
  }
  
  /** Used to send data to a given payment gateway. In gateways which redirect
  * before this step is necessary this method should just be left blank.
  */
	public function process_payment($txn) {
		
	}
   /** Used to record a successful recurring payment by the given gateway. It
    * should have the ability to record a successful payment or a failure. It is
    * this method that should be used when receiving an IPN from PayPal or a
    * Silent Post from Authorize.net.
    */
    public function record_subscription_payment() {
        // Make sure there's a valid subscription for this request and this payment hasn't already been recorded
		if( !($sub = MeprSubscription::get_one_by_subscr_id(sanitize_text_field($_POST['x_subscription_id']))) or
			MeprTransaction::get_one_by_trans_num(sanitize_text_field($_POST['x_trans_id'])) ) {
		  return false;
		}

		$first_txn = $sub->first_txn();
		if($first_txn == false || !($first_txn instanceof MeprTransaction)) {
		  $coupon_id = $sub->coupon_id;
		}
		else {
		  $coupon_id = $first_txn->coupon_id;
		}

		$txn = new MeprTransaction();
		$txn->user_id         = $sub->user_id;
		$txn->product_id      = $sub->product_id;
		$txn->txn_type        = MeprTransaction::$payment_str;
		$txn->status          = MeprTransaction::$complete_str;
		$txn->coupon_id       = $coupon_id;
		$txn->trans_num       = sanitize_text_field($_POST['x_trans_id']);
		$txn->subscription_id = $sub->id;
		$txn->gateway         = $this->id;

		$txn->set_gross( sanitize_text_field($_POST['x_amount']) );

		$txn->store();

		$sub->status = MeprSubscription::$active_str;
		$sub->cc_last4 = substr(sanitize_text_field($_POST['x_account_number']), -4); // Don't get the XXXX part of the string
		//$sub->txn_count = sanitize_text_field($_POST['x_subscription_paynum']);
		$sub->gateway = $this->id;
		$sub->store();

		// Not waiting for a silent post here bro ... just making it happen even
		// though totalOccurrences is Already capped in record_create_subscription()
		$sub->limit_payment_cycles();

		MeprUtils::send_transaction_receipt_notices( $txn );
		if(!isset($_REQUEST['silence_expired_cc'])) {
		  MeprUtils::send_cc_expiration_notices( $txn ); //Silence this when a user is updating their CC, or they'll get the old card notice
		}

		return $txn;
		  }
		
		  /** Used to record a declined payment. */
		  public function record_payment_failure() {
			if(isset($_POST['x_trans_id']) and !empty($_POST['x_trans_id'])) {
			  $txn_res = MeprTransaction::get_one_by_trans_num(sanitize_text_field($_POST['x_trans_id']));

			  if(is_object($txn_res) and isset($txn_res->id)) {
				$txn = new MeprTransaction($txn_res->id);
				$txn->status = MeprTransaction::$failed_str;
				$txn->store();
		  }
		  else if( isset($_POST['x_subscription_id']) and
				   $sub = MeprSubscription::get_one_by_subscr_id(sanitize_text_field($_POST['x_subscription_id'])) ) {
			$first_txn = $sub->first_txn();
			if($first_txn == false || !($first_txn instanceof MeprTransaction)) {
			  $coupon_id = $sub->coupon_id;
			}
			else {
			  $coupon_id = $first_txn->coupon_id;
			}

			$txn = new MeprTransaction();
			$txn->user_id         = $sub->user_id;
			$txn->product_id      = $sub->product_id;
			$txn->coupon_id       = $coupon_id;
			$txn->txn_type        = MeprTransaction::$payment_str;
			$txn->status          = MeprTransaction::$failed_str;
			$txn->subscription_id = $sub->id;
			$txn->trans_num       = sanitize_text_field($_POST['x_trans_id']);
			$txn->gateway         = $this->id;

			$txn->set_gross( sanitize_text_field($_POST['x_amount']) );

			$txn->store();

			$sub->status = MeprSubscription::$active_str;
			$sub->gateway = $this->id;
			$sub->expire_txns(); //Expire associated transactions for the old subscription
			$sub->store();
		  }
		  else
			return false; // Nothing we can do here ... so we outta here

		  MeprUtils::send_failed_txn_notices($txn);

		  return $txn;
    }

    return false;
      }
    
      /** Used to record a successful payment by the given gateway. It should have
        * the ability to record a successful payment or a failure. It is this method
        * that should be used when receiving an IPN from PayPal or a Silent Post
        * from Authorize.net.
        */
      public function record_payment() {
        if(isset($_POST['x_trans_id']) and !empty($_POST['x_trans_id'])) {
      $obj = MeprTransaction::get_one_by_trans_num(sanitize_text_field($_POST['x_trans_id']));

      if(is_object($obj) and isset($obj->id)) {
        $txn = new MeprTransaction();
        $txn->load_data($obj);
        $usr = $txn->user();

        // Just short circuit if the transaction has already completed
        if($txn->status == MeprTransaction::$complete_str) { return; }

        $txn->status   = MeprTransaction::$complete_str;

        // This will only work before maybe_cancel_old_sub is run
        $upgrade = $txn->is_upgrade();
        $downgrade = $txn->is_downgrade();

        $event_txn = $txn->maybe_cancel_old_sub();
        $txn->store();

        $this->email_status("record_payment: Transaction\n" . MeprUtils::object_to_string($txn->rec, true) . "\n", $this->settings->debug);

        $prd = $txn->product();

        if( $prd->period_type=='lifetime' ) {
          if( $upgrade ) {
            $this->upgraded_sub($txn, $event_txn);
          }
          else if( $downgrade ) {
            $this->downgraded_sub($txn, $event_txn);
          }
          else {
            $this->new_sub($txn);
          }

          MeprUtils::send_signup_notices( $txn );
        }

        MeprUtils::send_transaction_receipt_notices( $txn );
        if(!isset($_REQUEST['silence_expired_cc'])) {
          MeprUtils::send_cc_expiration_notices( $txn ); //Silence this when a user is updating their CC, or they'll get the old card notice
        }

        return $txn;
      }
    }

    return false;
      }
    
      /** This method should be used by the class to record a successful refund from
        * the gateway. This method should also be used by any IPN requests or Silent Posts.
        */
      public function process_refund(MeprTransaction $txn) {
        // This happens manually in test mode
		MeprUtils::debug_log('First Atlantic Commerce process_refund '. $txn);
      }
    
      /** This method should be used by the class to record a successful refund from
        * the gateway. This method should also be used by any IPN requests or Silent Posts.
        */
      public function record_refund() {
        // This happens manually in test mode
      }
    
      //Not needed in the Artificial gateway
      public function process_trial_payment($transaction) { }
      public function record_trial_payment($transaction) { }
    
      /** Used to send subscription data to a given payment gateway. In gateways
        * which redirect before this step is necessary this method should just be
        * left blank.
        */
      public function process_create_subscription($txn) {
		  $mepr_options = MeprOptions::fetch();

			if(isset($txn) and $txn instanceof MeprTransaction) {
			  $usr = $txn->user();
			  $prd = $txn->product();
			  $sub = $txn->subscription();
			}
			else {
			  throw new MeprGatewayException( __('Payment was unsuccessful, please check your payment details and try again.', 'memberpress') );
			}

			$invoice = $this->create_new_order_invoice($sub);

			if( empty($usr->first_name) or empty($usr->last_name) ) {
			  $usr->first_name  = sanitize_text_field(wp_unslash($_POST['mepr_first_name']));
			  $usr->last_name   = sanitize_text_field(wp_unslash($_POST['mepr_last_name']));
			  $usr->store();
			}
			
						 // record these for reference
             $_POST['subscr_id'] = $sub->id;
			 $_POST['txn_id'] = $txn->id;
			 $_POST['response'] = "";
			 $sub->store();
      }
	  
	// get sub interval
    protected function get_subscription_interval($sub) {
		if($sub->period_type=='months')
		  return "M";
		else if($sub->period_type=='years') {
		  return "Y";
		}
		else if($sub->period_type=='weeks')
		  return "W";
	}
      /** Used to record a successful subscription by the given gateway. It should have
        * the ability to record a successful subscription or a failure. It is this method
        * that should be used when receiving an IPN from PayPal or a Silent Post
        * from Authorize.net.
        */
      public function record_create_subscription() {
        $mepr_options = MeprOptions::fetch();

		if(isset($_POST['txn_id']) and is_numeric($_POST['txn_id'])) {
		  $txn                = new MeprTransaction((int)$_POST['txn_id']);
		  $sub                = $txn->subscription();
		  $sub->subscr_id     = sanitize_text_field($_POST['subscr_id']);
		  $sub->status        = MeprSubscription::$active_str;
		  $sub->created_at    = gmdate('c');
		  $sub->cc_last4      = substr(sanitize_text_field($_POST['mepr_cc_num']),-4); // Seriously ... only grab the last 4 digits!
		  $sub->cc_exp_month  = sanitize_text_field($_POST['mepr_cc_exp_month']);
		  $sub->cc_exp_year   = sanitize_text_field($_POST['mepr_cc_exp_year']);
		  $sub->store();

		  // This will only work before maybe_cancel_old_sub is run
		  $upgrade   = $sub->is_upgrade();
		  $downgrade = $sub->is_downgrade();

		  $event_txn = $sub->maybe_cancel_old_sub();

		  $old_total = $txn->total; // Save for later

		  // If no trial or trial amount is zero then we've got to make
		  // sure the confirmation txn lasts through the trial
		  if(!$sub->trial || ($sub->trial and $sub->trial_amount <= 0.00)) {
			$day_count = ($sub->trial)?$sub->trial_days:$mepr_options->grace_init_days;

			$txn->expires_at  = MeprUtils::ts_to_mysql_date(time() + MeprUtils::days($day_count), 'Y-m-d H:i:s'); // Grace period before txn processes
			$txn->txn_type    = MeprTransaction::$subscription_confirmation_str;
			$txn->status      = MeprTransaction::$confirmed_str;
			$txn->trans_num   = $sub->subscr_id;
			$txn->set_subtotal(0.00); // This txn is just a confirmation txn ... it shouldn't have a cost
			$txn->store();
		  }

		  if($upgrade) {
			$this->upgraded_sub($sub, $event_txn);
		  }
		  elseif($downgrade) {
			$this->downgraded_sub($sub, $event_txn);
		  }
		  else {
			$this->new_sub($sub, true);
		  }

		  // Artificially set the txn amount for the notifications
		  // $txn->set_gross($old_total);

		  /// This will only send if there's a new signup
		  MeprUtils::send_signup_notices($txn);
		}
      }
    
      public function process_update_subscription($sub_id) {
        // This happens manually in test mode
      }
    
      /** This method should be used by the class to record a successful cancellation
        * from the gateway. This method should also be used by any IPN requests or
        * Silent Posts.
        */
      public function record_update_subscription() {
        // No need for this one with the artificial gateway
      }
    
      /** Used to suspend a subscription by the given gateway.
        */
      public function process_suspend_subscription($sub_id) {}
    
      /** This method should be used by the class to record a successful suspension
        * from the gateway.
        */
      public function record_suspend_subscription() {}
    
      /** Used to suspend a subscription by the given gateway.
        */
      public function process_resume_subscription($sub_id) {}
    
      /** This method should be used by the class to record a successful resuming of
        * as subscription from the gateway.
        */
      public function record_resume_subscription() {}
    
      /** Used to cancel a subscription by the given gateway. This method should be used
        * by the class to record a successful cancellation from the gateway. This method
        * should also be used by any IPN requests or Silent Posts.
        */
      public function process_cancel_subscription($sub_id) {
          FacApi::mepr_delete_sub($sub_id);
          FacApi::db_record_complete_txn($sub_id);
			$_REQUEST['sub_id'] = $sub_id;
			return $this->record_cancel_subscription();
      }
    
      /** This method should be used by the class to record a successful cancellation
        * from the gateway. This method should also be used by any IPN requests or
        * Silent Posts.
        */
      public function record_cancel_subscription() {
        $sub = new MeprSubscription($_REQUEST['sub_id']);
    
        if(!$sub) { return false; }
    
        // Seriously ... if sub was already cancelled what are we doing here?
        if($sub->status == MeprSubscription::$cancelled_str) { return $sub; }
    
        $sub->status = MeprSubscription::$cancelled_str;
        $sub->store();
    
        if(isset($_REQUEST['expire']))
          $sub->limit_reached_actions();
    
        if(!isset($_REQUEST['silent']) || ($_REQUEST['silent'] == false))
          MeprUtils::send_cancelled_sub_notices($sub);
    
        return $sub;
      }
    
      /** This gets called on the 'init' hook when the signup form is processed ...
        * this is in place so that payment solutions like paypal can redirect
        * before any content is rendered.
      */
      public function process_signup_form($txn) {
		  MeprUtils::debug_log('First Atlantic Commerce process_signup_form '. $txn);
        //if($txn->amount <= 0.00) {
        //  MeprTransaction::create_free_transaction($txn);
        //  return;
        //}
    
        // Redirect to thank you page
        //$mepr_options = MeprOptions::fetch();
        // $product = new MeprProduct($txn->product_id);
        // $sanitized_title = sanitize_title($product->post_title);
        //MeprUtils::wp_redirect($mepr_options->thankyou_page_url("membership={$sanitized_title}&trans_num={$txn->trans_num}"));
      }
    
      public function display_payment_page($txn) {
		  MeprUtils::debug_log('First Atlantic Commerce display_apyment_page '. $txn);
        // Nothing here yet
      }
    
      /** This gets called on wp_enqueue_script and enqueues a set of
        * scripts for use on the page containing the payment form
        */
      public function enqueue_payment_form_scripts() {
        // This happens manually in test mode
      }
    
      /**
  * Returs the payment for and required fields for the gateway
  */
  public function spc_payment_fields() {
    $payment_method = $this;
    $payment_form_action = 'mepr-authorize-net-payment-form';
    $txn = new MeprTransaction; //FIXME: This is simply for the action mepr-authorize-net-payment-form
    return MeprView::get_string("/checkout/payment_form", get_defined_vars());
  }
     
  /** This gets called on the_content and just renders the payment form
    */
public function display_payment_form($amount, $usr, $product_id, $txn_id) {
    $prd = new MeprProduct($product_id);
    $coupon = false;
    $mepr_options = MeprOptions::fetch();

    $txn = new MeprTransaction($txn_id);
    $usr = $txn->user();

    //Artifically set the price of the $prd in case a coupon was used
    if($prd->price != $amount) {
      $coupon = true;
      $prd->price = $amount;
    }

    $invoice = MeprTransactionsHelper::get_invoice($txn);
	$orderId = $usr->ID."_".$product_id."_".$txn_id;
    echo $invoice;
	
	?>
    <div class="mp_wrapper mp_payment_form_wrapper">
      <?php MeprView::render('/shared/errors', get_defined_vars()); ?>
    </div>
	<script>
		var amount = <?= "'".$amount."'" ?>;
		var orderId = <?= "'".$orderId."'" ?>;
	</script>
	<?php FacApi::display_fac_hosted_payment_form() ?>
    <?php
  }

  public function process_payment_form($txn) {
    //We're just here to update the user's name if they changed it
    $user = $txn->user();
    $first_name = sanitize_text_field(wp_unslash(($_POST['mepr_first_name'])));
    $last_name = sanitize_text_field(wp_unslash(($_POST['mepr_last_name'])));

    if($user->first_name != $first_name) {
      update_user_meta($user->ID, 'first_name', $first_name);
    }

    if($user->last_name != $last_name) {
      update_user_meta($user->ID, 'last_name', $last_name);
    }

    //Call the parent to handle the rest of this
    parent::process_payment_form($txn);
  }

  /** Validates the payment form before a payment is processed */
  public function validate_payment_form($errors) {
    $mepr_options = MeprOptions::fetch();

    if(!isset($_POST['mepr_transaction_id']) || !is_numeric($_POST['mepr_transaction_id'])) {
      $errors[] = __('An unknown error has occurred.', 'memberpress');
    }

    // IF SPC is enabled, we need to bail on validation if 100% off forever coupon was used
    $txn = new MeprTransaction((int)$_POST['mepr_transaction_id']);
    if($txn->coupon_id) {
      $coupon = new MeprCoupon($txn->coupon_id);

      // TODO - need to check if 'dollar' amount discounts also make the price free forever
      // but those are going to be much less likely to be used than 100 'percent' type discounts
      if($coupon->discount_amount == 100 && $coupon->discount_type == 'percent' && $coupon->discount_mode == 'standard') {
        return $errors;
      }
    }

    // Authorize requires a firstname / lastname so if it's hidden on the signup form ...
    // guess what, the user will still have to fill it out here
    if(!$mepr_options->show_fname_lname &&
        (!isset($_POST['mepr_first_name']) || empty($_POST['mepr_first_name']) ||
         !isset($_POST['mepr_last_name']) || empty($_POST['mepr_last_name']))) {
      $errors[] = __('Your first name and last name must not be blank.', 'memberpress');
    }

    if(!isset($_POST['mepr_cc_num']) || empty($_POST['mepr_cc_num'])) {
      $errors[] = __('You must enter your Credit Card number.', 'memberpress');
    }
    elseif(!$this->is_credit_card_valid($_POST['mepr_cc_num'])) {
      $errors[] = __('Your credit card number is invalid.', 'memberpress');
    }

    if(!isset($_POST['mepr_cvv_code']) || empty($_POST['mepr_cvv_code'])) {
      $errors[] = __('You must enter your CVV code.', 'memberpress');
    }

    return $errors;
  }
  
      /** Displays the form for the given payment gateway on the MemberPress Options page */
      public function display_options_form() {
        $mepr_options = MeprOptions::fetch();
        $manually_complete = ($this->settings->manually_complete == true);
        $always_send_welcome = ($this->settings->always_send_welcome == true);
		$merchant_name = trim($this->settings->merchant_name);
		$txn_password =  trim($this->settings->transaction_password);
		$time_zone_gmt =  trim($this->settings->time_zone_gmt);
		$test_url =  trim($this->settings->test_url);
		$live_url =  trim($this->settings->live_url);
		$test_mode = ($this->settings->test_mode == 'on' or $this->settings->test_mode == true);
        ?>
        <table>
          <tr>
            <td colspan="2">
              <input type="checkbox" name="<?php echo $mepr_options->integrations_str; ?>[<?php echo $this->id;?>][manually_complete]"<?php echo checked($manually_complete); ?> />&nbsp;<?php _e('Admin Must Manually Complete Transactions', 'memberpress'); ?>
            </td>
          </tr>
          <tr>
            <td colspan="2">
              <input type="checkbox" name="<?php echo $mepr_options->integrations_str; ?>[<?php echo $this->id;?>][always_send_welcome]"<?php echo checked($always_send_welcome); ?> />&nbsp;<?php _e('Send Welcome email when "Admin Must Manually Complete Transactions" is enabled', 'memberpress'); ?>
            </td>
          </tr>      <tr>
            <td colspan="2">
              <label><?php _e('Description', 'memberpress'); ?></label><br/>
              <textarea name="<?php echo $mepr_options->integrations_str; ?>[<?php echo $this->id;?>][desc]" rows="3" cols="45"><?php echo stripslashes($this->settings->desc); ?></textarea>
            </td>
          </tr>
		  <tr>
			<td><a href="<?php echo "https://".$_SERVER['SERVER_NAME']."/wp-admin/admin.php?page=fac-api"; ?>">Edit FAC Settings</a></td>
		</tr>
        </table>
        <?php
      }
    
      /** Validates the form for the given payment gateway on the MemberPress Options page */
      public function validate_options_form($errors) {
        return $errors;
      }
    
      /** This gets called on wp_enqueue_script and enqueues a set of
        * scripts for use on the front end user account page.
        */
      public function enqueue_user_account_scripts() {
      }
    
      /** Displays the update account form on the subscription account page **/
      public function display_update_account_form($sub_id, $errors=array(), $message='') {
        $sub = new MeprSubscription($sub_id);

		$last4 = isset($_POST['update_cc_num']) ? substr(sanitize_text_field($_POST['update_cc_num']), -4) : $sub->cc_last4;
		$exp_month = isset($_POST['update_cc_exp_month']) ? sanitize_text_field($_POST['update_cc_exp_month']) : $sub->cc_exp_month;
		$exp_year = isset($_POST['update_cc_exp_year']) ? sanitize_text_field($_POST['update_cc_exp_year']) : $sub->cc_exp_year;

		// Only include the full cc number if there are errors
		if(strtolower($_SERVER['REQUEST_METHOD'])=='post' and empty($errors)) {
		  $sub->cc_last4 = $last4;
		  $sub->cc_exp_month = $exp_month;
		  $sub->cc_exp_year = $exp_year;
		  $sub->store();

		  unset($_POST['update_cvv_code']); // Unset this for security
		}
		else { // If there are errors then show the full cc num ... if it's there
		  $last4 = isset($_POST['update_cc_num']) ? sanitize_text_field($_POST['update_cc_num']) : $sub->cc_last4;
		}

		$ccv_code = (isset($_POST['update_cvv_code'])) ? sanitize_text_field($_POST['update_cvv_code']) : '';
		$exp = sprintf('%02d', $exp_month) . " / {$exp_year}";

		?>
		<div class="mp_wrapper">
		  <?php if( $sub->is_expired() &&
					$sub->latest_txn_failed() &&
					!$sub->first_real_payment_failed() && //Only when first payment failed, Authorize.net suspends sub, when we update the card it retries it, so don't catchup
					($catchup = $sub->calculate_catchup($this->settings->catchup_type)) &&
					$catchup->proration > 0.00 ): ?>
			<div class="mepr_error"><?php printf(__('Note: Because your subscription is expired, when you update your credit card number our system will attempt to bill your card for the prorated amount of %s to catch you up until the next automatic billing.','memberpress'), MeprAppHelper::format_currency($catchup->proration)); ?></div>
		  <?php endif; ?>

		  <form action="" method="post" id="mepr_authorize_net_update_cc_form" class="mepr-checkout-form mepr-form" novalidate>
			<input type="hidden" name="_mepr_nonce" value="<?php echo wp_create_nonce('mepr_process_update_account_form'); ?>" />
			<div class="mepr_update_account_table">
			  <div><strong><?php _e('Update your Credit Card information below', 'memberpress'); ?></strong></div>
			  <?php MeprView::render('/shared/errors', get_defined_vars()); ?>
			  <div class="mp-form-row">
				<label><?php _e('Credit Card Number', 'memberpress'); ?></label>
				<input type="text" class="mepr-form-input cc-number validation" pattern="\d*" autocomplete="cc-number" placeholder="<?php echo MeprUtils::cc_num($last4); ?>" required />
				<input type="hidden" class="mepr-cc-num" name="update_cc_num"/>
				<script>
				  jQuery(document).ready(function($) {
					$('input.cc-number').on('change blur', function (e) {
					  var num = $(this).val().replace(/ /g, '');
					  $('input.mepr-cc-num').val( num );
					});
				  });
				</script>
			  </div>

			  <input type="hidden" name="mepr-cc-type" class="cc-type" value="" />

			  <div class="mp-form-row">
				<div class="mp-form-label">
				  <label><?php _e('Expiration', 'memberpress'); ?></label>
				  <span class="cc-error"><?php _e('Invalid Expiration', 'memberpress'); ?></span>
				</div>
				<input type="text" class="mepr-form-input cc-exp validation" value="<?php echo $exp; ?>" pattern="\d*" autocomplete="cc-exp" placeholder="mm/yy" required>
				<input type="hidden" class="cc-exp-month" name="update_cc_exp_month"/>
				<input type="hidden" class="cc-exp-year" name="update_cc_exp_year"/>
				<script>
				  jQuery(document).ready(function($) {
					$('input.cc-exp').on('change blur', function (e) {
					  var exp = $(this).payment('cardExpiryVal');
					  $( 'input.cc-exp-month' ).val( exp.month );
					  $( 'input.cc-exp-year' ).val( exp.year );
					});
				  });
				</script>
			  </div>

			  <div class="mp-form-row">
				<div class="mp-form-label">
				  <label><?php _e('CVC', 'memberpress'); ?></label>
				  <span class="cc-error"><?php _e('Invalid CVC Code', 'memberpress'); ?></span>
				</div>
				<input type="text" name="update_cvv_code" class="mepr-form-input card-cvc cc-cvc validation" pattern="\d*" autocomplete="off" required />
			  </div>

			  <div class="mp-form-row">
				<div class="mp-form-label">
				  <label><?php _e('Zip code for Card', 'memberpress'); ?></label>
				</div>
				<input type="text" name="update_zip_post_code" class="mepr-form-input" autocomplete="off" value="" required />
			  </div>
			</div>

			<div class="mepr_spacer">&nbsp;</div>

			<input type="submit" class="mepr-submit" value="<?php _e('Update Credit Card', 'memberpress'); ?>" />
			<img src="<?php echo admin_url('images/loading.gif'); ?>" style="display: none;" class="mepr-loading-gif" />
			<?php MeprView::render('/shared/has_errors', get_defined_vars()); ?>
		  </form>
		</div>
    <?php
      }
    
      /** Validates the payment form before a payment is processed */
      public function validate_update_account_form($errors=array()) {
         if( !isset($_POST['_mepr_nonce']) or empty($_POST['_mepr_nonce']) or
			!wp_verify_nonce($_POST['_mepr_nonce'], 'mepr_process_update_account_form') )
		  $errors[] = __('An unknown error has occurred. Please try again.', 'memberpress');

		if(!isset($_POST['update_cc_num']) || empty($_POST['update_cc_num']))
		  $errors[] = __('You must enter your Credit Card number.', 'memberpress');
		elseif(!$this->is_credit_card_valid($_POST['update_cc_num']))
		  $errors[] = __('Your credit card number is invalid.', 'memberpress');

		if(!isset($_POST['update_cvv_code']) || empty($_POST['update_cvv_code']))
		  $errors[] = __('You must enter your CVV code.', 'memberpress');

		return $errors;
      }
    
      /** Used to update the credit card information on a subscription by the given gateway.
        * This method should be used by the class to record a successful cancellation from
        * the gateway. This method should also be used by any IPN requests or Silent Posts.
        */
      public function process_update_account_form($sub_id) {
         return $this->process_update_subscription($sub_id);
      }
	  
	    protected function create_new_order_invoice($sub) {
		$inv = strtoupper(substr(preg_replace('/\./','',uniqid('',true)),-20));

		$sub->token = $inv;
		$sub->store();

		return $inv;
  }
    
      /** Returns boolean ... whether or not we should be sending in test mode or not */
      public function is_test_mode() {
        return false; // Why bother
      }
    
      public function force_ssl() {
        return false; // Why bother
      }
}