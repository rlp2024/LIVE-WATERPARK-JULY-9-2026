<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

// ==============================================================================
// 1. FUNCTION: SEND ENTRY RECEIPT (Thermal Style for Regular Tickets)
// ==============================================================================
function sendEntryReceipt($customerEmail, $customerName, $bookingId, $details, $items, $cashierName) {
    global $pdo; // Ensure database connection is available

    $mail = new PHPMailer(true);

    try {
        // --- SERVER SETTINGS ---
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'nada@ajmanwaterpark.com'; 
        $mail->Password   = 'nwypttvbvfmahonh'; 
        $mail->SMTPSecure = 'tls'; 
        $mail->Port       = 587;

        // --- SENDER & RECIPIENT ---
        $mail->setFrom('tickets@ajmanwaterpark.com', 'Ajman Water Park');
        $mail->addAddress($customerEmail, $customerName);

        // --- EMAIL CONTENT ---
        $mail->isHTML(true);
        $mail->Subject = 'Official Receipt - Order #' . str_pad($bookingId, 6, '0', STR_PAD_LEFT);

        // --- PREPARE DATA ---
        $date = date("d-M-Y h:i A");
        $totalAmount = number_format($details['total_amount'], 2);
        
        // --- [NEW] FETCH INDIVIDUAL TICKETS FOR TURNSTILE ---
        $ticketQRsHtml = "";
        
        if ($pdo) {
            $stmtTix = $pdo->prepare("SELECT ticket_code, ticket_type FROM ticket_instances WHERE booking_id = ?");
            $stmtTix->execute([$bookingId]);
            $tickets = $stmtTix->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($tickets)) {
                $ticketQRsHtml .= "<div style='text-align:center; border-top:2px dashed #000; margin-top:15px; padding-top:15px; page-break-inside: avoid;'>";
                $ticketQRsHtml .= "<h3 style='margin:0 0 10px 0; color:#003B72; font-family: Arial, sans-serif;'>YOUR ENTRANCE TICKETS</h3>";
                $ticketQRsHtml .= "<p style='font-size:11px; margin-bottom:15px; color:#555;'>Scan each code individually at the Turnstile Gate.</p>";
                
                foreach ($tickets as $t) {
                    $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($t['ticket_code']);
                    $ticketQRsHtml .= "
                    <div style='display:inline-block; vertical-align:top; margin:5px; padding:10px; border:1px solid #ccc; border-radius:8px; background:#fff; width:110px;'>
                        <img src='$qrUrl' style='width:90px; height:90px; display:block; margin:0 auto;'>
                        <div style='font-weight:bold; font-size:12px; margin-top:8px; color:#003B72; text-transform:uppercase;'>{$t['ticket_type']}</div>
                        <div style='font-size:9px; color:#666; font-family:monospace; margin-top:2px;'>{$t['ticket_code']}</div>
                    </div>";
                }
                $ticketQRsHtml .= "</div>";
            }
        }

        // --- ITEMS LOOP WITH INDIVIDUAL QR CODES FOR ADD-ONS ---
        $itemsHtml = '';
        foreach($items as $item) {
            $rowTotal = number_format($item['price'] * $item['quantity'], 2);
            $itemName = $item['name']; // This comes from success.php query
            $productId = isset($item['product_id']) ? $item['product_id'] : '';
            
            // Add-on QR Logic
            $uniqueItemCode = $bookingId . '-' . $productId;
            $qrCodeHtml = '';

            // Check if it's an Add-on based on ID or Name keywords
            $isAddon = (strpos($productId, 'ADD') === 0) || 
                       (stripos($itemName, 'Zipline') !== false) ||
                       (stripos($itemName, 'Parking') !== false) ||
                       (stripos($itemName, 'Locker') !== false) ||
                       (stripos($itemName, 'Bridge') !== false) ||
                       (stripos($itemName, 'Photo') !== false) ||
                       (stripos($itemName, 'Meal') !== false);

            if ($isAddon) {
                // [UPDATED] INCREASED QR SIZE HERE (API Request 150x150)
                $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($uniqueItemCode);
                
                // [UPDATED] Display size increased to 100px
                $qrCodeHtml = "<br><div style='margin-top:5px; border:1px solid #eee; display:inline-block; padding:5px; background:#fff;'>
                                <img src='$qrUrl' style='width:100px; height:100px; display:block;'>
                                <div style='font-size:9px; color:#555; font-family:monospace; margin-top:2px;'>Add-on: $uniqueItemCode</div>
                               </div>";
            }

            $itemsHtml .= "
            <tr>
                <td style='padding:10px 0; border-bottom:1px dashed #ccc; vertical-align:top;'>
                    <strong>{$itemName}</strong>
                    $qrCodeHtml
                </td>
                <td style='padding:10px 0; border-bottom:1px dashed #ccc; text-align:center; vertical-align:top;'>{$item['quantity']}</td>
                <td style='padding:10px 0; border-bottom:1px dashed #ccc; text-align:right; vertical-align:top;'>{$rowTotal}</td>
            </tr>";
        }

        // --- RECEIPT HTML DESIGN (Thermal Look) ---
        $body = "
        <div style='background-color:#e0e0e0; padding:20px; font-family: Courier New, monospace;'>
            <div style='max-width:350px; margin:0 auto; background:#fff; padding:20px; border-radius:5px; box-shadow:0 2px 5px rgba(0,0,0,0.1); color:#000;'>
                
                <div style='text-align:center; border-bottom:1px dashed #000; padding-bottom:10px; margin-bottom:10px;'>
                    <h2 style='margin:0; font-size:18px; font-weight:900;'>Ajman Water Park</h2>
                    <p style='font-size:11px; margin:5px 0;'>Waterpark & Resorts, Ajman UAE</p>
                    <p style='font-size:11px; margin:0;'>Tel: +971 52 120 7573</p>
                </div>

                <div style='font-size:11px; margin-bottom:10px; border-bottom:1px dashed #000; padding-bottom:10px;'>
                    <p style='margin:2px 0; display:flex; justify-content:space-between;'><strong>Order ID:</strong> <span>#".str_pad($bookingId, 6, '0', STR_PAD_LEFT)."</span></p>
                    <p style='margin:2px 0; display:flex; justify-content:space-between;'><strong>Date:</strong> <span>$date</span></p>
                    <p style='margin:2px 0; display:flex; justify-content:space-between;'><strong>Guard on Duty:</strong> <span>$cashierName</span></p>
                    <p style='margin:2px 0; display:flex; justify-content:space-between;'><strong>Customer:</strong> <span>$customerName</span></p>
                </div>

                <table style='width:100%; font-size:11px; border-collapse:collapse; margin-bottom:10px;'>
                    <thead>
                        <tr>
                            <th style='text-align:left; border-bottom:1px dashed #000; padding-bottom:5px;'>ITEM</th>
                            <th style='text-align:center; border-bottom:1px dashed #000; padding-bottom:5px;'>QTY</th>
                            <th style='text-align:right; border-bottom:1px dashed #000; padding-bottom:5px;'>AMT</th>
                        </tr>
                    </thead>
                    <tbody>
                        $itemsHtml
                    </tbody>
                </table>

                <div style='border-top:1px dashed #000; padding-top:10px;'>
                    <div style='display:flex; justify-content:space-between; font-weight:bold; font-size:16px; margin-bottom:5px;'>
                        <span>TOTAL</span>
                        <span>AED $totalAmount</span>
                    </div>
                    <div style='display:flex; justify-content:space-between; font-size:11px;'>
                        <span>Payment Method:</span>
                        <span style='text-transform:uppercase; font-weight:bold;'>".strtoupper($details['payment_method'])."</span>
                    </div>
                </div>

                $ticketQRsHtml

                <div style='text-align:center; margin-top:20px; padding-top:10px; border-top:1px solid #eee; font-size:10px; color:#000;'>
                    <p style='margin-bottom:5px;'><strong>Scan tickets above at entrance.</strong></p>
                    <p style='margin:5px 0;'>Thank you for visiting!</p>
                    <p style='margin:0;'>www.ajmanwaterpark.com</p>
                </div>

            </div>
        </div>";

        $mail->Body = $body;
        $mail->AltBody = "Receipt for Order #$bookingId. Total: AED $totalAmount";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Receipt Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

// ==============================================================================
// 2. FUNCTION: SEND BOOKING CONFIRMATION (Digital Pass for Members)
// ==============================================================================
function sendBookingConfirmation($customerEmail, $customerName, $bookingId, $details, $items) {
    $mail = new PHPMailer(true);

    try {
        // --- SERVER SETTINGS ---
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'gladiatorsacademy2025@gmail.com'; 
        $mail->Password   = 'qlkrosezujuihjcv'; 
        $mail->SMTPSecure = 'tls'; 
        $mail->Port       = 587;

        $mail->setFrom('gladiatorsacademy2025@gmail.com', 'Ajman Water Park');
        $mail->addAddress($customerEmail, $customerName);

        $mail->isHTML(true);
        $mail->Subject = 'Membership Confirmed - #' . str_pad($bookingId, 6, '0', STR_PAD_LEFT);

        // --- DETECT PASS IMAGE ---
        $itemsHtml = '';
        $passImageFile = ''; 
        $basePath = __DIR__ . '/'; 

        foreach($items as $item) {
            $itemsHtml .= "<tr>
                <td style='padding:8px; border-bottom:1px solid #ddd;'>{$item['name']}</td>
                <td style='padding:8px; border-bottom:1px solid #ddd;'>{$item['quantity']}</td>
                <td style='padding:8px; border-bottom:1px solid #ddd;'>AED " . number_format($item['price'], 2) . "</td>
            </tr>";

            $pid = isset($item['product_id']) ? $item['product_id'] : '';

            // --- V3 IMAGE FILENAMES ---
            if ($pid == 'AP1') {
                $passImageFile = 'Images/annual passes/annual_passes-SILVER-V3.jpg'; 
            } elseif ($pid == 'AP2') {
                $passImageFile = 'Images/annual passes/annual_passes-GOLD-V3.jpg';
            } elseif ($pid == 'AP3') {
                $passImageFile = 'Images/annual passes/annual_passes-PLATINUM-V3.jpg';
            }
        }

        // --- GENERATE DIGITAL PASS ---
        $passDisplayHtml = "";
        $mainMessage = "";

        if (!empty($passImageFile) && !empty($details['expiry_date'])) {
            $bgPath = $basePath . $passImageFile;
            $expiryDate = date("F d, Y", strtotime($details['expiry_date']));
            
            // Get user's face path from details array
            $facePath = isset($details['face_image_path']) ? $details['face_image_path'] : null;

            if (file_exists($bgPath)) {
                // Pass $facePath to the function
                $generatedImagePath = generatePassImage($bgPath, $bookingId, $customerName, $expiryDate, $facePath);

                if ($generatedImagePath) {
                    $mail->addEmbeddedImage($generatedImagePath, 'digital_pass_img');
                    
                    $passDisplayHtml = "
                    <div style='text-align:center; margin: 20px 0;'>
                        <p style='font-size:16px; color:#003B72; font-weight:bold;'>OFFICIAL DIGITAL MEMBER CARD</p>
                        <img src='cid:digital_pass_img' alt='Annual Pass' style='width:100%; max-width:600px; border-radius:15px; box-shadow:0 4px 15px rgba(0,0,0,0.3);'>
                        <p style='font-size:12px; color:#888; margin-top:5px;'>Please save this image to your phone gallery.</p>
                    </div>";
                    
                    $mainMessage = "Congratulations! Here is your Annual Pass. You have unlimited access until <strong>$expiryDate</strong>.";
                }
            }
        } 
        
        if (empty($passDisplayHtml)) {
            $mainMessage = "Thank you for joining Ajman Water Park!";
        }

        // --- BUILD EMAIL BODY ---
        $total = number_format($details['total_amount'], 2);

        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #eee; padding: 20px;'>
            <h2 style='color: #003B72; text-align: center;'>Membership Confirmed!</h2>
            <p>Hi <strong>$customerName</strong>,</p>
            <p>$mainMessage</p>
            
            $passDisplayHtml

            <h3 style='text-align: right; color: #003B72; margin-top: 20px;'>Total: AED $total</h3>
            
            <p style='font-size: 12px; color: #888; text-align: center; margin-top: 30px;'>
                Ajman Water Park<br>
                Ajman, UAE
            </p>
        </div>";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

// ==============================================================================
// 3. FUNCTION: GENERATE PASS IMAGE (UPDATED: FIXED STRETCHED FACE)
// ==============================================================================
function generatePassImage($bgPath, $bookingId, $name, $expiry, $userFaceFile = null) {

    // --- SAFETY CHECK: Check kung may GD Library ---
    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        error_log("GD Library is NOT enabled.");
        return false; 
    }
    
    if (!file_exists($bgPath)) return false;

    // 1. GET SIZES
    list($width, $height) = getimagesize($bgPath);

    // 2. CREATE CANVAS
    $canvas = imagecreatetruecolor($width, $height);
    $ext = strtolower(pathinfo($bgPath, PATHINFO_EXTENSION));

    if ($ext == 'png') {
        $template = imagecreatefrompng($bgPath);
    } elseif ($ext == 'jpg' || $ext == 'jpeg') {
        $template = imagecreatefromjpeg($bgPath);
    } else {
        return false;
    }

    // 3. MERGE TEMPLATE
    imagecopyresampled($canvas, $template, 0, 0, 0, 0, $width, $height, $width, $height);

    // 4. DEFINE COLORS
    $darkBlue = imagecolorallocate($canvas, 0, 59, 114); 
    $black    = imagecolorallocate($canvas, 0, 0, 0);
    $white    = imagecolorallocate($canvas, 255, 255, 255);

    // 5. FONTS
    $fontRegular = __DIR__ . '/fonts/Montserrat-Regular.ttf'; 
    $fontBold    = __DIR__ . '/fonts/Montserrat-Bold.ttf';
    if (!file_exists($fontBold)) $fontBold = __DIR__ . '/fonts/arialbd.ttf';
    if (!file_exists($fontRegular)) $fontRegular = __DIR__ . '/fonts/arial.ttf';
    
    // -----------------------------------------------------------
    // A. FACE PHOTO PLACEMENT (FIXED: accepts filename OR absolute path)
    // -----------------------------------------------------------
    $boxX = (int) ($width * 0.048);
    $boxY = (int) ($height * 0.10);
    $boxW = (int) ($width * 0.292);
    $boxH = (int) $boxW; // Square

    $facePath = null;

    if (!empty($userFaceFile)) {
        // 1) If absolute path was passed
        if (file_exists($userFaceFile)) {
            $facePath = $userFaceFile;
        } else {
            // 2) If relative path like "uploads/faces/xxx.jpg"
            $tryRel = __DIR__ . '/' . ltrim($userFaceFile, '/\\');
            if (file_exists($tryRel)) {
                $facePath = $tryRel;
            } else {
                // 3) If only filename was passed
                $tryFile = __DIR__ . '/uploads/faces/' . basename($userFaceFile);
                if (file_exists($tryFile)) {
                    $facePath = $tryFile;
                }
            }
        }
    }

    if ($facePath) {
        $faceExt = strtolower(pathinfo($facePath, PATHINFO_EXTENSION));
        $faceImg = null;

        if ($faceExt === 'png') $faceImg = @imagecreatefrompng($facePath);
        elseif ($faceExt === 'jpg' || $faceExt === 'jpeg') $faceImg = @imagecreatefromjpeg($facePath);
        elseif ($faceExt === 'webp' && function_exists('imagecreatefromwebp')) $faceImg = @imagecreatefromwebp($facePath);

        if ($faceImg) {
            $origW = imagesx($faceImg);
            $origH = imagesy($faceImg);

            $src_ratio = $origW / max(1, $origH);
            $dst_ratio = $boxW / max(1, $boxH);

            $src_x = 0; $src_y = 0; $src_w = $origW; $src_h = $origH;

            if ($src_ratio > $dst_ratio) {
                $tempW = $origH * $dst_ratio;
                $src_x = ($origW - $tempW) / 2;
                $src_w = $tempW;
            } else {
                $tempH = $origW / $dst_ratio;
                $src_y = ($origH - $tempH) / 2;
                $src_h = $tempH;
            }

            imagecopyresampled(
                $canvas, $faceImg,
                $boxX, $boxY,
                (int)$src_x, (int)$src_y,
                $boxW, $boxH,
                (int)$src_w, (int)$src_h
            );

            // Border
            imagerectangle($canvas, $boxX, $boxY, $boxX + $boxW, $boxY + $boxH, $darkBlue);

            imagedestroy($faceImg);
        } else {
            error_log("Failed to load face image: " . $facePath);
        }
    }

    // -----------------------------------------------------------
    // B. TEXT PLACEMENT
    // -----------------------------------------------------------
    // Footer Position (Bottom White Strip)
    $footerY = (int) ($height * 0.62); 
    $textMarginLeft = (int) ($width * 0.05); 

    // 1. NAME
    $nameSize = 26; 
    $nameY = (int) ($footerY + ($height * 0.15)); 
    imagettftext($canvas, $nameSize, 0, $textMarginLeft, $nameY, $darkBlue, $fontBold, strtoupper($name));
    
    // 2. VALIDITY
    $dateSize = 13;
    $validY = (int) ($nameY + ($height * 0.07));
    imagettftext($canvas, $dateSize, 0, $textMarginLeft, $validY, $black, $fontRegular, "VALID UNTIL: " . strtoupper($expiry));

    // 3. ID NUMBER
    $idSize = 14;
    $idY = (int) ($validY + ($height * 0.06));
    $idText = "ID: " . str_pad($bookingId, 6, '0', STR_PAD_LEFT);
    imagettftext($canvas, $idSize, 0, $textMarginLeft, $idY, $black, $fontBold, $idText);

    // -----------------------------------------------------------
    // C. QR CODE PLACEMENT
    // -----------------------------------------------------------
    // Bottom Right in Footer
    $qrSize = (int) ($height * 0.25); 
    $qrMarginRight = (int) ($width * 0.05); 
    
    $qrX = (int) ($width - $qrSize - $qrMarginRight);
    $qrY = (int) ($footerY + ($height * 0.05)); 

    $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . $bookingId;
    $qrData = @file_get_contents($qrUrl);
    
    if ($qrData) {
        $qrImage = imagecreatefromstring($qrData);
        
        // White Box Background for QR
        imagefilledrectangle($canvas, $qrX - 5, $qrY - 5, $qrX + $qrSize + 5, $qrY + $qrSize + 5, $white);
        
        // Paste QR
        imagecopyresampled($canvas, $qrImage, $qrX, $qrY, 0, 0, $qrSize, $qrSize, 150, 150);
        
        imagedestroy($qrImage);
    }

    // 6. SAVE OUTPUT
    $tempDir = __DIR__ . '/temp_passes/';
    if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);

    $fileName = 'pass_' . $bookingId . '_' . time() . '.jpg'; 
    $savePath = $tempDir . $fileName;

    // Save as High Quality JPG
    if (!imagejpeg($canvas, $savePath, 100)) {
        error_log("FAILED TO SAVE IMAGE at $savePath");
        return false;
    }
    
    imagedestroy($canvas);
    imagedestroy($template);

    return $savePath;
}

function sendAddonRemainingUpdate($customerEmail, $customerName, $bookingId, $addonName, $productId, $used, $total, $remaining, $guardName) {
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'gladiatorsacademy2025@gmail.com';
        $mail->Password   = 'qlkrosezujuihjcv';
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        $mail->setFrom('gladiatorsacademy2025@gmail.com', 'Ajman Water Park');
        $mail->addAddress($customerEmail, $customerName);

        $mail->isHTML(true);
        $mail->Subject = "Add-on Update - Order #" . str_pad($bookingId, 6, '0', STR_PAD_LEFT);

        $date = date("d-M-Y h:i A");

        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width:600px; margin:0 auto; border:1px solid #eee; padding:20px;'>
            <h2 style='color:#003B72; margin:0;'>Add-on Usage Update</h2>
            <p style='margin:8px 0; color:#666;'>$date</p>

            <div style='background:#f8f9fa; padding:15px; border-radius:10px; border:1px solid #eee;'>
                <p style='margin:5px 0;'><b>Order ID:</b> #".str_pad($bookingId, 6, '0', STR_PAD_LEFT)."</p>
                <p style='margin:5px 0;'><b>Customer:</b> ".htmlspecialchars($customerName)."</p>
                <p style='margin:5px 0;'><b>Guard:</b> ".htmlspecialchars($guardName)."</p>
                <hr style='border:none; border-top:1px solid #ddd; margin:10px 0;'/>
                <p style='margin:5px 0;'><b>Add-on:</b> ".htmlspecialchars($addonName)." (".htmlspecialchars($productId).")</p>
                <p style='margin:5px 0; font-size:16px;'>
                    <b>Used:</b> $used / $total
                    <br>
                    <b>Remaining:</b> <span style='color:#28a745; font-size:18px; font-weight:bold;'>$remaining</span>
                </p>
            </div>

            <p style='margin-top:15px; font-size:12px; color:#888;'>
                This is a real-time update of your add-on usage.
            </p>
        </div>";

        $mail->send();
        return true;

    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log("Addon Update Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

// ==============================================================================
// 4. FUNCTION: SEND ADD-ON PURCHASE RECEIPT (TOP-UP / BUY MORE ADD-ONS)
// ==============================================================================
function sendAddonPurchaseReceipt($customerEmail, $customerName, $bookingId, $purchasedItems, $paymentMethod = 'card', $processedBy = 'Ajman Water Park') {
    $mail = new PHPMailer(true);

    try {
        // --- SERVER SETTINGS ---
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'gladiatorsacademy2025@gmail.com'; 
        $mail->Password   = 'qlkrosezujuihjcv'; 
        $mail->SMTPSecure = 'tls'; 
        $mail->Port       = 587;

        // --- SENDER & RECIPIENT ---
        $mail->setFrom('gladiatorsacademy2025@gmail.com', 'Ajman Water Park');
        $mail->addAddress($customerEmail, $customerName);

        // --- EMAIL CONTENT ---
        $mail->isHTML(true);
        $mail->Subject = 'Add-ons Purchase Receipt - Order #' . str_pad($bookingId, 6, '0', STR_PAD_LEFT);

        $date = date("d-M-Y h:i A");

        // Calculate total for purchased add-ons
        $total = 0;
        foreach ($purchasedItems as $it) {
            $qty = (int)($it['quantity'] ?? 0);
            $price = (float)($it['price'] ?? 0);
            $total += ($qty * $price);
        }
        $totalAmount = number_format($total, 2);

        // Build items HTML with QR per addon
        $itemsHtml = '';
        foreach ($purchasedItems as $item) {
            $itemName = htmlspecialchars($item['name'] ?? 'Add-on');
            $productId = strtoupper($item['product_id'] ?? '');
            $qty = (int)($item['quantity'] ?? 0);
            $price = (float)($item['price'] ?? 0);
            $rowTotal = number_format($qty * $price, 2);

            $uniqueItemCode = $bookingId . '-' . $productId;
            
            // [UPDATED] INCREASED QR SIZE HERE
            $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($uniqueItemCode);

            // Optional: show updated totals
            $extra = "";
            if (isset($item['new_total']) || isset($item['used']) || isset($item['remaining'])) {
                $nt = isset($item['new_total']) ? (int)$item['new_total'] : null;
                $used = isset($item['used']) ? (int)$item['used'] : null;
                $rem = isset($item['remaining']) ? (int)$item['remaining'] : null;

                $extra .= "<div style='font-size:10px; color:#444; margin-top:6px; line-height:1.3;'>";
                if ($nt !== null)  $extra .= "<div><b>New Total:</b> {$nt}</div>";
                if ($used !== null) $extra .= "<div><b>Used:</b> {$used}</div>";
                if ($rem !== null)  $extra .= "<div><b>Remaining:</b> <span style='color:#28a745; font-weight:900;'>{$rem}</span></div>";
                $extra .= "</div>";
            }

            $itemsHtml .= "
            <tr>
                <td style='padding:10px 0; border-bottom:1px dashed #ccc; vertical-align:top;'>
                    <strong>{$itemName}</strong>
                    <div style='font-size:10px; color:#555; margin-top:3px;'>({$productId})</div>
                    <div style='margin-top:8px;'>
                        <img src='{$qrUrl}' style='width:100px; height:100px; border:1px solid #ccc; padding:4px; border-radius:6px;'>
                        <div style='font-size:10px; color:#555; margin-top:3px; font-weight:bold;'>{$uniqueItemCode}</div>
                    </div>
                    {$extra}
                </td>
                <td style='padding:10px 0; border-bottom:1px dashed #ccc; text-align:center;'>{$qty}</td>
                <td style='padding:10px 0; border-bottom:1px dashed #ccc; text-align:right;'>{$rowTotal}</td>
            </tr>";
        }

        // Thermal style receipt (no "ENTRY VERIFIED" text)
        $body = "
        <div style='background-color:#e0e0e0; padding:20px; font-family: Courier New, monospace;'>
            <div style='max-width:360px; margin:0 auto; background:#fff; padding:20px; border-radius:5px; box-shadow:0 2px 5px rgba(0,0,0,0.1); color:#000;'>
                
                <div style='text-align:center; border-bottom:1px dashed #000; padding-bottom:10px; margin-bottom:10px;'>
                    <h2 style='margin:0; font-size:18px; font-weight:900;'>Ajman Water Park</h2>
                    <p style='font-size:11px; margin:5px 0;'>Waterpark & Resorts, Ajman UAE</p>
                    <p style='font-size:11px; margin:0;'>Tel: +971 52 120 7573</p>
                </div>

                <div style='text-align:center; margin-bottom:10px;'>
                    <div style='display:inline-block; padding:6px 10px; border:1px dashed #000; font-weight:900; font-size:12px;'>
                        ADD-ONS PURCHASE RECEIPT
                    </div>
                </div>

                <div style='font-size:11px; margin-bottom:10px; border-bottom:1px dashed #000; padding-bottom:10px;'>
                    <p style='margin:2px 0; display:flex; justify-content:space-between;'><strong>Order ID:</strong> <span>#".str_pad($bookingId, 6, '0', STR_PAD_LEFT)."</span></p>
                    <p style='margin:2px 0; display:flex; justify-content:space-between;'><strong>Date:</strong> <span>{$date}</span></p>
                    <p style='margin:2px 0; display:flex; justify-content:space-between;'><strong>Processed By:</strong> <span>".htmlspecialchars($processedBy)."</span></p>
                    <p style='margin:2px 0; display:flex; justify-content:space-between;'><strong>Customer:</strong> <span>".htmlspecialchars($customerName)."</span></p>
                </div>

                <table style='width:100%; font-size:11px; border-collapse:collapse; margin-bottom:10px;'>
                    <thead>
                        <tr>
                            <th style='text-align:left; border-bottom:1px dashed #000; padding-bottom:5px;'>ADD-ON</th>
                            <th style='text-align:center; border-bottom:1px dashed #000; padding-bottom:5px;'>QTY</th>
                            <th style='text-align:right; border-bottom:1px dashed #000; padding-bottom:5px;'>AMT</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$itemsHtml}
                    </tbody>
                </table>

                <div style='border-top:1px dashed #000; padding-top:10px;'>
                    <div style='display:flex; justify-content:space-between; font-weight:bold; font-size:16px; margin-bottom:5px;'>
                        <span>TOTAL </span>
                        <span> AED {$totalAmount}</span>
                    </div>
                    <div style='display:flex; justify-content:space-between; font-size:11px;'>
                        <span>Payment Method:</span>
                        <span style='text-transform:uppercase; font-weight:bold;'>".strtoupper($paymentMethod)."</span>
                    </div>
                </div>

                <div style='margin-top:14px; border-top:1px solid #eee; padding-top:10px; font-size:10px; color:#000; text-align:center;'>
                    <p style='margin:0; font-weight:900;'>IMPORTANT:</p>
                    <p style='margin:6px 0 0;'>Please show the <b>ADD-ON QR</b> to redeem at the counter/scanner.</p>
                    <p style='margin:6px 0 0;'>This is a TOP-UP to your existing Order.</p>
                </div>

            </div>
        </div>";

        $mail->Body = $body;
        $mail->AltBody = "Add-ons Purchase Receipt for Order #$bookingId. Total: AED $totalAmount";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Addon Purchase Receipt Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}