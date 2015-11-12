<?php 

/*
 * code to support direct post server-to-server method
 * might make the transition to SOAP easier, later on?
 */

class Beanstream_POST {
  CONST BEANSTREAM_TRANSACTION = 'https://www.beanstream.com/scripts/process_transaction.asp';
  CONST BEANSTREAM_PROFILE = 'https://www.beanstream.com/scripts/payment_profile.asp';

  CONST CURRENCIES = 'CAD,USD';
  // var $debug = 0;
  protected $query_string;
  protected $url;
  protected $log;
  
  // initialize the url and query string protected variables, depending on type
  function __construct($type, $log = TRUE) {

    $this->log = $log;
    switch($type) {

      case 'transaction': 
        $this->query_string = array('requestType=BACKEND');
        $this->url = $this::BEANSTREAM_TRANSACTION; 
        break;

      case 'profile': 
        $this->query_string = array();
        $this->url = $this::BEANSTREAM_PROFILE; 
        break;

      default:
        throw new Exception('Invalid construction type');

    }
  }

  // prepare data: convert key => value arrays into query strings
  function prepare($data) {
    $this->query_string[] = http_build_query($data);
  } 

  /* the core of this object, just post it all after logging */
  function process($credentials) {
    if ($this->log) {
      $logged_request = array();
      parse_str(implode('&',$this->query_string),$logged_request);
      // mask the cc numbers
      $this->mask($logged_request);
      // log: ip, invoiceNum, , cc, total, date
      // print_r($logged_request); die();
      $ip = (function_exists('ip_address') ? ip_address() : $_SERVER['REMOTE_ADDR']);

      if ($logged_request['trnOrderNumber'] == null) {
        // Need to set this as it's null in some cases (webform_civicrm)
        $logged_request['trnOrderNumber'] = '';
      }

      $query_params = array(
        1 => array($logged_request['trnOrderNumber'], 'String'),
        2 => array($ip, 'String'),
        3 => array(substr($logged_request['trnCardNumber'], -4), 'String'),
        4 => array('', 'String'),
        5 => array($logged_request['trnAmount'], 'String'),
      );
      CRM_Core_DAO::executeQuery("INSERT INTO civicrm_beanstream_request_log
        (invoice_num, ip, cc, customer_code, total, request_datetime) VALUES (%1, %2, %3, %4, %5, NOW())", $query_params);
    }
    // save the OrderNumber so I can log it for the response
    $this->orderNumber = $logged_request['trnOrderNumber'];


    // Initialize curl
    $ch = curl_init();
    // Get curl to POST
    curl_setopt( $ch, CURLOPT_POST, 1 );
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    // Instruct curl to suppress the output from Beanstream, and to directly
    // return the transfer instead. (Output will be stored in $txResult.)
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1);
    // This is the location of the Beanstream payment gateway or profile management
    curl_setopt( $ch, CURLOPT_URL, $this->url);
    // These are the transaction parameters that we will POST
    $qs = implode('&',$this->query_string).'&'.http_build_query($credentials);
    curl_setopt( $ch, CURLOPT_POSTFIELDS,$qs);
    // example: "requestType=BACKEND&merchant_id=109040000&trnCardOwner=Paul+Randal&trnCardNumber=5100000010001004&trnExpMonth=01&trnExpYear=05&trnOrderNumber=2232&trnAmount=10.00&ordEmailAddress=prandal@mydomain.net&ordName=Paul+Randal&ordPhoneNumber=9999999&ordAddress1=1045+Main+Street&ordAddress2=&ordCity=Vancouver&ordProvince=BC&ordPostalCode=V8R+1J6&ordCountry=CA"
    // Now POST the transaction. $txResult will contain Beanstream's response
    $response = curl_exec( $ch );
    curl_close( $ch );
    return $response;
  }

  /* function for processing resulting string into array */
  function result($response) {
    $array_result = array();
    parse_str($response,$array_result);
    if ($this->log) {
      $query_params = array(
        1 => array($this->orderNumber, 'String'),
        2 => array($array_result['authCode'], 'String'),
        3 => array($array_result['trnId'], 'String'),
        4 => array($array_result['messageId'], 'String'),
      );
      if (empty($query_params[2][0])) { // for declines, put in the reason in place of the authcode
        $query_params[2][0] = $array_result['messageText'];
      }
      CRM_Core_DAO::executeQuery("INSERT INTO civicrm_beanstream_response_log
        (order_number, auth_code, remote_id, message_id, response_datetime) VALUES (%1, %2, %3, %4, NOW())", $query_params);
    }

    return $array_result;
  }

  function mask(&$log_request) {
    // Mask the credit card number and CVV.
    foreach(array('trnCardNumber','trnCardCvd') as $mask) {
      if (!empty($log_request[$mask])) {
        if (4 < strlen($log_request[$mask])) { // show the last four digits of cc numbers
          $log_request[$mask] = str_repeat('X', strlen($log_request[$mask]) - 4) . substr($log_request[$mask], -4);
        }
        else {
          $log_request[$mask] = str_repeat('X', strlen($log_request[$mask]));
        }
      }
    }
  }

}
