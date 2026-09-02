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

<!-- Push Notification First-Open Prompt Modal -->
<div class="modal fade" id="pushPromptModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content push-prompt-modal-content">
            <div class="modal-header app-modal-header push-prompt-modal-header border-0 pb-0">
                <div>
                    <div class="app-modal-kicker push-prompt-kicker"><i class="bi bi-bell-fill text-primary"></i>Stay Updated</div>
                    <h5 class="modal-title mb-1 fw-bold">Enable Notifications?</h5>
                    <p class="app-modal-subtitle push-prompt-subtitle mb-0">Get important academic updates and alerts even when the app is closed.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" id="pushPromptCloseBtn" aria-label="Close"></button>
            </div>
            <div class="modal-body app-modal-body push-prompt-modal-body pt-3">
                <div class="push-prompt-features">
                    <div class="push-prompt-feature-item">
                        <div class="push-prompt-feature-icon text-primary"><i class="bi bi-calendar-check-fill"></i></div>
                        <div class="push-prompt-feature-text">
                            <span class="push-prompt-feature-title">Real-time Attendance</span>
                            <span class="push-prompt-feature-desc">Immediate alerts when attendance or tardiness is recorded.</span>
                        </div>
                    </div>
                    <div class="push-prompt-feature-item">
                        <div class="push-prompt-feature-icon text-success"><i class="bi bi-file-earmark-spreadsheet-fill"></i></div>
                        <div class="push-prompt-feature-text">
                            <span class="push-prompt-feature-title">Grade & Report Card Releases</span>
                            <span class="push-prompt-feature-desc">Be notified the moment quarterly grades and report cards are published.</span>
                        </div>
                    </div>
                    <div class="push-prompt-feature-item">
                        <div class="push-prompt-feature-icon text-warning"><i class="bi bi-megaphone-fill"></i></div>
                        <div class="push-prompt-feature-text">
                            <span class="push-prompt-feature-title">School Announcements</span>
                            <span class="push-prompt-feature-desc">Direct advisories, class messages, and urgent school notices.</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer app-modal-footer push-prompt-modal-footer d-flex flex-column flex-sm-row justify-content-end gap-2 border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary w-100 w-sm-auto order-3 order-sm-1" id="pushPromptLaterBtn">Later</button>
                <button type="button" class="btn btn-outline-danger w-100 w-sm-auto order-2 order-sm-2" id="pushPromptDenyBtn">Deny</button>
                <button type="button" class="btn btn-primary-custom w-100 w-sm-auto order-1 order-sm-3" id="pushPromptAllowBtn">
                    <i class="bi bi-bell-fill me-1"></i>Allow Notifications
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Settings Modal -->
<div class="modal fade" id="settingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content settings-modal-content">
            <div class="modal-header app-modal-header settings-modal-header">
                <div>
                    <div class="app-modal-kicker settings-modal-kicker"><i class="bi bi-sliders"></i>Preferences</div>
                    <h5 class="modal-title mb-0">Settings</h5>
                    <p class="app-modal-subtitle settings-modal-subtitle">Control the way your dashboard looks and how notifications behave.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body app-modal-body settings-modal-body">
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
                <label class="settings-option" for="pushNotifSwitch">
                    <span class="settings-option-main">
                        <span class="settings-option-icon"><i class="bi bi-bell"></i></span>
                        <span>
                            <span class="settings-option-title d-block">Push Notifications</span>
                            <span class="settings-option-desc d-block">Show phone notifications even when the app is closed.</span>
                            <span class="settings-option-desc d-block" id="pushNotificationStatus"></span>
                        </span>
                    </span>
                    <span class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" id="pushNotifSwitch" checked>
                    </span>
                </label>
                <div class="px-3 pb-2">
                    <span class="settings-option-desc d-block" id="pushPermissionGuidance"></span>
                    <span class="d-flex flex-wrap gap-2 mt-2 d-none" id="pushPermissionActions">
                        <button type="button" class="btn btn-sm btn-primary-custom" id="allowPushPermissionBtn">
                            <i class="bi bi-bell-check me-1"></i>Allow Notifications
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="deferPushPermissionBtn">Not Now</button>
                    </span>
                </div>

                <div id="settingsPwaInstallSection" class="mt-4" style="display:none;">
                    <div class="settings-group-title">Application</div>
                    <div class="settings-option">
                        <span class="settings-option-main">
                            <span class="settings-option-icon"><i class="bi bi-phone"></i></span>
                            <span>
                                <span class="settings-option-title d-block">Install App</span>
                                <span class="settings-option-desc d-block">Install BSHS AMS on this device for quick access and offline reliability.</span>
                            </span>
                        </span>
                        <button type="button" class="btn btn-sm btn-primary-custom flex-shrink-0" id="settingsPwaInstallBtn">
                            <i class="bi bi-download me-1"></i>Install
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer app-modal-footer settings-modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary-custom px-4" id="saveSettingsBtn">Save Changes</button>
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
                            <input type="text" class="form-control profile-name-input" id="profileFirstName" name="first_name" value="<?php echo htmlspecialchars($displayFirstName); ?>" autocapitalize="words" autocomplete="given-name" spellcheck="false" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Middle Name</label>
                            <input type="text" class="form-control profile-name-input" id="profileMiddleName" name="middle_name" value="<?php echo htmlspecialchars($displayMiddleName); ?>" autocapitalize="words" autocomplete="additional-name" spellcheck="false">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control profile-name-input" id="profileLastName" name="last_name" value="<?php echo htmlspecialchars($_SESSION['last_name'] ?? ''); ?>" autocapitalize="words" autocomplete="family-name" spellcheck="false" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" id="profileEmail" name="email" value="<?php echo htmlspecialchars($displayEmail); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Contact Number (Mobile)</label>
                        <input type="tel" class="form-control" id="profileContactNumber" name="contact_number" value="<?php echo htmlspecialchars($displayContactNumber ?? ''); ?>" placeholder="e.g. 09171234567" autocomplete="tel">
                        <div class="form-text text-muted">Used for official school SMS alerts (grades and release notices).</div>
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
                    <div id="profileModalAlert" class="alert d-none mb-3"></div>
                    <div class="settings-group-title mt-2">Password</div>
                    <p class="small text-muted mb-3">Change your password here when you know your current password. Use account recovery if you forgot it.</p>
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="profileCurrentPassword" name="current_password" placeholder="Enter current password">
                            <button type="button" class="btn btn-outline-secondary profile-password-toggle" data-target="profileCurrentPassword" aria-label="Show current password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="profileNewPassword" name="new_password" placeholder="Enter new password" autocomplete="new-password">
                            <button type="button" class="btn btn-outline-secondary profile-password-toggle" data-target="profileNewPassword" aria-label="Show new password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="password-guidance mt-2" data-password-guidance data-target="profileNewPassword">
                            <p class="password-guidance-title">Recommended password</p>
                            <ul class="password-guidance-list">
                                <li data-rule="length">12 to 72 characters</li>
                                <li data-rule="uppercase">At least one uppercase letter</li>
                                <li data-rule="lowercase">At least one lowercase letter</li>
                                <li data-rule="number">At least one number</li>
                                <li data-rule="special">At least one special character</li>
                            </ul>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Confirm New Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="profileConfirmPassword" name="confirm_password" placeholder="Re-enter new password" autocomplete="new-password">
                            <button type="button" class="btn btn-outline-secondary profile-password-toggle" data-target="profileConfirmPassword" aria-label="Show confirmed password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div id="profilePasswordMatchIndicator" class="small mt-1 text-muted"></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer app-modal-footer">
                <button type="button" class="btn btn-outline-warning" id="resetProfilePasswordBtn">Forgot Password?</button>
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

    function profileApiUrl() {
        var token = window.APP_CSRF_TOKEN || '';
        var path = window.location.pathname;
        var isSubfolder = (path.indexOf('/admin/') !== -1 || path.indexOf('/teacher/') !== -1 || path.indexOf('/student/') !== -1 || path.indexOf('/parent/') !== -1 || path.indexOf('/auth/') !== -1);
        var apiPath = isSubfolder ? '../api/index.php' : 'api/index.php';
        return apiPath + '?route=profile' + (token ? '&csrf_token=' + encodeURIComponent(token) : '');
    }

    function setProfileBusy(isBusy) {
        var btn = document.getElementById('saveProfileBtn');
        if (!btn) return;
        btn.disabled = !!isBusy;
        btn.innerHTML = isBusy ? '<span class="spinner-border spinner-border-sm me-2"></span>Saving...' : 'Save Changes';
    }

    function showProfileModalAlert(message, type) {
        var alertBox = document.getElementById('profileModalAlert');
        if (!alertBox) return;
        if (!message) {
            alertBox.className = 'alert d-none mb-3';
            alertBox.textContent = '';
            return;
        }
        alertBox.className = 'alert alert-' + (type || 'danger') + ' mb-3';
        alertBox.textContent = message;
    }

    function toTitleCase(str) {
        if (!str) return '';
        return str.replace(/\b([a-z\u00C0-\u017F])/gi, function (char) {
            return char.toUpperCase();
        });
    }

    document.querySelectorAll('.profile-name-input, #profileFirstName, #profileMiddleName, #profileLastName').forEach(function (input) {
        input.addEventListener('blur', function () {
            var val = (this.value || '').trim();
            if (val) {
                this.value = toTitleCase(val);
            }
        });
        input.addEventListener('input', function () {
            var val = this.value || '';
            var pos = this.selectionStart;
            if (val.length > 0 && pos === val.length) {
                var words = val.split(/(\s+|-)/);
                var formatted = words.map(function (w) {
                    if (/^\s+$/.test(w) || w === '-') return w;
                    return w.charAt(0).toUpperCase() + w.slice(1);
                }).join('');
                if (val !== formatted) {
                    this.value = formatted;
                    if (pos !== null) {
                        this.setSelectionRange(pos, pos);
                    }
                }
            }
        });
    });

    var newPassInput = document.getElementById('profileNewPassword');
    var confirmPassInput = document.getElementById('profileConfirmPassword');
    var matchIndicator = document.getElementById('profilePasswordMatchIndicator');

    var passwordRules = {
        length: function (v) { return v.length >= 12 && v.length <= 72; },
        uppercase: function (v) { return /[A-Z]/.test(v); },
        lowercase: function (v) { return /[a-z]/.test(v); },
        number: function (v) { return /[0-9]/.test(v); },
        special: function (v) { return /[^A-Za-z0-9\s]/.test(v); }
    };

    function updateProfilePasswordGuide() {
        if (!newPassInput) return;
        var guide = document.querySelector('[data-password-guidance][data-target="profileNewPassword"]');
        if (!guide) return;
        var val = newPassInput.value || '';
        var items = guide.querySelectorAll('[data-rule]');
        items.forEach(function (item) {
            var rule = item.getAttribute('data-rule');
            var passed = passwordRules[rule] ? passwordRules[rule](val) : false;
            item.classList.toggle('is-valid', passed);
        });
    }

    function updateProfilePasswordMatch() {
        if (!matchIndicator) return;
        var newV = newPassInput ? newPassInput.value : '';
        var confirmV = confirmPassInput ? confirmPassInput.value : '';
        if (!confirmV && !newV) {
            matchIndicator.textContent = '';
            matchIndicator.className = 'small mt-1 text-muted';
            return;
        }
        if (confirmV && newV && confirmV === newV) {
            matchIndicator.textContent = '✓ Passwords match';
            matchIndicator.className = 'small mt-1 text-success fw-bold';
        } else if (confirmV) {
            matchIndicator.textContent = '✗ Passwords do not match';
            matchIndicator.className = 'small mt-1 text-danger fw-bold';
        } else {
            matchIndicator.textContent = '';
            matchIndicator.className = 'small mt-1 text-muted';
        }
    }

    if (newPassInput) {
        newPassInput.addEventListener('input', function () {
            updateProfilePasswordGuide();
            updateProfilePasswordMatch();
            showProfileModalAlert('', '');
        });
        updateProfilePasswordGuide();
    }
    if (confirmPassInput) {
        confirmPassInput.addEventListener('input', function () {
            updateProfilePasswordMatch();
            showProfileModalAlert('', '');
        });
    }

    document.querySelectorAll('.profile-password-toggle').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = document.getElementById(button.getAttribute('data-target') || '');
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            var icon = button.querySelector('i');
            if (icon) {
                icon.classList.toggle('bi-eye', !show);
                icon.classList.toggle('bi-eye-slash', show);
            }
            button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    });

    var saveProfileBtn = document.getElementById('saveProfileBtn');
    if (saveProfileBtn) {
        saveProfileBtn.addEventListener('click', function () {
            var form = document.getElementById('profileForm');
            if (!form) return;
            showProfileModalAlert('', '');

            var currentP = document.getElementById('profileCurrentPassword') ? document.getElementById('profileCurrentPassword').value : '';
            var newP = newPassInput ? newPassInput.value : '';
            var confirmP = confirmPassInput ? confirmPassInput.value : '';

            if (newP || confirmP || currentP) {
                if (!currentP) {
                    showProfileModalAlert('Please enter your current password to update your password.', 'danger');
                    return;
                }
                if (!newP) {
                    showProfileModalAlert('Please enter a new password.', 'danger');
                    return;
                }
                if (newP !== confirmP) {
                    showProfileModalAlert('New password and confirm password do not match.', 'danger');
                    return;
                }
                var allValid = Object.keys(passwordRules).every(function (k) { return passwordRules[k](newP); });
                if (!allValid) {
                    showProfileModalAlert('Please meet all password requirements highlighted in the checklist below.', 'danger');
                    return;
                }
            }

            var payload = {};
            new FormData(form).forEach(function (value, key) {
                payload[key] = String(value || '');
            });
            if (payload.first_name) payload.first_name = toTitleCase(payload.first_name.trim());
            if (payload.middle_name) payload.middle_name = toTitleCase(payload.middle_name.trim());
            if (payload.last_name) payload.last_name = toTitleCase(payload.last_name.trim());
            setProfileBusy(true);
            fetch(profileApiUrl(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.APP_CSRF_TOKEN || ''
                },
                body: JSON.stringify(payload)
            }).then(function (response) {
                return response.json();
            }).then(function (data) {
                if (!data.ok) {
                    throw new Error(data.message || 'Failed to update profile');
                }
                var updates = data.updates || {};
                if (updates.first_name || updates.last_name) {
                    var firstName = updates.first_name || (document.getElementById('profileFirstName') ? document.getElementById('profileFirstName').value : '');
                    var lastName = updates.last_name || (document.getElementById('profileLastName') ? document.getElementById('profileLastName').value : '');
                    var nameParts = [firstName, lastName].filter(Boolean);
                    var name = nameParts.join(' ').replace(/\s+/g, ' ').trim();
                    var headerName = document.getElementById('headerProfileName');
                    if (headerName && name) headerName.textContent = name;
                    var profileName = document.querySelector('.header-profile-name');
                    if (profileName && name) profileName.textContent = name;
                }
                ['profileCurrentPassword', 'profileNewPassword', 'profileConfirmPassword'].forEach(function (id) {
                    var input = document.getElementById(id);
                    if (input) input.value = '';
                });
                updateProfilePasswordGuide();
                updateProfilePasswordMatch();
                showProfileModalAlert(data.message || 'Profile updated successfully', 'success');
                if (typeof showNotification === 'function') {
                    showNotification(data.message || 'Profile updated successfully', 'success');
                }
            }).catch(function (error) {
                showProfileModalAlert(error.message || 'Failed to update profile', 'danger');
                if (typeof showNotification === 'function') {
                    showNotification(error.message || 'Failed to update profile', 'danger');
                }
            }).finally(function () {
                setProfileBusy(false);
            });
        });
    }

    var resetProfilePasswordBtn = document.getElementById('resetProfilePasswordBtn');
    if (resetProfilePasswordBtn) {
        resetProfilePasswordBtn.addEventListener('click', function () {
            var emailInput = document.getElementById('profileEmail');
            var email = emailInput ? String(emailInput.value || '').trim() : '';
            var isSubfolder = (window.location.pathname.indexOf('/admin/') !== -1 || window.location.pathname.indexOf('/teacher/') !== -1 || window.location.pathname.indexOf('/student/') !== -1 || window.location.pathname.indexOf('/parent/') !== -1);
            var recoveryUrl = isSubfolder ? '../auth/forgot-password.php' : 'auth/forgot-password.php';
            if (email) {
                recoveryUrl += '?email=' + encodeURIComponent(email);
            }
            window.location.href = recoveryUrl;
        });
    }
})();
</script>
