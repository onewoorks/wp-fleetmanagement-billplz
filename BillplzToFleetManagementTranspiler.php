<?php
/**
 * Billplz Payment Library to Fleet Management Transpiler class
 *
 * Transpiler - It's a source-to-source compiler, it translates source code
 * from one language to another (or to another version of the same language).
 *
 * @note - Library transpiler class should not have a namespace, because all transpilers are loaded as dynamic libraries
 * and that would anyway require a full-qualified namespaces for each transpiler constructor. So to avoid that,
 * we just do not use namespaces for transpilers at all.
 *
 * @package FleetManagement
 * @author Irwan Ibrahim
 * @copyright Onewoorks Solutions
 * @license See Legal/License.txt for details.
 */
use FleetManagement\Models\Configuration\ConfigurationInterface;
use FleetManagement\Models\Language\LanguageInterface;
use FleetManagement\Models\Order\Order;
use FleetManagement\Models\Customer\Customer;
use FleetManagement\Models\Order\OrdersObserver;
use FleetManagement\Models\Invoice\InvoicesObserver;
use FleetManagement\Models\Order\OrderNotificationsObserver;
use FleetManagement\Models\Invoice\Invoice;

require_once('FleetManagementPaymentInterface.php');
require_once(__DIR__.'/Billplz/configuration.php');

if(!class_exists('BillplzToFleetManagementTranspiler'))
{
    class BillplzToFleetManagementTranspiler implements \FleetManagementPaymentInterface
    {
        const PAYMENT_METHOD                = true;
        const PRODUCTION_HOST               = 'www.billplz.com';
        const SANDBOX_HOST                  = 'www.billplz-sandbox.com';
        protected $conf                     = null;
        protected $lang                     = null;
        protected $settings                 = array();
        protected $debugMode                = 0;

        // Array holds the fields to submit to <HOST>
        protected $fields                   = array();
        protected $use_ssl                  = true;

        protected $paymentMethodId          = 0;
        protected $paymentMethodCode        = 'BILLPLZ';
        protected $businessEmail            = '';
        protected $payInCurrencyRate        = 1.0000;
        protected $payInCurrencyCode        = 'MYR';
        protected $payInCurrencySymbol      = 'RM';
        protected $currencyCode             = 'MYR';
        protected $currencySymbol           = 'RM';
        // www.<SANDBOX_HOST>.com (true) or www.<PRODUCTION_HOST>.com (false)
        protected $useSandbox               = false;
        protected $checkCertificate         = false;
        protected $companyName              = '';
        protected $companyPhone             = '';
        protected $companyEmail             = '';
        protected $paymentCancelledPageId   = 0;
        protected $orderConfirmedPageId     = 0;
        protected $sendNotifications        = 0;
        protected $dbSets	                = null;
        protected $collection_id            = '';
        protected $api_key                  = '';    
        protected $x_signature              = '';

        /**
         * @param ConfigurationInterface &$paramConf
         * @param LanguageInterface &$paramLang
         * @param array $paramSettings
         * @param array $paramPaymentMethodDetails
         */
        public function __construct(ConfigurationInterface &$paramConf, LanguageInterface &$paramLang, array $paramSettings, array $paramPaymentMethodDetails)
        {
            $this->conf     = $paramConf;
            $this->lang     = $paramLang;
            $this->settings = $paramSettings;

            $this->paymentMethodId          = isset($paramPaymentMethodDetails['payment_method_id']) ? abs(intval($paramPaymentMethodDetails['payment_method_id'])) : 0;
            $this->paymentMethodCode        = isset($paramPaymentMethodDetails['payment_method_code']) ? sanitize_text_field($paramPaymentMethodDetails['payment_method_code']) : "";
            $this->businessEmail            = isset($paramPaymentMethodDetails['payment_method_email']) ? sanitize_email($paramPaymentMethodDetails['payment_method_email']) : "";
            $this->payInCurrencyRate        = isset($paramPaymentMethodDetails['pay_in_currency_rate']) ? floatval($paramPaymentMethodDetails['private_key']) : 1.000;
            $this->payInCurrencyCode        = !empty($paramPaymentMethodDetails['pay_in_currency_code']) ? sanitize_text_field($paramPaymentMethodDetails['pay_in_currency_code']) : '';
            $this->payInCurrencySymbol      = !empty($paramPaymentMethodDetails['pay_in_currency_symbol']) ? sanitize_text_field($paramPaymentMethodDetails['pay_in_currency_symbol']) : '';
            $this->useSandbox               = !empty($paramPaymentMethodDetails['sandbox_mode']) ? true : false;
            $this->checkCertificate         = !empty($paramPaymentMethodDetails['check_certificate']) ? true : false;
            $this->currencyCode             = isset($paramSettings['conf_currency_code']) ? sanitize_text_field($paramSettings['conf_currency_code']) : 'USD';
            $this->currencySymbol           = isset($paramSettings['conf_currency_symbol']) ? sanitize_text_field($paramSettings['conf_currency_symbol']) : '$';
            $this->companyName              = isset($paramSettings['conf_company_name']) ? sanitize_text_field($paramSettings['conf_company_name']) : '';
            $this->companyPhone             = isset($paramSettings['conf_company_phone']) ? sanitize_text_field($paramSettings['conf_company_phone']) : '';
            $this->companyEmail             = isset($paramSettings['conf_company_email']) ? sanitize_email($paramSettings['conf_company_email']) : '';
            $this->paymentCancelledPageId   = isset($paramSettings['conf_cancelled_payment_page_id']) ? abs(intval($paramSettings['conf_cancelled_payment_page_id'])) : 0;
            $this->orderConfirmedPageId     = isset($paramSettings['conf_confirmation_page_id']) ? abs(intval($paramSettings['conf_confirmation_page_id'])) : 0;
            $this->sendNotifications        = isset($paramSettings['conf_send_emails']) ? abs(intval($paramSettings['conf_send_emails'])) : 0;
            
            $this->api_key          = base64_encode(trim($paramPaymentMethodDetails['public_key']).':');
            $billplz_sec            = explode('|', $paramPaymentMethodDetails['private_key']);
            $this->x_signature      = $billplz_sec[0];
            $this->collection_id    = $billplz_sec[1];
        }

        /**
         * @return bool
         */
        public function inDebug()
        {
            return ($this->debugMode >= 1 ? true : false);
        }
        public function getDescriptionHTML($paramCurrentDescription, $paramTotalPayNow = '0.00')
        {
            return $paramCurrentDescription;
        }

        public function createBillPlzBill($payload){
            $postvars = '';
            foreach($payload as $key=>$value) {
                $postvars .= $key . "=" . $value . "&";
            }
    
            $url = $this->getFormSubmitURL();
            echo $url;
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS =>   $postvars,
                CURLOPT_HTTPHEADER => array(
                    'Authorization: Basic '.$this->api_key,
                    'Content-Type: application/x-www-form-urlencoded'
                ),
        ));
            $response = curl_exec($curl);
            curl_close($curl);
            $r = json_decode($response);
            return $r;
        }

        public function checkBillId($bill_id){
            $url = $this->getFormSubmitURL();
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $url.$bill_id,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    'Authorization: Basic '.$this->api_key,
                    'Content-Type: application/x-www-form-urlencoded'
                ),
        ));
            $response = curl_exec($curl);
            curl_close($curl);
            $r = json_decode($response);
            return $r;
        }

        public function getProcessingPage($paramOrderCode, $paramTotalPayNow = '0.00')
        {
            $sanitizedOrderCode     = sanitize_text_field($paramOrderCode);
            $objOrdersObserver      = new OrdersObserver($this->conf, $this->lang, $this->settings);
            $orderId                = $objOrdersObserver->getIdByCode($sanitizedOrderCode);
            $objOrder               = new Order($this->conf, $this->lang, [], $orderId);
            $orderDetails           = $objOrder->getDetails(true);
            $objCustomer            = new Customer($this->conf, $this->lang, [], $orderDetails['customer_id']);
            $customerDetails        = $objCustomer->getDetails(true); 
            
            $validPositiveTotalPayNow = $paramTotalPayNow > 0 ? floatval($paramTotalPayNow) : 0.00;
            if($this->payInCurrencyCode != ''){
                $positiveAmountToPay    = $this->payInCurrencyRate > 0 ? $validPositiveTotalPayNow / $this->payInCurrencyRate : $validPositiveTotalPayNow;
                $payInCurrencyCode      = $this->payInCurrencyCode;
                $payInCurrencySymbol    = $this->payInCurrencySymbol;
            } else{
                $positiveAmountToPay    = $validPositiveTotalPayNow;
                $payInCurrencyCode      = $this->currencyCode;
                $payInCurrencySymbol     = $this->currencySymbol;
            }
            if ($this->businessEmail != ""){
                $notifyURL = site_url().'/?__'.$this->conf->getPluginPrefix().'api=1&ext_code='.$this->conf->getExtCode();
                $notifyURL .= '&ext_action=payment-callback&payment_method_id='.$this->paymentMethodId;

                $order_description = sprintf("Booking Code: %s",$orderDetails['booking_code']);

                $payload = (object) array(
                    'collection_id'     => $this->collection_id,
                    'email'             => $customerDetails['email'],
                    'mobile'            => $customerDetails['phone'],
                    'name'              => $customerDetails['print_full_name'],
                    'amount'            => (float) $positiveAmountToPay * 100,
                    'callback_url'      => urlencode($notifyURL),
                    'redirect_url'      => urlencode($notifyURL),
                    'description'       => $order_description,
                    'reference_1_label' => 'Booking Code',
                    'reference_1'       => $orderDetails['booking_code']
                );
                $billplz_response = $this->createBillPlzBill($payload);
            }

            $output = '<h2>'.$this->lang->escHTML('LANG_PAYMENT_PROCESSING_TEXT').'</h2>
                    <div class="info-content">
                        '.$this->lang->escHTML('LANG_ORDER_CODE2_TEXT').': '.esc_html($sanitizedOrderCode).'.<br />
                        '.$this->lang->escHTML('LANG_ORDER_PLEASE_WAIT_UNTIL_WILL_BE_PROCESSED_TEXT').'
                    </div>';
            $output .= "<script>
                setTimeout(function(){
                    window.location = '$billplz_response->url';
                },1500)
            </script>";
                
            return array(
                'payment_completed_transaction_id'  => 0, 
                'currency_code'                     => $payInCurrencyCode,
                'currency_symbol'                   => $payInCurrencySymbol,
                'amount'                            => $positiveAmountToPay,
                'errors'                            => array(),
                'debug_messages'                    => array(),
                'trusted_output_html'               => $output, 
            );
        }

        private function successPayment($ordercode, $amount){
            $confirmURL = $this->orderConfirmedPageId > 0 ? $this->lang->getTranslatedURL($this->orderConfirmedPageId) : site_url();
            
            $order_code             = $ordercode;
            $payInCurrencyCode      = $this->payInCurrencyCode;
            $paramTransactionAmount = $amount/100;
            $positiveAmountToPay    = abs(floatval($paramTransactionAmount));

            $objOrdersObserver              = new OrdersObserver($this->conf, $this->lang, $this->settings);
            $objOrderNotificationsObserver  = new OrderNotificationsObserver($this->conf, $this->lang, $this->settings);
            $objInvoicesObserver            = new InvoicesObserver($this->conf, $this->lang, $this->settings);

            $orderId = $objOrdersObserver->getIdByCode($order_code);
            if($orderId > 0) {
                $objOrder               = new Order($this->conf, $this->lang, $this->settings, $orderId);
                $customerId             = $objOrder->getCustomerId();

                $objCustomer            = new Customer($this->conf, $this->lang, $this->settings, $customerId);
                $overallInvoiceId       = $objInvoicesObserver->getIdByParams('OVERALL', $orderId, -1);
                $objOverallInvoice      = new Invoice($this->conf, $this->lang, $this->settings, $overallInvoiceId);

                $transactionDateI18n    = date_i18n(get_option('date_format'), time() + get_option('gmt_offset') * 3600, true);
                $transactionTimeI18n    = date_i18n(get_option('time_format'), time() + get_option('gmt_offset') * 3600, true);
                $transactionType        = $this->lang->getText('LANG_TRANSACTION_TYPE_PAYMENT_TEXT');
                $transactionAmount      = sanitize_text_field($payInCurrencyCode) . ' ' . floatval($positiveAmountToPay);

                $paymentHTML_ToAppend = '<!-- EXTERNAL TRANSACTION DETAILS -->
                <br /><br />
                <h2>Reservation confirmed</h2>
                <p>Thank you. We received your payment. Your reservation is now confirmed.</p>
                <table style="font-family:Verdana, Geneva, sans-serif; font-size: 12px; background-color:#eeeeee; width:840px; border:none;" cellpadding="5" cellspacing="1">
                <tr>
                <td align="left" width="30%" style="font-weight:bold; background-color:#ffffff; padding-left:5px;">' . $this->lang->escHTML('LANG_TRANSACTION_DATE_TEXT') . '</td>
                <td align="left" style="background-color:#ffffff; padding-left:5px;">' . $transactionDateI18n . ' ' . $transactionTimeI18n . '</td>
                </tr>
                <tr>
                <td align="left" style="font-weight:bold; background-color:#ffffff; padding-left:5px;">' . $this->lang->escHTML('LANG_TRANSACTION_TYPE_TEXT') . '</td>
                <td align="left" style="background-color:#ffffff; padding-left:5px;">' . esc_html($transactionType) . '</td>
                </tr>
                <tr>
                <td align="left" style="font-weight:bold; background-color:#ffffff; padding-left:5px;">' . $this->lang->escHTML('LANG_TRANSACTION_AMOUNT_TEXT') . '</td>
                <td align="left" style="background-color:#ffffff; padding-left:5px;">' . esc_html($transactionAmount) . '</td>
                </tr>
                </table>';

                $objOrder->confirm($orderId, $objCustomer->getEmail());
                $objOverallInvoice->appendHTML_ToFinalInvoice($paymentHTML_ToAppend);
                if ($this->sendNotifications) {
                    $objOrderNotificationsObserver->sendOrderConfirmedNotifications($orderId, true);
                }
                header("Location: {$confirmURL}");   
            }
        }

        private function failedPayment($ordercode){
            $cancelURL = $this->paymentCancelledPageId > 0 ? $this->lang->getTranslatedURL($this->paymentCancelledPageId) : site_url();
            header("Location: {$cancelURL}");
        }
        
        public function processCallback()
        {
            
            $bill_id        = $_REQUEST['billplz']['id'];
            $bill_status    = $this->checkBillId($bill_id);
            $ordercode      = $bill_status->reference_1;
            if(strtolower($bill_status->state) == 'paid'){
                $amount     = $bill_status->paid_amount;
                $this->successPayment($ordercode, $amount);
            }
            if(strtolower($bill_status->state) == 'due'){
                $this->failedPayment($ordercode);
            }
        }
        private function getWebHost()
        {
            if ($this->useSandbox)
            {
                return static::SANDBOX_HOST;
            } else
            {
                return static::PRODUCTION_HOST;
            }
        }    

        private function getFormSubmitURL()
        {
            if ($this->use_ssl)
            {
                $uri = 'https://'.$this->getWebHost().'/api/v3/bills/';
            } else
            {
                $uri = 'http://'.$this->getWebHost().'/api/v3/bills/';
            }

            return $uri;
        }

        }
}