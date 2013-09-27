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
  
  // initialize the url and query string protected variables, depending on type
  function __construct($type) {

    switch($type) {

      case 'transaction': 
        $this->query_string = array('requestType=BACKEND');
        $this->url = BEANSTREAM_TRANSACTION; 
        break;

      case 'profile': 
        $this->query_string = array();
        $this->url = BEANSTREAM_PROFILE; 
        break;

      default:
        throw new Exception('Invalid construction type');

    }
  }

  // prepare data: convert key => value arrays into query strings
  function prepare($data) {
    $this->query_string[] = http_build_query($data);
  } 

  /* the core of this object, just post it all */
  function process($credentials) {
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
    return parse_str($response);
  }
}
