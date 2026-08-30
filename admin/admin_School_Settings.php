<?php
require_once __DIR__ . '/../functions/bootstrap.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    if (!headers_sent()) {
        header("Location: ../auth/login.php");
    } else {
        echo '<script>window.location.href="../auth/login.php";</script>';
    }
    exit();
}

$database = new Database();
$db = $database->getConnection();

$schoolSettings = getSchoolSettings($db);

$schoolName = $schoolSettings['school_name'] ?? 'Balingasag Senior High School';
$schoolId = $schoolSettings['school_id'] ?? '341227';
$schoolHead = $schoolSettings['school_head'] ?? '';
$region = $schoolSettings['region'] ?? 'Region X';
$division = $schoolSettings['division'] ?? 'Misamis Oriental';
$district = $schoolSettings['district'] ?? 'Balingasag North';
$schoolAddress = $schoolSettings['school_address'] ?? 'Balingasag, Misamis Oriental, Philippines';
$contactEmail = $schoolSettings['contact_email'] ?? 'balingasagshs@deped.gov.ph';
$contactNumber = $schoolSettings['contact_number'] ?? '';
$officeHours = $schoolSettings['office_hours'] ?? 'Monday – Friday: 7:00 AM – 5:00 PM';

$websiteContent = [];
if ($db instanceof PDO) {
    try {
        $wcStmt = $db->query("SELECT section_key, title, content FROM website_content");
        while ($r = $wcStmt->fetch(PDO::FETCH_ASSOC)) {
            $websiteContent[$r['section_key']] = $r;
        }
    } catch (Throwable $e) {
    }
}

$heroTitle = $websiteContent['hero_title']['title'] ?? ('Welcome to ' . $schoolName);
$heroSubtitle = $websiteContent['hero_title']['content'] ?? ('Nurturing excellence, building futures. A DepEd-accredited Senior High School in ' . $division . ', ' . $region . '.');
$announcementsTagline = $websiteContent['announcements_heading']['content'] ?? ('Important advisories, activities, and school notices posted for the ' . $schoolName . ' community.');
$aboutTitle = $websiteContent['about']['title'] ?? 'About Our School';
$aboutContent = $websiteContent['about']['content'] ?? ($schoolName . ' is committed to providing quality education for Senior High School students in the municipality of ' . $division . '. We offer various tracks and strands aligned with the K to 12 curriculum of the Department of Education.');

$statusMsg = trim((string)($_GET['status'] ?? ''));
$errorMsg = trim((string)($_GET['error'] ?? ''));

$current_role = 'admin';
$current_page = 'school_settings';
$page_title = 'School Settings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?> - Balingasag Senior High School</title>
    <link href="<?php echo appAssetPath('vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo appAssetPath('vendor/bootstrap-icons/bootstrap-icons.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/main.css'); ?>">
    <link rel="stylesheet" href="<?php echo appAssetPath('css/role.css'); ?>">
<?php echo pwaHeadHtml(); ?>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include '../includes/header.php'; ?>
        <div class="page-content">
            <div class="container-fluid p-0">
                <section class="admin-hero admin-hero-compact mb-4">
                    <div class="admin-hero-grid">
                        <div class="admin-hero-main">
                            <div class="welcome-role-chip"><i class="bi bi-building-gear"></i><span>DepEd Public School Profile</span></div>
                            <h4 class="mb-2">Manage School Profile & DepEd Jurisdiction</h4>
                            <p class="text-muted mb-3">Configure official school details, DepEd division/region metadata, and contact info used across SF1, SF2, ECR reports, and the public website.</p>
                            <div class="admin-chip-row">
                                <span class="admin-chip"><i class="bi bi-upc-scan"></i>School ID: <strong><?php echo htmlspecialchars($schoolId); ?></strong></span>
                                <span class="admin-chip"><i class="bi bi-geo-alt"></i><?php echo htmlspecialchars($division); ?> (<?php echo htmlspecialchars($region); ?>)</span>
                            </div>
                        </div>
                    </div>
                </section>

                <?php if ($statusMsg === 'saved'): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>School settings updated successfully! Changes are now reflected across official reports and the website.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($errorMsg !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($errorMsg); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="admin_School_Settings_Action.php" id="schoolSettingsForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

                    <div class="row g-4">
                        <div class="col-lg-8">
                            <!-- School Seal & Logo Card -->
                            <div class="content-card mb-4">
                                <div class="content-card-header d-flex align-items-center gap-2">
                                    <i class="bi bi-image text-primary fs-5"></i>
                                    <h5 class="content-card-title mb-0">School Seal & Brand Logo</h5>
                                </div>
                                <div class="content-card-body">
                                    <div class="d-flex flex-column flex-sm-row align-items-center gap-4">
                                        <div class="position-relative text-center">
                                            <div class="rounded-circle border p-1 bg-white shadow-sm d-inline-flex align-items-center justify-content-center" style="width: 90px; height: 90px;">
                                                <img src="../assets/images/bshs-logo.jpg?t=<?php echo time(); ?>" alt="Current School Logo" id="currentLogoPreview" class="rounded-circle" style="width: 80px; height: 80px; object-fit: cover;">
                                            </div>
                                            <div class="small text-muted mt-1">Current Logo</div>
                                        </div>
                                        <div class="flex-grow-1 w-100">
                                            <label for="school_logo" class="form-label fw-semibold">Upload New Official Logo / Seal</label>
                                            <input type="file" class="form-control" id="school_logo" name="school_logo" accept="image/png, image/jpeg, image/webp">
                                            <div class="form-text mt-2">
                                                <i class="bi bi-info-circle me-1"></i>Supports <strong>PNG, JPG, or WEBP</strong> up to 5MB. Square aspect ratio (e.g. 512×512) recommended. Automatically updates the portal header, login screens, and regenerates PWA mobile app icons.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- School Identification Card -->
                            <div class="content-card mb-4">
                                <div class="content-card-header d-flex align-items-center gap-2">
                                    <i class="bi bi-building text-primary fs-5"></i>
                                    <h5 class="content-card-title mb-0">School Identification</h5>
                                </div>
                                <div class="content-card-body">
                                    <div class="row g-3">
                                        <div class="col-md-8">
                                            <label for="school_name" class="form-label fw-semibold">Official School Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="school_name" name="school_name" value="<?php echo htmlspecialchars($schoolName); ?>" required placeholder="e.g. Balingasag Senior High School">
                                            <div class="form-text">Displayed on report headers, web portal brand, and official documents.</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="school_id" class="form-label fw-semibold">DepEd School ID <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="school_id" name="school_id" value="<?php echo htmlspecialchars($schoolId); ?>" required maxlength="12" placeholder="e.g. 341227">
                                            <div class="form-text">6-digit official DepEd identification code.</div>
                                        </div>
                                        <div class="col-12">
                                            <label for="school_head" class="form-label fw-semibold">School Principal / School Head</label>
                                            <input type="text" class="form-control" id="school_head" name="school_head" value="<?php echo htmlspecialchars($schoolHead); ?>" placeholder="e.g. Maria Santos, EdD - Principal II">
                                            <div class="form-text">Included in official SF1/SF2 certification and approval blocks.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- DepEd Jurisdiction Card -->
                            <div class="content-card mb-4">
                                <div class="content-card-header d-flex align-items-center gap-2">
                                    <i class="bi bi-diagram-3 text-primary fs-5"></i>
                                    <h5 class="content-card-title mb-0">DepEd Administrative Jurisdiction</h5>
                                </div>
                                <div class="content-card-body">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label for="region" class="form-label fw-semibold">Region <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="region" name="region" value="<?php echo htmlspecialchars($region); ?>" required placeholder="e.g. Region X">
                                            <div class="form-text">DepEd Regional Office code (e.g. Region X, NCR).</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="division" class="form-label fw-semibold">Schools Division Office <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="division" name="division" value="<?php echo htmlspecialchars($division); ?>" required placeholder="e.g. Misamis Oriental">
                                            <div class="form-text">Division jurisdiction for SF1, SF2 & ECR.</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="district" class="form-label fw-semibold">District</label>
                                            <input type="text" class="form-control" id="district" name="district" value="<?php echo htmlspecialchars($district); ?>" placeholder="e.g. Balingasag North">
                                            <div class="form-text">School district name.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Location & Contact Information Card -->
                            <div class="content-card mb-4">
                                <div class="content-card-header d-flex align-items-center gap-2">
                                    <i class="bi bi-geo-alt text-primary fs-5"></i>
                                    <h5 class="content-card-title mb-0">Location & Contact Details</h5>
                                </div>
                                <div class="content-card-body">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label for="school_address" class="form-label fw-semibold">School Campus Address <span class="text-danger">*</span></label>
                                            <textarea class="form-control" id="school_address" name="school_address" rows="2" required placeholder="e.g. Balingasag, Misamis Oriental, Philippines"><?php echo htmlspecialchars($schoolAddress); ?></textarea>
                                            <div class="form-text">Physical address shown in public website and school reports.</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="contact_email" class="form-label fw-semibold">Official School Email</label>
                                            <input type="email" class="form-control" id="contact_email" name="contact_email" value="<?php echo htmlspecialchars($contactEmail); ?>" placeholder="e.g. balingasagshs@deped.gov.ph">
                                            <div class="form-text">Institutional DepEd or school inbox.</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="contact_number" class="form-label fw-semibold">Contact Telephone / Mobile</label>
                                            <input type="text" class="form-control" id="contact_number" name="contact_number" value="<?php echo htmlspecialchars($contactNumber); ?>" placeholder="e.g. (088) 123-4567 / 0912-345-6789">
                                        </div>
                                        <div class="col-12">
                                            <label for="office_hours" class="form-label fw-semibold">Office Hours</label>
                                            <input type="text" class="form-control" id="office_hours" name="office_hours" value="<?php echo htmlspecialchars($officeHours); ?>" placeholder="e.g. Monday – Friday: 7:00 AM – 5:00 PM">
                                            <div class="form-text">Displayed on the public website contact section.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Public Website Presentation Card -->
                            <div class="content-card mb-4">
                                <div class="content-card-header d-flex align-items-center gap-2">
                                    <i class="bi bi-globe2 text-primary fs-5"></i>
                                    <h5 class="content-card-title mb-0">Public Website Presentation</h5>
                                </div>
                                <div class="content-card-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="website_hero_title" class="form-label fw-semibold">Welcome Headline</label>
                                            <input type="text" class="form-control" id="website_hero_title" name="website_hero_title" value="<?php echo htmlspecialchars($heroTitle); ?>" placeholder="e.g. Welcome to Balingasag Senior High School">
                                            <div class="form-text">Main headline on the public homepage hero section.</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="website_announcements_tagline" class="form-label fw-semibold">Announcements Tagline</label>
                                            <input type="text" class="form-control" id="website_announcements_tagline" name="website_announcements_tagline" value="<?php echo htmlspecialchars($announcementsTagline); ?>" placeholder="e.g. Important advisories, activities, and school notices...">
                                            <div class="form-text">Subtitle text above the announcements list.</div>
                                        </div>
                                        <div class="col-12">
                                            <label for="website_hero_subtitle" class="form-label fw-semibold">Hero Subtitle / Mission Tagline</label>
                                            <textarea class="form-control" id="website_hero_subtitle" name="website_hero_subtitle" rows="2" placeholder="e.g. Nurturing excellence, building futures..."><?php echo htmlspecialchars($heroSubtitle); ?></textarea>
                                            <div class="form-text">Supporting statement below the welcome headline.</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="website_about_title" class="form-label fw-semibold">About Section Title</label>
                                            <input type="text" class="form-control" id="website_about_title" name="website_about_title" value="<?php echo htmlspecialchars($aboutTitle); ?>" placeholder="e.g. About Our School">
                                        </div>
                                        <div class="col-12">
                                            <label for="website_about_content" class="form-label fw-semibold">About the School Overview</label>
                                            <textarea class="form-control" id="website_about_content" name="website_about_content" rows="3" placeholder="Description of school background, programs, and DepEd alignment..."><?php echo htmlspecialchars($aboutContent); ?></textarea>
                                            <div class="form-text">Appears in the "About Our School" section of the landing page.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end gap-2 mb-4">
                                <button type="reset" class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</button>
                                <button type="submit" class="btn btn-primary-custom"><i class="bi bi-check-lg me-1"></i>Save School Information</button>
                            </div>
                        </div>

                        <!-- Sidebar Summary & Form Standards Card -->
                        <div class="col-lg-4">
                            <div class="content-card mb-4 sticky-top" style="top: 80px; z-index: 5;">
                                <div class="content-card-header">
                                    <h5 class="content-card-title mb-0"><i class="bi bi-eye me-1 text-primary"></i>Live DepEd Header Preview</h5>
                                </div>
                                <div class="content-card-body">
                                    <div class="p-3 bg-light rounded border mb-3">
                                        <div class="text-center small text-muted text-uppercase mb-1" style="font-size: 0.75rem; letter-spacing: 0.05em;">Republic of the Philippines</div>
                                        <div class="text-center fw-bold text-uppercase mb-1" style="font-size: 0.85rem;">Department of Education</div>
                                        <div class="text-center small text-primary fw-semibold" id="previewRegion"><?php echo htmlspecialchars($region); ?></div>
                                        <div class="text-center small text-muted" id="previewDivision">Division of <?php echo htmlspecialchars($division); ?></div>
                                        <hr class="my-2">
                                        <div class="text-center fw-bold text-primary" id="previewSchoolName"><?php echo htmlspecialchars($schoolName); ?></div>
                                        <div class="text-center small text-muted">School ID: <strong id="previewSchoolId"><?php echo htmlspecialchars($schoolId); ?></strong> · District: <span id="previewDistrict"><?php echo htmlspecialchars($district); ?></span></div>
                                    </div>

                                    <div class="alert alert-info py-2 px-3 small mb-0">
                                        <i class="bi bi-info-circle me-1"></i><strong>Automated Sync:</strong> Updating this form automatically updates official header cells in SF1, SF2, ECR workbooks and the public site footer.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include '../includes/modals.php'; ?>
    <script src="<?php echo appAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
    <script src="<?php echo appAssetPath('js/main.js'); ?>"></script>
    <script>
    (function () {
        const nameInput = document.getElementById('school_name');
        const idInput = document.getElementById('school_id');
        const regionInput = document.getElementById('region');
        const divisionInput = document.getElementById('division');
        const districtInput = document.getElementById('district');

        const prevName = document.getElementById('previewSchoolName');
        const prevId = document.getElementById('previewSchoolId');
        const prevRegion = document.getElementById('previewRegion');
        const prevDivision = document.getElementById('previewDivision');
        const prevDistrict = document.getElementById('previewDistrict');

        function updatePreview() {
            if (prevName && nameInput) prevName.textContent = nameInput.value || 'School Name';
            if (prevId && idInput) prevId.textContent = idInput.value || '------';
            if (prevRegion && regionInput) prevRegion.textContent = regionInput.value || 'Region';
            if (prevDivision && divisionInput) prevDivision.textContent = 'Division of ' + (divisionInput.value || 'Division');
            if (prevDistrict && districtInput) prevDistrict.textContent = districtInput.value || 'District';
        }

        [nameInput, idInput, regionInput, divisionInput, districtInput].forEach(function (inp) {
            if (inp) {
                inp.addEventListener('input', updatePreview);
            }
        });

        const logoInput = document.getElementById('school_logo');
        const logoPreview = document.getElementById('currentLogoPreview');
        if (logoInput && logoPreview) {
            logoInput.addEventListener('change', function () {
                const file = this.files && this.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function (e) {
                        logoPreview.src = e.target.result;
                    };
                    reader.readAsDataURL(file);
                }
            });
        }
    })();
    </script>
</body>
</html>