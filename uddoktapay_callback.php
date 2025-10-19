<?php
require_once 'config.php';

// Start a session if you plan to use flash messages
session_start();

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$requiredFiles = [
    'vendor/autoload.php'
];

require_once $requiredFiles[0];

// Uddoktapay API Configuration
$uddoktapay_config = [
    'base_url' => 'https://shopnocari.paymently.io/api',
    'api_key' => 'kUbtZ9pNOAxsCjrMD8gmYVVUluT3yr1CCAFiWQ5G'
];

$config = $uddoktapay_config;

//================================================================================
// Uddoktapay API Functions
//================================================================================

/**
 * Verify a Uddoktapay payment.
 * @param array $config Uddoktapay configuration array
 * @param string $invoice_id The invoice ID from Uddoktapay
 * @return array|null The API response or null on failure
 */
function verifyUddoktapayPayment($config, $invoice_id) {
    $curl = curl_init();
    
    curl_setopt_array($curl, [
        CURLOPT_URL => $config['base_url'] . '/verify-payment',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode(['invoice_id' => $invoice_id]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $config['api_key']
        ],
    ]);
    
    $response = curl_exec($curl);
    $error = curl_error($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    if ($error) {
        error_log('Uddoktapay Verify Payment cURL Error: ' . $error);
        return null;
    }
    
    if ($http_code !== 200) {
        error_log("Uddoktapay Verify Payment HTTP Error: HTTP Code $http_code, Response: $response");
        return null;
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Uddoktapay Verify Payment JSON Parse Error: " . json_last_error_msg() . ", Response: $response");
        return null;
    }
    
    return $data;
}

/**
 * Send email notification for successful payment
 * @param array $payment_record Payment record from database
 * @param string $trxId Transaction ID
 * @return bool True if email sent successfully, false otherwise
 */
function sendPaymentSuccessEmail($payment_record, $trxId) {
    $mail = new PHPMailer(true);

    try {
        // SMTP configuration
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'urexamportal@gmail.com';
        $mail->Password = 'zucr sood dhsf rkqb';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Recipients
        $mail->setFrom('urexamportal@gmail.com', 'Shopnocari');
        $mail->addAddress('underixsn7@gmail.com');
         
        // Email content
        $mail->isHTML(true);
        $mail->Subject = 'Payment Successful - Shopnocari';
        
        $emailBody = "
        <html>
        <head>
            <title>Payment Success Notification</title>
        </head>
        <body>
            <h2>Payment Successfully Completed</h2>
            
            <h3>Transaction Details:</h3>
            <table border='1' cellpadding='8' cellspacing='0' style='border-collapse: collapse;'>
                <tr>
                    <td><strong>Transaction ID:</strong></td>
                    <td>{$trxId}</td>
                </tr>
                <tr>
                    <td><strong>Invoice ID:</strong></td>
                    <td>{$payment_record['uddoktapay_invoice_id']}</td>
                </tr>
                <tr>
                    <td><strong>User ID:</strong></td>
                    <td>{$payment_record['user_id']}</td>
                </tr>
                <tr>
                    <td><strong>Payment Type:</strong></td>
                    <td>{$payment_record['payment_type']}</td>
                </tr>
                <tr>
                    <td><strong>Amount:</strong></td>
                    <td>৳{$payment_record['total_amount']}</td>
                </tr>
                <tr>
                    <td><strong>Month/Year:</strong></td>
                    <td>" . ($payment_record['month_year'] ?? 'N/A') . "</td>
                </tr>
                <tr>
                    <td><strong>Payment Date:</strong></td>
                    <td>" . date('Y-m-d H:i:s') . "</td>
                </tr>
            </table>
            
            <p><strong>Status:</strong> <span style='color: green;'>COMPLETED</span></p>
            
            <hr>
            <p><em>This is an automated notification from the Shopno Payment System.</em></p>
        </body>
        </html>
        ";
        
        $mail->Body = $emailBody;
        
        // Plain text version
        $mail->AltBody = "
        Payment Successfully Completed
        
        Transaction Details:
        Transaction ID: {$trxId}
        Invoice ID: {$payment_record['uddoktapay_invoice_id']}
        User ID: {$payment_record['user_id']}
        Payment Type: {$payment_record['payment_type']}
        Amount: ৳{$payment_record['total_amount']}
        Month/Year: " . ($payment_record['month_year'] ?? 'N/A') . "
        Payment Date: " . date('Y-m-d H:i:s') . "
        Status: COMPLETED
        
        This is an automated notification from the Shopno Payment System.
        ";

        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Email sending failed: {$mail->ErrorInfo}");
        return false;
    }
}

//================================================================================
// Main Callback Logic
//================================================================================

$invoice_id = $_GET['invoice_id'] ?? null;
$status = $_GET['status'] ?? null;

// Validate callback parameters
if (!$invoice_id || !$status) {
    error_log('Uddoktapay Callback Error: Missing invoice_id or status');
    header("Location: profile.php?payment_status=error&message=" . urlencode("Invalid Uddoktapay callback parameters."));
    exit();
}

try {
    // Find the payment record from your database using the Uddoktapay invoice ID
    $stmt = $pdo->prepare("SELECT * FROM shopno_deposit_payment WHERE uddoktapay_invoice_id = ?");
    $stmt->execute([$invoice_id]);
    $payment_record = $stmt->fetch();

    if (!$payment_record) {
        throw new Exception("Payment record not found for invoice_id: $invoice_id");
    }

    // Avoid reprocessing a completed payment
    if ($payment_record['payment_status'] === 'completed') {
        header("Location: profile.php?payment_status=success&trx_id=" . urlencode($payment_record['transaction_id']));
        exit();
    }

    if ($status === 'success') {
        // Payment was authorized by the user, now verify it to capture the funds
        $verify_response = verifyUddoktapayPayment($config, $invoice_id);

        // Check if the verification was successful
        if ($verify_response && isset($verify_response['status']) && $verify_response['status'] === 'PAID' && isset($verify_response['transaction_id'])) {
            // **PAYMENT SUCCESSFUL**
            $trxId = $verify_response['transaction_id'];

            // Begin transaction for payment status update
            $pdo->beginTransaction();

            // Update the shopno_deposit_payment table FIRST
            $update_stmt = $pdo->prepare(
                "UPDATE shopno_deposit_payment SET 
                    payment_status = 'completed', 
                    transaction_id = ?, 
                    payment_date = NOW(), 
                    uddoktapay_response = ?,
                    failure_reason = NULL
                WHERE uddoktapay_invoice_id = ? AND payment_status != 'completed'"
            );
            $update_result = $update_stmt->execute([
                $trxId,
                json_encode($verify_response),
                $invoice_id
            ]);

            if (!$update_result || $update_stmt->rowCount() === 0) {
                error_log("Failed to update payment record for invoice_id: $invoice_id");
                $pdo->rollBack();
                throw new Exception("Database update failed or payment already completed");
            }

            // Commit the transaction HERE to ensure payment status is saved
            $pdo->commit();
            error_log("Payment status updated successfully for transaction: $trxId");

            // Now handle the shopno_final_amount logic (start new transaction)
            try {
                $pdo->beginTransaction();

                $month_year = $payment_record['payment_type'] === 'monthly_deposit' 
                    ? $payment_record['month_year'] 
                    : null;

                if ($payment_record['payment_type'] === 'first_deposit') {
                    // For first_deposit, always insert new record (only once)
                    $check_first_deposit = $pdo->prepare("
                        SELECT id 
                        FROM shopno_final_amount 
                        WHERE user_id = ? AND payment_type = 'first_deposit'
                    ");
                    $check_first_deposit->execute([$payment_record['user_id']]);
                    
                    if (!$check_first_deposit->fetch()) {
                        $insert_stmt = $pdo->prepare("
                            INSERT INTO shopno_final_amount 
                            (user_id, payment_type, final_amount, month_year, created_at) 
                            VALUES (?, ?, ?, ?, NOW())
                        ");
                        $insert_stmt->execute([
                            $payment_record['user_id'],
                            'first_deposit',
                            $payment_record['total_amount'],
                            $month_year
                        ]);
                        error_log("First deposit record created in shopno_final_amount for user: {$payment_record['user_id']}");
                    }
                } else {
                    // For monthly_deposit, update the existing first_deposit record
                    $update_stmt = $pdo->prepare("
                        UPDATE shopno_final_amount 
                        SET final_amount = final_amount + ?, 
                            month_year = ? 
                        WHERE user_id = ? AND payment_type = 'first_deposit'
                    ");
                    $update_result = $update_stmt->execute([
                        $payment_record['total_amount'],
                        $month_year,
                        $payment_record['user_id']
                    ]);
                    
                    if ($update_result && $update_stmt->rowCount() > 0) {
                        error_log("Monthly deposit added to shopno_final_amount for user: {$payment_record['user_id']}");
                    } else {
                        error_log("Warning: No shopno_final_amount record found to update for user: {$payment_record['user_id']}");
                    }
                }

                $pdo->commit();

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Final amount update failed: " . $e->getMessage());
                // Don't throw exception here since main payment is already completed
            }

            // Send email (don't let email failure affect payment status)
            try {
                if (sendPaymentSuccessEmail($payment_record, $trxId)) {
                    error_log("Payment success email sent successfully for transaction: $trxId");
                } else {
                    error_log("Failed to send payment success email for transaction: $trxId");
                }
            } catch (Exception $e) {
                error_log("Email sending exception for transaction: $trxId - " . $e->getMessage());
            }
            
            // Redirect to a success page
            header("Location: profile.php?payment_status=success&trx_id=" . urlencode($trxId));
            exit();

        } else {
            // **PAYMENT VERIFICATION FAILED**
            $failure_reason = $verify_response['message'] ?? 'Uddoktapay verification failed.';
            
            $pdo->beginTransaction();
            $update_stmt = $pdo->prepare(
                "UPDATE shopno_deposit_payment SET 
                    payment_status = 'failed', 
                    failure_reason = ?,
                    uddoktapay_response = ?
                WHERE uddoktapay_invoice_id = ? AND payment_status != 'completed'"
            );
            $update_stmt->execute([
                $failure_reason,
                json_encode($verify_response),
                $invoice_id
            ]);
            $pdo->commit();

            error_log("Payment verification failed for invoice_id: $invoice_id - $failure_reason");
            header("Location: profile.php?payment_status=failed&message=" . urlencode($failure_reason));
            exit();
        }

    } elseif ($status === 'cancel') {
        // **USER CANCELLED**
        $pdo->beginTransaction();
        $update_stmt = $pdo->prepare(
            "UPDATE shopno_deposit_payment SET 
                payment_status = 'cancelled', 
                failure_reason = 'User cancelled the payment on the Uddoktapay page.' 
            WHERE uddoktapay_invoice_id = ? AND payment_status != 'completed'"
        );
        $update_stmt->execute([$invoice_id]);
        $pdo->commit();
        
        error_log("Payment cancelled by user for invoice_id: $invoice_id");
        header("Location: profile.php?payment_status=cancelled");
        exit();

    } else { // status === 'failure'
        // **PAYMENT FAILED ON UDDOKTAPAY GATEWAY**
        $pdo->beginTransaction();
        $update_stmt = $pdo->prepare(
            "UPDATE shopno_deposit_payment SET 
                payment_status = 'failed', 
                failure_reason = 'Payment failed on Uddoktapay gateway before verification.' 
            WHERE uddoktapay_invoice_id = ? AND payment_status != 'completed'"
        );
        $update_stmt->execute([$invoice_id]);
        $pdo->commit();

        error_log("Payment failed on Uddoktapay gateway for invoice_id: $invoice_id");
        header("Location: profile.php?payment_status=failed&message=" . urlencode("Payment failed on Uddoktapay gateway."));
        exit();
    }

} catch (Exception $e) {
    // Log the error for developers
    error_log('Uddoktapay Callback Error: ' . $e->getMessage());

    // If we have a payment record, mark it as failed (in a separate transaction)
    if (isset($payment_record['id']) && isset($invoice_id)) {
        try {
            $pdo->beginTransaction();
            $fail_stmt = $pdo->prepare(
                "UPDATE shopno_deposit_payment SET 
                    payment_status = 'failed', 
                    failure_reason = ? 
                WHERE uddoktapay_invoice_id = ? AND payment_status != 'completed'"
            );
            $fail_stmt->execute([$e->getMessage(), $invoice_id]);
            $pdo->commit();
        } catch (Exception $db_error) {
            $pdo->rollBack();
            error_log('Failed to update payment status to failed: ' . $db_error->getMessage());
        }
    }

    // Redirect to a generic error page
    header("Location: profile.php?payment_status=error&message=" . urlencode("An unexpected error occurred. Please try again."));
    exit();
}
?>
