<?php
session_start();
include_once 'db_connect.php';

// Set Timezone
date_default_timezone_set('Asia/Dubai');

// Security Check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

$message = "";
$messageType = "";
$admin_who_scanned = $_SESSION['admin_fullname'];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['booking_id'])) {
    $input = trim($_POST['booking_id']);

    if (preg_match('/^\d+\-ADD\d+$/i', $input)) {
        $message = "ADD-ON QR DETECTED. NOT VALID IN EXIT SCANNER.";
        $messageType = "error";
    } else {
        // Hahanapin yung ticket code
        $stmtTix = $pdo->prepare("SELECT * FROM ticket_instances WHERE ticket_code = ?");
        $stmtTix->execute([$input]);
        $ticket = $stmtTix->fetch(PDO::FETCH_ASSOC);

        if ($ticket) {
            if ($ticket['is_used'] == 0) {
                // Hindi pa nakakapasok, hindi pwede i-exit
                $message = "⚠️ CANNOT EXIT: Ticket has NO ENTRY record yet.";
                $messageType = "error";
            } elseif ($ticket['exited_at'] !== null) {
                // Nakalabas na dati
                $message = "⛔ ALREADY EXITED at " . date('h:i A', strtotime($ticket['exited_at'])) . " (By: " . htmlspecialchars($ticket['exited_by']) . ")";
                $messageType = "used";
            } else {
                // Successful Exit
                $updateTix = $pdo->prepare("UPDATE ticket_instances SET exited_at = NOW(), exited_by = ? WHERE ticket_code = ?");
                $updateTix->execute([$admin_who_scanned, $input]);
                
                $message = "✅ EXIT CONFIRMED! (".$input.")";
                $messageType = "success";
            }
        } else {
            // Check kung Annual Pass (pass_visits)
            $stmtVisit = $pdo->prepare("SELECT id, exited_at, exited_by FROM pass_visits WHERE booking_id = ? AND DATE(visit_date) = CURDATE() ORDER BY visit_date DESC LIMIT 1");
            $stmtVisit->execute([$input]);
            $visit = $stmtVisit->fetch(PDO::FETCH_ASSOC);

            if ($visit) {
                if ($visit['exited_at'] !== null) {
                    $message = "⛔ MEMBER ALREADY EXITED at " . date('h:i A', strtotime($visit['exited_at']));
                    $messageType = "used";
                } else {
                    $updateVisit = $pdo->prepare("UPDATE pass_visits SET exited_at = NOW(), exited_by = ? WHERE id = ?");
                    $updateVisit->execute([$admin_who_scanned, $visit['id']]);
                    
                    $message = "✅ MEMBER EXIT CONFIRMED! (#".$input.")";
                    $messageType = "success";
                }
            } else {
                $message = "❌ QR NOT FOUND OR NO ENTRY RECORD TODAY.";
                $messageType = "error";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EXIT SCANNER - Ajman Water Park</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; overflow-x: hidden; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #222;
            color: #fff;
        }

        .header-bar {
            background: #dc3545; /* Red theme for EXIT */
            padding: 14px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            color: white;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
            position: sticky;
            top: 0;
            z-index: 100;
            flex-wrap: wrap;
        }

        .guard-name {
            font-weight: bold;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .guard-name > div:last-child {
            min-width: 0;
            word-break: break-word;
        }

        .logout-btn {
            color: #fff;
            text-decoration: none;
            font-weight: bold;
            border: 1px solid #fff;
            padding: 8px 14px;
            border-radius: 8px;
            transition: 0.3s;
            white-space: nowrap;
        }

        .logout-btn:hover {
            background: #fff;
            color: #dc3545;
        }

        .main-container {
            width: min(100%, 760px);
            margin: 18px auto;
            background: #fff;
            color: #333;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }

        #reader {
            width: 100%;
            background: #000;
            min-height: 280px;
            position: relative;
        }

        #reader video {
            width: 100% !important;
            height: auto !important;
            object-fit: cover;
        }

        .scanner-status {
            background: #0f172a;
            color: #fff;
            text-align: center;
            padding: 10px 14px;
            font-size: 0.92rem;
            font-weight: 600;
        }

        .camera-label-bar {
            background: #fff0f1;
            border-top: 1px solid rgba(0,0,0,0.06);
            border-bottom: 1px solid rgba(0,0,0,0.06);
            padding: 10px 14px;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .camera-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #dc3545; /* Red theme */
            color: #fff;
            padding: 10px 14px;
            border-radius: 999px;
            font-size: 0.92rem;
            font-weight: 700;
            text-align: center;
            flex-wrap: wrap;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.2);
        }

        .camera-pill-icon {
            width: 28px;
            height: 28px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,0.16);
            color: #fff;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .camera-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 110px;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 800;
            line-height: 1;
            color: #fff;
            letter-spacing: 0.2px;
            box-shadow: inset 0 -2px 0 rgba(0,0,0,0.15);
        }

        .camera-badge-green { background: #16a34a; }
        .camera-badge-orange { background: #ea580c; }
        .camera-badge-blue { background: #2563eb; }
        .camera-badge-gray { background: #6b7280; }

        .content-box {
            padding: 24px;
            text-align: center;
        }

        h2 {
            color: #dc3545;
            margin-top: 0;
            font-size: clamp(1.35rem, 2vw, 1.8rem);
        }

        .scanner-controls {
            display: flex;
            gap: 10px;
            margin: 0 0 18px 0;
            flex-wrap: wrap;
        }

        .scanner-btn {
            flex: 1;
            min-width: 180px;
            border: none;
            border-radius: 10px;
            padding: 14px 16px;
            font-weight: 800;
            cursor: pointer;
            color: #fff;
            font-size: 0.95rem;
            min-height: 52px;
            transition: transform 0.15s ease, opacity 0.15s ease, background 0.2s ease;
        }

        .scanner-btn:hover {
            transform: translateY(-1px);
        }

        .scanner-btn:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            transform: none;
        }

        .btn-toggle-start { background: #16a34a; }
        .btn-toggle-stop { background: #dc2626; }
        .btn-switch-camera { background: #003B72; }
        .btn-new-scan { background: #7c3aed; }

        .search-group {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            align-items: stretch;
        }

        input[type="text"] {
            flex: 1;
            min-width: 0;
            width: 100%;
            padding: 15px;
            border: 3px solid #dc3545;
            border-radius: 10px;
            font-weight: bold;
            text-align: center;
            font-size: clamp(1rem, 2.8vw, 1.35rem);
        }

        .btn-go {
            background: #dc3545;
            color: white;
            border: none;
            padding: 0 26px;
            border-radius: 10px;
            font-weight: bold;
            cursor: pointer;
            font-size: 1.05rem;
            min-height: 56px;
            min-width: 96px;
        }

        .status-box {
            padding: 18px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: bold;
            font-size: clamp(0.95rem, 2.5vw, 1.15rem);
            word-break: break-word;
        }

        .status-valid { background: #d4edda; color: #155724; border: 2px solid #c3e6cb; }
        .status-error { background: #f8d7da; color: #721c24; border: 2px solid #f5c6cb; }
        .status-used { background: #fff3cd; color: #856404; border: 2px solid #ffeeba; }
        .status-info { background: #cce5ff; color: #004085; border: 2px solid #b8daff; }

        #reader__scan_region { min-height: 240px; }
        #reader__dashboard { padding: 10px !important; }
        #reader__dashboard_section_swaplink { display: none !important; }
        #reader img { max-width: 100%; }

        @media (max-width: 768px) {
            .header-bar { padding: 12px 14px; }
            .guard-name { font-size: 1rem; }

            .main-container {
                width: calc(100% - 16px);
                margin: 12px auto;
                border-radius: 14px;
            }

            .content-box { padding: 18px 14px 20px; }
            .search-group { flex-direction: column; }
            .btn-go { width: 100%; padding: 14px 16px; }

            .scanner-controls { flex-direction: column; }

            .scanner-btn {
                width: 100%;
                min-width: 0;
            }
        }

        @media (max-width: 480px) {
            #reader { min-height: 230px; }

            .logout-btn {
                width: 100%;
                text-align: center;
            }

            .header-bar { align-items: stretch; }

            .camera-label-bar { padding: 10px; }

            .camera-pill {
                width: 100%;
                border-radius: 14px;
                font-size: 0.9rem;
                padding: 12px;
            }
        }
    </style>
</head>
<body>

<div class="header-bar">
    <div class="guard-name">
        <i class="fas fa-door-open" style="font-size:1.5rem;"></i>
        <div>
            <div style="font-size:0.8rem; opacity:0.8;">GUARD ON DUTY (EXIT)</div>
            <?php echo htmlspecialchars($_SESSION['admin_fullname']); ?>
        </div>
    </div>
    <a href="admin_login.php" class="logout-btn"><i class="fas fa-arrow-left"></i> LOGOUT</a>
</div>

<div class="main-container">
    <div id="reader"></div>
    <div id="scannerStatus" class="scanner-status">Camera is OFF — Use text input or press START SCANNING.</div>

    <div class="camera-label-bar">
        <div class="camera-pill">
            <span id="cameraTypeIcon" class="camera-pill-icon">
                <i class="fas fa-camera-slash"></i>
            </span>
            <span>Selected Camera:</span>
            <span id="cameraBadge" class="camera-badge camera-badge-gray">Detecting...</span>
        </div>
    </div>

    <div class="content-box">
        <div class="scanner-controls">
            <button type="button" id="switchCameraBtn" class="scanner-btn btn-switch-camera" onclick="switchCamera()">
                🔄 SWITCH CAMERA
            </button>
            <button type="button" id="newScanBtn" class="scanner-btn btn-new-scan" onclick="resetForNewScan()">
                <i class="fas fa-qrcode" style="margin-right:6px;"></i> NEW SCAN
            </button>
            <button type="button" id="toggleScanBtn" class="scanner-btn btn-toggle-start" onclick="toggleScanner()">
                ▶ START SCANNING
            </button>
        </div>

        <h2><i class="fas fa-sign-out-alt"></i> SCAN TICKET TO EXIT</h2>

        <?php if ($message): ?>
            <div class="status-box <?php 
                if ($messageType == 'success') echo 'status-valid';
                elseif ($messageType == 'used') echo 'status-used';
                else echo 'status-error';
            ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php else: ?>
            <div class="status-box status-info">Scan a Valid Ticket QR to Record Exit.</div>
        <?php endif; ?>

        <form method="POST" id="scanForm">
    <div class="search-group">
        <input type="text" id="booking_input" name="booking_id" placeholder="Scan or Enter ID..." autocomplete="off" autofocus required>
        <button type="submit" class="btn-go">EXIT</button>
    </div>
</form>


    </div>
</div>

<script>
    let html5QrCode = null;
    let scanStarted = false;
    let availableCameras = [];
    let currentCameraIndex = 0;
    let currentCameraId = null;
    const cameraStorageKey = 'admin_exit_selected_camera';

    const backCameraKeywords = ['back', 'rear', 'environment', 'world', 'traseira', 'trasera', 'arriere', 'hintere'];
    const frontCameraKeywords = ['front', 'user', 'face', 'facetime', 'selfie'];

    function getQrConfig() {
        const viewportWidth = Math.max(window.innerWidth || 0, 320);
        const size = Math.max(170, Math.min(Math.floor(viewportWidth * 0.62), 280));
        return {
            fps: 12,
            qrbox: { width: size, height: size },
            aspectRatio: 4 / 3,
            disableFlip: false,
            formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE]
        };
    }

    function hasCameraKeyword(camera, keywords) {
        const label = ((camera && camera.label) || '').toLowerCase();
        return keywords.some(keyword => label.includes(keyword));
    }

    function isBackCamera(camera) {
        return hasCameraKeyword(camera, backCameraKeywords);
    }

    function isFrontCamera(camera) {
        return hasCameraKeyword(camera, frontCameraKeywords);
    }

    function getPreferredCameraIndex(cameras) {
        if (!cameras || !cameras.length) return 0;

        const foundIndex = cameras.findIndex(cam => isBackCamera(cam));
        if (foundIndex !== -1) return foundIndex;

        const frontIndex = cameras.findIndex(cam => isFrontCamera(cam));
        if (frontIndex !== -1 && cameras.length > 1) {
            const nonFrontIndex = cameras.findIndex((cam, idx) => idx !== frontIndex);
            if (nonFrontIndex !== -1) return nonFrontIndex;
        }

        if (cameras.length > 1) return cameras.length - 1;
        return 0;
    }

    function formatCameraName(camera, index) {
        if (!camera) return "No Camera";
        if (isBackCamera(camera)) return "Rear Camera";
        if (isFrontCamera(camera)) return "Front Camera";
        return "Camera " + (index + 1);
    }

    function getCameraStartSequence(camera, cameraId) {
        const sequence = [];

        if (isBackCamera(camera)) {
            sequence.push({ facingMode: { ideal: 'environment' } });
        } else if (isFrontCamera(camera)) {
            sequence.push({ facingMode: { ideal: 'user' } });
        }

        if (cameraId) {
            sequence.push({ deviceId: { exact: cameraId } });
        }

        sequence.push({ facingMode: { ideal: 'environment' } });

        return sequence.filter((config, index, arr) => {
            const key = JSON.stringify(config);
            return arr.findIndex(item => JSON.stringify(item) === key) === index;
        });
    }

    function updateCameraLabel() {
        const badgeEl = document.getElementById('cameraBadge');
        const iconWrap = document.getElementById('cameraTypeIcon');

        if (!badgeEl || !iconWrap) return;

        badgeEl.classList.remove(
            'camera-badge-green',
            'camera-badge-orange',
            'camera-badge-blue',
            'camera-badge-gray'
        );

        let cameraName = "No Camera";
        let iconClass = "fas fa-camera-slash";
        let badgeClass = "camera-badge-gray";

        if (availableCameras.length) {
            const selectedCamera = availableCameras[currentCameraIndex] || availableCameras[0];
            cameraName = formatCameraName(selectedCamera, currentCameraIndex);

            if (cameraName === "Rear Camera") {
                iconClass = "fas fa-camera-rotate";
                badgeClass = "camera-badge-green";
            } else if (cameraName === "Front Camera") {
                iconClass = "fas fa-user";
                badgeClass = "camera-badge-orange";
            } else if (cameraName.startsWith("Camera ")) {
                iconClass = "fas fa-video";
                badgeClass = "camera-badge-blue";
            }
        }

        badgeEl.textContent = cameraName;
        badgeEl.classList.add(badgeClass);
        iconWrap.innerHTML = '<i class="' + iconClass + '"></i>';
    }

    async function loadCameras() {
        try {
            availableCameras = await Html5Qrcode.getCameras() || [];

            if (availableCameras.length > 0) {
                const savedCameraId = localStorage.getItem(cameraStorageKey);
                const savedIndex = savedCameraId
                    ? availableCameras.findIndex(cam => cam.id === savedCameraId)
                    : -1;

                if (savedIndex !== -1) {
                    currentCameraIndex = savedIndex;
                } else if (currentCameraId !== null) {
                    const liveIndex = availableCameras.findIndex(cam => cam.id === currentCameraId);
                    currentCameraIndex = liveIndex !== -1 ? liveIndex : getPreferredCameraIndex(availableCameras);
                } else {
                    currentCameraIndex = getPreferredCameraIndex(availableCameras);
                }

                currentCameraId = availableCameras[currentCameraIndex].id;
                localStorage.setItem(cameraStorageKey, currentCameraId);
            }

            updateCameraLabel();
        } catch (err) {
            console.error("Failed to load cameras:", err);
            availableCameras = [];
            updateCameraLabel();
        }
    }

    function updateScannerButtons() {
        const toggleBtn = document.getElementById('toggleScanBtn');
        const switchBtn = document.getElementById('switchCameraBtn');

        if (toggleBtn) {
            if (scanStarted) {
                toggleBtn.innerHTML = '⏹ STOP SCANNING';
                toggleBtn.classList.remove('btn-toggle-start');
                toggleBtn.classList.add('btn-toggle-stop');
            } else {
                toggleBtn.innerHTML = '▶ START SCANNING';
                toggleBtn.classList.remove('btn-toggle-stop');
                toggleBtn.classList.add('btn-toggle-start');
            }
        }

        if (switchBtn) {
            switchBtn.disabled = availableCameras.length <= 1;
        }
    }

    function setScannerStatus(text) {
        const el = document.getElementById('scannerStatus');
        if (el) el.textContent = text;
    }

    async function stopScanner(customStatus = "Scanner stopped.") {
        try {
            if (html5QrCode) {
                if (scanStarted) {
                    await html5QrCode.stop();
                }
                try { await html5QrCode.clear(); } catch (e) {}
            }
        } catch (err) {
            console.warn("Scanner stop warning:", err);
        } finally {
            html5QrCode = null;
            scanStarted = false;
            setScannerStatus(customStatus);
            updateScannerButtons();
        }
    }

    async function startScanner(cameraId = null) {
        if (scanStarted) return;

        try {
            if (!availableCameras.length) {
                await loadCameras();
            }

            if (!availableCameras.length) {
                setScannerStatus("No camera detected.");
                updateScannerButtons();
                return;
            }

            if (cameraId) {
                const foundIndex = availableCameras.findIndex(cam => cam.id === cameraId);
                if (foundIndex !== -1) {
                    currentCameraIndex = foundIndex;
                    currentCameraId = availableCameras[foundIndex].id;
                }
            } else if (!currentCameraId) {
                currentCameraIndex = getPreferredCameraIndex(availableCameras);
                currentCameraId = availableCameras[currentCameraIndex].id;
            }

            const selectedCamera = availableCameras[currentCameraIndex] || availableCameras[0];

            if (!selectedCamera) {
                setScannerStatus("No available camera found.");
                updateScannerButtons();
                return;
            }

            currentCameraId = selectedCamera.id;
            localStorage.setItem(cameraStorageKey, currentCameraId);
            updateCameraLabel();

            const startSequence = getCameraStartSequence(selectedCamera, currentCameraId);
            let lastError = null;

            for (const cameraConfig of startSequence) {
                try {
                    if (!html5QrCode) {
                        html5QrCode = new Html5Qrcode("reader");
                    }

                    await html5QrCode.start(
                        cameraConfig,
                        getQrConfig(),
                        onScanSuccess,
                        () => {}
                    );

                    scanStarted = true;
                    setScannerStatus("Camera active: " + (selectedCamera.label || ("Camera " + (currentCameraIndex + 1))));
                    updateScannerButtons();
                    return;
                } catch (err) {
                    lastError = err;
                    console.warn("Camera start attempt failed:", cameraConfig, err);

                    try {
                        if (html5QrCode && scanStarted) {
                            await html5QrCode.stop();
                        }
                    } catch (stopErr) {
                        console.warn("Scanner stop attempt warning:", stopErr);
                    }

                    try {
                        if (html5QrCode) {
                            await html5QrCode.clear();
                        }
                    } catch (clearErr) {}

                    html5QrCode = null;
                    scanStarted = false;
                }
            }

            throw lastError || new Error('Unable to start camera');

        } catch (primaryError) {
            console.error("Scanner start failed:", primaryError);
            html5QrCode = null;
            scanStarted = false;
            setScannerStatus("Unable to start camera. Please allow camera access.");
            updateScannerButtons();
        }
    }

    async function toggleScanner() {
        if (scanStarted) {
            await stopScanner("Scanner stopped manually.");
        } else {
            await startScanner(currentCameraId);
        }
    }

    async function switchCamera() {
        if (!availableCameras.length) {
            await loadCameras();
        }

        if (availableCameras.length <= 1) {
            setScannerStatus("No other camera available.");
            updateScannerButtons();
            return;
        }

        currentCameraIndex = (currentCameraIndex + 1) % availableCameras.length;
        currentCameraId = availableCameras[currentCameraIndex].id;
        localStorage.setItem(cameraStorageKey, currentCameraId);
        updateCameraLabel();

        const nextLabel = availableCameras[currentCameraIndex].label || ("Camera " + (currentCameraIndex + 1));

        if (scanStarted) {
            await stopScanner("Switching camera...");
            await startScanner(currentCameraId);
        } else {
            setScannerStatus("Selected camera: " + nextLabel + " (scanner off)");
            updateScannerButtons();
        }
    }

    async function resetForNewScan() {
        const inputEl = document.getElementById('booking_input');
        if (inputEl) inputEl.value = '';

        await stopScanner("Preparing new scan...");
        window.location.href = 'admin_exit.php';
    }

    async function onScanSuccess(text) {
        document.getElementById('booking_input').value = text;
        await stopScanner("QR detected. Processing...");

        try {
            // Optional: Play a sound on successful scan
            let audio = new Audio('https://www.soundjay.com/buttons/sounds/button-3.mp3');
            await audio.play();
        } catch(e) {}

        document.getElementById('scanForm').submit();
    }

    window.addEventListener('load', async function () {
    await loadCameras();
    updateScannerButtons();
    setScannerStatus("Camera is OFF — Use text input or press START SCANNING.");

    // Auto-focus sa text input para diretso pwedeng mag-scan/type
    const inputEl = document.getElementById('booking_input');
    if (inputEl) {
        inputEl.focus();

        // AUTO-SUBMIT detection para sa external USB/Bluetooth QR scanner.
        // Karamihan ng QR scanner ay mabilis mag-type tapos magpapadala ng Enter.
        // Pag-detect natin ng mabilis na pagkakatipa, i-auto-submit natin agad.
        let lastInputTime = 0;
        let scanTimeout = null;

        inputEl.addEventListener('input', function () {
            const now = Date.now();
            const timeDiff = now - lastInputTime;
            lastInputTime = now;

            if (scanTimeout) clearTimeout(scanTimeout);

            // Pag mabilis ang pagdating ng characters (less than 50ms between chars), QR scanner ito.
            // After 200ms na walang bagong character + may laman ang input, i-submit.
            scanTimeout = setTimeout(function () {
                const val = inputEl.value.trim();
                if (val.length >= 4 && timeDiff < 50) {
                    document.getElementById('scanForm').submit();
                }
            }, 200);
        });

        // Backup: kapag nag-paste ng QR text, auto-submit din
        inputEl.addEventListener('paste', function () {
            setTimeout(function () {
                const val = inputEl.value.trim();
                if (val.length >= 4) {
                    document.getElementById('scanForm').submit();
                }
            }, 50);
        });
    }

    // Hindi na i-auto-start ang camera — manual lang via START SCANNING button
});

</script>

</body>
</html>