<?php
require_once 'CRM/Core/Payment.php';
use Civi\Payment\Exception\PaymentProcessorException;
class CRM_Core_Payment_Beanstream extends CRM_Core_Payment
{
    const CHARSET  = 'iso-8859-1';
    static private $_singleton = null;

    /**
     * Constructor
     *
     * @param string $mode the mode of operation: live or test
     *
     * @return void
     */
    function __construct($mode, &$paymentProcessor)
    {
        $this->_mode             = $mode;
        $this->_paymentProcessor = $paymentProcessor;
        $this->_processorName    = ts('Beanstream');
    }

    /**
     * singleton function used to manage this object
     *
     * @param string $mode the mode of operation: live or test
     *
     * @return object
     * @static
     *
     */
    static function &singleton($mode, &$paymentProcessor)
    {
        $processorName = $paymentProcessor['name'];
        if (self::$_singleton[$processorName] === null)
        {
            self::$_singleton[$processorName] = new CRM_Core_Payment_Beanstream($mode, $paymentProcessor);
        }
        return self::$_singleton[$processorName];
    }

    function mapParamsToBeanstreamFields($params)
    {
        $requestFields['requestType']      = "BACKEND";
        $requestFields['merchant_id']      = $this->_paymentProcessor['signature'];
        $requestFields['trnCardOwner']     = $params['billing_first_name']." ".
            (strlen($params['billing_middle_name'])> 0 ? $params['billing_middle_name']." " : "").$params['billing_last_name'];
        $requestFields['trnCardNumber']    = str_replace(" ", "", $params['credit_card_number']);   # no spaces
        $requestFields['trnExpMonth']      = str_pad($params['month'], 2, 0, STR_PAD_LEFT);
        $requestFields['trnExpYear']       = substr($params['year'], -2);
        $requestFields['trnCardCvd']       = $params['cvv2'];       # optional
        $requestFields['trnOrderNumber']   = $params['invoiceID'];
        $requestFields['trnAmount']        = round($params['amount'], 2);
        $requestFields['ordEmailAddress']  = $params['email'];
        $requestFields['ordName']          = $params['first_name']." ".$params['last_name'];
        # default to fake phone number
        $requestFields['ordPhoneNumber']   = "5551112222";
        # use secondary phone if set
        if (isset($params['phone-2-1'])){$requestFields['ordPhoneNumber'] = $params['phone-2-1'];}
        # prefer primary phone number field
        if (isset($params['phone-Primary-1'])){$requestFields['ordPhoneNumber'] = $params['phone-Primary-1'];}
        $requestFields['ordAddress1']      = $params['street_address'];
        # address2 is optional for beanstream, and not included by civicrm
        $requestFields['ordCity']          = $params['city'];
        $requestFields['ordProvince']      = in_array($params['country'], Array("US", "CA")) ? $params['state_province'] : "--";
        $requestFields['ordPostalCode']    = $params['postal_code'];
        $requestFields['ordCountry']       = $params['country'];        # must match ISO country codes
        $requestFields['trnType']          = "P";                       # not sure if this is required
        #$requestFields['paymentMethod']    = "CC";
        #$requestFields['username']         = $this->_paymentProcessor['user_name'];
        #$requestFields['password']         = $this->_paymentProcessor['password'];
		
		// gord patch - added customer's IP address - 20 Apr 2016
		$ipadd = $_SERVER['REMOTE_ADDR']?:($_SERVER['HTTP_X_FORWARDED_FOR']?:$_SERVER['HTTP_CLIENT_IP']);
		$requestFields['customerIp']          = $ipadd;
		$requestFields['passcode']          = "8b0101e1ae7E450585D6537E715fA724"; // must be generated from Beanstream in account settings > order settings > API access passcode. Remote IP won't be passed as a variable without this passcode present.
		// end gord patch 

        #error_log(print_r($params, true));
        if (isset($params["is_pledge"]) && 1 == $params["is_pledge"])
        {
            # [is_pledge] => 1
            # [pledge_installments] => 4
            # [pledge_frequency_interval] => 6
            # [pledge_frequency_unit] => month
            $requestFields['trnRecurring']      = "1";                  # this is a recurring transaction
            $requestFields['rbBillingPeriod']   = "M";                  # monthly
            if (isset($params["pledge_frequency_unit"]) && $params["pledge_frequency_unit"] == "year")
            {
                $requestFields['rbBillingPeriod']   = "Y";              # might support yearly payments in future
            }
            $requestFields['rbBillingIncrement']    = "1";              # every '1' months
            #$requestFields['rbEndMonth']           = "1";              # omit to skip. set to 1 to bill on the last day of the month
            #$requestFields['rbCharge']             = "1";              # omit to skip. set to 0 to delay first payment until rbFirstBilling
            #$requestFields['rbFirstBilling']       = "1";              # omit. can be used to set first payment date
            #$requestFields['rbExpiry']             = "MMDDYYY";        # date to end recurring payments
        }
        # ref1, ref2, ref3, ref4, ref5 -- can use these reference fields to store more detail about transaction
        if (isset($params["invoiceID"]))
        {
            # something like 786b29a08915f38e364a37dccd994aa5
            $requestFields['ref1'] = "invoiceID: ".$params["invoiceID"];
        }
        if (isset($params["contributionPageID"]))
        {
            # something like 786b29a08915f38e364a37dccd994aa5
            $requestFields['ref2'] = "contributionPageID: ".$params["contributionPageID"];
        }
        return $requestFields;
    }

    /**
     * Required method for Civi to implement the Abstract method
     *
     * @param  string $token the key associated with this transaction
     *
     * @return array the result in an nice formatted array (or an error object)
     * @public
     */
    function doPayment(&$params, $component = 'contribute')
    {
	$completed = [
            'payment_status_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed'),
	    'payment_status' => 'Completed',
        ];
        if (empty($params['amount'])) {
          return $completed;
        }
        $requestFields = self::mapParamsToBeanstreamFields($params);

        # allow manipulation of the arguments via custom hook
        CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $requestFields);
        $postfields = http_build_query($requestFields);
		// gord patch - 7-Apr-2020 - line below leaking data to error log!
        // error_log("beanstream request: ".$postfields);

        $count = 0;
        $responseData = null;
        do
        {
            set_time_limit(90);
            $ch = curl_init();
            if (! $ch)
            {
                throw new PaymentProcessorException("Could not connect to Beanstream payment gateway.");
            }
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);

#            curl_setopt($ch, CURLOPT_PROXY, "abillusia.com");
#            curl_setopt($ch, CURLOPT_PROXYPORT, 3128);

            # set URL and POST Fields
            # url should be https://www.beanstream.com/scripts/process_transaction.asp
            curl_setopt($ch, CURLOPT_URL, $this->_paymentProcessor['url_site']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);

            # get the response
            #error_log("Attempt $count to connect to Beanstream.");
            $responseData = curl_exec($ch);
            curl_close($ch);
        }
        while ($responseData === false && ++$count < 10);    # seems to time out fairly often, so try 10 times
        if (false === $responseData)
        {
            $info = curl_getinfo($ch);
            error_log("curl info: ".print_r($info, true));
#            error_log("response: ".print_r($responseData, true));
            error_log("post: ".print_r($postfields, true));
            #error_log(print_r($requestFields, true));
            #error_log(print_r($postfields, true));
            error_log("An error occurred while connecting to Beanstream to process a payment. Curl error ".curl_errno($ch)." '".curl_error($ch)."'.");
            error_log("Could not connect to ".$this->_paymentProcessor['url_site']);
            throw new PaymentProcessorException("The payment processor has DECLINED your request. [".curl_error($ch)."]");
        }

error_log("response: ".print_r($responseData, true));

        $trnResult = array();
        parse_str($responseData, $trnResult);

        # read and format transaction response
        if ($trnResult['responseType'] == "T")
        {
            # declined
            if ($trnResult['trnApproved'] != "1" && $trnResult['paymentMethod'] == "CC")
            {
                // CRM_Core_Error::statusBounce("The payment processor has DECLINED your request. [" .$trnResult['messageId']. " - ".$trnResult['messageText']."]");
                throw new PaymentProcessorException("The payment processor has DECLINED your request. [" .$trnResult['messageId']. " - ".$trnResult['messageText']."]");
            }
            else
            {
                # approved
                $params['trxn_id'] = $trnResult['trnId'];
                $params['trxn_result_code'] =  "Message: ".$trnResult['messageText']."Order number: ".$trnResult['trnOrderNumber'].
			" authcode: ".$trnResult['authCode']."CVD Response: ".$trnResult['cvdId'];
		$params += $completed;
                return $params;
            }
        }
    }

    # note: this is from the paypalimpl.php payment processor
    function doTransferCheckout(&$params, $component = 'contribute')
    {
        throw new Exception("transfer checkout is not supported");
        $config = CRM_Core_Config::singleton();

        if ($component != 'contribute' && $component != 'event')
        {
            throw new PaymentProcessorException(ts('Component is invalid'));
        }

        $notifyURL = $config->userFrameworkResourceURL.
            "extern/bean.php?reset=1&contactID={$params['contactID']}".
            "&contributionID={$params['contributionID']}".
            "&module={$component}";

        if ($component == 'event')
        {
            $notifyURL .= "&eventID={$params['eventID']}&participantID={$params['participantID']}";
        }
        else
        {
            $membershipID = CRM_Utils_Array::value('membershipID', $params);
            if ($membershipID)
            {
                $notifyURL .= "&membershipID=$membershipID";
            }
            $relatedContactID = CRM_Utils_Array::value('related_contact', $params);
            if ($relatedContactID)
            {
                $notifyURL .= "&relatedContactID=$relatedContactID";

                $onBehalfDupeAlert = CRM_Utils_Array::value('onbehalf_dupe_alert', $params);
                if ($onBehalfDupeAlert)
                {
                    $notifyURL .= "&onBehalfDupeAlert=$onBehalfDupeAlert";
                }
            }
        }

        $url        = ($component == 'event') ? 'civicrm/event/register' : 'civicrm/contribute/transact';
        $cancel     = ($component == 'event') ? '_qf_Register_display'   : '_qf_Main_display';
        $returnURL  = CRM_Utils_System::url($url, "_qf_ThankYou_display=1&qfKey={$params['qfKey']}", true, null, false);

        $cancelUrlString = "$cancel=1&cancel=1&qfKey={$params['qfKey']}";
        if (CRM_Utils_Array::value('is_recur', $params))
        {
            $cancelUrlString .= "&isRecur=1&recurId={$params['contributionRecurID']}&contribId={$params[contributionID]}";
        }

        $cancelURL = CRM_Utils_System::url($url, $cancelUrlString, true, null, false);

        // ensure that the returnURL is absolute.
        if (substr($returnURL, 0, 4) != 'http')
        {
            require_once 'CRM/Utils/System.php';
            $fixUrl = CRM_Utils_System::url("civicrm/admin/setting/url", '&reset=1');
            throw new PaymentProcessorException(ts("Cannot send a relative URL to a notify payment processor. ".
                "Please make your resource URL (in <a href=\"%1\">Administer CiviCRM &raquo; ".
                "Global Settings &raquo; Resource URLs</a>) complete.", array(1 => $fixUrl)));
        }

# todo: bean's paramater are different -- change this
        #$PaymentProcessorParams = array(
        #    'business'           => $this->_paymentProcessor['user_name'],
        #    'notify_url'         => $notifyURL,
        #    'item_name'          => $params['item_name'],
        #    'quantity'           => 1,
        #    'undefined_quantity' => 0,
        #    'cancel_return'      => $cancelURL,
        #    'no_note'            => 1,
        #    'no_shipping'        => 1,
        #    'return'             => $returnURL,
        #    'rm'                 => 2,
        #    'currency_code'      => $params['currencyID'],
        #    'invoice'            => $params['invoiceID'] ,
        #    'lc'                 => substr($config->lcMessages, -2),
        #    'charset'            => function_exists('mb_internal_encoding') ? mb_internal_encoding() : 'UTF-8',
        #    'custom'             => CRM_Utils_Array::value('accountingCode', $params)
        #    );
        $PaymentProcessorParams = self::mapParamsToBeanstreamFields($params);

        // add name and address if available, CRM-3130
        $otherVars = array(
            'first_name'         => 'first_name',
            'last_name'          => 'last_name',
            'street_address'     => 'address1',
            'country'            => 'country',
            'preferred_language' => 'lc',
            'city'               => 'city',
            'state_province'     => 'state',
            'postal_code'        => 'zip',
            'email'              => 'email'
            );

        foreach (array_keys($params) as $p)
        {
            // get the base name without the location type suffixed to it
            $parts = explode('-', $p);
            $name  = count($parts) > 1 ? $parts[0] : $p;
            if (isset($otherVars[$name]))
            {
                $value = $params[$p];
                if ($value)
                {
                    if ($name == 'state_province')
                    {
                        $stateName = CRM_Core_PseudoConstant::stateProvinceAbbreviation($value);
                        $value     = $stateName;
                    }
                    if ($name == 'country')
                    {
                        $countryName = CRM_Core_PseudoConstant::countryIsoCode($value);
                        $value       = $countryName;
                    }
                    // ensure value is not an array
                    // CRM-4174
                    if (! is_array($value))
                    {
                        $PaymentProcessorParams[$otherVars[$name]] = $value;
                    }
                }
            }
        }

        // if recurring donations, add a few more items
# todo: ignore recurring transactions for now. get this later
    #    if (! empty($params['is_recur']))
    #    {
    #        if ($params['contributionRecurID'])
    #        {
    #            $notifyURL .= "&contributionRecurID={$params['contributionRecurID']}&contributionPageID={$params['contributionPageID']}";
    #            $PaymentProcessorParams['notify_url'] = $notifyURL;
    #        }
    #        else
    #        {
    #            CRM_Core_Error::statusBounce(ts('Recurring contribution, but no database id'));
    #        }
    #
    #        $PaymentProcessorParams += array(
    #            'cmd'       => '_xclick-subscriptions',
    #            'a3'        => $params['amount'],
    #            'p3'        => $params['frequency_interval'],
    #            't3'        => ucfirst(substr($params['frequency_unit'], 0, 1)),
    #            'src'       => 1,
    #            'sra'       => 1,
    #            'srt'       => ($params['installments'] > 0) ? $params['installments'] : null,
    #            'no_note'   => 1,
    #            'modify'    => 0,
    #            );
    #    }
    #    else
    #    {
    #        $PaymentProcessorParams += array(
    #            'cmd'       => '_xclick',
    #            'amount'    => $params['amount'],
    #            );
    #    }

        // Allow manipulation of the arguments via custom hooks
        CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $PaymentProcessorParams);

        $uri = '';
        foreach ($PaymentProcessorParams as $key => $value)
        {
            if ($value === null)
            {
                continue;
            }

            $value = urlencode($value);
            if ($key == 'return' ||
                $key == 'cancel_return' ||
                $key == 'notify_url')
            {
                $value = str_replace('%2F', '/', $value);
            }
            $uri .= "&{$key}={$value}";
        }

        $uri = substr($uri, 1); # drop the leading '&' character
        $url = $this->_paymentProcessor['url_site'];
        $sub = null; #todo: is there a different url for recurring payments? empty($params['is_recur']) ? 'cgi-bin/webscr' : 'subscriptions';

        # redirect user to payment processor's payment form
# error_log("payment url: {$url}{$sub}?$uri");
#todo: uncomment this        CRM_Utils_System::redirect("{$url}{$sub}?$uri");
    }

    /**
     * This function checks to see if we have the right config values
     *
     * @return string the error message if any
     * @public
     */
    function checkConfig()
    {
        $error = array();
        if (empty($this->_paymentProcessor['signature']))
        {
            $error[] = ts('Merchant ID is not set in the Administer CiviCRM &raquo; Payment Processor.');
        }
        if (empty($this->_paymentProcessor['user_name']))
        {
            $error[] = ts('API Username is not set in the Administer CiviCRM &raquo; Payment Processor.');
        }
        if (empty($this->_paymentProcessor['password']))
        {
            $error[] = ts('API Password is not set in the Administer CiviCRM &raquo; Payment Processor.');
        }
        if (! empty($error))
        {
            return implode('<p>', $error);
        }
        else
        {
            return null;
        }
    }
}

