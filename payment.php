<?php
require_once 'config.php';

// Check if user is logged in
if (!is_logged_in()) {
    header("Location: login.php");
    exit();
}

$user = get_logged_in_user();
if (!$user) {
    // If user data can't be fetched, log them out for security
    logout();
}

// Handle logout action
if (isset($_GET['logout'])) {
    logout();
}

$user_id = $_SESSION['user_id'];

// Check if first deposit is completed
$first_deposit_stmt = $pdo->prepare("SELECT COUNT(*) as first_deposit_count 
                                    FROM shopno_deposit_payment 
                                    WHERE user_id = ? AND payment_type = 'first_deposit' AND payment_status = 'completed'");
$first_deposit_stmt->execute([$user_id]);
$first_deposit_result = $first_deposit_stmt->fetch();
$has_first_deposit = $first_deposit_result['first_deposit_count'] > 0;

// Determine payment type based on first deposit status
if ($has_first_deposit) {
    $payment_type = 'monthly_deposit';
    $payment_display_name = 'Monthly Deposit';
} else {
    $payment_type = 'first_deposit';
    $payment_display_name = 'First Deposit';
}

// Override payment type if specified in URL (for testing purposes)
if (isset($_GET['type']) && in_array($_GET['type'], ['first_deposit', 'monthly_deposit'])) {
    $payment_type = $_GET['type'];
    $payment_display_name = $payment_type === 'first_deposit' ? 'First Deposit' : 'Monthly Deposit';
}

// Fetch user's active deposits for total amount and individual breakdown
$stmt = $pdo->prepare("SELECT d.*, p.plan, p.amount as plan_amount 
                       FROM shopno_deposit d 
                       JOIN payment_plan p ON d.payment_plan_id = p.id 
                       WHERE d.user_id = ? AND d.status = 'active' 
                       ORDER BY d.created_at DESC");
$stmt->execute([$user['id']]);
$user_deposits = $stmt->fetchAll();

$payment_amount = 0;
$individual_deposits = [];
foreach ($user_deposits as $deposit) {
    $payment_amount += $deposit['monthly_deposit_amount'];
    $individual_deposits[] = [
        'deposit_id' => $deposit['id'],
        'member_name' => $deposit['member_name'],
        'amount' => $deposit['monthly_deposit_amount']
    ];
}

// Ensure payment amount is not zero or null
if ($payment_amount <= 0) {
    header("Location: profile.php?error=no_active_deposits");
    exit();
}

// Additional check for monthly deposits - ensure first deposit is completed
if ($payment_type === 'monthly_deposit' && !$has_first_deposit) {
    header("Location: profile.php?error=first_deposit_required");
    exit();
}

// Check for missed monthly deposits and calculate delay fees
$missed_months = 0;
$consecutive_missed = 0;
$delay_fee = 0;
$current_month_year = date('Y-m');
$missed_amount = 0;

if ($payment_type === 'monthly_deposit') {
    // Get the last successful monthly payment date
    $last_payment_stmt = $pdo->prepare("SELECT MAX(payment_date) as last_payment_date, MAX(month_year) as last_month_year
                                        FROM shopno_deposit_payment 
                                        WHERE user_id = ? AND payment_type = 'monthly_deposit' AND payment_status = 'completed'");
    $last_payment_stmt->execute([$user_id]);
    $last_payment_result = $last_payment_stmt->fetch();
    $last_payment_date = $last_payment_result['last_payment_date'];
    $last_month_year = $last_payment_result['last_month_year'];

    // If no previous monthly payment found, get the first deposit completion date as reference
    if (!$last_payment_date) {
        $first_deposit_date_stmt = $pdo->prepare("SELECT MIN(payment_date) as first_payment_date 
                                                  FROM shopno_deposit_payment 
                                                  WHERE user_id = ? AND payment_type = 'first_deposit' AND payment_status = 'completed'");
        $first_deposit_date_stmt->execute([$user_id]);
        $first_deposit_result = $first_deposit_date_stmt->fetch();
        $reference_date = $first_deposit_result['first_payment_date'];
        
        // If first deposit date exists, start from the next month
        if ($reference_date) {
            $reference_datetime = new DateTime($reference_date);
            $reference_datetime->modify('first day of next month');
            $start_month_year = $reference_datetime->format('Y-m');
        } else {
            // Fallback to deposit start date if no payment date found
            $start_date_stmt = $pdo->prepare("SELECT MIN(start_date) as start_date 
                                              FROM shopno_deposit 
                                              WHERE user_id = ? AND status = 'active'");
            $start_date_stmt->execute([$user_id]);
            $start_date_result = $start_date_stmt->fetch();
            $start_date = $start_date_result['start_date'];
            
            if ($start_date) {
                $start_datetime = new DateTime($start_date);
                $start_month_year = $start_datetime->format('Y-m');
            } else {
                $start_month_year = date('Y-m'); // Current month as fallback
            }
        }
    } else {
        // Start checking from the month after the last successful payment
        $last_payment_datetime = new DateTime($last_payment_date);
        $last_payment_datetime->modify('first day of next month');
        $start_month_year = $last_payment_datetime->format('Y-m');
    }

    // Generate all months from start month to previous month (excluding current month)
    $expected_months = [];
    $temp_date = new DateTime($start_month_year . '-01');
    $current_date = new DateTime();
    $end_date = new DateTime('first day of this month'); // Start of current month

    while ($temp_date < $end_date) {
        $expected_months[] = $temp_date->format('Y-m');
        $temp_date->modify('+1 month');
    }

    if (!empty($expected_months)) {
        // Get all completed or processing monthly payments for the expected months
        $placeholders = str_repeat('?,', count($expected_months) - 1) . '?';
        $payment_check_stmt = $pdo->prepare("SELECT month_year 
                                             FROM shopno_deposit_payment 
                                             WHERE user_id = ? AND payment_type = 'monthly_deposit' 
                                             AND payment_status IN ('completed', 'processing', 'pending')
                                             AND month_year IN ($placeholders)");
        $payment_check_params = array_merge([$user_id], $expected_months);
        $payment_check_stmt->execute($payment_check_params);
        $paid_months = $payment_check_stmt->fetchAll(PDO::FETCH_COLUMN);

        // Find missed months
        $missed_months_array = array_diff($expected_months, $paid_months);
        $total_missed = count($missed_months_array);
        
        // Calculate missed amount
        $missed_amount = $total_missed * $payment_amount;

        // Calculate consecutive missed months from the most recent expected months
        $consecutive_missed = 0;
        $reversed_expected = array_reverse($expected_months);
        
        foreach ($reversed_expected as $month) {
            if (in_array($month, $paid_months)) {
                break; // Stop when we find a paid month
            }
            $consecutive_missed++;
        }

        // Calculate delay fee based on consecutive missed payments
        if ($consecutive_missed == 2) {
            $delay_fee = 50;
        } elseif ($consecutive_missed == 3) {
            $delay_fee = 100;
        } elseif ($consecutive_missed >= 4) {
            // Deactivate account for 3 or more consecutive missed payments
            $deactivate_stmt = $pdo->prepare("UPDATE shopno_users SET is_active = 0 WHERE id = ?");
            $deactivate_stmt->execute([$user_id]);
            header("Location: contact.php?error=Your account is deactivated because you miss 3 months deposit please contact us");
            exit();
        }

        // Add missed amount and delay fee to current payment
        $payment_amount = $missed_amount + $delay_fee;
    }
}

// Check for existing payment record first to determine if we should use existing amount
$month_year_for_check = $payment_type === 'monthly_deposit' ? date('Y-m') : date('Y-m');

if ($payment_type === 'monthly_deposit') {
    $existing_check_sql = "SELECT * FROM shopno_deposit_payment 
                          WHERE user_id = ? AND payment_type = ? AND month_year = ? 
                          ORDER BY id DESC LIMIT 1";
    $existing_check_params = [$user['id'], $payment_type, $month_year_for_check];
} else {
    $existing_check_sql = "SELECT * FROM shopno_deposit_payment 
                          WHERE user_id = ? AND payment_type = ? 
                          ORDER BY id DESC LIMIT 1";
    $existing_check_params = [$user['id'], $payment_type];
}

$existing_check_stmt = $pdo->prepare($existing_check_sql);
$existing_check_stmt->execute($existing_check_params);
$existing_check_payment = $existing_check_stmt->fetch();

// If existing payment with reusable status exists, use its amount
if ($existing_check_payment && 
    in_array($existing_check_payment['payment_status'], ['pending', 'processing', 'failed', 'cancelled'])) {
    $payment_amount = $existing_check_payment['total_amount'];
}

// Add 1.2% transaction fee to payment amount (not stored in database)
$transaction_fee = $payment_amount * 0.012;
$final_payment_amount = $payment_amount + $transaction_fee;


// For monthly deposits, check if payment for current month already exists and is completed 
if ($payment_type === 'monthly_deposit') { 
    $monthly_check_stmt = $pdo->prepare("SELECT id FROM shopno_deposit_payment WHERE user_id = ? AND month_year = ? AND payment_type = 'monthly_deposit' AND payment_status = 'completed'"); 
    $monthly_check_stmt->execute([$user_id, $current_month_year]); 
    if ($monthly_check_stmt->fetch()) { 
        header("Location: profile.php?error=monthly_payment_exists"); 
        exit(); 
    } 
}


// Uddoktapay API Configuration
$uddoktapay_config = [
    'base_url' => 'https://shopnocari.paymently.io/api',
    'api_key' => 'kUbtZ9pNOAxsCjrMD8gmYVVUluT3yr1CCAFiWQ5G'
];

$config = $uddoktapay_config;

// Handle payment creation
// Handle payment creation
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'create_payment') {
    try {
        $month_year = $payment_type === 'monthly_deposit' ? date('Y-m') : date('Y-m');
        
        // Define query to find existing payment
        $check_sql = $payment_type === 'monthly_deposit'
            ? "SELECT * FROM shopno_deposit_payment 
               WHERE user_id = ? AND payment_type = ? AND month_year = ? 
               ORDER BY id DESC LIMIT 1"
            : "SELECT * FROM shopno_deposit_payment 
               WHERE user_id = ? AND payment_type = ? 
               ORDER BY id DESC LIMIT 1";
        $check_params = $payment_type === 'monthly_deposit'
            ? [$user['id'], $payment_type, $month_year]
            : [$user['id'], $payment_type];
        
        $check_stmt = $pdo->prepare($check_sql);
        if (!$check_stmt->execute($check_params)) {
            throw new Exception("Failed to check for existing payment.");
        }
        $existing_payment = $check_stmt->fetch();
        
        // Initialize variables for payment processing
        $payment_record_id = null;
        $reuse_existing_amount = false;
        
        if ($existing_payment) {
            if ($existing_payment['payment_status'] === 'completed') {
                throw new Exception($payment_type === 'first_deposit' 
                    ? "First deposit has already been completed." 
                    : "Payment for this month is already completed.");
            }
            
            // Check if existing payment has status that allows reuse
            if (in_array($existing_payment['payment_status'], ['pending', 'processing', 'failed', 'cancelled'])) {
                $payment_record_id = $existing_payment['id'];
                $payment_amount = $existing_payment['total_amount'];
                $reuse_existing_amount = true;
                
                // Update to pending
                $update_pending = $pdo->prepare("UPDATE shopno_deposit_payment SET payment_status = 'pending' WHERE id = ?");
                if (!$update_pending->execute([$payment_record_id])) {
                    throw new Exception("Failed to update payment status to pending.");
                }
            }
        }
        
        // If we're not reusing an existing record, create a new one
        if (!$payment_record_id) {
            $insert_stmt = $pdo->prepare("INSERT INTO shopno_deposit_payment 
                                          (user_id, deposit_id, payment_type, payment_method, uddoktapay_invoice_id, total_amount, individual_deposits, month_year, payment_status) 
                                          VALUES (?, ?, ?, 'uddoktapay', '', ?, ?, ?, 'pending')");
            $insert_result = $insert_stmt->execute([
                $user['id'],
                $user_deposits[0]['id'] ?? null,
                $payment_type,
                $payment_amount,
                json_encode($individual_deposits),
                $month_year
            ]);
            if (!$insert_result) {
                throw new Exception("Failed to insert new payment record.");
            }
            $payment_record_id = $pdo->lastInsertId();
        }
        
        // Calculate final payment amount with transaction fee
        $transaction_fee = $payment_amount * 0.012;
        $final_payment_amount = $payment_amount + $transaction_fee;
        
        // Create Uddoktapay payment
        $payment_data = [
            'full_name' => $user['full_name'] ?? 'Unknown User',
            'email' => $user['email'] ?? 'noemail@shopnocari.com',
            'mobile' => $user['account_number'] ?? 'N/A',
            'amount' => number_format($final_payment_amount, 2, '.', ''),
            'currency' => 'BDT',
            'reference' => strtoupper($payment_type) . '_' . $payment_record_id . '_' . time(),
            'callback_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/shopnocari/uddoktapay_callback.php'
        ];
        
        error_log("Uddoktapay Payment Request Data: " . json_encode($payment_data));
        
        $uddokta_response = createUddoktapayPayment($config, $payment_data);
        
        if ($uddokta_response && isset($uddokta_response['status']) && $uddokta_response['status'] === true && isset($uddokta_response['payment_url'])) {
            // Update payment record with Uddoktapay response
            $update_stmt = $pdo->prepare("UPDATE shopno_deposit_payment 
                                         SET uddoktapay_invoice_id = ?, uddoktapay_response = ?, payment_status = 'processing' 
                                         WHERE id = ?");
            $update_result = $update_stmt->execute([
                $uddokta_response['invoice_id'] ?? null,
                json_encode($uddokta_response),
                $payment_record_id
            ]);
            
            if (!$update_result) {
                throw new Exception("Failed to update payment record with Uddoktapay response.");
            }
            
            // Redirect to Uddoktapay payment page
            error_log("Redirecting to Uddoktapay payment URL: " . $uddokta_response['payment_url']);
            header("Location: " . $uddokta_response['payment_url']);
            exit();
        } else {
            $error_message = isset($uddokta_response['message']) 
                ? $uddokta_response['message'] 
                : "Failed to create Uddoktapay payment. Response: " . json_encode($uddokta_response);
            error_log("Uddoktapay Payment Creation Failed: " . $error_message);
            throw new Exception($error_message);
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log("Payment Creation Error: " . $error);
        
        // Update payment record with failure
        if (isset($payment_record_id)) {
            $fail_stmt = $pdo->prepare("UPDATE shopno_deposit_payment 
                                       SET payment_status = 'failed', failure_reason = ? 
                                       WHERE id = ?");
            $fail_stmt->execute([$error, $payment_record_id]);
        }
        
        // Store error for display
        $error = htmlspecialchars($error);
    }
}

// Updated Uddoktapay API Function
function createUddoktapayPayment($config, $payment_data) {
    $curl = curl_init();
    
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . trim($config['api_key']) // Trim to avoid spaces
    ];
    
    curl_setopt_array($curl, [
        CURLOPT_URL => $config['base_url'] . '/checkout-v2',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($payment_data, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true, // Ensure SSL verification is enabled
        CURLOPT_VERBOSE => true, // Enable verbose output for debugging
        CURLOPT_STDERR => $verbose = fopen('php://temp', 'w+'), // Capture verbose output
    ]);
    
    $response = curl_exec($curl);
    $error = curl_error($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    
    // Capture verbose output for debugging
    rewind($verbose);
    $verbose_log = stream_get_contents($verbose);
    fclose($verbose);
    error_log("Uddoktapay cURL Verbose Output: " . $verbose_log);
    
    curl_close($curl);
    
    if ($error) {
        error_log("Uddoktapay Create Payment cURL Error: $error");
        return ['status' => false, 'message' => "cURL Error: $error"];
    }
    
    if ($http_code !== 200) {
        error_log("Uddoktapay Create Payment HTTP Error: HTTP Code $http_code, Response: $response");
        return ['status' => false, 'message' => "HTTP Error: Code $http_code, Response: $response"];
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Uddoktapay Create Payment JSON Parse Error: " . json_last_error_msg() . ", Response: $response");
        return ['status' => false, 'message' => "JSON Parse Error: " . json_last_error_msg()];
    }
    
    return $data;
}
?>  
  
  
  
   <!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $payment_display_name; ?> Payment - ShopnoCari</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

<script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        sage: '#A8BBA3',
                        cream: '#F7F4EA',
                        peach: '#EBD9D1',
                        brown: '#B87C4C',
                        uddokta: '#22c55e'
                    }
                }
            }
        }
    </script>
<style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Noto Sans Bengali', sans-serif; }
         
    </style>
</head>
<body class="bg-cream text-brown">
    <!-- Navigation -->
    <?php include 'partials/nav.php'; ?>

<?php include 'partials/buttom_navigation.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="max-w-2xl mx-auto">
            
            <!-- Error Message -->
            <?php if (isset($error)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <?php echo $error; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Payment Type Status -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Payment Status</h3>
                        <p class="text-sm text-gray-600">Current payment type determination</p>
                    </div>
                    <div class="text-right">
                        <?php if ($has_first_deposit): ?>
                            <div class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-medium">
                                <i class="fas fa-check-circle mr-1"></i>
                                First Deposit Completed
                            </div>
                        <?php else: ?>
                            <div class="bg-yellow-100 text-yellow-800 px-3 py-1 rounded-full text-sm font-medium">
                                <i class="fas fa-clock mr-1"></i>
                                First Deposit Pending
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Payment Header -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <div class="text-center mb-6">
                    <h1 class="text-3xl font-bold text-gray-800 mb-2"><?php echo $payment_display_name; ?></h1>
                    <p class="text-gray-600">Secure payment with UddoktaPay</p>
                    <?php if ($payment_type === 'monthly_deposit'): ?>
                        <div class="mt-2 text-sm text-blue-600">
                            <i class="fas fa-info-circle mr-1"></i>
                            This is a monthly recurring deposit payment
                        </div>
                    <?php else: ?>
                        <div class="mt-2 text-sm text-orange-600">
                            <i class="fas fa-star mr-1"></i>
                            This is your initial deposit payment to activate your account
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Payment Amount Display -->
                <div class="bg-gradient-to-r from-green-50 to-blue-50 border border-green-200 rounded-lg p-6 text-center mb-6">
                    <div class="text-sm text-gray-600 mb-2">Total Amount</div>
                    <div class="text-4xl font-bold text-green-800 mb-2">৳<?php echo number_format($final_payment_amount, 2); ?></div>
                    <div class="text-sm text-gray-500">
                        <?php echo $payment_display_name; ?>
                        <?php if ($payment_type === 'monthly_deposit'): ?>
                            - <?php echo date('F Y'); ?>
                            <?php if ($missed_amount > 0): ?>
                            
                            <?php if (in_array($existing_payment['payment_status'], ['pending', 'processing', 'failed', 'cancelled'])): ?>

                                <div class="text-red-600">Includes ৳<?php echo number_format($missed_amount, 2); ?> for <?php echo $total_missed; ?> missed month(s)</div>
                                
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($delay_fee > 0): ?>
                                <div class="text-red-600">Includes ৳<?php echo number_format($delay_fee, 2); ?> delay fee</div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="text-blue-600">Includes ৳<?php echo number_format($transaction_fee, 2); ?> UddoktaPay transaction fee (1.2%)</div>
                    </div>
                </div>
            </div>

            <!-- Payment Breakdown -->
            <?php if (!empty($individual_deposits)): ?>
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">Payment Breakdown</h2>
                <div class="space-y-3">
                    <?php foreach ($individual_deposits as $deposit): ?>
                        <div class="flex justify-between items-center py-3 border-b border-gray-100 last:border-b-0">
                            <div>
                                <div class="font-medium text-gray-800"><?php echo htmlspecialchars($deposit['member_name']); ?></div>
                                <div class="text-sm text-gray-500">
                                    <?php echo $payment_type === 'first_deposit' ? 'Initial Deposit' : 'Monthly Deposit'; ?>
                                </div>
                            </div>
                            <div class="text-lg font-semibold text-green-600">
                                ৳<?php echo number_format($deposit['amount'], 2); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($missed_amount > 0): ?>
                     <?php if (in_array($existing_payment['payment_status'], ['pending', 'processing', 'failed', 'cancelled'])): ?>

                        <div class="flex justify-between items-center py-3 border-b border-gray-100">
                            <div>
                                                         
                             
                              
                                <div class="font-medium text-gray-800">Missed Payments (<?php echo $total_missed; ?> month(s))</div>
                                <div class="text-sm text-gray-500">Previous Monthly Deposits</div>
                            </div>
                            <div class="text-lg font-semibold text-red-600">
                                ৳<?php echo number_format($missed_amount, 2); ?>
                            </div>
                            
                            
                            
                        </div>
                         <?php endif; ?>

                    <?php endif; ?>
                    <?php if ($delay_fee > 0): ?>
                        <div class="flex justify-between items-center py-3 border-b border-gray-100">
                            <div>
                                <div class="font-medium text-gray-800">Delay Fee</div>
                                <div class="text-sm text-gray-500">For <?php echo $consecutive_missed; ?> missed month(s)</div>
                            </div>
                            <div class="text-lg font-semibold text-red-600">
                                ৳<?php echo number_format($delay_fee, 2); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="flex justify-between items-center py-3 border-b border-gray-100">
                        <div>
                            <div class="font-medium text-gray-800">UddoktaPay Transaction Fee (1.2%)</div>
                            <div class="text-sm text-gray-500">Not stored in system</div>
                        </div>
                        <div class="text-lg font-semibold text-blue-600">
                            ৳<?php echo number_format($transaction_fee, 2); ?>
                        </div>
                    </div>
                </div>
                
                <div class="border-t border-gray-200 pt-4 mt-4">
                    <div class="flex justify-between items-center">
                        <div class="text-lg font-bold text-gray-800">Total Amount</div>
                        <div class="text-xl font-bold text-green-600">৳<?php echo number_format($final_payment_amount, 2); ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Payment Method Selection -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h2 class="text-xl font-bold text-gray-800 mb-6">Payment Method</h2>
                
                <!-- UddoktaPay Option -->
                <div class="border-2 border-uddokta rounded-lg p-4 bg-gradient-to-r from-green-50 to-lime-50">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-uddokta rounded-lg flex items-center justify-center mr-4">
                                <span class="text-white font-bold text-lg">UP</span>
                            </div>
                            <div>
                                <h3 class="text-lg font-bold text-gray-800">UddoktaPay</h3>
                                <p class="text-sm text-gray-600">Pay securely with UddoktaPay mobile wallet</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm text-gray-500">Transaction Fee</div>
                            <div class="text-blue-600 font-semibold">1.2% (৳<?php echo number_format($transaction_fee, 2); ?>)</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Confirmation -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">Confirm Payment</h2>
                
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-info-circle text-yellow-600 mr-2"></i>
                        <div class="text-sm text-yellow-800">
                            <p class="font-medium mb-1">Important Notes:</p>
                            <ul class="list-disc list-inside space-y-1 text-xs">
                                <li>You will be redirected to UddoktaPay payment gateway</li>
                                <li>Keep your UddoktaPay account ready for payment</li>
                                <li>Payment confirmation will be sent via SMS</li>
                                <li>Do not close the browser during payment process</li>
                                <?php if ($payment_type === 'first_deposit'): ?>
                                    <li><strong>This is your first deposit - once completed, you can make monthly payments</strong></li>
                                <?php else: ?>
                                    <li><strong>Ensure timely monthly payments to avoid delay fees</strong></li>
                                    <?php if ($consecutive_missed > 0): ?>
                                        <li><strong>Includes missed payments for <?php echo $consecutive_missed; ?> month(s)</strong></li>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Payment Form -->
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="create_payment">
                    
                    <!-- User Information Summary -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="font-semibold text-gray-700 mb-3">Payment Details</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                            <div>
                                <span class="text-gray-600">Account Holder:</span>
                                <span class="font-medium text-gray-800 ml-2"><?php echo htmlspecialchars($user['full_name']); ?></span>
                            </div>
                            <div>
                                <span class="text-gray-600">Account Number:</span>
                                <span class="font-medium text-gray-800 ml-2"><?php echo htmlspecialchars($user['account_number']); ?></span>
                            </div>
                            <div>
                                <span class="text-gray-600">Payment Type:</span>
                                <span class="font-medium text-gray-800 ml-2"><?php echo $payment_display_name; ?></span>
                            </div>
                            <div>
                                <span class="text-gray-600">Payment Date:</span>
                                <span class="font-medium text-gray-800 ml-2"><?php echo date('F d, Y'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex flex-col sm:flex-row justify-between items-center space-y-4 sm:space-y-0 sm:space-x-4">
                        <a href="profile.php" class="w-full sm:w-auto bg-gray-500 text-white px-6 py-3 rounded-lg hover:bg-gray-600 transition duration-200 text-center">
                            <i class="fas fa-arrow-left mr-2"></i>
                            Back to Profile
                        </a>
                        
                        <button type="submit" class="w-full sm:w-auto bg-uddokta text-white px-8 py-3 rounded-lg hover:bg-green-600 transition duration-200 font-semibold text-lg shadow-lg hover:shadow-xl transform hover:scale-105">
                            <i class="fas fa-credit-card mr-2"></i>
                            Pay with UddoktaPay
                        </button>
                    </div>
                </form>
            </div>

            <!-- Security Information -->
            <div class="mt-6 text-center mb-16">
                <div class="flex items-center justify-center space-x-4 text-sm text-gray-600">
                    <div class="flex items-center">
                        <i class="fas fa-shield-alt text-green-600 mr-1"></i>
                        <span>Secure Payment</span>
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-lock text-green-600 mr-1"></i>
                        <span>SSL Encrypted</span>
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-mobile-alt text-green-600 mr-1"></i>
                        <span>Mobile Friendly</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="fixed inset-0 bg-gray-900 bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 max-w-sm mx-4 text-center">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-uddokta mx-auto mb-4"></div>
            <h3 class="text-lg font-semibold text-gray-800 mb-2">Processing Payment</h3>
            <p class="text-gray-600 text-sm">Please wait while we redirect you to UddoktaPay...</p>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        // Show loading overlay on form submit
        document.querySelector('form').addEventListener('submit', function() {
            document.getElementById('loadingOverlay').classList.remove('hidden');
            document.getElementById('loadingOverlay').classList.add('flex');
        });

        // Hide loading overlay if there's an error (page reloads)
        window.addEventListener('load', function() {
            document.getElementById('loadingOverlay').classList.add('hidden');
        });

        // Prevent multiple submissions
        let isSubmitting = false;
        document.querySelector('form').addEventListener('submit', function(e) {
            if (isSubmitting) {
                e.preventDefault();
                return false;
            }
            isSubmitting = true;
        });
    </script>

    <!-- Footer JS -->
    <?php include 'partials/js.php'; ?>
</body>
</html>
