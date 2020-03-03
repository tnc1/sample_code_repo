<?php
	ini_set('memory_limit', '4096M');

	$pid = mt_rand(1e6, 1e7-1);

	require_once '/var/www/teyzesizsiniz/engine/start.php';
	require 'lib/TokenService.php';

	mb_internal_encoding("UTF-8");

	$logfile_path = '/root/admin/auto_renew_orders.log';

	// Check to see if an instance of the script is already running
	$ini_path = "/root/admin/auto_renew_orders.ini";
	$ini_array = parse_ini_file($ini_path);

	if ($ini_array["execution_status"] == 'Ready') {

		$ini_array["execution_status"] = 'Running';
		write_ini_file($ini_array, $ini_path);
	} else {
		logStr('Auto renew orders process can not start because there is a running instance!');
		exit;
	}

	logStr('Auto renew orders process started...');

	$merchant = "X";
	$secretKey = "XYZ";

	define('MAX_NUMBER_OF_AUTO_RENEWALS', 11);
	define('MAX_NUMBER_OF_PAYMENT_ATTEMPTS', 6);

	$users_expiring = evcimeniz_get_expiring_unlimited_communication_packages();
	$user_count = count($users_expiring);		
	logStr('Expiring users list: ' . serialize($users_expiring));
	$users_expiring = $users_expiring[0];
			
	if ($users_expiring != '' && $users_expiring <> 0) {

		$connect = new \PayU\MerchantClientApi\TokenService('https://secure.payu.com.tr/order/tokens/', $merchant, $secretKey);
	
		logStr('Selected expiring user guid: ' . $users_expiring);

		$user = get_user($users_expiring);

		logStr('Expiring user info: ' . $user->guid .' '. $user->username .' '. $user->email);
		
		// Record auto renewal attempt
		$result = evcimeniz_record_auto_renewal_unlimited_communication_package($user, FALSE);							
		if ($result != '') {
			logStr('evcimeniz_record_auto_renewal_unlimited_communication_package result: '.$result);
		}
	
		$limit = 10;
		$offset = 0;
		
		$options = array('types'=>	"object",
						 'subtypes'			=>	"order_item",
						 'limit'			=>  $limit,
						 'offset'			=> 	$offset,
						 'owner_guids'		=>	$users_expiring,
						 'order_by' => 'time_created desc',
						 );
			
		$order_items = elgg_get_entities_from_metadata($options);
		
		// Select only the latest order item 		
		foreach($order_items as $order_item) {
			if(($order_item) && ($order_item instanceof ElggObject)) {			
				if ((mb_strpos($order_item->title, 'Gold') !== FALSE) &&
					($order_item->status == 4)) {
						
					$order_item = $order_item;
					break;
				}
			}
		}	
				
		if(($order_item) && ($order_item instanceof ElggObject)) {
		
			logStr('Title: ' . $order_item->title);
			logStr('User setting: ' . $user->auto_renew_membership);
			logStr('Expiring order item guid: ' . $order_item->guid);
			logStr('Expiring order status: ' . $order_item->status);

			$userHist = $user->UCP_last_auto_renewal_approval_history;

			if(isset($userHist)) {				
				if(isSerialized($userHist)) {
					$arrHist = unserialize($userHist);
				}

				$auto_renewal_count = count($arrHist);
				logStr('UCP_last_auto_renewal_approval_history result: ' . $auto_renewal_count . '-' . $userHist);
			}
			else {
				$auto_renewal_count = 0;
			}
			logStr('User auto renewal count: ' . $auto_renewal_count);			

			// Send expiring order reminder email
			if (($user->auto_renew_membership == 'no') || (mb_strpos($order_item->title, 'hafta') !== FALSE)) {
				if (evcimeniz_send_expiring_order_email($order_item)) {											
					logStr('Expiring order email sent to '.$user->email);			
				}			
			}

			if($order_item->status == 4 && 
				$user->auto_renew_membership != 'no' &&
				$auto_renewal_count < MAX_NUMBER_OF_AUTO_RENEWALS &&
				$user->deactivated != 'yes' &&
				!$user->isBanned()) {
				
				$options = array('relationship'		=> 	'order_item',
								 'relationship_guid'=>	$order_item->guid,
								 'inverse_relationship' => true,
								 'types'			=>	"object",
								 'subtypes'			=>	"order",
								 'limit'			=>	$limit);
				$order = elgg_get_entities_from_relationship($options);
				
				$order = $order[0];
												
				$duration_in_days = 0;				
				if(($order_item->title == '1 aylık Gold üyelik')) {					
					$duration_in_days = 30;
				}
				
				// Only renew 1 month membership option not others				
				if(($order->checkout_method == 'card') && 
					(mb_strpos($order_item->title, 'Gold') !== FALSE) &&
					($duration_in_days == 30)) {			
					
					if ($order->ipn_cc_token != '' &&
						$order->ipn_cc_mask != '' &&
						$order->ipn_cc_exp_date != '') {

						$additionalData = array(
							'BILL_FNAME' => $order->b_first_name,
							'BILL_LNAME' => $order->b_last_name,
							'BILL_EMAIL' => $user->email,
							'BILL_PHONE' => $order->b_mobileno,
							'BILL_ADDRESS' => $order->b_address_line_1.' '.$order->b_address_line_2,
							'BILL_CITY' => $order->b_city.' '.$order->b_pincode,
							"BILL_COUNTRYCODE" => 'TR',
						);
						
						$tran_id = $order->transaction_id;
						$ext_tran_id = $order->ext_transaction_id;
						$currency = 'TRY';
						$total_price = ($order_item->price)+($order_item->tax_price);					
																		
						$token_info = $connect->getInfo($tran_id);
						$order_item->pre_tran_token_info = serialize($token_info);
						
						logStr('Pre_tran_token_info: ' . serialize($token_info));							
												
						if ($token_info['TOKEN_STATUS'] == 'ACTIVE') {
													
							$new_sale_info = $connect->newSale($tran_id,$total_price,$currency,$ext_tran_id,$additionalData);						
							
							$tempArray = array();
							if(isset($order_item->token_tran_result)) {
								$tempArray = unserialize($order_item->token_tran_result);
							}
							array_push($tempArray, $new_sale_info);
							$order_item->token_tran_result = serialize($tempArray);															
							logStr('Token_tran_result: ' . serialize($tempArray));
													
							if (($new_sale_info['code'] == '0') && ($result == '')) {
																																				
								evcimeniz_record_auto_renewal_unlimited_communication_package($user, TRUE);
									
								evcimeniz_add_unlimited_communication_package($user, $duration_in_days);				

								date_default_timezone_set('Europe/Istanbul');
								
								// Output info necessary for invoice related to this payment
								$content  = mb_strtoupper($order->b_first_name).' '.mb_strtoupper($order->b_last_name);
								$content .= ",";
								$content .= mb_strtoupper(str_replace(',','',$order->b_address_line_1));
								$content .= ",";
								$content .= mb_strtoupper(str_replace(',','',$order->b_address_line_2));
								$content .= ",";
								$content .= mb_strtoupper(str_replace(',','',$order->b_city)).' '.$order->b_pincode;
								$content .= ",";
								$content .= ($order->b_country == 'TUR' ? 'TÜRKİYE' : $order->b_country);
								$content .= ",";
								$content .= $order->b_tckn;
								$content .= ",";
								$content .= "Teyzesizsiniz.com - " . $order_item->title." - Sipariş No: ". $order_item->guid;				
								$content .= ",";
								$content .= $order_item->quantity;					
								$content .= ","; 
								$content .= $order_item->price;
								$content .= ",";
								$content .= number_format($order_item->tax_price, 2, '.', '');
								$content .= ",";
								$content .= number_format(($order_item->price)+($order_item->tax_price), 2, '.', '');
								$content .= ",";
								$content .= date("d/m/Y ");						
								$content .= ",";
								$content .= date("H:i ");						
								$content .= ",Hayır ";
								$content .= "\n";					
								
								if ($content != '') {
																								
									$filename1 = '/root/admin/invoice_printing_data_recurring.csv';
									$filename2 = '/root/admin/invoice_printing_data_recurring_bk.csv';
									
									$file1 = file_put_contents($filename1, $content, FILE_APPEND | LOCK_EX);									
									$file2 = file_put_contents($filename2, $content, FILE_APPEND | LOCK_EX);
										
									if ($file1 && $file2) {											
										logStr("Invoice printing file generation successful! (".$file1." bytes)");
									}
									else{										
										logStr("Invoice printing file generation error!");
									}
								}
								
								$token_info = $connect->getInfo($tran_id);								
								$order_item->post_tran_token_info = serialize($token_info);
								logStr('Token info: ' . serialize($token_info));
																																												
							}
													
							$token_info = $connect->getInfo($tran_id);								
							$order_item->post_tran_token_info = serialize($token_info);
							logStr('Post_tran_token_info' . serialize($token_info));
						}					
					}						
				}
			}
		}
	}
	else {

		logStr("No expiring users found at this moment!");
	}

	logStr('Auto renew orders process completed...');

	// Set execution status back to Ready 
	$ini_array["execution_status"] = 'Ready';
	write_ini_file($ini_array, $ini_path);		
