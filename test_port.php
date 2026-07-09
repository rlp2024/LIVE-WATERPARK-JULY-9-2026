<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('Asia/Dubai');

echo "<h2>SMTP Test Email</h2>";
echo "<pre>";

$mail = new PHPMailer(true);

try {
    $mail->SMTPDebug = 3;
    $mail->Debugoutput = function ($str, $level) {
        echo "DEBUG[$level]: $str\n";
    };

    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'gladiatorsacademy2025@gmail.com';
    $mail->Password   = 'edkuwiymsdbhiozy';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->Timeout    = 30;

    echo "Testing Host: {$mail->Host}\n";
    echo "Testing Port: {$mail->Port}\n";
    echo "Testing Username: {$mail->Username}\n";
    echo "Testing Encryption: STARTTLS\n\n";

    $mail->setFrom('gladiatorsacademy2025@gmail.com', 'Ajman Water Park Test');

    // PALITAN MO ITO NG EMAIL NA GUSTO MONG PAGTEST-AN
    $mail->addAddress('rainierpinol11@gmail.com', 'Test Receiver');

    $mail->isHTML(true);
    $mail->Subject = 'SMTP Test - ' . date('Y-m-d H:i:s');
    $mail->Body    = '
        <h3>SMTP Test Successful</h3>
        <p>If you received this email, SMTP is working.</p>
        <p>Server time: ' . date('Y-m-d H:i:s') . '</p>
    ';
    $mail->AltBody = 'SMTP Test Successful';

    $mail->send();

    echo "\n=====================================\n";
    echo "EMAIL SENT SUCCESSFULLY\n";
    echo "=====================================\n";

} catch (Exception $e) {
    echo "\n=====================================\n";
    echo "EMAIL FAILED\n";
    echo "Mailer ErrorInfo: " . $mail->ErrorInfo . "\n";
    echo "Exception Message: " . $e->getMessage() . "\n";
    echo "=====================================\n";
}

echo "</pre>";
?>