<?php

if (!function_exists('trhPeriodBounds')) {
    function trhPeriodBounds($academicYear, $scope, $semester = null) {
        preg_match('/^(\d{4})-(\d{4})$/', (string)$academicYear, $m);
        $startYear = (int)($m[1] ?? date('Y'));
        $endYear = (int)($m[2] ?? ($startYear + 1));
        if ($scope === 'yearly' || $scope === '3_term' || $semester === '' || $semester === null) {
            return [$startYear . '-06-01', $endYear . '-05-31'];
        }
        if ($scope === 'semester' || $scope === '4_quarter') {
            if ($semester === 'S2' || $semester === 2) {
                return [$startYear . '-12-01', $endYear . '-05-31'];
            }
            return [$startYear . '-06-01', $startYear . '-11-30'];
        }
        return [$startYear . '-06-01', $startYear . '-11-30'];
    }
}

if (!function_exists('trhCurrentAcademicYear')) {
    function trhCurrentAcademicYear() {
        $year = (int)date('Y');
        $month = (int)date('n');
        $start = $month >= 6 ? $year : $year - 1;
        return $start . '-' . ($start + 1);
    }
}

if (!function_exists('trhHasAttendanceTerms')) {
    function trhHasAttendanceTerms($db) {
        $hasAttendanceYear = dbHasColumn($db, 'attendance', 'academic_year');
        $hasAttendanceSemester = dbHasColumn($db, 'attendance', 'semester');
        $hasAttendanceTerm = dbHasColumn($db, 'attendance', 'term');
        return $hasAttendanceYear && ($hasAttendanceSemester || $hasAttendanceTerm);
    }
}

if (!function_exists('trhFetchAdvisoryInfo')) {
    function trhFetchAdvisoryInfo($db, $teacherId) {
        $stmt = $db->prepare("SELECT id, grade_level, section
                              FROM classes
                              WHERE teacher_id = ? AND status = 'active'
                              ORDER BY id ASC
                              LIMIT 1");
        $stmt->execute([(int)$teacherId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }

        $profileStmt = $db->prepare("SELECT grade_level, section
                                     FROM users
                                     WHERE id = ? AND role = 'teacher'
                                     LIMIT 1");
        $profileStmt->execute([(int)$teacherId]);
        $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);
        if ($profile && $profile['grade_level'] !== null && trim((string)$profile['section']) !== '') {
            return [
                'id' => 0,
                'grade_level' => (int)$profile['grade_level'],
                'section' => trim((string)$profile['section'])
            ];
        }

        return null;
    }
}

if (!function_exists('trhFetchClassesForFilter')) {
    function trhFetchClassesForFilter($db, $teacherId, $mode, $advisoryInfo) {
        if ($mode === 'advisory' && is_array($advisoryInfo)) {
            $stmt = $db->prepare("SELECT c.id, c.class_name, c.grade_level, c.section
                                  FROM classes c
                                  WHERE c.status = 'active'
                                  AND c.grade_level = ?
                                  AND " . sectionMatchSql('c.section') . "
                                  ORDER BY c.class_name");
            $stmt->execute([(int)$advisoryInfo['grade_level'], (string)$advisoryInfo['section'], (string)$advisoryInfo['section']]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($mode === 'advisory') {
            return [];
        }

        $stmt = $db->prepare("SELECT c.id, c.class_name, c.grade_level, c.section
                              FROM class_subjects cs
                              JOIN classes c ON c.id = cs.class_id
                              WHERE c.status = 'active'
                              AND (
                                cs.teacher_id = ?
                                OR c.teacher_id = ?
                              )
                              GROUP BY c.id, c.class_name, c.grade_level, c.section
                              ORDER BY c.grade_level, c.section, c.class_name");
        $stmt->execute([(int)$teacherId, (int)$teacherId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('trhBuildAttendanceScope')) {
    function trhBuildAttendanceScope($teacherId, $mode, $advisoryInfo, $hasAttendanceTerms, $academicYear, $scope, $semesterNo, $dateFrom, $dateTo, $classId, $sex = '') {
        $sex = strtolower(trim((string)$sex));
        if (!in_array($sex, ['male', 'female'], true)) {
            $sex = '';
        }

        $where = [];
        $params = [];

        if ($mode === 'advisory') {
            if (is_array($advisoryInfo)) {
                $where[] = "EXISTS (
                    SELECT 1
                    FROM classes cscope
                    WHERE cscope.id = a.class_id
                    AND cscope.status = 'active'
                    AND cscope.grade_level = ?
                    AND " . sectionMatchSql('cscope.section') . "
                )";
                $params[] = (int)$advisoryInfo['grade_level'];
                $params[] = (string)$advisoryInfo['section'];
                $params[] = (string)$advisoryInfo['section'];
            } else {
                $where[] = "1 = 0";
            }
        } else {
            $where[] = "EXISTS (
                SELECT 1
                FROM classes csub
                LEFT JOIN class_subjects cs ON cs.class_id = csub.id
                WHERE csub.id = a.class_id
                AND csub.status = 'active'
                AND (
                    cs.teacher_id = ?
                    OR csub.teacher_id = ?
                )
            )";
            $params[] = (int)$teacherId;
            $params[] = (int)$teacherId;
        }

        if ($hasAttendanceTerms) {
            $where[] = "a.academic_year = ?";
            $params[] = (string)$academicYear;
            if ($scope === 'semester' || $scope === '4_quarter') {
                $where[] = "a.semester = ?";
                $params[] = (int)$semesterNo;
            } elseif ($scope === 'term' || $scope === '3_term') {
                $where[] = "a.term = ?";
                $params[] = $semesterNo;
            }
        } else {
            $where[] = "a.date BETWEEN ? AND ?";
            $params[] = (string)$dateFrom;
            $params[] = (string)$dateTo;
        }

        if ((int)$classId > 0) {
            $where[] = "a.class_id = ?";
            $params[] = (int)$classId;
        }
        if ($sex !== '') {
            $where[] = "EXISTS (
                SELECT 1
                FROM users ux
                WHERE ux.id = a.student_id
                AND LOWER(COALESCE(ux.sex, '')) = ?
            )";
            $params[] = $sex;
        }

        return [$where, $params];
    }
}
