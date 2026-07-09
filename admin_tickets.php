<?php
session_start();
include_once 'db_connect.php';

// Simple admin check (adjust to your existing auth)
// if (!isset($_SESSION['admin_logged_in'])) { header('Location: admin_login.php'); exit; }

$message = '';
$error = '';

/* ==========================================================================
   HANDLE POST ACTIONS
========================================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    // --- UPDATE ALL PRODUCTS (BATCH SAVE) ---
    if ($action === 'update_all_products') {
        $products_data = $_POST['products'] ?? [];
        $updated_count = 0;

        try {
            $pdo->beginTransaction();

            // May pangalan (kapag inedit) at walang-pangalan (para hindi mabura kung blangko)
            $stmtName = $pdo->prepare("UPDATE products SET name = ?, price = ?, available_from = ?, available_until = ?, is_active = ? WHERE product_id = ?");
            $stmt     = $pdo->prepare("UPDATE products SET price = ?, available_from = ?, available_until = ?, is_active = ? WHERE product_id = ?");

            foreach ($products_data as $pid => $data) {
                $name      = trim($data['name'] ?? '');
                $price     = (float)($data['price'] ?? 0);
                $from      = !empty($data['available_from'])  ? $data['available_from']  : null;
                $until     = !empty($data['available_until']) ? $data['available_until'] : null;
                $is_active = (int)($data['is_active'] ?? 0);   // hidden=0 + checkbox=1 trick

                // LIGTAS: pinapalitan LANG ang NAME (display). Hindi ginagalaw ang
                // product_id (ang tunay na susi na gamit ng cart / gate / booking),
                // kaya walang masisira kahit palitan ang pangalan.
                if ($name !== '') {
                    $stmtName->execute([$name, $price, $from, $until, $is_active, $pid]);
                } else {
                    $stmt->execute([$price, $from, $until, $is_active, $pid]);   // iwas mabura ang pangalan
                }
                $updated_count++;
            }

            $pdo->commit();
            $message = "✓ All products saved successfully! ($updated_count products updated)";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Save failed: " . $e->getMessage();
        }
    }

    // --- DELETE TICKET TYPE (priority check before batch update) ---
    $deleted_type_id = null;
    if (!empty($_POST['delete_type_id'])) {
        $deleted_type_id = (int)$_POST['delete_type_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM ticket_types WHERE type_id = ?");
            $stmt->execute([$deleted_type_id]);
            $message = "Ticket type #$deleted_type_id deleted!";
        } catch (Exception $e) {
            $error = "Delete failed: " . $e->getMessage();
        }
    }

    // --- UPDATE ALL TICKET TYPES (BATCH SAVE) ---
    if ($action === 'update_all_ticket_types') {
        $tt_data = $_POST['tickets'] ?? [];
        $updated_count = 0;

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE ticket_types SET category = ?, sub_label = ?, price = ?, day_type = ? WHERE type_id = ?");

            foreach ($tt_data as $tid => $data) {
                $tid = (int)$tid;
                if ($deleted_type_id !== null && $tid === $deleted_type_id) continue;

                $category  = trim($data['category'] ?? '');
                $sub_label = trim($data['sub_label'] ?? '') ?: null;
                $price     = (float)($data['price'] ?? 0);
                $day_type  = trim($data['day_type'] ?? 'all');

                if ($category === '') continue;

                $stmt->execute([$category, $sub_label, $price, $day_type, $tid]);
                $updated_count++;
            }

            $pdo->commit();
            $msg_part = "✓ Saved $updated_count ticket type(s)!";
            $message  = $message ? "$message $msg_part" : $msg_part;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Save failed: " . $e->getMessage();
        }
    }

    // --- ADD NEW TICKET TYPE ---
    if ($action === 'add_ticket_type') {
        $pid = $_POST['product_id'];
        $category = trim($_POST['category']);
        $sub_label = trim($_POST['sub_label'] ?? '') ?: null;
        $price = (float)$_POST['price'];
        $day_type = trim($_POST['day_type'] ?? 'all');

        $stmt = $pdo->prepare("INSERT INTO ticket_types (product_id, category, sub_label, price, day_type) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$pid, $category, $sub_label, $price, $day_type]);
        $message = "New ticket type '$category' ($day_type) added to product '$pid'!";
    }

    // --- ADD TICKET TYPE PAIR (Weekday + Weekend at once) ---
    if ($action === 'add_ticket_type_pair') {
        $pid = $_POST['product_id'];
        $category = trim($_POST['category']);
        $sub_label = trim($_POST['sub_label'] ?? '') ?: null;
        $price_weekday = (float)$_POST['price_weekday'];
        $price_weekend = (float)$_POST['price_weekend'];

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO ticket_types (product_id, category, sub_label, price, day_type) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$pid, $category, $sub_label, $price_weekday, 'weekday']);
            $stmt->execute([$pid, $category, $sub_label, $price_weekend, 'weekend']);
            $pdo->commit();
            $message = "Added '$category' — Weekday: AED $price_weekday / Weekend: AED $price_weekend!";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Add pair failed: " . $e->getMessage();
        }
    }

    // --- UPDATE BUNDLE CONFIG ---
    if ($action === 'update_bundle') {
        $config_id = (int)$_POST['config_id'];
        $bundle_price = (float)$_POST['bundle_price'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        $stmt = $pdo->prepare("UPDATE product_bundle_configs SET bundle_price = ?, is_active = ? WHERE config_id = ?");
        $stmt->execute([$bundle_price, $is_active, $config_id]);
        $message = "Bundle config #$config_id updated!";
    }

    // --- ADD NEW PRODUCT ---
    if ($action === 'add_product') {
        $pid = trim($_POST['product_id']);
        $name = trim($_POST['name']);
        $price = (float)$_POST['price'];
        $image_url = trim($_POST['image_url']);
        $category_id = (int)$_POST['category_id'];
        $from = !empty($_POST['available_from']) ? $_POST['available_from'] : null;
        $until = !empty($_POST['available_until']) ? $_POST['available_until'] : null;

        $stmt = $pdo->prepare("INSERT INTO products (product_id, name, price, image_url, category_id, is_active, available_from, available_until) VALUES (?, ?, ?, ?, ?, 1, ?, ?)");
        $stmt->execute([$pid, $name, $price, $image_url, $category_id, $from, $until]);
        $message = "Product '$name' ($pid) created!";
    }
}

/* ==========================================================================
   FETCH DATA
========================================================================== */

// All products with category
$products = $pdo->query("
    SELECT p.*, pc.name as cat_name 
    FROM products p 
    LEFT JOIN product_categories pc ON p.category_id = pc.category_id 
    ORDER BY p.category_id, p.product_id
")->fetchAll(PDO::FETCH_ASSOC);

// All ticket types
$ticket_types = $pdo->query("
    SELECT tt.*, p.name as product_name 
    FROM ticket_types tt 
    JOIN products p ON tt.product_id = p.product_id 
    ORDER BY tt.product_id, tt.price DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Bundle configs
$bundles = $pdo->query("
    SELECT bc.*, p.name as product_name 
    FROM product_bundle_configs bc 
    JOIN products p ON bc.main_product_id = p.product_id
")->fetchAll(PDO::FETCH_ASSOC);

// Bundle ticket qtys
$bundle_qtys = [];
$stmt_bq = $pdo->query("SELECT * FROM product_bundle_ticket_qtys ORDER BY config_id");
while ($row = $stmt_bq->fetch(PDO::FETCH_ASSOC)) {
    $bundle_qtys[$row['config_id']][] = $row;
}

// Bundle addons (kasama ang product names)
$bundle_addons = [];
$stmt_ba = $pdo->query("
    SELECT ba.*, p.name AS addon_name 
    FROM product_bundle_addons ba 
    LEFT JOIN products p ON ba.addon_product_id = p.product_id 
    ORDER BY ba.config_id
");
while ($row = $stmt_ba->fetch(PDO::FETCH_ASSOC)) {
    $bundle_addons[$row['config_id']][] = $row;
}

// Categories
$categories = $pdo->query("SELECT * FROM product_categories ORDER BY category_id")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Ticket Management</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #003B72;
            --accent: #008CBA;
            --success: #16a34a;
            --danger: #dc2626;
            --warning: #d97706;
            --bg: #f4f6f9;
            --card: #ffffff;
            --border: #e2e8f0;
            --text: #1e293b;
            --muted: #64748b;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            padding: 20px;
            line-height: 1.5;
        }

        .container { max-width: 1200px; margin: 0 auto; }

        h1 {
            color: var(--primary);
            margin-bottom: 10px;
            font-size: 1.8rem;
        }

        .subtitle {
            color: var(--muted);
            margin-bottom: 30px;
            font-size: 0.9rem;
        }

        .alert {
            padding: 12px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .alert-success { background: #ecfdf5; color: var(--success); border: 1px solid #bbf7d0; }
        .alert-error { background: #fef2f2; color: var(--danger); border: 1px solid #fecaca; }

        .tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 25px;
            border-bottom: 2px solid var(--border);
            padding-bottom: 0;
        }

        .tab-btn {
            padding: 12px 24px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-weight: 700;
            color: var(--muted);
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            transition: 0.2s;
            font-size: 0.9rem;
        }

        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .tab-btn:hover { color: var(--primary); }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .card {
            background: var(--card);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
            border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }

        .card h3 {
            color: var(--primary);
            margin-bottom: 15px;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 700;
        }

        .badge-active { background: #ecfdf5; color: var(--success); }
        .badge-inactive { background: #fef2f2; color: var(--danger); }
        .badge-info { background: #eff6ff; color: #1d4ed8; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        th {
            text-align: left;
            padding: 10px 12px;
            background: #f8fafc;
            color: var(--muted);
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border);
        }

        td {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        tr:hover td { background: #fafbfc; }

        input[type="text"],
        input[type="number"],
        input[type="date"],
        select {
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.85rem;
            width: 100%;
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(0,140,186,0.1);
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 700;
            font-size: 0.8rem;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: #002d5a; }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #15803d; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-sm { padding: 5px 10px; font-size: 0.75rem; }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--muted);
            margin-bottom: 5px;
            text-transform: uppercase;
        }

        .date-range {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
        }

        .date-range span { color: var(--muted); font-weight: 600; }

        .inline-form {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        .status-dot.active { background: var(--success); }
        .status-dot.inactive { background: var(--danger); }

        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
            .tabs { overflow-x: auto; }
            .tab-btn { white-space: nowrap; padding: 10px 16px; }
        }
    </style>
</head>
<body>
<div class="container">

    <h1><i class="fas fa-ticket-alt"></i> Ticket Management</h1>
    <p class="subtitle">Manage products, ticket types, pricing, date availability, and bundle configurations.</p>

    <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- TABS -->
    <div class="tabs">
        <button class="tab-btn active" data-tab="products"><i class="fas fa-box"></i> Products</button>
        <button class="tab-btn" data-tab="ticket-types"><i class="fas fa-tags"></i> Ticket Types</button>
        <button class="tab-btn" data-tab="bundles"><i class="fas fa-gift"></i> Bundles</button>
        <button class="tab-btn" data-tab="add-new"><i class="fas fa-plus-circle"></i> Add New</button>
    </div>


    <!-- ============================================================
     TAB 1: PRODUCTS (SINGLE FORM, BATCH SAVE)
============================================================ -->
<div class="tab-content active" id="tab-products">
    <form method="POST">
        <input type="hidden" name="action" value="update_all_products">

        <div class="card">
            <h3 style="display:flex; justify-content:space-between; align-items:center;">
                <span><i class="fas fa-box"></i> All Products & Date Availability</span>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i> Save All Changes
                </button>
            </h3>

            <table>
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Category</th>
                        <th>Base Price</th>
                        <th>Available From</th>
                        <th>Available Until</th>
                        <th>Active</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($products as $p): 
                    $pid = htmlspecialchars($p['product_id']); ?>
                    <tr>
                        <td>
                            <span class="status-dot <?= $p['is_active'] ? 'active' : 'inactive' ?>" id="dot-<?= $pid ?>"></span>
                        </td>
                        <td><strong><?= $pid ?></strong></td>
                        <td>
                            <input type="text"
                                   name="products[<?= $pid ?>][name]"
                                   value="<?= htmlspecialchars($p['name']) ?>"
                                   style="min-width:170px;">
                        </td>
                        <td><span class="badge badge-info"><?= htmlspecialchars($p['cat_name'] ?? 'N/A') ?></span></td>

                        <td>
                            <input type="number" 
                                   name="products[<?= $pid ?>][price]" 
                                   value="<?= $p['price'] ?>" 
                                   step="0.01" style="width:90px;">
                        </td>
                        <td>
                            <input type="date" 
                                   name="products[<?= $pid ?>][available_from]" 
                                   value="<?= $p['available_from'] ?? '' ?>" 
                                   style="width:140px;">
                        </td>
                        <td>
                            <input type="date" 
                                   name="products[<?= $pid ?>][available_until]" 
                                   value="<?= $p['available_until'] ?? '' ?>" 
                                   style="width:140px;">
                        </td>
                        <td>
                            <?php /* TRICK: hidden=0 + checkbox=1.
                                 Pag unchecked: hidden=0 lang ang masu-submit.
                                 Pag checked: checkbox=1 ang mag-o-override (HTTP last-wins). */ ?>
                            <input type="hidden" name="products[<?= $pid ?>][is_active]" value="0">
                            <input type="checkbox" 
                                   name="products[<?= $pid ?>][is_active]" 
                                   value="1" 
                                   <?= $p['is_active'] ? 'checked' : '' ?>
                                   onchange="document.getElementById('dot-<?= $pid ?>').className='status-dot '+(this.checked?'active':'inactive')">
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:15px;">
                <p style="font-size:0.78rem; color:var(--muted); margin:0;">
                    <i class="fas fa-info-circle"></i> Leave "Available From/Until" empty = available on ALL dates. 
                    <strong>Click "Save All Changes" to commit all edits at once.</strong>
                </p>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i> Save All Changes
                </button>
            </div>
        </div>
    </form>
</div>

    <!-- ============================================================
         TAB 2: TICKET TYPES
    ============================================================ -->
    <div class="tab-content" id="tab-ticket-types">
<form method="POST" id="ticket-types-form">
    <input type="hidden" name="action" value="update_all_ticket_types">

    <div class="card">
        <h3 style="display:flex; justify-content:space-between; align-items:center;">
            <span><i class="fas fa-tags"></i> Ticket Type Variations (Pricing per Category)</span>
            <button type="submit" class="btn btn-success">
                <i class="fas fa-save"></i> Save All Changes
            </button>
        </h3>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Sub Label</th>
                    <th>Day Type</th>
                    <th>Price (AED)</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ticket_types as $tt): 
                $tid = (int)$tt['type_id']; ?>
                <tr id="tt-row-<?= $tid ?>">
                    <td>#<?= $tid ?></td>
                    <td><?= htmlspecialchars($tt['product_name']) ?></td>
                    <td>
                        <input type="text" 
                               name="tickets[<?= $tid ?>][category]" 
                               value="<?= htmlspecialchars($tt['category']) ?>" 
                               style="width:130px;">
                    </td>
                    <td>
                        <input type="text" 
                               name="tickets[<?= $tid ?>][sub_label]" 
                               value="<?= htmlspecialchars($tt['sub_label'] ?? '') ?>" 
                               placeholder="e.g. with swim" 
                               style="width:130px;">
                    </td>
                    <td>
                        <select name="tickets[<?= $tid ?>][day_type]" style="width:115px;">
                            <option value="all" <?= ($tt['day_type'] ?? 'all') === 'all' ? 'selected' : '' ?>>All Days</option>
                            <option value="weekday" <?= ($tt['day_type'] ?? '') === 'weekday' ? 'selected' : '' ?>>Weekday</option>
                            <option value="weekend" <?= ($tt['day_type'] ?? '') === 'weekend' ? 'selected' : '' ?>>Weekend</option>
                        </select>
                    </td>
                    <td>
                        <input type="number" 
                               name="tickets[<?= $tid ?>][price]" 
                               value="<?= $tt['price'] ?>" 
                               step="0.01" 
                               style="width:90px;">
                    </td>
                    <td>
                        <button type="submit" 
                                name="delete_type_id" 
                                value="<?= $tid ?>" 
                                class="btn btn-danger btn-sm" 
                                onclick="return confirm('Delete ticket type #<?= $tid ?> (<?= htmlspecialchars($tt['category']) ?>)?');">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:15px;">
            <p style="font-size:0.78rem; color:var(--muted); margin:0;">
                <i class="fas fa-info-circle"></i> 
                <strong>Display name = Category + Sub Label.</strong> 
                E.g. Category="Kids" + Sub Label="w/ Meal" = "Kids w/ Meal". 
                <strong>Click "Save All Changes" to commit edits.</strong>
            </p>
            <button type="submit" class="btn btn-success">
                <i class="fas fa-save"></i> Save All Changes
            </button>
        </div>
    </div>
</form>


        <!-- Add New Ticket Type -->
        <div class="card">
            <h3><i class="fas fa-plus"></i> Add New Ticket Type</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add_ticket_type">
                <div class="form-row">
                    <div class="form-group">
                        <label>Product</label>
                        <select name="product_id" required>
                            <?php foreach ($products as $p): ?>
                                <?php if ($p['category_id'] == 1): ?>
                                    <option value="<?= $p['product_id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= $p['product_id'] ?>)</option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <input type="text" name="category" placeholder="e.g. Kids, Adult" required>
                    </div>
                    <div class="form-group">
                        <label>Sub Label (optional)</label>
                        <input type="text" name="sub_label" placeholder="e.g. with swim">
                    </div>
                    <div class="form-group">
                        <label>Day Type</label>
                        <select name="day_type" required>
                            <option value="all">All Days</option>
                            <option value="weekday">Weekday (Mon-Thu)</option>
                            <option value="weekend">Weekend (Fri-Sun)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Price (AED)</label>
                        <input type="number" name="price" step="0.01" placeholder="65.00" required>
                    </div>
                    <div class="form-group" style="display:flex; align-items:flex-end;">
                        <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Add Type</button>
                    </div>
                </div>
            </form>

            <!-- QUICK ADD: Weekend + Weekday pair -->
            <div style="margin-top:20px; padding:15px; background:#eff6ff; border-radius:8px; border:1px solid #bfdbfe;">
                <h4 style="font-size:0.9rem; color:#1d4ed8; margin-bottom:10px;"><i class="fas fa-bolt"></i> Quick Add: Weekday + Weekend Pair</h4>
                <p style="font-size:0.78rem; color:var(--muted); margin-bottom:12px;">
                    Creates TWO entries at once — one Weekday (Mon-Thu) and one Weekend (Fri-Sun) with different prices.
                </p>
                <form method="POST">
                    <input type="hidden" name="action" value="add_ticket_type_pair">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Product</label>
                            <select name="product_id" required>
                                <?php foreach ($products as $p): ?>
                                    <?php if ($p['category_id'] == 1): ?>
                                        <option value="<?= $p['product_id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= $p['product_id'] ?>)</option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Category</label>
                            <input type="text" name="category" placeholder="e.g. Kids, Adult" required>
                        </div>
                        <div class="form-group">
                            <label>Sub Label (optional)</label>
                            <input type="text" name="sub_label" placeholder="e.g. with swim">
                        </div>
                        <div class="form-group">
                            <label>Weekday Price (Mon-Thu)</label>
                            <input type="number" name="price_weekday" step="0.01" placeholder="65.00" required>
                        </div>
                        <div class="form-group">
                            <label>Weekend Price (Fri-Sun)</label>
                            <input type="number" name="price_weekend" step="0.01" placeholder="75.00" required>
                        </div>
                        <div class="form-group" style="display:flex; align-items:flex-end;">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Add Pair</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ============================================================
         TAB 3: BUNDLES
    ============================================================ -->
    <div class="tab-content" id="tab-bundles">
        <?php foreach ($bundles as $b): ?>
            <div class="card">
                <h3>
                    <i class="fas fa-gift"></i> 
                    <?= htmlspecialchars($b['product_name']) ?> Bundle
                    <span class="badge <?= $b['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                        <?= $b['is_active'] ? 'ACTIVE' : 'INACTIVE' ?>
                    </span>
                </h3>

                <form method="POST">
                    <input type="hidden" name="action" value="update_bundle">
                    <input type="hidden" name="config_id" value="<?= $b['config_id'] ?>">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Bundle Price (AED)</label>
                            <input type="number" name="bundle_price" value="<?= $b['bundle_price'] ?>" step="0.01">
                        </div>
                        <div class="form-group">
                            <label>Active</label>
                            <select name="is_active">
                                <option value="1" <?= $b['is_active'] ? 'selected' : '' ?>>Yes - Active</option>
                                <option value="0" <?= !$b['is_active'] ? 'selected' : '' ?>>No - Disabled</option>
                            </select>
                        </div>
                        <div class="form-group" style="display:flex; align-items:flex-end;">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Bundle</button>
                        </div>
                    </div>
                </form>

                <!-- Show included tickets -->
                <?php if (!empty($bundle_qtys[$b['config_id']])): ?>
                    <div style="margin-top:15px; padding:12px; background:#f8fafc; border-radius:8px; border:1px solid var(--border);">
                        <strong style="font-size:0.8rem; color:var(--muted);">INCLUDED TICKETS:</strong>
                        <ul style="margin:8px 0 0 20px; font-size:0.85rem;">
                            <?php foreach ($bundle_qtys[$b['config_id']] as $bq): ?>
                                <li><?= $bq['quantity'] ?>x <?= htmlspecialchars($bq['variant_name']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Show included addons -->
                <?php if (!empty($bundle_addons[$b['config_id']])): ?>
                    <div style="margin-top:10px; padding:12px; background:#f0fdf4; border-radius:8px; border:1px solid #bbf7d0;">
                        <strong style="font-size:0.8rem; color:var(--success);">INCLUDED ADD-ONS:</strong>
                        <ul style="margin:8px 0 0 20px; font-size:0.85rem;">
                            <?php 
                            $addon_count = [];
                            $addon_names = [];
                            foreach ($bundle_addons[$b['config_id']] as $ba) {
                                $aid = $ba['addon_product_id'];
                                $addon_count[$aid] = ($addon_count[$aid] ?? 0) + 1;
                                $addon_names[$aid] = $ba['addon_name'] ?? $aid;
                            }
                            foreach ($addon_count as $aid => $cnt): ?>
                                <li><?= $cnt ?>x <?= htmlspecialchars($addon_names[$aid]) ?> <span style="color:var(--muted); font-size:0.75rem;">(<?= htmlspecialchars($aid) ?>)</span></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if (empty($bundles)): ?>
            <div class="card">
                <p style="color:var(--muted); text-align:center; padding:30px;">
                    <i class="fas fa-info-circle"></i> No bundle configurations found. Create one using the database or add bundle feature here.
                </p>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============================================================
         TAB 4: ADD NEW PRODUCT
    ============================================================ -->
    <div class="tab-content" id="tab-add-new">
        <div class="card">
            <h3><i class="fas fa-plus-circle"></i> Add New Product</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add_product">
                <div class="form-row">
                    <div class="form-group">
                        <label>Product ID</label>
                        <input type="text" name="product_id" placeholder="e.g. STU-PKG" required maxlength="10">
                    </div>
                    <div class="form-group">
                        <label>Product Name</label>
                        <input type="text" name="name" placeholder="e.g. Student Happy Time" required>
                    </div>
                    <div class="form-group">
                        <label>Base Price (AED)</label>
                        <input type="number" name="price" step="0.01" placeholder="89.00" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category_id" required>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Image URL</label>
                        <input type="text" name="image_url" placeholder="Images/packages/..." value="Images/packages/PACKAGE-1.webp">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Available From (leave empty = always)</label>
                        <input type="date" name="available_from">
                    </div>
                    <div class="form-group">
                        <label>Available Until (leave empty = always)</label>
                        <input type="date" name="available_until">
                    </div>
                    <div class="form-group" style="display:flex; align-items:flex-end;">
                        <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Create Product</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Quick Reference -->
        <div class="card">
            <h3><i class="fas fa-lightbulb"></i> Quick Reference</h3>
            <table>
                <thead><tr><th>Scenario</th><th>How To Set Up</th></tr></thead>
                <tbody>
                    <tr>
                        <td><strong>3 Hours Pass (May 21-26 only)</strong></td>
                        <td>Set <code>available_until = 2026-05-26</code> on DP-RES</td>
                    </tr>
                    <tr>
                        <td><strong>Family Package (May 27-30 only)</strong></td>
                        <td>Set <code>available_from = 2026-05-27</code> and <code>available_until = 2026-05-30</code> on FAM-PKG</td>
                    </tr>
                    <tr>
                        <td><strong>Full Day Pass (all dates)</strong></td>
                        <td>Leave both date fields empty on DP-FULL</td>
                    </tr>
                    <tr>
                        <td><strong>Kids w/ Meal ticket</strong></td>
                        <td>Go to Ticket Types tab → Add type: Category="Kids", Sub Label="w/ Meal", Price=93</td>
                    </tr>
                    <tr>
                        <td><strong>Auto-Meal Voucher (ADD6)</strong></td>
                        <td>Anumang ticket type na may "meal" o "w/" sa Sub Label ay automatic mag-generate ng Meal Voucher QR sa email</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
    // Tab switching
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            this.classList.add('active');
            document.getElementById('tab-' + this.dataset.tab).classList.add('active');
        });
    });
</script>
</body>
</html>