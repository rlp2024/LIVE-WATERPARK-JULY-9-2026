<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| TIMEZONE
|--------------------------------------------------------------------------
*/
date_default_timezone_set('Asia/Dubai');

/*
|--------------------------------------------------------------------------
| COMPANY LOGO
|--------------------------------------------------------------------------
*/
define('COMPANY_LOGO_PATH', __DIR__ . '/Images/awpemaillogo.png');

/*
|--------------------------------------------------------------------------
| SMTP CONFIG
|--------------------------------------------------------------------------
| Mas madali na itong baguhin sa isang lugar lang.
*/
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'nada@ajmanwaterpark.com');
define('SMTP_PASSWORD', 'nwypttvbvfmahonh');
define('SMTP_FROM_EMAIL', 'tickets@ajmanwaterpark.com');
define('SMTP_FROM_NAME', 'Ajman Water Park');

/**
 * Central mailer builder
 */
function buildMailer() {
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;

    $mail->CharSet    = 'UTF-8';
    $mail->Encoding   = 'base64';
    $mail->Timeout    = 30;

    // Keep debug off in production, but log if needed
    $mail->SMTPDebug = 0;
    $mail->Debugoutput = function ($str, $level) {
        error_log("PHPMailer SMTP[$level]: $str");
    };

    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);

    return $mail;
}

/**
 * Safe HTML escape helper
 */
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email before sending
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Embed company logo safely
 */
function mailEmbedCompanyLogo(PHPMailer $mail, $cid = 'company_logo') {
    $logoPath = COMPANY_LOGO_PATH;

    if (!empty($logoPath) && file_exists($logoPath) && is_readable($logoPath)) {
        try {
            $mail->addEmbeddedImage($logoPath, $cid);
            return "
                <div style='text-align:center; margin-bottom:10px;'>
                    <img src='cid:$cid' alt='Ajman Water Park'
                         style='max-width:160px; width:60%; height:auto; display:block; margin:0 auto;'>
                </div>
            ";
        } catch (Exception $e) {
            error_log("Logo embed failed: " . $e->getMessage());
        }
    } else {
        error_log("Logo file missing or unreadable: " . $logoPath);
    }

    return "";
}


function normalizeTicketLabelText($value) {
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/\s+/', ' ', $value);
    return trim($value);
}

function resolveValidatedTicketReceiptItem(PDO $pdo, $bookingId, array $ticketRow) {
    $ticketTypeRaw = trim((string)($ticketRow['ticket_type'] ?? 'Validated Ticket'));
    $ticketTypeNorm = normalizeTicketLabelText($ticketTypeRaw);

    $queries = [];

    if ($ticketTypeNorm !== '') {
        $queries[] = [
            "SELECT bi.product_id, bi.price_per_item AS price, tt.category, tt.sub_label
             FROM booking_items bi
             INNER JOIN ticket_types tt ON bi.product_id = CONCAT('type_', tt.type_id)
             WHERE bi.booking_id = ?
               AND LOWER(TRIM(CONCAT(tt.category, ' ', COALESCE(tt.sub_label, '')))) = ?
             LIMIT 1",
            [$bookingId, $ticketTypeNorm]
        ];

        $queries[] = [
            "SELECT bi.product_id, bi.price_per_item AS price, tt.category, tt.sub_label
             FROM booking_items bi
             INNER JOIN ticket_types tt ON bi.product_id = CONCAT('type_', tt.type_id)
             WHERE bi.booking_id = ?
               AND LOWER(TRIM(tt.category)) = ?
             LIMIT 1",
            [$bookingId, $ticketTypeNorm]
        ];
    }

    foreach ($queries as [$sql, $params]) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'product_id'  => $row['product_id'] ?? 'validated_ticket',
                'name'        => $ticketTypeRaw !== '' ? $ticketTypeRaw : trim(($row['category'] ?? 'Ticket') . ' ' . ($row['sub_label'] ?? '')),
                'quantity'    => 1,
                'price'       => (float)($row['price'] ?? 0),
                'ticket_code' => $ticketRow['ticket_code'] ?? ''
            ];
        }
    }

    $params = [$bookingId];
    $sql = "SELECT bi.product_id, bi.price_per_item AS price, tt.category, tt.sub_label
            FROM booking_items bi
            INNER JOIN ticket_types tt ON bi.product_id = CONCAT('type_', tt.type_id)
            WHERE bi.booking_id = ?";

    if (preg_match('/child/', $ticketTypeNorm)) {
        $sql .= " AND LOWER(tt.category) LIKE '%child%'";
    } elseif (preg_match('/adult/', $ticketTypeNorm)) {
        $sql .= " AND LOWER(tt.category) LIKE '%adult%'";
    }

    if (strpos($ticketTypeNorm, 'w/o') !== false || strpos($ticketTypeNorm, 'without') !== false) {
        $sql .= " AND (LOWER(COALESCE(tt.sub_label, '')) LIKE '%w/o%' OR LOWER(COALESCE(tt.sub_label, '')) LIKE '%without%')";
    } elseif (strpos($ticketTypeNorm, 'w/') !== false || strpos($ticketTypeNorm, 'with') !== false) {
        $sql .= " AND (LOWER(COALESCE(tt.sub_label, '')) LIKE '%w/%' OR LOWER(COALESCE(tt.sub_label, '')) LIKE '%with%')";
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        return [
            'product_id'  => $row['product_id'] ?? 'validated_ticket',
            'name'        => $ticketTypeRaw !== '' ? $ticketTypeRaw : trim(($row['category'] ?? 'Ticket') . ' ' . ($row['sub_label'] ?? '')),
            'quantity'    => 1,
            'price'       => (float)($row['price'] ?? 0),
            'ticket_code' => $ticketRow['ticket_code'] ?? ''
        ];
    }

    return [
        'product_id'  => 'validated_ticket',
        'name'        => $ticketTypeRaw !== '' ? $ticketTypeRaw : 'Validated Ticket',
        'quantity'    => 1,
        'price'       => 0,
        'ticket_code' => $ticketRow['ticket_code'] ?? ''
    ];
}

// ==============================================================================
// 1. SEND ENTRY RECEIPT
// ==============================================================================
function sendEntryReceipt($customerEmail, $customerName, $bookingId, $details, $items, $cashierName, $options = []) {
    global $pdo;

    if (!isValidEmail($customerEmail)) {
        error_log("sendEntryReceipt invalid email: " . $customerEmail);
        return false;
    }

    try {
        $mail = buildMailer();
        $mail->addAddress($customerEmail, $customerName);
        $mail->isHTML(true);

        $requestedValidatedTicketCode = trim((string)($options['validated_ticket_code'] ?? ''));
        $validatedTicketCode = $requestedValidatedTicketCode;
        $validatedLabelText = trim((string)($options['validated_label'] ?? 'VALIDATED'));
        $isValidatedSingle = false;

        $mail->Subject = ($isValidatedSingle ? 'Validation Receipt - Order #' : 'Official Receipt - Order #')
            . str_pad($bookingId, 6, '0', STR_PAD_LEFT);

        $logoHtml = mailEmbedCompanyLogo($mail);

        $validatedTicketRow = null;
        $validatedDateSource = null;
        $ticketQRsHtml = "";

        if ($requestedValidatedTicketCode !== '' && isset($pdo) && $pdo instanceof PDO) {
            $stmtOneTicket = $pdo->prepare("SELECT ticket_code, ticket_type, used_at FROM ticket_instances WHERE booking_id = ? AND ticket_code = ? LIMIT 1");
            $stmtOneTicket->execute([$bookingId, $validatedTicketCode]);
            $validatedTicketRow = $stmtOneTicket->fetch(PDO::FETCH_ASSOC);

            if ($validatedTicketRow) {
                $isValidatedSingle = true;
                $items = [resolveValidatedTicketReceiptItem($pdo, $bookingId, $validatedTicketRow)];
                $validatedDateSource = $validatedTicketRow['used_at'] ?? null;

                $safeTicketCode = e($validatedTicketRow['ticket_code'] ?? '');
                $safeTicketType = e($validatedTicketRow['ticket_type'] ?? 'Validated Ticket');
                $safeValidatedLabel = e($validatedLabelText !== '' ? $validatedLabelText : 'VALIDATED');
                $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($safeTicketCode);

                $ticketQRsHtml = "
                <div style='text-align:center; border-top:2px dashed #000; margin-top:15px; padding-top:15px; page-break-inside: avoid;'>
                    <h3 style='margin:0 0 10px 0; color:#003B72; font-family: Arial, sans-serif;'>VALIDATION RECEIPT</h3>
                    <p style='font-size:11px; margin-bottom:15px; color:#555;'>Only the validated ticket from this scan is included below.</p>
                    <table role='presentation' cellpadding='0' cellspacing='0' border='0' style='margin:0 auto; border:1px solid #ccc; border-radius:10px; background:#fff;'>
                        <tr>
                            <td style='padding:12px; text-align:center; vertical-align:middle;'>
                                <img src='{$qrUrl}' style='width:100px; height:100px; display:block; margin:0 auto;' alt='Validated QR Code'>
                            </td>
                            <td style='padding:12px 14px 12px 4px; text-align:left; vertical-align:middle;'>
                                <div style='display:inline-block; background:#28a745; color:#fff; font-weight:900; font-size:13px; padding:8px 14px; border-radius:999px; letter-spacing:0.8px; text-transform:uppercase; white-space:nowrap;'>{$safeValidatedLabel}</div>
                                <div style='font-weight:bold; font-size:13px; margin-top:10px; color:#003B72; text-transform:uppercase;'>{$safeTicketType}</div>
                                <div style='font-size:10px; color:#666; font-family:monospace; margin-top:4px; word-break:break-all;'>{$safeTicketCode}</div>
                            </td>
                        </tr>
                    </table>
                </div>";
            }
        }

        if (!$validatedDateSource) {
            $validatedDateSource = date("Y-m-d H:i:s");
        }
        $date = date("d-M-Y h:i A", strtotime($validatedDateSource));

        $visitDateRaw = $details['visit_date']
            ?? $details['date_of_visit']
            ?? $details['visitDay']
            ?? $details['visit_day']
            ?? $details['visitDate']
            ?? null;
        $visitDate = $visitDateRaw ? date("d-M-Y", strtotime($visitDateRaw)) : "N/A";

        if (!$isValidatedSingle) {
            
            // --- NEW SAFETY FALLBACK CODE START ---
            // Kung hindi makita ang $pdo mula sa global scope (dahil nasa loob ng isa pang function ang dashboard script), 
            // i-require natin ulit ang db_connect.php para siguradong may connection at makuha ang main tickets.
            if (!isset($pdo) || !($pdo instanceof PDO)) {
                if (file_exists(__DIR__ . '/db_connect.php')) {
                    require __DIR__ . '/db_connect.php';
                } elseif (file_exists($_SERVER['DOCUMENT_ROOT'] . '/db_connect.php')) {
                    require $_SERVER['DOCUMENT_ROOT'] . '/db_connect.php';
                }
            }
            // --- NEW SAFETY FALLBACK CODE END ---

            if (isset($pdo) && $pdo instanceof PDO) {
                $stmtTix = $pdo->prepare("SELECT ticket_code, ticket_type FROM ticket_instances WHERE booking_id = ?");
                $stmtTix->execute([$bookingId]);
                $tickets = $stmtTix->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($tickets)) {
                    $ticketQRsHtml .= "<div style='text-align:center; border-top:2px dashed #000; margin-top:15px; padding-top:15px; page-break-inside: avoid;'>";
                    $ticketQRsHtml .= "<h3 style='margin:0 0 10px 0; color:#003B72; font-family: Arial, sans-serif;'>YOUR ENTRANCE TICKETS</h3>";
                    $ticketQRsHtml .= "<p style='font-size:11px; margin-bottom:15px; color:#555;'>Scan each code individually at the Turnstile Gate.</p>";

                    foreach ($tickets as $t) {
                        $ticketCode = e($t['ticket_code'] ?? '');
                        $ticketType = e($t['ticket_type'] ?? '');
                        $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($ticketCode);

                        $ticketQRsHtml .= "
                        <div style='display:inline-block; vertical-align:top; margin:5px; padding:10px; border:1px solid #ccc; border-radius:8px; background:#fff; width:110px;'>
                            <img src='$qrUrl' style='width:90px; height:90px; display:block; margin:0 auto;' alt='Entry Ticket QR'>
                            <div style='font-weight:bold; font-size:12px; margin-top:8px; color:#003B72; text-transform:uppercase;'>{$ticketType}</div>
                            <div style='font-size:9px; color:#666; font-family:monospace; margin-top:2px;'>{$ticketCode}</div>
                        </div>";
                    }
                    $ticketQRsHtml .= "</div>";
                }
            } else {
                error_log("sendEntryReceipt warning: PDO connection unavailable.");
            }
        }

        $itemsHtml = '';
        $computedItemsTotal = 0.0;

        // === AUGMENT $items WITH BUNDLED MEAL VOUCHERS ===
        // Para makasama sa receipt at email yung 4x ADD6 (Meal Voucher)
        // na galing sa Family Package bundle. Tinitignan natin ang
        // addon_redemptions table at idinadagdag ang excess qty bilang
        // virtual line items na may price = 0.
        if (!$isValidatedSingle && isset($pdo) && $pdo instanceof PDO) {
            try {
                $stmtRedeemAug = $pdo->prepare("
                    SELECT ar.product_id, ar.quantity_total,
                           COALESCE(p.name, ar.product_id) AS name
                    FROM addon_redemptions ar
                    LEFT JOIN products p ON ar.product_id = p.product_id
                    WHERE ar.booking_id = ?
                ");
                $stmtRedeemAug->execute([$bookingId]);
                $redemptionsAug = $stmtRedeemAug->fetchAll(PDO::FETCH_ASSOC);

                $existingByProductAug = [];
                foreach ($items as $itAug) {
                    $pidAug = strtoupper((string)($itAug['product_id'] ?? ''));
                    if (!isset($existingByProductAug[$pidAug])) $existingByProductAug[$pidAug] = 0;
                    $existingByProductAug[$pidAug] += (int)($itAug['quantity'] ?? 0);
                }

                foreach ($redemptionsAug as $rAug) {
                    $pidAug = strtoupper((string)$rAug['product_id']);
                    $totalQtyAug = (int)$rAug['quantity_total'];
                    $existingQtyAug = $existingByProductAug[$pidAug] ?? 0;
                    $bundledQtyAug = $totalQtyAug - $existingQtyAug;

                    if ($bundledQtyAug > 0) {
                        $items[] = [
                            'product_id' => $rAug['product_id'],
                            'quantity'   => $bundledQtyAug,
                            'price'      => 0.00,
                            'name'       => $rAug['name'],
                            'is_bundled' => true,
                        ];
                    }
                }
            } catch (Throwable $eAug) {
                error_log("sendEntryReceipt augment redemptions failed: " . $eAug->getMessage());
            }
        }

// Hanapin ang foreach loop sa loob ng sendEntryReceipt function (bandang Line 205)
foreach ($items as $item) {
    $qty = max(1, (int)($item['quantity'] ?? 0));
    $price = (float)($item['price'] ?? 0);
    $rowTotalValue = $price * $qty;
    $computedItemsTotal += $rowTotalValue;
    $rowTotal = number_format($rowTotalValue, 2);

    // KUNIN ANG PRODUCT ID AT PANGALAN
    $productIdRaw = strtoupper((string)($item['product_id'] ?? ''));
    
    // Kunin ang pangalan mula sa 'name', o kaya sa pinagsamang 'category' at 'sub_label'
    $cat = $item['category'] ?? '';
    $sub = $item['sub_label'] ?? '';
    $combinedName = trim($cat . ' ' . $sub);
    
    if (!empty($item['name']) && $item['name'] !== 'Item') {
        $itemName = $item['name'];
    } elseif (!empty($combinedName)) {
        $itemName = $combinedName;
    } else {
        $itemName = $productIdRaw; // Fallback muna sa ID
    }

    // --- MAPPING LOGIC PARA SA EMAIL ---
    switch ($productIdRaw) {
        case 'ADD1': $itemName = "Parking"; break;
        case 'ADD2': $itemName = "Zipline"; break;
        case 'ADD3': $itemName = "Locker"; break;
        case 'ADD4': $itemName = "Hanging Bridge"; break;
        case 'ADD5': $itemName = "Photography"; break;
        case 'ADD6': $itemName = "Meal Voucher"; break;
        default:
            // Check para sa dynamic IDs tulad ng QRN_123 o QRR_456
            if (strpos($productIdRaw, 'QRN_') === 0) {
                $itemName = "New QR Wallet Card";
            } elseif (strpos($productIdRaw, 'QRR_') === 0) {
                $itemName = "QR Wallet Reload";
            } 
            // Fallback logic para sa Entrance Tickets (gagawin lang 'to kung walang nakuha sa DB)
            elseif (empty($itemName) || $itemName == $productIdRaw) {
                if (strpos($productIdRaw, 'TYPE_') === 0) {
                    $itemName = "Entrance Ticket (" . str_replace('TYPE_', '', $productIdRaw) . ")";
                } else {
                    $itemName = "Item: " . $productIdRaw;
                }
            }
            break;
    }
    
    $itemName = e($itemName); // I-escape para sa HTML safety
    if (!empty($item['is_bundled'])) {
        $itemName .= " <span style='color:#16a34a; font-size:10px; font-weight:600;'>(Family Package - Included)</span>";
    }
    $uniqueItemCode = $bookingId . '-' . $productIdRaw;
    $qrCodeHtml = '';

    // Check kung Add-on para lagyan ng QR sa email
    $isAddon = (strpos($productIdRaw, 'ADD') === 0);

    if ($isAddon) {
        $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($uniqueItemCode);
        $qrCodeHtml = "<br><div style='margin-top:5px; border:1px solid #eee; display:inline-block; padding:5px; background:#fff;'>
                        <img src='$qrUrl' style='width:100px; height:100px; display:block;' alt='Add-on QR'>
                        <div style='font-size:9px; color:#555; font-family:monospace; margin-top:2px;'>Add-on: " . e($uniqueItemCode) . "</div>
                       </div>";
    }

    $itemsHtml .= "
    <tr>
        <td style='padding:10px 0; border-bottom:1px dashed #ccc; vertical-align:top;'>
            <strong>{$itemName}</strong>
            $qrCodeHtml
        </td>
        <td style='padding:10px 0; border-bottom:1px dashed #ccc; text-align:center; vertical-align:top;'>{$qty}</td>
        <td style='padding:10px 0; border-bottom:1px dashed #ccc; text-align:right; vertical-align:top;'>AED {$rowTotal}</td>
    </tr>";
}

        $totalAmount = number_format($isValidatedSingle ? $computedItemsTotal : (float)($details['total_amount'] ?? $computedItemsTotal), 2);

        // Bagong logic para palitan ang display ng QR_POINTS
        $rawPaymentMethod = $details['payment_method'] ?? 'N/A';
        if ($rawPaymentMethod === 'qr_points') {
            $paymentMethodDisplay = 'QR Wallet / Points'; // Pwede mong palitan kung anong text ang gusto mo
        } else {
            $paymentMethodDisplay = strtoupper($rawPaymentMethod);
        }
        $paymentMethod = e($paymentMethodDisplay);

        $safeCustomerName = e($customerName);

        $safeCashierName = e($cashierName);

        $headerBadgeHtml = $isValidatedSingle
            ? "<div style='text-align:center; margin-bottom:10px;'><div style='display:inline-block; padding:6px 12px; border-radius:999px; background:#28a745; color:#fff; font-size:12px; font-weight:900; letter-spacing:0.8px; text-transform:uppercase;'>Validation Receipt</div></div>"
            : '';

        $dateLabel = $isValidatedSingle ? 'Validation Date:' : 'Purchase Date:';

        $vatHtml = $isValidatedSingle
            ? ''
            : "<div style='display:flex; justify-content:space-between; font-size:11px; margin-bottom:4px;'>
                    <span>VAT (5% included):</span>
                    <span>AED " . number_format((float)($details['vat_amount'] ?? 0), 2) . "</span>
               </div>";

        $totalLabel = $isValidatedSingle ? 'VALIDATED TICKET TOTAL:' : 'GRAND TOTAL:';

        $footerText = $isValidatedSingle
            ? "<p style='margin-bottom:5px;'><strong>This email confirms one validated entrance ticket only.</strong></p>"
            : "<p style='margin-bottom:5px;'><strong>Scan tickets above at entrance.</strong></p>";

        $body = "
        <div style='background-color:#e0e0e0; padding:20px; font-family: Courier New, monospace;'>
            <div style='max-width:350px; margin:0 auto; background:#fff; padding:20px; border-radius:5px; box-shadow:0 2px 5px rgba(0,0,0,0.1); color:#000;'>

                <div style='text-align:center; border-bottom:1px dashed #000; padding-bottom:10px; margin-bottom:10px;'>
                    $logoHtml
                    $headerBadgeHtml
                    <h2 style='margin:0; font-size:18px; font-weight:900;'>Ajman Water Park</h2>
                    <p style='font-size:11px; margin:5px 0;'>Waterpark & Resorts, Ajman UAE</p>
                    <p style='font-size:11px; margin:0;'>Tel: +971 52 120 7573</p>
                </div>

                <div style='font-size:11px; margin-bottom:10px; border-bottom:1px dashed #000; padding-bottom:10px;'>
                    <p style='margin:2px 0; display:flex; justify-content:space-between;'><strong>Order ID:</strong> <span>#" . str_pad($bookingId, 6, '0', STR_PAD_LEFT) . "</span></p>
                    <p style='margin:2px 0; display:flex; justify-content:space-between;'><strong>{$dateLabel}</strong> <span>{$date}</span></p>
                    <p style='margin:2px 0; display:flex; justify-content:space-between;'><strong>Date of Visit:</strong> <span>{$visitDate}</span></p>
                    <p style='margin:2px 0; display:flex; justify-content:space-between;'><strong>Person on Duty:</strong> <span>{$safeCashierName}</span></p>
                    <p style='margin:2px 0; display:flex; justify-content:space-between;'><strong>Customer:</strong> <span>{$safeCustomerName}</span></p>
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
                    {$vatHtml}
                    <div style='font-size:16px; margin-bottom:8px;'>
                        <strong>{$totalLabel}</strong> AED {$totalAmount}
                    </div>

                    <div style='display:flex; justify-content:space-between; font-size:11px;'>
                        <span>Payment Method:</span>
                        <span style='text-transform:uppercase; font-weight:bold;'>
                            " . ($paymentMethod === 'qr_points' ? 'QR WALLET / POINTS' : e(strtoupper($paymentMethod))) . "
                        </span>
                    </div>
                </div>

                {$ticketQRsHtml}

                <div style='text-align:center; margin-top:20px; padding-top:10px; border-top:1px solid #eee; font-size:10px; color:#000;'>
                    {$footerText}
                    <p style='margin:5px 0;'>Thank you for visiting!</p>
                    <p style='margin:0;'>www.ajmanwaterpark.com</p>
                </div>

            </div>
        </div>";

        $mail->Body = $body;
        $mail->AltBody = ($isValidatedSingle ? "Validated entry receipt" : "Receipt") . " for Order #{$bookingId}. Total: AED {$totalAmount}";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("sendEntryReceipt ErrorInfo: " . (isset($mail) ? $mail->ErrorInfo : 'mailer not initialized'));
        error_log("sendEntryReceipt Exception: " . $e->getMessage());
        return false;
    }
}

// ==============================================================================
// 2. SEND BOOKING CONFIRMATION
// ==============================================================================
function sendBookingConfirmation($customerEmail, $customerName, $bookingId, $details, $items) {
    if (!isValidEmail($customerEmail)) {
        error_log("sendBookingConfirmation invalid email: " . $customerEmail);
        return false;
    }

    try {
        $mail = buildMailer();

        $mail->addAddress($customerEmail, $customerName);
        $mail->isHTML(true);
        $mail->Subject = 'Membership Confirmed - #' . str_pad($bookingId, 6, '0', STR_PAD_LEFT);

        $logoHtml = mailEmbedCompanyLogo($mail);

        $passImageFile = '';
        $basePath = __DIR__ . '/';

        foreach ($items as $item) {
            $pid = $item['product_id'] ?? '';

            if ($pid == 'AP1') {
                $passImageFile = 'Images/annual_passes/annual_passes-SILVER-V3.jpg';
            } elseif ($pid == 'AP2') {
                $passImageFile = 'Images/annual_passes/annual_passes-GOLD-V3.jpg';
            } elseif ($pid == 'AP3') {
                $passImageFile = 'Images/annual_passes/annual_passes-PLATINUM-V3.jpg';
            }
        }

        $passDisplayHtml = "";
        $mainMessage = "";

        if (!empty($passImageFile) && !empty($details['expiry_date'])) {
            $bgPath = $basePath . $passImageFile;
            $expiryDate = date("F d, Y", strtotime($details['expiry_date']));
            $facePath = $details['face_image_path'] ?? null;

            if (file_exists($bgPath)) {
                $generatedImagePath = generatePassImage($bgPath, $bookingId, $customerName, $expiryDate, $facePath);

                if ($generatedImagePath && file_exists($generatedImagePath)) {
                    $mail->addEmbeddedImage($generatedImagePath, 'digital_pass_img');

                    $passDisplayHtml = "
                    <div style='text-align:center; margin: 20px 0;'>
                        <p style='font-size:16px; color:#003B72; font-weight:bold;'>OFFICIAL DIGITAL MEMBER CARD</p>
                        <img src='cid:digital_pass_img' alt='Annual Pass' style='width:100%; max-width:600px; border-radius:15px; box-shadow:0 4px 15px rgba(0,0,0,0.3);'>
                        <p style='font-size:12px; color:#888; margin-top:5px;'>Please save this image to your phone gallery.</p>
                    </div>";

                    $mainMessage = "Congratulations! Here is your Annual Pass. You have unlimited access until <strong>{$expiryDate}</strong>.";
                } else {
                    error_log("sendBookingConfirmation failed to generate or find pass image for booking: " . $bookingId);
                }
            } else {
                error_log("Pass background image missing: " . $bgPath);
            }
        }

        if (empty($passDisplayHtml)) {
            $mainMessage = "Thank you for joining Ajman Water Park!";
        }

        $total = number_format((float)($details['total_amount'] ?? 0), 2);
        $safeCustomerName = e($customerName);

        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #eee; padding: 20px;'>
            $logoHtml
            <h2 style='color: #003B72; text-align: center;'>Membership Confirmed!</h2>
            <p>Hi <strong>{$safeCustomerName}</strong>,</p>
            <p>{$mainMessage}</p>

            {$passDisplayHtml}

            <h3 style='text-align: right; color: #003B72; margin-top: 20px;'>Total: AED {$total}</h3>

            <p style='font-size: 12px; color: #888; text-align: center; margin-top: 30px;'>
                Ajman Water Park<br>
                Ajman, UAE
            </p>
        </div>";

        $mail->AltBody = "Membership confirmed. Booking #{$bookingId}. Total AED {$total}";
        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("sendBookingConfirmation ErrorInfo: " . (isset($mail) ? $mail->ErrorInfo : 'mailer not initialized'));
        error_log("sendBookingConfirmation Exception: " . $e->getMessage());
        return false;
    }
}

// ==============================================================================
// 3. GENERATE PASS IMAGE
// ==============================================================================
function generatePassImage($bgPath, $bookingId, $name, $expiry, $userFaceFile = null) {
    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        error_log("GD Library is NOT enabled.");
        return false;
    }

    if (!file_exists($bgPath)) {
        error_log("Pass background not found: " . $bgPath);
        return false;
    }

    $imageInfo = getimagesize($bgPath);
    if ($imageInfo === false) {
        error_log("Could not read image size: " . $bgPath);
        return false;
    }

    list($width, $height) = $imageInfo;

    $canvas = imagecreatetruecolor($width, $height);
    $ext = strtolower(pathinfo($bgPath, PATHINFO_EXTENSION));

    if ($ext == 'png') {
        $template = imagecreatefrompng($bgPath);
    } elseif ($ext == 'jpg' || $ext == 'jpeg') {
        $template = imagecreatefromjpeg($bgPath);
    } else {
        error_log("Unsupported background image type: " . $bgPath);
        return false;
    }

    imagecopyresampled($canvas, $template, 0, 0, 0, 0, $width, $height, $width, $height);

    $darkBlue = imagecolorallocate($canvas, 0, 59, 114);
    $black    = imagecolorallocate($canvas, 0, 0, 0);
    $white    = imagecolorallocate($canvas, 255, 255, 255);

    $fontRegular = __DIR__ . '/fonts/Montserrat-Regular.ttf';
    $fontBold    = __DIR__ . '/fonts/Montserrat-Bold.ttf';
    if (!file_exists($fontBold)) $fontBold = __DIR__ . '/fonts/arialbd.ttf';
    if (!file_exists($fontRegular)) $fontRegular = __DIR__ . '/fonts/arial.ttf';

    $boxX = (int) ($width * 0.048);
    $boxY = (int) ($height * 0.10);
    $boxW = (int) ($width * 0.292);
    $boxH = (int) $boxW;

    $facePath = null;

    if (!empty($userFaceFile)) {
        if (file_exists($userFaceFile)) {
            $facePath = $userFaceFile;
        } else {
            $tryRel = __DIR__ . '/' . ltrim($userFaceFile, '/\\');
            if (file_exists($tryRel)) {
                $facePath = $tryRel;
            } else {
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

            imagerectangle($canvas, $boxX, $boxY, $boxX + $boxW, $boxY + $boxH, $darkBlue);
            imagedestroy($faceImg);
        } else {
            error_log("Failed to load face image: " . $facePath);
        }
    }

    $footerY = (int) ($height * 0.62);
    $textMarginLeft = (int) ($width * 0.05);

    $nameSize = 26;
    $nameY = (int) ($footerY + ($height * 0.15));
    imagettftext($canvas, $nameSize, 0, $textMarginLeft, $nameY, $darkBlue, $fontBold, strtoupper($name));

    $dateSize = 13;
    $validY = (int) ($nameY + ($height * 0.07));
    imagettftext($canvas, $dateSize, 0, $textMarginLeft, $validY, $black, $fontRegular, "VALID UNTIL: " . strtoupper($expiry));

    $idSize = 14;
    $idY = (int) ($validY + ($height * 0.06));
    $idText = "ID: " . str_pad($bookingId, 6, '0', STR_PAD_LEFT);
    imagettftext($canvas, $idSize, 0, $textMarginLeft, $idY, $black, $fontBold, $idText);

    $qrSize = (int) ($height * 0.25);
    $qrMarginRight = (int) ($width * 0.05);

    $qrX = (int) ($width - $qrSize - $qrMarginRight);
    $qrY = (int) ($footerY + ($height * 0.05));

    $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode((string)$bookingId);
    $qrData = @file_get_contents($qrUrl);

    if ($qrData !== false) {
        $qrImage = @imagecreatefromstring($qrData);
        if ($qrImage) {
            imagefilledrectangle($canvas, $qrX - 5, $qrY - 5, $qrX + $qrSize + 5, $qrY + $qrSize + 5, $white);
            imagecopyresampled($canvas, $qrImage, $qrX, $qrY, 0, 0, $qrSize, $qrSize, 150, 150);
            imagedestroy($qrImage);
        } else {
            error_log("Failed to create QR image from response for booking: " . $bookingId);
        }
    } else {
        error_log("Failed to fetch QR image: " . $qrUrl);
    }

    $tempDir = __DIR__ . '/temp_passes/';
    if (!is_dir($tempDir) && !mkdir($tempDir, 0777, true)) {
        error_log("Failed to create temp directory: " . $tempDir);
        return false;
    }

    $fileName = 'pass_' . $bookingId . '_' . time() . '.jpg';
    $savePath = $tempDir . $fileName;

    if (!imagejpeg($canvas, $savePath, 100)) {
        error_log("FAILED TO SAVE IMAGE at " . $savePath);
        imagedestroy($canvas);
        imagedestroy($template);
        return false;
    }

    imagedestroy($canvas);
    imagedestroy($template);

    return $savePath;
}

// ==============================================================================
// 4. SEND ADD-ON REMAINING UPDATE
// ==============================================================================
function sendAddonRemainingUpdate($customerEmail, $customerName, $bookingId, $addonName, $productId, $used, $total, $remaining, $guardName) {
    if (!isValidEmail($customerEmail)) {
        error_log("sendAddonRemainingUpdate invalid email: " . $customerEmail);
        return false;
    }

    try {
        $mail = buildMailer();

        $mail->addAddress($customerEmail, $customerName);
        $mail->isHTML(true);
        $mail->Subject = "Add-on Update - Order #" . str_pad($bookingId, 6, '0', STR_PAD_LEFT);

        $logoHtml = mailEmbedCompanyLogo($mail);
        $date = date("d-M-Y h:i A");

        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width:600px; margin:0 auto; border:1px solid #eee; padding:20px;'>
            $logoHtml
            <h2 style='color:#003B72; margin:0;'>Add-on Usage Update</h2>
            <p style='margin:8px 0; color:#666;'>$date</p>

            <div style='background:#f8f9fa; padding:15px; border-radius:10px; border:1px solid #eee;'>
                <p style='margin:5px 0;'><b>Order ID:</b> #".str_pad($bookingId, 6, '0', STR_PAD_LEFT)."</p>
                <p style='margin:5px 0;'><b>Customer:</b> ".e($customerName)."</p>
                <p style='margin:5px 0;'><b>Guard:</b> ".e($guardName)."</p>
                <hr style='border:none; border-top:1px solid #ddd; margin:10px 0;'/>
                <p style='margin:5px 0;'><b>Add-on:</b> ".e($addonName)." (".e($productId).")</p>
                <p style='margin:5px 0; font-size:16px;'>
                    <b>Used:</b> ".(int)$used." / ".(int)$total."
                    <br>
                    <b>Remaining:</b> <span style='color:#28a745; font-size:18px; font-weight:bold;'>".(int)$remaining."</span>
                </p>
            </div>

            <p style='margin-top:15px; font-size:12px; color:#888;'>
                This is a real-time update of your add-on usage.
            </p>
        </div>";

        $mail->AltBody = "Add-on usage update for Order #" . str_pad($bookingId, 6, '0', STR_PAD_LEFT);
        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("sendAddonRemainingUpdate ErrorInfo: " . (isset($mail) ? $mail->ErrorInfo : 'mailer not initialized'));
        error_log("sendAddonRemainingUpdate Exception: " . $e->getMessage());
        return false;
    }
}

// ==============================================================================
// 5. SEND ADD-ON PURCHASE RECEIPT
// ==============================================================================
function sendAddonPurchaseReceipt($customerEmail, $customerName, $bookingId, $purchasedItems, $paymentMethod = 'card', $processedBy = 'Ajman Water Park') {
    if (!isValidEmail($customerEmail)) {
        error_log("sendAddonPurchaseReceipt invalid email: " . $customerEmail);
        return false;
    }

    try {
        $mail = buildMailer();

        $mail->addAddress($customerEmail, $customerName);
        $mail->isHTML(true);
        $mail->Subject = 'Add-ons Purchase Receipt - Order #' . str_pad($bookingId, 6, '0', STR_PAD_LEFT);

        $logoHtml = mailEmbedCompanyLogo($mail);
        $date = date("d-M-Y h:i A");

        $total = 0;
        foreach ($purchasedItems as $it) {
            $qty = (int)($it['quantity'] ?? 0);
            $price = (float)($it['price'] ?? 0);
            $total += ($qty * $price);
        }
        $totalAmount = number_format($total, 2);

        $itemsHtml = '';
        foreach ($purchasedItems as $item) {
            $itemName = e($item['name'] ?? 'Add-on');
            $productIdRaw = strtoupper((string)($item['product_id'] ?? ''));
            $productId = e($productIdRaw);
            $qty = (int)($item['quantity'] ?? 0);
            $price = (float)($item['price'] ?? 0);
            $rowTotal = number_format($qty * $price, 2);

            $uniqueItemCode = $bookingId . '-' . $productIdRaw;
            $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($uniqueItemCode);

            $extra = "";
            if (isset($item['new_total']) || isset($item['used']) || isset($item['remaining'])) {
                $nt = isset($item['new_total']) ? (int)$item['new_total'] : null;
                $usedVal = isset($item['used']) ? (int)$item['used'] : null;
                $rem = isset($item['remaining']) ? (int)$item['remaining'] : null;

                $extra .= "<div style='font-size:10px; color:#444; margin-top:6px; line-height:1.3;'>";
                if ($nt !== null)  $extra .= "<div><b>New Total:</b> {$nt}</div>";
                if ($usedVal !== null) $extra .= "<div><b>Used:</b> {$usedVal}</div>";
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
                        <div style='font-size:10px; color:#555; margin-top:3px; font-weight:bold;'>".e($uniqueItemCode)."</div>
                    </div>
                    {$extra}
                </td>
                <td style='padding:10px 0; border-bottom:1px dashed #ccc; text-align:center;'>{$qty}</td>
                <td style='padding:10px 0; border-bottom:1px dashed #ccc; text-align:right;'>{$rowTotal}</td>
            </tr>";
        }

        $body = "
        <div style='background-color:#e0e0e0; padding:20px; font-family: Courier New, monospace;'>
            <div style='max-width:350px; margin:0 auto; background:#fff; padding:20px; border-radius:5px; box-shadow:0 2px 5px rgba(0,0,0,0.1); color:#000;'>

                <div style='text-align:center; border-bottom:1px dashed #000; padding-bottom:10px; margin-bottom:10px;'>
                    $logoHtml
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
                    <p style='margin:2px 0; display:flex; justify-content:space-between;'><strong>Processed By:</strong> <span>".e($processedBy)."</span></p>
                    <p style='margin:2px 0; display:flex; justify-content:space-between;'><strong>Customer:</strong> <span>".e($customerName)."</span></p>
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
                    <div style='display:flex; justify-content:space-between; font-size:11px; margin-bottom:4px;'>
                        <span>VAT (5% included):</span>
                        <span>AED ".number_format($total * 0.05, 2)."</span>
                    </div>
                    <div style='font-size:16px; margin-bottom:8px;'>
                        <strong>GRAND TOTAL:</strong> AED ".number_format($total * 1.05, 2)."
                    </div>

                    <div style='display:flex; justify-content:space-between; font-size:11px;'>
                        <span>Payment Method:</span>
                        <span style='text-transform:uppercase; font-weight:bold;'>".e(strtoupper($paymentMethod))."</span>
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
        $mail->AltBody = "Add-ons Purchase Receipt for Order #{$bookingId}. Total: AED {$totalAmount}";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("sendAddonPurchaseReceipt ErrorInfo: " . (isset($mail) ? $mail->ErrorInfo : 'mailer not initialized'));
        error_log("sendAddonPurchaseReceipt Exception: " . $e->getMessage());
        return false;
    }
}
// ==============================================================================
// 6. SEND RESCHEDULE CONFIRMATION
// ==============================================================================
function sendRescheduleEmail($customerEmail, $customerName, $bookingId, $details, $items, $newDate, $processedBy) {
    global $pdo;

    if (!isValidEmail($customerEmail)) {
        error_log("sendRescheduleEmail invalid email: " . $customerEmail);
        return false;
    }

    try {
        $mail = buildMailer();
        $mail->addAddress($customerEmail, $customerName);
        $mail->isHTML(true);
        $mail->Subject = 'Booking Rescheduled - Order #' . str_pad($bookingId, 6, '0', STR_PAD_LEFT);

        $logoHtml = mailEmbedCompanyLogo($mail);
        $formattedNewDate = date("F d, Y", strtotime($newDate));

        $ticketQRsHtml = "";
        
        // Fetch the QR codes again so they don't have to look for the old email
        if (isset($pdo) && $pdo instanceof PDO) {
            $stmtTix = $pdo->prepare("SELECT ticket_code, ticket_type FROM ticket_instances WHERE booking_id = ?");
            $stmtTix->execute([$bookingId]);
            $tickets = $stmtTix->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($tickets)) {
                $ticketQRsHtml .= "<div style='text-align:center; border-top:1px dashed #000; margin-top:15px; padding-top:15px; page-break-inside: avoid;'>";
                $ticketQRsHtml .= "<h3 style='margin:0 0 10px 0; color:#003B72; font-family: Arial, sans-serif;'>YOUR TICKETS</h3>";
                $ticketQRsHtml .= "<p style='font-size:11px; margin-bottom:15px; color:#555;'>Please present these tickets on your new visit date.</p>";

                foreach ($tickets as $t) {
                    $ticketCode = e($t['ticket_code'] ?? '');
                    $ticketType = e($t['ticket_type'] ?? '');
                    $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($ticketCode);

                    $ticketQRsHtml .= "
                    <div style='display:inline-block; vertical-align:top; margin:5px; padding:10px; border:1px solid #ccc; border-radius:8px; background:#fff; width:110px;'>
                        <img src='$qrUrl' style='width:90px; height:90px; display:block; margin:0 auto;'>
                        <div style='font-weight:bold; font-size:12px; margin-top:8px; color:#003B72; text-transform:uppercase;'>{$ticketType}</div>
                        <div style='font-size:9px; color:#666; font-family:monospace; margin-top:2px;'>{$ticketCode}</div>
                    </div>";
                }
                $ticketQRsHtml .= "</div>";
            }
        }

        $safeCustomerName = e($customerName);
        $safeProcessedBy = e($processedBy);

        $body = "
        <div style='background-color:#e0e0e0; padding:20px; font-family: Courier New, monospace;'>
            <div style='max-width:350px; margin:0 auto; background:#fff; padding:20px; border-radius:5px; box-shadow:0 2px 5px rgba(0,0,0,0.1); color:#000;'>

                <div style='text-align:center; border-bottom:1px dashed #000; padding-bottom:10px; margin-bottom:10px;'>
                    $logoHtml
                    <h2 style='margin:0; font-size:18px; font-weight:900;'>Ajman Water Park</h2>
                </div>

                <div style='text-align:center; margin-bottom:15px; background:#e2f3f5; padding:10px; border-radius:5px; border:1px solid #b8daff; color:#004085;'>
                    <strong style='font-size:14px;'>NOTICE OF RESCHEDULE</strong><br>
                    Your visit has been successfully moved!
                </div>

                <div style='font-size:11px; margin-bottom:10px;'>
                    <p style='margin:4px 0; display:flex; justify-content:space-between;'><strong>Order ID:</strong> <span>#".str_pad($bookingId, 6, '0', STR_PAD_LEFT)."</span></p>
                    <p style='margin:4px 0; display:flex; justify-content:space-between;'><strong>Customer:</strong> <span>$safeCustomerName</span></p>
                    <p style='margin:4px 0; display:flex; justify-content:space-between;'><strong>Processed By:</strong> <span>$safeProcessedBy</span></p>
                </div>
                
                <div style='text-align:center; margin:15px 0; padding:10px; border:1px dashed #28a745; background:#f4fdf6;'>
                    <div style='font-size:11px; color:#555; margin-bottom:5px;'>NEW VISIT DATE:</div>
                    <div style='font-size:16px; font-weight:bold; color:#28a745;'>$formattedNewDate</div>
                </div>

                $ticketQRsHtml

                <div style='text-align:center; margin-top:20px; padding-top:10px; border-top:1px solid #eee; font-size:10px; color:#000;'>
                    <p style='margin-bottom:5px;'><strong>See you on your new date!</strong></p>
                    <p style='margin:0;'>www.ajmanwaterpark.com</p>
                </div>

            </div>
        </div>";

        $mail->Body = $body;
        $mail->AltBody = "Your booking #{$bookingId} has been rescheduled to {$formattedNewDate}.";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("sendRescheduleEmail ErrorInfo: " . (isset($mail) ? $mail->ErrorInfo : 'mailer not initialized'));
        error_log("sendRescheduleEmail Exception: " . $e->getMessage());
        return false;
    }
}
// ==============================================================================
// 7. SEND QR WALLET PURCHASE RECEIPT (PROOF OF PURCHASE ONLY)
// ==============================================================================
function sendQRWalletReceipt($customerEmail, $customerName, $walletId, $amount, $type = 'NEW') {
    if (!isValidEmail($customerEmail)) return false;

    try {
        $mail = buildMailer();
        $mail->addAddress($customerEmail, $customerName);
        $mail->isHTML(true);
        $mail->Subject = 'QR Wallet Top-Up Confirmation - W-' . str_pad($walletId, 4, '0', STR_PAD_LEFT);

        $logoHtml = mailEmbedCompanyLogo($mail);
        $date = date("F d, Y h:i A");
        $amountFormatted = number_format($amount, 2);
        $safeName = e($customerName);

        $statusMsg = "";
        if ($type === 'NEW') {
            $title = "Purchase Successful!";
            $statusMsg = "<strong>IMPORTANT:</strong><br>This email is your proof of purchase. Please present this receipt at the reception counter to claim your physical QR Wallet Card.";
        } else {
            $title = "Card Reload Successful!";
            $statusMsg = "<strong>SUCCESS:</strong><br>Your existing QR Card has been successfully reloaded. You can now use your points anywhere inside the park.";
        }

        $body = "
        <div style='background-color:#e0e0e0; padding:20px; font-family: Arial, sans-serif;'>
            <div style='max-width:400px; margin:0 auto; background:#fff; padding:20px; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.1); color:#333;'>
                $logoHtml
                <h2 style='text-align:center; color:#003B72; margin-top:0;'>$title</h2>
                <p>Hi <strong>$safeName</strong>,</p>
                <p>Thank you for purchasing Ajman Water Park QR Points.</p>
                
                <div style='background:#f4f8fb; border:1px dashed #003B72; padding:15px; border-radius:5px; margin:15px 0;'>
                    <p style='margin:0 0 5px 0;'><strong>Wallet Reference:</strong> W-" . str_pad($walletId, 4, '0', STR_PAD_LEFT) . "</p>
                    <p style='margin:0 0 5px 0;'><strong>Date:</strong> $date</p>
                    <p style='margin:0; font-size:16px;'><strong>Points Loaded:</strong> AED $amountFormatted</p>
                </div>

                <div style='text-align:center; background:#fff3cd; color:#856404; padding:10px; border-radius:5px; font-size:13px;'>
                    $statusMsg
                </div>

                <p style='font-size:12px; text-align:center; color:#888; margin-top:20px;'>
                    Ajman Water Park<br>See you soon!
                </p>
            </div>
        </div>";

        $mail->Body = $body;
        $mail->AltBody = "You purchased AED $amountFormatted in QR Points.";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("sendQRWalletReceipt Error: " . $e->getMessage());
        return false;
    }
}
?>