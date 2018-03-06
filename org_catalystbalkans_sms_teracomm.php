<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.5                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2014                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2014
 * $Id$
 *
 */
class org_catalystbalkans_sms_teracomm extends CRM_SMS_Provider
{

    const MAX_SMS_CHAR = 160;

    /**
     * api type to use to send a message
     * @var    string
     */
    protected $_apiType = 'http';

    /**
     * provider details
     * @var    string
     */
    protected $_providerInfo = array();

    /**
     * Teracomm API Server Session ID
     *
     * @var string
     */
    protected $_sessionID = NULL;

    /**
     * Curl handle resource id
     *
     */
    protected $_ch;

    /**
     * Temporary file resource id
     * @var    resource
     */
    protected $_fp;

    public $_apiURL = "https://bulkrssrv.allterco.net";

    protected $_messageType = array(
        'SMS_TEXT',
        'SMS_FLASH',
        'SMS_NOKIA_OLOGO',
        'SMS_NOKIA_GLOGO',
        'SMS_NOKIA_PICTURE',
        'SMS_NOKIA_RINGTONE',
        'SMS_NOKIA_RTTL',
        'SMS_NOKIA_CLEAN',
        'SMS_NOKIA_VCARD',
        'SMS_NOKIA_VCAL',
    );

    protected $_messageStatus = array(
        '0' => 'Queued',
        //'1' => 'Message queued',
        '1' => 'Delivered',
        '2' => 'Processing',
        '3' => 'Failed',
        '4' => 'Cancelled',
        //'007' => 'Error delivering message',
        //'008' => 'OK',
        //'009' => 'Routing error',
        //'010' => 'Message expired',
        '11' => 'DeliveredToMobile',
        '13' => 'NotDeliveredToMobile',
        '14' => 'Undeliverable',
    );

    /**
     * We only need one instance of this object. So we use the singleton
     * pattern and cache the instance in this variable
     *
     * @var object
     * @static
     */
    static private $_singleton = array();

    /**
     * Constructor
     *
     * Create and auth a Teracomm session.
     *
     * @param array $provider
     * @param bool $skipAuth
     *
     * @return \org_catalystbalkans_sms_teracomm
     */
    function __construct($provider = array(), $skipAuth = TRUE)
    {

 // initialize vars
        $this->_apiType = CRM_Utils_Array::value('api_type', $provider, 'http');
        $this->_providerInfo = $provider;

        // first create the curl handle

        /**
         * Reuse the curl handle
         */
        $this->_ch = curl_init();

        if (!$this->_ch || !is_resource($this->_ch)) {
            return PEAR::raiseError('Cannot initialise a new curl handle.');
        }

        curl_setopt($this->_ch, CURLOPT_TIMEOUT, 20);


        curl_setopt($this->_ch, CURLOPT_RETURNTRANSFER, 1);


        curl_setopt($this->_ch, CURLOPT_FAILONERROR, 1);
        if (ini_get('open_basedir') == '' && ini_get('safe_mode') == 'Off') {
            curl_setopt($this->_ch, CURLOPT_FOLLOWLOCATION, 1);
        }

    }

    /**
     * singleton function used to manage this object
     *
     * @param array $providerParams
     * @param bool $force
     * @return object
     * @static
     */
    static function &singleton($providerParams = array(), $force = FALSE)
    {
	$providerID = CRM_Utils_Array::value('provider_id', $providerParams); //returns null since providerID is not defined in query string
       $providerID = CRM_SMS_BAO_Provider::getProviderInfo($providerID,'id');
        //$providerName = CRM_SMS_BAO_Provider::getProviderInfo($providerID);

        $skipAuth = TRUE; //$providerID ? FALSE : TRUE; hack to skip authorization
        $cacheKey = (int)$providerID;



        if (!isset(self::$_singleton[$cacheKey]) || $force) {
            $provider = array();
            if ($providerID) {
                $provider = CRM_SMS_BAO_Provider::getProviderInfo($providerID);
            }
            self::$_singleton[$cacheKey] = new org_catalystbalkans_sms_teracomm($provider, $skipAuth);
        }
        return self::$_singleton[$cacheKey];
    }

    /**
     * Authenticate to the Teracomm API Server.
     *
     * @return mixed true on sucess or PEAR_Error object
     * @access public
     * @since 1.1
     */
    function authenticate()
    {
        $url = $this->_providerInfo['api_url'] . "/sendsms/sms.php";

        $postDataArray = array(
            'user' => $this->_providerInfo['username'],
            'pass' => $this->_providerInfo['password'],
            'api_id' => $this->_providerInfo['api_params']['api_id']
        );

        if (array_key_exists('is_test', $this->_providerInfo['api_params']) &&
            $this->_providerInfo['api_params']['is_test'] == 1
        ) {
            $response = array('data' => 'OK:' . rand());
        } else {
            $postData = $this->urlEncode($postDataArray);
            $response = $this->curl($url, $postData);
        }
        if (PEAR::isError($response)) {
            return $response;
        }
        //$sess = explode(":", $response['data']);
        $sess = preg_split('/\s+/', $response['data']);

        $this->_sessionID = trim($sess[1]);

        if ($sess[0] == "OK") {
            return TRUE;
        } else {
            return PEAR::raiseError($response['data']);
        }
    }

    /**
     * @param $url
     * @param $postDataArray
     * @param null $id
     *
     * @return object|string
     */
    function formURLPostData($url, &$postDataArray, $id = NULL)
    {
        $url = $this->_providerInfo['api_url'] . $url;
        //$postDataArray['session_id'] = $this->_sessionID;
        if ($id) {
            if (strlen($id) < 32 || strlen($id) > 32) {
                return PEAR::raiseError('Invalid API Message Id');
            }
            $postDataArray['apimsgid'] = $id;
        }
        return $url;
    }

    /**
     * Send an SMS Message via the Teracomm API Server
     *
     * @param $recipients
     * @param $header
     * @param $message
     * @param null $jobID
     * @param null $userID
     * @internal param \the $array message with a to/from/text
     *
     * @return mixed true on sucess or PEAR_Error object
     * @access public
     */
    function send($recipients, $header, $message, $jobID = NULL, $userID = NULL)
    {
        if ($this->_apiType == 'http') {
            $postDataArray = array();
            $url = $this->formURLPostData("/sendsms/sms.php", $postDataArray);

            $postDataArray['user'] = $this->_providerInfo['username'];
            $postDataArray['pass'] = $this->_providerInfo['password'];
//      $postDataArray['api_id'] = $this->_providerInfo['api_params']['api_id'];

            if (array_key_exists('from', $this->_providerInfo['api_params'])) {
                $postDataArray['nmb_from'] = $this->_providerInfo['api_params']['from'];
            }
            if (array_key_exists('concat', $this->_providerInfo['api_params'])) {
                $postDataArray['concat'] = $this->_providerInfo['api_params']['concat'];
            }
            //TODO:
            $postDataArray['nmb_to'] = $header['To'];
            $postDataArray['text'] = utf8_decode(substr($message, 0, 160)); // max of 160 characters, is probably not multi-lingual
            if (array_key_exists('mo', $this->_providerInfo['api_params'])) {
                $postDataArray['mo'] = $this->_providerInfo['api_params']['mo'];
            }
            // sendmsg with callback request:
            //$postDataArray['callback'] = 3;
            $postDataArray['dlrr'] = 1;

            $isTest = 0;
            if (array_key_exists('is_test', $this->_providerInfo['api_params']) &&
                $this->_providerInfo['api_params']['is_test'] == 1
            ) {
                $isTest = 1;
            }

            /**
             * Check if we are using a queue when sending as each account
             * with Clickatell is assigned three queues namely 1, 2 and 3.
             */
            if (isset($header['queue']) && is_numeric($header['queue'])) {
                if (in_array($header['queue'], range(1, 3))) {
                    $postDataArray['queue'] = $header['queue'];
                }
            }

            /**
             * Must we escalate message delivery if message is stuck in
             * the queue at Clickatell?
             */
            if (isset($header['escalate']) && !empty($header['escalate'])) {
                if (is_numeric($header['escalate'])) {
                    if (in_array($header['escalate'], range(1, 2))) {
                        $postDataArray['escalate'] = $header['escalate'];
                    }
                }
            }

            if ($isTest == 1) {
                $response = array('data' => 'ID:' . rand());
            } else {
                $postData = $this->urlEncode($postDataArray);
                $response = $this->curl($url, $postData);
            }
            if (PEAR::isError($response)) {
                return $response;
            }
            //$send = explode(":", $response['data']);
            $send = preg_split('/\s+/', $response['data']);

            if ($send[0] == "OK") {
                $this->createActivity(trim($send[1]), $message, $header, $jobID, $userID);
                return $send[1];
            } else {
                // TODO: Should add a failed activity instead.
                CRM_Core_Error::debug_log_message(json_encode($response) . " - for phone: {$postDataArray['nmb_to']}"); //$response['data']
                return PEAR::raiseError($response['data'], null, PEAR_ERROR_RETURN);
            }
        }
    }

    function send_mt($recipients, $header, $message, $jobID = NULL, $userID = NULL)
    {
        if ($this->_apiType == 'http') {
            $postDataArray = array();
            //$url = "http://sr.tera-com.com/tsmsgw/s.php";
            $url = $this->formURLPostData("/tsmsgw/s.php", $postDataArray);

            $this->_providerInfo['username'];
            $this->_providerInfo['password'];

            if (array_key_exists('from', $this->_providerInfo['api_params'])) {
                $postDataArray['nmb_from'] = $this->_providerInfo['api_params']['from'];
            }
            if (array_key_exists('concat', $this->_providerInfo['api_params'])) {
                $postDataArray['concat'] = $this->_providerInfo['api_params']['concat'];
            }

            //TODO:

            $postDataArray['text'] = utf8_decode(substr($message, 0, 160)); // max of 160 characters, is probably not multi-lingual
            if (array_key_exists('mo', $this->_providerInfo['api_params'])) {
                $postDataArray['mo'] = $this->_providerInfo['api_params']['mo'];
            }

            $postDataArray["msg_id"] = $header['msg_id'];
            $postDataArray["service_id"] = $header['service_id'];
            $postDataArray["type"] = $header['type'];
            $postDataArray["shortcode"] = $header['shortcode'];
            $postDataArray["msisdn"] = $header['msisdn'];
            $postDataArray["mcc"] = $header['mcc'];
            $postDataArray["mnc"] = $header['mnc'];

            $isTest = 0;
            if (array_key_exists('is_test', $this->_providerInfo['api_params']) &&
                $this->_providerInfo['api_params']['is_test'] == 1
            ) {
                $isTest = 1;
            }

            if ($isTest == 1) {
                $response = array('data' => 'ID:' . rand());
            } else {
                $postData = $this->urlEncode($postDataArray);
                $response = $this->curl($url, $postData);
            }
            if (PEAR::isError($response)) {
                return $response;
            }
            $send = preg_split('/\s+/', $response['data']);

            if ($send[0] == "OK") {
                $this->createActivity(trim($send[1]), $message, $header, $jobID, $userID);
                return $send[1];
            } else {
                // TODO: Should add a failed activity instead.
                CRM_Core_Error::debug_log_message(json_encode($response) . " - for phone: {$postDataArray['nmb_to']}"); //$response['data']
                return PEAR::raiseError($response['data'], null, PEAR_ERROR_RETURN);
            }
        }
    }

    /**
     * Callback is used for receiving errors or status updates on the messages alredy sent
     * A callback url could be setup in your provider account to receive 'SMS Status notifications'.
     * The example of a url is : http://www.example.com/civicrm/sms/callback?provider=clickatell
     *
     * @return bool
     */
    function callback()
    {

        if (array_key_exists('msg_id'))
	{
	$msg_id = $this->retrieve('msg_id', 'String');
	$apiMsgID = $msg_id;
	}


	if (array_key_exists('type', $_REQUEST)) {
		$type = $this->retrieve('type','String');

            switch ($type) {
                case "MO":
                    $this->process_mo();
                    return;
                case "REPORT":
                    $this->process_mt_response();
                    return;
            }
       }
        $activity = new CRM_Activity_DAO_Activity();
        $activity->result = $apiMsgID;

        if ($activity->find(TRUE)) {
            $actStatusIDs = array_flip(CRM_Core_OptionGroup::values('activity_status'));

            $status = $this->retrieve('dlr_code', 'String');

            switch ($status) {
                /* case "001":
                  $statusID = $actStatusIDs['Cancelled'];
                  $clickStat = $this->_messageStatus[$status] . " - Message Unknown";
                  break; */

                case "0":
                    $statusID = $actStatusIDs['Scheduled'];
                    $clickStat = $this->_messageStatus[$status] . " - Message Queued";
                    break;

                case "1":
                    $statusID = $actStatusIDs['Completed'];
                    $clickStat = $this->_messageStatus[$status] . " - Delivered to Gateway";
                    break;

                case "2":
                    $statusID = $actStatusIDs['Scheduled'];
                    $clickStat = $this->_messageStatus[$status] . " - Message being Processed"; //Received by Recipient
                    break;

                case "3":
                    $statusID = $actStatusIDs['Cancelled'];
                    $clickStat = $this->_messageStatus[$status] . " - Message Failed";
                    break;

                case "4":
                    $statusID = $actStatusIDs['Cancelled'];
                    $clickStat = $this->_messageStatus[$status] . " - User cancelled message";
                    break;

                case "11":
                    $statusID = $actStatusIDs['Completed'];
                    $clickStat = $this->_messageStatus[$status] . " - Message Delivered to Mobile";
                    break;

                case "13":
                    $statusID = $actStatusIDs['Cancelled'];
                    $clickStat = $this->_messageStatus[$status] . " - Message Not Delivered to Mobile";
                    break;

                case "14":
                    $statusID = $actStatusIDs['Cancelled'];
                    $clickStat = $this->_messageStatus[$status] . " - Message Undeliverable";
                    break;
            }

            if ($statusID) {
                // update activity with status + msg in location
                $activity->status_id = $statusID;
                $activity->location = $clickStat;
                $activity->activity_date_time = CRM_Utils_Date::isoToMysql($activity->activity_date_time);
                $activity->save();
                CRM_Core_Error::debug_log_message("SMS Response updated for apiMsgId={$apiMsgID}.");
                return TRUE;
            }
        }

        // if no update is done
        CRM_Core_Error::debug_log_message("Could not update SMS Response for apiMsgId={$apiMsgID}.");
        return FALSE;
    }

    function process_mo(){

        $msg_id = $this->retrieve('msg_id','String');
    	$service_id = $this->retrieve('service_id', 'String');
        $type = $this->retrieve('type', 'String'); //refactor
        $shortcode = $this->retrieve('shortcode', 'String');
        $msisdn = $this->retrieve('msisdn', 'String');
        $mcc = $this->retrieve('mcc', 'String'); //220
        $mnc = $this->retrieve('mnc', 'String');
        $text = $this->retrieve('text', 'String');
        $time = $this->retrieve('time', 'String');

        echo "OK " . $msg_id;

        //check message body for service info

        $result_campaign = civicrm_api3('Campaign', 'get', array(
            'sequential' => 1,
            //'campaign_id' => $text, //disabled for testing
	   'external_identifier' => $text,
	));

        //$result_campaign_json = json_decode($result_campaign,true);

       // $plannedamount = $result_campaign["values"][0]["goal_revenue"];

	    $header['msg_id'] = mt_rand(1,500000);
	    $header['service_id'] = $service_id;
        $header['shortcode'] = $shortcode;
        $header['msisdn'] = $msisdn;
        $header['mcc'] = $mcc;
        $header['mnc'] = $mnc;

        if ($result_campaign["count"]==0){

            //campaign does not exist
            //send MT message (type=FREE_MT)

            //get 'from' number first

           // $like = "";
           // $fromPhone = $this->retrieve('from', 'String');
           // $fromPhone = $this->formatPhone($this->stripPhone($msisdn), $like, "like");

            $header['type']="FREE_MT";
            $message= 'Donacije nije uspela jer kampanja ne postoji ili nije aktivna. Molimo pokusajte ponovo.';

	        //send MT message without charge (type = FREE_MT)
            $this->send_mt($msisdn,$header,$message);
        }
        else{
            //send MT message for charging (type=PREMIUM_MT)

            $header['type'] = "PREMIUM_MT";
	        $plannedamount = $result_campaign["values"][0]["goal_revenue"];

	        //get sum of existing donations

            $donacije = civicrm_api3('Contribution', 'get', array(
                'sequential' => 1,
                'return' => array("total_amount"),
                'campaign_id' => $text,
            ));

            $donacije_total = array_sum(array_column_recursive($donacije,"total_amount"));

	        //$actualamount = array_sum($donations)
            $message= "Hvala na donaciji. Do sada je za ovu kampanju prikupljeno " . $donacije_total . " od " . $plannedamount . " dinara." ;

            $this->send_mt($msisdn,$header,$message);
        }

        return;
    }

    function array_column_recursive(array $haystack, $needle) {
        $found = [];
        array_walk_recursive($haystack, function($value, $key) use (&$found, $needle) {
            if ($key == $needle)
                $found[] = $value;
        });
        return $found;
    }

    function process_mt_response(){

        $msg_id = $this->retrieve('msg_id','String');
	    $parent_msg_id = $this->retrieve('parent_msg_id','String');
        $service_id = $this->retrieve('service_id', 'String');
        $shortcode = $this->retrieve('shortcode', 'String');
        $msisdn = $this->retrieve('msisdn', 'String');
        $mcc = $this->retrieve('mcc', 'String');
        $mnc = $this->retrieve('mnc', 'String');
        $status = $this->retrieve('status','String');
        $text = $this->retrieve('text', 'String');
        $time = $this->retrieve('time', 'String');

        //get 'from' number

        $like = "";
        //$fromPhone = $this->retrieve('from', 'String');
        $fromPhone = $this->formatPhone($this->stripPhone($msisdn), $like, "like");

        if ($status == 'CHARGED') {

            // create contribution

            $result_create_contribution = civicrm_api3('Contact', 'get', array(
                'sequential' => 1,
                'phone' => $msisdn,
                'api.Contribution.create' => array(
                    'sequential' => 1,
                    'financial_type_id' => "SMS",
                    'total_amount' => 100,
                    'contact_id' => "user_contact_id",
                    'campaign_id' => $text,
                ),
            ));

            if ($result_create_contribution["count"] == 0) {

                //contact does not exist
                $result_create_contact = civicrm_api3('Contact', 'create', array(
                    'sequential' => 1,
                    'contact_type' => "Individual",
		    'first_name' => "",
                    'last_name' => "",
                    'email' => $msisdn . "@mobile.sms",
                    'display_name' => @msisdn,
                    'phone' => $msisdn,
                ));
$created_contact_id = $result_create_contact["id"];

                //create contribution for newly created contact
                $result_create_contribution = civicrm_api3('Contribution', 'create', array(
                        'sequential' => 1,
                        'financial_type_id' => "SMS",
                        'total_amount' => 100,
                        'contact_id' => $created_contact_id,
                        'campaign_id' => $text,
                    ));

                //send message ACK to sender
		echo "OK " . $msg_id;

            }
        }

        return;
    }

    /**
     * Inbound is used for handling messages replies
     * @return $this|null|object
     */
    function inbound()
    {

        //check for dlr_status or type


        if (array_key_exists('dlr_status', $_REQUEST) or array_key_exists('type', $_REQUEST)){
            $this->callback();
        }
        else{

            $like = "";
            $fromPhone = $this->retrieve('from', 'String');
            $fromPhone = $this->formatPhone($this->stripPhone($fromPhone), $like, "like");
            return parent::processInbound($fromPhone, $this->retrieve('text', 'String'), NULL, $this->retrieve('moMsgId', 'String'));
        }
    }

    /**
     * Perform curl stuff
     *
     * @param   string  URL to call
     * @param   string  HTTP Post Data
     *
     * @return  mixed   HTTP response body or PEAR Error Object
     * @access    private
     */
    function curl($url, $postData)
    {
	$this->_fp = tmpfile();

        curl_setopt($this->_ch, CURLOPT_URL, $url);
        curl_setopt($this->_ch, CURLOPT_POST, 1);
        curl_setopt($this->_ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($this->_ch, CURLOPT_FILE, $this->_fp);

        $status = curl_exec($this->_ch);

        $response['http_code'] = curl_getinfo($this->_ch, CURLINFO_HTTP_CODE);

        if (empty($response['http_code'])) {
            return PEAR::raiseError('No HTTP Status Code was returned.');
        } elseif ($response['http_code'] === 0) {
            return PEAR::raiseError('Cannot connect to the Teracomm API Server.');
        }

        if ($status) {
            $response['error'] = curl_error($this->_ch);
            $response['errno'] = curl_errno($this->_ch);
        }

        rewind($this->_fp);

        $pairs = "";
        while ($str = fgets($this->_fp, 4096)) {
            $pairs .= $str;
        }
        fclose($this->_fp);

        $response['data'] = $pairs;
        unset($pairs);
        asort($response);

        return ($response);
    }
}


