<?php
session_start([
    'cookie_httponly' => true,
    'cookie_secure'   => isset($_SERVER['HTTPS']),
    'use_strict_mode' => true,
]);

// Load config
$cfg = require __DIR__ . '/config.php';

// DB connect
try {
    $pdo = new PDO(
        $cfg['db']['dsn'],
        $cfg['db']['user'],
        $cfg['db']['pass'],
        $cfg['db']['options']
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit("Database connection failed.");
}

// Base URL
function base_url($path = '') {
    static $base;
    if ($base === null) {
        $base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' .
                $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
    }
    return $base . ltrim($path, '/');
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . base_url());
    exit;
}

// Login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {

    // CSRF check
    if (!hash_equals($_SESSION['csrf_login'] ?? '', $_POST['csrf'] ?? '')) {
        die("Security token mismatch!");
    }

    $stmt = $pdo->prepare("SELECT id, username, password, role, emp_id FROM users WHERE username=?");
    $stmt->execute([$_POST['username']]);
    $user = $stmt->fetch();

    if ($user && password_verify($_POST['password'], $user['password'])) {

        // USER SESSION
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['role']     = $user['role'];

        // Store the employee primary id from employees table
        $_SESSION['emp_id'] = $user['emp_id'];

        // redirect based on role
        if ($user['role'] === 'admin' || $user['role'] === 'hr') {
            $redirect = "admin.php";
        } else {
            $redirect = "employee.php";
        }

        header("Location: " . base_url($redirect));
        exit;
    }

    $error = "Invalid username or password.";
}


// Logged in user auto-redirect
if (isset($_SESSION['user_id'])) {
    $redirect = ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'hr') ? "admin.php" : "employee.php";
    header("Location: " . base_url($redirect));
    exit;
}

// Generate CSRF
$_SESSION['csrf_login'] = bin2hex(random_bytes(16));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HRMS Login</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            background: #e9f0ff;
            font-family: "Poppins", sans-serif;
        }
        .login-card {
            max-width: 430px;
            margin: auto;
            margin-top: 8%;
            background: #fff;
            padding: 35px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
        }
        .brand-title {
            font-weight: 700;
            font-size: 1.8rem;
        }
        .btn-primary {
            background: #0d6efd;
        }
        .icon-box {
            font-size: 3rem;
            color: #0d6efd;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>

<div class="login-card text-center">

    <div class="icon-box">
        <i class="bi bi-fingerprint"></i>
    </div>

    <h3 class="brand-title">HRMS Attendance</h3>
    <p class="text-muted mb-4">Login to access your dashboard</p>

    <?php if (!empty($error)): ?>
    <div class="alert alert-danger text-start">
        <i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">

        <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_login'] ?>">
        <input type="hidden" name="login" value="1">

        <div class="mb-3 text-start">
            <label class="form-label">Username</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-person"></i></span>
                <input type="text" name="username" class="form-control" required autofocus>
            </div>
        </div>

        <div class="mb-3 text-start">
            <label class="form-label">Password</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                <input type="password" name="password" class="form-control" required>
            </div>
        </div>

        <button class="btn btn-primary w-100 py-2">
            <i class="bi bi-box-arrow-in-right me-1"></i> Login
        </button>

    </form>

    <p class="text-muted mt-4">&copy; <?= date("Y") ?> HRMS Attendance System</p>
</div>

</body>
</html>