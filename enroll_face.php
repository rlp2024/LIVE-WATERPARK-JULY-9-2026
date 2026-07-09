<?php
// enroll_face.php
session_start();
include_once 'db_connect.php';

if (!isset($_GET['booking_id'])) {
    header("Location: index.php");
    exit;
}

$booking_id = $_GET['booking_id'];

// Check kung valid ang booking
$stmt = $pdo->prepare("SELECT * FROM bookings WHERE booking_id = ?");
$stmt->execute([$booking_id]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) die("Booking not found.");

// Kung may picture na, wag na ulitin, proceed na sa success
if (!empty($booking['face_image_path'])) {
    header("Location: success.php?booking_id=" . $booking_id);
    exit;
}

// HANDLE IMAGE UPLOAD (AJAX POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['image_data'])) {
    
    $img = $_POST['image_data'];
    $img = str_replace('data:image/jpeg;base64,', '', $img);
    $img = str_replace(' ', '+', $img);
    $data = base64_decode($img);
    
    // Create Folder if not exists
    $uploadDir = 'images/faces/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    
    $fileName = 'face_' . $booking_id . '_' . time() . '.jpg';
    $filePath = $uploadDir . $fileName;
    
    if (file_put_contents($filePath, $data)) {
        // Update Database
        $stmtUpdate = $pdo->prepare("UPDATE bookings SET face_image_path = ? WHERE booking_id = ?");
        $stmtUpdate->execute([$filePath, $booking_id]);
        
        echo "success"; // Response for JS
    } else {
        echo "error";
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Annual Pass Registration</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .camera-card { background: white; padding: 30px; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); text-align: center; max-width: 500px; width: 100%; }
        h2 { color: #003B72; margin-bottom: 10px; }
        p { color: #666; margin-bottom: 20px; }
        
        #camera-view, #snap-preview { width: 100%; border-radius: 10px; background: #000; transform: scaleX(-1); /* Mirror effect */ }
        #snap-preview { display: none; }
        
        .btn-group { margin-top: 20px; display: flex; gap: 10px; justify-content: center; }
        
        button { padding: 12px 25px; border: none; border-radius: 50px; font-weight: bold; cursor: pointer; font-size: 1rem; transition: 0.2s; }
        .btn-capture { background: #003B72; color: white; }
        .btn-retake { background: #6c757d; color: white; display: none; }
        .btn-save { background: #28a745; color: white; display: none; }
        
        button:hover { opacity: 0.9; transform: translateY(-2px); }
    </style>
</head>
<body>

<div class="camera-card">
    <h2>Annual Pass Registration</h2>
    <p>Please take a clear photo for your Member ID.</p>
    
    <video id="camera-view" autoplay playsinline></video>
    <canvas id="snap-preview"></canvas>
    
    <div class="btn-group">
        <button class="btn-capture" onclick="takeSnapshot()">Take Photo <i class="fas fa-camera"></i></button>
        <button class="btn-retake" onclick="resetCamera()">Retake</button>
        <button class="btn-save" onclick="savePhoto()">Save & Continue <i class="fas fa-check"></i></button>
    </div>
</div>

<script>
    const video = document.getElementById('camera-view');
    const canvas = document.getElementById('snap-preview');
    const btnCapture = document.querySelector('.btn-capture');
    const btnRetake = document.querySelector('.btn-retake');
    const btnSave = document.querySelector('.btn-save');
    let streamObj = null;

    // 1. Start Camera
    async function startCamera() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "user" } });
            video.srcObject = stream;
            streamObj = stream;
        } catch (err) {
            alert("Camera access denied. Please enable camera permissions.");
        }
    }

    // 2. Take Snapshot
    function takeSnapshot() {
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');
        ctx.translate(canvas.width, 0);
        ctx.scale(-1, 1); // Mirror effect
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        
        video.style.display = 'none';
        canvas.style.display = 'block';
        
        btnCapture.style.display = 'none';
        btnRetake.style.display = 'inline-block';
        btnSave.style.display = 'inline-block';
    }

    // 3. Reset
    function resetCamera() {
        video.style.display = 'block';
        canvas.style.display = 'none';
        btnCapture.style.display = 'inline-block';
        btnRetake.style.display = 'none';
        btnSave.style.display = 'none';
    }

    // 4. Save to Server
    function savePhoto() {
        const dataURL = canvas.toDataURL('image/jpeg', 0.9);
        btnSave.innerText = "Saving...";
        
        const formData = new FormData();
        formData.append('image_data', dataURL);

        fetch('enroll_face.php?booking_id=<?php echo $booking_id; ?>', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(result => {
            if(result.trim() === 'success') {
                window.location.href = 'success.php?booking_id=<?php echo $booking_id; ?>';
            } else {
                alert('Error saving photo. Please try again.');
                btnSave.innerText = "Save & Continue";
            }
        })
        .catch(err => {
            console.error(err);
            alert('Network error.');
        });
    }

    startCamera();
</script>

</body>
</html>