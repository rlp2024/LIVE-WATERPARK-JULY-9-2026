<?php
session_start();
include_once 'db_connect.php';

// 1. SECURITY CHECK
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: admin_login.php");
    exit;
}

$search = $_GET['search'] ?? '';

// --- LOGIC 1: PENDING PAYMENTS ---
if ($search) {
    $sql_pending = "SELECT * FROM bookings WHERE payment_status = 'pending' AND booking_id = ? ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql_pending);
    $stmt->execute([$search]);
} else {
    $sql_pending = "SELECT * FROM bookings WHERE payment_status = 'pending' ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql_pending);
    $stmt->execute();
}
$pending_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);


// --- LOGIC 2: TRANSACTION HISTORY ---
$sql_history = "SELECT * FROM bookings WHERE payment_status = 'paid' ORDER BY paid_at DESC LIMIT 20";
$stmt_hist = $pdo->prepare($sql_history);
$stmt_hist->execute();
$history_bookings = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);

if ($search && empty($pending_bookings)) {
    $sql_history_search = "SELECT * FROM bookings WHERE payment_status = 'paid' AND booking_id = ?";
    $stmt_hist = $pdo->prepare($sql_history_search);
    $stmt_hist->execute([$search]);
    $history_bookings = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cashier Terminal | Ajman Water Park</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #003B72;
            --secondary: #002855;
            --accent: #28a745;
            --bg-light: #f4f6f9;
            --white: #ffffff;
            --text-dark: #333;
            --text-light: #666;
            --shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
        }

        body { font-family: 'Poppins', sans-serif; background: var(--bg-light); margin: 0; color: var(--text-dark); padding-bottom: 50px; }
        
        /* HEADER */
        .header { 
            background: linear-gradient(135deg, var(--primary), var(--secondary)); 
            color: var(--white); 
            padding: 15px 40px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header-title { display: flex; align-items: center; gap: 12px; }
        .header-title h2 { margin: 0; font-size: 1.4rem; font-weight: 600; }
        .cashier-badge { background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; }

        .nav-actions { display: flex; gap: 10px; }
        .nav-btn { 
            color: var(--white); text-decoration: none; font-weight: 500; font-size: 0.9rem;
            background: rgba(255,255,255,0.15); padding: 10px 20px; border-radius: 8px; 
            transition: all 0.3s ease; display: flex; align-items: center; gap: 8px;
        }
        .nav-btn:hover { background: rgba(255,255,255,0.3); transform: translateY(-2px); }
        .btn-logout { background: rgba(220, 53, 69, 0.8); }
        .btn-logout:hover { background: #c82333; }

        /* CONTAINER */
        .container { max-width: 1100px; margin: 40px auto; padding: 0 20px; }

        /* SEARCH BAR */
        .search-wrapper { 
            background: var(--white); padding: 10px; border-radius: 12px; 
            box-shadow: var(--shadow); margin-bottom: 40px; border: 1px solid #eee;
        }
        .search-form { display: flex; gap: 10px; }
        .search-input { 
            flex: 1; padding: 15px 20px; border: 1px solid #e0e0e0; border-radius: 8px; 
            font-size: 1.1rem; font-family: inherit; outline: none; transition: 0.3s;
        }
        .search-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(0, 59, 114, 0.1); }
        .btn-search { 
            padding: 0 30px; background: var(--primary); color: white; border: none; 
            border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.3s; 
            font-size: 1rem;
        }
        .btn-search:hover { background: var(--secondary); }
        .btn-clear { text-decoration: none; color: #666; display: flex; align-items: center; padding: 0 15px; font-weight: 500; }

        /* SECTION TITLES */
        .section-header { 
            display: flex; align-items: center; gap: 10px; margin-bottom: 20px; 
            padding-bottom: 10px; border-bottom: 2px solid #e0e0e0;
        }
        .section-header h3 { margin: 0; font-size: 1.2rem; color: #444; }
        .section-header i { color: var(--primary); }

        /* PENDING CARDS GRID */
        .pending-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            margin-bottom: 50px;
        }

        .txn-card { 
            background: var(--white); border-radius: 12px; box-shadow: var(--shadow); 
            overflow: hidden; border: 1px solid #f0f0f0; transition: transform 0.3s, box-shadow 0.3s;
            display: flex; flex-direction: column; justify-content: space-between;
        }
        .txn-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        
        .card-header { padding: 15px 20px; background: #fff8e1; border-bottom: 1px solid #ffeeba; display: flex; justify-content: space-between; align-items: center; }
        .order-id { font-weight: 800; font-size: 1.1rem; color: #856404; letter-spacing: 1px; }
        .card-body { padding: 20px; }
        .info-row { margin-bottom: 8px; font-size: 0.95rem; display: flex; justify-content: space-between; }
        .info-label { color: #888; font-size: 0.85rem; }
        
        .card-footer { 
            padding: 15px 20px; background: #fafafa; border-top: 1px solid #eee; 
            display: flex; justify-content: space-between; align-items: center; 
        }
        .amount-display { font-size: 1.4rem; font-weight: 700; color: var(--primary); }
        
        .btn-accept { 
            background: var(--accent); color: white; border: none; padding: 10px 20px; 
            border-radius: 50px; cursor: pointer; font-weight: 600; font-size: 0.9rem;
            display: flex; align-items: center; gap: 8px; transition: 0.2s; box-shadow: 0 4px 6px rgba(40, 167, 69, 0.2);
        }
        .btn-accept:hover { background: #218838; transform: scale(1.05); }

        /* HISTORY TABLE */
        .table-container { background: var(--white); border-radius: 12px; box-shadow: var(--shadow); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8f9fa; color: #555; font-weight: 600; text-transform: uppercase; font-size: 0.8rem; padding: 15px 20px; text-align: left; }
        td { padding: 15px 20px; border-bottom: 1px solid #eee; font-size: 0.95rem; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover { background-color: #fcfcfc; }

        /* BADGES */
        .badge { padding: 6px 12px; border-radius: 30px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; display: inline-flex; align-items: center; gap: 5px; letter-spacing: 0.5px; }
        .badge-cash { background: #e6f9ed; color: #155724; border: 1px solid #c3e6cb; }
        .badge-card { background: #e7f1ff; color: #004085; border: 1px solid #b8daff; }
        .badge-paypal { background: #e0e7ff; color: #003087; border: 1px solid #99c2ff; }
        .badge-wallet { background: #f8f9fa; color: #383d41; border: 1px solid #d6d8db; }

        .btn-reprint { 
            color: #6c757d; background: #e2e6ea; width: 35px; height: 35px; 
            border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; 
            text-decoration: none; transition: 0.2s; 
        }
        .btn-reprint:hover { background: var(--primary); color: white; }

        .empty-state { text-align: center; padding: 50px; color: #999; background: white; border-radius: 12px; border: 2px dashed #eee; }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .header { padding: 15px 20px; flex-direction: column; gap: 15px; }
            .search-form { flex-direction: column; }
            .pending-grid { grid-template-columns: 1fr; }
            table { display: block; overflow-x: auto; }
        }
    </style>
</head>
<body>

    <div class="header">
        <div class="header-title">
            <i class="fas fa-cash-register fa-2x"></i>
            <div>
                <h2>Cashier Terminal</h2>
                <span class="cashier-badge">
                    <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['admin_fullname']); ?>
                </span>
            </div>
        </div>
        <div class="nav-actions">
            <a href="admin_verify.php" class="nav-btn"><i class="fas fa-qrcode"></i> Verification Scanner</a>
            <a href="admin_logout.php" class="nav-btn btn-logout"><i class="fas fa-power-off"></i> Logout</a>
        </div>
    </div>

    <div class="container">
        
        <div class="search-wrapper">
            <form class="search-form">
                <input type="number" name="search" class="search-input" placeholder="Scan QR or Enter Order ID..." value="<?php echo htmlspecialchars($search); ?>" autofocus autocomplete="off">
                <button type="submit" class="btn-search"><i class="fas fa-search"></i> Find</button>
                <?php if($search): ?>
                    <a href="cashier_view.php" class="btn-clear"><i class="fas fa-times"></i> Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="section-header">
            <i class="fas fa-clock fa-lg" style="color: #ffc107;"></i>
            <h3>Pending Payments</h3>
        </div>
        
        <?php if (count($pending_bookings) > 0): ?>
            <div class="pending-grid">
                <?php foreach ($pending_bookings as $booking): ?>
                    <div class="txn-card">
                        <div class="card-header">
                            <span class="order-id">#<?php echo str_pad($booking['booking_id'], 6, '0', STR_PAD_LEFT); ?></span>
                            <span class="badge badge-wallet" style="font-size:0.7rem;">
                                <?php echo date("h:i A", strtotime($booking['created_at'])); ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="info-row">
                                <span class="info-label">Customer Name</span>
                                <strong><?php echo htmlspecialchars($booking['customer_name']); ?></strong>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Payment Method</span>
                                <strong style="text-transform:uppercase; color:var(--primary);">
                                    <?php echo htmlspecialchars($booking['payment_method']); ?>
                                </strong>
                            </div>
                        </div>
                        <div class="card-footer">
                            <div class="amount-display">AED <?php echo number_format($booking['total_amount'], 2); ?></div>
                            
                            <form method="POST" action="admin_mark_paid.php">
                                <input type="hidden" name="booking_id" value="<?php echo $booking['booking_id']; ?>">
                                <button type="submit" class="btn-accept" onclick="return confirm('Confirm cash payment received?');">
                                    ACCEPT <i class="fas fa-chevron-right"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-check-circle fa-3x" style="color:#ddd; margin-bottom:15px;"></i><br>
                No pending payments. Ready for the next guest.
            </div>
        <?php endif; ?>


        <div class="section-header" style="margin-top:50px; border-color:#28a745;">
            <i class="fas fa-history fa-lg" style="color: #28a745;"></i>
            <h3>Recent Paid Transactions</h3>
        </div>
        
        <?php if (count($history_bookings) > 0): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th width="10%">ID</th>
                            <th width="25%">Customer</th>
                            <th width="15%">Amount</th>
                            <th width="15%">Time Paid</th>
                            <th width="15%">Method</th>
                            <th width="15%">Cashier</th>
                            <th width="5%" style="text-align:center;">Print</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history_bookings as $hist): ?>
                        <tr>
                            <td style="font-weight:700; color:#555;">#<?php echo str_pad($hist['booking_id'], 6, '0', STR_PAD_LEFT); ?></td>
                            
                            <td style="font-weight:600;"><?php echo htmlspecialchars($hist['customer_name']); ?></td>
                            
                            <td style="font-weight:700; color:var(--primary);">AED <?php echo number_format($hist['total_amount'], 2); ?></td>
                            
                            <td style="color:#777; font-size:0.9rem;">
                                <?php echo $hist['paid_at'] ? date("M d, h:i A", strtotime($hist['paid_at'])) : '-'; ?>
                            </td>
                            
                            <td>
                                <?php 
                                    $pm = $hist['payment_method'];
                                    if ($pm === 'cash') {
                                        echo '<span class="badge badge-cash"><i class="fas fa-money-bill-wave"></i> CASH</span>';
                                    } elseif ($pm === 'paypal') {
                                        echo '<span class="badge badge-paypal"><i class="fab fa-paypal"></i> PAYPAL</span>';
                                    } elseif (in_array($pm, ['apple_pay', 'google_pay'])) {
                                        echo '<span class="badge badge-wallet"><i class="fas fa-wallet"></i> WALLET</span>';
                                    } else {
                                        echo '<span class="badge badge-card"><i class="fas fa-credit-card"></i> CARD</span>';
                                    }
                                ?>
                            </td>

                            <td style="font-size:0.85rem;">
                                <?php if (!empty($hist['cashier_name'])): ?>
                                    <span style="color:#333; font-weight:500;"><?php echo htmlspecialchars($hist['cashier_name']); ?></span>
                                <?php else: ?>
                                    <span style="color:#999; font-style:italic;">Online System</span>
                                <?php endif; ?>
                            </td>

                            <td style="text-align:center;">
                                <a href="print_receipt.php?booking_id=<?php echo $hist['booking_id']; ?>" target="_blank" class="btn-reprint" title="Reprint Receipt">
                                    <i class="fas fa-print"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No payment history found yet.</div>
        <?php endif; ?>

    </div>

</body>
</html>