<!-- Add Student Modal -->
<div class="modal fade" id="addStudentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content app-modal-content">
            <div class="modal-header app-modal-header">
                <div>
                    <div class="app-modal-kicker"><i class="bi bi-person-plus"></i>Enrollment</div>
                    <h5 class="modal-title mb-0">Add New Student</h5>
                    <p class="app-modal-subtitle">Create a student account and enroll in matching classes.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body app-modal-body">
                <form id="addStudentForm">
                    <div class="app-modal-stack">
                        <div class="app-modal-panel">
                            <div class="app-modal-panel-title"><i class="bi bi-person-vcard"></i>Student Profile</div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="addFirstName">First Name <span class="text-danger">*</span></label>
                                    <input type="text" name="first_name" id="addFirstName" class="form-control" required oninput="capitalizeWords(this)" autocomplete="given-name">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="addMiddleName">Middle Name</label>
                                    <input type="text" name="middle_name" id="addMiddleName" class="form-control" oninput="capitalizeWords(this)" autocomplete="additional-name">
                                </div>
                                <div class="col-md-4 mb-0">
                                    <label class="form-label" for="addLastName">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" name="last_name" id="addLastName" class="form-control" required oninput="capitalizeWords(this)" autocomplete="family-name">
                                </div>
                            </div>
                            <div class="row mt-1">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="addSex">Sex <span class="text-danger">*</span></label>
                                    <select name="sex" id="addSex" class="form-select" required autocomplete="sex">
                                        <option value="">Select Sex</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-0">
                                    <label class="form-label" for="addLrn">LRN <span class="text-danger">*</span> <small class="text-muted">(12 digits)</small></label>
                                    <input type="text" name="lrn" id="addLrn" class="form-control" maxlength="12" placeholder="Enter 12-digit LRN" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required autocomplete="off">
                                </div>
                            </div>
                        </div>
                        <div class="app-modal-panel">
                            <div class="app-modal-panel-title"><i class="bi bi-mortarboard"></i>Enrollment Placement</div>
                            <p class="app-modal-panel-copy">For Grade 11, the selected section determines the Strengthened SHS program and matching classes.</p>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="addGradeLevel">Grade Level <span class="text-danger">*</span></label>
                                    <select name="grade_level" id="addGradeLevel" class="form-select" required onchange="updateAddSections()" autocomplete="off">
                                        <option value="">Select Grade</option>
                                        <option value="11">Grade 11</option>
                                        <option value="12">Grade 12</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-0">
                                    <label class="form-label" for="addSection">Section <span class="text-danger">*</span></label>
                                    <select name="section" id="addSection" class="form-select" required disabled autocomplete="off">
                                        <option value="">Select Grade First</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="app-modal-panel">
                            <div class="app-modal-panel-title"><i class="bi bi-telephone"></i>Contact Details</div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="addDob">Date of Birth</label>
                                    <input type="date" name="date_of_birth" id="addDob" class="form-control" autocomplete="bday">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="addContact">Contact Number</label>
                                    <input type="text" name="contact_number" id="addContact" class="form-control" maxlength="20" placeholder="e.g. 09XX-XXX-XXXX" autocomplete="tel">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="addHouseStreet">House No./Street/Sitio/Purok</label>
                                    <input type="text" name="house_street" id="addHouseStreet" class="form-control" maxlength="120" oninput="capitalizeWords(this)" autocomplete="street-address">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="addBarangay">Barangay</label>
                                    <input type="text" name="barangay" id="addBarangay" class="form-control" maxlength="120" oninput="capitalizeWords(this)">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="addMunicipality">Municipality/City</label>
                                    <input type="text" name="municipality" id="addMunicipality" class="form-control" maxlength="120" oninput="capitalizeWords(this)">
                                </div>
                                <div class="col-md-6 mb-0">
                                    <label class="form-label" for="addProvince">Province</label>
                                    <input type="text" name="province" id="addProvince" class="form-control" maxlength="120" oninput="capitalizeWords(this)">
                                </div>
                            </div>
                        </div>
                        <div class="app-modal-note">
                            <i class="bi bi-info-circle-fill"></i>
                            <div>
                                <strong>Auto-enrollment</strong>
                                <p>Student will be auto-enrolled in all active classes matching the selected grade level, section, and track setup.</p>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer app-modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="createStudentBtn" onclick="createStudent()">Create & Enroll</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Student Modal -->
<div class="modal fade" id="editStudentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content app-modal-content">
            <div class="modal-header app-modal-header">
                <div>
                    <div class="app-modal-kicker"><i class="bi bi-pencil-square"></i>Update</div>
                    <h5 class="modal-title mb-0">Edit Student</h5>
                    <p class="app-modal-subtitle">Update student details and re-sync enrollment.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body app-modal-body">
                <form id="editStudentForm">
                    <input type="hidden" name="user_id" id="editUserId">
                    <div class="app-modal-stack">
                        <div class="app-modal-panel">
                            <div class="app-modal-panel-title"><i class="bi bi-pencil-square"></i>Profile Updates</div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="editFirstName">First Name <span class="text-danger">*</span></label>
                                    <input type="text" name="first_name" id="editFirstName" class="form-control" required oninput="capitalizeWords(this)" autocomplete="given-name">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label" for="editMiddleName">Middle Name</label>
                                    <input type="text" name="middle_name" id="editMiddleName" class="form-control" oninput="capitalizeWords(this)" autocomplete="additional-name">
                                </div>
                                <div class="col-md-4 mb-0">
                                    <label class="form-label" for="editLastName">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" name="last_name" id="editLastName" class="form-control" required oninput="capitalizeWords(this)" autocomplete="family-name">
                                </div>
                            </div>
                            <div class="row mt-1">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="editSex">Sex <span class="text-danger">*</span></label>
                                    <select name="sex" id="editSex" class="form-select" required autocomplete="sex">
                                        <option value="">Select Sex</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-0">
                                    <label class="form-label" for="editLrn">LRN <span class="text-danger">*</span> <small class="text-muted">(12 digits)</small></label>
                                    <input type="text" name="lrn" id="editLrn" class="form-control" maxlength="12" placeholder="Enter 12-digit LRN" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required autocomplete="off">
                                </div>
                            </div>
                        </div>
                        <div class="app-modal-panel">
                            <div class="app-modal-panel-title"><i class="bi bi-arrow-repeat"></i>Enrollment Placement</div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="editGradeLevel">Grade Level <span class="text-danger">*</span></label>
                                    <select name="grade_level" id="editGradeLevel" class="form-select" required onchange="updateEditSections()" autocomplete="off">
                                        <option value="">Select Grade</option>
                                        <option value="11">Grade 11</option>
                                        <option value="12">Grade 12</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-0">
                                    <label class="form-label" for="editSection">Section <span class="text-danger">*</span></label>
                                    <select name="section" id="editSection" class="form-select" required disabled autocomplete="off">
                                        <option value="">Select Grade First</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer app-modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="updateStudentBtn" onclick="updateStudent()">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- View Student Modal -->
<div class="modal fade" id="viewStudentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-header app-modal-header">
                <div>
                    <div class="app-modal-kicker"><i class="bi bi-person-circle"></i>Profile</div>
                    <h5 class="modal-title mb-0">Student Details</h5>
                    <p class="app-modal-subtitle">View student profile and enrolled classes.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body app-modal-body">
                <div class="text-center mb-4">
                    <div id="viewStudentAvatar" class="user-avatar-small mx-auto" style="width: 80px; height: 80px; font-size: 28px;"></div>
                    <h5 class="mt-3 mb-1" id="viewFullName"></h5>
                    <span class="role-badge role-student">Student</span>
                </div>
                <div class="app-modal-panel">
                    <div class="app-modal-panel-title"><i class="bi bi-person-badge"></i>Student Snapshot</div>
                    <div class="app-modal-detail-list">
                        <div class="app-modal-detail-row">
                            <div class="app-modal-detail-label">LRN</div>
                            <div class="app-modal-detail-value" id="viewLrn"></div>
                        </div>
                        <div class="app-modal-detail-row">
                            <div class="app-modal-detail-label">Reference Code</div>
                            <div class="app-modal-detail-value" id="viewRefCode"></div>
                        </div>
                        <div class="app-modal-detail-row">
                            <div class="app-modal-detail-label">Email</div>
                            <div class="app-modal-detail-value" id="viewEmail"></div>
                        </div>
                        <div class="app-modal-detail-row">
                            <div class="app-modal-detail-label">Sex</div>
                            <div class="app-modal-detail-value" id="viewSex"></div>
                        </div>
                        <div class="app-modal-detail-row">
                            <div class="app-modal-detail-label">Grade Level</div>
                            <div class="app-modal-detail-value" id="viewGradeLevel"></div>
                        </div>
                        <div class="app-modal-detail-row">
                            <div class="app-modal-detail-label">Section</div>
                            <div class="app-modal-detail-value" id="viewSection"></div>
                        </div>
                        <div class="app-modal-detail-row">
                            <div class="app-modal-detail-label">Program</div>
                            <div class="app-modal-detail-value" id="viewTrack"></div>
                        </div>
                        <div class="app-modal-detail-row">
                            <div class="app-modal-detail-label">Status</div>
                            <div class="app-modal-detail-value"><span id="viewStatus" class="status-badge"></span></div>
                        </div>
                        <div class="app-modal-detail-row">
                            <div class="app-modal-detail-label">Enrolled Classes</div>
                            <div class="app-modal-detail-value" id="viewEnrolledClasses"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer app-modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Import Modal -->
<!-- SF1 Import Modal with Interactive Preview -->
<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl">
        <div class="modal-content app-modal-content">
            <div class="modal-header app-modal-header">
                <div>
                    <div class="app-modal-kicker"><i class="bi bi-file-earmark-spreadsheet"></i>SF1 Import & Verification</div>
                    <h5 class="modal-title mb-0" id="importModalLabel">Bulk Student Import</h5>
                    <p class="app-modal-subtitle" id="importModalSubtitle">Upload, review, edit, and verify official DepEd SF1 learner data before committing to the system.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body app-modal-body">
                <div class="app-modal-stack">
                    <!-- STAGE 1: UPLOAD -->
                    <div id="importUploadStage">
                        <div class="app-modal-note mb-3">
                            <i class="bi bi-file-earmark-check-fill"></i>
                            <div>
                                <strong>Interactive SF1 Verification</strong>
                                <p class="mb-0">After selecting an SF1 file (XLSX or CSV), click <strong>"Preview & Verify Data"</strong> to review learner records, edit individual fields, or exclude rows before importing.</p>
                            </div>
                        </div>

                        <form id="importForm" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" id="importCsrfToken" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                            <input type="hidden" name="action" value="preview">
                            <div class="app-modal-panel">
                                <div class="app-modal-panel-title"><i class="bi bi-upload"></i>Select Official SF1 File</div>
                                <div class="row g-3 align-items-end mb-0">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold" for="sf1File">SF1 File <span class="text-danger">*</span></label>
                                        <input type="file" name="sf1_file" id="sf1File" class="form-control" accept=".csv,.xlsx" required>
                                        <div class="form-text">Official DepEd SF1 (.xlsx) or formatted (.csv) spreadsheet. Max 10MB.</div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold" for="importAcademicYear">Academic Year</label>
                                        <input type="text" name="academic_year" id="importAcademicYear" class="form-control" value="<?php echo date('Y') . '-' . (date('Y') + 1); ?>" placeholder="e.g. 2025-2026">
                                    </div>
                                    <div class="col-md-3">
                                        <a href="?download_template=1" class="btn btn-outline-primary btn-sm w-100 mb-2" download data-skip-loader="true">
                                            <i class="bi bi-download me-1"></i>Download CSV Template
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- STAGE 2: EDITABLE PREVIEW GRID -->
                    <div id="importPreviewStage" style="display: none;">
                        <!-- Header Meta Configuration Card -->
                        <div class="app-modal-panel mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="app-modal-panel-title mb-0"><i class="bi bi-gear-wide-connected"></i>SF1 Classification & Section Settings</div>
                                <span class="badge bg-primary px-3 py-2 fs-6" id="previewFileName">SF1 File</span>
                            </div>
                            <div class="row g-3 align-items-end">
                                <div class="col-md-3 col-sm-6">
                                    <label class="form-label small fw-bold">Grade Level</label>
                                    <select id="previewGradeLevel" class="form-select form-select-sm" onchange="syncPreviewMetadata()">
                                        <option value="11">Grade 11</option>
                                        <option value="12">Grade 12</option>
                                    </select>
                                </div>
                                <div class="col-md-3 col-sm-6">
                                    <label class="form-label small fw-bold">Section Name</label>
                                    <input type="text" id="previewSection" class="form-control form-control-sm text-uppercase" placeholder="e.g. STEM-A" oninput="syncPreviewMetadata()">
                                </div>
                                <div class="col-md-3 col-sm-6">
                                    <label class="form-label small fw-bold">Track / Strand</label>
                                    <select id="previewTrack" class="form-select form-select-sm" onchange="syncPreviewMetadata()">
                                        <option value="academic">Academic</option>
                                        <option value="techpro">TechPro / TVL</option>
                                    </select>
                                </div>
                                <div class="col-md-3 col-sm-6">
                                    <label class="form-label small fw-bold">School Year</label>
                                    <input type="text" id="previewSchoolYear" class="form-control form-control-sm" placeholder="2025-2026">
                                </div>
                            </div>
                        </div>

                        <!-- KPI Summary Badges -->
                        <div class="row g-2 mb-3">
                            <div class="col-6 col-md-2">
                                <div class="p-2 border rounded text-center bg-light">
                                    <div class="text-muted small">Total Learners</div>
                                    <div class="fs-5 fw-bold" id="previewStatTotal">0</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <div class="p-2 border rounded text-center bg-light">
                                    <div class="text-muted small">Male / Female</div>
                                    <div class="fs-5 fw-bold"><span id="previewStatMale">0</span> / <span id="previewStatFemale">0</span></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="p-2 border rounded text-center bg-light">
                                    <div class="text-muted small">New Registrations</div>
                                    <div class="fs-5 fw-bold text-success" id="previewStatNew">0</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="p-2 border rounded text-center bg-light">
                                    <div class="text-muted small">Already in System</div>
                                    <div class="fs-5 fw-bold text-warning" id="previewStatExisting">0</div>
                                </div>
                            </div>
                            <div class="col-12 col-md-2">
                                <div class="p-2 border rounded text-center bg-light">
                                    <div class="text-muted small">Format Issues</div>
                                    <div class="fs-5 fw-bold text-danger" id="previewStatInvalid">0</div>
                                </div>
                            </div>
                        </div>

                        <!-- Search Filter & Add Row Toolbar -->
                        <div class="d-flex justify-content-between align-items-center gap-2 mb-2 flex-wrap">
                            <div class="d-flex align-items-center gap-2 flex-grow-1" style="max-width: 400px;">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="text" id="previewSearchFilter" class="form-control" placeholder="Search learner name or LRN in preview..." oninput="filterPreviewRows()">
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="addPreviewRow()">
                                    <i class="bi bi-person-plus me-1"></i>Add Learner Row
                                </button>
                            </div>
                        </div>

                        <!-- Interactive Spreadsheet Grid -->
                        <div class="table-responsive border rounded" style="max-height: 420px; overflow-y: auto;">
                            <table class="table table-sm table-hover align-middle mb-0" id="previewTable" style="min-width: 1400px; font-size: 0.85rem;">
                                <thead class="table-light sticky-top" style="z-index: 2;">
                                    <tr>
                                        <th style="width: 40px;" class="text-center">#</th>
                                        <th style="width: 100px;">Status</th>
                                        <th style="width: 130px;">LRN (12 Digits) <span class="text-danger">*</span></th>
                                        <th style="width: 130px;">Last Name <span class="text-danger">*</span></th>
                                        <th style="width: 130px;">First Name <span class="text-danger">*</span></th>
                                        <th style="width: 110px;">Middle Name</th>
                                        <th style="width: 60px;">Ext</th>
                                        <th style="width: 90px;">Sex</th>
                                        <th style="width: 120px;">Birthdate</th>
                                        <th style="width: 130px;">House / Street</th>
                                        <th style="width: 120px;">Barangay</th>
                                        <th style="width: 120px;">Municipality</th>
                                        <th style="width: 120px;">Province</th>
                                        <th style="width: 110px;">Contact #</th>
                                        <th style="width: 140px;">Parent / Guardian</th>
                                        <th style="width: 60px;" class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="previewTableBody">
                                    <!-- Rows rendered dynamically by JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- STAGE 3: PROGRESS & RESULTS -->
                    <div id="importProgress" style="display:none;" class="mt-0">
                        <div class="progress mb-2" style="height:8px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" id="importProgressBar" style="width:0%;"></div>
                        </div>
                        <div id="importStatus" class="text-muted small">Processing...</div>
                    </div>

                    <div id="importResults" style="display:none;" class="mt-0">
                        <div id="importSummary" class="mb-3"></div>
                        <div id="importLog" style="max-height:400px;overflow-y:auto;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer app-modal-footer">
                <!-- Upload Stage Buttons -->
                <div id="uploadStageButtons" class="d-flex gap-2 w-100 justify-content-end">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="previewBtn" onclick="previewImport()">
                        <i class="bi bi-eye me-1"></i>Preview & Verify Data
                    </button>
                </div>

                <!-- Preview Stage Buttons -->
                <div id="previewStageButtons" class="d-flex gap-2 w-100 justify-content-between" style="display: none !important;">
                    <button type="button" class="btn btn-outline-secondary" onclick="backToUploadStage()">
                        <i class="bi bi-arrow-left me-1"></i>Back to Upload
                    </button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-success" id="commitBtn" onclick="commitImport()">
                            <i class="bi bi-check-circle me-1"></i>Confirm & Import (<span id="commitCount">0</span>) Learners
                        </button>
                    </div>
                </div>

                <!-- Results Stage Buttons -->
                <div id="resultsStageButtons" class="d-flex gap-2 w-100 justify-content-end" style="display: none !important;">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal" onclick="loadStudents(1)">Done</button>
                </div>
            </div>
        </div>
    </div>
</div>

