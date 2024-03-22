// sfspay code start

if ($method_name == 'sfspay') {
    $txnid = $_REQUEST['txnid'];

    if (empty($txnid)) {
        die('Direct access is not allowed.');
    }


	$apiKey =  trim($extras['api_key']);
	$host = parse_url(trim($extras['api_url']),  PHP_URL_HOST);
    $apiUrl = "https://{$host}/api/sfspay/verify-payment?apiKey={$apiKey}";

    $invoice_data = [
        'invoice_id' => $txnid
    ];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode($invoice_data),
        CURLOPT_HTTPHEADER => [
            "SFSPAY-API-KEY: " . $apiKey,
            "accept: application/json",
            "content-type: application/json"
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);

    curl_close($curl);

    if ($err) {
        echo "cURL Error #:" . $err;
        exit();
    }

    if (!empty($response)) {
        $data = json_decode($response, true);

        if (!isset($data['status']) || !isset($data['metadata'])) {
            die('Invalid Response.');
        }

       if ($data['status'] == 'completed' && countRow(['table' => 'payments', 'where' => ['client_id' => $data['metadata']['user_id'], 'payment_method' => 71, 'payment_status' => 1, 'payment_delivery' => 1, 'payment_extra' => $data['metadata']['txnid']]])) {
    $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
    $payment->execute(['extra' => $data['metadata']['txnid']]);
    $payment = $payment->fetch(PDO::FETCH_ASSOC);

    $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method AND bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
    $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
    $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);

    $amount = ($payment_bonus) ? $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100) : $payment['payment_amount'];

    $conn->beginTransaction();
    $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
    $update->execute(['balance' => $payment['balance'], 'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);

    $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
    $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);

    $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');
    $insert->execute(['c_id' => $payment['client_id'], 'action' => '', 'ip' => '', 'date' => '']);  // Fill in the action, ip, and date values

    $insert25 = $conn->prepare("INSERT INTO payments SET client_id=:client_id , client_balance=:client_balance , payment_amount=:payment_amount , payment_method=:payment_method ,
        payment_status=:status, payment_delivery=:delivery , payment_note=:payment_note , payment_create_date=:payment_create_date , payment_extra=:payment_extra , bonus=:bonus");

    $paymentNote = 'New ' . $amount . ' ' . $settings["currency"] . ' payment has been made with ' . $method['method_name'];

    // Include additional information in the payment note
    $paymentNote .= ' PaymentID: ' . $data['metadata']['paymentID'];
    $paymentNote .= ' Txnid: ' . $data['metadata']['txnid'];
    $paymentNote .= ' CustomerMsisdn: ' . $data['metadata']['customerMsisdn'];

    if ($payment_bonus) {
        $bonus_amount = ($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100;
        $insert25->execute(array(
            "client_id" => $payment['client_id'], "client_balance" => (($payment['balance'] + $amount) - $bonus_amount),
            "payment_amount" => $bonus_amount, "payment_method" => 1, 'status' => 3, 'delivery' => 2, "payment_note" => $paymentNote, "payment_create_date" => date('Y-m-d H:i:s'), "payment_extra" => "Bonus added for previous payment",
            "bonus" => 1
        ));
        $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus.', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
    } else {
        $insert->execute(['c_id' => $payment['client_id'], 'action' => $paymentNote, 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
    }

    if ($update && $balance) {
        $conn->commit();
        echo 'OK';
    } else {
        $conn->rollBack();
        echo 'NO';
    }
} else {
    $update = $conn->prepare('UPDATE payments SET payment_status=:payment_status WHERE client_id=:client_id AND payment_method=:payment_method AND payment_delivery=:payment_delivery AND payment_extra=:payment_extra');
    $update->execute(['payment_status' => 2, 'client_id' => $data['metadata']['user_id'], 'payment_method' => 71, 'payment_delivery' => 1, 'payment_extra' => $data['metadata']['txnid']]);
    echo 'NO';
}

header('Location:' . site_url('addfunds?success=true'));
exit('Invalid Request');

    }
}

// sfspay code end