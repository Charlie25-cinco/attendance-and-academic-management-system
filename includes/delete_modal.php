<?php
// Reusable Delete Confirmation Modal Component
// Usage: 
//   1. Include this file: include '../includes/delete_modal.php';
//   2. Call showDeleteModal(id, itemName) from JavaScript
//   3. Implement handleDelete(id) function in your page
?>
<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal-content app-modal-danger">
            <div class="modal-header app-modal-header">
                <div>
                    <div class="app-modal-kicker"><i class="bi bi-exclamation-triangle-fill"></i>Delete</div>
                    <h5 class="modal-title mb-0">Confirm Delete</h5>
                    <p class="app-modal-subtitle">This action cannot be undone.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body app-modal-body text-center">
                <div class="mb-3">
                    <i class="bi bi-trash-fill text-danger" style="font-size: 4rem;"></i>
                </div>
                <h5 class="mb-2">Are you sure?</h5>
                <p class="text-muted mb-0" id="deleteModalMessage">This action cannot be undone.</p>
            </div>
            <div class="modal-footer app-modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg me-2"></i>Cancel
                </button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="bi bi-trash me-2"></i>Yes, Delete
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // Global delete modal variables
    let deleteItemId = null;
    let deleteItemType = 'item';
    let deleteModal = null;

    function getDeleteModalInstance() {
        const modalEl = document.getElementById('deleteConfirmModal');
        if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            return null;
        }
        if (!deleteModal) {
            deleteModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        }
        return deleteModal;
    }
    
    // Show delete modal
    function showDeleteModal(id, itemName, itemType) {
        deleteItemId = id;
        deleteItemType = itemType || 'item';
        
        // Update message
        const message = itemName 
            ? `Are you sure you want to delete <strong>${escapeHtml(itemName)}</strong>? This action cannot be undone.`
            : `Are you sure you want to delete this ${deleteItemType}? This action cannot be undone.`;
        document.getElementById('deleteModalMessage').innerHTML = message;
        
        // Update button text
        const btnText = itemType ? `Yes, Delete ${capitalizeFirst(itemType)}` : 'Yes, Delete';
        document.getElementById('confirmDeleteBtn').innerHTML = `<i class="bi bi-trash me-2"></i>${btnText}`;
        
        const modalInstance = getDeleteModalInstance();
        if (modalInstance) {
            modalInstance.show();
        } else if (typeof showAppConfirm === 'function') {
            showAppConfirm({
                title: 'Confirm delete',
                subtitle: 'This action cannot be undone.',
                message: message,
                confirmText: btnText.replace(/^Yes,\s*/, ''),
                cancelText: 'Cancel',
                tone: 'danger',
                icon: 'bi-trash-fill'
            }).then((confirmed) => {
                if (confirmed && deleteItemId && typeof handleDelete === 'function') {
                    handleDelete(deleteItemId);
                }
            });
        } else {
            if (window.confirm('Are you sure you want to delete this ' + deleteItemType + '?')) {
                if (deleteItemId && typeof handleDelete === 'function') {
                    handleDelete(deleteItemId);
                }
            }
        }
    }
    
    // Confirm delete button handler
    document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
        if (deleteItemId && typeof handleDelete === 'function') {
            handleDelete(deleteItemId);
        }
        const modalInstance = getDeleteModalInstance();
        if (modalInstance) {
            modalInstance.hide();
        }
    });
    
    // Helper functions
    
    function capitalizeFirst(string) {
        return string.charAt(0).toUpperCase() + string.slice(1);
    }
</script>
<script>
(function() {
    var modal = document.getElementById('deleteConfirmModal');
    if (modal) document.body.appendChild(modal);
})();
</script>
