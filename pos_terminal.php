<?php
session_start();
include_once 'db_connect.php';

$today = date('Y-m-d');

// Fetch General Admission tickets (category_id=1)
$stmtTickets = $pdo->prepare("
    SELECT p.product_id, p.name, p.price, p.image_url, p.available_from, p.available_until
    FROM products p
    WHERE p.category_id = 1 AND p.is_active = 1
    AND (p.available_from IS NULL OR p.available_from <= :today1)
    AND (p.available_until IS NULL OR p.available_until >= :today2)
    ORDER BY p.price ASC
");
$stmtTickets->execute(['today1' => $today, 'today2' => $today]);
$ticketProducts = $stmtTickets->fetchAll();

$stmtTypes   = $pdo->prepare("SELECT type_id, category, sub_label, price FROM ticket_types WHERE product_id = ?");
$stmtBundle  = $pdo->prepare("SELECT config_id, bundle_price FROM product_bundle_configs WHERE main_product_id = ? AND is_active = 1 LIMIT 1");
$stmtBundleTickets = $pdo->prepare("SELECT variant_name, quantity FROM product_bundle_ticket_qtys WHERE config_id = ?");
$stmtBundleAddons  = $pdo->prepare("SELECT addon_product_id FROM product_bundle_addons WHERE config_id = ?");

// Fetch Add-ons (category_id=6)
$stmtAddons = $pdo->prepare("
    SELECT p.product_id, p.name, p.price, p.image_url
    FROM products p
    WHERE p.category_id = 6 AND p.is_active = 1
    AND (p.available_from IS NULL OR p.available_from <= :today1)
    AND (p.available_until IS NULL OR p.available_until >= :today2)
    ORDER BY p.price ASC
");
$stmtAddons->execute(['today1' => $today, 'today2' => $today]);
$addonProducts = $stmtAddons->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Walk-in POS System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { margin: 0; padding: 0; background-color: #f4f6f9; height: 100vh; display: flex; overflow: hidden; }

        .pos-container { display: flex; width: 100%; height: 100%; }

        /* ── LEFT ── */
        .pos-left { flex: 7; display: flex; flex-direction: column; background: #fff; border-right: 1px solid #ddd; position: relative; }

        .pos-header { padding: 15px 20px; background: #003B72; color: #fff; display: flex; justify-content: space-between; align-items: center; }
        .pos-header h2 { margin: 0; font-size: 1.2rem; display: flex; align-items: center; gap: 15px; }
        .pos-search input { padding: 10px 15px; border-radius: 20px; border: none; outline: none; width: 250px; }
        .btn-logout { background: #dc3545; color: #fff; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer; font-weight: bold; font-size: 0.9rem; }
        .btn-logout:hover { background: #c82333; }

        .pos-categories { display: flex; gap: 10px; padding: 15px 20px; background: #f8f9fa; border-bottom: 1px solid #ddd; overflow-x: auto; }
        .cat-btn { padding: 10px 20px; border: 1px solid #003B72; background: #fff; color: #003B72; border-radius: 50px; font-weight: bold; cursor: pointer; white-space: nowrap; transition: 0.2s; }
        .cat-btn.active, .cat-btn:hover { background: #003B72; color: #fff; }
        .cat-btn.topup-btn { background: #17a2b8; color: #fff; border-color: #17a2b8; }
        .cat-btn.existing-booking-btn { background: #ff9800; color: #fff; border-color: #ff9800; }

        .tab-content { display: none; padding: 20px; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; overflow-y: auto; height: calc(100vh - 130px); align-content: start; background: #f0f2f5; }
        .tab-content.active { display: grid; }

        .ticket-group { background: #fff; border: 1px solid #e0e0e0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); transition: transform 0.2s; }
        .ticket-group:hover { border-color: #003B72; box-shadow: 0 6px 12px rgba(0,59,114,0.1); }
        .ticket-group-header { margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #f4f6f9; }
        .ticket-group-title { font-weight: 800; color: #003B72; font-size: 1.2rem; margin-bottom: 5px; display: flex; align-items: center; gap: 10px; }
        .ticket-group-desc { font-size: 0.85rem; color: #666; }

        /* ─ Add-ons-only warning banner ─ */
        .addon-only-notice {
            grid-column: 1 / -1;
            background: #fff3cd; border: 1px solid #ffc107; border-radius: 10px;
            padding: 12px 16px; display: flex; align-items: center; gap: 10px;
            font-size: 0.88rem; color: #856404; font-weight: 600;
        }
        .addon-only-notice i { font-size: 1.1rem; }
        .addon-only-notice a { color: #003B72; text-decoration: underline; cursor: pointer; }

        .variant-row { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px dashed #eee; }
        .variant-row:last-child { border-bottom: none; padding-bottom: 0; }
        .variant-info { display: flex; flex-direction: column; }
        .variant-name { font-weight: 700; color: #333; font-size: 0.95rem; }
        .variant-price { color: #28a745; font-weight: bold; font-size: 0.95rem; margin-top: 3px; }
        .btn-add-item { background: #e3f2fd; color: #003B72; border: 1px solid #003B72; padding: 8px 18px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.2s; }
        .btn-add-item:hover { background: #003B72; color: #fff; transform: translateY(-2px); }

        .split-container { grid-column: 1 / -1; display: flex; gap: 20px; width: 100%; max-width: 900px; margin: 0 auto; }
        .topup-ui, .existing-booking-ui, .register-ui { background: #fff; padding: 30px; border-radius: 12px; border: 1px solid #e0e0e0; flex: 1; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .topup-ui input, .existing-booking-ui input, .register-ui input { width: 100%; padding: 15px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 8px; font-size: 1.1rem; text-align: center; outline: none; }
        .topup-ui input:focus, .register-ui input:focus { border-color: #17a2b8; box-shadow: 0 0 5px rgba(23,162,184,0.3); }
        .topup-ui button { width: 100%; padding: 15px; background: #17a2b8; color: #fff; border: none; border-radius: 8px; font-size: 1.1rem; font-weight: bold; cursor: pointer; }
        .register-ui button { width: 100%; padding: 15px; background: #28a745; color: #fff; border: none; border-radius: 8px; font-size: 1.1rem; font-weight: bold; cursor: pointer; }
        .existing-booking-ui button { width: 100%; padding: 15px; background: #ff9800; color: #fff; border: none; border-radius: 8px; font-size: 1.1rem; font-weight: bold; cursor: pointer; }

        #attached-booking-badge { display: none; background: #ff9800; color: #fff; padding: 5px 15px; border-radius: 20px; font-size: 0.9rem; margin-left: 15px; font-weight: bold; align-items: center; gap: 8px; }
        .btn-unlink { background: rgba(0,0,0,0.2); border: none; color: white; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 0.8rem; }
        .btn-unlink:hover { background: rgba(0,0,0,0.5); }

        /* ── RIGHT: CART ── */
        .pos-right { flex: 3; display: flex; flex-direction: column; background: #fff; border-left: 1px solid #ddd; }
        .cart-header { padding: 20px; background: #f8f9fa; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; }
        .cart-header h3 { margin: 0; color: #333; font-size: 1.1rem; }
        .cart-items { flex: 1; overflow-y: auto; padding: 10px 20px; }
        .cart-item { display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px dashed #ddd; }
        .cart-item-info { flex: 1; }
        .cart-item-title { font-weight: bold; color: #333; font-size: 0.95rem; }
        .cart-item-price { color: #666; font-size: 0.85rem; margin-top: 3px; }
        .cart-qty-ctrl { display: flex; align-items: center; gap: 10px; }
        .qty-btn { background: #eee; border: none; width: 30px; height: 30px; border-radius: 5px; cursor: pointer; font-weight: bold; color: #333; }
        .qty-btn:hover { background: #ddd; }
        .qty-btn.del { background: #ffebee; color: #dc3545; }

        .cart-totals { padding: 20px; background: #f8f9fa; border-top: 2px solid #ddd; }
        .total-row { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 1rem; color: #555; }
        .total-row.grand { font-size: 1.5rem; font-weight: 900; color: #155724; border-top: 1px solid #ccc; padding-top: 10px; margin-top: 5px; }

        .btn-proceed { width: 100%; padding: 16px; background: #ff9800; color: #fff; border: none; border-radius: 10px; font-size: 1.15rem; font-weight: 800; cursor: pointer; margin-top: 15px; transition: 0.2s; letter-spacing: 0.3px; }
        .btn-proceed:hover { background: #e68900; transform: scale(1.01); }
        .btn-proceed:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

        /* ── MODAL SYSTEM ── */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.55); z-index: 1000; justify-content: center; align-items: center; }
        .modal-overlay.show { display: flex; }
        .modal-box { background: #fff; border-radius: 14px; width: 460px; max-height: 90vh; overflow-y: auto; box-shadow: 0 12px 40px rgba(0,0,0,0.25); position: relative; }
        .modal-box-wide { width: 520px; }

        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 18px 24px; border-bottom: 1px solid #eee; }
        .modal-header h3 { margin: 0; font-size: 1.1rem; color: #333; display: flex; align-items: center; gap: 8px; }
        .modal-close { background: none; border: none; font-size: 1.4rem; color: #999; cursor: pointer; padding: 0; line-height: 1; }
        .modal-close:hover { color: #333; }
        .modal-body { padding: 20px 24px; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid #eee; }

        /* ── Guest Details Modal ── */
        .guest-field { margin-bottom: 14px; }
        .guest-field label { display: block; font-size: 0.85rem; font-weight: 600; color: #555; margin-bottom: 5px; }
        .guest-field input { width: 100%; padding: 12px 14px; border: 1px solid #ddd; border-radius: 8px; font-size: 1rem; outline: none; transition: border-color 0.2s; }
        .guest-field input:focus { border-color: #003B72; box-shadow: 0 0 0 3px rgba(0,59,114,0.08); }
        .guest-field input[readonly] { background: #f4f6f9; color: #777; cursor: not-allowed; }

        /* Customer detect feedback */
        .detect-status {
            display: none; margin-top: 6px; padding: 7px 12px; border-radius: 8px;
            font-size: 0.82rem; font-weight: 600; align-items: center; gap: 7px;
        }
        .detect-status.loading { display: flex; background: #e8f4fd; color: #003B72; }
        .detect-status.found   { display: flex; background: #e8f5e9; color: #1b5e20; }
        .detect-status.not-found { display: flex; background: #f5f5f5; color: #777; }
        .detect-status.error   { display: flex; background: #ffebee; color: #b71c1c; }

        /* Review & Pay */
        .review-guest-bar { background: #f0f7ff; border: 1px solid #d0e3f7; border-radius: 10px; padding: 12px 16px; margin-bottom: 18px; display: flex; justify-content: space-between; align-items: center; }
        .review-guest-bar .name { font-weight: 700; color: #003B72; }
        .review-guest-bar .phone { color: #555; font-size: 0.9rem; }

        .order-summary-list { margin-bottom: 18px; }
        .order-summary-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
        .order-summary-item:last-child { border-bottom: none; }
        .os-qty { background: #003B72; color: #fff; font-size: 0.75rem; font-weight: 700; width: 24px; height: 24px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; margin-right: 10px; }
        .os-name { font-weight: 500; color: #333; font-size: 0.88rem; }
        .os-price { font-weight: 700; color: #003B72; font-size: 0.95rem; }
        .review-total { display: flex; justify-content: space-between; padding: 14px 0; font-size: 1.3rem; font-weight: 900; }
        .review-total .label { color: #333; }
        .review-total .amount { color: #ff5722; }

        /* Payment Selection */
        .payment-option { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border: 2px solid #eee; border-radius: 10px; margin-bottom: 10px; cursor: pointer; transition: 0.2s; }
        .payment-option:hover { border-color: #ccc; }
        .payment-option.selected { border-color: #ff9800; background: #fff8f0; }
        .payment-option input[type="radio"] { accent-color: #ff9800; width: 18px; height: 18px; }
        .payment-option .po-info { flex: 1; }
        .payment-option .po-title { font-weight: 700; color: #333; font-size: 0.95rem; }
        .payment-option .po-desc { font-size: 0.8rem; color: #888; margin-top: 2px; }
        .payment-option .po-icons { display: flex; gap: 6px; font-size: 1.2rem; }
        .payment-option.disabled { opacity: 0.4; pointer-events: none; }

        .btn-pay-proceed { width: 100%; padding: 16px; background: #ff9800; color: #fff; border: none; border-radius: 10px; font-size: 1.1rem; font-weight: 800; cursor: pointer; transition: 0.2s; }
        .btn-pay-proceed:hover { background: #e68900; }

        /* Receive Payment */
        .rp-total-box { background: #f0f7ff; border: 2px solid #003B72; border-radius: 12px; padding: 16px; text-align: center; margin-bottom: 20px; }
        .rp-total-label { font-size: 0.9rem; color: #666; margin-bottom: 4px; }
        .rp-total-amount { font-size: 2rem; font-weight: 900; color: #003B72; }
        .rp-field { margin-bottom: 16px; }
        .rp-field label { display: flex; align-items: center; gap: 8px; font-size: 0.9rem; font-weight: 700; color: #333; margin-bottom: 6px; }
        .rp-field input { width: 100%; padding: 14px; border: 1px solid #ddd; border-radius: 8px; font-size: 1.15rem; outline: none; }
        .rp-field input:focus { border-color: #003B72; box-shadow: 0 0 0 3px rgba(0,59,114,0.08); }
        .rp-change-box { background: #e8f5e9; border-radius: 10px; padding: 14px; text-align: center; margin-bottom: 16px; }
        .rp-change-label { font-size: 0.85rem; color: #666; }
        .rp-change-amount { font-size: 1.6rem; font-weight: 900; color: #2e7d32; }
        .btn-confirm-payment { width: 100%; padding: 16px; background: #28a745; color: #fff; border: none; border-radius: 10px; font-size: 1.1rem; font-weight: 800; cursor: pointer; transition: 0.2s; }
        .btn-confirm-payment:hover { background: #218838; }
        .btn-confirm-payment:disabled { opacity: 0.5; cursor: not-allowed; }

        /* Confirm Modal */
        .confirm-icon { text-align: center; margin-bottom: 14px; }
        .confirm-icon i { font-size: 3.5rem; color: #adb5bd; background: #f0f2f5; width: 80px; height: 80px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; }
        .confirm-title { text-align: center; font-size: 1.4rem; font-weight: 800; color: #333; margin-bottom: 6px; }
        .confirm-subtitle { text-align: center; font-size: 0.95rem; color: #888; margin-bottom: 20px; }
        .confirm-method-badge { text-align: center; background: #f0f2f5; padding: 10px 20px; border-radius: 8px; font-weight: 700; color: #333; font-size: 1.05rem; display: inline-block; margin: 0 auto 20px; }
        .confirm-checkbox-box { background: #fffbeb; border: 1px solid #ffd54f; border-radius: 10px; padding: 14px 16px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 12px; }
        .confirm-checkbox-box input[type="checkbox"] { width: 20px; height: 20px; accent-color: #28a745; margin-top: 2px; flex-shrink: 0; }
        .confirm-checkbox-box label { font-size: 0.9rem; color: #555; line-height: 1.4; cursor: pointer; }
        .confirm-btns { display: flex; gap: 12px; }
        .btn-yes-confirm { flex: 1; padding: 14px; background: #28a745; color: #fff; border: none; border-radius: 10px; font-size: 1rem; font-weight: 700; cursor: pointer; }
        .btn-yes-confirm:hover { background: #218838; }
        .btn-yes-confirm:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-cancel-confirm { flex: 0 0 auto; padding: 14px 24px; background: #6c757d; color: #fff; border: none; border-radius: 10px; font-size: 1rem; font-weight: 700; cursor: pointer; }
        .btn-cancel-confirm:hover { background: #5a6268; }

        /* Receipt Modal */
        .receipt-modal-box { width: 500px; max-height: 95vh; }
        .receipt-iframe { width: 100%; height: 550px; border: none; }
        .receipt-actions { display: flex; gap: 10px; padding: 16px 24px; border-top: 1px solid #eee; }
        .btn-print-receipt { flex: 1; padding: 14px; background: #003B72; color: #fff; border: none; border-radius: 8px; font-size: 1rem; font-weight: 700; cursor: pointer; }
        .btn-print-receipt:hover { background: #002a54; }
        .btn-close-receipt { flex: 0 0 auto; padding: 14px 20px; background: #6c757d; color: #fff; border: none; border-radius: 8px; font-size: 1rem; font-weight: 700; cursor: pointer; }

        /* Add-on only confirm modal */
        .addon-warning-box { background: #fff8e1; border: 1px solid #ffca28; border-radius: 10px; padding: 16px; margin-bottom: 18px; font-size: 0.9rem; color: #5d4037; line-height: 1.5; }
        .addon-warning-box strong { color: #e65100; }
    </style>
</head>
<body>

<div class="pos-container">
    <div class="pos-left">
        <div class="pos-header">
            <h2>
                <i class="fas fa-desktop"></i> POS Terminal
                <span id="attached-booking-badge">
                    <i class="fas fa-link"></i> Linked: <span id="lbl-booking-id"></span>
                    <button class="btn-unlink" onclick="unlinkBooking()" title="Remove Link"><i class="fas fa-times"></i></button>
                </span>
            </h2>
            <div style="display: flex; gap: 10px; align-items: center;">
                <div class="pos-search">
                    <input type="text" placeholder="Search products...">
                </div>
                <button class="btn-logout" onclick="hardResetPOS()"><i class="fas fa-sync-alt"></i> Reset POS</button>
            </div>
        </div>

        <div class="pos-categories">
            <button class="cat-btn active" onclick="switchTab('tab-tickets', this)">Tickets & Add-ons</button>
            <button class="cat-btn topup-btn" onclick="switchTab('tab-topup', this)"><i class="fas fa-wallet"></i> Wallet / Top-Up</button>
            <button class="cat-btn existing-booking-btn" onclick="switchTab('tab-existing', this)"><i class="fas fa-user-plus"></i> Link Booking</button>
        </div>

        <div id="tab-tickets" class="tab-content active">

            <!-- ── Dynamic add-on-only warning (rendered by JS) ── -->
            <div id="addon-only-banner" class="addon-only-notice" style="display:none;">
                <i class="fas fa-exclamation-triangle"></i>
                <span>
                    Cart has <strong>add-ons only</strong> — please add a main ticket,
                    or <a onclick="switchTab('tab-existing', document.querySelector('.existing-booking-btn'))">link an existing booking</a> first.
                </span>
            </div>

            <?php foreach ($ticketProducts as $product): ?>
                <?php
                    $pid = $product['product_id'];
                    $stmtBundle->execute([$pid]);
                    $bundleConfig = $stmtBundle->fetch();

                    if ($bundleConfig):
                        $stmtBundleTickets->execute([$bundleConfig['config_id']]);
                        $bundleTickets = $stmtBundleTickets->fetchAll();
                        $stmtBundleAddons->execute([$bundleConfig['config_id']]);
                        $bundleAddons = $stmtBundleAddons->fetchAll();
                        $bundlePrice  = $bundleConfig['bundle_price'];
                        $stmtTypes->execute([$pid]);
                        $famVariants = $stmtTypes->fetchAll();
                ?>
                <div class="ticket-group">
                    <div class="ticket-group-header">
                        <div class="ticket-group-title"><i class="fas fa-users" style="color:#e91e63;"></i> <?= htmlspecialchars($product['name']) ?></div>
                        <div class="ticket-group-desc">
                            <?php foreach ($bundleTickets as $bt): ?>
                                <?= (int)$bt['quantity'] ?>x <?= htmlspecialchars(ucfirst($bt['variant_name'])) ?>
                            <?php endforeach; ?>
                            + <?= count($bundleAddons) ?>x Combo Meals included
                        </div>
                    </div>
                    <div class="variant-row">
                        <div class="variant-info">
                            <span class="variant-name">Family Package (2 Adults + 2 Kids + 4 Meals)</span>
                            <span class="variant-price">AED <?= number_format($bundlePrice, 2) ?></span>
                        </div>
                        <button class="btn-add-item" onclick="addToCart('Family Package', <?= $bundlePrice ?>, 'bundle', '<?= htmlspecialchars($pid) ?>', 0)">+ Add</button>
                    </div>
                    <?php foreach ($famVariants as $fv): ?>
                    <div class="variant-row">
                        <div class="variant-info">
                            <span class="variant-name"><?= htmlspecialchars($fv['category']) ?><?= $fv['sub_label'] ? ' ' . htmlspecialchars($fv['sub_label']) : '' ?></span>
                            <span class="variant-price">AED <?= number_format($fv['price'], 2) ?></span>
                        </div>
                        <button class="btn-add-item" onclick="addToCart('<?= htmlspecialchars($product['name']) ?> - <?= htmlspecialchars($fv['category']) ?><?= $fv['sub_label'] ? ' ' . htmlspecialchars($fv['sub_label']) : '' ?>', <?= $fv['price'] ?>, 'child', '<?= htmlspecialchars($pid) ?>', <?= (int)$fv['type_id'] ?>)">+ Add</button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php else:
                    $stmtTypes->execute([$pid]);
                    $variants = $stmtTypes->fetchAll();
                    if (empty($variants)) continue;

                    $icon      = 'fa-ticket-alt'; $iconColor = '#17a2b8';
                    if (strpos(strtolower($product['name']), 'full day') !== false) { $icon = 'fa-sun'; $iconColor = '#f39c12'; }
                    elseif (strpos(strtolower($product['name']), '3 hour') !== false) { $icon = 'fa-clock'; $iconColor = '#17a2b8'; }
                ?>
                <div class="ticket-group">
                    <div class="ticket-group-header">
                        <div class="ticket-group-title"><i class="fas <?= $icon ?>" style="color:<?= $iconColor ?>;"></i> <?= htmlspecialchars($product['name']) ?></div>
                    </div>
                    <?php foreach ($variants as $v):
                        $variantType = 'adult';
                        $catLower    = strtolower($v['category']);
                        if (strpos($catLower, 'kid') !== false || strpos($catLower, 'child') !== false) $variantType = 'child';
                        if (strpos($catLower, 'infant') !== false) $variantType = 'infant';
                        $displayName = $product['name'] . ' - ' . $v['category'] . (!empty($v['sub_label']) ? ' ' . $v['sub_label'] : '');
                    ?>
                    <div class="variant-row">
                        <div class="variant-info">
                            <span class="variant-name" data-label="<?= htmlspecialchars($v['category'] . (!empty($v['sub_label']) ? ' ' . $v['sub_label'] : '')) ?>"><?= htmlspecialchars($v['category']) ?><?= $v['sub_label'] ? ' <span style="color:#888;font-size:0.8rem;">(' . htmlspecialchars($v['sub_label']) . ')</span>' : '' ?></span>
                            <span class="variant-price">AED <?= number_format($v['price'], 2) ?></span>
                        </div>
                        <button class="btn-add-item" onclick="addToCart('<?= htmlspecialchars($displayName, ENT_QUOTES) ?>', <?= $v['price'] ?>, '<?= $variantType ?>', '<?= htmlspecialchars($pid) ?>', <?= (int)$v['type_id'] ?>)">+ Add</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if (!empty($addonProducts)): ?>
            <div class="ticket-group">
                <div class="ticket-group-header">
                    <div class="ticket-group-title"><i class="fas fa-plus-circle" style="color:#28a745;"></i> Add-ons</div>
                    <div class="ticket-group-desc">Can be added with a main ticket or to an existing booking.</div>
                </div>
                <?php foreach ($addonProducts as $addon): ?>
                <div class="variant-row">
                    <div class="variant-info">
                        <span class="variant-name"><?= htmlspecialchars($addon['name']) ?></span>
                        <span class="variant-price">AED <?= number_format($addon['price'], 2) ?></span>
                    </div>
                    <button class="btn-add-item" onclick="addToCart('<?= htmlspecialchars($addon['name'], ENT_QUOTES) ?>', <?= $addon['price'] ?>, 'addon', '<?= htmlspecialchars($addon['product_id']) ?>', 0)">+ Add</button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div><!-- /tab-tickets -->

        <div id="tab-topup" class="tab-content" style="display:none; place-items: center;">
            <div class="split-container">
                <div class="topup-ui">
                    <h3 style="color:#17a2b8; margin-top:0;"><i class="fas fa-wallet"></i> Top-Up Existing Wallet</h3>
                    <p style="color:#666; font-size:0.9rem; margin-bottom:20px;">Scan QR or enter Wallet ID to load funds.</p>
                    <input type="text" id="topup-qr" placeholder="Scan QR or Wallet ID (e.g. W-1024)">
                    <input type="number" id="topup-amount" placeholder="Amount to Load (AED)">
                    <button onclick="addTopupToCart()"><i class="fas fa-cart-plus"></i> Add Top-up to Cart</button>
                </div>
                <div class="register-ui">
                    <h3 style="color:#28a745; margin-top:0;"><i class="fas fa-user-plus"></i> Register New Wallet</h3>
                    <p style="color:#666; font-size:0.9rem; margin-bottom:20px;">Create an account to get a new QR Wallet.</p>
                    <input type="text" id="reg-name" placeholder="Full Name">
                    <input type="text" id="reg-phone" placeholder="Phone Number">
                    <input type="email" id="reg-email" placeholder="Email Address (Optional)">
                    <button onclick="registerNewWallet()"><i class="fas fa-qrcode"></i> Register & Generate ID</button>
                </div>
            </div>
        </div>

        <div id="tab-existing" class="tab-content" style="display:none; place-items: center;">
            <div class="existing-booking-ui" style="max-width: 500px;">
                <h3 style="color:#ff9800; margin-top:0;"><i class="fas fa-link"></i> Add to Existing Booking</h3>
                <p style="color:#666; margin-bottom:20px;">Scan Guest QR or Enter Booking ID to attach new tickets/items to their group.</p>
                <input type="text" id="existing-booking-id" placeholder="Enter Booking ID (e.g. 10052)">
                <button onclick="attachToBooking()"><i class="fas fa-search"></i> Find & Link Booking</button>
            </div>
        </div>
    </div>

    <!-- ── RIGHT: CART ── -->
    <div class="pos-right">
        <div class="cart-header">
            <h3><i class="fas fa-shopping-cart"></i> Current Order</h3>
            <button style="border:none; background:none; color:#dc3545; cursor:pointer; font-weight:bold;" onclick="clearCart()"><i class="fas fa-trash"></i> Clear</button>
        </div>

        <div class="cart-items" id="cart-list">
            <div style="text-align: center; color: #999; margin-top: 50px;">Cart is empty. Select items to begin.</div>
        </div>

        <div class="cart-totals">
            <div class="total-row"><span>Subtotal</span> <span id="subtotal">AED 0.00</span></div>
            <div class="total-row"><span>VAT (5%)</span> <span id="vat">AED 0.00</span></div>
            <div class="total-row grand"><span>Total</span> <span id="grand-total">AED 0.00</span></div>
            <button class="btn-proceed" id="btn-proceed" onclick="openGuestDetailsModal()" disabled>
                <i class="fas fa-arrow-right"></i> PROCEED TO PAYMENT &mdash; AED 0.00
            </button>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════════
     MODAL 1 · GUEST DETAILS
════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-guest">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-user-edit" style="color:#003B72;"></i> Guest Details</h3>
            <button class="modal-close" onclick="closeModal('modal-guest')">&times;</button>
        </div>
        <div class="modal-body">

            <!-- PHONE -->
            <div class="guest-field">
                <label><i class="fas fa-phone"></i> Phone Number <span style="color:#dc3545;">*</span></label>
                <input type="text" id="g-phone" placeholder="e.g. +971 50 123 4567" oninput="detectCustomer()">
                <div class="detect-status" id="detect-status">
                    <i class="fas fa-spinner fa-spin" id="detect-icon"></i>
                    <span id="detect-msg">Searching...</span>
                </div>
            </div>

            <!-- FIRST NAME -->
            <div class="guest-field">
                <label><i class="fas fa-user"></i> First Name <span style="color:#dc3545;">*</span></label>
                <input type="text" id="g-fname" placeholder="First Name">
            </div>

            <!-- LAST NAME -->
            <div class="guest-field">
                <label><i class="fas fa-user"></i> Last Name <span style="color:#dc3545;">*</span></label>
                <input type="text" id="g-lname" placeholder="Last Name">
            </div>

            <!-- EMAIL -->
            <div class="guest-field">
                <label><i class="fas fa-envelope"></i> Email Address <span style="color:#999; font-weight:400;">(optional)</span></label>
                <input type="email" id="g-email" placeholder="Email">
            </div>

        </div>
        <div class="modal-footer">
            <button class="btn-pay-proceed" onclick="proceedToReview()">
                <i class="fas fa-arrow-right"></i> Continue to Review
            </button>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════════
     MODAL 1B · ADD-ON ONLY WARNING
════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-addon-warn">
    <div class="modal-box" style="width:400px;">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color:#ff9800;"></i> Add-ons Only</h3>
            <button class="modal-close" onclick="closeModal('modal-addon-warn')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="addon-warning-box">
                The cart contains <strong>add-ons only</strong> with no main ticket and no linked booking.<br><br>
                Add-ons must be attached to either:
                <ul style="margin:8px 0 0 0; padding-left:20px;">
                    <li>A <strong>main ticket</strong> in this transaction, <em>or</em></li>
                    <li>An <strong>existing booking</strong> (Link Booking tab)</li>
                </ul>
            </div>
            <p style="font-size:0.88rem; color:#555;">Do you still want to proceed?</p>
        </div>
        <div class="modal-footer" style="display:flex; gap:10px;">
            <button class="btn-pay-proceed" style="flex:1;" onclick="forceOpenGuestModal()">
                <i class="fas fa-arrow-right"></i> Yes, Proceed Anyway
            </button>
            <button onclick="closeModal('modal-addon-warn'); switchTab('tab-existing', document.querySelector('.existing-booking-btn'));"
                    style="flex:1; padding:16px; background:#ff9800; color:#fff; border:none; border-radius:10px; font-size:1rem; font-weight:700; cursor:pointer;">
                <i class="fas fa-link"></i> Link Booking
            </button>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════════
     MODAL 2 · REVIEW & PAY
════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-review">
    <div class="modal-box modal-box-wide">
        <div class="modal-header">
            <h3><i class="fas fa-arrow-left" style="cursor:pointer; color:#888;" onclick="backToGuestDetails()"></i> Review & Pay</h3>
            <button class="modal-close" onclick="closeModal('modal-review')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="review-guest-bar">
                <div><span style="font-size:0.8rem; color:#888;">Name:</span> <span class="name" id="review-name">Guest</span></div>
                <div><i class="fas fa-phone" style="color:#888; margin-right:4px;"></i><span class="phone" id="review-phone">—</span></div>
            </div>

            <h4 style="margin:0 0 10px; color:#333; font-size:1rem;">Order Summary</h4>
            <div class="order-summary-list" id="review-items"></div>
            <div class="review-total">
                <span class="label">Total</span>
                <span class="amount" id="review-total">AED 0.00</span>
            </div>

            <h4 style="margin:0 0 12px; color:#333; font-size:1rem;">Select Payment</h4>
            <div class="payment-option selected" id="po-cashcard" onclick="selectPaymentOption('cashcard')">
                <input type="radio" name="pay-method" value="cashcard" checked>
                <div class="po-info">
                    <div class="po-title">Receive Cash / Card</div>
                    <div class="po-desc">Cashier accepts cash, card, or both</div>
                </div>
                <div class="po-icons">
                    <i class="fas fa-money-bill-wave" style="color:#28a745;"></i>
                    <i class="fas fa-credit-card" style="color:#ff9800;"></i>
                </div>
            </div>
            <div class="payment-option" id="po-wallet" onclick="selectPaymentOption('wallet')">
                <input type="radio" name="pay-method" value="wallet">
                <div class="po-info">
                    <div class="po-title">QR Card Wallet</div>
                    <div class="po-desc">Deduct from guest wallet balance</div>
                </div>
                <div class="po-icons"><i class="fas fa-qrcode" style="color:#6f42c1;"></i></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-pay-proceed" id="btn-review-pay" onclick="proceedToPayment()">
                <i class="fas fa-lock"></i> PAY AED <span id="review-pay-amount">0.00</span>
            </button>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════════
     MODAL 3A · RECEIVE PAYMENT (Cash/Card)
════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-receive">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-cash-register" style="color:#28a745;"></i> Receive Payment</h3>
            <button class="modal-close" onclick="closeModal('modal-receive')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="rp-total-box">
                <div class="rp-total-label">Total Amount to Pay:</div>
                <div class="rp-total-amount" id="rp-total">AED 0.00</div>
            </div>
            <div class="rp-field">
                <label><i class="fas fa-money-bill-wave" style="color:#28a745;"></i> Cash Amount (AED):</label>
                <input type="number" id="rp-cash" placeholder="Click Here" 
                    style="background:#f0f0f0; color:#aaa; border:1px solid #ccc; cursor:pointer;"
                    readonly
                    onclick="enableRpCash(this)"
                    oninput="onCashChange()" step="0.01" min="0">
            </div>
            <div class="rp-field">
                <label><i class="fas fa-credit-card" style="color:#ff9800;"></i> Card Amount (AED):</label>
                <input type="number" id="rp-card" placeholder="0.00" oninput="recalcReceivePayment()" step="0.01" min="0">
            </div>
            <div class="rp-change-box" id="rp-change-box">
                <div class="rp-change-label">Change:</div>
                <div class="rp-change-amount" id="rp-change">AED 0.00</div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-confirm-payment" id="btn-rp-confirm" onclick="openConfirmModal()" disabled>CONFIRM PAYMENT</button>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════════
     MODAL 3B · WALLET PAYMENT
════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-wallet">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-qrcode" style="color:#6f42c1;"></i> Pay via Wallet</h3>
            <button class="modal-close" onclick="closeModal('modal-wallet')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="rp-total-box" style="border-color:#6f42c1;">
                <div class="rp-total-label">Total Amount to Deduct:</div>
                <div class="rp-total-amount" id="wallet-total" style="color:#6f42c1;">AED 0.00</div>
            </div>
            <div class="rp-field">
                <label><i class="fas fa-qrcode" style="color:#6f42c1;"></i> Scan Customer QR / Enter Wallet ID:</label>
                <input type="text" id="wallet-scan-id" placeholder="Scan QR or enter Wallet ID" style="border:2px dashed #6f42c1; text-align:center;">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-confirm-payment" style="background:#6f42c1;" onclick="openConfirmModalWallet()">DEDUCT & CONFIRM</button>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════════
     MODAL 4 · CONFIRM PAYMENT
════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-confirm">
    <div class="modal-box">
        <div class="modal-body" style="text-align:center; padding:30px 24px;">
            <div class="confirm-icon"><i class="fas fa-question-circle"></i></div>
            <div class="confirm-title">Confirm Payment?</div>
            <div class="confirm-subtitle">Process this payment?</div>
            <div style="margin-bottom:20px;">
                <span class="confirm-method-badge" id="confirm-method-badge">Cash: AED 0.00</span>
            </div>
            <div class="confirm-checkbox-box">
                <input type="checkbox" id="confirm-check" onchange="toggleConfirmBtn()">
                <label for="confirm-check" id="confirm-check-label">The card terminal showed <strong>APPROVED</strong> before I clicked Confirm.</label>
            </div>
            <div class="confirm-btns">
                <button class="btn-yes-confirm" id="btn-yes-confirm" onclick="finalizePayment()" disabled><i class="fas fa-check"></i> Yes, Confirm</button>
                <button class="btn-cancel-confirm" onclick="closeModal('modal-confirm')">Cancel</button>
            </div>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════════
     MODAL 5 · RECEIPT
════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-receipt">
    <div class="modal-box receipt-modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-receipt" style="color:#003B72;"></i> Payment Successful</h3>
            <span></span>
        </div>
        <iframe id="receipt-iframe" class="receipt-iframe" src="about:blank"></iframe>
        <div class="receipt-actions">
            <button class="btn-print-receipt" onclick="printReceipt()"><i class="fas fa-print"></i> Print Receipt</button>
            <button class="btn-close-receipt" onclick="closeReceiptAndReset()"><i class="fas fa-check"></i> Done</button>
        </div>
    </div>
</div>


<script>
// ═══════════════════════════════════════════════════════
//  STATE
// ═══════════════════════════════════════════════════════
let cart                = {};
let attachedBookingId   = null;
let currentTotal        = 0;
let selectedPayMethod   = 'cashcard';
let pendingPaymentData  = {};
let detectTimer         = null;

// ═══════════════════════════════════════════════════════
//  INIT FROM LOCALSTORAGE
// ═══════════════════════════════════════════════════════
window.onload = function () {
    if (localStorage.getItem('pos_cart'))    cart              = JSON.parse(localStorage.getItem('pos_cart'));
    if (localStorage.getItem('pos_booking_id')) {
        attachedBookingId = localStorage.getItem('pos_booking_id');
        restoreLinkedUI(attachedBookingId);
    }
    if (localStorage.getItem('pos_guest')) {
        const g = JSON.parse(localStorage.getItem('pos_guest'));
        if (g.phone) document.getElementById('g-phone').value = g.phone;
        if (g.fname) document.getElementById('g-fname').value = g.fname;
        if (g.lname) document.getElementById('g-lname').value = g.lname;
        if (g.email) document.getElementById('g-email').value = g.email;
    }
    renderCart();

    const pendingReceipt = localStorage.getItem('pos_last_receipt');
    if (pendingReceipt) showReceiptModal(pendingReceipt);
};

// Persist guest fields on every keystroke
function saveGuestToStorage() {
    localStorage.setItem('pos_guest', JSON.stringify({
        phone: document.getElementById('g-phone').value,
        fname: document.getElementById('g-fname').value,
        lname: document.getElementById('g-lname').value,
        email: document.getElementById('g-email').value,
    }));
}
document.querySelectorAll('#modal-guest input').forEach(i => i.addEventListener('input', saveGuestToStorage));

// ═══════════════════════════════════════════════════════
//  LINKED BOOKING UI
// ═══════════════════════════════════════════════════════
function restoreLinkedUI(bId) {
    document.getElementById('attached-booking-badge').style.display = 'flex';
    document.getElementById('lbl-booking-id').innerText = bId;
}

function unlinkBooking() {
    if (!confirm('Remove the linked booking? Cart items will remain.')) return;
    attachedBookingId = null;
    localStorage.removeItem('pos_booking_id');
    document.getElementById('attached-booking-badge').style.display = 'none';
    ['g-fname','g-lname','g-email','g-phone'].forEach(id => {
        const el = document.getElementById(id);
        el.value = ''; el.readOnly = false;
    });
    localStorage.removeItem('pos_guest');
}

// ═══════════════════════════════════════════════════════
//  GLOBAL BARCODE SCANNER
// ═══════════════════════════════════════════════════════
let barcode = '', scanInterval;
document.addEventListener('keydown', function (evt) {
    if (evt.target.tagName === 'INPUT' || evt.target.tagName === 'TEXTAREA') return;
    if (evt.key !== 'Enter') {
        barcode += evt.key;
        clearInterval(scanInterval);
        scanInterval = setInterval(() => { barcode = ''; }, 100);
    } else {
        if (barcode.length > 3) handleGlobalScan(barcode);
        barcode = '';
    }
});

function handleGlobalScan(scannedCode) {
    if (document.getElementById('modal-wallet').classList.contains('show')) {
        document.getElementById('wallet-scan-id').value = scannedCode;
        return;
    }
    if (scannedCode.toUpperCase().startsWith('W-')) {
        switchTab('tab-topup', document.querySelector('.topup-btn'));
        document.getElementById('topup-qr').value = scannedCode.toUpperCase();
        document.getElementById('topup-amount').focus();
    } else {
        switchTab('tab-existing', document.querySelector('.existing-booking-btn'));
        document.getElementById('existing-booking-id').value = scannedCode;
        attachToBooking();
    }
}

// ═══════════════════════════════════════════════════════
//  TAB SWITCHING
// ═══════════════════════════════════════════════════════
function switchTab(tabId, btnElement) {
    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.cat-btn').forEach(el => el.classList.remove('active'));
    const el = document.getElementById(tabId);
    el.style.display = (tabId === 'tab-topup' || tabId === 'tab-existing') ? 'flex' : 'grid';
    if (btnElement) btnElement.classList.add('active');
}

// ═══════════════════════════════════════════════════════
//  CART MANAGEMENT
// ═══════════════════════════════════════════════════════
function addToCart(name, price, type, productId, typeId) {
    // ── REMOVED: adult-before-child restriction ──
    // Kids/infant tickets can now be added freely.

    if (cart[name]) cart[name].qty++;
    else cart[name] = { price, qty: 1, type, product_id: productId || '', type_id: typeId || 0 };
    renderCart();
}

function addTopupToCart() {
    const qr  = document.getElementById('topup-qr').value.trim();
    const amt = parseFloat(document.getElementById('topup-amount').value);
    if (!qr || isNaN(amt) || amt <= 0) return alert('Please enter a valid QR/Wallet ID and Amount.');
    addToCart('Top-Up: ' + qr, amt, 'topup', '', 0);
    document.getElementById('topup-qr').value    = '';
    document.getElementById('topup-amount').value = '';
}

function registerNewWallet() {
    const name  = document.getElementById('reg-name').value;
    const phone = document.getElementById('reg-phone').value;
    if (!name || !phone) return alert('Name and Phone are required to register a wallet.');
    const newId = 'W-' + Math.floor(Math.random() * 90000 + 10000);
    alert(`Successfully created wallet!\n\nGuest: ${name}\nWallet ID: ${newId}\n\nYou can now top-up this wallet.`);
    document.getElementById('topup-qr').value = newId;
    document.getElementById('reg-name').value  = '';
    document.getElementById('reg-phone').value = '';
    document.getElementById('reg-email').value = '';
    document.getElementById('topup-amount').focus();
}

function attachToBooking() {
    const bId = document.getElementById('existing-booking-id').value.trim();
    if (!bId) return;
    attachedBookingId = bId;
    localStorage.setItem('pos_booking_id', bId);
    restoreLinkedUI(bId);
    // Auto-switch back to tickets tab so cashier can now add items
    switchTab('tab-tickets', document.querySelector('.cat-btn'));
    renderCart();
}

function changeQty(name, amount) {
    if (!cart[name]) return;
    cart[name].qty += amount;
    if (cart[name].qty <= 0) delete cart[name];
    renderCart();
}

function clearCart() {
    if (!confirm('Clear the cart?')) return;
    cart = {}; attachedBookingId = null;
    localStorage.removeItem('pos_cart');
    localStorage.removeItem('pos_booking_id');
    localStorage.removeItem('pos_guest');
    document.getElementById('attached-booking-badge').style.display = 'none';
    ['g-fname','g-lname','g-email','g-phone'].forEach(id => {
        const el = document.getElementById(id); el.value = ''; el.readOnly = false;
    });
    renderCart();
}

function renderCart() {
    const list = document.getElementById('cart-list');
    list.innerHTML = '';
    localStorage.setItem('pos_cart', JSON.stringify(cart));

    const itemCount   = Object.keys(cart).length;
    let   hasTopup    = false;
    let   hasMain     = false;   // adult / child / infant / bundle
    let   hasAddon    = false;

    if (itemCount === 0) {
        list.innerHTML = '<div style="text-align:center;color:#999;margin-top:50px;"><i class="fas fa-shopping-cart" style="font-size:2rem;display:block;margin-bottom:10px;"></i>Cart is empty.</div>';
        updateTotalsUI(0);
        // Hide warning banner
        document.getElementById('addon-only-banner').style.display = 'none';
        return;
    }

    let rawSubtotal = 0;
    for (const [name, item] of Object.entries(cart)) {
        if (item.type === 'topup') hasTopup = true;
        if (['adult','child','infant','bundle'].includes(item.type)) hasMain = true;
        if (item.type === 'addon') hasAddon = true;

        const itemTotal = item.price * item.qty;
        rawSubtotal += itemTotal;

        list.innerHTML += `
            <div class="cart-item">
                <div class="cart-item-info">
                    <div class="cart-item-title">${name}</div>
                    <div class="cart-item-price">AED ${item.price.toFixed(2)} × ${item.qty} = <strong>AED ${itemTotal.toFixed(2)}</strong></div>
                </div>
                <div class="cart-qty-ctrl">
                    <button class="qty-btn del" onclick="changeQty('${name.replace(/'/g,"\\'")}', -1)">-</button>
                    <span>${item.qty}</span>
                    <button class="qty-btn" onclick="changeQty('${name.replace(/'/g,"\\'")}', 1)">+</button>
                </div>
            </div>`;
    }

    // ── Addon-only warning banner ──
    const banner = document.getElementById('addon-only-banner');
    if (hasAddon && !hasMain && !attachedBookingId) {
        banner.style.display = 'flex';
    } else {
        banner.style.display = 'none';
    }

    // ── Disable wallet if cart has top-up ──
    if (hasTopup) {
        document.getElementById('po-wallet').classList.add('disabled');
        if (selectedPayMethod === 'wallet') selectPaymentOption('cashcard');
    } else {
        document.getElementById('po-wallet').classList.remove('disabled');
    }

    const vat      = rawSubtotal * 0.05;
    const grand    = rawSubtotal + vat;
    currentTotal   = grand;
    updateTotalsUI(grand);
}

function updateTotalsUI(grand) {
    const rawSub = grand / 1.05;
    const rawVat = grand - rawSub;

    if (grand === 0) {
        document.getElementById('subtotal').innerText   = 'AED 0.00';
        document.getElementById('vat').innerText        = 'AED 0.00';
        document.getElementById('grand-total').innerText = 'AED 0.00';
        document.getElementById('btn-proceed').disabled  = true;
        document.getElementById('btn-proceed').innerHTML = '<i class="fas fa-arrow-right"></i> PROCEED TO PAYMENT — AED 0.00';
    } else {
        let sub = 0;
        for (const item of Object.values(cart)) sub += item.price * item.qty;
        let vat = sub * 0.05, tot = sub + vat;

        document.getElementById('subtotal').innerText    = 'AED ' + sub.toFixed(2);
        document.getElementById('vat').innerText         = 'AED ' + vat.toFixed(2);
        document.getElementById('grand-total').innerText = 'AED ' + tot.toFixed(2);
        document.getElementById('btn-proceed').disabled  = false;
        document.getElementById('btn-proceed').innerHTML = '<i class="fas fa-arrow-right"></i> PROCEED TO PAYMENT — AED ' + tot.toFixed(2);
    }
}

// ═══════════════════════════════════════════════════════
//  MODAL HELPERS
// ═══════════════════════════════════════════════════════
function openModal(id)  { document.getElementById(id).classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); }

// ═══════════════════════════════════════════════════════
//  STEP 1 · GUEST DETAILS
// ═══════════════════════════════════════════════════════
function openGuestDetailsModal() {
    if (Object.keys(cart).length === 0) return alert('Cart is empty!');

    // If linked booking → skip guest details
    if (attachedBookingId) {
        document.getElementById('g-fname').value = 'Linked Guest';
        document.getElementById('g-lname').value = '(Booking #' + attachedBookingId + ')';
        document.getElementById('g-phone').value = 'N/A';
        proceedToReview();
        return;
    }

    // ── Add-on only check ──
    const hasMain  = Object.values(cart).some(i => ['adult','child','infant','bundle'].includes(i.type));
    const hasAddon = Object.values(cart).some(i => i.type === 'addon');
    if (hasAddon && !hasMain && !attachedBookingId) {
        openModal('modal-addon-warn');
        return;
    }

    openModal('modal-guest');
    document.getElementById('g-phone').focus();
}

// "Yes, proceed anyway" button in the add-on warning modal
function forceOpenGuestModal() {
    closeModal('modal-addon-warn');
    openModal('modal-guest');
    document.getElementById('g-phone').focus();
}

// ── CUSTOMER AUTO-DETECT ──
function detectCustomer() {
    const phone   = document.getElementById('g-phone').value.trim();
    const statusEl = document.getElementById('detect-status');
    const iconEl   = document.getElementById('detect-icon');
    const msgEl    = document.getElementById('detect-msg');

    if (detectTimer) clearTimeout(detectTimer);

    // Hide badge if phone too short
    if (phone.length < 7) {
        statusEl.className = 'detect-status';  // hidden
        return;
    }

    // Show loading
    iconEl.className = 'fas fa-spinner fa-spin';
    msgEl.textContent = 'Searching customer...';
    statusEl.className = 'detect-status loading';

    detectTimer = setTimeout(() => {
        // Use the same endpoint as details.php (api_check_customer.php)
        fetch('api_check_customer.php?phone=' + encodeURIComponent(phone))
            .then(r => r.json())
            .then(data => {
                if (data.success && data.name) {
                    // Populate fields
                    const parts  = data.name.trim().split(' ');
                    const fname  = parts[0] || '';
                    const lname  = parts.slice(1).join(' ') || '';

                    document.getElementById('g-fname').value = fname;
                    document.getElementById('g-lname').value = lname;
                    if (data.email) document.getElementById('g-email').value = data.email;

                    // Green flash on fields
                    ['g-fname','g-lname','g-email'].forEach(id => {
                        const el = document.getElementById(id);
                        el.style.background = '#e8f5e9';
                        setTimeout(() => { el.style.background = ''; }, 1200);
                    });

                    iconEl.className  = 'fas fa-check-circle';
                    msgEl.textContent = 'Returning customer — details auto-filled.';
                    statusEl.className = 'detect-status found';
                    saveGuestToStorage();

                } else {
                    iconEl.className  = 'fas fa-info-circle';
                    msgEl.textContent = 'No record found — new guest.';
                    statusEl.className = 'detect-status not-found';
                    // Auto-hide after 2.5 s
                    setTimeout(() => { statusEl.className = 'detect-status'; }, 2500);
                }
            })
            .catch(() => {
                iconEl.className  = 'fas fa-exclamation-triangle';
                msgEl.textContent = 'Could not reach customer lookup.';
                statusEl.className = 'detect-status error';
                setTimeout(() => { statusEl.className = 'detect-status'; }, 2500);
            });
    }, 500);
}

// ═══════════════════════════════════════════════════════
//  STEP 2 · REVIEW & PAY
// ═══════════════════════════════════════════════════════
function proceedToReview() {
    const phone = document.getElementById('g-phone').value.trim();
    const fname = document.getElementById('g-fname').value.trim();
    const lname = document.getElementById('g-lname').value.trim();

    if (!attachedBookingId && (!phone || !fname || !lname)) {
        return alert('Please fill in Phone, First Name, and Last Name.');
    }

    closeModal('modal-guest');
    saveGuestToStorage();

    document.getElementById('review-name').innerText  = fname + ' ' + lname;
    document.getElementById('review-phone').innerText = phone;

    let html = '';
    for (const [name, item] of Object.entries(cart)) {
        const tot = item.price * item.qty;
        html += `
            <div class="order-summary-item">
                <div style="display:flex;align-items:center;">
                    <span class="os-qty">${item.qty}x</span>
                    <span class="os-name">${name}</span>
                </div>
                <span class="os-price">AED ${tot.toFixed(2)}</span>
            </div>`;
    }
    document.getElementById('review-items').innerHTML = html;
    document.getElementById('review-total').innerText     = 'AED ' + currentTotal.toFixed(2);
    document.getElementById('review-pay-amount').innerText = currentTotal.toFixed(2);
    openModal('modal-review');
}

function backToGuestDetails() {
    closeModal('modal-review');
    if (!attachedBookingId) openModal('modal-guest');
}

function selectPaymentOption(method) {
    selectedPayMethod = method;
    document.querySelectorAll('.payment-option').forEach(el => el.classList.remove('selected'));
    document.querySelectorAll('.payment-option input[type="radio"]').forEach(el => el.checked = false);
    if (method === 'cashcard') {
        document.getElementById('po-cashcard').classList.add('selected');
        document.getElementById('po-cashcard').querySelector('input').checked = true;
    } else {
        document.getElementById('po-wallet').classList.add('selected');
        document.getElementById('po-wallet').querySelector('input').checked = true;
    }
}

// ═══════════════════════════════════════════════════════
//  STEP 3 · PAYMENT
// ═══════════════════════════════════════════════════════
function enableRpCash(el) {
    el.readOnly = false;
    el.style.background = '#fff';
    el.style.color = '#000';
    el.style.border = '1px solid #ddd';
    el.style.cursor = 'text';
    el.value = '';
    el.focus();
    onCashChange();
}

function proceedToPayment() {
    closeModal('modal-review');
    if (selectedPayMethod === 'cashcard') {
        document.getElementById('rp-total').innerText = 'AED ' + currentTotal.toFixed(2);

        // Reset cash — grayed out at readonly muna
        const cashInput = document.getElementById('rp-cash');
        cashInput.value = '';
        cashInput.readOnly = true;
        cashInput.placeholder = 'Click Here';
        cashInput.style.background = '#f0f0f0';
        cashInput.style.color = '#aaa';
        cashInput.style.border = '1px solid #ccc';
        cashInput.style.cursor = 'pointer';

        // Card defaults to full amount
        document.getElementById('rp-card').value = currentTotal.toFixed(2);
        recalcReceivePayment();
        openModal('modal-receive');
    } else {
        document.getElementById('wallet-total').innerText = 'AED ' + currentTotal.toFixed(2);
        document.getElementById('wallet-scan-id').value   = '';
        openModal('modal-wallet');
        document.getElementById('wallet-scan-id').focus();
    }
}

function onCashChange() {
    const cash      = parseFloat(document.getElementById('rp-cash').value) || 0;
    const remainder = currentTotal - cash;
    document.getElementById('rp-card').value = remainder > 0 ? remainder.toFixed(2) : '0.00';
    recalcReceivePayment();
}

function recalcReceivePayment() {
    const cash     = parseFloat(document.getElementById('rp-cash').value) || 0;
    const card     = parseFloat(document.getElementById('rp-card').value) || 0;
    const tendered = cash + card;
    const change   = tendered - currentTotal;
    const btn      = document.getElementById('btn-rp-confirm');

    if (tendered >= currentTotal) {
        document.getElementById('rp-change').innerText         = 'AED ' + Math.max(0, change).toFixed(2);
        document.getElementById('rp-change-box').style.background = '#e8f5e9';
        document.getElementById('rp-change').style.color          = '#2e7d32';
        btn.disabled = false;
    } else {
        const short = (currentTotal - tendered).toFixed(2);
        document.getElementById('rp-change').innerText         = 'AED -' + short + ' (Short)';
        document.getElementById('rp-change-box').style.background = '#ffebee';
        document.getElementById('rp-change').style.color          = '#c62828';
        btn.disabled = true;
    }
}

// ═══════════════════════════════════════════════════════
//  STEP 4 · CONFIRM
// ═══════════════════════════════════════════════════════
function openConfirmModal() {
    const cash = parseFloat(document.getElementById('rp-cash').value) || 0;
    const card = parseFloat(document.getElementById('rp-card').value) || 0;

    let methodLabel, checkLabel;
    if (cash > 0 && card > 0) {
        methodLabel = 'Cash: AED ' + cash.toFixed(2) + ' | Card: AED ' + card.toFixed(2);
        checkLabel  = 'The card terminal showed <strong>APPROVED</strong> before I clicked Confirm.';
    } else if (card > 0) {
        methodLabel = 'Card: AED ' + card.toFixed(2);
        checkLabel  = 'The card terminal showed <strong>APPROVED</strong> before I clicked Confirm.';
    } else {
        methodLabel = 'Cash: AED ' + cash.toFixed(2);
        checkLabel  = 'I have verified the cash amount is correct.';
    }

    pendingPaymentData = {
        method: (cash > 0 && card > 0)
            ? 'Cash: ' + cash.toFixed(2) + ' | Card: ' + card.toFixed(2)
            : (card > 0 ? 'Card' : 'Cash'),
        cashAmount: cash, cardAmount: card,
        change: Math.max(0, (cash + card) - currentTotal),
    };

    document.getElementById('confirm-method-badge').innerText    = methodLabel;
    document.getElementById('confirm-check-label').innerHTML     = checkLabel;
    document.getElementById('confirm-check').checked             = false;
    document.getElementById('btn-yes-confirm').disabled          = true;

    closeModal('modal-receive');
    openModal('modal-confirm');
}

function openConfirmModalWallet() {
    const walletId = document.getElementById('wallet-scan-id').value.trim();
    if (!walletId) return alert('Please scan or enter a Wallet ID.');

    pendingPaymentData = { method: 'Wallet QR', walletId, cashAmount: 0, cardAmount: 0, change: 0 };
    document.getElementById('confirm-method-badge').innerText = 'Wallet: ' + walletId + ' — AED ' + currentTotal.toFixed(2);
    document.getElementById('confirm-check-label').innerHTML  = 'I have verified the wallet ID and balance is sufficient.';
    document.getElementById('confirm-check').checked          = false;
    document.getElementById('btn-yes-confirm').disabled       = true;

    closeModal('modal-wallet');
    openModal('modal-confirm');
}

function toggleConfirmBtn() {
    document.getElementById('btn-yes-confirm').disabled = !document.getElementById('confirm-check').checked;
}

// ═══════════════════════════════════════════════════════
//  STEP 5 · FINALIZE & RECEIPT
// ═══════════════════════════════════════════════════════
function finalizePayment() {
    closeModal('modal-confirm');

    let items = [];
    for (const [name, item] of Object.entries(cart)) {
        items.push({ name, product_id: item.product_id || '', type_id: item.type_id || 0, type: item.type, qty: item.qty, price: item.price });
    }

    let subtotal = 0;
    for (const item of Object.values(cart)) subtotal += item.price * item.qty;
    const vatAmount   = subtotal * 0.05;
    const totalAmount = subtotal + vatAmount;

    const payload = {
        customer_name:  (document.getElementById('g-fname').value + ' ' + document.getElementById('g-lname').value).trim(),
        customer_email: document.getElementById('g-email').value || 'pos@ajmanwaterpark.com',
        customer_phone: document.getElementById('g-phone').value || '',
        payment_method: pendingPaymentData.method,
        total_amount:   totalAmount,
        vat_amount:     vatAmount,
        items,
        linked_booking_id: attachedBookingId,
        visit_date: new Date().toISOString().split('T')[0],
    };

    fetch('pos_save_booking.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            localStorage.setItem('pos_last_receipt', data.booking_id);
            cart = {}; attachedBookingId = null;
            localStorage.removeItem('pos_cart');
            localStorage.removeItem('pos_booking_id');
            localStorage.removeItem('pos_guest');
            showReceiptModal(data.booking_id);
        } else {
            alert('Error saving booking: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => alert('Network error: ' + err.message));
}

function showReceiptModal(bookingId) {
    const iframe = document.getElementById('receipt-iframe');
    iframe.onload = function () {
        try { setTimeout(() => { iframe.contentWindow.focus(); iframe.contentWindow.print(); }, 600); }
        catch (e) {}
    };
    iframe.src = 'print_receipt.php?booking_id=' + bookingId + '&embed=1';
    openModal('modal-receipt');
}

function printReceipt() {
    const iframe = document.getElementById('receipt-iframe');
    if (iframe.contentWindow) { iframe.contentWindow.focus(); iframe.contentWindow.print(); }
}

function closeReceiptAndReset() {
    closeModal('modal-receipt');
    const iframe = document.getElementById('receipt-iframe');
    iframe.src = 'about:blank'; iframe.onload = null;
    localStorage.removeItem('pos_last_receipt');
    document.getElementById('attached-booking-badge').style.display = 'none';
    ['g-fname','g-lname','g-email','g-phone'].forEach(id => {
        const el = document.getElementById(id); el.value = ''; el.readOnly = false;
    });
    document.getElementById('detect-status').className = 'detect-status';
    renderCart();
}

function hardResetPOS() {
    cart = {}; attachedBookingId = null;
    localStorage.removeItem('pos_cart');
    localStorage.removeItem('pos_booking_id');
    localStorage.removeItem('pos_guest');
    localStorage.removeItem('pos_last_receipt');
    location.reload();
}
</script>
</body>
</html>