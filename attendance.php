<?php
session_start();

/* Block access if not employee */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: index.php");
    exit;
}

/* Load config */
$config = require __DIR__ . "/config.php";
$cfg = $config; // compatibility

/* DB connect */
try {
    $pdo = new PDO(
        $config['db']['dsn'],
        $config['db']['user'],
        $config['db']['pass'],
        $config['db']['options']
    );
} catch (PDOException $e) {
    die("DB Connection Failed");
}

/* Logged-in employee ID */
$empId = $_SESSION['emp_id'] ?? null;

/* Fetch employee info */
$stmt = $pdo->prepare("
    SELECT e.*, d.name AS dept_name, s.name AS shift_name
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN shifts s ON s.id = e.shift_id
    WHERE e.id=?
");
$stmt->execute([$empId]);
$employee = $stmt->fetch();

/* Add check: If employee not found, logout and redirect */
if (!$employee) {
    session_destroy();
    header("Location: index.php");
    exit;
}

/* Fetch geo settings */
$settings = $pdo->query("SELECT * FROM settings WHERE id=1")->fetch();

/* Utility: sanitize */
function clean($x) {
    return htmlspecialchars(trim($x), ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Employee Attendance</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body { 
            background: #eef2ff;
            font-family: "Poppins", sans-serif;
        }

        .sidebar {
            width: 240px;
            height: 100vh;
            position: fixed;
            background: #0d6efd;
            color: #fff;
            top: 0;
            left: 0;
            padding-top: 30px;
        }

        .sidebar a {
            display: block;
            padding: 12px 20px;
            text-decoration: none;
            color: #fff;
            font-size: 15px;
        }

        .sidebar a:hover, .sidebar a.active {
            background: rgba(255,255,255,0.25);
        }

        .content {
            margin-left: 240px;
            padding: 25px;
        }

        .card {
            border-radius: 12px;
            box-shadow: 0 5px 18px rgba(0,0,0,0.08);
        }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <h4 class="text-center mb-4">HRMS Employee</h4>

    <a href="employee.php">Home</a>
    <a href="attendance.php" class="active">Attendance</a>
    <a href="leaveapply.php">Apply Leave</a>
    <a href="myleave.php">My Leaves</a>
    <a href="profile.php">Profile</a>
    <a href="index.php?logout=1">Logout</a>
</div>

<!-- MAIN CONTENT -->
<div class="content">

<h2 class="mb-4">Attendance</h2>

<div class="card p-4 mb-4">

    <h5>Your Location</h5>
    <p>
        Office Location:  
        <b><?= $settings['office_lat'] ?> , <?= $settings['office_lng'] ?></b><br>
        Allowed Radius: <b><?= $settings['radius'] ?>m</b>
    </p>

    <div class="alert alert-info">
        Only GPS is required now.  
        No selfie needed.  
        Make sure Location Permission is ON.
    </div>

    <!-- Hidden canvas + video for live selfie -->
    <div class="text-center mb-3">
       <video id="cameraStream" autoplay playsinline style="width:100%; max-width:300px; display:none; border-radius:10px;"></video>
        <canvas id="snapshotCanvas" style="display:none;"></canvas>
    </div>

    <!-- GPS FORM -->
    <form id="attendanceForm">

        <input type="hidden" name="action" id="actionType">

        <input type="hidden" name="lat" id="latitude">
        <input type="hidden" name="lng" id="longitude">

        <input type="hidden" name="selfie" id="selfieData">

        <button type="button" onclick="doAttendance('checkin')" class="btn btn-success w-100 mb-3">
            Check In
        </button>

        <button type="button" onclick="doAttendance('checkout')" class="btn btn-danger w-100">
            Check Out
        </button>

    </form>

</div>

<!-- Attendance History -->
<div class="card p-4">
    <h5>My Recent Attendance</h5>

    <table class="table table-bordered mt-3">
        <tr>
            <th>Check In</th>
            <th>Check Out</th>
            <th>Status</th>
        </tr>

        <?php
        $stmt = $pdo->prepare("
            SELECT check_in, check_out, status 
            FROM attendance 
            WHERE emp_id=? 
            ORDER BY id DESC 
            LIMIT 10
        ");
        $stmt->execute([$empId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $r):
        ?>
        <tr>
            <td><?= $r['check_in'] ?></td>
            <td><?= $r['check_out'] ?></td>
            <td><?= $r['status'] ?></td>
        </tr>
        <?php endforeach; ?>

    </table>

</div>

   <!-- ================= GPS SCRIPT ONLY ================= -->
<script>
let cameraEnabled = false;

/* Enable Camera */
async function enableCamera() {
    if (cameraEnabled) return;

    const video = document.getElementById("cameraStream");

    try {
        const stream = await navigator.mediaDevices.getUserMedia({ video: true });
        video.srcObject = stream;
        video.style.display = "block";
        cameraEnabled = true;
    } catch (err) {
        alert("Camera permission denied. Please allow camera access.");
    }
}

/* Capture selfie from camera */
function captureSelfie() {
    const video = document.getElementById("cameraStream");
    const canvas = document.getElementById("snapshotCanvas");
    const selfieInput = document.getElementById("selfieData");

    if (!cameraEnabled || video.videoWidth === 0) {
        selfieInput.value = "";
        return;
    }

    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;

    const ctx = canvas.getContext("2d");
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

    let base64Image = canvas.toDataURL("image/png");
    selfieInput.value = base64Image;
}

/* Attendance button click */
function doAttendance(type) {
    document.getElementById("actionType").value = type;

    // Ensure camera starts
    enableCamera();

    // Wait → capture selfie → then GPS → then submit
    setTimeout(() => {
        captureSelfie();
        captureGPSAndSend();
    }, 1200);
}

/* Capture GPS location */
function captureGPSAndSend() {
    if (!navigator.geolocation) {
        alert("Your device does not support GPS.");
        return;
    }

    navigator.geolocation.getCurrentPosition(position => {
        document.getElementById("latitude").value = position.coords.latitude;
        document.getElementById("longitude").value = position.coords.longitude;

        submitAttendance();

    }, () => {
        alert("Enable location access to record attendance.");
    });
}

/* Send everything to ajax.php */
function submitAttendance() {
    const form = document.getElementById("attendanceForm");
    const formData = new FormData(form);

    fetch("ajax.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(response => {
        alert(response.msg);
        if (response.status === "success") {
            location.reload();
        }
    })
    .catch(err => {
        alert("Error sending attendance.");
    });
}
</script>
</div> <!-- END CONTENT -->

<!-- BOOTSTRAP JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>