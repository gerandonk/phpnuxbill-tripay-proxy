<?php


/**
 * PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *
 * Payment Gateway tripay.com
 **/

function tripay_validate_config()
{
    global $config;
    if (empty($config['tripay_secret_key'])) {
        sendTelegram("Tripay payment gateway not configured");
        r2(U . 'order/package', 'w', Lang::T("Admin has not yet setup Tripay payment gateway, please tell admin"));
    }
}

function tripay_show_config()
{
    global $ui, $config;
    $ui->assign('_title', 'Tripay - Payment Gateway');
    $ui->assign('channels', json_decode(file_get_contents('system/paymentgateway/channel_tripay.json'), true));
    $ui->display('tripay.tpl');
}

function tripay_save_config()
{
    global $admin, $_L;
    $tripay_merchant = _post('tripay_merchant');
    $tripay_api_key = _post('tripay_api_key');
    $tripay_secret_key = _post('tripay_secret_key');
    $tripay_view_payment = _post('tripay_view_payment');
    $d = ORM::for_table('tbl_appconfig')->where('setting', 'tripay_merchant')->find_one();
    if ($d) {
        $d->value = $tripay_merchant;
        $d->save();
    } else {
        $d = ORM::for_table('tbl_appconfig')->create();
        $d->setting = 'tripay_merchant';
        $d->value = $tripay_merchant;
        $d->save();
    }
    $d = ORM::for_table('tbl_appconfig')->where('setting', 'tripay_api_key')->find_one();
    if ($d) {
        $d->value = $tripay_api_key;
        $d->save();
    } else {
        $d = ORM::for_table('tbl_appconfig')->create();
        $d->setting = 'tripay_api_key';
        $d->value = $tripay_api_key;
        $d->save();
    }

    $d = ORM::for_table('tbl_appconfig')->where('setting', 'tripay_view_payment')->find_one();
    if ($d) {
        $d->value = $tripay_view_payment;
        $d->save();
    } else {
        $d = ORM::for_table('tbl_appconfig')->create();
        $d->setting = 'tripay_view_payment';
        $d->value = $tripay_view_payment;
        $d->save();
    }
    $d = ORM::for_table('tbl_appconfig')->where('setting', 'tripay_secret_key')->find_one();
    if ($d) {
        $d->value = $tripay_secret_key;
        $d->save();
    } else {
        $d = ORM::for_table('tbl_appconfig')->create();
        $d->setting = 'tripay_secret_key';
        $d->value = $tripay_secret_key;
        $d->save();
    }
    $d = ORM::for_table('tbl_appconfig')->where('setting', 'tripay_channel')->find_one();
    if ($d) {
        $d->value = implode(',', $_POST['tripay_channel']);
        $d->save();
    } else {
        $d = ORM::for_table('tbl_appconfig')->create();
        $d->setting = 'tripay_channel';
        $d->value = implode(',', $_POST['tripay_channel']);
        $d->save();
    }

    _log('[' . $admin['username'] . ']: Tripay ' . Lang::T('Settings_Saved_Successfully') . json_encode($_POST['tripay_channel']), 'Admin', $admin['id']);

    r2(U . 'paymentgateway/tripay', 's', Lang::T('Settings_Saved_Successfully'));
}


function tripay_create_transaction($trx, $user)
{
    global $config, $routes, $ui;
    $channels = json_decode(file_get_contents('system/paymentgateway/channel_tripay.json'), true);
    if (!in_array($routes[4], explode(",", $config['tripay_channel']))) {
        $ui->assign('_title', 'Tripay Channel');
        $ui->assign('channels', $channels);
        $ui->assign('tripay_channels', explode(",", $config['tripay_channel']));
        $ui->assign('path', $routes[2] . '/' . $routes[3]);
        $ui->display('tripay_channel.tpl');
        die();
    }
    $json = [
        'method' => $routes[4],
        'amount' => $trx['price'],
        'merchant_ref' => $trx['id'],
        'customer_name' =>  $user['fullname'],
        'customer_email' => (empty($user['email'])) ? $user['username'] . '@' . $_SERVER['HTTP_HOST'] : $user['email'],
        'customer_phone' => $user['phonenumber'],
        'order_items' => [
            [
                'name' => $trx['plan_name'],
                'price' => $trx['price'],
                'quantity' => 1
            ]
        ],
        'return_url' => U . 'order/view/' . $trx['id'] . '/check',
        'signature' => hash_hmac('sha256', $config['tripay_merchant'] . $trx['id'] . $trx['price'], $config['tripay_secret_key'])
    ];
    $result = json_decode(Http::postJsonData(tripay_get_server() . 'transaction/create', $json, ['Authorization: Bearer ' . $config['tripay_api_key']]), true);
    if ($result['success'] != 1) {
        sendTelegram("Tripay payment failed\n\n" . json_encode($result, JSON_PRETTY_PRINT));
        r2(U . 'order/package', 'e', Lang::T("Failed to create transaction."));
    }
    $d = ORM::for_table('tbl_payment_gateway')
        ->where('username', $user['username'])
        ->where('status', 1)
        ->find_one();
    $d->gateway_trx_id = $result['data']['reference'];
    if ($config['tripay_view_payment'] == 'local') {
        $d->pg_url_payment = U . 'plugin/tripay_show_payment&id=' . $d['id'];
    } else {
        $d->pg_url_payment = $result['data']['checkout_url'];
    }
    $d->pg_request = json_encode($result);
    $d->expired_date = date('Y-m-d H:i:s', $result['data']['expired_time']);
    $d->save();
    if ($config['tripay_view_payment'] == 'local') {
        r2(U . "plugin/tripay_show_payment&id=" . $d['id'], 's', Lang::T("Create Transaction Success"));
    } else {
        r2(U . "order/view/" . $d['id'], 's', Lang::T("Create Transaction Success"));
    }
}

function tripay_get_status($trx, $user)
{
    global $config;
    $result = json_decode(Http::getData(tripay_get_server() . 'transaction/detail?' . http_build_query(['reference' => $trx['gateway_trx_id']]), [
        'Authorization: Bearer ' . $config['tripay_api_key']
    ]), true);
    if ($result['success'] != 1) {
        sendTelegram("Tripay payment status failed\n\n" . json_encode($result, JSON_PRETTY_PRINT));
        r2(U . "order/view/" . $trx['id'], 'w', Lang::T("Payment check failed."));
    }
    $result =  $result['data'];
    if ($result['status'] == 'UNPAID') {
        r2(U . "order/view/" . $trx['id'], 'w', Lang::T("Transaction still unpaid."));
    } else if (in_array($result['status'], ['PAID', 'SETTLED']) && $trx['status'] != 2) {
        if (!Package::rechargeUser($user['id'], $trx['routers'], $trx['plan_id'], $trx['gateway'],  $result['payment_name'])) {
            r2(U . "order/view/" . $trx['id'], 'd', Lang::T("Failed to activate your Package, try again later."));
        }

        $trx->pg_paid_response = json_encode($result);
        $trx->payment_method = $result['payment_method'];
        $trx->payment_channel = $result['payment_name'];
        $trx->paid_date = date('Y-m-d H:i:s', $result['paid_at']);
        $trx->status = 2;
        $trx->save();

        r2(U . "order/view/" . $trx['id'], 's', Lang::T("Transaction has been paid."));
    } else if (in_array($result['status'], ['EXPIRED', 'FAILED', 'REFUND'])) {
        $trx->pg_paid_response = json_encode($result);
        $trx->status = 3;
        $trx->save();
        r2(U . "order/view/" . $trx['id'], 'd', Lang::T("Transaction expired."));
    } else if ($trx['status'] == 2) {
        r2(U . "order/view/" . $trx['id'], 'd', Lang::T("Transaction has been paid.."));
    }
}

// callback
function tripay_payment_notification()
{
    global $config;

    // Retrieve the notification data
    $json = file_get_contents('php://input');
    $notification = json_decode($json, true);

    // Verify the signature
    $signature = hash_hmac('sha256', $json, $config['tripay_secret_key']);
    if ($signature !== $_SERVER['HTTP_X_CALLBACK_SIGNATURE']) {
        die('Invalid signature');
    }

    // Process the notification
    if ($notification['status'] === 'PAID') {
        $trx = ORM::for_table('tbl_payment_gateway')
            ->where('gateway_trx_id', $notification['reference'])
            ->find_one();

        if ($trx && $trx['status'] != 2) {
            $user = ORM::for_table('tbl_users')->find_one($trx['user_id']);

            if (Package::rechargeUser($user['id'], $trx['routers'], $trx['plan_id'], $trx['gateway'], $notification['payment_method'])) {
                $trx->pg_paid_response = json_encode($notification);
                $trx->payment_method = $notification['payment_method'];
                $trx->payment_channel = $notification['payment_method_code'];
                $trx->paid_date = date('Y-m-d H:i:s', $notification['paid_at']);
                $trx->trx_invoice = "INV-" . (Package::_raid() - 1);
                $trx->status = 2;
                $trx->save();

                // You might want to add logging here
            }
        }
    }

    die('OK');
}

function tripay_get_server()
{
    global $_app_stage;
    if ($_app_stage == 'Live') {
        return 'https://tripay.ibnux.com/api/';
    } else {
        return 'https://tripay.ibnux.com/api-sandbox/';
    }
}
