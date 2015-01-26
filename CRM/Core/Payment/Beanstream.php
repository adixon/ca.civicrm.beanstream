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

  static function &singleton($mode, &$paymentProcessor) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === NULL) {
      self::$_singleton[$processorName] = new CRM_Core_Payment_Beanstream($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
  }

  function doDirectPayment(&$params) {

    if (!$this->_profile) {
      return self::error('Unexpected error, missing profile');
    }
    // use the Beanstream SOAP object for interacting with Beanstream, mostly the same for recurring contributions
    require_once("CRM/Beanstream/POST.php");
    $isRecur =  CRM_Utils_Array::value('is_recur', $params) && $params['contributionRecurID'];
    // to add debugging info in the drupal log, assign 1 to log['all'] below
    /* TODO: beanstream object is ignoring all args to contructor */
    // $postType = $isRecur ? 'profile' : 'transaction';
    $postType = 'transaction';
    $beanstream = new Beanstream_POST($postType); // ,array('log' => array('all' => 0),'trace' => FALSE));
    if (!in_array($params['currencyID'], explode(',', $beanstream::CURRENCIES))) {
      return self::error('Invalid currency selection, must be one of ' . $beanstream::CURRENCIES);
    }
    $request = $this->convertParams($params);
    $request['email'] = $this->bestEmail($params);
    $beanstream->prepare($request);
    // $request['customerIPAddress'] = (function_exists('ip_address') ? ip_address() : $_SERVER['REMOTE_ADDR']);
    $credentials = array( 'merchant_id' => $this->_paymentProcessor['signature'],
                          'username'  => $this->_paymentProcessor['user_name'],
                          'password'  => $this->_paymentProcessor['password']); 
    // Get the API endpoint URL for the method's transaction mode.
    // TODO: enable override of the default url in the request object
    // $url = $this->_paymentProcessor['url_site'];

    // make the soap request
    $response = $beanstream->process($credentials);
    // process the soap response into a readable result
    $result = $beanstream->result($response);
    if (empty($result['trnApproved'])) {
      // deal with errors of all kinds
      $error = array();
      foreach(array('messageText','errorFields','errorType') as $key) {
         $error[$key] = empty($result[$key]) ? 'Unexpected error' : $result[$key];
      }
      $message = $error['messageText'];
      if ('U' == $error['errorType']) {
        $message .= $error['errorFields'];
      } 
      return self::error($message);
    }
    else { // transaction was approved!
      $params['trxn_id'] = $result['trnId'] . ':' . time();
      $params['gross_amount'] = $params['amount'];
      if ($isRecur) { 
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
      }
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
      $error[] = ts('Password is not set in the Administer CiviCRM &raquo; System Settings &raquo; Payment Processors.');
    }

    if (empty($this->_paymentProcessor['signature'])) {
      $error[] = ts('Username is not set in the Administer CiviCRM &raquo; System Settings &raquo; Payment Processors.');
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
   * TODO: need to require or somehow fill in email and phone!
   * TODO: deal with profile saving
   */
  function convertParams($params) {
    $request = array();
    $convert = array(
      'ordAddress1' => 'street_address',
      'ordCity' => 'city',
      'ordProvince' => 'state_province',
      'ordPostalCode' => 'postal_code',
      'trnOrderNumber' => 'invoiceID',
      'trnCardNumber' => 'credit_card_number',
      'trnCardCvd' => 'cvv2',
      'ordEmailAddress' => 'email', 
      'ordPhoneNumber' => 'phone',
    );
 
    foreach($convert as $r => $p) {
      if (isset($params[$p])) {
        $request[$r] = $params[$p];
      }
    }
    $fullname = array();
    foreach(array('first','middle','last') as $name) {
      if (!empty($params['billing_'.$name.'_name'])) {
        $fullname[] = $params['billing_'.$name.'_name'];
      }
    }
    $request['trnCardOwner'] = implode(' ',$fullname);
    $request['trnExpMonth'] = sprintf('%02d', $params['month']);
    $request['trnExpYear'] = sprintf('%02d', ($params['year'] % 100));
    $request['trnAmount'] = sprintf('%01.2f', $params['amount']);
    $request['ordProvince'] = $params['state_province']; // convert to 2-character id codes for Canada/US
    $request['ordCountry'] = $params['country']; // TODO! this should convert country name to 2-character ISO code
    return $request;
  }
 
}

