<?php
// TATAWAGIN NATIN YUNG PHPMAILER DIRECTLY PARA MAKA-PAG DEBUG TAYO SA SCREEN
require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

echo "<h3>Testing SMTP Connection...</h3>";

$mail = new PHPMailer(true);

try {
    // 🔴 ITO YUNG MAGPAPAKITA NG ERROR SA SCREEN
    $mail->SMTPDebug = 2; // Sets debug mode (2 = Client and Server messages)
    $mail->Debugoutput = 'html'; // Formats the output nicely in the browser

    // LOCAL BYPASS
    $mail->SMTPOptions = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        )
    );

    // SERVER SETTINGS
    $mail->isSMTP();
    $mail->Host       = '68.178.170.32';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'awp@ajmanwaterpark.com';
    $mail->Password   = '5~O{mqgs$Okxh5nk';
    
    // TRY NATIN ANG SSL / 465 MUNA
    $mail->SMTPSecure = 'ssl';
    $mail->Port       = 465;

    // SENDER AND RECIPIENT
    $mail->setFrom('awp@ajmanwaterpark.com', 'Local Test Server');
    
    // 👇 ILAGAY MO GMAIL MO DITO
$mail->addAddress('rainierpinol11@gmail.com', 'Test User');
    // CONTENT
    $mail->isHTML(true);
    $mail->Subject = 'Test SMTP from Localhost';
    $mail->Body    = 'Hello! Kung nababasa mo ito, gumagana na ang cPanel SMTP sa local mo.';

    $mail->send();
    echo "<br><h3 style='color:green;'>✅ Email Sent Successfully!</h3>";

} catch (Exception $e) {
    echo "<br><h3 style='color:red;'>❌ Email Failed. Mailer Error: {$mail->ErrorInfo}</h3>";
}
?>