<?php
class Ecomatic_Collectorbank_IndexController extends Mage_Core_Controller_Front_Action{
	
	public function indexAction(){
		$cart = Mage::getSingleton('checkout/cart');
		$messages = array();
        foreach ($cart->getQuote()->getMessages() as $message) {
            if ($message) {
                // Escape HTML entities in quote message to prevent XSS
                $message->setCode(Mage::helper('core')->escapeHtml($message->getCode()));
                $messages[] = $message;
            }
        }
        $cart->getCheckoutSession()->addUniqueMessages($messages);
		$this->loadLayout()
			->_initLayoutMessages('checkout/session')
			->_initLayoutMessages('catalog/session');
		$this->renderLayout();
	}
	
	/* Redirection URL Action */	
	public function BsuccessAction() {
		
		$session = Mage::getSingleton('checkout/session');
		
		$logFileName = 'magentoorder.log';
		
		Mage::log('----------------- START ------------------------------- ', null, $logFileName);	
		
		$quote = $session->getQuote();
		$quoteId = $quote->getEntityId();
		
		
		$typeData = $session->getTypeData();
		
		$privateId = $session->getBusinessPrivateId();
		if($privateId){
			$orderData = Mage::getModel('collectorbank/api')->getOrderResponse();	
			//echo "<pre>business ";print_r($orderData);die;
			if(isset($orderData['error'])){
				$session->addError($orderData['error']['message']);
				$this->_redirect('checkout/cart');
				return;
			}
			$orderDetails = $orderData['data'];
			if ($orderDetails['purchase']["paymentName"] == "DirectInvoice"){
				$session->setData('use_fee', 1);
			}
			else {
				$session->setData('use_fee', 5);
			}
		
		if($orderDetails){
			$email = $orderDetails['businessCustomer']['email'];
			$mobile = $orderDetails['businessCustomer']['mobilePhoneNumber'];
			$firstName = $orderDetails['businessCustomer']['deliveryAddress']['companyName'];
			$lastName = $orderDetails['businessCustomer']['referencePerson'];
			
			
			$store = Mage::app()->getStore();
			$website = Mage::app()->getWebsite();
			$customer = Mage::getModel('customer/customer')->setWebsiteId($website->getId())->loadByEmail($email);
				// if the customer is not already registered
				if (!$customer->getId()) {
					$customer = Mage::getModel('customer/customer');			
					$customer->setWebsiteId($website->getId())
							 ->setStore($store)
							 ->setFirstname($firstName)
							 ->setLastname($lastName)
							 ->setEmail($email);  
					try {
						$password = $customer->generatePassword();         
						$customer->setPassword($password);        
						// set the customer as confirmed
						$customer->setForceConfirmed(true);        
						// save customer
						$customer->save();        
						$customer->setConfirmation(null);
						$customer->save();
						
						// set customer address
						$customerId = $customer->getId();        
						$customAddress = Mage::getModel('customer/address');            
						$customAddress->setData($billingAddress)
									  ->setCustomerId($customerId)
									  ->setIsDefaultBilling('1')
									  ->setIsDefaultShipping('1')
									  ->setSaveInAddressBook('1');
						
						// save customer address
						$customAddress->save();
						// send new account email to customer    
						
						$storeId = $customer->getSendemailStoreId();
						$customer->sendNewAccountEmail('registered', '', $storeId);
						
						Mage::log('Customer with email '.$email.' is successfully created.', null, $logFileName);
						
					} catch (Mage_Core_Exception $e) {						
						Mage::log('Cannot add customer for  '.$e->getMessage(), null, $logFileName);
					} catch (Exception $e) {
						Mage::log('Cannot add customer for  '.$email, null, $logFileName);
					} 
				}
				
			// Assign Customer To Sales Order Quote
			$quote->assignCustomer($customer);
			
			if($orderDetails['customer']['deliveryAddress']['country'] == 'Sverige'){	
				$scountry_id = "SE";
			}
			else if ($orderDetails['customer']['deliveryAddress']['country'] == 'Norge'){
				$scountry_id = "NO";
			}
			if($orderDetails['customer']['billingAddress']['country'] == 'Sverige'){  
				$bcountry_id = "SE";
			}
			else if ($orderDetails['customer']['billingAddress']['country'] == 'Norge'){
				$scountry_id = "NO";
			}

			
			$billingAddress = array(
				'customer_address_id' => '',
				'prefix' => '',
				'firstname' => $firstName,
				'middlename' => '',
				'lastname' => $lastName,
				'suffix' => '',
				'company' => $orderDetails['businessCustomer']['invoiceAddress']['companyName'], 
				'street' => array(
					 '0' => $orderDetails['businessCustomer']['invoiceAddress']['address'], // compulsory
					 '1' => $orderDetails['businessCustomer']['invoiceAddress']['address2'] // optional
				 ),
				'city' => $orderDetails['businessCustomer']['invoiceAddress']['city'],
				'country_id' => $scountry_id, // two letters country code
				'region' => '', // can be empty '' if no region
				'region_id' => '', // can be empty '' if no region_id
				'postcode' => $orderDetails['businessCustomer']['invoiceAddress']['postalCode'],
				'telephone' => $mobile,
				'fax' => '',
				'save_in_address_book' => 1
			);
		
			$shippingAddress = array(
				'customer_address_id' => '',
				'prefix' => '',
				'firstname' => $firstName,
				'middlename' => '',
				'lastname' => $lastName,
				'suffix' => '',
				'company' => $orderDetails['businessCustomer']['deliveryAddress']['companyName'], 
				'street' => array(
					 '0' => $orderDetails['businessCustomer']['deliveryAddress']['address'], // compulsory
					 '1' => $orderDetails['businessCustomer']['deliveryAddress']['address2'] // optional
				 ),
				'city' => $orderDetails['businessCustomer']['deliveryAddress']['city'],
				'country_id' => $scountry_id, // two letters country code
				'region' => '', // can be empty '' if no region
				'region_id' => '', // can be empty '' if no region_id
				'postcode' => $orderDetails['businessCustomer']['deliveryAddress']['postalCode'],
				'telephone' => $mobile,
				'fax' => '',
				'save_in_address_book' => 1
			);
		
		
			// Add billing address to quote
			$billingAddressData = $quote->getBillingAddress()->addData($billingAddress);
		 
			// Add shipping address to quote
			$shippingAddressData = $quote->getShippingAddress()->addData($shippingAddress);
			
			//check for selected shipping method
			$shippingMethod = $session->getSelectedShippingmethod();
			if(empty($shippingMethod)){
				$allShippingData = Mage::getModel('collectorbank/config')->getActiveShppingMethods();
				$orderItems = $orderDetails['order']['items'];
				foreach($orderItems as $oitem){
					//echo "<pre>";print_r($oitem);
					if(in_array($oitem['id'], $allShippingData)) {
						$shippingMethod = $oitem['id'];						
						break;
					}
				}
			}

			// Collect shipping rates on quote shipping address data
			$shippingAddressData->setCollectShippingRates(true)->collectShippingRates();

			// Set shipping and payment method on quote shipping address data
			$shippingAddressData->setShippingMethod($shippingMethod);			
			
			
			$paymentMethod = 'collectorbank_invoice';
			// Set shipping and payment method on quote shipping address data
			$shippingAddressData->setPaymentMethod($paymentMethod);			
			
			$colpayment_method = $orderDetails['purchase']['paymentMethod'];
			$colpayment_details = json_encode($orderDetails['purchase']);
			
			
			// Set payment method for the quote
			$quote->getPayment()->importData(array('method' => $paymentMethod,'coll_payment_method' => $colpayment_method,'coll_payment_details' => $colpayment_details));
			
			//die;
			try{
				$orderReservedId = $session->getReference();
				$quote->setResponse($orderDetails);
				$quote->setCollCustomerType($orderDetails['customerType']);
				$quote->setCollBusinessCustomer($orderDetails['businessCustomer']);
				$quote->setCollStatus($orderDetails['status']);
				$quote->setCollPurchaseIdentifier($orderDetails['purchase']['purchaseIdentifier']);
				$quote->setCollTotalAmount($orderDetails['order']['totalAmount']);
				if($orderDetails['reference'] == $orderReservedId){
					$quote->setReservedOrderId($orderReservedId);
				} else {
					$quote->setReservedOrderId($orderDetails['reference']);
				}
							
			 	// Collect totals of the quote
				$quote->collectTotals();
				$quote->save();
				
				$service = Mage::getModel('sales/service_quote', $quote);
				$service->submitAll();
				$incrementId = $service->getOrder()->getRealOrderId();
				
				if($session->getIsSubscribed() == 1){
					Mage::getModel('newsletter/subscriber')->subscribe($email);
				} 				
				
				$session->setLastQuoteId($quote->getId())
					->setLastSuccessQuoteId($quote->getId())
					->clearHelperData();
					
				Mage::getSingleton('checkout/session')->clear();
				Mage::getSingleton('checkout/cart')->truncate()->save();
				
				
				$session->unsBusinessPrivateId();
				$session->unsReference();
				
				
				
				 // Log order created message
				Mage::log('Order created with increment id: '.$incrementId, null, $logFileName);						
				$result['success'] = true;
				$result['error']   = false;
				
				$order = Mage::getModel('sales/order')->loadByIncrementId($incrementId);
				$this->loadLayout();
				$block = Mage::app()->getLayout()->getBlock('collectorbank_success');
				if ($block){//check if block actually exists					
						if ($order->getId()) {
							$orderId = $order->getId();
							$isVisible = !in_array($order->getState(),Mage::getSingleton('sales/order_config')->getInvisibleOnFrontStates());
							$block->setOrderId($incrementId);
							$block->setIsOrderVisible($isVisible);
							$block->setViewOrderId($block->getUrl('sales/order/view/', array('order_id' => $orderId)));
							$block->setViewOrderUrl($block->getUrl('sales/order/view/', array('order_id' => $orderId)));
							$block->setPrintUrl($block->getUrl('sales/order/print', array('order_id'=> $orderId)));
							$block->setCanPrintOrder($isVisible);
							$block->setCanViewOrder(Mage::getSingleton('customer/session')->isLoggedIn() && $isVisible);
						}
				}
				Mage::dispatchEvent('checkout_onepage_controller_success_action', array('order_ids' => array($order->getId())));
				$this->renderLayout();			
				
				
			} catch (Mage_Core_Exception $e) {
					$result['success'] = false;
					$result['error'] = true;
					$result['error_messages'] = $e->getMessage();    
					Mage::log('Order creation is failed for invoice no '.$orderDetails['purchase']['purchaseIdentifier'] ."Error is --> ".Mage::helper('core')->jsonEncode($result), null, $logFileName);		
					$this->loadLayout();
					$block = Mage::app()->getLayout()->getBlock('collectorbank_success');
					if ($block){
						if($orderDetails['purchase']['purchaseIdentifier']){
							$block->setInvoiceNo($orderDetails['purchase']['purchaseIdentifier']);
						} else {
							$block->setCode(222);
						}
					}
					$this->renderLayout();					
			} 			
		} 
		
		} else {
			Mage::log('Order is already generated.', null, $logFileName);		
			$this->loadLayout();   
			$block = Mage::app()->getLayout()->getBlock('collectorbank_success');
			if ($block){
				$block->setCode(111);
			}
			$this->renderLayout();
		}

		Mage::log('----------------- END ------------------------------- ', null, $logFileName);		
	}
	
	/* Redirection URL Action */	
	public function SuccessAction() {
		
		//echo "in b2b";die;
		$session = Mage::getSingleton('checkout/session');
		
		$logFileName = 'magentoorder.log';
		
		Mage::log('----------------- START ------------------------------- ', null, $logFileName);	
		
		$quote = $session->getQuote();
		$quoteId = $quote->getEntityId();
		$privateId = $session->getPrivateId();
		
		
		
		if($privateId){
			$orderData = Mage::getModel('collectorbank/api')->getOrderResponse();	
			
			if(isset($orderData['error'])){
				$session->addError($orderData['error']['message']);
				$this->_redirect('checkout/cart');
				return;
			}
			$orderDetails = $orderData['data'];
			if ($orderDetails['purchase']["paymentName"] == "DirectInvoice"){
				$session->setData('use_fee', 1);
			}
			else {
				$session->setData('use_fee', 5);
			}
		
		
		if($orderDetails){		
			$email = $orderDetails['customer']['email'];
			$mobile = $orderDetails['customer']['mobilePhoneNumber'];
			$firstName = $orderDetails['customer']['deliveryAddress']['firstName'];
			$lastName = $orderDetails['customer']['deliveryAddress']['lastName'];
			
			
			$store = Mage::app()->getStore();
			$website = Mage::app()->getWebsite();
			$customer = Mage::getModel('customer/customer')->setWebsiteId($website->getId())->loadByEmail($email);
				// if the customer is not already registered
				if (!$customer->getId()) {
					$customer = Mage::getModel('customer/customer');			
					$customer->setWebsiteId($website->getId())
							 ->setStore($store)
							 ->setFirstname($firstName)
							 ->setLastname($lastName)
							 ->setEmail($email);  
					try {
					   
						$password = $customer->generatePassword();         
						$customer->setPassword($password);        
						// set the customer as confirmed
						$customer->setForceConfirmed(true);        
						// save customer
						$customer->save();        
						$customer->setConfirmation(null);
						$customer->save();
						
						// set customer address
						$customerId = $customer->getId();        
						$customAddress = Mage::getModel('customer/address');            
						$customAddress->setData($billingAddress)
									  ->setCustomerId($customerId)
									  ->setIsDefaultBilling('1')
									  ->setIsDefaultShipping('1')
									  ->setSaveInAddressBook('1');
						
						// save customer address
						$customAddress->save();
						// send new account email to customer    
						
						$storeId = $customer->getSendemailStoreId();
						$customer->sendNewAccountEmail('registered', '', $storeId);
						
						Mage::log('Customer with email '.$email.' is successfully created.', null, $logFileName);
						
					} catch (Mage_Core_Exception $e) {						
						Mage::log('Cannot add customer for  '.$e->getMessage(), null, $logFileName);
					} catch (Exception $e) {
						Mage::log('Cannot add customer for  '.$email, null, $logFileName);
					} 
				}
				
			// Assign Customer To Sales Order Quote
			$quote->assignCustomer($customer);
			
			if($orderDetails['customer']['deliveryAddress']['country'] == 'Sverige'){	
				$scountry_id = "SE";
			}
			else if ($orderDetails['customer']['deliveryAddress']['country'] == 'Norge'){
				$scountry_id = "NO";
			}
			if($orderDetails['customer']['billingAddress']['country'] == 'Sverige'){  
				$bcountry_id = "SE";
			}
			else if ($orderDetails['customer']['billingAddress']['country'] == 'Norge'){
				$scountry_id = "NO";
			}

			
			$billingAddress = array(
				'customer_address_id' => '',
				'prefix' => '',
				'firstname' => $firstName,
				'middlename' => '',
				'lastname' => $lastName,
				'suffix' => '',
				'company' => $orderDetails['customer']['billingAddress']['coAddress'], 
				'street' => array(
					 '0' => $orderDetails['customer']['billingAddress']['address'], // compulsory
					 '1' => $orderDetails['customer']['billingAddress']['address2'] // optional
				 ),
				'city' => $orderDetails['customer']['billingAddress']['city'],
				'country_id' => $scountry_id, // two letters country code
				'region' => '', // can be empty '' if no region
				'region_id' => '', // can be empty '' if no region_id
				'postcode' => $orderDetails['customer']['billingAddress']['postalCode'],
				'telephone' => $mobile,
				'fax' => '',
				'save_in_address_book' => 1
			);
		
			$shippingAddress = array(
				'customer_address_id' => '',
				'prefix' => '',
				'firstname' => $firstName,
				'middlename' => '',
				'lastname' => $lastName,
				'suffix' => '',
				'company' => $orderDetails['customer']['deliveryAddress']['coAddress'], 
				'street' => array(
					 '0' => $orderDetails['customer']['deliveryAddress']['address'], // compulsory
					 '1' => $orderDetails['customer']['deliveryAddress']['address2'] // optional
				 ),
				'city' => $orderDetails['customer']['deliveryAddress']['city'],
				'country_id' => $scountry_id, // two letters country code
				'region' => '', // can be empty '' if no region
				'region_id' => '', // can be empty '' if no region_id
				'postcode' => $orderDetails['customer']['deliveryAddress']['postalCode'],
				'telephone' => $mobile,
				'fax' => '',
				'save_in_address_book' => 1
			);
		
		
			// Add billing address to quote
			$billingAddressData = $quote->getBillingAddress()->addData($billingAddress);
		 
			// Add shipping address to quote
			$shippingAddressData = $quote->getShippingAddress()->addData($shippingAddress);
			
			//check for selected shipping method
			$shippingMethod = $session->getSelectedShippingmethod();
			$allShippingData = Mage::getModel('collectorbank/config')->getActiveShppingMethods();
			$orderItems = $orderDetails['order']['items'];
			if(empty($shippingMethod)){
				foreach($orderItems as $oitem){
					//echo "<pre>";print_r($oitem);
					if(in_array($oitem['id'], $allShippingData)) {
						$shippingMethod = $oitem['id'];						
						break;
					}
				}
			}
			
			
			
			
			
			foreach($orderItems as $oitem){
				//echo "<pre>";print_r($oitem);
				if(in_array($oitem['id'], $allShippingData)) {
					$shippingPrice = $oitem['unitPrice'];
					$shippingTax = $oitem['vat'];
					break;
				}
			}
			
			
			
			
			
			
			
			
			
			
			
			$shippingAddressData->setShippingAmount($shippingPrice);
                        $shippingAddressData->setBaseShippingAmount($shippingPrice);
			
			
			// Collect shipping rates on quote shipping address data
			$shippingAddressData->setCollectShippingRates(true)->collectShippingRates();

			// Set shipping and payment method on quote shipping address data
			$shippingAddressData->setShippingMethod($shippingMethod);	

			if ($shippingTax != 0){
				$shippingAddressData->setShippingAmount($shippingPrice/($shippingTax/100+1));
				$shippingAddressData->setBaseShippingAmount($shippingPrice/($shippingTax/100+1));
			}
			else {
				$shippingAddressData->setShippingAmount($shippingPrice);
                                $shippingAddressData->setBaseShippingAmount($shippingPrice);
			}
                        $shippingAddressData->setShippingInclTax($shippingPrice);
			$shippingAddressData->save();



			//$paymentMethod = 'collectorpay';
			$paymentMethod = 'collectorbank_invoice';
			// Set shipping and payment method on quote shipping address data
			$shippingAddressData->setPaymentMethod($paymentMethod);			

			$colpayment_method = $orderDetails['purchase']['paymentMethod'];
			$colpayment_details = json_encode($orderDetails['purchase']);

			// Set payment method for the quote
			$quote->getPayment()->importData(array('method' => $paymentMethod,'coll_payment_method' => $colpayment_method,'coll_payment_details' => $colpayment_details));


			try{
				$orderReservedId = $session->getReference();
				$quote->setResponse($orderDetails);
				$quote->setCollCustomerType($orderDetails['customerType']);
				$quote->setCollBusinessCustomer($orderDetails['businessCustomer']);
				$quote->setCollStatus($orderDetails['status']);
				$quote->setCollPurchaseIdentifier($orderDetails['purchase']['purchaseIdentifier']);
				$quote->setCollTotalAmount($orderDetails['order']['totalAmount']);
				if($orderDetails['reference'] == $orderReservedId){
					$quote->setReservedOrderId($orderReservedId);
				} else {
					$quote->setReservedOrderId($orderDetails['reference']);
				}
				
			 	// Collect totals of the quote
				$quote->collectTotals();
				$quote->save();
				
				$service = Mage::getModel('sales/service_quote', $quote);
				$service->submitAll();
				$incrementId = $service->getOrder()->getRealOrderId();
				
				if($session->getIsSubscribed() == 1){
					Mage::getModel('newsletter/subscriber')->subscribe($email);
				} 				
				
				$session->setLastQuoteId($quote->getId())
					->setLastSuccessQuoteId($quote->getId())
					->clearHelperData();
					
				Mage::getSingleton('checkout/session')->clear();
				Mage::getSingleton('checkout/cart')->truncate()->save();
				
				$session->unsPrivateId();
				$session->unsReference();
				
				 // Log order created message
				Mage::log('Order created with increment id: '.$incrementId, null, $logFileName);						
				$result['success'] = true;
				$result['error']   = false;
				
				$order = Mage::getModel('sales/order')->loadByIncrementId($incrementId);
				
				
				
				$oldShippingAmount = $order->getShippingAmount();
				$oldShippingTaxAmount = $order->getShippingTaxAmount();
				if ($shippingTax != 0){
					$order->setShippingAmount($shippingPrice/($shippingTax/100+1));
	                                $order->setBaseShippingAmount($shippingPrice/($shippingTax/100+1));
					$order->setShippingTaxAmount($shippingPrice-($shippingPrice/($shippingTax/100+1)));
                                        $order->setBaseShippingTaxAmount($shippingPrice-($shippingPrice/($shippingTax/100+1)));
				}
				else {
					$order->setShippingAmount($shippingPrice);
	                                $order->setBaseShippingAmount($shippingPrice);
					$order->setShippingTaxAmount(0);
					$order->setBaseShippingTaxAmount(0);
				}
				$order->setShippingInclTax($shippingPrice);
				$order->setBaseShippingInclTax($shippingPrice);
				$orderGrandTotalAdjustment = $order->getShippingAmount() + $order->getShippingTaxAmount() - $oldShippingAmount - $oldShippingTaxAmount;
				$order->setGrandTotal($order->getGrandTotal()+$orderGrandTotalAdjustment);
				$order->setBaseGrandTotal($order->getBaseGrandTotal()+$orderGrandTotalAdjustment);
				
				
				
				
				
				
				
				if ($orderDetails["purchase"]["result"] == "OnHold"){
					$pending = Mage::getStoreConfig('ecomatic_collectorbank/general/pending_order_status');
					$order->setState($pending, true);
					$order->save();
				}
				else if ($orderDetails["purchase"]["result"] == "Preliminary"){
					$auth = Mage::getStoreConfig('ecomatic_collectorbank/general/authorized_order_status');
					$order->setState($auth, true);
					$order->save();
				}
				else {
					$denied = Mage::getStoreConfig('ecomatic_collectorbank/general/denied_order_status');
					$order->setState($denied, true);
					$order->save();
				}
				
				
				$this->loadLayout();
				$block = Mage::app()->getLayout()->getBlock('collectorbank_success');
				if ($block){//check if block actually exists					
						if ($order->getId()) {
							$orderId = $order->getId();
							$isVisible = !in_array($order->getState(),Mage::getSingleton('sales/order_config')->getInvisibleOnFrontStates());
							$block->setOrderId($incrementId);
							$block->setIsOrderVisible($isVisible);
							$block->setViewOrderId($block->getUrl('sales/order/view/', array('order_id' => $orderId)));
							$block->setViewOrderUrl($block->getUrl('sales/order/view/', array('order_id' => $orderId)));
							$block->setPrintUrl($block->getUrl('sales/order/print', array('order_id'=> $orderId)));
							$block->setCanPrintOrder($isVisible);
							$block->setCanViewOrder(Mage::getSingleton('customer/session')->isLoggedIn() && $isVisible);
						}
				}
				Mage::dispatchEvent('checkout_onepage_controller_success_action', array('order_ids' => array($order->getId())));
				$this->renderLayout();			
				
				
			} catch (Mage_Core_Exception $e) {
					$result['success'] = false;
					$result['error'] = true;
					$result['error_messages'] = $e->getMessage();    
					Mage::log('Order creation is failed for invoice no '.$orderDetails['purchase']['purchaseIdentifier'] ."Error is --> ".Mage::helper('core')->jsonEncode($result), null, $logFileName);		
					//Mage::app()->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
					$this->loadLayout();
					$block = Mage::app()->getLayout()->getBlock('collectorbank_success');
					if ($block){
						if($orderDetails['purchase']['purchaseIdentifier']){
							$block->setInvoiceNo($orderDetails['purchase']['purchaseIdentifier']);
						} else {
							$block->setCode(222);
						}
					}
					$this->renderLayout();					
			} 			
		} 
		
		} else {
			Mage::log('Order is already generated.', null, $logFileName);		
			$this->loadLayout();   
			$block = Mage::app()->getLayout()->getBlock('collectorbank_success');
			if ($block){
				$block->setCode(111);
			}
			$this->renderLayout();
		}

		Mage::log('----------------- END ------------------------------- ', null, $logFileName);		
	}
	
	/* Notification URL Action */
	public function NotificationAction(){
		if (isset($_GET['OrderNo']) && isset($_GET['InvoiceStatus'])){
			$order = Mage::getModel('sales/order')->loadByIncrementId($_GET['OrderNo']);
			if ($order->getId()){
				if ($_GET['InvoiceStatus'] == "0"){
					$pending = Mage::getStoreConfig('ecomatic_collectorbank/general/pending_order_status');
					$order->setState($pending, true);
					$order->save();
				}
				else if ($_GET['InvoiceStatus'] == "1"){
					$auth = Mage::getStoreConfig('ecomatic_collectorbank/general/authorized_order_status');
					$order->setState($auth, true);
					$order->save();
				}
				else {
					$denied = Mage::getStoreConfig('ecomatic_collectorbank/general/denied_order_status');
					$order->setState($denied, true);
					$order->save();
				}
				$this->loadLayout();
				$this->renderLayout();
			}
			else {
				$quote = Mage::getModel('sales/quote')->getCollection()->addFieldToFilter('reserved_order_id', $_GET['OrderNo'])->getFirstItem();
				
				if ($quote->getId()) {
					$btype = $quote->getData('coll_customer_type');
					$privId = $quote->getData('coll_purchase_identifier');
					$resp = $this->getResp($privId, $btype);
					ob_start();
					print_r($btype);
					echo "\n";
					print_r($privId);
					echo "\n";
					print_r($resp);
					file_put_contents("test", "resp: " . ob_get_clean() . "\n", FILE_APPEND);
					if ($btype == 'b2b'){
						$this->createB2BOrder($quote, $resp, $privId, $_GET['OrderNo']);
					}
					else {
						$this->createB2COrder($quote, $resp, $privId, $_GET['OrderNo']);
					}
				}
				else {
					file_put_contents("test", "no order with id: " . $_GET['OrderNo'] . "\n", FILE_APPEND);
					$this->loadLayout();
					$this->renderLayout();
				}
			}
		}
	}
	
	public function createB2BOrder($quote, $orderData, $privateId, $orderId){
		
		$session = Mage::getSingleton('checkout/session');
		
		$logFileName = 'magentoorder.log';
		
		Mage::log('----------------- START ------------------------------- ', null, $logFileName);	
		
		if(isset($orderData['error'])){
			$session->addError($orderData['error']['message']);
			$this->_redirect('checkout/cart');
			return;
		}
		$orderDetails = $orderData['data'];
		if ($orderDetails['purchase']["paymentName"] == "DirectInvoice"){
			$session->setData('use_fee', 1);
		}
		else {
			$session->setData('use_fee', 5);
		}
		$email = $orderDetails['businessCustomer']['email'];
		$mobile = $orderDetails['businessCustomer']['mobilePhoneNumber'];
		$firstName = $orderDetails['businessCustomer']['deliveryAddress']['companyName'];
		$lastName = $orderDetails['businessCustomer']['referencePerson'];
		
		
		$store = Mage::app()->getStore();
		$website = Mage::app()->getWebsite();
		$customer = Mage::getModel('customer/customer')->setWebsiteId($website->getId())->loadByEmail($email);
		// if the customer is not already registered
		if (!$customer->getId()) {
			$customer = Mage::getModel('customer/customer');			
			$customer->setWebsiteId($website->getId())
					 ->setStore($store)
					 ->setFirstname($firstName)
					 ->setLastname($lastName)
					 ->setEmail($email);  
			try {
				$password = $customer->generatePassword();         
				$customer->setPassword($password);        
				// set the customer as confirmed
				$customer->setForceConfirmed(true);        
				// save customer
				$customer->save();        
				$customer->setConfirmation(null);
				$customer->save();
				
				// set customer address
				$customerId = $customer->getId();        
				$customAddress = Mage::getModel('customer/address');            
				$customAddress->setData($billingAddress)
							  ->setCustomerId($customerId)
							  ->setIsDefaultBilling('1')
							  ->setIsDefaultShipping('1')
							  ->setSaveInAddressBook('1');
				
				// save customer address
				$customAddress->save();
				// send new account email to customer    
				
				$storeId = $customer->getSendemailStoreId();
				$customer->sendNewAccountEmail('registered', '', $storeId);
				
				Mage::log('Customer with email '.$email.' is successfully created.', null, $logFileName);
				
			} catch (Mage_Core_Exception $e) {						
				Mage::log('Cannot add customer for  '.$e->getMessage(), null, $logFileName);
			} catch (Exception $e) {
				Mage::log('Cannot add customer for  '.$email, null, $logFileName);
			} 
		}
		$quote->assignCustomer($customer);
		if($orderDetails['customer']['deliveryAddress']['country'] == 'Sverige'){	
			$scountry_id = "SE";
		}
		else if ($orderDetails['customer']['deliveryAddress']['country'] == 'Norge'){
			$scountry_id = "NO";
		}
		if($orderDetails['customer']['billingAddress']['country'] == 'Sverige'){  
			$bcountry_id = "SE";
		}
		else if ($orderDetails['customer']['billingAddress']['country'] == 'Norge'){
			$scountry_id = "NO";
		}
		$billingAddress = array(
			'customer_address_id' => '',
			'prefix' => '',
			'firstname' => $firstName,
			'middlename' => '',
			'lastname' => $lastName,
			'suffix' => '',
			'company' => $orderDetails['businessCustomer']['invoiceAddress']['companyName'], 
			'street' => array(
				 '0' => $orderDetails['businessCustomer']['invoiceAddress']['address'], // compulsory
				 '1' => $orderDetails['businessCustomer']['invoiceAddress']['address2'] // optional
			 ),
			'city' => $orderDetails['businessCustomer']['invoiceAddress']['city'],
			'country_id' => $scountry_id, // two letters country code
			'region' => '', // can be empty '' if no region
			'region_id' => '', // can be empty '' if no region_id
			'postcode' => $orderDetails['businessCustomer']['invoiceAddress']['postalCode'],
			'telephone' => $mobile,
			'fax' => '',
			'save_in_address_book' => 1
		);
	
		$shippingAddress = array(
			'customer_address_id' => '',
			'prefix' => '',
			'firstname' => $firstName,
			'middlename' => '',
			'lastname' => $lastName,
			'suffix' => '',
			'company' => $orderDetails['businessCustomer']['deliveryAddress']['companyName'], 
			'street' => array(
				 '0' => $orderDetails['businessCustomer']['deliveryAddress']['address'], // compulsory
				 '1' => $orderDetails['businessCustomer']['deliveryAddress']['address2'] // optional
			 ),
			'city' => $orderDetails['businessCustomer']['deliveryAddress']['city'],
			'country_id' => $scountry_id, // two letters country code
			'region' => '', // can be empty '' if no region
			'region_id' => '', // can be empty '' if no region_id
			'postcode' => $orderDetails['businessCustomer']['deliveryAddress']['postalCode'],
			'telephone' => $mobile,
			'fax' => '',
			'save_in_address_book' => 1
		);
		$billingAddressData = $quote->getBillingAddress()->addData($billingAddress);
		$shippingAddressData = $quote->getShippingAddress()->addData($shippingAddress);
		$allShippingData = Mage::getModel('collectorbank/config')->getActiveShppingMethods();
		$orderItems = $orderDetails['order']['items'];
		foreach($orderItems as $oitem){
			if(in_array($oitem['id'], $allShippingData)) {
				$shippingMethod = $oitem['id'];						
				break;
			}
		}
		$shippingAddressData->setCollectShippingRates(true)->collectShippingRates();
		$shippingAddressData->setShippingMethod($shippingMethod);
		$paymentMethod = 'collectorbank_invoice';
		$shippingAddressData->setPaymentMethod($paymentMethod);
		$colpayment_method = $orderDetails['purchase']['paymentMethod'];
		$colpayment_details = json_encode($orderDetails['purchase']);
		$quote->getPayment()->importData(array('method' => $paymentMethod,'coll_payment_method' => $colpayment_method,'coll_payment_details' => $colpayment_details));
		try{
			$orderReservedId = $session->getReference();
			$quote->setResponse($orderDetails);
			$quote->setCollCustomerType($orderDetails['customerType']);
			$quote->setCollBusinessCustomer($orderDetails['businessCustomer']);
			$quote->setCollStatus($orderDetails['status']);
			$quote->setCollPurchaseIdentifier($orderDetails['purchase']['purchaseIdentifier']);
			$quote->setCollTotalAmount($orderDetails['order']['totalAmount']);
			if($orderDetails['reference'] == $orderReservedId){
				$quote->setReservedOrderId($orderReservedId);
			} else {
				$quote->setReservedOrderId($orderDetails['reference']);
			}
			$quote->collectTotals();
			$quote->save();
			$service = Mage::getModel('sales/service_quote', $quote);
			$service->submitAll();
			$incrementId = $service->getOrder()->getRealOrderId();
			if($session->getIsSubscribed() == 1){
				Mage::getModel('newsletter/subscriber')->subscribe($email);
			}
			$session->setLastQuoteId($quote->getId())->setLastSuccessQuoteId($quote->getId())->clearHelperData();
			Mage::getSingleton('checkout/session')->clear();
			Mage::getSingleton('checkout/cart')->truncate()->save();
			$session->unsBusinessPrivateId();
			$session->unsReference();
			Mage::log('Order created with increment id: '.$incrementId, null, $logFileName);						
			$result['success'] = true;
			$result['error']   = false;
			$order = Mage::getModel('sales/order')->loadByIncrementId($incrementId);
			$this->loadLayout();
			$block = Mage::app()->getLayout()->getBlock('collectorbank_success');
			if ($block){//check if block actually exists					
				if ($order->getId()) {
					$orderId = $order->getId();
					$isVisible = !in_array($order->getState(),Mage::getSingleton('sales/order_config')->getInvisibleOnFrontStates());
					$block->setOrderId($incrementId);
					$block->setIsOrderVisible($isVisible);
					$block->setViewOrderId($block->getUrl('sales/order/view/', array('order_id' => $orderId)));
					$block->setViewOrderUrl($block->getUrl('sales/order/view/', array('order_id' => $orderId)));
					$block->setPrintUrl($block->getUrl('sales/order/print', array('order_id'=> $orderId)));
					$block->setCanPrintOrder($isVisible);
					$block->setCanViewOrder(Mage::getSingleton('customer/session')->isLoggedIn() && $isVisible);
				}
			}
			Mage::dispatchEvent('checkout_onepage_controller_success_action', array('order_ids' => array($order->getId())));
			$this->renderLayout();
		} 
		catch (Mage_Core_Exception $e) {
			$result['success'] = false;
			$result['error'] = true;
			$result['error_messages'] = $e->getMessage();    
			Mage::log('Order creation is failed for invoice no '.$orderDetails['purchase']['purchaseIdentifier'] ."Error is --> ".Mage::helper('core')->jsonEncode($result), null, $logFileName);		
			$this->loadLayout();
			$block = Mage::app()->getLayout()->getBlock('collectorbank_success');
			if ($block){
				if($orderDetails['purchase']['purchaseIdentifier']){
					$block->setInvoiceNo($orderDetails['purchase']['purchaseIdentifier']);
				} else {
					$block->setCode(222);
				}
			}
			$this->renderLayout();					
		}
		Mage::log('----------------- END ------------------------------- ', null, $logFileName);		
	}
	
	public function createB2COrder($quote, $orderData, $privateId, $orderId){
		$logFileName = 'magentoorder.log';
		
		$session = Mage::getSingleton('checkout/session');
		Mage::log('----------------- START ------------------------------- ', null, $logFileName);	
		
		if(isset($orderData['error'])){
			$session->addError($orderData['error']['message']);
			$this->_redirect('checkout/cart');
			return;
		}
		$orderDetails = $orderData['data'];
		if ($orderDetails['purchase']["paymentName"] == "DirectInvoice"){
			$session->setData('use_fee', 1);
		}
		else {
			$session->setData('use_fee', 5);
		}
		
		Mage::log('1', null, $logFileName);	
	
		$email = $orderDetails['customer']['email'];
		$mobile = $orderDetails['customer']['mobilePhoneNumber'];
		$firstName = $orderDetails['customer']['deliveryAddress']['firstName'];
		$lastName = $orderDetails['customer']['deliveryAddress']['lastName'];
		
		
		$store = Mage::app()->getStore();
		$website = Mage::app()->getWebsite();
		$customer = Mage::getModel('customer/customer')->setWebsiteId($website->getId())->loadByEmail($email);
		// if the customer is not already registered
		if (!$customer->getId()) {
			$customer = Mage::getModel('customer/customer');			
			$customer->setWebsiteId($website->getId())->setStore($store)->setFirstname($firstName)->setLastname($lastName)->setEmail($email);  
			try {
				$password = $customer->generatePassword();         
				$customer->setPassword($password);        
				// set the customer as confirmed
				$customer->setForceConfirmed(true);        
				// save customer
				$customer->save();        
				$customer->setConfirmation(null);
				$customer->save();
				
				// set customer address
				$customerId = $customer->getId();        
				$customAddress = Mage::getModel('customer/address');            
				$customAddress->setData($billingAddress)->setCustomerId($customerId)->setIsDefaultBilling('1')->setIsDefaultShipping('1')->setSaveInAddressBook('1');
				
				// save customer address
				$customAddress->save();
				// send new account email to customer
				$storeId = $customer->getSendemailStoreId();
				$customer->sendNewAccountEmail('registered', '', $storeId);
				Mage::log('Customer with email '.$email.' is successfully created.', null, $logFileName);
			} 
			catch (Mage_Core_Exception $e) {						
				Mage::log('Cannot add customer for  '.$e->getMessage(), null, $logFileName);
			} 
			catch (Exception $e) {
				Mage::log('Cannot add customer for  '.$email, null, $logFileName);
			}
		}
		// Assign Customer To Sales Order Quote
		$quote->assignCustomer($customer);
		if($orderDetails['customer']['deliveryAddress']['country'] == 'Sverige'){	
			$scountry_id = "SE";
		}
		else if ($orderDetails['customer']['deliveryAddress']['country'] == 'Norge'){
			$scountry_id = "NO";
		}
		if($orderDetails['customer']['billingAddress']['country'] == 'Sverige'){  
			$bcountry_id = "SE";
		}
		else if ($orderDetails['customer']['billingAddress']['country'] == 'Norge'){
			$scountry_id = "NO";
		}
		$billingAddress = array(
			'customer_address_id' => '',
			'prefix' => '',
			'firstname' => $firstName,
			'middlename' => '',
			'lastname' => $lastName,
			'suffix' => '',
			'company' => $orderDetails['customer']['billingAddress']['coAddress'], 
			'street' => array(
				 '0' => $orderDetails['customer']['billingAddress']['address'], // compulsory
				 '1' => $orderDetails['customer']['billingAddress']['address2'] // optional
			 ),
			'city' => $orderDetails['customer']['billingAddress']['city'],
			'country_id' => $scountry_id, // two letters country code
			'region' => '', // can be empty '' if no region
			'region_id' => '', // can be empty '' if no region_id
			'postcode' => $orderDetails['customer']['billingAddress']['postalCode'],
			'telephone' => $mobile,
			'fax' => '',
			'save_in_address_book' => 1
		);
	Mage::log('2', null, $logFileName);	
		$shippingAddress = array(
			'customer_address_id' => '',
			'prefix' => '',
			'firstname' => $firstName,
			'middlename' => '',
			'lastname' => $lastName,
			'suffix' => '',
			'company' => $orderDetails['customer']['deliveryAddress']['coAddress'], 
			'street' => array(
				 '0' => $orderDetails['customer']['deliveryAddress']['address'], // compulsory
				 '1' => $orderDetails['customer']['deliveryAddress']['address2'] // optional
			 ),
			'city' => $orderDetails['customer']['deliveryAddress']['city'],
			'country_id' => $scountry_id, // two letters country code
			'region' => '', // can be empty '' if no region
			'region_id' => '', // can be empty '' if no region_id
			'postcode' => $orderDetails['customer']['deliveryAddress']['postalCode'],
			'telephone' => $mobile,
			'fax' => '',
			'save_in_address_book' => 1
		);
		$billingAddressData = $quote->getBillingAddress()->addData($billingAddress);
		$shippingAddressData = $quote->getShippingAddress()->addData($shippingAddress);
		$allShippingData = Mage::getModel('collectorbank/config')->getActiveShppingMethods();
		$orderItems = $orderDetails['order']['items'];
		foreach($orderItems as $oitem){
			if(in_array($oitem['id'], $allShippingData)) {
				$shippingMethod = $oitem['id'];						
				break;
			}
		}
		foreach($orderItems as $oitem){
			if(in_array($oitem['id'], $allShippingData)) {
				$shippingPrice = $oitem['unitPrice'];
				$shippingTax = $oitem['vat'];
				break;
			}
		}
	Mage::log('3', null, $logFileName);	
		$shippingAddressData->setShippingAmount($shippingPrice);
		$shippingAddressData->setBaseShippingAmount($shippingPrice);
		$shippingAddressData->setCollectShippingRates(true)->collectShippingRates();
		$shippingAddressData->setShippingMethod($shippingMethod);
		if ($shippingTax != 0){
			$shippingAddressData->setShippingAmount($shippingPrice/($shippingTax/100+1));
			$shippingAddressData->setBaseShippingAmount($shippingPrice/($shippingTax/100+1));
		}
		else {
			$shippingAddressData->setShippingAmount($shippingPrice);
			$shippingAddressData->setBaseShippingAmount($shippingPrice);
		}
		$shippingAddressData->setShippingInclTax($shippingPrice);
		$shippingAddressData->save();
		$paymentMethod = 'collectorbank_invoice';
		$shippingAddressData->setPaymentMethod($paymentMethod);			
		$colpayment_method = $orderDetails['purchase']['paymentMethod'];
		$colpayment_details = json_encode($orderDetails['purchase']);
		$quote->getPayment()->importData(array('method' => $paymentMethod,'coll_payment_method' => $colpayment_method,'coll_payment_details' => $colpayment_details));
		try{
	Mage::log('4', null, $logFileName);	
			$orderReservedId = $orderId;
			$quote->setResponse($orderDetails);
			$quote->setCollCustomerType($orderDetails['customerType']);
			$quote->setCollBusinessCustomer($orderDetails['businessCustomer']);
			$quote->setCollStatus($orderDetails['status']);
			$quote->setCollPurchaseIdentifier($orderDetails['purchase']['purchaseIdentifier']);
			$quote->setCollTotalAmount($orderDetails['order']['totalAmount']);
			if($orderDetails['reference'] == $orderReservedId){
				$quote->setReservedOrderId($orderReservedId);
			} else {
				$quote->setReservedOrderId($orderDetails['reference']);
			}
			$quote->collectTotals();
			$quote->save();
			$service = Mage::getModel('sales/service_quote', $quote);
			$service->submitAll();
			$incrementId = $service->getOrder()->getRealOrderId();
			Mage::getSingleton('checkout/session')->clear();
			Mage::getSingleton('checkout/cart')->truncate()->save();
			
			Mage::log('Order created with increment id: '.$incrementId, null, $logFileName);						
			$result['success'] = true;
			$result['error']   = false;
			
			$order = Mage::getModel('sales/order')->loadByIncrementId($incrementId);
			
			$oldShippingAmount = $order->getShippingAmount();
			$oldShippingTaxAmount = $order->getShippingTaxAmount();
			if ($shippingTax != 0){
				$order->setShippingAmount($shippingPrice/($shippingTax/100+1));
				$order->setBaseShippingAmount($shippingPrice/($shippingTax/100+1));
				$order->setShippingTaxAmount($shippingPrice-($shippingPrice/($shippingTax/100+1)));
				$order->setBaseShippingTaxAmount($shippingPrice-($shippingPrice/($shippingTax/100+1)));
			}
			else {
				$order->setShippingAmount($shippingPrice);
				$order->setBaseShippingAmount($shippingPrice);
				$order->setShippingTaxAmount(0);
				$order->setBaseShippingTaxAmount(0);
			}
			$order->setShippingInclTax($shippingPrice);
			$order->setBaseShippingInclTax($shippingPrice);
			$orderGrandTotalAdjustment = $order->getShippingAmount() + $order->getShippingTaxAmount() - $oldShippingAmount - $oldShippingTaxAmount;
			$order->setGrandTotal($order->getGrandTotal()+$orderGrandTotalAdjustment);
			$order->setBaseGrandTotal($order->getBaseGrandTotal()+$orderGrandTotalAdjustment);
			
			if ($orderDetails["purchase"]["result"] == "OnHold"){
				$pending = Mage::getStoreConfig('ecomatic_collectorbank/general/pending_order_status');
				$order->setState($pending, true);
				$order->save();
			}
			else if ($orderDetails["purchase"]["result"] == "Preliminary"){
				$auth = Mage::getStoreConfig('ecomatic_collectorbank/general/authorized_order_status');
				$order->setState($auth, true);
				$order->save();
			}
			else {
				$denied = Mage::getStoreConfig('ecomatic_collectorbank/general/denied_order_status');
				$order->setState($denied, true);
				$order->save();
			}
			$this->loadLayout();
			$block = Mage::app()->getLayout()->getBlock('collectorbank_success');
			if ($block){//check if block actually exists					
					if ($order->getId()) {
						$orderId = $order->getId();
						$isVisible = !in_array($order->getState(),Mage::getSingleton('sales/order_config')->getInvisibleOnFrontStates());
						$block->setOrderId($incrementId);
						$block->setIsOrderVisible($isVisible);
						$block->setViewOrderId($block->getUrl('sales/order/view/', array('order_id' => $orderId)));
						$block->setViewOrderUrl($block->getUrl('sales/order/view/', array('order_id' => $orderId)));
						$block->setPrintUrl($block->getUrl('sales/order/print', array('order_id'=> $orderId)));
						$block->setCanPrintOrder($isVisible);
						$block->setCanViewOrder(Mage::getSingleton('customer/session')->isLoggedIn() && $isVisible);
					}
			}
			Mage::dispatchEvent('checkout_onepage_controller_success_action', array('order_ids' => array($order->getId())));
			$this->renderLayout();
			
	Mage::log('5', null, $logFileName);	
		} 
		catch (Mage_Core_Exception $e) {
			$result['success'] = false;
			$result['error'] = true;
			$result['error_messages'] = $e->getMessage();    
			Mage::log('Order creation is failed for invoice no '.$orderDetails['purchase']['purchaseIdentifier'] ."Error is --> ".Mage::helper('core')->jsonEncode($result), null, $logFileName);
			$this->loadLayout();
			$block = Mage::app()->getLayout()->getBlock('collectorbank_success');
			if ($block){
				if($orderDetails['purchase']['purchaseIdentifier']){
					$block->setInvoiceNo($orderDetails['purchase']['purchaseIdentifier']);
				} else {
					$block->setCode(222);
				}
			}
			$this->renderLayout();					
		} 			
		
		Mage::log('----------------- END ------------------------------- ', null, $logFileName);
	}

	public function getResp($privId, $btype){
		$init = Mage::getModel('collectorbank/config')->getInitializeUrl();
		if($privId){
			if(isset($btype)){
				if($btype == 'b2b'){
					$pusername = trim(Mage::getModel('collectorbank/config')->getBusinessUsername());
					$psharedSecret = trim(Mage::getModel('collectorbank/config')->getBusinessSecretkey());
					$pstoreId = Mage::getModel('collectorbank/config')->getBusinessStoreId();
					$array['storeId'] = $pstoreId;
				} else {
					$pusername = trim(Mage::getModel('collectorbank/config')->getPrivateUsername());
					$psharedSecret = trim(Mage::getModel('collectorbank/config')->getPrivateSecretkey());
					$pstoreId = Mage::getModel('collectorbank/config')->getPrivateStoreId();
					$array['storeId'] = $pstoreId;
				}
				
			} else {
				$pusername = trim(Mage::getModel('collectorbank/config')->getPrivateUsername());
				$psharedSecret = trim(Mage::getModel('collectorbank/config')->getPrivateSecretkey());
				$pstoreId = Mage::getModel('collectorbank/config')->getPrivateStoreId();
				$array['storeId'] = $pstoreId;
			}
					
			$path = '/merchants/'.$pstoreId.'/checkouts/'.$privId;
			$hash = $pusername.":".hash("sha256",$path.$psharedSecret);
			$hashstr = 'SharedKey '.base64_encode($hash);
			
			Mage::log('REQUEST >>> Private id is '.$privId .' with shared key --> '.$hashstr, null,'magentoorder.log');			

			$ch = curl_init($init.$path);
			curl_setopt($ch, CURLOPT_HTTPGET, 1);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization:'.$hashstr));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

			$output = curl_exec($ch);
			Mage::log('RESPONSE >>> '.$output, null,'magentoorder.log');
			$data = json_decode($output,true);
			
			if($data["data"]){
				$result['code'] = 1;
				$result['id'] = $data["id"];
				$result['data'] = $data["data"];
				
			} else {
				$result['code'] = 0;
				$result['error'] = $data["error"];
				
			}			
			return $result;
		}
	}
}