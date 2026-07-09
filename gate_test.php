<?php
/**
 * gate_test.php
 * ---------------------------------------------------------------------------
 * Panimulang TEST para sa ProDynamics "Online Order" API.
 * Nagpapadala ng ISANG sample na order gamit ang TEST AuthKey mula sa
 * dokumento, para makita kung umaabot at tumatanggap ang API server nila.
 *
 * WALANG epekto sa totoong booking - test/sample lang ito.
 *
 * Patakbuhin:
 *   - Browser: buksan ang /gate_test.php (admin login required), o
 *   - CLI:     php gate_test.php
 *
 * I-CONFIRM muna sa vendor na OK lang magpadala ng isang test order.
 */

$IS_CLI = (php_sapi_name() === 'cli');

if (!$IS_CLI) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(403);
        exit('403 - admin login required.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// === CONFIG (galing sa Online Order Service Documentation) ===
$URL      = 'http://prodynamicsdxb.dyndns-web.com:5999/Service.svc/OnlineOrder';
$AUTH_KEY = 'LzLsW849cD+uiFttNiQrJuqeRxmk92CpRNeUQ0DfTFj6WodvDCVG8pTTo7dUBYGK'; // TEST key

// MODE: single (default) | sample | verbatim
//   single   -> isang item, configurable barcode/price (default 3HRWKDY)
//   sample   -> EKSAKTONG 2-item example mula sa dokumento, fresh numeric OrderKey
//   verbatim -> example with OrderKey "260" (baka mag-"Order Already Placed" = 2)
//
//   Browser: /gate_test.php?mode=sample   o   ?barcode=XXXX&price=95&authkey=...
//   CLI:     php gate_test.php sample      o   php gate_test.php XXXX 95
if ($IS_CLI) {
    $arg1 = $argv[1] ?? '';
    if (in_array($arg1, ['sample', 'verbatim'], true)) { $MODE = $arg1; $BARCODE = '3HRWKDY'; $PRICE = 95; $ORDERKEY = ''; }
    else { $MODE = 'single'; $BARCODE = $arg1 !== '' ? $arg1 : '3HRWKDY'; $PRICE = isset($argv[2]) ? (float)$argv[2] : 95; $ORDERKEY = $argv[3] ?? ''; }
} else {
    $MODE     = $_GET['mode'] ?? 'single';
    $BARCODE  = $_GET['barcode'] ?? '3HRWKDY';
    $PRICE    = isset($_GET['price']) ? (float)$_GET['price'] : 95;
    $ORDERKEY = $_GET['orderkey'] ?? '';   // pansubok kung tanggap ang alphanumeric (hal. 27-01-9F2)
    if (!empty($_GET['authkey'])) $AUTH_KEY = $_GET['authkey'];
}

if ($MODE === 'sample' || $MODE === 'verbatim') {
    // EKSAKTONG sample mula sa Online Order Service Documentation
    $payload = [
        'AuthKey'        => $AUTH_KEY,
        'OrderKey'       => ($MODE === 'verbatim') ? '260' : date('ymdHis'), // numeric
        'Remarks'        => '',
        'LoyaltyPoints'  => 0,
        'BillDiscAmount' => 0,
        'OrderDetails'   => [
            ['SerialNo' => 1, 'ProductBarcode' => '3HRWKND', 'Price' => 150, 'Quantity' => 1, 'DiscAmount' => 0,  'Amount' => 150],
            ['SerialNo' => 2, 'ProductBarcode' => '3HRWKDY', 'Price' => 95,  'Quantity' => 1, 'DiscAmount' => 10, 'Amount' => 85],
        ],
        'CustomerDetails' => [
            'CustomerKey' => '114', 'FirstName' => 'Abdul', 'LastName' => 'Rasheed', 'Gender' => 'Male',
            'MobileNo' => '0525162279', 'LandLine' => '',
            'AddressDetails' => [
                'AddressKey' => '1', 'BuildingName' => 'Sahara Tower', 'FloorNo' => '5', 'ApartmentNo' => '501',
                'LandMark' => '', 'Area' => 'Al Nahda', 'City' => 'Dubai', 'Country' => 'UAE', 'Remarks' => '', 'GPSCoordinate' => '',
            ],
        ],
        'PaymentDetails' => [
            ['PaymentType' => 1, 'Amount' => 150, 'CreditCardType' => 0, 'CreditCardRemarks' => '2222'],
            ['PaymentType' => 2, 'Amount' => 85,  'CreditCardType' => 1, 'CreditCardRemarks' => '2222'],
        ],
    ];
} else {
    // single item (configurable)
    $payload = [
        'AuthKey'        => $AUTH_KEY,
        'OrderKey'       => $ORDERKEY !== '' ? $ORDERKEY : date('YmdHis'), // default numeric; override via ?orderkey=
        'Remarks'        => 'AWP connectivity test - ignore',
        'LoyaltyPoints'  => 0,
        'BillDiscAmount' => 0,
        'OrderDetails'   => [[
            'SerialNo'       => 1,
            'ProductBarcode' => $BARCODE,
            'Price'          => $PRICE,
            'Quantity'       => 1,
            'DiscAmount'     => 0,
            'Amount'         => $PRICE,
        ]],
        'CustomerDetails' => [
            'CustomerKey' => '114',   // numeric (gaya ng gumaganang sample)
            'FirstName'   => 'Test',
            'LastName'    => 'Guest',
            'Gender'      => '',
            'MobileNo'    => '0500000000',
            'LandLine'    => '',
            'AddressDetails' => [
                'AddressKey'  => '1',
                'BuildingName'=> 'Ajman Water Park',
                'FloorNo'     => '',
                'ApartmentNo' => '1',
                'LandMark'    => '',
                'Area'        => 'Ajman',
                'City'        => 'Ajman',
                'Country'     => 'UAE',
                'Remarks'     => '',
                'GPSCoordinate' => '',
            ],
        ],
        'PaymentDetails' => [[
            'PaymentType'       => 1,
            'Amount'            => $PRICE,
            'CreditCardType'    => 0,
            'CreditCardRemarks' => '',
        ]],
    ];
}

echo "MODE: $MODE\n";
echo "POST $URL\n";
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

$ch = curl_init($URL);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 25,
]);
$resp = curl_exec($ch);
$err  = curl_error($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "=== RESPONSE ===\n";
echo "HTTP status: $http\n";
if ($resp === false) {
    echo "cURL error: $err\n";
    echo "\n(Posibleng naka-block ang outbound connection mula sa server papunta sa port 5999 - i-check ang firewall.)\n";
    exit;
}

echo "Raw: $resp\n\n";
$j  = json_decode($resp, true);
$rc = $j['Status']['ResultCode'] ?? null;
$rs = $j['Status']['ResultStatus'] ?? null;
$er = $j['Status']['Error'] ?? null;

echo "Parsed:\n";
echo "  ResultCode   : " . var_export($rc, true) . "\n";
echo "  ResultStatus : " . var_export($rs, true) . "\n";
echo "  Error        : " . var_export($er, true) . "\n";
echo "  BillSerialNo : " . var_export($j['BillSerialNo'] ?? null, true) . "\n\n";

if ($rc === '1') {
    echo "RESULT: SUCCESS - gumagana ang API at tama ang AuthKey. Pwede nang ituloy ang integration.\n";
} elseif ($rc === '2') {
    echo "RESULT: Order already placed (OK din ito - umabot at tama ang AuthKey).\n";
} elseif (in_array($rc, ['101','102'], true)) {
    echo "RESULT: NOT AUTHORIZED - mali/hindi tanggap ang AuthKey. Kunin ang tamang production key sa vendor.\n";
} else {
    echo "RESULT: Tumugon ang server pero may issue - tingnan ang Error sa itaas at ipakita sa vendor.\n";
}