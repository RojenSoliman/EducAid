// Rejection Modal Functions
let currentRejectStudentId = null;
let currentRejectStudentName = null;

function showRejectModal(studentId, studentName) {
    currentRejectStudentId = studentId;
    currentRejectStudentName = studentName;
    
    document.getElementById('rejectStudentName').textContent = studentName;
    
    // Reset form
    document.getElementById('rejectTypeDocument').checked = true;
    document.getElementById('documentReuploadSection').style.display = 'block';
    document.getElementById('archiveSection').style.display = 'none';
    document.querySelectorAll('input[name="rejected_documents[]"]').forEach(cb => cb.checked = false);
    document.getElementById('archiveReasonSelect').value = '';
    document.getElementById('rejectionNotes').value = '';
    
    // Create custom backdrop to dim student info modal
    let backdrop = document.getElementById('rejectModalBackdrop');
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.id = 'rejectModalBackdrop';
        backdrop.className = 'rejection-backdrop';
        document.body.appendChild(backdrop);
    }
    
    // Show backdrop
    backdrop.classList.add('show');
    
    // Show the rejection modal
    const rejectModalEl = document.getElementById('rejectModal');
    const modal = new bootstrap.Modal(rejectModalEl, {
        backdrop: false, // We're using our custom backdrop
        keyboard: false
    });
    modal.show();
    
    // Hide backdrop when modal is closed
    rejectModalEl.addEventListener('hidden.bs.modal', function() {
        backdrop.classList.remove('show');
    }, { once: true });
}

// Toggle between document rejection and archive
document.addEventListener('DOMContentLoaded', function() {
    const docRadio = document.getElementById('rejectTypeDocument');
    const archiveRadio = document.getElementById('rejectTypeArchive');
    const docSection = document.getElementById('documentReuploadSection');
    const archiveSection = document.getElementById('archiveSection');
    
    if (docRadio && archiveRadio) {
        docRadio.addEventListener('change', function() {
            if (this.checked) {
                docSection.style.display = 'block';
                archiveSection.style.display = 'none';
            }
        });
        
        archiveRadio.addEventListener('change', function() {
            if (this.checked) {
                docSection.style.display = 'none';
                archiveSection.style.display = 'block';
            }
        });
    }
});

function selectAllDocuments() {
    document.querySelectorAll('input[name="rejected_documents[]"]').forEach(cb => cb.checked = true);
}

function clearAllDocuments() {
    document.querySelectorAll('input[name="rejected_documents[]"]').forEach(cb => cb.checked = false);
}

function confirmRejectStudent() {
    const rejectionType = document.querySelector('input[name="rejection_type"]:checked').value;
    const rejectionNotes = document.getElementById('rejectionNotes').value.trim();
    
    if (rejectionType === 'document') {
        // Document rejection - check if at least one document is selected
        const selectedDocs = Array.from(document.querySelectorAll('input[name="rejected_documents[]"]:checked'));
        
        if (selectedDocs.length === 0) {
            alert('Please select at least one document to reject.');
            return;
        }
        
        const docNames = selectedDocs.map(cb => {
            const label = document.querySelector(`label[for="${cb.id}"]`);
            return label.querySelector('strong').textContent;
        }).join(', ');
        
        if (!confirm(`Reject the following documents for ${currentRejectStudentName}?\n\n${docNames}\n\nThe student will be able to re-upload only these documents.`)) {
            return;
        }
        
        // Get CSRF token from the rejection modal
        const csrfToken = document.querySelector('#rejectModal input[name="csrf_token"]').value;
        
        // Submit form
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="reject_applicant" value="1">
            <input type="hidden" name="student_id" value="${currentRejectStudentId}">
            <input type="hidden" name="rejection_type" value="document">
            <input type="hidden" name="rejection_notes" value="${rejectionNotes.replace(/"/g, '&quot;')}">
            <input type="hidden" name="csrf_token" value="${csrfToken}">
        `;
        
        selectedDocs.forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'rejected_documents[]';
            input.value = cb.value;
            form.appendChild(input);
        });
        
        document.body.appendChild(form);
        form.submit();
        
    } else if (rejectionType === 'archive') {
        // Archive rejection
        const archiveReason = document.getElementById('archiveReasonSelect').value;
        
        if (!archiveReason) {
            alert('Please select an archive reason.');
            return;
        }
        
        const finalReason = archiveReason === 'custom' ? rejectionNotes : archiveReason;
        
        if (!finalReason) {
            alert('Please specify the archive reason.');
            return;
        }
        
        if (!confirm(`ARCHIVE ${currentRejectStudentName}?\n\nReason: ${finalReason}\n\nThis student will be permanently archived and cannot log in.`)) {
            return;
        }
        
        // Get CSRF token from the rejection modal
        const csrfToken = document.querySelector('#rejectModal input[name="csrf_token"]').value;
        
        // Submit form
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="reject_applicant" value="1">
            <input type="hidden" name="student_id" value="${currentRejectStudentId}">
            <input type="hidden" name="rejection_type" value="archive">
            <input type="hidden" name="archive_reason" value="${finalReason.replace(/"/g, '&quot;')}">
            <input type="hidden" name="rejection_notes" value="${rejectionNotes.replace(/"/g, '&quot;')}">
            <input type="hidden" name="csrf_token" value="${csrfToken}">
        `;
        
        document.body.appendChild(form);
        form.submit();
    }
}
