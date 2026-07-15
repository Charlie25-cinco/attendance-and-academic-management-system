<?php
require_once __DIR__ . '/../functions/bootstrap.php';
// Public School Website - Balingasag Senior High School

$db = null;
try {
    $db = (new Database())->getConnection();
} catch (Throwable $e) {}

$announcements = [];
$websiteContent = [];

if ($db) {
    try {
        $stmt = $db->query("SELECT section_key, title, content FROM website_content ORDER BY id");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $websiteContent[$row['section_key']] = $row;
        }
    } catch (Throwable $e) {}

    try {
        $stmt = $db->query("SELECT a.id, a.title, a.content, a.category, a.created_at,
                            CONCAT(u.first_name, ' ', u.last_name) as posted_by
                            FROM announcements a
                            LEFT JOIN users u ON u.id = a.posted_by
                            WHERE a.status = 'active' AND a.show_on_website = 1
                            ORDER BY a.created_at DESC
                            LIMIT 6");
        $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

function wc($db, $key, $field = 'content', $default = '') {
    global $websiteContent;
    return htmlspecialchars($websiteContent[$key][$field] ?? $default);
}

$loginUrl = '../auth/login.php';
$logoPath = '../assets/images/bshs-logo.jpg';
$bootstrapCss = '../assets/vendor/bootstrap/bootstrap.min.css';
$bootstrapIconsCss = '../assets/vendor/bootstrap-icons/bootstrap-icons.css';
$bootstrapJs = '../assets/vendor/bootstrap/bootstrap.bundle.min.js';
$siteCss = '../assets/css/Site.css';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balingasag Senior High School</title>
    <link href="<?php echo $bootstrapCss; ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo $bootstrapIconsCss; ?>">
    <link rel="stylesheet" href="<?php echo $siteCss; ?>">
</head>
<body class="site-shell">
    <nav class="site-navbar navbar navbar-expand-lg">
        <div class="container site-container">
            <a class="navbar-brand" href="#top">
                <img src="<?php echo $logoPath; ?>" alt="BSHS Logo">
                <span>
                    <small class="brand-kicker">Balingasag Senior High School</small>
                    <strong>Balingasag SHS</strong>
                </span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#siteNav" aria-controls="siteNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="siteNav">
                <ul class="navbar-nav ms-auto me-lg-3">
                    <li class="nav-item"><a class="nav-link" href="#about">About</a></li>
                    <li class="nav-item"><a class="nav-link" href="#announcements">Announcements</a></li>
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
            <div class="container site-container">
                <div class="row align-items-center g-5 hero-grid">
                    <div class="col-lg-7 text-center text-lg-start">
                        <div class="hero-badge"><i class="bi bi-stars me-2"></i>Academic &amp; TechPro track management</div>
                        <h1 class="hero-title"><?php echo wc($db, 'hero_title', 'title', 'Welcome to Balingasag Senior High School'); ?></h1>
                        <p class="hero-subtitle"><?php echo wc($db, 'hero_title', 'content', 'Nurturing excellence, building futures.'); ?></p>
                        <div class="cta-badges justify-content-center justify-content-lg-start">
                            <span class="cta-badge"><i class="bi bi-mortarboard"></i>Senior High School</span>
                            <span class="cta-badge"><i class="bi bi-journal-check"></i>SSHS Strengthened Curriculum</span>
                            <span class="cta-badge"><i class="bi bi-shield-lock"></i>Secure role-based portal</span>
                        </div>
                        <div class="hero-actions d-flex gap-3 flex-wrap justify-content-center justify-content-lg-start">
                            <a href="<?php echo $loginUrl; ?>" class="btn btn-login btn-lg">
                                <i class="bi bi-grid-1x2-fill me-2"></i>Access Academic Portal
                            </a>
                            <a href="#about" class="btn btn-outline-light btn-lg hero-secondary-btn">Learn More</a>
                        </div>
                        <div class="hero-stats justify-content-center justify-content-lg-start">
                            <div class="hero-stat">
                                <div class="hero-stat-num">K-12</div>
                                <div class="hero-stat-label">Curriculum</div>
                            </div>
                            <div class="hero-stat">
                                <div class="hero-stat-num">SF2</div>
                                <div class="hero-stat-label">Attendance Ready</div>
                            </div>
                            <div class="hero-stat">
                                <div class="hero-stat-num">ECR</div>
                                <div class="hero-stat-label">Reporting Support</div>
                            </div>
                        </div>
                        <div class="hero-panel mx-auto mx-lg-0">
                            <div class="panel-label">School Community</div>
                            <div class="panel-value">Connected learning for students, parents, teachers, and administrators</div>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="hero-visual-wrap">
                            <div class="hero-visual-card">
                                <img src="<?php echo $logoPath; ?>" alt="Balingasag SHS Logo" class="hero-logo">
                                <div class="hero-visual-copy">
                                    <p class="hero-visual-kicker">Digital school operations</p>
                                    <h2>Attendance, grading, records, and communication in one system.</h2>
                                    <p>Built for the school workflows you outlined, with cleaner access for each role and export support for official forms.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="announcement-section" id="announcements">
            <div class="container site-container">
                <div class="section-heading text-center">
                    <span class="section-kicker">School updates</span>
                    <div class="section-title">Announcements & Events</div>
                    <div class="section-divider mx-auto"></div>
                    <p class="section-copy">Stay updated with the latest announcements, advisories, and activities from Balingasag SHS.</p>
                </div>
                <?php if (empty($announcements)): ?>
                <div class="empty-state text-center">
                    <i class="bi bi-megaphone"></i>
                    <p>No announcements posted yet.</p>
                </div>
                <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($announcements as $ann): ?>
                    <div class="col-md-6 col-xl-4">
                        <article class="ann-card">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <span class="ann-badge <?php echo htmlspecialchars($ann['category']); ?>"><?php echo ucfirst(htmlspecialchars($ann['category'])); ?></span>
                                <span class="ann-date"><?php echo date('M d, Y', strtotime($ann['created_at'])); ?></span>
                            </div>
                            <div class="ann-title"><?php echo htmlspecialchars($ann['title']); ?></div>
                            <div class="ann-content"><?php echo nl2br(htmlspecialchars(mb_substr($ann['content'], 0, 180) . (mb_strlen($ann['content']) > 180 ? '...' : ''))); ?></div>
                        </article>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="about-section" id="about">
            <div class="container site-container">
                <div class="row align-items-center g-5">
                    <div class="col-lg-6">
                        <span class="section-kicker">About the school</span>
                        <div class="section-title"><?php echo wc($db, 'about', 'title', 'About Our School'); ?></div>
                        <div class="section-divider"></div>
                        <p class="about-copy"><?php echo nl2br(wc($db, 'about', 'content', "Balingasag Senior High School offers both Academic and TechPro tracks under the SSHS Strengthened Curriculum. We support learners with strong instruction, clear guidance, and school processes aligned to DepEd Order 74, s. 2025.")); ?></p>
                        <a href="<?php echo $loginUrl; ?>" class="btn btn-login mt-3">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Access the Academic Portal
                        </a>
                    </div>
                    <div class="col-lg-6">
                        <div class="feature-grid">
                            <div class="feature-card">
                                <div class="feature-icon"><i class="bi bi-book-half"></i></div>
                                <div class="feature-title">Academic &amp; TechPro Tracks</div>
                                <p>Two pathways — Academic and Technical-Professional — with core subjects and elective clusters under the SSHS Strengthened Curriculum (SY 2026–2027).</p>
                            </div>
                            <div class="feature-card">
                                <div class="feature-icon"><i class="bi bi-person-video3"></i></div>
                                <div class="feature-title">Student Portal</div>
                                <p>Students can review grades, attendance, class details, and required academic information online.</p>
                            </div>
                            <div class="feature-card">
                                <div class="feature-icon"><i class="bi bi-people"></i></div>
                                <div class="feature-title">Parent Access</div>
                                <p>Parents can monitor linked student progress and stay informed through the system portal.</p>
                            </div>
                            <div class="feature-card">
                                <div class="feature-icon"><i class="bi bi-clipboard-data"></i></div>
                                <div class="feature-title">DepEd DM 74 Reporting</div>
                                <p>Attendance (SF2), grading weights per subject category, and school-year records managed with DM 74, s. 2025 reporting workflows.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="contact-section" id="contact">
            <div class="container site-container">
                <div class="row g-5 align-items-stretch">
                    <div class="col-lg-6">
                        <span class="section-kicker section-kicker-light">Get in touch</span>
                        <div class="section-title text-white"><?php echo wc($db, 'contact_address', 'title', 'Contact Information'); ?></div>
                        <div class="section-divider"></div>
                        <div class="contact-stack">
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
                    <div class="col-lg-6 d-flex align-items-center">
                        <div class="login-cta w-100">
                            <div class="cta-badges justify-content-center">
                                <span class="cta-badge cta-badge-dark"><i class="bi bi-person-badge"></i>Reference code</span>
                                <span class="cta-badge cta-badge-dark"><i class="bi bi-lock"></i>Secure login</span>
                            </div>
                            <i class="bi bi-shield-lock-fill login-cta-icon"></i>
                            <h4>Access the Academic Portal</h4>
                            <p>Teachers, students, parents, and administrators can log in to use the full Academic Management System.</p>
                            <a href="<?php echo $loginUrl; ?>" class="btn btn-login btn-lg w-100">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Go to AMS Login
                            </a>
                            <div class="cta-note">
                                Don&apos;t have an account yet? Contact the school to receive your reference code and activation steps.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="container site-container">
            <p class="mb-1">&copy; <?php echo date('Y'); ?> Balingasag Senior High School. All rights reserved. Department of Education - Region X.</p>
            <p class="mb-0"><a href="<?php echo $loginUrl; ?>">Academic Management System</a></p>
        </div>
    </footer>

    <script src="<?php echo $bootstrapJs; ?>"></script>
    <script src="../assets/js/main.js"></script>
    <script>
    document.querySelectorAll('a[href^="#"]').forEach(a => {
        a.addEventListener('click', function(e) {
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });
    </script>
</body>
</html>
