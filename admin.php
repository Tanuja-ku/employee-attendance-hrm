<?php
session_start();

/* Access Protection */
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'hr'])) {
    header("Location: index.php");
    exit;
}

/* Load Config */
$config = require __DIR__ . "/config.php";
$cfg = $config; // backward compatibility

/* Database Connection */
try {
    $pdo = new PDO(
        $config['db']['dsn'],
        $config['db']['user'],
        $config['db']['pass'],
        $config['db']['options']
    );
} catch (PDOException $e) {
    die("DB connection failed: " . $e->getMessage());
}

/* Helpers */
function sanitize($v) {
    return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8');
}

function haversineDistance($lat1, $lon1, $lat2, $lon2) {
    $earth = 6371000; 
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2)**2;
    return 2 * $earth * asin(sqrt($a));
}

function addWatermark($imagePath, $outputPath, $text) {
    $img = imagecreatefrompng($imagePath);
    if (!$img) return false;

    $black = imagecolorallocate($img, 0, 0, 0);
    imagestring($img, 5, 10, 10, $text, $black);
    imagepng($img, $outputPath);
    imagedestroy($img);
    return true;
}

/* Determine active page */
$page = $_GET['page'] ?? "dashboard";

/* POST Actions Routing */
if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $action = $_POST['action'] ?? "";

    /* ---------------------------
        ADD OR EDIT EMPLOYEE
    -----------------------------*/
    if ($action === "add_employee" || $action === "edit_employee") {

        $id = (int)($_POST['id'] ?? 0);

        $data = [
            "emp_id"        => sanitize($_POST['emp_id']),
            "name"          => sanitize($_POST['name']),
            "department_id" => (int)$_POST['department_id'],
            "designation"   => sanitize($_POST['designation']),
            "email"         => sanitize($_POST['email']),
            "phone"         => sanitize($_POST['phone']),
            "shift_id"      => (int)$_POST['shift_id']
        ];

        $passwordInput = trim($_POST['password'] ?? "");

        /* INSERT NEW EMPLOYEE */
        if ($action === "add_employee") {

            $stmt = $pdo->prepare("
                INSERT INTO employees (emp_id, name, department_id, designation, email, phone, shift_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute(array_values($data));

            $newId = $pdo->lastInsertId();

            $passwordUse = !empty($passwordInput) ? $passwordInput : "emp123";
            $hashed = password_hash($passwordUse, PASSWORD_DEFAULT);

            $pdo->prepare("
                INSERT INTO users (username, password, role, emp_id)
                VALUES (?, ?, 'employee', ?)
            ")->execute([$data["emp_id"], $hashed, $newId]);

            $pdo->prepare("INSERT INTO leave_balances (emp_id) VALUES (?)")
                ->execute([$newId]);

        } 
        /* UPDATE EMPLOYEE */
        else {
            $stmt = $pdo->prepare("
                UPDATE employees 
                SET emp_id=?, name=?, department_id=?, designation=?, email=?, phone=?, shift_id=?
                WHERE id=?
            ");
            $stmt->execute([
                $data["emp_id"], $data["name"], $data["department_id"],
                $data["designation"], $data["email"], $data["phone"],
                $data["shift_id"], $id
            ]);

            /* Update password only if entered */
            if (!empty($passwordInput)) {
                $hashed = password_hash($passwordInput, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password=? WHERE emp_id=?")
                    ->execute([$hashed, $id]);
            }
        }

        header("Location: admin.php?page=employees");
        exit;
    }

    /* ---------------------------
        BULK IMPORT EMPLOYEES
    -----------------------------*/
    if ($action === "bulk_import") {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
            $file = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file, "r");
            $header = fgetcsv($handle); // skip header
            while (($row = fgetcsv($handle)) !== FALSE) {
                if (count($row) < 7) continue;
                $data = [
                    "emp_id" => sanitize($row[0]),
                    "name" => sanitize($row[1]),
                    "department_id" => (int)$row[2],
                    "designation" => sanitize($row[3]),
                    "email" => sanitize($row[4]),
                    "phone" => sanitize($row[5]),
                    "shift_id" => (int)$row[6]
                ];

                // Check if emp_id exists
                $check = $pdo->prepare("SELECT id FROM employees WHERE emp_id = ?");
                $check->execute([$data['emp_id']]);
                if ($check->fetch()) continue; // skip if exists

                $stmt = $pdo->prepare("
                    INSERT INTO employees (emp_id, name, department_id, designation, email, phone, shift_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute(array_values($data));

                $newId = $pdo->lastInsertId();

                $hashed = password_hash("emp123", PASSWORD_DEFAULT);

                $pdo->prepare("
                    INSERT INTO users (username, password, role, emp_id)
                    VALUES (?, ?, 'employee', ?)
                ")->execute([$data["emp_id"], $hashed, $newId]);

                $pdo->prepare("INSERT INTO leave_balances (emp_id) VALUES (?)")
                    ->execute([$newId]);
            }
            fclose($handle);
        }
        header("Location: admin.php?page=employees");
        exit;
    }

    /* ---------------------------
        DELETE EMPLOYEE
    -----------------------------*/
    if ($action === "delete_employee") {
        $id = (int)$_POST["id"];
        // Added: Clean up related records for consistency
        $pdo->prepare("DELETE FROM users WHERE emp_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM leave_balances WHERE emp_id=?")->execute([$id]);
        // Optional: Keep attendance and leaves for records, or delete if needed
        // $pdo->prepare("DELETE FROM attendance WHERE emp_id=?")->execute([$id]);
        // $pdo->prepare("DELETE FROM leaves WHERE emp_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM employees WHERE id=?")->execute([$id]);
        header("Location: admin.php?page=employees");
        exit;
    }

    /* ---------------------------
        APPROVE / REJECT LEAVE
    -----------------------------*/
    if ($action === "approve_leave") {
        $pdo->prepare("
            UPDATE leaves 
            SET status='approved', approved_by=?, approved_date=NOW()
            WHERE id=?
        ")->execute([$_SESSION["user_id"], $_POST["id"]]);

        $id = $_POST['id'];

        // Notify employee
        $leaveEmp = $pdo->prepare("SELECT emp_id FROM leaves WHERE id=?");
        $leaveEmp->execute([$id]);
        $eid = $leaveEmp->fetchColumn();

        // Find employee's user_id
        $u = $pdo->prepare("SELECT id FROM users WHERE emp_id=?");
        $u->execute([$eid]);
        $userId = $u->fetchColumn();

        $pdo->prepare("
            INSERT INTO notifications (user_id, message, link)
            VALUES (?, 'Your leave request has been approved', 'myleave.php')
        ")->execute([$userId]);

        header("Location: admin.php?page=leaves");
        exit;
    }

    if ($action === "reject_leave") {
        $pdo->prepare("
            UPDATE leaves 
            SET status='rejected', approved_by=?, approved_date=NOW()
            WHERE id=?
        ")->execute([$_SESSION["user_id"], $_POST["id"]]);

        $id = $_POST['id'];

        // Notify employee
        $leaveEmp = $pdo->prepare("SELECT emp_id FROM leaves WHERE id=?");
        $leaveEmp->execute([$id]);
        $eid = $leaveEmp->fetchColumn();

        // Find employee's user_id
        $u = $pdo->prepare("SELECT id FROM users WHERE emp_id=?");
        $u->execute([$eid]);
        $userId = $u->fetchColumn();

        $pdo->prepare("
            INSERT INTO notifications (user_id, message, link)
            VALUES (?, 'Your leave request has been rejected', 'myleave.php')
        ")->execute([$userId]);

        header("Location: admin.php?page=leaves");
        exit;
    }

    /* ---------------------------
        UPDATE GEO-FENCE SETTINGS
    -----------------------------*/
    if ($action === "update_geo") {
        $lat = (float)$_POST["lat"];
        $lng = (float)$_POST["lng"];
        $radius = (int)$_POST["radius"];

        $pdo->prepare("UPDATE settings SET office_lat=?, office_lng=?, radius=? WHERE id=1")
            ->execute([$lat, $lng, $radius]);

        header("Location: admin.php?page=settings");
        exit;
    }

    /* ---------------------------
        ADD HOLIDAY
    -----------------------------*/
    if ($action === "add_holiday") {
        $pdo->prepare("
            INSERT INTO holidays (date, description)
            VALUES (?, ?)
        ")->execute([$_POST["date"], sanitize($_POST["description"])]);
        header("Location: admin.php?page=settings");
        exit;
    }

    /* ---------------------------
        ADD SHIFT
    -----------------------------*/
    if ($action === "add_shift") {
        $pdo->prepare("
            INSERT INTO shifts (name, start_time, end_time)
            VALUES (?, ?, ?)
        ")->execute([
            sanitize($_POST["name"]),
            $_POST["start_time"],
            $_POST["end_time"]
        ]);
        header("Location: admin.php?page=settings");
        exit;
    }
}
/* ============================================
   FETCH PAGE DATA BASED ON PAGE PARAMETER
   ============================================ */

$today = date("Y-m-d");

if ($page === "dashboard") {

    /* TOTAL EMPLOYEES */
    $totalEmployees = $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();

    /* PRESENT / ABSENT TODAY */
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN status='present' THEN 1 END) as present_count,
            COUNT(CASE WHEN status='absent' THEN 1 END) as absent_count
        FROM attendance
        WHERE DATE(check_in)=?
    ");
    $stmt->execute([$today]);
    $summary = $stmt->fetch();

    /* ON LEAVE TODAY */
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM leaves
        WHERE status='approved'
        AND ? BETWEEN start_date AND end_date
    ");
    $stmt->execute([$today]);
    $onLeave = $stmt->fetchColumn();
}

/* ---------------- EMPLOYEES PAGE ---------------- */
elseif ($page === "employees") {

    $editId = (int)($_GET["edit"] ?? 0);

    $employees = $pdo->query("
        SELECT e.*, d.name AS dept_name, s.name AS shift_name
        FROM employees e
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN shifts s ON e.shift_id = s.id
        ORDER BY e.id DESC
    ")->fetchAll();

    $departments = $pdo->query("SELECT * FROM departments")->fetchAll();
    $shifts = $pdo->query("SELECT * FROM shifts")->fetchAll();

    /* For editing */
    $editEmployee = null;
    if ($editId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE id=?");
        $stmt->execute([$editId]);
        $editEmployee = $stmt->fetch();
    }
}

/* ---------------- ATTENDANCE PAGE ---------------- */
elseif ($page == 'attendance') {

    // FETCH ATTENDANCE RECORDS
    $stmt = $pdo->query("
        SELECT a.*, e.name, e.emp_id
        FROM attendance a
        JOIN employees e ON a.emp_id = e.id
        ORDER BY a.id DESC
    ");

    $records = $stmt->fetchAll();
}

/* ---------------- LEAVES PAGE ---------------- */
elseif ($page === "leaves") {
    $search = sanitize($_GET['search'] ?? '');
    $statusFilter = $_GET['status'] ?? '';

    $sql = "
        SELECT l.*, e.name AS employee_name, u.username AS approver
        FROM leaves l
        INNER JOIN employees e ON e.id = l.emp_id
        LEFT JOIN users u ON u.id = l.approved_by
        WHERE 1=1
    ";

    $params = [];

    if ($search) {
        $sql .= " AND e.name LIKE ?";
        $params[] = "%$search%";
    }

    if ($statusFilter && in_array($statusFilter, ['pending', 'approved', 'rejected'])) {
        $sql .= " AND l.status = ?";
        $params[] = $statusFilter;
    }

    $sql .= " ORDER BY l.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $leaves = $stmt->fetchAll();
}

/* ---------------- REPORTS PAGE ---------------- */
elseif ($page === "reports") {

    $period = $_GET["period"] ?? "daily";
    $deptFilter = (int)($_GET["dept"] ?? 0);
    $empFilter = (int)($_GET["emp"] ?? 0);

    /* Time Filter */
    if ($period === "daily") {
        $dateFilter = "DATE(a.check_in) = CURDATE()";
    } elseif ($period === "weekly") {
        $dateFilter = "YEARWEEK(a.check_in) = YEARWEEK(NOW())";
    } else { // monthly
        $dateFilter = "MONTH(a.check_in)=MONTH(NOW()) AND YEAR(a.check_in)=YEAR(NOW())";
    }

    $sql = "
        SELECT e.name, d.name AS department,
               COUNT(CASE WHEN a.status='present' THEN 1 END) AS present_days,
               COUNT(CASE WHEN a.status='absent' THEN 1 END) AS absent_days
        FROM employees e
        LEFT JOIN departments d ON d.id = e.department_id
        LEFT JOIN attendance a ON a.emp_id = e.id AND $dateFilter
        WHERE 1=1
    ";

    $params = [];

    if ($deptFilter > 0) {
        $sql .= " AND e.department_id = ?";
        $params[] = $deptFilter;
    }
    if ($empFilter > 0) {
        $sql .= " AND e.id = ?";
        $params[] = $empFilter;
    }

    $sql .= " GROUP BY e.id ORDER BY d.name, e.name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll();

    $departments = $pdo->query("SELECT id, name FROM departments")->fetchAll();
    $allEmployees = $pdo->query("SELECT id, name FROM employees")->fetchAll();

    /* Export CSV */
    if (isset($_GET["export"]) && $_GET["export"] === "csv") {
        header("Content-Type: text/csv");
        header("Content-Disposition: attachment; filename=report.csv");
        $f = fopen("php://output", "w");
        fputcsv($f, ["Employee", "Department", "Present Days", "Absent Days"]);
        foreach ($reports as $r) {
            fputcsv($f, [$r["name"], $r["department"], $r["present_days"], $r["absent_days"]]);
        }
        fclose($f);
        exit;
    }
}

/* ---------------- SETTINGS PAGE ---------------- */
elseif ($page === "settings") {
    $settings = $pdo->query("SELECT * FROM settings WHERE id=1")->fetch();
    $holidays = $pdo->query("SELECT * FROM holidays")->fetchAll();
    $shifts = $pdo->query("SELECT * FROM shifts")->fetchAll();
}

/* ---------------- DOCUMENTS PAGE ---------------- */
elseif ($page === "documents") {
    // Placeholder for documents
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HRMS Admin Panel</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body { 
            background: #f5f6fa;
            font-family: "Poppins", sans-serif;
        }
        .sidebar {
            width: 240px;
            height: 100vh;
            background: #0d6efd;
            position: fixed;
            top: 0;
            left: 0;
            color: #fff;
            padding-top: 30px;
        }
        .sidebar a {
            display: block;
            padding: 12px 20px;
            color: #fff;
            font-size: 15px;
            text-decoration: none;
        }
        .sidebar a:hover, .sidebar a.active {
            background: rgba(255,255,255,0.2);
        }
        .content {
            margin-left: 240px;
            padding: 30px;
        }
        .card {
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

       
    </style>
</head>
<body>

<!-- ================= SIDEBAR ================= -->
<div class="sidebar">
    <h4 class="text-center mb-4">HRMS Admin</h4>
     
    

    <a href="admin.php?page=dashboard" class="<?= $page==='dashboard'?'active':'' ?>"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
    <a href="admin.php?page=employees" class="<?= $page==='employees'?'active':'' ?>"><i class="bi bi-people me-2"></i>Employees</a>
    <a href="admin.php?page=attendance" class="<?= $page==='attendance'?'active':'' ?>"><i class="bi bi-clock-history me-2"></i>Attendance</a>
    <a href="admin.php?page=leaves" class="<?= $page==='leaves'?'active':'' ?>"><i class="bi bi-calendar-x me-2"></i>Leaves</a>
    <a href="admin.php?page=reports" class="<?= $page==='reports'?'active':'' ?>"><i class="bi bi-graph-up me-2"></i>Reports</a>

    <?php if ($_SESSION['role'] === 'admin'): ?>

    <a href="admin.php?page=settings" class="<?= $page==='settings'?'active':'' ?>"><i class="bi bi-gear me-2"></i>Settings</a>
    <?php endif; ?>
       
    

    <a href="index.php?logout=1"><i class="bi bi-box-arrow-right me-2"></i>Logout</a>
    
</div>

   <div class="notification-wrapper" style="position: fixed; top: 15px; right: 20px; z-index: 9999;">
    <i id="notifBell" class="bi bi-bell-fill" style="font-size: 28px; cursor: pointer; color: #f3e00c;"></i>
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


<!-- ================= MAIN CONTENT ================= -->
<div class="content">
   


<?php if ($page === "dashboard"): ?>

    <h2 class="mb-4">Dashboard Overview</h2>

    <div class="row">

        <div class="col-md-4">
            <div class="card p-3 text-center">
                <h3><?= $totalEmployees ?></h3>
                <p>Total Employees</p>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card p-3 text-center">
                <h3><?= $summary['present_count'] ?></h3>
                <p>Present Today</p>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card p-3 text-center">
                <h3><?= $summary['absent_count'] ?></h3>
                <p>Absent Today</p>
            </div>
        </div>

        <div class="col-md-4 mt-4">
            <div class="card p-3 text-center">
                <h3><?= $onLeave ?></h3>
                <p>On Leave</p>
            </div>
        </div>

    </div>

<?php endif; ?>
<!-- ================= EMPLOYEES PAGE ================= -->
<?php if ($page === "employees"): ?>
<h2 class="mb-4">Employee Management</h2>

<div class="card p-4 mb-4">
    <h5><?= $editEmployee ? "Edit Employee" : "Add New Employee" ?></h5>

    <form method="POST">
        <input type="hidden" name="action" value="<?= $editEmployee ? 'edit_employee' : 'add_employee' ?>">
        <input type="hidden" name="id" value="<?= $editEmployee['id'] ?? '' ?>">

        <div class="row">
            <div class="col-md-4 mb-3">
                <label>Employee ID</label>
                <input type="text" class="form-control" name="emp_id"
                    value="<?= $editEmployee['emp_id'] ?? '' ?>"
                    <?= $editEmployee ? "readonly" : "" ?> required>
            </div>

            <div class="col-md-4 mb-3">
                <label>Name</label>
                <input type="text" class="form-control" name="name" 
                    value="<?= $editEmployee['name'] ?? '' ?>" required>
            </div>

            <div class="col-md-4 mb-3">
                <label>Designation</label>
                <input type="text" class="form-control" name="designation"
                    value="<?= $editEmployee['designation'] ?? '' ?>">
            </div>

            <div class="col-md-4 mb-3">
                <label>Email</label>
                <input type="email" class="form-control" name="email"
                    value="<?= $editEmployee['email'] ?? '' ?>">
            </div>

            <div class="col-md-4 mb-3">
                <label>Phone</label>
                <input type="text" class="form-control" name="phone"
                    value="<?= $editEmployee['phone'] ?? '' ?>">
            </div>

            <div class="col-md-4 mb-3">
                <label>Department</label>
                <select name="department_id" class="form-control">
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>"
                            <?= ($editEmployee['department_id'] ?? '') == $d['id'] ? "selected" : "" ?>>
                            <?= $d['name'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4 mb-3">
                <label>Shift</label>
                <select name="shift_id" class="form-control">
                    <option value="">None</option>
                    <?php foreach ($shifts as $s): ?>
                        <option value="<?= $s['id'] ?>"
                            <?= ($editEmployee['shift_id'] ?? '') == $s['id'] ? "selected" : "" ?>>
                            <?= $s['name'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4 mb-3">
                <label>Password <?= $editEmployee ? "(leave blank to keep old)" : "" ?></label>
                <input type="password" class="form-control" name="password">
            </div>
        </div>

        <button class="btn btn-primary"><?= $editEmployee ? "Update" : "Add" ?> Employee</button>
    </form>
</div>

<!-- BULK IMPORT -->
<div class="card p-4 mb-4">
    <h5>Bulk Import Employees</h5>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="bulk_import">
        <div class="mb-3">
            <label>CSV File (headers: emp_id,name,department_id,designation,email,phone,shift_id)</label>
            <input type="file" class="form-control" name="csv_file" accept=".csv" required>
        </div>
        <button class="btn btn-primary">Import</button>
    </form>
</div>

<!-- LIST OF EMPLOYEES -->
<div class="card p-4">
    <h5>Employees List</h5>

    <table class="table table-striped mt-3">
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Dept</th>
            <th>Designation</th>
            <th>Email</th>
            <th>Actions</th>
        </tr>

        <?php foreach ($employees as $emp): ?>
        <tr>
            <td><?= $emp['emp_id'] ?></td>
            <td><?= $emp['name'] ?></td>
            <td><?= $emp['dept_name'] ?></td>
            <td><?= $emp['designation'] ?></td>
            <td><?= $emp['email'] ?></td>
            <td>
                <a href="admin.php?page=employees&edit=<?= $emp['id'] ?>" class="btn btn-sm btn-warning">Edit</a>

                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="delete_employee">
                    <input type="hidden" name="id" value="<?= $emp['id'] ?>">
                    <button class="btn btn-sm btn-danger"
                        onclick="return confirm('Delete this employee?')">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php endif; ?>


<!-- ================= ATTENDANCE PAGE ================= -->
<?php if ($page == 'attendance'): ?>

<h2 class="mb-3">Attendance Records</h2>

<div class="card p-3">
    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>Employee</th>
                <th>Check In</th>
                <th>Check Out</th>
                <th>Status</th>
                <th>Selfies</th>
                <th>Location</th>
            </tr>
        </thead>

        <tbody>
        <?php foreach ($records as $rec): ?>
            <tr>
                <td><?= $rec['emp_id'] ?> - <?= $rec['name'] ?></td>
                <td><?= $rec['check_in'] ?></td>
                <td><?= $rec['check_out'] ?></td>
                <td>
                    <?= $rec['status'] ?>
                    <?php if ($rec['is_outside_zone']): ?>
                        <span class="badge bg-danger">Outside Zone</span>
                    <?php endif; ?>
                </td>

                <!-- SELFIE IMAGES -->
                <td>
                    <?php if ($rec['in_selfie']): ?>
                        <img src="uploads/<?= $rec['in_selfie'] ?>" 
                             onclick="showImage('uploads/<?= $rec['in_selfie'] ?>')" 
                             style="width:55px; height:55px; object-fit:cover; border-radius:6px; cursor:pointer; margin-right:5px;">
                    <?php endif; ?>

                    <?php if ($rec['out_selfie']): ?>
                        <img src="uploads/<?= $rec['out_selfie'] ?>" 
                             onclick="showImage('uploads/<?= $rec['out_selfie'] ?>')" 
                             style="width:55px; height:55px; object-fit:cover; border-radius:6px; cursor:pointer;">
                    <?php endif; ?>
                </td>

                <!-- LOCATION VIEW -->
                <td>
                    <?php if ($rec['in_lat'] && $rec['in_lng']): ?>
                        <a href="https://www.google.com/maps/?q=<?= $rec['in_lat'] ?>,<?= $rec['in_lng'] ?>" 
                           target="_blank" class="btn btn-sm btn-primary">
                           View Map
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php endif; ?>



<!-- ================= LEAVES PAGE ================= -->
<?php if ($page === "leaves"): ?>
<h2 class="mb-4">Leave Requests</h2>

<div class="card p-4 mb-4">
    <form method="GET" class="row">
        <input type="hidden" name="page" value="leaves">
        <div class="col-md-4 mb-3">
            <label>Search Employee</label>
            <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-md-4 mb-3">
            <label>Status</label>
            <select name="status" class="form-control">
                <option value="">All</option>
                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
        </div>
        <div class="col-md-4 d-flex align-items-end">
            <button class="btn btn-primary w-100">Filter</button>
        </div>
    </form>
</div>

<div class="card p-4">
    <table class="table table-bordered">
        <tr>
            <th>Employee</th>
            <th>Leave Type</th>
            <th>Period</th>
            <th>Reason</th>
            <th>Document</th>
            <th>Status</th>
            <th>Action</th>
        </tr>

        <?php foreach ($leaves as $l): ?>
        <tr>
            <td><?= $l['employee_name'] ?></td>
            <td><?= $l['leave_type'] ?? 'N/A' ?></td>
            <td><?= $l['start_date'] ?> → <?= $l['end_date'] ?></td>
            <td><?= $l['reason'] ?></td>
            <td>
                <?php if (!empty($l['document'])): ?>
                    <a href="uploads/<?= $l['document'] ?>" target="_blank">View Document</a>
                <?php else: ?>
                    No Document
                <?php endif; ?>
            </td>
            <td><?= $l['status'] ?></td>
            <td>
                <?php if ($l['status'] === "pending"): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="approve_leave">
                    <input type="hidden" name="id" value="<?= $l['id'] ?>">
                    <button class="btn btn-success btn-sm">Approve</button>
                </form>

                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="reject_leave">
                    <input type="hidden" name="id" value="<?= $l['id'] ?>">
                    <button class="btn btn-danger btn-sm">Reject</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>


<!-- ================= REPORTS PAGE ================= -->
<?php if ($page === "reports"): ?>
<h2 class="mb-4">Attendance Reports</h2>

<div class="card p-4 mb-4">

    <form method="GET" class="row">
        <input type="hidden" name="page" value="reports">

        <!-- Period -->
        <div class="col-md-3">
            <label>Period</label>
            <select name="period" class="form-control">
                <option value="daily" <?= $period==='daily'?'selected':'' ?>>Daily</option>
                <option value="weekly" <?= $period==='weekly'?'selected':'' ?>>Weekly</option>
                <option value="monthly" <?= $period==='monthly'?'selected':'' ?>>Monthly</option>
            </select>
        </div>

        <!-- Department -->
        <div class="col-md-3">
            <label>Department</label>
            <select name="dept" class="form-control">
                <option value="0">All</option>
                <?php foreach ($departments as $d): ?>
                <option value="<?= $d['id'] ?>"
                        <?= $deptFilter==$d['id']?'selected':'' ?>>
                    <?= $d['name'] ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Employee -->
        <div class="col-md-3">
            <label>Employee</label>
            <select name="emp" class="form-control">
                <option value="0">All</option>
                <?php foreach ($allEmployees as $e): ?>
                <option value="<?= $e['id'] ?>"
                        <?= $empFilter==$e['id']?'selected':'' ?>>
                    <?= $e['name'] ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3 d-flex align-items-end">
            <button class="btn btn-primary w-100">Filter</button>
        </div>
    </form>

</div>


<div class="card p-4">
    <table class="table table-bordered">
        <tr>
            <th>Employee</th>
            <th>Department</th>
            <th>Present</th>
            <th>Absent</th>
        </tr>

        <?php foreach ($reports as $r): ?>
        <tr>
            <td><?= $r['name'] ?></td>
            <td><?= $r['department'] ?></td>
            <td><?= $r['present_days'] ?></td>
            <td><?= $r['absent_days'] ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <a href="admin.php?page=reports&period=<?= $period ?>&dept=<?= $deptFilter ?>&emp=<?= $empFilter ?>&export=csv" 
       class="btn btn-success">
       Export CSV
    </a>
</div>

<?php endif; ?>
<!-- ================= SETTINGS PAGE ================= -->
<?php if ($page === "settings"): ?>

<h2 class="mb-4">System Settings</h2>

<!-- GEO-FENCE SETTINGS -->
<div class="card p-4 mb-4">
    <h5>Office Geo-Fence</h5>

    <form method="POST" class="row mt-3">
        <input type="hidden" name="action" value="update_geo">

        <div class="col-md-4">
            <label>Latitude</label>
            <input type="text" class="form-control" name="lat" value="<?= $settings['office_lat'] ?>">
        </div>

        <div class="col-md-4">
            <label>Longitude</label>
            <input type="text" class="form-control" name="lng" value="<?= $settings['office_lng'] ?>">
        </div>

        <div class="col-md-4">
            <label>Allowed Radius (meters)</label>
            <input type="number" class="form-control" name="radius" value="<?= $settings['radius'] ?>">
        </div>

        <div class="col-md-12 mt-3">
            <button class="btn btn-primary w-100">Update Geo Settings</button>
        </div>
    </form>
</div>


<!-- HOLIDAYS -->
<div class="card p-4 mb-4">
    <h5>Add Holiday</h5>

    <form method="POST" class="row mt-3">
        <input type="hidden" name="action" value="add_holiday">

        <div class="col-md-4">
            <label>Date</label>
            <input type="date" class="form-control" name="date" required>
        </div>

        <div class="col-md-8">
            <label>Description</label>
            <input class="form-control" name="description" required>
        </div>

        <div class="col-12 mt-3">
            <button class="btn btn-primary w-100">Add Holiday</button>
        </div>
    </form>

    <table class="table table-bordered mt-3">
        <tr><th>Date</th><th>Description</th></tr>
        <?php foreach ($holidays as $h): ?>
        <tr>
            <td><?= $h['date'] ?></td>
            <td><?= $h['description'] ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<!-- SHIFTS -->
<div class="card p-4 mb-4">
    <h5>Add Shift</h5>

    <form method="POST" class="row mt-3">
        <input type="hidden" name="action" value="add_shift">

        <div class="col-md-4">
            <label>Shift Name</label>
            <input class="form-control" name="name" required>
        </div>

        <div class="col-md-4">
            <label>Start Time</label>
            <input type="time" class="form-control" name="start_time" required>
        </div>

        <div class="col-md-4">
            <label>End Time</label>
            <input type="time" class="form-control" name="end_time" required>
        </div>

        <div class="col-12 mt-3">
            <button class="btn btn-primary w-100">Add Shift</button>
        </div>
    </form>

    <table class="table table-bordered mt-3">
        <tr><th>Name</th><th>Start</th><th>End</th></tr>
        <?php foreach ($shifts as $s): ?>
        <tr>
            <td><?= $s['name'] ?></td>
            <td><?= $s['start_time'] ?></td>
            <td><?= $s['end_time'] ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php endif; ?>



<!-- IMAGE POPUP (Bootstrap Modal) -->
<div class="modal fade" id="imgModal">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <img id="modalImg" src="" style="width:100%; border-radius:10px;">
    </div>
  </div>
</div>

<script>
function showImage(src) {
    document.getElementById("modalImg").src = src;
    let myModal = new bootstrap.Modal(document.getElementById('imgModal'));
    myModal.show();
}


   // Load notifications
function loadNotifications() {
    fetch("notification.php")
        .then(res => res.json())
        .then(data => {
            let count = data.length;
            let badge = document.getElementById("notifCount");
            let dropdown = document.getElementById("notifDropdown");

            // badge count
            badge.innerText = count > 0 ? count : "";

            // dropdown list
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

// auto refresh every 5 seconds
setInterval(loadNotifications, 5000);
loadNotifications();

   




</script>


</div> <!-- END CONTENT -->

<!-- BOOTSTRAP JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>