<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
// Student QR Code Page
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'student') {
    header("Location: ../auth/login.php");
    exit();
}

$db = (new Database())->getConnection();
$userId = (int)($_SESSION['user_id'] ?? 0);

$user = [];
if ($db && $userId > 0) {
    $stmt = $db->prepare("SELECT reference_code, first_name, middle_name, last_name, grade_level, section, lrn FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

$current_role = 'student';
$current_page = 'qr_code';
$page_title = 'My QR Code';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?> - Balingasag Senior High School</title>
    <link href="<?php echo appAssetPath('vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo appAssetPath('vendor/bootstrap-icons/bootstrap-icons.css'); ?>">
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/role.css">
<?php echo pwaHeadHtml(); ?>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include '../includes/header.php'; ?>
        <div class="page-content">
            <div class="mb-4">
                <h4 class="mb-1">My QR Code</h4>
                <p class="text-muted mb-0">Present this QR code to your teacher for attendance scanning.</p>
            </div>

            <div class="row justify-content-center">
                <div class="col-md-6 col-lg-5">
                    <div class="content-card text-center">
                        <div class="content-card-header">
                            <h5 class="content-card-title"><i class="bi bi-qr-code me-2"></i>Attendance QR Code</h5>
                        </div>
                        <div class="content-card-body">
                            <div class="mb-3">
                                <div id="qrcode" style="display:inline-block;padding:16px;background:#fff;border-radius:12px;border:2px solid #dee2e6;"></div>
                            </div>

                            <div class="mb-3">
                                <div class="fw-bold fs-5"><?php
                                    $fn = htmlspecialchars($user['first_name'] ?? '');
                                    $mn = htmlspecialchars($user['middle_name'] ?? '');
                                    $ln = htmlspecialchars($user['last_name'] ?? '');
                                    echo trim("$fn $mn $ln");
                                ?></div>
                                <div class="text-muted font-monospace"><?php echo htmlspecialchars($user['reference_code'] ?? ''); ?></div>
                                <?php if (!empty($user['lrn'])): ?>
                                <div class="text-muted small">LRN: <?php echo htmlspecialchars($user['lrn']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($user['grade_level'])): ?>
                                <div class="badge bg-primary mt-1"><?php if (!empty($user['grade_level']) || !empty($user['section'])): ?><?php if (!empty($user['grade_level'])): ?>Grade <?php echo (int)$user['grade_level']; ?><?php endif; ?><?php if (!empty($user['grade_level']) && !empty($user['section'])): ?> - <?php endif; ?><?php echo htmlspecialchars((string)($user['section'] ?? '')); ?><?php else: ?>Grade/section not set<?php endif; ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="d-grid gap-2">
                                <button class="btn btn-primary-custom" onclick="downloadQR()">
                                    <i class="bi bi-download me-2"></i>Download QR Code
                                </button>
                                <button class="btn btn-outline-secondary" onclick="window.print()">
                                    <i class="bi bi-printer me-2"></i>Print QR Code
                                </button>
                            </div>

                            <div class="alert alert-info mt-3 mb-0 text-start" role="alert">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>How to use:</strong> Show this QR code to your subject teacher when recording attendance. Keep your screen brightness high for better scanning.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo appAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
    <script src="../assets/js/main.js"></script>
    <!-- QR Code generator -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
    const refCode = <?php echo json_encode($user['reference_code'] ?? ''); ?>;
    const studentName = <?php echo json_encode(trim(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))); ?>;

    let qrInstance = null;
    if (refCode && typeof QRCode !== 'undefined') {
        qrInstance = new QRCode(document.getElementById('qrcode'), {
            text: refCode,
            width: 220,
            height: 220,
            colorDark: '#1a3a6b',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.H
        });
    } else if (!refCode) {
        document.getElementById('qrcode').innerHTML = '<p class="text-danger">No reference code found.</p>';
    } else {
        document.getElementById('qrcode').innerHTML = '<p class="text-warning">QR library loading failed. Please refresh.</p>';
    }

    function downloadQR() {
        const canvas = document.getElementById('qrcode')?.querySelector('canvas');
        if (!canvas) {
            if (typeof showNotification === 'function') {
                showNotification('QR code not ready. Please wait and try again.', 'warning');
            }
            return;
        }
        const link = document.createElement('a');
        link.download = 'BSHS_QR_' + refCode + '.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    }
    </script>
    <style>
    @media print {
        .sidebar, .main-content > div:first-child, .page-content > .mb-4 { display:none!important; }
        .main-content { margin:0!important; padding:0!important; }
        .content-card { box-shadow:none!important; border:1px solid #ccc!important; }
    }
    </style>
</body>
</html>
