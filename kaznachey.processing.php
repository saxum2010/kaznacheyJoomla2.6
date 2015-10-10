<?
error_reporting(E_ALL);
header("Content-Type: text/html;charset=utf-8");
define ('_JEXEC','1');

include '../../../configuration.php';

$config = new JConfig;

$mysql=mysql_connect($config->host, $config->user, $config->password);
mysql_select_db($config->db);
mysql_query("SET NAMES utf8");
$prefix=$config->dbprefix;

$request_json = file_get_contents('php://input');
$request = json_decode($request_json, true);

if($request["MerchantInternalPaymentId"]){
	$c_order_id = $request["MerchantInternalPaymentId"];
}elseif($_POST['Payment']!=''){
	$c_order_id = intval($_POST['order_id']);
}

$query = mysql_fetch_array(mysql_query("SELECT * FROM ".$prefix."virtuemart_orders WHERE virtuemart_order_id='$c_order_id'"));

$conf_query = mysql_fetch_array(mysql_query("SELECT * FROM ".$prefix."virtuemart_paymentmethods WHERE virtuemart_paymentmethod_id='$query[virtuemart_paymentmethod_id]'"));

preg_match("#merch_guid=\"([^\"]*)\"\|merch_secret_key=\"([^\"]*)\"\|merch_redirect_page=\"([^\"]*)\"\|payment_language=\"([^\"]*)\"\|merch_order_status=\"([^\"]*)\"\|#isu",$conf_query['payment_params'],$conf_res);

$merch_guid=$conf_res[1];
$merch_secret_key=$conf_res[2];
$merch_redirect_page=$conf_res[3];
$payment_language=$conf_res[4];
$merch_order_status=$conf_res[5];

$request_sign = md5($request["ErrorCode"].
$request["OrderId"].
$request["MerchantInternalPaymentId"]. 
$request["MerchantInternalUserId"]. 
number_format($request["OrderSum"],2,".",""). 
number_format($request["Sum"],2,".",""). 
strtoupper($request["Currency"]). 
$request["CustomMerchantInfo"]. 
strtoupper($merch_secret_key));

if($request['SignatureEx'] == $request_sign) {
	mysql_query("UPDATE ".$prefix."virtuemart_orders SET order_status='$merch_order_status' WHERE virtuemart_order_id='$c_order_id'");
	die();
}

if ($_POST['Payment']!=''){
	$order_id = intval($_POST['order_id']);
	$pay_system = intval($_POST['SelectedPaySystemId']);
	
	if ($merch_redirect_page == '') $merch_redirect_page = 'http://'.$_SERVER['HTTP_HOST'].str_replace('processing','success',$_SERVER['SCRIPT_NAME']);
	
	$sum = 0;
	$user_id = $query['virtuemart_user_id'];
	$count = 0;
	
	$query_items = mysql_query("SELECT * FROM ".$prefix."virtuemart_order_items WHERE virtuemart_order_id='$order_id'");
	
	while ($res_items = mysql_fetch_array($query_items)){
		$count+=$res_items['product_quantity'];
		$this_product['ProductItemsNum']=$res_items['product_quantity'];
		$this_product['ProductName']=$res_items['order_item_name'];
		$this_product['ProductId']=$res_items['virtuemart_product_id'];
		$this_product['ProductPrice']=number_format($res_items['product_final_price'],2,'.','');
		
		$sum+=number_format($res_items['product_final_price'],2,'.','')*$res_items['product_quantity'];

		$q_product = mysql_fetch_array(mysql_query("SELECT product_parent_id FROM ".$prefix."virtuemart_products WHERE virtuemart_product_id='".$res_items['virtuemart_product_id']."'"));
		if ($q_product[0] > 0) $res_items['virtuemart_product_id']=$q_product[0];
		$q_media = mysql_fetch_array(mysql_query("SELECT virtuemart_media_id FROM ".$prefix."virtuemart_product_medias WHERE virtuemart_product_id='".$res_items['virtuemart_product_id']."' ORDER BY id ASC LIMIT 1"));
		$q_media2 = mysql_fetch_array(mysql_query("SELECT file_url FROM ".$prefix."virtuemart_medias WHERE virtuemart_media_id='$q_media[0]'"));
	
		$this_product['ImageUrl']='http://'.$_SERVER['HTTP_HOST'].str_replace('plugins/vmpayment/kaznachey/kaznachey.processing.php',"$q_media2[0]",$_SERVER['SCRIPT_NAME']);
		
		$products[]=$this_product;
	}
	
	//добавление цены доставки если есть
	if ($query['order_shipment']>0 OR $query['order_tax']>0){
		$this_product['ProductItemsNum']='1';
		$this_product['ProductName']='Доставка';
		$this_product['ProductId']='0';
		$this_product['ProductPrice']=number_format($query['order_shipment']+$query['order_shipment_tax']+$query['order_tax'],2,'.','');
		$this_product['ImageUrl']='';
		$products[]=$this_product;
		$count++;
		$sum+=number_format($query['order_shipment']+$query['order_shipment_tax']+$query['order_tax'],2,'.','');
	}

	$query_user_info=mysql_fetch_array(mysql_query("SELECT * FROM ".$prefix."virtuemart_order_userinfos WHERE virtuemart_order_id='$order_id'"));
	
	$query_currency=mysql_fetch_array(mysql_query("SELECT currency_code_3 FROM ".$prefix."virtuemart_currencies WHERE virtuemart_currency_id='".$query['order_currency']."'"));
	
	if ($query_user_info['phone_1'] == '') $phone = $query_user_info['phone_2'];
	else $phone = $query_user_info['phone_1'];
	
	$request['PaymentDetails'] = array(
		"MerchantInternalPaymentId"=>$order_id,// Номер платежа в системе мерчанта
		"MerchantInternalUserId"=>$user_id, //Номер пользователя в системе мерчанта
		"CustomMerchantInfo"=>$query['customer_note'],// Любая информация

		"Currency"=>$query_currency[0],
		"PhoneNumber"=>$query_user_info['phone_1'],
		"EMail"=>$query_user_info['email'],
		"BuyerFirstname"=>$query_user_info['first_name'],//Имя,
		"BuyerLastname"=>$query_user_info['last_name'],//Фамилия
		"BuyerStreet"=>$query_user_info['address_1'],// Адрес
		"BuyerZone"=>$query_user_info['virtuemart_state_id'],//   Область
		"BuyerZip"=>$query_user_info['zip'],//  Индекс
		"BuyerCity"=>$query_user_info['city'],//   Город,
		"BuyerCountry"=>$query_user_info['virtuemart_country_id'],//Страна
		"PhoneNumber"=>$phone, //Телефон

		//информация о доставке
		"DeliveryFirstname"=>$query_user_info['first_name'],// 
		"DeliveryLastname"=>$query_user_info['last_name'],//
		"DeliveryZip"=>$query_user_info['zip'],//     
		"DeliveryCountry"=>$query_user_info['virtuemart_country_id'],//   
		"DeliveryStreet"=>$query_user_info['address_1'],//   
		"DeliveryCity"=>$query_user_info['city'],//      
		"DeliveryZone"=>$query_user_info['virtuemart_state_id'],//

		"StatusUrl"=>'http://'.$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME'],// url состояния
		"ReturnUrl"=>$merch_redirect_page //url возврата       
	);
	
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
	
	$request["MerchantGuid"] = $merch_guid;
	$request['SelectedPaySystemId'] = $pay_system;
	$request['Currency'] = (is_array($query_currency))?$query_currency[0]:$query_currency;
	$request['Language'] = $payment_language;
	$request['Products'] = $products;
	
	$request["Signature"] = md5(strtoupper($request["MerchantGuid"]) .
		number_format($sum, 2, ".", "") . 
		$pay_system . 
		$request["PaymentDetails"]["EMail"] . 
		$request["PaymentDetails"]["PhoneNumber"] . 
		$request["PaymentDetails"]["MerchantInternalUserId"] . 
		$request["PaymentDetails"]["MerchantInternalPaymentId"] . 
		strtoupper($request["Language"]) . 
		strtoupper($request["Currency"]) . 
		strtoupper($merch_secret_key));
	
	$resMerchantPayment = json_decode(sendRequestKaznachey('http://payment.kaznachey.net/api/PaymentInterface/CreatePaymentEx', json_encode($request)),true);
	
	echo base64_decode($resMerchantPayment['ExternalForm']);
}

mysql_close($mysql);
?>