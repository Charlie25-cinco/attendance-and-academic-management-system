<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);
// Teacher Chat Page - Adviser communicates with parents
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../auth/login.php");
    exit();
}

$db = (new Database())->getConnection();
$teacherId = (int)($_SESSION['user_id'] ?? 0);

// Get parents linked to students in the teacher's advisory section.
$conversations = [];
if ($db) {
    try {
        $stmt = $db->prepare("
            SELECT DISTINCT
                u_parent.id AS parent_id,
                u_parent.first_name AS parent_first,
                u_parent.last_name AS parent_last,
                u_parent.email AS parent_email,
                u_student.id AS student_id,
                u_student.first_name AS student_first,
                u_student.last_name AS student_last,
                u_student.grade_level,
                u_student.section,
                (SELECT COUNT(*) FROM messages m WHERE m.from_user_id = u_parent.id AND m.to_user_id = ? AND m.is_read = 0) AS unread_count,
                (SELECT m2.message FROM messages m2
                 WHERE (m2.from_user_id = ? AND m2.to_user_id = u_parent.id)
                    OR (m2.from_user_id = u_parent.id AND m2.to_user_id = ?)
                 ORDER BY m2.created_at DESC LIMIT 1) AS last_message,
                (SELECT m3.created_at FROM messages m3
                 WHERE (m3.from_user_id = ? AND m3.to_user_id = u_parent.id)
                    OR (m3.from_user_id = u_parent.id AND m3.to_user_id = ?)
                 ORDER BY m3.created_at DESC LIMIT 1) AS last_message_at
            FROM parent_students ps
            JOIN users u_parent ON u_parent.id = ps.parent_id AND u_parent.role = 'parent' AND u_parent.status = 'active'
            JOIN users u_student ON u_student.id = ps.student_id AND u_student.status = 'active'
            JOIN classes c ON c.teacher_id = ?
                AND c.status = 'active'
                AND c.grade_level = u_student.grade_level
                AND " . sectionMatchSql('c.section', 'u_student.section') . "
            ORDER BY last_message_at DESC, u_student.last_name ASC
        ");
        $stmt->execute([$teacherId, $teacherId, $teacherId, $teacherId, $teacherId, $teacherId]);
        $rawConversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $conversationsByParent = [];
        foreach ($rawConversations as $row) {
            $pid = (int)($row['parent_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $studentName = trim(($row['student_first'] ?? '') . ' ' . ($row['student_last'] ?? ''));
            if (!isset($conversationsByParent[$pid])) {
                $row['student_names'] = $studentName !== '' ? [$studentName] : [];
                $conversationsByParent[$pid] = $row;
            } else {
                if ($studentName !== '' && !in_array($studentName, $conversationsByParent[$pid]['student_names'], true)) {
                    $conversationsByParent[$pid]['student_names'][] = $studentName;
                }
            }
        }
        $conversations = array_values($conversationsByParent);
    } catch (Throwable $e) {
        error_log('Teacher_Chat conversations error: ' . $e->getMessage());
    }
}

$selectedParentId = (int)($_GET['parent_id'] ?? 0);
$selectedConv = null;
$chatMessages = [];

if ($selectedParentId > 0 && $db) {
    // Verify this parent is linked to one of teacher's students
    foreach ($conversations as $conv) {
        if ((int)$conv['parent_id'] === $selectedParentId) {
            $selectedConv = $conv;
            break;
        }
    }
    if ($selectedConv) {
        try {
            // Mark messages from parent as read
            $db->prepare("UPDATE messages SET is_read = 1 WHERE from_user_id = ? AND to_user_id = ? AND is_read = 0")
               ->execute([$selectedParentId, $teacherId]);

            $msgStmt = $db->prepare("
                SELECT m.id, m.from_user_id, m.message, m.is_read, m.created_at,
                       u.first_name, u.last_name, u.role
                FROM messages m
                JOIN users u ON u.id = m.from_user_id
                WHERE (m.from_user_id = ? AND m.to_user_id = ?)
                   OR (m.from_user_id = ? AND m.to_user_id = ?)
                ORDER BY m.created_at ASC
                LIMIT 100
            ");
            $msgStmt->execute([$teacherId, $selectedParentId, $selectedParentId, $teacherId]);
            $chatMessages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Teacher_Chat messages error: ' . $e->getMessage());
        }
    }
}

$current_role = 'teacher';
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
<style>
.chat-layout { display:flex; gap:0; height:calc(100vh - 220px); min-height:400px; }
.chat-list { width:300px; flex-shrink:0; overflow-y:auto; }
.chat-window { flex:1; display:flex; flex-direction:column; }
.chat-list-item { cursor:pointer; }
.chat-list-avatar { width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:.85rem; flex-shrink:0; }
.chat-list-name { font-weight:600; font-size:.9rem; }
.chat-list-sub { font-size:.78rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:180px; }
.chat-header { padding:16px 20px; }
.chat-messages { flex:1; overflow-y:auto; padding:16px 20px; display:flex; flex-direction:column; gap:10px; }
.chat-bubble { max-width:70%; padding:10px 14px; border-radius:16px; font-size:.9rem; line-height:1.5; word-break:break-word; }
.chat-bubble.sent { border-bottom-right-radius:4px; align-self:flex-end; }
.chat-bubble.received { border-bottom-left-radius:4px; align-self:flex-start; }
.chat-time { font-size:.72rem; opacity:.65; margin-top:3px; }
.chat-input-area { padding:14px 16px; }
.chat-placeholder { flex:1; display:flex; align-items:center; justify-content:center; flex-direction:column; gap:10px; }
@media (max-width:768px) {
    .chat-list { width:100%; border-radius:12px; }
    .chat-layout { flex-direction:column; }
    .chat-window { border-radius:12px; min-height:350px; }
    <?php if ($selectedParentId > 0): ?>.chat-list { display:none; }<?php endif; ?>
}
</style>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
    <?php include '../includes/header.php'; ?>
        <div class="page-content">
            <div class="teacher-hero mb-4">
                <div class="teacher-hero-grid">
                    <div class="teacher-hero-copy">
                        <div class="app-detail-kicker mb-2">Adviser-Parent Messaging</div>
                        <h4 class="mb-2">Messages</h4>
                        <p class="mb-0">Communicate with parents of students in your advisory section about attendance, academic progress, and important updates.</p>
                        <div class="teacher-chip-row">
                            <span class="teacher-chip"><i class="bi bi-people"></i><?php echo number_format(count($conversations)); ?> parent conversations</span>
                            <span class="teacher-chip"><i class="bi bi-chat-dots"></i><?php echo $selectedParentId > 0 ? 'Active thread open' : 'Select a thread'; ?></span>
                        </div>
                    </div>
                    <div class="teacher-summary-panel">
                        <div class="teacher-summary-grid">
                            <div class="teacher-summary-stat">
                                <span class="teacher-summary-label">Unread Parents</span>
                                <span class="teacher-summary-value"><?php echo number_format(count(array_filter($conversations, fn($c) => (int)($c['unread_count'] ?? 0) > 0))); ?></span>
                            </div>
                            <div class="teacher-summary-stat">
                                <span class="teacher-summary-label">Selected</span>
                                <span class="teacher-summary-value"><?php echo $selectedParentId > 0 ? 'Yes' : 'No'; ?></span>
                            </div>
                        </div>
                        <p class="teacher-summary-note">Unread counters update from the parent side and the active thread marks messages as read when opened.</p>
                    </div>
                </div>
            </div>

            <div class="content-card p-0" style="overflow:hidden;">
                <div class="chat-layout teacher-chat-shell">
                    <!-- Conversation List -->
                    <div class="chat-list teacher-chat-list">
                        <div class="teacher-chat-section-label">
                            <i class="bi bi-people me-1"></i>Parents (<?php echo count($conversations); ?>)
                        </div>
                        <?php if (empty($conversations)): ?>
                        <div class="text-center text-muted py-5 px-3">
                            <i class="bi bi-chat-dots" style="font-size:2rem;opacity:.3;"></i>
                            <p class="mt-2 small">No parents linked to your advisory section yet.</p>
                        </div>
                        <?php endif; ?>
                        <?php foreach ($conversations as $conv): ?>
                        <?php
                        $pFirst = trim((string)($conv['parent_first'] ?? ''));
                        $pLast = trim((string)($conv['parent_last'] ?? ''));
                        $pName = trim("$pFirst $pLast") ?: 'Parent';
                        $initials = strtoupper(($pFirst !== '' ? $pFirst[0] : '') . ($pLast !== '' ? $pLast[0] : 'P'));
                        $sFirst = trim((string)($conv['student_first'] ?? ''));
                        $sLast = trim((string)($conv['student_last'] ?? ''));
                        $sName = !empty($conv['student_names']) ? implode(', ', $conv['student_names']) : trim("$sFirst $sLast");
                        $unread = (int)($conv['unread_count'] ?? 0);
                        $lastMsg = trim((string)($conv['last_message'] ?? ''));
                        $isActive = (int)$conv['parent_id'] === $selectedParentId;
                        ?>
                        <a href="?parent_id=<?php echo (int)$conv['parent_id']; ?>" class="teacher-chat-item chat-list-item text-decoration-none <?php echo $isActive ? 'active' : ''; ?>">
                            <div class="teacher-chat-avatar chat-list-avatar"><?php echo htmlspecialchars($initials); ?></div>
                            <div class="flex-grow-1 overflow-hidden">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="teacher-chat-name chat-list-name"><?php echo htmlspecialchars($pName); ?></div>
                                    <?php if ($unread > 0): ?>
                                    <span class="badge bg-danger rounded-pill"><?php echo $unread; ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="teacher-chat-sub chat-list-sub text-muted">Student: <?php echo htmlspecialchars($sName); ?></div>
                                <?php if ($lastMsg !== ''): ?>
                                <div class="teacher-chat-sub chat-list-sub"><?php echo htmlspecialchars(mb_substr($lastMsg, 0, 40) . (mb_strlen($lastMsg) > 40 ? '...' : '')); ?></div>
                                <?php endif; ?>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>

                    <!-- Chat Window -->
                    <div class="chat-window teacher-chat-pane">
                        <?php if ($selectedConv): ?>
                        <?php
                        $pName = trim(($selectedConv['parent_first'] ?? '') . ' ' . ($selectedConv['parent_last'] ?? ''));
                        $sName = !empty($selectedConv['student_names']) ? implode(', ', $selectedConv['student_names']) : trim(($selectedConv['student_first'] ?? '') . ' ' . ($selectedConv['student_last'] ?? ''));
                        ?>
                        <div class="chat-header teacher-chat-header d-flex align-items-center gap-3">
                            <a href="teacher_Chat.php" class="btn btn-sm btn-outline-secondary d-lg-none"><i class="bi bi-arrow-left"></i></a>
                            <div>
                                <div class="fw-bold"><?php echo htmlspecialchars($pName); ?></div>
                                <small class="text-muted">Parent of <?php echo htmlspecialchars($sName); ?> · Grade <?php echo (int)($selectedConv['grade_level'] ?? 0); ?> <?php echo htmlspecialchars($selectedConv['section'] ?? ''); ?></small>
                            </div>
                        </div>
                        <div class="chat-messages teacher-chat-messages" id="chatMessages">
                            <?php if (empty($chatMessages)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-chat-text" style="font-size:1.5rem;opacity:.3;"></i>
                                <p class="mt-2 small">No messages yet. Start the conversation!</p>
                            </div>
                            <?php endif; ?>
                            <?php foreach ($chatMessages as $msg): ?>
                            <?php $isSent = (int)$msg['from_user_id'] === $teacherId; ?>
                            <div class="d-flex flex-column <?php echo $isSent ? 'align-items-end' : 'align-items-start'; ?>">
                                <div class="chat-bubble teacher-chat-bubble <?php echo $isSent ? 'sent' : 'received'; ?>">
                                    <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                </div>
                                <div class="chat-time teacher-chat-time"><?php echo date('M d, g:i A', strtotime($msg['created_at'])); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="chat-input-area teacher-chat-input">
                            <form id="sendMsgForm" class="d-flex gap-2">
                                <input type="hidden" name="action" value="send_message">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                <input type="hidden" name="to_user_id" value="<?php echo $selectedParentId; ?>">
                                <input type="hidden" name="student_id" value="<?php echo (int)($selectedConv['student_id'] ?? 0); ?>">
                                <textarea name="message" id="msgInput" class="form-control" rows="2" placeholder="Type a message..." style="resize:none;" required maxlength="1000"></textarea>
                                <button type="submit" class="btn btn-primary-custom px-3" id="sendBtn">
                                    <i class="bi bi-send-fill"></i>
                                </button>
                            </form>
                        </div>
                        <?php else: ?>
                        <div class="chat-placeholder teacher-chat-empty">
                            <i class="bi bi-chat-heart" style="font-size:3rem;"></i>
                            <p class="mb-0">Select a parent to start a conversation</p>
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
    const chatActionUrl = 'teacher_Chat_Action.php';
    const selectedParentId = <?php echo $selectedParentId; ?>;

    // Auto-scroll to bottom
    function scrollToBottom() {
        const el = document.getElementById('chatMessages');
        if (el) el.scrollTop = el.scrollHeight;
    }
    scrollToBottom();

    // Send message
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
                if (res.success) {
                    msgInput.value = '';
                    loadNewMessages();
                } else {
                    showNotification(res.message || 'Failed to send.', 'danger');
                }
            })
            .catch(() => showNotification('Send failed.', 'danger'))
            .finally(() => { if (btn) btn.disabled = false; });
    });

    // Enter to send (Shift+Enter for newline)
    document.getElementById('msgInput')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            document.getElementById('sendMsgForm')?.dispatchEvent(new Event('submit'));
        }
    });

    // Poll for new messages
    let lastMsgId = <?php echo !empty($chatMessages) ? (int)end($chatMessages)['id'] : 0; ?>;

    function loadNewMessages() {
        if (!selectedParentId) return;
        fetch(chatActionUrl + '?action=get_messages&parent_id=' + selectedParentId + '&last_id=' + lastMsgId)
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
                        wrapper.innerHTML = '<div class="chat-bubble ' + (isSent ? 'sent' : 'received') + '">' +
                            msg.message.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>') +
                            '</div><div class="chat-time">' + msg.time + '</div>';                        container.appendChild(wrapper);
                        lastMsgId = Math.max(lastMsgId, msg.id);
                    });
                    scrollToBottom();
                }
            })
            .catch(() => {});
    }

    if (selectedParentId) {
        setInterval(loadNewMessages, 5000);
    }
    </script>
</body>
</html>
