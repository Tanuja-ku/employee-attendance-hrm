<?php
session_start();
header("Content-Type: application/json");

require_once "config.php";

/* ============================
   DB CONNECTION
============================ */
try {
    $pdo = new PDO(
        $config['db']['dsn'],
        $config['db']['user'],
        $config['db']['pass'],
        $config['db']['options']
    );
} catch (Exception $e) {
    echo json_encode(["status" => "error", "msg" => "DB Connection Failed"]);
    exit;
}

$action = $_POST['action'] ?? null;

/* ============================
   JSON HELPER
============================ */
function send($x) {
    echo json_encode($x);
    exit;
}

/* ============================
   SAVE BASE64 IMAGE
============================ */
function saveBase64Image($base64, $prefix = "selfie") {

    if (!$base64 || strlen($base64) < 100) return null;

    $base64 = str_replace("data:image/png;base64,", "", $base64);
    $base64 = str_replace(" ", "+", $base64);

    $decoded = base64_decode($base64);

    if (!$decoded) return null;

    $filename = $prefix . "_" . time() . "_" . rand(1000,9999) . ".png";

    file_put_contents("uploads/" . $filename, $decoded);

    return $filename;
}

/* ============================
   EMPLOYEE CHECK-IN
============================ */
if ($action === "checkin") {

    if (!isset($_SESSION['emp_id'])) {
        send(["status" => "error", "msg" => "Not logged in"]);
    }

    $emp = $_SESSION['emp_id'];
    $lat = $_POST['lat'] ?? null;
    $lng = $_POST['lng'] ?? null;

    $selfie = saveBase64Image($_POST['selfie'] ?? "", "in");

    $stmt = $pdo->prepare("
        INSERT INTO attendance (emp_id, check_in, in_lat, in_lng, in_selfie)
        VALUES (?, NOW(), ?, ?, ?)
    ");

    $stmt->execute([$emp, $lat, $lng, $selfie]);

    send(["status" => "success", "msg" => "Check-in successful"]);
}

/* ============================
   EMPLOYEE CHECK-OUT
============================ */
if ($action === "checkout") {

    if (!isset($_SESSION['emp_id'])) {
        send(["status" => "error", "msg" => "Not logged in"]);
    }

    $emp = $_SESSION['emp_id'];
    $lat = $_POST['lat'] ?? null;
    $lng = $_POST['lng'] ?? null;

    $selfie = saveBase64Image($_POST['selfie'] ?? "", "out");

    $stmt = $pdo->prepare("
        UPDATE attendance
        SET check_out = NOW(),
            out_lat = ?, 
            out_lng = ?, 
            out_selfie = ?
        WHERE emp_id = ? 
        AND check_out IS NULL
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([$lat, $lng, $selfie, $emp]);

    send(["status" => "success", "msg" => "Check-out successful"]);
}


/* ============================
   APPLY LEAVE (EMPLOYEE)
============================ */
if ($action === "apply_leave") {

    if (!isset($_SESSION['emp_id'])) {
        send(["status" => "error", "msg" => "Not logged in"]);
    }

    $emp      = $_SESSION['emp_id'];
    $leave_type = $_POST['leave_type'];
    $start    = $_POST['start_date'];
    $end      = $_POST['end_date'];
    $reason   = $_POST['reason'];

    /* Upload document */
    $document = null;
    if (isset($_FILES['document']) && $_FILES['document']['error'] === 0) {
        $fileTmpPath = $_FILES['document']['tmp_name'];
        $fileName = $_FILES['document']['name'];
        $destPath = "uploads/leave_doc_" . time() . "_" . rand(1000,9999) . "_" . basename($fileName);
        if (move_uploaded_file($fileTmpPath, $destPath)) {
            $document = basename($destPath);
        }
    }

    /* Insert leave */
    $stmt = $pdo->prepare("
        INSERT INTO leaves (emp_id, leave_type, start_date, end_date, reason, document)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$emp, $leave_type, $start, $end, $reason, $document]);

    /* Fetch employee name for better notification */
    $empInfo = $pdo->prepare("SELECT name FROM employees WHERE id=?");
    $empInfo->execute([$emp]);
    $empName = $empInfo->fetchColumn();

    /* Notify admin */
    $message = "$empName submitted a new leave request";
    $pdo->prepare("
        INSERT INTO notifications (user_id, message, link)
        VALUES (1, ?, 'admin.php?page=leaves')
    ")->execute([$message]);

    send(["status" => "success", "msg" => "Leave applied successfully"]);
}


/* ============================
   APPROVE LEAVE
============================ */
if ($action === "approve_leave") {

    $id = $_POST["id"];

    $pdo->prepare("
        UPDATE leaves
        SET status='approved', approved_by=?, approved_date=NOW()
        WHERE id=?
    ")->execute([$_SESSION["user_id"], $id]);

    /* Notify Employee */
    $leaveEmp = $pdo->prepare("SELECT emp_id FROM leaves WHERE id=?");
    $leaveEmp->execute([$id]);
    $eid = $leaveEmp->fetchColumn();

    $u = $pdo->prepare("SELECT id FROM users WHERE emp_id=?");
    $u->execute([$eid]);
    $userId = $u->fetchColumn();

    $pdo->prepare("
        INSERT INTO notifications (user_id, message, link)
        VALUES (?, 'Your leave request has been approved', 'myleave.php')
    ")->execute([$userId]);

    send(["status" => "success", "msg" => "Leave approved"]);
}


/* ============================
   REJECT LEAVE
============================ */
if ($action === "reject_leave") {

    $id = $_POST["id"];

    $pdo->prepare("
        UPDATE leaves
        SET status='rejected', approved_by=?, approved_date=NOW()
        WHERE id=?
    ")->execute([$_SESSION["user_id"], $id]);

    /* Notify Employee */
    $leaveEmp = $pdo->prepare("SELECT emp_id FROM leaves WHERE id=?");
    $leaveEmp->execute([$id]);
    $eid = $leaveEmp->fetchColumn();

    $u = $pdo->prepare("SELECT id FROM users WHERE emp_id=?");
    $u->execute([$eid]);
    $userId = $u->fetchColumn();

    $pdo->prepare("
        INSERT INTO notifications (user_id, message, link)
        VALUES (?, 'Your leave request has been rejected', 'myleave.php')
    ")->execute([$userId]);

    send(["status" => "success", "msg" => "Leave rejected"]);
}


/* ============================
   DELETE EMPLOYEE
============================ */
if ($action === "delete_employee") {

    $id = $_POST["id"];

    $pdo->prepare("DELETE FROM users WHERE emp_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM employees WHERE id=?")->execute([$id]);

    send(["status" => "success", "msg" => "Employee deleted"]);
}


/* ============================
   ADD EMPLOYEE
============================ */
if ($action === "add_employee") {

    $empId = $_POST["emp_id"];
    $name  = $_POST["name"];
    $dept  = $_POST["dept"];
    $desg  = $_POST["designation"];
    $email = $_POST["email"];
    $phone = $_POST["phone"];
    $shift = $_POST["shift"];

    $defaultPass = password_hash("emp123", PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO employees (emp_id, name, department_id, designation, email, phone, shift_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$empId, $name, $dept, $desg, $email, $phone, $shift]);

    $empPrimaryId = $pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO users (username, password, role, emp_id)
        VALUES (?, ?, 'employee', ?)
    ")->execute([$empId, $defaultPass, $empPrimaryId]);

    send(["status" => "success", "msg" => "Employee added successfully"]);
}


/* ============================
   UPDATE GEOFENCE
============================ */
if ($action === "update_settings") {

    $lat = $_POST["lat"];
    $lng = $_POST["lng"];
    $radius = $_POST["radius"];

    $pdo->prepare("
        UPDATE settings 
        SET office_lat=?, office_lng=?, radius=? 
        WHERE id=1
    ")
    ->execute([$lat, $lng, $radius]);

    send(["status" => "success", "msg" => "Settings updated"]);
}


/* ============================
   INVALID ACTION
============================ */
send(["status" => "error", "msg" => "Invalid action"]);
