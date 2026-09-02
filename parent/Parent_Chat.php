<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
// Parent Chat Page - Parent communicates with child's adviser
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'parent') {
    header("Location: ../auth/login.php");
    exit();
}

$db = (new Database())->getConnection();
$parentId = (int)($_SESSION['user_id'] ?? 0);

// Get children and their advisers.
$conversations = [];
if ($db) {
    try {
        $stmt = $db->prepare("
            SELECT DISTINCT
                u_teacher.id AS teacher_id,
                u_teacher.first_name AS teacher_first,
                u_teacher.last_name AS teacher_last,
                u_teacher.email AS teacher_email,
                u_student.id AS student_id,
                u_student.first_name AS student_first,
                u_student.last_name AS student_last,
                u_student.grade_level,
                u_student.section,
                (SELECT COUNT(*) FROM messages m WHERE m.from_user_id = u_teacher.id AND m.to_user_id = ? AND m.is_read = 0) AS unread_count,
                (SELECT m2.message FROM messages m2
                 WHERE (m2.from_user_id = ? AND m2.to_user_id = u_teacher.id)
                    OR (m2.from_user_id = u_teacher.id AND m2.to_user_id = ?)
                 ORDER BY m2.created_at DESC LIMIT 1) AS last_message,
                (SELECT m3.created_at FROM messages m3
                 WHERE (m3.from_user_id = ? AND m3.to_user_id = u_teacher.id)
                    OR (m3.from_user_id = u_teacher.id AND m3.to_user_id = ?)
                 ORDER BY m3.created_at DESC LIMIT 1) AS last_message_at
            FROM parent_students ps
            JOIN users u_student ON u_student.id = ps.student_id AND u_student.status = 'active'
            JOIN classes c ON c.status = 'active'
                AND c.teacher_id IS NOT NULL
                AND c.grade_level = u_student.grade_level
                AND " . sectionMatchSql('c.section', 'u_student.section') . "
            JOIN users u_teacher ON u_teacher.id = c.teacher_id AND u_teacher.role = 'teacher' AND u_teacher.status = 'active'
            WHERE ps.parent_id = ?
            ORDER BY last_message_at DESC, u_teacher.last_name ASC
        ");
        $stmt->execute([$parentId, $parentId, $parentId, $parentId, $parentId, $parentId]);
        $rawConversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $conversationsByTeacher = [];
        foreach ($rawConversations as $row) {
            $tid = (int)($row['teacher_id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            $studentName = trim(($row['student_first'] ?? '') . ' ' . ($row['student_last'] ?? ''));
            if (!isset($conversationsByTeacher[$tid])) {
                $row['student_names'] = $studentName !== '' ? [$studentName] : [];
                $conversationsByTeacher[$tid] = $row;
            } else {
                if ($studentName !== '' && !in_array($studentName, $conversationsByTeacher[$tid]['student_names'], true)) {
                    $conversationsByTeacher[$tid]['student_names'][] = $studentName;
                }
            }
        }
        $conversations = array_values($conversationsByTeacher);
    } catch (Throwable $e) {
        error_log('Parent_Chat conversations error: ' . $e->getMessage());
    }
}

$selectedTeacherId = (int)($_GET['teacher_id'] ?? 0);
$selectedConv = null;
$chatMessages = [];

if ($selectedTeacherId > 0 && $db) {
    foreach ($conversations as $conv) {
        if ((int)$conv['teacher_id'] === $selectedTeacherId) {
            $selectedConv = $conv;
            break;
        }
    }
    if ($selectedConv) {
        try {
            $db->prepare("UPDATE messages SET is_read = 1 WHERE from_user_id = ? AND to_user_id = ? AND is_read = 0")
               ->execute([$selectedTeacherId, $parentId]);

            $msgStmt = $db->prepare("
                SELECT m.id, m.from_user_id, m.message, m.is_read, m.created_at
                FROM messages m
                WHERE (m.from_user_id = ? AND m.to_user_id = ?)
                   OR (m.from_user_id = ? AND m.to_user_id = ?)
                ORDER BY m.created_at ASC
                LIMIT 100
            ");
            $msgStmt->execute([$parentId, $selectedTeacherId, $selectedTeacherId, $parentId]);
            $chatMessages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Parent_Chat messages error: ' . $e->getMessage());
        }
    }
}

$current_role = 'parent';
$current_page = 'chat';
$page_title = 'Messages';
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
            <section class="parent-hero parent-hero-compact mb-4">
                <div class="parent-hero-grid">
                    <div class="parent-hero-main">
                        <div class="welcome-role-chip"><i class="bi bi-chat-dots"></i><span>Parent Messages</span></div>
                        <h4 class="mb-2">Coordinate with your child's adviser in one shared inbox.</h4>
                        <p class="text-muted mb-3">Open an adviser thread, review unread updates, and send quick follow-ups about your child's section.</p>
                        <div class="parent-chip-row">
                            <span class="parent-chip"><i class="bi bi-people"></i><?php echo number_format(count($conversations)); ?> conversation<?php echo count($conversations) === 1 ? '' : 's'; ?></span>
                            <?php if ($selectedConv): ?><span class="parent-chip"><i class="bi bi-person-badge"></i><?php echo htmlspecialchars(trim(($selectedConv['teacher_first'] ?? '') . ' ' . ($selectedConv['teacher_last'] ?? ''))); ?></span><?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <div class="content-card p-0" style="overflow:hidden;">
                <div class="parent-chat-layout">
                    <!-- Conversation List -->
                    <div class="parent-chat-list">
                        <div class="parent-chat-section-label">
                            <i class="bi bi-person-badge me-1"></i>Advisers
                        </div>
                        <?php if (empty($conversations)): ?>
                        <div class="text-center text-muted py-5 px-3">
                            <i class="bi bi-chat-dots" style="font-size:2rem;opacity:.3;"></i>
                            <p class="mt-2 small">No adviser linked to your child's section yet.</p>
                        </div>
                        <?php endif; ?>
                        <?php foreach ($conversations as $conv): ?>
                        <?php
                        $tFirst = trim((string)($conv['teacher_first'] ?? ''));
                        $tLast = trim((string)($conv['teacher_last'] ?? ''));
                        $tName = trim("$tFirst $tLast") ?: 'Teacher';
                        $initials = strtoupper(($tFirst !== '' ? $tFirst[0] : '') . ($tLast !== '' ? $tLast[0] : 'T'));
                        $sFirst = trim((string)($conv['student_first'] ?? ''));
                        $sLast = trim((string)($conv['student_last'] ?? ''));
                        $sName = !empty($conv['student_names']) ? implode(', ', $conv['student_names']) : trim("$sFirst $sLast");
                        $unread = (int)($conv['unread_count'] ?? 0);
                        $lastMsg = trim((string)($conv['last_message'] ?? ''));
                        $isActive = (int)$conv['teacher_id'] === $selectedTeacherId;
                        ?>
                        <a href="?teacher_id=<?php echo (int)$conv['teacher_id']; ?>" class="d-flex align-items-center gap-2 parent-chat-item text-decoration-none <?php echo $isActive ? 'active' : ''; ?>">
                            <div class="parent-chat-avatar"><?php echo htmlspecialchars($initials); ?></div>
                            <div class="flex-grow-1 overflow-hidden">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="parent-chat-name"><?php echo htmlspecialchars($tName); ?></div>
                                    <?php if ($unread > 0): ?>
                                    <span class="badge bg-danger rounded-pill"><?php echo $unread; ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="parent-chat-sub text-muted">For: <?php echo htmlspecialchars($sName); ?></div>
                                <?php if ($lastMsg !== ''): ?>
                                <div class="parent-chat-sub"><?php echo htmlspecialchars(mb_substr($lastMsg, 0, 40) . (mb_strlen($lastMsg) > 40 ? '...' : '')); ?></div>
                                <?php endif; ?>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>

                    <!-- Chat Window -->
                    <div class="parent-chat-window">
                        <?php if ($selectedConv): ?>
                        <?php
                        $tName = trim(($selectedConv['teacher_first'] ?? '') . ' ' . ($selectedConv['teacher_last'] ?? ''));
                        $sName = !empty($selectedConv['student_names']) ? implode(', ', $selectedConv['student_names']) : trim(($selectedConv['student_first'] ?? '') . ' ' . ($selectedConv['student_last'] ?? ''));
                        ?>
                        <div class="parent-chat-header d-flex align-items-center gap-3">
                            <a href="Parent_Chat.php" class="btn btn-sm btn-outline-secondary d-lg-none"><i class="bi bi-arrow-left"></i></a>
                            <div>
                                <div class="fw-bold"><?php echo htmlspecialchars($tName); ?></div>
                                <small class="text-muted">Adviser of <?php echo htmlspecialchars($sName); ?><?php if (!empty($selectedConv['grade_level']) || !empty($selectedConv['section'])): ?> · <?php if (!empty($selectedConv['grade_level'])): ?>Grade <?php echo (int)$selectedConv['grade_level']; ?><?php endif; ?><?php if (!empty($selectedConv['grade_level']) && !empty($selectedConv['section'])): ?> <?php endif; ?><?php echo htmlspecialchars((string)($selectedConv['section'] ?? '')); ?><?php endif; ?></small>
                            </div>
                        </div>
                        <div class="parent-chat-messages" id="chatMessages">
                            <?php if (empty($chatMessages)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-chat-text" style="font-size:1.5rem;opacity:.3;"></i>
                                <p class="mt-2 small">No messages yet. Start the conversation!</p>
                            </div>
                            <?php endif; ?>
                            <?php foreach ($chatMessages as $msg): ?>
                            <?php $isSent = (int)$msg['from_user_id'] === $parentId; ?>
                            <div class="d-flex flex-column <?php echo $isSent ? 'align-items-end' : 'align-items-start'; ?>">
                                <div class="parent-chat-bubble <?php echo $isSent ? 'sent' : 'received'; ?>">
                                    <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                </div>
                                <div class="parent-chat-time"><?php echo date('M d, g:i A', strtotime($msg['created_at'])); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="parent-chat-input">
                            <form id="sendMsgForm" class="d-flex gap-2">
                                <input type="hidden" name="action" value="send_message">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                <input type="hidden" name="to_user_id" value="<?php echo $selectedTeacherId; ?>">
                                <input type="hidden" name="student_id" value="<?php echo (int)($selectedConv['student_id'] ?? 0); ?>">
                                <textarea name="message" id="msgInput" class="form-control" rows="2" placeholder="Type a message..." style="resize:none;" required maxlength="1000"></textarea>
                                <button type="submit" class="btn btn-primary-custom px-3" id="sendBtn">
                                    <i class="bi bi-send-fill"></i>
                                </button>
                            </form>
                        </div>
                        <?php else: ?>
                        <div class="parent-chat-placeholder">
                            <i class="bi bi-chat-heart" style="font-size:3rem;"></i>
                            <p class="mb-0">Select an adviser to start a conversation</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo appAssetPath('vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
    <script src="<?php echo appAssetPath('js/main.js'); ?>"></script>
    <script>
    const chatActionUrl = 'Parent_Chat_Action.php';
    const selectedTeacherId = <?php echo $selectedTeacherId; ?>;

    function scrollToBottom() {
        const el = document.getElementById('chatMessages');
        if (el) el.scrollTop = el.scrollHeight;
    }
    scrollToBottom();

    document.getElementById('sendMsgForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        const msgInput = document.getElementById('msgInput');
        const msg = msgInput?.value.trim();
        if (!msg) return;
        const btn = document.getElementById('sendBtn');
        if (btn) btn.disabled = true;
        const data = new URLSearchParams(new FormData(this));
        fetch(chatActionUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: data })
            .then(r => r.json())
            .then(res => {
                if (res.success) { msgInput.value = ''; loadNewMessages(); }
                else showNotification(res.message || 'Failed to send.', 'danger');
            })
            .catch(() => showNotification('Send failed.', 'danger'))
            .finally(() => { if (btn) btn.disabled = false; });
    });

    document.getElementById('msgInput')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            document.getElementById('sendMsgForm')?.dispatchEvent(new Event('submit'));
        }
    });

    let lastMsgId = <?php echo !empty($chatMessages) ? (int)end($chatMessages)['id'] : 0; ?>;

    function loadNewMessages() {
        if (!selectedTeacherId) return;
        fetch(chatActionUrl + '?action=get_messages&teacher_id=' + selectedTeacherId + '&last_id=' + lastMsgId)
            .then(r => r.json())
            .then(data => {
                if (data.messages && data.messages.length > 0) {
                    const container = document.getElementById('chatMessages');
                    if (!container) return;
                    const isEmpty = container.querySelector('.text-center');
                    if (isEmpty) isEmpty.remove();
                    data.messages.forEach(msg => {
                        const isSent = msg.is_mine;
                        const wrapper = document.createElement('div');
                        wrapper.className = 'd-flex flex-column ' + (isSent ? 'align-items-end' : 'align-items-start');
                        wrapper.innerHTML = '<div class="parent-chat-bubble ' + (isSent ? 'sent' : 'received') + '">' +
                            msg.message.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>') +
                            '</div><div class="parent-chat-time">' + msg.time + '</div>';
                        container.appendChild(wrapper);
                        lastMsgId = Math.max(lastMsgId, msg.id);
                    });
                    scrollToBottom();
                }
            })
            .catch(() => {});
    }

    if (selectedTeacherId) setInterval(loadNewMessages, 5000);
    </script>
</body>
</html>
