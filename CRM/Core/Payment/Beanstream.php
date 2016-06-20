<?php
/*
 | License info here
 +--------------------------------------------------------------------+
*/

/**
 *
 * @author Alan Dixon
 *
 * This code provides glue between CiviCRM payment model and the Beanstream SOAP payment processor encapsulated in the Beanstream_Service_Request object
 *
 */
class CRM_Core_Payment_Beanstream extends CRM_Core_Payment {

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = NULL;
  // TODO: we have no way of testing that the merchant account currency matches my transaction
  //   what other tools can we use to avoid charges in the wrong currency?
  // const CURRENCIES = 'CAD, USD';
  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */ 
   function __construct($mode, &$paymentProcessor) {
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('Beanstream POST');

    // get merchant data from config
    $config = CRM_Core_Config::singleton();
    // live or test
    $this->_profile['mode'] = $mode;
  }

  static function &singleton($mode, &$paymentProcessor = NULL, &$paymentForm = NULL, $force = FALSE) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === NULL || $force) {
      self::$_singleton[$processorName] = new self($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
  }

  function doDirectPayment(&$params) {

    if (!$this->_profile) {
      return self::error('Unexpected error, missing profile');
    }
    // use the Beanstream Gateway object
    require 'vendor/autoload.php';
    // $isRecur =  CRM_Utils_Array::value('is_recur', $params) && $params['contributionRecurID'];
    $cred = array('merchant_id'  => $this->_paymentProcessor['user_name'], 'api_passcode'  => $this->_paymentProcessor['password']); 
    $beanstream = new \Beanstream\Gateway($cred['merchant_id'], $cred['api_passcode'], 'www', 'v1');
    // echo '<pre>'; print_r($beanstream); die();
    // TODO: can I figure out from the gatway object whether the merchant account will support this currency? No!
    //if (!in_array($params['currencyID'], explode(',', self::CURRENCIES))) {
    //  return self::error('Invalid currency selection, must be one of ' . self::CURRENCIES);
    //}
    $request = $this->convertParams($params);
    // $request['email'] = $this->bestEmail($params);
    $request['customer_ip'] = (function_exists('ip_address') ? ip_address() : $_SERVER['REMOTE_ADDR']);
    try {
      //set to FALSE for Pre-Auth
      $result = $beanstream->payments()->makeCardPayment($request, TRUE); 
      CRM_Core_Error::debug_var('result', $result);
      // print_r( $result );
    } catch (\Beanstream\Exception $e) {
      //handle exception by passing the message back to the user
      // CRM_Core_Error::debug_var('request', $request);
      // CRM_Core_Error::debug_var('exception', $e);
      return self::error($e->getMessage() . '[Error code '.$e->getCode().']');
    } 
    if (empty($result['approved'])) {
      // unexpected error
      return self::error($result['message']. '[Error code '.$result['message_id'].']');
    }
    else { // transaction was approved!
      $params['trxn_id'] = $result['auth_code'] . ':' . time();
      $params['gross_amount'] = $params['amount'];
      /* if ($isRecur) { 
        // TODO: save the profile information in beanstream, needs a separate POST
        // save the client info in my custom table
        // Allow further manipulation of the arguments via custom hooks,
        // before initiating processCreditCard()
        // CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $beanstreamlink1);
        $customer_code = $result['TODO'];
        $exp = sprintf('%02d%02d', ($params['year'] % 100), $params['month']);
        $email = $this->bestEmail($params);
        $query_params = array(
          1 => array($customer_code, 'String'),
          2 => array($request['customerIPAddress'], 'String'),
          3 => array($exp, 'String'),
          4 => array($params['contactID'], 'Integer'),
          5 => array($email, 'String'),
          6 => array($params['contributionRecurID'], 'Integer'),
        );
        CRM_Core_DAO::executeQuery("INSERT INTO civicrm_beanstream_customer_codes
          (customer_code, ip, expiry, cid, email, recur_id) VALUES (%1, %2, %3, %4, %5, %6)", $query_params);
        $params['contribution_status_id'] = 1;
        // also set next_sched_contribution
        $params['next_sched_contribution'] = strtotime('+'.$params['frequency_interval'].' '.$params['frequency_unit']);
      } */
      return $params;
    }
  }
  
  function bestEmail($params) {
    $email = '';
    foreach(array('','-5','-Primary') as $suffix) {
      if (!empty($params['email'.$suffix])) {
        return $params['email'.$suffix];
      }
    }
    return;
  }

  function &error($error = NULL) {
    $e = CRM_Core_Error::singleton();
    if (is_object($error)) {
      $e->push($error->getResponseCode(),
        0, NULL,
        $error->getMessage()
      );
    }
    elseif ($error && is_numeric($error)) {
      $e->push($error,
        0, NULL,
        $this->errorString($error)
      );
    }
    elseif (is_string($error)) {
      $e->push(9002,
        0, NULL,
        $error
      );
    }
    else {
      $e->push(9001, 0, NULL, "Unknown System Error.");
    }
    return $e;
  }

  /**
   * This function checks to see if we have the right config values
   *
   * @param  string $mode the mode we are operating in (live or test)
   *
   * @return string the error message if any
   * @public
   */
  function checkConfig() {
    $error = array();

    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('Merchant ID is not set in the Administer CiviCRM &raquo; System Settings &raquo; Payment Processors.');
    }

    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts('API access password is not set in the Administer CiviCRM &raquo; System Settings &raquo; Payment Processors.');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /*
   * Convert the values in the civicrm params to the request array with keys as expected by Beanstream
   * convert array has beanstream => civicrm names
   * TODO: deal with profile saving
   * SAMPLE: $payment_data = array(
            'order_number' => 'orderNumber0023',
            'amount' => 19.99,
            'payment_method' => 'card',
            'card' => array(
                'name' => 'Mr. Card Testerson',
                'number' => '4030000010001234',
                'expiry_month' => '07',
                'expiry_year' => '22',
                'cvd' => '123'
            )
    ); 
     See http://developer.beanstream.com/documentation/rest-api-reference/
   */
  function convertParams($params) {
    $request = array(
      'order_number' => $params['invoiceID'],
      'amount' => 0,
      'payment_method' => 'card',
      'card' => array(),
      'billing' => array(),
    );
    $convert = array(
      'card' => array(
        'cvd' => 'cvv2',
        'number' => 'credit_card_number',
      ),
      'billing' => array(
        'address_line1' => 'street_address',
        'city' => 'city',
        'province' => 'state_province',
        'country' => 'country',
        'postal_code' => 'postal_code',
        'email_address' => 'email', 
        'phone_number' => 'phone',
      )
    );
    foreach($convert as $key => $group) {
      foreach($group as $r => $p) {
        if (isset($params[$p])) {
          $request[$key][$r] = $params[$p];
        }
      }
    }
    $fullname = array();
    foreach(array('first','middle','last') as $name) {
      if (!empty($params['billing_'.$name.'_name'])) {
        $fullname[] = $params['billing_'.$name.'_name'];
      }
    }
    $request['card']['name'] = implode(' ',$fullname);
    $request['billing']['name'] = implode(' ',$fullname);
    $request['card']['expiry_month'] = sprintf('%02d', $params['month']);
    $request['card']['expiry_year'] = sprintf('%02d', ($params['year'] % 100));
    $request['amount'] = sprintf('%01.2f', $params['amount']);
    return $request;
  }
 
}

