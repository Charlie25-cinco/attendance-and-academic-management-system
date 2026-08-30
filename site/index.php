<?php
require_once __DIR__ . '/../functions/bootstrap.php';

$db = null;
try {
    $db = (new Database())->getConnection();
} catch (Throwable $e) {
}

$announcements = [];
$websiteContent = [];

if ($db) {
    try {
        $stmt = $db->query("SELECT section_key, title, content FROM website_content ORDER BY id");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $websiteContent[$row['section_key']] = $row;
        }
    } catch (Throwable $e) {
    }

    try {
        $stmt = $db->query("SELECT a.id, a.title, a.content, a.category, a.created_at,
                            CONCAT(u.first_name, ' ', u.last_name) as posted_by
                            FROM announcements a
                            LEFT JOIN users u ON u.id = a.posted_by
                            WHERE a.status = 'active' AND a.show_on_website = 1
                            ORDER BY a.created_at DESC
                            LIMIT 6");
        $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
    }
}

function wc($db, $key, $field = 'content', $default = '')
{
    global $websiteContent;
    return htmlspecialchars($websiteContent[$key][$field] ?? $default);
}

$loginUrl = '../auth/login.php';
$logoPath = '../assets/images/bshs-logo.jpg';
$bootstrapCss = '../assets/vendor/bootstrap/bootstrap.min.css';
$bootstrapIconsCss = '../assets/vendor/bootstrap-icons/bootstrap-icons.css';
$bootstrapJs = '../assets/vendor/bootstrap/bootstrap.bundle.min.js';
$siteCss = '../assets/css/Site.css';

$siteSchoolName = 'Balingasag Senior High School';
$siteRegion = 'Region X';
$siteDivision = 'Misamis Oriental';
if ($db instanceof PDO && function_exists('getSchoolSetting')) {
    $dbName = getSchoolSetting($db, 'school_name', '');
    if ($dbName !== '') {
        $siteSchoolName = $dbName;
    }
    $dbReg = getSchoolSetting($db, 'region', '');
    if ($dbReg !== '') {
        $siteRegion = $dbReg;
    }
    $dbDiv = getSchoolSetting($db, 'division', '');
    if ($dbDiv !== '') {
        $siteDivision = $dbDiv;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars($siteSchoolName); ?> public website and Academic Management System portal access.">
    <title><?php echo htmlspecialchars($siteSchoolName); ?></title>
    <link href="<?php echo $bootstrapCss; ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo $bootstrapIconsCss; ?>">
    <link rel="stylesheet" href="<?php echo $siteCss; ?>">
<?php echo pwaHeadHtml(); ?>
</head>
<body class="site-shell">
    <nav class="site-navbar navbar navbar-expand-lg">
        <div class="container site-container">
            <a class="navbar-brand" href="#top" aria-label="<?php echo htmlspecialchars($siteSchoolName); ?> home">
                <img src="<?php echo $logoPath; ?>" alt="School Logo">
                <span>
                    <small class="brand-kicker"><?php echo htmlspecialchars($siteSchoolName); ?></small>
                    <strong><?php echo htmlspecialchars($siteSchoolName); ?></strong>
                </span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#siteNav" aria-controls="siteNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="siteNav">
                <ul class="navbar-nav ms-auto me-lg-3">
                    <li class="nav-item"><a class="nav-link" href="#announcements">Updates</a></li>
                    <li class="nav-item"><a class="nav-link" href="#about">School</a></li>
                    <li class="nav-item"><a class="nav-link" href="#portal">Portal</a></li>
                    <li class="nav-item"><a class="nav-link" href="#contact">Contact</a></li>
                </ul>
                <a href="<?php echo $loginUrl; ?>" class="btn btn-login">
                    <i class="bi bi-box-arrow-in-right me-2"></i>AMS Login
                </a>
            </div>
        </div>
    </nav>

    <main id="top">
        <section class="hero-section">
            <div class="container site-container hero-content">
                <div class="hero-school-mark">
                    <img src="<?php echo $logoPath; ?>" alt="Balingasag SHS Logo">
                </div>
                <div class="hero-badge"><i class="bi bi-mortarboard-fill me-2"></i>Senior High School Academic Management</div>
                <h1 class="hero-title"><?php echo wc($db, 'hero_title', 'title', 'Welcome to Balingasag Senior High School'); ?></h1>
                <p class="hero-subtitle"><?php echo wc($db, 'hero_title', 'content', 'Nurturing excellence, building futures.'); ?></p>
                <div class="hero-actions">
                    <a href="<?php echo $loginUrl; ?>" class="btn btn-login btn-lg">
                        <i class="bi bi-grid-1x2-fill me-2"></i>Access Academic Portal
                    </a>
                    <a href="#announcements" class="btn btn-ghost-light btn-lg">
                        <i class="bi bi-megaphone me-2"></i>View Updates
                    </a>
                </div>
                <div class="hero-stats" aria-label="Portal highlights">
                    <div class="hero-stat">
                        <span>Role-Based</span>
                        <strong>Portal</strong>
                    </div>
                    <div class="hero-stat">
                        <span>DepEd Forms</span>
                        <strong>SF1 / SF2 / ECR</strong>
                    </div>
                    <div class="hero-stat">
                        <span>Family Access</span>
                        <strong>Parent Updates</strong>
                    </div>
                </div>
            </div>
        </section>

        <section class="announcement-section" id="announcements">
            <div class="container site-container">
                <div class="section-heading">
                    <span class="section-kicker">School updates</span>
                    <h2 class="section-title">Announcements & Events</h2>
                    <p class="section-copy">Important advisories, activities, and school notices posted for the Balingasag SHS community.</p>
                </div>
                <?php if (empty($announcements)): ?>
                <div class="empty-state">
                    <i class="bi bi-megaphone"></i>
                    <div>
                        <strong>No public announcements yet</strong>
                        <p>School website posts will appear here once published by the admin portal.</p>
                    </div>
                </div>
                <?php else: ?>
                <div class="row g-3 g-lg-4">
                    <?php foreach ($announcements as $ann): ?>
                    <div class="col-md-6 col-xl-4">
                        <article class="ann-card">
                            <div class="ann-meta-row">
                                <span class="ann-badge <?php echo htmlspecialchars($ann['category']); ?>"><?php echo ucfirst(htmlspecialchars($ann['category'])); ?></span>
                                <time class="ann-date" datetime="<?php echo htmlspecialchars(date('Y-m-d', strtotime($ann['created_at']))); ?>"><?php echo date('M d, Y', strtotime($ann['created_at'])); ?></time>
                            </div>
                            <h3 class="ann-title"><?php echo htmlspecialchars($ann['title']); ?></h3>
                            <p class="ann-content"><?php echo nl2br(htmlspecialchars(mb_substr($ann['content'], 0, 180) . (mb_strlen($ann['content']) > 180 ? '...' : ''))); ?></p>
                        </article>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="about-section" id="about">
            <div class="container site-container">
                <div class="row align-items-start g-4 g-lg-5">
                    <div class="col-lg-5">
                        <span class="section-kicker">About the school</span>
                        <h2 class="section-title"><?php echo wc($db, 'about', 'title', 'About Our School'); ?></h2>
                        <p class="about-copy"><?php echo nl2br(wc($db, 'about', 'content', "Balingasag Senior High School offers both Academic and TechPro tracks under the SSHS Strengthened Curriculum. We support learners with strong instruction, clear guidance, and school processes aligned to DepEd Order 74, s. 2025.")); ?></p>
                        <a href="<?php echo $loginUrl; ?>" class="btn btn-login">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Open Portal
                        </a>
                    </div>
                    <div class="col-lg-7">
                        <div class="feature-grid">
                            <div class="feature-card">
                                <div class="feature-icon"><i class="bi bi-book-half"></i></div>
                                <div class="feature-title">Academic &amp; TechPro Tracks</div>
                                <p>Supports Senior High School learners across academic and technical-professional pathways.</p>
                            </div>
                            <div class="feature-card">
                                <div class="feature-icon"><i class="bi bi-calendar-check"></i></div>
                                <div class="feature-title">Attendance Monitoring</div>
                                <p>Teachers record attendance while students and linked parents receive in-app updates.</p>
                            </div>
                            <div class="feature-card">
                                <div class="feature-icon"><i class="bi bi-clipboard-data"></i></div>
                                <div class="feature-title">Grades &amp; Reports</div>
                                <p>Grade workflows support admin review, adviser report cards, and final release to families.</p>
                            </div>
                            <div class="feature-card">
                                <div class="feature-icon"><i class="bi bi-file-earmark-spreadsheet"></i></div>
                                <div class="feature-title">DepEd Exports</div>
                                <p>SF1, SF2, and ECR exports remain available through the role-based system reports.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="portal-section" id="portal">
            <div class="container site-container">
                <div class="portal-panel">
                    <div>
                        <span class="section-kicker">Academic portal</span>
                        <h2 class="section-title">One access point for school operations</h2>
                        <p class="section-copy">Students, parents, teachers, and administrators use their assigned accounts to view the tools and records available to their role.</p>
                    </div>
                    <div class="portal-actions">
                        <a href="<?php echo $loginUrl; ?>" class="btn btn-login btn-lg">
                            <i class="bi bi-shield-lock-fill me-2"></i>Go to AMS Login
                        </a>
                        <p>Need an account? Contact the school office for your reference code and activation steps.</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="contact-section" id="contact">
            <div class="container site-container">
                <div class="row g-4 align-items-stretch">
                    <div class="col-lg-5">
                        <span class="section-kicker section-kicker-light">Get in touch</span>
                        <h2 class="section-title text-white"><?php echo wc($db, 'contact_address', 'title', 'Contact Information'); ?></h2>
                        <p class="contact-intro">For account access, records concerns, and school announcements, please coordinate with the school office.</p>
                    </div>
                    <div class="col-lg-7">
                        <div class="contact-grid">
                            <div class="contact-item">
                                <div class="contact-icon"><i class="bi bi-geo-alt-fill"></i></div>
                                <div>
                                    <div class="contact-label">Address</div>
                                    <div class="contact-value"><?php echo wc($db, 'contact_address', 'content', 'Balingasag, Misamis Oriental, Philippines'); ?></div>
                                </div>
                            </div>
                            <div class="contact-item">
                                <div class="contact-icon"><i class="bi bi-envelope-fill"></i></div>
                                <div>
                                    <div class="contact-label">Email</div>
                                    <div class="contact-value"><?php echo wc($db, 'contact_email', 'content', 'balingasagshs@deped.gov.ph'); ?></div>
                                </div>
                            </div>
                            <div class="contact-item">
                                <div class="contact-icon"><i class="bi bi-telephone-fill"></i></div>
                                <div>
                                    <div class="contact-label">Phone</div>
                                    <div class="contact-value"><?php echo wc($db, 'contact_phone', 'content', 'School contact available through the registrar or main office'); ?></div>
                                </div>
                            </div>
                            <div class="contact-item">
                                <div class="contact-icon"><i class="bi bi-clock-fill"></i></div>
                                <div>
                                    <div class="contact-label">Office Hours</div>
                                    <div class="contact-value"><?php echo wc($db, 'contact_hours', 'content', 'Monday - Friday: 7:00 AM - 5:00 PM'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="container site-container">
            <p class="mb-1">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($siteSchoolName); ?>. All rights reserved.</p>
            <p class="mb-0">Department of Education - <?php echo htmlspecialchars($siteRegion); ?> · <a href="<?php echo $loginUrl; ?>">Academic Management System</a></p>
        </div>
    </footer>

    <script src="<?php echo $bootstrapJs; ?>"></script>
    <script src="<?php echo appAssetPath('js/main.js'); ?>"></script>
    <script>
    document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
        anchor.addEventListener('click', function(event) {
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
                event.preventDefault();
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });
    </script>
</body>
</html>
