<!-- Shared Modals -->
<div class="modal fade" id="appConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-header app-modal-header">
                <div>
                    <div class="app-modal-kicker" data-confirm-kicker><i class="bi bi-question-circle-fill"></i>Confirmation</div>
                    <h5 class="modal-title mb-0" data-confirm-title>Please confirm</h5>
                    <p class="app-modal-subtitle" data-confirm-subtitle></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body app-modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-question-circle-fill app-confirm-hero-icon text-primary" data-confirm-icon></i>
                </div>
                <div class="app-confirm-message" data-confirm-message>Are you sure you want to continue?</div>
            </div>
            <div class="modal-footer app-modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-confirm-cancel>Cancel</button>
                <button type="button" class="btn btn-primary-custom" data-confirm-accept>Continue</button>
            </div>
        </div>
    </div>
</div>

<style>
    .settings-modal-content {
        border: 0;
        border-radius: 24px;
        overflow: hidden;
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.18);
    }
    .settings-modal-header {
        padding: 1.25rem 1.5rem 1rem;
        border-bottom: 0;
        background:
            radial-gradient(circle at top right, rgba(59, 130, 246, 0.20), transparent 32%),
            linear-gradient(135deg, #eff6ff 0%, #f8fafc 100%);
    }
    .settings-modal-kicker {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 6px 12px;
        border-radius: 999px;
        background: rgba(37, 99, 235, 0.10);
        color: #1d4ed8;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        margin-bottom: 10px;
    }
    .settings-modal-subtitle {
        color: #64748b;
        font-size: 0.92rem;
        margin: 6px 0 0;
    }
    .settings-modal-body {
        padding: 1.25rem 1.5rem 1.5rem;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    }
    .settings-group-title {
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #94a3b8;
        margin-bottom: 12px;
    }
    .settings-option {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 14px 16px;
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 18px;
        background: #fff;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
    }
    .settings-option + .settings-option {
        margin-top: 12px;
    }
    .settings-option-main {
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 0;
    }
    .settings-option-icon {
        width: 42px;
        height: 42px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(37, 99, 235, 0.10);
        color: #2563eb;
        font-size: 1rem;
        flex: 0 0 auto;
    }
    .settings-option-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: #0f172a;
        margin: 0 0 2px;
    }
    .settings-option-desc {
        font-size: 0.82rem;
        color: #64748b;
        margin: 0;
    }
    .settings-modal-footer {
        border-top: 0;
        padding: 0 1.5rem 1.5rem;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    }
    body.dark-mode .settings-modal-content {
        background: #0f172a;
        color: #e5e7eb;
        box-shadow: 0 26px 64px rgba(0, 0, 0, 0.42);
    }
    body.dark-mode .settings-modal-header {
        background:
            radial-gradient(circle at top right, rgba(96, 165, 250, 0.14), transparent 32%),
            linear-gradient(135deg, #111827 0%, #0f172a 100%);
    }
    body.dark-mode .settings-modal-kicker {
        background: rgba(59, 130, 246, 0.18);
        color: #bfdbfe;
    }
    body.dark-mode .settings-modal-subtitle,
    body.dark-mode .settings-group-title,
    body.dark-mode .settings-option-desc {
        color: #94a3b8;
    }
    body.dark-mode .settings-modal-body,
    body.dark-mode .settings-modal-footer {
        background: linear-gradient(180deg, #0f172a 0%, #111827 100%);
    }
    body.dark-mode .settings-option {
        background: rgba(17, 24, 39, 0.9);
        border-color: rgba(148, 163, 184, 0.16);
        box-shadow: 0 12px 24px rgba(0, 0, 0, 0.22);
    }
    body.dark-mode .settings-option-title {
        color: #f8fafc;
    }
    body.dark-mode .settings-option-icon {
        background: rgba(59, 130, 246, 0.16);
        color: #93c5fd;
    }
    body.dark-mode .settings-modal-content .btn-light {
        background: #1f2937;
        border-color: #374151;
        color: #e5e7eb;
    }
    body.dark-mode .settings-modal-content .btn-light:hover {
        background: #374151;
        border-color: #4b5563;
        color: #f8fafc;
    }
</style>

<!-- Settings Modal -->
<div class="modal fade" id="settingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content settings-modal-content">
            <div class="modal-header settings-modal-header">
                <div>
                    <div class="settings-modal-kicker"><i class="bi bi-sliders"></i>Preferences</div>
                    <h5 class="modal-title mb-0">Settings</h5>
                    <p class="settings-modal-subtitle">Control the way your dashboard looks and how notifications behave.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body settings-modal-body">
                <div class="settings-group-title">Appearance</div>
                <div class="settings-preview-note text-muted" id="settingsPreviewNote"></div>
                <label class="settings-option" for="darkModeSwitch">
                    <span class="settings-option-main">
                        <span class="settings-option-icon"><i class="bi bi-moon-stars"></i></span>
                        <span>
                            <span class="settings-option-title d-block">Dark Mode</span>
                            <span class="settings-option-desc d-block">Switch the interface to a darker palette for low-light use.</span>
                        </span>
                    </span>
                    <span class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" id="darkModeSwitch">
                    </span>
                </label>

                <div class="settings-group-title mt-4">Notifications</div>
                <label class="settings-option" for="emailNotifSwitch">
                    <span class="settings-option-main">
                        <span class="settings-option-icon"><i class="bi bi-envelope"></i></span>
                        <span>
                            <span class="settings-option-title d-block">Email Notifications</span>
                            <span class="settings-option-desc d-block">Keep email alerts enabled for important account and class updates.</span>
                        </span>
                    </span>
                    <span class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" id="emailNotifSwitch" checked>
                    </span>
                </label>

                <label class="settings-option" for="pushNotifSwitch">
                    <span class="settings-option-main">
                        <span class="settings-option-icon"><i class="bi bi-bell"></i></span>
                        <span>
                            <span class="settings-option-title d-block">Push Notifications</span>
                            <span class="settings-option-desc d-block">Show phone notifications even when the app is closed.</span>
                        </span>
                    </span>
                    <span class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" id="pushNotifSwitch" checked>
                    </span>
                </label>
            </div>
            <div class="modal-footer settings-modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary px-4" id="saveSettingsBtn">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- Profile Modal -->
<div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-header app-modal-header">
                <div>
                    <div class="app-modal-kicker"><i class="bi bi-person-circle"></i>Profile</div>
                    <h5 class="modal-title mb-0">My Profile</h5>
                    <p class="app-modal-subtitle">Update your account information and password.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body app-modal-body">
                <form id="profileForm">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">First Name</label>
                            <input type="text" class="form-control" id="profileFirstName" name="first_name" value="<?php echo htmlspecialchars($displayFirstName); ?>" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Middle Name</label>
                            <input type="text" class="form-control" id="profileMiddleName" name="middle_name" value="<?php echo htmlspecialchars($displayMiddleName); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control" id="profileLastName" name="last_name" value="<?php echo htmlspecialchars($_SESSION['last_name'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" id="profileEmail" name="email" value="<?php echo htmlspecialchars($displayEmail); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sex</label>
                        <select class="form-select" id="profileSex" name="sex" required>
                            <option value="">Select Sex</option>
                            <option value="male" <?php echo $displaySex === 'male' ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo $displaySex === 'female' ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($displayRole); ?>" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reference Code</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($displayReference !== '' ? $displayReference : 'Not available'); ?>" readonly>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" class="form-control" id="profileCurrentPassword" name="current_password" placeholder="Enter current password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" class="form-control" id="profileNewPassword" name="new_password" placeholder="Enter new password">
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" id="profileConfirmPassword" name="confirm_password" placeholder="Re-enter new password">
                    </div>
                </form>
            </div>
            <div class="modal-footer app-modal-footer">
                <button type="button" class="btn btn-warning" id="resetProfilePasswordBtn">Reset Password</button>
                <button type="button" class="btn btn-primary" id="saveProfileBtn">Save Changes</button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var modals = document.querySelectorAll('.modal');
    for (var i = 0; i < modals.length; i++) {
        document.body.appendChild(modals[i]);
    }
})();
</script>
