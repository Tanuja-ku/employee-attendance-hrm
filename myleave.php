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
    <title>My Leaves</title>

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
    <a href="attendance.php">Attendance</a>
    <a href="leaveapply.php">Apply Leave</a>
    <a href="myleave.php" class="active">My Leaves</a>
    <a href="profile.php">Profile</a>
    <a href="index.php?logout=1">Logout</a>
</div>

<!-- MAIN CONTENT -->
<div class="content">
    


<h2 class="mb-4">My Leave Requests</h2>

<div class="card p-4">

    <table class="table table-bordered">
        <tr>
            <th>Date Range</th>
            <th>Reason</th>
            <th>Status</th>
        </tr>

        <?php
        $stmt = $pdo->prepare("
            SELECT * FROM leaves
            WHERE emp_id=?
            ORDER BY id DESC
        ");
        $stmt->execute([$empId]);
        $myLeaves = $stmt->fetchAll();

        foreach ($myLeaves as $l):
        ?>
        <tr>
            <td><?= $l['start_date'] ?> → <?= $l['end_date'] ?></td>
            <td><?= $l['reason'] ?></td>
            <td>
                <?php if ($l['status'] == 'pending'): ?>
                    <span class="badge bg-warning text-dark">Pending</span>
                <?php elseif ($l['status'] == 'approved'): ?>
                    <span class="badge bg-success">Approved</span>
                <?php else: ?>
                    <span class="badge bg-danger">Rejected</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>

    </table>

</div>

</div> <!-- END CONTENT -->

<!-- BOOTSTRAP JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>