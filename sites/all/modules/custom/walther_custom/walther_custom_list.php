<?php

/**
 * STEP 1: read POST data
 * Reading POSTed data directly from $_POST causes serialization issues with array data in the POST.
 * Instead, read raw POST data from the input stream.
 */
$raw_post_data = file_get_contents('php://input');
$raw_post_array = explode('&', $raw_post_data);
$my_post = array();
foreach ($raw_post_array as $keyval) {
  $keyval = explode('=', $keyval);
  if (count($keyval) == 2) {
    $my_post[$keyval[0]] = urldecode($keyval[1]);
  }
}

// Read the IPN message sent from PayPal and prepend cmd=_notify-validate.
$req = 'cmd=_notify-validate';
if (function_exists('get_magic_quotes_gpc')) {
  $get_magic_quotes_exists = TRUE;
}

foreach ($my_post as $key => $value) {
  if ($get_magic_quotes_exists == TRUE && get_magic_quotes_gpc() == 1) {
    $value = urlencode(stripslashes($value));
  }
  else {
    $value = urlencode($value);
  }
  $req .= "&$key=$value";
}

// STEP 2: POST IPN data back to PayPal to validate.
$ch = curl_init('https://www.paypal.com/cgi-bin/webscr');
curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Connection: Close'));

if (!($res = curl_exec($ch))) {
  curl_close($ch);
  exit;
}
curl_close($ch);

// STEP 3: Inspect IPN validation result and act accordingly.

if (strcmp($res, "VERIFIED") == 0 && !empty($_POST['mc_gross']) && $_POST['txn_type'] != 'subscr_failed') {
  $custom = array();
  // Assign posted variables to local variables.
  if (!empty($_POST['custom'])) {
    $user = user_load($_POST['custom']);
  }
  else {
    $sub_id = array();
    $sub_id = $_POST['subscr_id'];
    $query = new EntityFieldQuery();
    $query->entityCondition('entity_type', 'user')
          ->fieldCondition('field_paypal_trans_id_field', 'value', $sub_id, '=');
    $result = $query->execute();
    foreach ($result['user'] as $user_id) {
      $uid_load = $user_id->uid;
    }
    $user = user_load($uid_load);
  }

  //$custom['amount'] = $_POST['mc_gross'];
  /* if(!empty($_POST['echeck_time_processed'])) {
    $date = strtotime($_POST['echeck_time_processed']);
  }
  else {
    $date = strtotime($_POST['payment_date']);
  }
  $custom['date'] = date('Y-m-d', $date);
  */
  $custom['subscr_id'] = $_POST['subscr_id'];
  $custom['payer_email'] = $_POST['payer_email'];
  $custom['first_name'] = $_POST['first_name'];
  $custom['last_name'] = $_POST['last_name'];

  $user->roles[5] = 'premium';
  $user->field_last_payment_date['und'][0]['value'] = date('Y-m-d H:m:s');
  $user->field_paypal_email_field['und'][0]['value'] = $custom['payer_email'];
  $user->field_user_first_name['und'][0]['value'] = $custom['first_name'];
  $user->field_user_last_name['und'][0]['value'] = $custom['last_name'];
  if (empty($user->field_paypal_trans_id_field['und'][0]['value'])) {
    $user->field_paypal_trans_id_field['und'][0]['value'] = $custom['subscr_id'];
  }
  if (!empty($_POST['mc_gross'])) {
    $user->field_last_payment_amt['und'][0]['value'] = $_POST['mc_gross'];
    $user->field_last_payment_text['und'][0]['value'] = $_POST['mc_gross'];
  }
  // Let's look at what we are getting in the post.
  $pp_data = fopen("pp_post.txt", "a+");
  foreach ($_POST as $key => $postdata) {
    fwrite($pp_data, $key . ': ' . $postdata . PHP_EOL);
  }
  fwrite($pp_data, PHP_EOL);
  fclose($pp_data);
  user_save($user);
}

elseif (strcmp($res, "INVALID") == 0) {
  // IPN invalid, log for manual investigation.
  $pp_error = fopen("pp_error.txt", "a+");
  fwrite($pp_error, $res);
  fwrite($pp_error, PHP_EOL);
  fclose($pp_error);
}
