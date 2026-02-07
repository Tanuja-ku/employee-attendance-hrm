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

/* Utility: sanitize */
function clean($x) {
    return htmlspecialchars(trim($x), ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Employee Dashboard</title>

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

    <a href="employee.php" class="active">Home</a>
    <a href="attendance.php">Attendance</a>
    <a href="leaveapply.php">Apply Leave</a>
    <a href="myleave.php">My Leaves</a>
    <a href="profile.php">Profile</a>
    <a href="index.php?logout=1">Logout</a>
</div>

   <div class="notification-wrapper" style="position: fixed; top: 15px; right: 20px; z-index: 9999;">
    <i id="notifBell" class="bi bi-bell-fill" style="font-size: 28px; cursor: pointer; color: #0d6efd;"></i>

    <span id="notifCount"
          style="position: absolute; top: -5px; right: -8px; background: red; 
                 color: white; padding: 2px 7px; border-radius: 50%; font-size: 12px;">
    </span>

    <div id="notifDropdown"
         style="display:none; position:absolute; right:0; top:40px; width:300px; 
                max-height:350px; overflow-y:auto; background:white;
                box-shadow:0 5px 15px rgba(0,0,0,0.2); border-radius:10px;">
    </div>
</div>


<!-- MAIN CONTENT -->
<div class="content">

    


    <h2 class="mb-4">Welcome, <?= $employee['name'] ?></h2>

    <div class="row">
        <div class="col-md-4">
            <div class="card p-4 text-center">
                <h3><?= $employee['emp_id'] ?></h3>
                <p>Employee ID</p>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card p-4 text-center">
                <h3><?= $employee['dept_name'] ?></h3>
                <p>Department</p>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card p-4 text-center">
                <h3><?= $employee['shift_name'] ?: 'No Shift' ?></h3>
                <p>Shift</p>
            </div>
        </div>
    </div>

    <script>
// Load employee notifications
function loadNotifications() {
    fetch("notification.php")
        .then(res => res.json())
        .then(data => {
            let count = data.length;
            let badge = document.getElementById("notifCount");
            let dropdown = document.getElementById("notifDropdown");

            badge.innerText = count > 0 ? count : "";

            dropdown.innerHTML = "";

            if (count === 0) {
                dropdown.innerHTML = `<div class="notif-item" style="padding:10px;">No new notifications</div>`;
                return;
            }

            data.forEach(n => {
                dropdown.innerHTML += `
                    <div class="notif-item"
                         onclick="window.location='${n.link}'"
                         style="padding:10px; border-bottom:1px solid #eee; cursor:pointer;">
                        <b>${n.message}</b>
                        <div style="font-size:12px; color:gray;">${n.created_at}</div>
                    </div>
                `;
            });
        });
}

// toggle dropdown
document.getElementById("notifBell").onclick = function () {
    const dropdown = document.getElementById("notifDropdown");

    dropdown.style.display = dropdown.style.display === "block" ? "none" : "block";

    if (dropdown.style.display === "block") {
        // mark notifications as read
        fetch("mark_read.php")
            .then(() => {
                document.getElementById("notifCount").innerText = "";
            });
    }
};

// refresh every 5 seconds
setInterval(loadNotifications, 5000);
loadNotifications();
</script>


   


</div> <!-- END CONTENT -->

<!-- BOOTSTRAP JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>