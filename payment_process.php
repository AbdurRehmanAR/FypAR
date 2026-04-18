<?php
/**
 * Pakistan AR Guide — Payment Processing Backend
 * File: payment_process.php
 *
 * Handles: Card payments, Easypaisa, JazzCash, Bank Transfer
 * Integrates with: HBL PayConnect / Stripe for cards
 *                  Easypaisa REST API
 *                  JazzCash REST API
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ─────────────────────────────────────────
// CONFIG  (move to .env in production!)
// ─────────────────────────────────────────
define('STRIPE_SECRET_KEY',       'sk_test_YOUR_STRIPE_KEY');
define('EASYPAISA_MERCHANT_ID',   'YOUR_EASYPAISA_MERCHANT_ID');
define('EASYPAISA_HASH_KEY',      'YOUR_EASYPAISA_HASH_KEY');
define('JAZZCASH_MERCHANT_ID',    'YOUR_JAZZCASH_MERCHANT_ID');
define('JAZZCASH_PASSWORD',       'YOUR_JAZZCASH_PASSWORD');
define('JAZZCASH_INTEGRITY_SALT', 'YOUR_JAZZCASH_INTEGRITY_SALT');
define('JAZZCASH_API_URL',        'https://sandbox.jazzcash.com.pk/ApplicationAPI/API/Payment/DoTransaction');
define('EASYPAISA_API_URL',       'https://easypay.easypaisa.com.pk/tpg/');
define('DB_HOST', 'localhost');
define('DB_NAME', 'pakistan_ar_guide');
define('DB_USER', 'root');
define('DB_PASS', '');

// ─────────────────────────────────────────
// DATABASE CONNECTION
// ─────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

// ─────────────────────────────────────────
// INPUT PARSING & VALIDATION
// ─────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    respond(false, 'Invalid JSON input');
}

$method    = sanitize($input['payment_method'] ?? '');
$bookingId = sanitize($input['booking_id'] ?? '');
$amount    = (int) ($input['amount'] ?? 0);         // in PKR
$currency  = sanitize($input['currency'] ?? 'PKR');
$userId    = sanitize($input['user_id'] ?? '');

if (!in_array($method, ['card', 'easypaisa', 'jazzcash', 'bank_transfer'])) {
    respond(false, 'Invalid payment method');
}
if ($amount <= 0) respond(false, 'Invalid amount');
if (empty($bookingId)) respond(false, 'Booking ID required');

// ─────────────────────────────────────────
// ROUTE TO PAYMENT HANDLER
// ─────────────────────────────────────────
switch ($method) {
    case 'card':          processCard($input, $bookingId, $amount, $currency, $userId); break;
    case 'easypaisa':     processEasypaisa($input, $bookingId, $amount, $userId);       break;
    case 'jazzcash':      processJazzCash($input, $bookingId, $amount, $userId);        break;
    case 'bank_transfer': processBankTransfer($input, $bookingId, $amount, $userId);   break;
}

// ─────────────────────────────────────────
// CARD PAYMENT (via Stripe)
// ─────────────────────────────────────────
function processCard(array $data, string $bookingId, int $amount, string $currency, string $userId): void {
    $paymentMethodId = sanitize($data['payment_method_id'] ?? '');
    $cardHolder      = sanitize($data['card_holder'] ?? '');

    if (empty($paymentMethodId)) {
        respond(false, 'Payment method ID required (Stripe tokenization)');
    }

    // Convert PKR to smallest unit (paisas) — Stripe uses paise for PKR
    $amountInPaise = $amount * 100;

    $payload = [
        'amount'               => $amountInPaise,
        'currency'             => strtolower($currency),
        'payment_method'       => $paymentMethodId,
        'confirm'              => true,
        'description'          => "Pakistan AR Guide — Booking #{$bookingId}",
        'metadata'             => [
            'booking_id' => $bookingId,
            'user_id'    => $userId,
            'card_holder'=> $cardHolder,
        ],
        'return_url' => 'https://yourdomain.com/payment/callback',
    ];

    $response = stripeRequest('POST', '/v1/payment_intents', $payload);

    if (isset($response['error'])) {
        logPayment($bookingId, $userId, 'card', $amount, 'failed', $response['error']['message'] ?? 'Unknown error');
        respond(false, $response['error']['message'] ?? 'Card payment failed');
    }

    if ($response['status'] === 'succeeded') {
        $ref = $response['id'];
        updateBookingStatus($bookingId, 'paid', $ref);
        logPayment($bookingId, $userId, 'card', $amount, 'success', $ref);
        respond(true, 'Payment successful', ['reference' => $ref, 'status' => 'paid']);
    }

    if ($response['status'] === 'requires_action') {
        respond(true, '3D Secure required', [
            'requires_action'      => true,
            'payment_intent_id'    => $response['id'],
            'client_secret'        => $response['client_secret'],
        ]);
    }

    respond(false, 'Unexpected payment status: ' . $response['status']);
}

// ─────────────────────────────────────────
// EASYPAISA (OTC / MA)
// ─────────────────────────────────────────
function processEasypaisa(array $data, string $bookingId, int $amount, string $userId): void {
    $phone = preg_replace('/\D/', '', $data['phone'] ?? '');

    if (strlen($phone) !== 11 || !str_starts_with($phone, '03')) {
        respond(false, 'Invalid Easypaisa mobile number');
    }

    $orderId   = 'PAK' . strtoupper(substr(md5($bookingId . time()), 0, 10));
    $timestamp = date('YmdHis');
    $postData  = http_build_query([
        'merchantId'        => EASYPAISA_MERCHANT_ID,
        'storeId'           => '00000',
        'orderId'           => $orderId,
        'transactionAmount' => number_format($amount, 2, '.', ''),
        'transactionType'   => 'MA',       // Mobile Account
        'mobileAccountNo'   => $phone,
        'emailAddress'      => $data['email'] ?? '',
        'tokenExpiry'       => date('YmdHis', strtotime('+30 minutes')),
        'paymentMethod'     => 'MA',
        'signature'         => generateEasypaisaSignature($orderId, $amount, $phone, $timestamp),
        'timeStamp'         => $timestamp,
    ]);

    $response = httpPost(EASYPAISA_API_URL . 'initPayment', $postData);

    if (!$response || $response['responseCode'] !== '0000') {
        $msg = $response['responseDesc'] ?? 'Easypaisa request failed';
        logPayment($bookingId, $userId, 'easypaisa', $amount, 'failed', $msg);
        respond(false, $msg);
    }

    logPayment($bookingId, $userId, 'easypaisa', $amount, 'pending', $orderId);
    respond(true, 'OTP sent to your Easypaisa account. Approve to complete payment.', [
        'order_id' => $orderId,
        'status'   => 'pending_otp',
    ]);
}

// ─────────────────────────────────────────
// JAZZCASH
// ─────────────────────────────────────────
function processJazzCash(array $data, string $bookingId, int $amount, string $userId): void {
    $phone = preg_replace('/\D/', '', $data['phone'] ?? '');
    $mpin  = sanitize($data['mpin'] ?? '');

    if (strlen($phone) !== 11 || !str_starts_with($phone, '03')) {
        respond(false, 'Invalid JazzCash mobile number');
    }
    if (strlen($mpin) < 4) {
        respond(false, 'MPIN must be at least 4 digits');
    }

    $txnRef   = 'T' . date('YmdHis') . rand(100, 999);
    $dateTime = date('Y-m-d\TH:i:s');
    $expiry   = date('Y-m-d\TH:i:s', strtotime('+30 minutes'));

    $payload = [
        'pp_Version'            => '1.1',
        'pp_TxnType'            => 'MWALLET',
        'pp_Language'           => 'EN',
        'pp_MerchantID'         => JAZZCASH_MERCHANT_ID,
        'pp_SubMerchantID'      => '',
        'pp_Password'           => JAZZCASH_PASSWORD,
        'pp_BankID'             => '',
        'pp_ProductID'          => 'RETL',
        'pp_TxnRefNo'           => $txnRef,
        'pp_Amount'             => $amount * 100,  // in paisas
        'pp_TxnCurrency'        => 'PKR',
        'pp_TxnDateTime'        => $dateTime,
        'pp_BillReference'      => 'bookingRef_' . $bookingId,
        'pp_Description'        => 'Pakistan AR Guide Booking',
        'pp_TxnExpiryDateTime'  => $expiry,
        'pp_SecureHash'         => '',              // will be set below
        'ppmpf_1'               => $phone,
        'ppmpf_2'               => '',
        'ppmpf_3'               => '',
        'ppmpf_4'               => '',
        'ppmpf_5'               => '',
    ];

    // Build secure hash (HMAC-SHA256 over sorted values)
    $hashStr = JAZZCASH_INTEGRITY_SALT;
    ksort($payload);
    foreach ($payload as $key => $val) {
        if ($key !== 'pp_SecureHash' && $val !== '') {
            $hashStr .= '&' . $val;
        }
    }
    $payload['pp_SecureHash'] = hash_hmac('sha256', $hashStr, JAZZCASH_INTEGRITY_SALT);

    $response = httpPost(JAZZCASH_API_URL, json_encode($payload), 'application/json');

    if (!$response || $response['pp_ResponseCode'] !== '000') {
        $msg = $response['pp_ResponseMessage'] ?? 'JazzCash payment failed';
        logPayment($bookingId, $userId, 'jazzcash', $amount, 'failed', $msg);
        respond(false, $msg);
    }

    updateBookingStatus($bookingId, 'paid', $txnRef);
    logPayment($bookingId, $userId, 'jazzcash', $amount, 'success', $txnRef);
    respond(true, 'JazzCash payment successful!', [
        'reference' => $txnRef,
        'status'    => 'paid',
    ]);
}

// ─────────────────────────────────────────
// BANK TRANSFER
// ─────────────────────────────────────────
function processBankTransfer(array $data, string $bookingId, int $amount, string $userId): void {
    $txnId = sanitize($data['transaction_id'] ?? '');
    if (empty($txnId)) {
        respond(false, 'Bank transaction reference is required');
    }

    // Mark as pending verification
    logPayment($bookingId, $userId, 'bank_transfer', $amount, 'pending_verification', $txnId);
    updateBookingStatus($bookingId, 'payment_pending', $txnId);

    // Notify admin (you'd send an email here in production)
    notifyAdmin($bookingId, $txnId, $amount);

    respond(true, 'Bank transfer recorded. Your booking will be confirmed within 2–4 hours after verification.', [
        'reference' => $txnId,
        'status'    => 'pending_verification',
    ]);
}

// ─────────────────────────────────────────
// PROMO CODE VALIDATION (AJAX endpoint)
// ─────────────────────────────────────────
if (isset($input['action']) && $input['action'] === 'validate_promo') {
    $code = strtoupper(sanitize($input['promo_code'] ?? ''));
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT * FROM promo_codes WHERE code = ? AND active = 1 AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1");
    $stmt->execute([$code]);
    $promo = $stmt->fetch();

    if ($promo) {
        respond(true, 'Promo code valid', [
            'discount_type'  => $promo['discount_type'],   // 'percentage' or 'fixed'
            'discount_value' => $promo['discount_value'],
        ]);
    } else {
        respond(false, 'Invalid or expired promo code');
    }
}

// ─────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────
function stripeRequest(string $method, string $endpoint, array $data): array {
    $ch = curl_init('https://api.stripe.com' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . STRIPE_SECRET_KEY,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_POST           => true,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true) ?? [];
}

function httpPost(string $url, $data, string $contentType = 'application/x-www-form-urlencoded'): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $data,
        CURLOPT_HTTPHEADER     => ["Content-Type: {$contentType}"],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result = curl_exec($ch);
    $err    = curl_error($ch);
    curl_close($ch);
    if ($err) { error_log("cURL error: $err"); return null; }
    return json_decode($result, true);
}

function generateEasypaisaSignature(string $orderId, int $amount, string $phone, string $timestamp): string {
    $hashStr = EASYPAISA_HASH_KEY . '&' . $orderId . '&' . number_format($amount, 2, '.', '') . '&' . $phone . '&' . $timestamp;
    return hash('sha256', $hashStr);
}

function updateBookingStatus(string $bookingId, string $status, string $txnRef): void {
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("UPDATE bookings SET payment_status = ?, txn_reference = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $txnRef, $bookingId]);
    } catch (Exception $e) {
        error_log("DB update failed: " . $e->getMessage());
    }
}

function logPayment(string $bookingId, string $userId, string $method, int $amount, string $status, string $details): void {
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("INSERT INTO payment_logs (booking_id, user_id, payment_method, amount, status, details, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$bookingId, $userId, $method, $amount, $status, $details]);
    } catch (Exception $e) {
        error_log("Payment log failed: " . $e->getMessage());
    }
}

function notifyAdmin(string $bookingId, string $txnId, int $amount): void {
    // In production: send email via PHPMailer / SendGrid
    $msg = "New bank transfer pending:\nBooking: {$bookingId}\nTxn: {$txnId}\nAmount: PKR {$amount}";
    error_log($msg); // Replace with actual email sending
}

function sanitize(string $val): string {
    return htmlspecialchars(strip_tags(trim($val)), ENT_QUOTES, 'UTF-8');
}

function respond(bool $success, string $message, array $data = []): never {
    echo json_encode(['success' => $success, 'message' => $message, ...$data]);
    exit;
}
