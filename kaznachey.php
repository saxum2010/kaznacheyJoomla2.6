<?php

define( '_JEXEC', 1 );
define('JPATH_BASE', dirname(__FILE__) );
if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

class plgVMPaymentKaznachey extends vmPSPlugin {

    // instance of class
    public static $_this = false;

    function __construct(& $subject, $config){
		//if (self::$_this)
		 //   return self::$_this;
		parent::__construct($subject, $config);

		$this->_loggable = true;

		$varsToPush = array(
			'merch_guid' => array('', 'char'),
			'merch_secret_key' => array('', 'int'),
			'merch_redirect_page' => array('', 'char'),
			'payment_language' => array('', 'char'),
			'merch_order_status' => array('', 'char')
		);

		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }
	
	protected function checkConditions($cart, $method, $cart_prices){
		return true;
	}

	function plgVmConfirmedOrder($cart, $order){	
		
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {return null;}
		if (!$this->selectedThisElement($method->payment_element)) {return false;}
		
		$session = JFactory::getSession();
		$return_context = $session->getId();
	
		$requestMerchantInfo = Array(
			"MerchantGuid"=>$method->merch_guid,
			"Signature"=>md5($method->merch_guid.$method->merch_secret_key)
		);
		
		$resMerchantInfo = json_decode($this->sendRequestKaznachey('http://payment.kaznachey.net/api/PaymentInterface/GetMerchantInformation', json_encode($requestMerchantInfo)),true);
		
		$action_url = JROUTE::_(JURI::root().'plugins/vmpayment/kaznachey/kaznachey.processing.php');

		$html = "<form action='$action_url' method='post'>";				$html .= "<b>Выберите вариант оплаты</b> : <br /><br />";
		
		foreach ($resMerchantInfo['PaySystems'] as $value){
			if ($checked != 1) $checked_text = 'checked';
			$html .="<input type='radio' name='SelectedPaySystemId' value='$value[Id]' ".$checked_text.">$value[PaySystemName] <br />";
			$checked = 1;
		}
		
		$html .= "<br /><input type='checkbox' name='confirm' checked> Согласен с <a href='$resMerchantInfo[TermToUse]'>условиями использования</a><br /><br />";
		$html .= "<input type='hidden' name='order_id' value='".$order['details']['BT']->virtuemart_order_id."'>";
		$html .= '<input type="submit" name="Payment" value="Оплатить сейчас"></form>';
	
		//$cart->emptyCart ();
		JRequest::setVar ('html', $html);
	}
	
	function plgVmOnPaymentNotification(){
		$method = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id);
	}

	//объявление настроек
    function plgVmDeclarePluginParamsPayment($name, $id, &$data){
		return $this->declarePluginParams('payment', $name, $id, $data);
    }

	//сохранение настроек
    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table){
		return $this->setOnTablePluginParams($name, $id, $table);
    }
	
	//отображение в списке доступных способов оплаты
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn){
		return $this->displayListFE($cart, $selected, $htmlIn);
    }
	
	public function plgVmOnSelectCheckPayment(VirtueMartCart $cart){
		return $this->OnSelectCheck($cart);
    }
	
	//отображение в заказе
	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
	  $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }
	
	function plgVmonShowOrderPrintPayment($order_number, $method_id) {
		return $this->onShowOrderPrint($order_number, $method_id);
    }
	
	public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name){
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }
	
	//запрос к серверу казначей
	function sendRequestKaznachey($url,$data){
		$curl =curl_init();
		
		if (!$curl) return false;
		
		curl_setopt($curl, CURLOPT_URL,$url );
		curl_setopt($curl, CURLOPT_POST,true);
		curl_setopt($curl, CURLOPT_HTTPHEADER,array("Expect: ","Content-Type: application/json; charset=UTF-8",'Content-Length: '. strlen($data)));
		curl_setopt($curl, CURLOPT_POSTFIELDS,$data);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER,True);
		$res =  curl_exec($curl);
		curl_close($curl);
		return $res;
	}
	
	function _geteshop_id($method) {
		return $method->eshop_id;
    }
	
}
// No closing tag
