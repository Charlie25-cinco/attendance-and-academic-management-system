<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content app-modal-content">
            <div class="modal-header app-modal-header">
                <div>
                    <div class="app-modal-kicker"><i class="bi bi-person-plus"></i>Users</div>
                    <h5 class="modal-title mb-0">Add New User</h5>
                    <p class="app-modal-subtitle">Create accounts and assign roles quickly.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body app-modal-body">
                <form id="addUserForm">
                    <div class="app-modal-stack">
                        <div class="app-modal-panel">
                            <div class="app-modal-panel-title"><i class="bi bi-person-badge"></i>Account Setup</div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Role <span class="text-danger">*</span></label>
                                    <select name="role" id="userRole" class="form-select" required onchange="updateReferenceCode(); onRoleChange(this);">
                                        <option value="">Select Role</option>
                                        <option value="teacher">Teacher</option>
                                        <option value="parent">Parent</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-0">
                                    <label class="form-label">Reference Code <small class="text-muted">(Auto-generated)</small></label>
                                    <input type="text" name="reference_code" id="referenceCode" class="form-control" readonly style="background-color: #f8f9fa;">
                                </div>
                            </div>
                            <div class="row mt-1">
                                <div class="col-md-4 mb-3" id="gradeLevelDropdown" style="display: none;">
                                    <label class="form-label" id="gradeLevelLabel">Grade Level <span class="text-danger">*</span></label>
                                    <select name="grade_level" id="gradeLevel" class="form-select" onchange="updateSections()">
                                        <option value="">Select Grade Level</option>
                                        <option value="11">Grade 11</option>
                                        <option value="12">Grade 12</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3" id="trackDropdownCreate" style="display: none;">
                                    <label class="form-label" id="trackLabelCreate">Track <span class="text-danger">*</span></label>
                                    <select name="track" id="trackCreate" class="form-select" onchange="updateSections()">
                                        <option value="">Select Track</option>
                                        <option value="academic">Academic</option>
                                        <option value="techpro">TechPro</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-0" id="sectionDropdown" style="display: none;">
                                    <label class="form-label" id="sectionLabel">Section <span class="text-danger">*</span></label>
                                    <select name="section" id="sectionSelect" class="form-select" disabled onchange="updateClassAvailabilityWarning()">
                                        <option value="">Select Grade & Track First</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="app-modal-panel">
                            <div class="app-modal-panel-title"><i class="bi bi-person-lines-fill"></i>Profile Details</div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" name="first_name" id="firstName" class="form-control" required oninput="capitalizeWords(this)">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Middle Name</label>
                                    <input type="text" name="middle_name" id="middleName" class="form-control" oninput="capitalizeWords(this)">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" name="last_name" id="lastName" class="form-control" required oninput="capitalizeWords(this)">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Sex <span class="text-danger">*</span></label>
                                    <select name="sex" id="sex" class="form-select" required>
                                        <option value="">Select Sex</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Contact Number (Mobile)</label>
                                    <input type="text" name="contact_number" id="contactNumber" class="form-control" placeholder="09XXXXXXXXX" maxlength="20" autocomplete="tel">
                                </div>
                            </div>
                        </div>

                        <!-- Class/Subject Selection (Teacher only) -->
                        <div class="app-modal-panel mb-0" id="classDropdown" style="display: none;">
                            <div class="app-modal-panel-title"><i class="bi bi-journal-check"></i>Teacher Subject Assignment</div>
                            <p class="app-modal-panel-copy">Assign subjects now or leave this blank and transfer subjects later from class management.</p>
                            <div class="class-checklist" id="classChecklist">
                            <?php
                            // Fetch classes for checklist - these are the subjects
                            $classQuery = "SELECT id, class_name, grade_level, section, schedule FROM classes WHERE status = 'active' ORDER BY class_name";
                            $classStmt = $db->query($classQuery);
                            $classesList = $classStmt->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($classesList as $class):
                                $scheduleDisplay = $class['schedule'] ? htmlspecialchars($class['schedule']) : 'No schedule set';
                            ?>
                            <div class="form-check class-check-item">
                                <input class="form-check-input class-checkbox" type="checkbox" name="class_ids[]" value="<?php echo $class['id']; ?>" id="class_<?php echo $class['id']; ?>">
                                <label class="form-check-label" for="class_<?php echo $class['id']; ?>">
                                    <strong><?php echo htmlspecialchars($class['class_name']); ?></strong>
                                    <small class="text-muted d-block"><?php if (!empty($class['grade_level']) || !empty($class['section'])): ?><?php if (!empty($class['grade_level'])): ?>Grade <?php echo htmlspecialchars((string)$class['grade_level']); ?><?php endif; ?><?php if (!empty($class['grade_level']) && !empty($class['section'])): ?> - <?php endif; ?><?php echo htmlspecialchars((string)($class['section'] ?? '')); ?><?php else: ?>Grade/section not set<?php endif; ?></small>
                                    <small class="text-info d-block"><i class="bi bi-clock me-1"></i><?php echo $scheduleDisplay; ?></small>
                                </label>
                            </div>
                            <?php endforeach; ?>
                            </div>
                            <div class="form-text text-muted" id="classHelpText">Select subjects now or transfer a subject if needed.</div>
                        </div>
                        <div class="app-modal-panel mb-0" id="parentStudentDropdown" style="display: none;">
                            <div class="app-modal-panel-title"><i class="bi bi-people"></i>Parent Link</div>
                            <label class="form-label">Link Student(s) <span class="text-danger">*</span></label>
                            <div class="input-group input-group-sm mb-2">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control" id="parentStudentSearch" placeholder="Type student name or reference code to filter..." oninput="filterParentStudentList('parentStudentSearch', '#parentStudentChecklist .class-check-item')">
                                <button class="btn btn-outline-secondary" type="button" id="clearParentStudentSearchBtn" style="display:none;" onclick="clearParentSearch('parentStudentSearch', '#parentStudentChecklist .class-check-item', 'clearParentStudentSearchBtn')"><i class="bi bi-x-lg"></i></button>
                            </div>
                            <div class="class-checklist" id="parentStudentChecklist">
                            <?php foreach ($studentsForParent as $student): ?>
                            <div class="form-check class-check-item" data-student-search="<?php echo htmlspecialchars(strtolower($student['last_name'] . ' ' . $student['first_name'] . ' ' . ($student['reference_code'] ?? ''))); ?>">
                                <input class="form-check-input parent-student-checkbox" type="checkbox" name="linked_student_ids[]" value="<?php echo (int)$student['id']; ?>" id="parent_student_<?php echo (int)$student['id']; ?>">
                                <label class="form-check-label" for="parent_student_<?php echo (int)$student['id']; ?>">
                                    <strong><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></strong>
                                    <small class="text-muted d-block"><?php echo !empty($student['reference_code']) ? htmlspecialchars((string)$student['reference_code']) : 'No reference code'; ?></small>
                                </label>
                            </div>
                            <?php endforeach; ?>
                            </div>
                            <div class="form-text text-muted">Select at least 1 student.</div>
                        </div>
                        <div class="app-modal-note">
                            <i class="bi bi-info-circle-fill"></i>
                            <div>
                                <strong>Access reminder</strong>
                                <p>Default password will be assigned during account creation. Users should update it from their profile after first login.</p>
                            </div>
                        </div>
                        <div class="app-modal-note d-none" id="classAvailabilityWarning">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <div>
                                <strong>No matching classes yet</strong>
                                <p>No active class was found for the selected grade level and section.</p>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer app-modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="createUserBtn" onclick="createUser()">Create User</button>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/delete_modal.php'; ?>

<!-- View User Modal -->
<div class="modal fade" id="viewUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content">
            <div class="modal-header app-modal-header">
                <div>
                    <div class="app-modal-kicker"><i class="bi bi-person-circle"></i>Profile</div>
                    <h5 class="modal-title mb-0">User Details</h5>
                    <p class="app-modal-subtitle">Review profile details and assignments.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body app-modal-body">
                <div class="text-center mb-4">
                    <div id="viewUserAvatar" class="user-avatar-small mx-auto" style="width: 80px; height: 80px; font-size: 28px;"></div>
                    <h5 class="mt-3 mb-1" id="viewUserName"></h5>
                    <span id="viewUserRole" class="role-badge"></span>
                </div>
                <div class="app-modal-panel">
                    <div class="app-modal-panel-title"><i class="bi bi-person-lines-fill"></i>User Snapshot</div>
                    <div class="app-modal-detail-list">
                        <div class="app-modal-detail-row">
                            <div class="app-modal-detail-label">Reference Code</div>
                            <div class="app-modal-detail-value" id="viewReferenceCode"></div>
                        </div>
                        <div class="app-modal-detail-row">
                            <div class="app-modal-detail-label">Full Name</div>
                            <div class="app-modal-detail-value" id="viewFullName"></div>
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
                            <div class="app-modal-detail-label">Contact Number</div>
                            <div class="app-modal-detail-value" id="viewContactNumber"></div>
                        </div>
                        <div class="app-modal-detail-row">
                            <div class="app-modal-detail-label">Status</div>
                            <div class="app-modal-detail-value"><span id="viewStatus" class="status-badge"></span></div>
                        </div>
                        <div class="app-modal-detail-row" id="viewGradeLevelRow">
                            <div class="app-modal-detail-label">Grade Level</div>
                            <div class="app-modal-detail-value" id="viewGradeLevel"></div>
                        </div>
                        <div class="app-modal-detail-row" id="viewSectionRow">
                            <div class="app-modal-detail-label">Section</div>
                            <div class="app-modal-detail-value" id="viewSection"></div>
                        </div>
                        <div class="app-modal-detail-row" id="viewTrackRow">
                            <div class="app-modal-detail-label">Track</div>
                            <div class="app-modal-detail-value" id="viewTrack"></div>
                        </div>
                        <div class="app-modal-detail-row" id="viewAssignedClassesRow">
                            <div class="app-modal-detail-label" id="viewAssignedLabel">Assigned Subjects</div>
                            <div class="app-modal-detail-value" id="viewAssignedClasses"></div>
                        </div>
                        <div class="app-modal-detail-row">
                            <div class="app-modal-detail-label">Created At</div>
                            <div class="app-modal-detail-value" id="viewCreatedAt"></div>
                        </div>
                        <div class="app-modal-detail-row">
                            <div class="app-modal-detail-label">Last Login</div>
                            <div class="app-modal-detail-value" id="viewLastLogin"></div>
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

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content app-modal-content">
            <div class="modal-header app-modal-header">
                <div>
                    <div class="app-modal-kicker"><i class="bi bi-pencil-square"></i>Update</div>
                    <h5 class="modal-title mb-0">Edit User</h5>
                    <p class="app-modal-subtitle">Update profile details and assignments.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body app-modal-body">
                <form id="editUserForm">
                    <input type="hidden" name="user_id" id="editUserId">
                    <div class="app-modal-stack">
                        <div class="app-modal-panel">
                            <div class="app-modal-panel-title"><i class="bi bi-sliders2"></i>Account Summary</div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Reference Code</label>
                                    <input type="text" id="editReferenceCode" class="form-control" readonly style="background-color: #f8f9fa;">
                                </div>
                                <div class="col-md-6 mb-0">
                                    <label class="form-label">Role</label>
                                    <input type="text" name="role" id="editRole" class="form-control" readonly style="background-color: #f8f9fa;">
                                </div>
                            </div>
                            <div class="row mt-1">
                                <div class="col-md-4 mb-3" id="editGradeLevelDiv" style="display: none;">
                                    <label class="form-label" id="editGradeLevelLabel">Grade Level <span class="text-danger">*</span></label>
                                    <select name="grade_level" id="editGradeLevel" class="form-select" onchange="editUpdateSections()">
                                        <option value="">Select Grade Level</option>
                                        <option value="11">Grade 11</option>
                                        <option value="12">Grade 12</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3" id="editTrackDiv" style="display: none;">
                                    <label class="form-label" id="editTrackLabel">Track <span class="text-danger">*</span></label>
                                    <select name="track" id="editTrack" class="form-select" onchange="editUpdateSections()">
                                        <option value="">Select Track</option>
                                        <option value="academic">Academic</option>
                                        <option value="techpro">TechPro</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-0" id="editSectionDiv" style="display: none;">
                                    <label class="form-label" id="editSectionLabel">Section <span class="text-danger">*</span></label>
                                    <select name="section" id="editSection" class="form-select" disabled onchange="editUpdateClassAvailabilityWarning()">
                                        <option value="">Select Grade & Track First</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Subject/Class Selection (Teacher only) -->
                        <div class="app-modal-panel mb-0" id="editClassDiv" style="display: none;">
                            <div class="app-modal-panel-title"><i class="bi bi-journal-check"></i>Teacher Subject Assignment</div>
                            <div class="class-checklist" id="editClassChecklist">
                            <?php 
                            // Fetch classes for edit checklist - show ALL classes without filtering
                            $classQuery2 = "SELECT id, class_name, grade_level, section, schedule FROM classes WHERE status = 'active' ORDER BY grade_level, section, class_name";
                            $classStmt2 = $db->query($classQuery2);
                            $classesList2 = $classStmt2->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($classesList2 as $class): 
                                $scheduleDisplay = $class['schedule'] ? htmlspecialchars($class['schedule']) : 'No schedule set';
                            ?>
                            <div class="form-check class-check-item">
                                <input class="form-check-input edit-class-checkbox" type="checkbox" name="class_ids[]" value="<?php echo $class['id']; ?>" id="edit_class_<?php echo $class['id']; ?>">
                                <label class="form-check-label" for="edit_class_<?php echo $class['id']; ?>">
                                    <strong><?php echo htmlspecialchars($class['class_name']); ?></strong>
                                    <small class="text-muted d-block"><?php if (!empty($class['grade_level']) || !empty($class['section'])): ?><?php if (!empty($class['grade_level'])): ?>Grade <?php echo htmlspecialchars((string)$class['grade_level']); ?><?php endif; ?><?php if (!empty($class['grade_level']) && !empty($class['section'])): ?> - <?php endif; ?><?php echo htmlspecialchars((string)($class['section'] ?? '')); ?><?php else: ?>Grade/section not set<?php endif; ?></small>
                                    <small class="text-info d-block"><i class="bi bi-clock me-1"></i><?php echo $scheduleDisplay; ?></small>
                                </label>
                            </div>
                            <?php endforeach; ?>
                            </div>
                            <div class="form-text text-muted">Select subjects now or transfer a subject if needed.</div>
                        </div>
                        <div class="app-modal-panel mb-0" id="editParentStudentDiv" style="display: none;">
                            <div class="app-modal-panel-title"><i class="bi bi-people"></i>Parent Link</div>
                            <label class="form-label">Link Student(s) <span class="text-danger">*</span></label>
                            <div class="input-group input-group-sm mb-2">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control" id="editParentStudentSearch" placeholder="Type student name or reference code to filter..." oninput="filterParentStudentList('editParentStudentSearch', '#editParentStudentChecklist .class-check-item')">
                                <button class="btn btn-outline-secondary" type="button" id="clearEditParentStudentSearchBtn" style="display:none;" onclick="clearParentSearch('editParentStudentSearch', '#editParentStudentChecklist .class-check-item', 'clearEditParentStudentSearchBtn')"><i class="bi bi-x-lg"></i></button>
                            </div>
                            <div class="class-checklist" id="editParentStudentChecklist">
                            <?php foreach ($studentsForParent as $student): ?>
                            <div class="form-check class-check-item" data-student-search="<?php echo htmlspecialchars(strtolower($student['last_name'] . ' ' . $student['first_name'] . ' ' . ($student['reference_code'] ?? ''))); ?>">
                                <input class="form-check-input edit-parent-student-checkbox" type="checkbox" name="linked_student_ids[]" value="<?php echo (int)$student['id']; ?>" id="edit_parent_student_<?php echo (int)$student['id']; ?>">
                                <label class="form-check-label" for="edit_parent_student_<?php echo (int)$student['id']; ?>">
                                    <strong><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></strong>
                                    <small class="text-muted d-block"><?php echo !empty($student['reference_code']) ? htmlspecialchars((string)$student['reference_code']) : 'No reference code'; ?></small>
                                </label>
                            </div>
                            <?php endforeach; ?>
                            </div>
                            <div class="form-text text-muted">Select at least 1 student.</div>
                        </div>

                        <div class="app-modal-panel">
                            <div class="app-modal-panel-title"><i class="bi bi-person-lines-fill"></i>Profile Details</div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" name="first_name" id="editFirstName" class="form-control" required oninput="capitalizeWords(this)">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Middle Name</label>
                                    <input type="text" name="middle_name" id="editMiddleName" class="form-control" oninput="capitalizeWords(this)">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" name="last_name" id="editLastName" class="form-control" required oninput="capitalizeWords(this)">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Sex <span class="text-danger">*</span></label>
                                    <select name="sex" id="editSex" class="form-select" required>
                                        <option value="">Select Sex</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Contact Number (Mobile)</label>
                                    <input type="text" name="contact_number" id="editContactNumber" class="form-control" placeholder="09XXXXXXXXX" maxlength="20" autocomplete="tel">
                                </div>
                            </div>
                        </div>
                        <div class="app-modal-note d-none" id="editClassAvailabilityWarning">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <div>
                                <strong>No matching classes yet</strong>
                                <p>No active class was found for the selected grade level and section.</p>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer app-modal-footer">
                <button type="button" class="btn btn-warning" onclick="resetUserPassword()">
                    <i class="bi bi-key me-2"></i>Reset Password
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="updateUserBtn" onclick="updateUser()">Save Changes</button>
            </div>
        </div>
    </div>
</div>
