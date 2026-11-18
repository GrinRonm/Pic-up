document.addEventListener('DOMContentLoaded', () => {
    const MAX_FILES = 10;
    const MAX_SIZE = 50 * 1024 * 1024; // 50MB

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
    const viewBatchBtn = document.getElementById('view-batch-btn');

    let filesToUpload = [];
    let isUploading = false;

    if (dropZone && fileInput) {
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
                if (item.kind === 'file') {
                    files.push(item.getAsFile());
                }
            }
            if (files.length > 0) {
                handleFiles(files);
            }
        });
    }

    function preventDefault(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function getFileIcon(type, name) {
        const ext = name.split('.').pop().toLowerCase();
        if (type.startsWith('image/')) return '🖼️';
        if (type.startsWith('video/') || ['mp4', 'mkv', 'webm', 'mov'].includes(ext)) return '🎬';
        if (type.startsWith('audio/') || ['mp3', 'wav', 'ogg', 'flac'].includes(ext)) return '🎵';
        if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) return '📦';
        if (['pdf', 'doc', 'docx', 'txt', 'md'].includes(ext)) return '📄';
        return '📁';
    }

    function handleFiles(files) {
        if (isUploading) return;

        const newCount = filesToUpload.length + Array.from(files).length;
        if (newCount > MAX_FILES) {
            alert(`Максимум ${MAX_FILES} файлов разрешено загрузить за раз`);
            return;
        }

        Array.from(files).forEach(file => {
            // Validate size
            if (file.size > MAX_SIZE) {
                alert(`Файл "${file.name}" превышает максимальный размер 50 МБ`);
                return;
            }

            filesToUpload.push(file);
            createPreview(file);
        });

        updateUploadButton();
    }

    function createPreview(file) {
        const item = document.createElement('div');
        item.className = 'preview-item';

        const isImg = file.type.startsWith('image/');

        if (isImg) {
            const reader = new FileReader();
            reader.onload = (e) => {
                const img = document.createElement('img');
                img.src = e.target.result;
                img.alt = file.name;
                item.appendChild(img);
            };
            reader.readAsDataURL(file);
        } else {
            const genericDiv = document.createElement('div');
            genericDiv.className = 'preview-generic';
            const icon = getFileIcon(file.type, file.name);
            genericDiv.innerHTML = `
                <div class="preview-generic-icon">${icon}</div>
                <div class="preview-generic-name" title="${file.name}">${file.name}</div>
                <div style="font-size: 0.75rem; color: var(--text-dim);">${formatBytes(file.size)}</div>
            `;
            item.appendChild(genericDiv);
        }

        const removeBtn = document.createElement('button');
        removeBtn.className = 'remove-btn';
        removeBtn.innerHTML = '&times;';
        removeBtn.title = 'Удалить';
        removeBtn.addEventListener('click', (ev) => {
            ev.stopPropagation();
            filesToUpload = filesToUpload.filter(f => f !== file);
            item.remove();
            updateUploadButton();
        });

        item.appendChild(removeBtn);
        previewContainer.appendChild(item);
    }

    function updateUploadButton() {
        if (uploadBtn) {
            uploadBtn.disabled = filesToUpload.length === 0 || isUploading;
        }
    }

    if (uploadBtn) {
        uploadBtn.addEventListener('click', uploadFiles);
    }

    function uploadFiles() {
        if (filesToUpload.length === 0 || isUploading) return;

        isUploading = true;
        const formData = new FormData();
        filesToUpload.forEach(file => {
            formData.append('files', file);
        });

        // Hide inputs, show progress
        dropZone.style.display = 'none';
        uploadBtn.style.display = 'none';
        previewContainer.style.display = 'none';
        progressContainer.style.display = 'block';
        progressFill.style.width = '0%';
        progressText.innerText = '0%';

        const xhr = new XMLHttpRequest();

        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                progressFill.style.width = percent + '%';
                progressText.innerText = percent + '%';
            }
        });

        xhr.addEventListener('load', () => {
            try {
                const response = JSON.parse(xhr.responseText);
                if (xhr.status === 200 && (response.success || response.link)) {
                    showSuccess(response.view_url || response.link);
                } else {
                    showError(response.detail || response.message || 'Ошибка загрузки');
                }
            } catch (e) {
                showError('Ошибка парсинга ответа сервера');
            }
            isUploading = false;
        });

        xhr.addEventListener('error', () => {
            showError('Сетевая ошибка при загрузке');
            isUploading = false;
        });

        xhr.open('POST', '/api/upload', true);
        xhr.send(formData);
    }

    function showSuccess(link) {
        progressContainer.style.display = 'none';
        resultContainer.style.display = 'block';
        shareLink.value = link;
        if (viewBatchBtn) {
            viewBatchBtn.href = link;
        }
        resultContainer.scrollIntoView({ behavior: 'smooth' });
    }

    function showError(message) {
        alert('Ошибка: ' + message);
        resetUpload();
    }

    function resetUpload() {
        isUploading = false;
        if (dropZone) dropZone.style.display = 'block';
        if (uploadBtn) uploadBtn.style.display = 'inline-flex';
        if (previewContainer) {
            previewContainer.style.display = 'grid';
            previewContainer.innerHTML = '';
        }
        if (progressContainer) progressContainer.style.display = 'none';
        filesToUpload = [];
        updateUploadButton();
    }

    if (copyBtn) {
        copyBtn.addEventListener('click', () => {
            shareLink.select();
            navigator.clipboard.writeText(shareLink.value).then(() => {
                const orig = copyBtn.innerText;
                copyBtn.innerText = 'Скопировано!';
                copyBtn.style.background = 'var(--primary)';
                copyBtn.style.color = '#042f2e';
                setTimeout(() => {
                    copyBtn.innerText = orig;
                    copyBtn.style.background = '';
                    copyBtn.style.color = '';
                }, 2000);
            }).catch(() => {
                alert('Скопируйте ссылку вручную');
            });
        });
    }

    // --- Sidebar & History ---
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

    if (sidebarToggle && sidebar && closeSidebar) {
        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.add('active');
            if (historyOffset === 0) loadHistory();
        });

        closeSidebar.addEventListener('click', () => {
            sidebar.classList.remove('active');
        });
    }

    if (closeModal && modalOverlay) {
        closeModal.addEventListener('click', () => {
            modalOverlay.classList.remove('active');
        });
        modalOverlay.addEventListener('click', (e) => {
            if (e.target === modalOverlay) modalOverlay.classList.remove('active');
        });
    }

    async function loadHistory(append = false) {
        if (!sidebarContent || historyLoading || !hasMoreHistory) return;
        historyLoading = true;

        if (!append) {
            sidebarContent.innerHTML = '<div class="history-loader" style="text-align: center; color: var(--text-muted); padding: 2rem;">Загрузка...</div>';
            historyOffset = 0;
        }

        try {
            const response = await fetch(`/api/history?offset=${historyOffset}&limit=${historyLimit}`);
            const data = await response.json();

            if (!append) sidebarContent.innerHTML = '';

            if (data.success) {
                if (data.items.length === 0 && !append) {
                    sidebarContent.innerHTML = '<div class="history-empty" style="text-align: center; color: var(--text-muted); padding: 2rem;">История пуста</div>';
                } else {
                    data.items.forEach(item => {
                        const div = document.createElement('div');
                        div.className = 'history-item';
                        
                        let previewHtml = '';
                        if (item.first_file_type === 'image' && item.preview_url) {
                            previewHtml = `<img src="${item.preview_url}" class="history-preview" alt="Preview">`;
                        } else {
                            const icon = getFileIcon(item.first_file_type || '', '');
                            previewHtml = `<div class="history-preview">${icon}</div>`;
                        }

                        div.innerHTML = `
                            ${previewHtml}
                            <div class="history-info">
                                <span class="history-date">${item.created_at}</span>
                                <span class="history-stats">${item.files} файл(ов)</span>
                            </div>
                        `;
                        div.addEventListener('click', () => {
                            window.location.href = item.view_url;
                        });
                        sidebarContent.appendChild(div);
                    });

                    historyOffset += data.items.length;
                    hasMoreHistory = data.has_more;
                }
            }
        } catch (e) {
            if (sidebarContent) {
                sidebarContent.innerHTML = '<div style="color: var(--danger); text-align: center; padding: 2rem;">Ошибка загрузки</div>';
            }
        } finally {
            historyLoading = false;
        }
    }
});
