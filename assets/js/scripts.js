document.addEventListener('DOMContentLoaded', () => {
    const MAX_FILES = 5;
    const MAX_SIZE = 15 * 1024 * 1024; // 15MB
    const ALLOWED_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];

    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');
    const previewContainer = document.getElementById('preview-container');
    const uploadBtn = document.getElementById('upload-btn');
    const progressContainer = document.getElementById('progress-container');
    const progressFill = document.getElementById('progress-fill');
    const progressText = document.getElementById('progress-text');
    const resultContainer = document.getElementById('result-container');
    const shareLink = document.getElementById('share-link');
    const copyBtn = document.getElementById('copy-btn');

    let filesToUpload = [];
    let isUploading = false;

    // Drag & Drop
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(event => {
        dropZone.addEventListener(event, preventDefault);
    });
    
    ['dragenter', 'dragover'].forEach(event => {
        dropZone.addEventListener(event, () => dropZone.classList.add('active'));
    });
    
    ['dragleave', 'drop'].forEach(event => {
        dropZone.addEventListener(event, () => dropZone.classList.remove('active'));
    });

    dropZone.addEventListener('drop', (e) => {
        preventDefault(e);
        handleFiles(e.dataTransfer.files);
    });

    dropZone.addEventListener('click', () => fileInput.click());

    fileInput.addEventListener('change', () => {
        handleFiles(fileInput.files);
        fileInput.value = '';
    });

    // Paste from clipboard
    document.addEventListener('paste', (e) => {
        const items = (e.clipboardData || e.originalEvent.clipboardData).items;
        const files = [];
        for (const item of items) {
            if (item.type.startsWith('image/')) {
                files.push(item.getAsFile());
            }
        }
        if (files.length > 0) {
            handleFiles(files);
        }
    });

    function preventDefault(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    function handleFiles(files) {
        if (isUploading) return;

        const newCount = filesToUpload.length + Array.from(files).length;
        if (newCount > MAX_FILES) {
            alert(`Maximum ${MAX_FILES} files allowed`);
            return;
        }

        Array.from(files).forEach(file => {
            // Validate type
            if (!ALLOWED_TYPES.includes(file.type)) {
                alert(`${file.name}: Invalid image type`);
                return;
            }

            // Validate size
            if (file.size > MAX_SIZE) {
                alert(`${file.name}: File too large (max 15MB)`);
                return;
            }

            filesToUpload.push(file);
            createPreview(file);
        });

        updateUploadButton();
    }

    function createPreview(file) {
        const reader = new FileReader();
        reader.onload = (e) => {
            const item = document.createElement('div');
            item.className = 'preview-item';
            item.innerHTML = `
                <img src="${e.target.result}" alt="Preview">
                <button class="remove-btn" title="Remove">×</button>
            `;

            item.querySelector('.remove-btn').addEventListener('click', (ev) => {
                ev.stopPropagation();
                filesToUpload = filesToUpload.filter(f => f !== file);
                item.remove();
                updateUploadButton();
            });

            previewContainer.appendChild(item);
        };
        reader.readAsDataURL(file);
    }

    function updateUploadButton() {
        uploadBtn.disabled = filesToUpload.length === 0 || isUploading;
    }

    uploadBtn.addEventListener('click', uploadFiles);

    function uploadFiles() {
        if (filesToUpload.length === 0 || isUploading) return;

        isUploading = true;
        const formData = new FormData();
        filesToUpload.forEach(file => {
            formData.append('images[]', file);
        });

        // Show progress, hide other elements
        dropZone.style.display = 'none';
        uploadBtn.style.display = 'none';
        previewContainer.style.display = 'none';
        progressContainer.style.display = 'block';
        progressFill.style.width = '0%';
        progressText.innerText = '0%';

        const xhr = new XMLHttpRequest();

        // Track upload progress
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                progressFill.style.width = percent + '%';
                progressText.innerText = percent + '%';
            }
        });

        // Handle completion
        xhr.addEventListener('load', () => {
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    showSuccess(response.link, response.files);
                } else {
                    showError(response.message || 'Upload failed');
                }
            } catch (e) {
                showError('Server error');
            }
            isUploading = false;
        });

        // Handle errors
        xhr.addEventListener('error', () => {
            showError('Network error');
            isUploading = false;
        });

        xhr.addEventListener('abort', () => {
            showError('Upload cancelled');
            isUploading = false;
        });

        xhr.open('POST', '/upload/', true);
        xhr.send(formData);
    }

    function showSuccess(link, fileCount) {
        progressContainer.style.display = 'none';
        resultContainer.style.display = 'block';
        shareLink.value = link;
        
        // Scroll to result
        resultContainer.scrollIntoView({ behavior: 'smooth' });
    }

    function showError(message) {
        alert('Error: ' + message);
        resetUpload();
    }

    function resetUpload() {
        isUploading = false;
        dropZone.style.display = 'block';
        uploadBtn.style.display = 'inline-block';
        previewContainer.style.display = 'grid';
        progressContainer.style.display = 'none';
        filesToUpload = [];
        previewContainer.innerHTML = '';
        updateUploadButton();
    }

    // Copy to clipboard
    copyBtn.addEventListener('click', () => {
        shareLink.select();
        try {
            document.execCommand('copy');
            copyBtn.innerText = 'Copied!';
            setTimeout(() => {
                copyBtn.innerText = 'Copy';
            }, 2000);
        } catch (e) {
            // Fallback for older browsers
            alert('Copy the link manually');
        }
    });

    // --- Sidebar & History Logic ---
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const closeSidebar = document.getElementById('close-sidebar');
    const sidebarContent = document.getElementById('sidebar-content');
    const modalOverlay = document.getElementById('modal-overlay');
    const closeModal = document.getElementById('close-modal');
    const modalContent = document.getElementById('modal-content');
    const modalCopyBtn = document.getElementById('modal-copy-btn');

    let historyOffset = 0;
    const historyLimit = 15;
    let historyLoading = false;
    let hasMoreHistory = true;
    let currentModalLink = '';

    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.add('active');
        if (historyOffset === 0) loadHistory();
    });

    closeSidebar.addEventListener('click', () => {
        sidebar.classList.remove('active');
    });

    closeModal.addEventListener('click', () => {
        modalOverlay.classList.remove('active');
    });

    modalOverlay.addEventListener('click', (e) => {
        if (e.target === modalOverlay) modalOverlay.classList.remove('active');
    });

    async function loadHistory(append = false) {
        if (historyLoading || !hasMoreHistory) return;
        historyLoading = true;

        if (!append) {
            sidebarContent.innerHTML = '<div class="history-loader">Загрузка...</div>';
            historyOffset = 0;
        }

        try {
            const response = await fetch(`/api/history.php?offset=${historyOffset}&limit=${historyLimit}`);
            const data = await response.json();

            if (!append) sidebarContent.innerHTML = '';

            if (data.success) {
                if (data.items.length === 0 && !append) {
                    sidebarContent.innerHTML = '<div class="history-empty">История пуста</div>';
                } else {
                    data.items.forEach(item => {
                        const div = document.createElement('div');
                        div.className = 'history-item';
                        div.innerHTML = `
                            <img src="${item.preview_url || '/assets/img/og-preview.png'}" class="history-preview" alt="Preview">
                            <div class="history-info">
                                <span class="history-date">${item.created_at}</span>
                                <span class="history-stats">${item.files} фото</span>
                            </div>
                        `;
                        div.addEventListener('click', () => openHistoryModal(item));
                        sidebarContent.appendChild(div);
                    });

                    historyOffset += data.items.length;
                    hasMoreHistory = data.has_more;

                    if (hasMoreHistory) {
                        const moreBtn = document.createElement('div');
                        moreBtn.className = 'history-loader';
                        moreBtn.style.cursor = 'pointer';
                        moreBtn.innerText = 'Загрузить ещё...';
                        moreBtn.onclick = () => {
                            moreBtn.remove();
                            loadHistory(true);
                        };
                        sidebarContent.appendChild(moreBtn);
                    }
                }
            }
        } catch (e) {
            sidebarContent.innerHTML = '<div class="history-empty">Ошибка загрузки</div>';
        } finally {
            historyLoading = false;
        }
    }

    function openHistoryModal(item) {
        currentModalLink = window.location.origin + item.view_url;
        modalContent.innerHTML = `
            <div style="text-align: center; margin-bottom: 1.5rem;">
                <img src="${item.preview_url || '/assets/img/og-preview.png'}" style="max-width: 100%; border-radius: 1rem; border: 1px solid var(--border-color);">
            </div>
            <div style="display: grid; gap: 0.5rem; font-size: 0.9rem;">
                <p><strong>Дата:</strong> ${item.created_at}</p>
                <p><strong>Файлов:</strong> ${item.files}</p>
                <p><strong>Истекает:</strong> ${item.expires_at}</p>
                <p style="margin-top: 1rem; color: var(--text-muted); word-break: break-all;">
                    ${currentModalLink}
                </p>
            </div>
        `;
        modalOverlay.classList.add('active');
    }

    modalCopyBtn.addEventListener('click', () => {
        const tempInput = document.createElement('input');
        tempInput.value = currentModalLink;
        document.body.appendChild(tempInput);
        tempInput.select();
        document.execCommand('copy');
        document.body.removeChild(tempInput);
        
        const originalText = modalCopyBtn.innerText;
        modalCopyBtn.innerText = 'Скопировано!';
        modalCopyBtn.classList.add('success');
        setTimeout(() => {
            modalCopyBtn.innerText = originalText;
            modalCopyBtn.classList.remove('success');
        }, 2000);
    });

    // Lazy load logic for sidebar
    sidebarContent.addEventListener('scroll', () => {
        if (sidebarContent.scrollTop + sidebarContent.clientHeight >= sidebarContent.scrollHeight - 50) {
            loadHistory(true);
        }
    });
});
