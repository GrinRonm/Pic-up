// Pic-up v2.0 - Core Interactive Logic & Toast System

document.addEventListener('DOMContentLoaded', () => {
    const MAX_FILES = 10;
    const MAX_SIZE = 50 * 1024 * 1024; // 50MB

    // Elements
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');
    const previewSection = document.getElementById('preview-section');
    const previewContainer = document.getElementById('preview-container');
    const previewCount = document.getElementById('preview-count');
    const clearAllBtn = document.getElementById('clear-all-btn');
    const uploadBtn = document.getElementById('upload-btn');
    const stationActions = document.getElementById('station-actions');

    const progressContainer = document.getElementById('progress-container');
    const progressFill = document.getElementById('progress-fill');
    const progressText = document.getElementById('progress-text');
    const progressBytes = document.getElementById('progress-bytes');
    const progressStatusText = document.getElementById('progress-status-text');

    const resultContainer = document.getElementById('result-container');
    const shareLink = document.getElementById('share-link');
    const copyBtn = document.getElementById('copy-btn');
    const viewBatchBtn = document.getElementById('view-batch-btn');

    let filesToUpload = [];
    let isUploading = false;

    // --- Toast System ---
    window.showToast = function(message, type = 'info') {
        const container = document.getElementById('toast-container');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = 'toast';
        
        let iconSvg = `
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
        `;
        if (type === 'error') {
            iconSvg = `
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="15" y1="9" x2="9" y2="15"></line>
                    <line x1="9" y1="9" x2="15" y2="15"></line>
                </svg>
            `;
        }

        toast.innerHTML = `${iconSvg} <span>${message}</span>`;
        container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px) scale(0.9)';
            toast.style.transition = 'all 0.25s ease';
            setTimeout(() => toast.remove(), 250);
        }, 3000);
    };

    // --- Drag and Drop Handlers ---
    if (dropZone && fileInput) {
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

        // Clipboard Paste
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
                window.showToast(`Добавлено ${files.length} файл(ов) из буфера обмена`);
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

    function getExtension(name) {
        return name.split('.').pop().toUpperCase() || 'FILE';
    }

    function handleFiles(files) {
        if (isUploading) return;

        const incoming = Array.from(files);
        if (filesToUpload.length + incoming.length > MAX_FILES) {
            window.showToast(`Максимум ${MAX_FILES} файлов разрешено загрузить за раз`, 'error');
            return;
        }

        let addedCount = 0;
        incoming.forEach(file => {
            if (file.size > MAX_SIZE) {
                window.showToast(`Файл "${file.name}" превышает 50 МБ`, 'error');
                return;
            }
            filesToUpload.push(file);
            createPreviewCard(file);
            addedCount++;
        });

        if (addedCount > 0) {
            updateUIState();
        }
    }

    function createPreviewCard(file) {
        if (!previewContainer) return;

        const card = document.createElement('div');
        card.className = 'preview-card-item';

        const isImg = file.type.startsWith('image/');
        const ext = getExtension(file.name);

        if (isImg) {
            const reader = new FileReader();
            reader.onload = (e) => {
                const img = document.createElement('img');
                img.src = e.target.result;
                img.alt = file.name;
                const overlay = document.createElement('div');
                overlay.className = 'preview-img-overlay';
                card.prepend(overlay);
                card.prepend(img);
            };
            reader.readAsDataURL(file);

            card.innerHTML = `
                <div></div>
                <div style="z-index: 2;">
                    <div class="preview-file-name" title="${file.name}">${file.name}</div>
                    <div class="preview-file-size">${formatBytes(file.size)}</div>
                </div>
            `;
        } else {
            card.innerHTML = `
                <div class="preview-generic-box">
                    <div class="file-type-pill">${ext}</div>
                    <div class="preview-file-name" title="${file.name}">${file.name}</div>
                    <div class="preview-file-size">${formatBytes(file.size)}</div>
                </div>
            `;
        }

        const removeBtn = document.createElement('button');
        removeBtn.className = 'btn-remove-preview';
        removeBtn.innerHTML = '&times;';
        removeBtn.title = 'Удалить из списка';
        removeBtn.addEventListener('click', (ev) => {
            ev.stopPropagation();
            filesToUpload = filesToUpload.filter(f => f !== file);
            card.remove();
            updateUIState();
        });

        card.appendChild(removeBtn);
        previewContainer.appendChild(card);
    }

    function updateUIState() {
        const count = filesToUpload.length;
        if (uploadBtn) {
            uploadBtn.disabled = count === 0 || isUploading;
        }

        if (previewSection && previewCount) {
            if (count > 0) {
                previewSection.style.display = 'block';
                previewCount.innerText = `${count} ${pluralizeFiles(count)}`;
            } else {
                previewSection.style.display = 'none';
            }
        }
    }

    function pluralizeFiles(n) {
        if (n % 10 === 1 && n % 100 !== 11) return 'файл';
        if ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100)) return 'файла';
        return 'файлов';
    }

    if (clearAllBtn) {
        clearAllBtn.addEventListener('click', () => {
            filesToUpload = [];
            if (previewContainer) previewContainer.innerHTML = '';
            updateUIState();
        });
    }

    // --- Upload Trigger ---
    if (uploadBtn) {
        uploadBtn.addEventListener('click', startUpload);
    }

    function startUpload() {
        if (filesToUpload.length === 0 || isUploading) return;

        isUploading = true;
        const formData = new FormData();
        filesToUpload.forEach(file => {
            formData.append('files', file);
        });

        // Hide inputs, show progress
        if (dropZone) dropZone.style.display = 'none';
        if (previewSection) previewSection.style.display = 'none';
        if (stationActions) stationActions.style.display = 'none';
        if (progressContainer) progressContainer.style.display = 'block';

        progressFill.style.width = '0%';
        progressText.innerText = '0%';
        if (progressStatusText) progressStatusText.innerText = 'Загрузка файлов на сервер...';

        const xhr = new XMLHttpRequest();
        let totalBytes = filesToUpload.reduce((acc, f) => acc + f.size, 0);

        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                progressFill.style.width = percent + '%';
                progressText.innerText = percent + '%';
                if (progressBytes) {
                    progressBytes.innerText = `${formatBytes(e.loaded)} из ${formatBytes(e.total)}`;
                }
                if (percent >= 100 && progressStatusText) {
                    progressStatusText.innerText = 'Отправка в облачное хранилище Telegram...';
                }
            }
        });

        xhr.addEventListener('load', () => {
            try {
                const response = JSON.parse(xhr.responseText);
                if (xhr.status === 200 && (response.success || response.link)) {
                    showSuccess(response.view_url || response.link);
                    window.showToast('Файлы успешно загружены!');
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
        if (progressContainer) progressContainer.style.display = 'none';
        if (resultContainer) resultContainer.style.display = 'block';
        if (shareLink) shareLink.value = link;
        if (viewBatchBtn) viewBatchBtn.href = link;
        resultContainer.scrollIntoView({ behavior: 'smooth' });
    }

    function showError(message) {
        window.showToast(message, 'error');
        resetUpload();
    }

    function resetUpload() {
        isUploading = false;
        if (dropZone) dropZone.style.display = 'block';
        if (stationActions) stationActions.style.display = 'flex';
        if (progressContainer) progressContainer.style.display = 'none';
        filesToUpload = [];
        if (previewContainer) previewContainer.innerHTML = '';
        updateUIState();
    }

    // Copy Button with Toast
    if (copyBtn && shareLink) {
        copyBtn.addEventListener('click', () => {
            shareLink.select();
            navigator.clipboard.writeText(shareLink.value).then(() => {
                window.showToast('Ссылка скопирована в буфер обмена!');
            }).catch(() => {
                window.showToast('Скопируйте ссылку вручную', 'error');
            });
        });
    }

    // --- History Drawer Logic ---
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const closeSidebar = document.getElementById('close-sidebar');
    const backdrop = document.getElementById('drawer-backdrop');
    const sidebarContent = document.getElementById('sidebar-content');

    let historyLoaded = false;

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.add('active');
            if (backdrop) backdrop.classList.add('active');
            if (!historyLoaded) {
                loadHistory();
            }
        });

        const closeDrawer = () => {
            sidebar.classList.remove('active');
            if (backdrop) backdrop.classList.remove('active');
        };

        if (closeSidebar) closeSidebar.addEventListener('click', closeDrawer);
        if (backdrop) backdrop.addEventListener('click', closeDrawer);
    }

    async function loadHistory() {
        if (!sidebarContent) return;
        try {
            const res = await fetch('/api/history?offset=0&limit=30');
            const data = await res.json();
            sidebarContent.innerHTML = '';

            if (data.success && data.items && data.items.length > 0) {
                data.items.forEach(item => {
                    const el = document.createElement('div');
                    el.className = 'drawer-history-item';
                    
                    let thumbHtml = '';
                    if (item.first_file_type === 'image' && item.preview_url) {
                        thumbHtml = `<img src="${item.preview_url}" class="drawer-thumb" alt="Preview">`;
                    } else {
                        thumbHtml = `<div class="drawer-thumb">📁</div>`;
                    }

                    el.innerHTML = `
                        ${thumbHtml}
                        <div class="drawer-item-info">
                            <div class="drawer-item-title">#${item.code} (${item.files} файлов)</div>
                            <div class="drawer-item-date">${item.created_at}</div>
                        </div>
                    `;

                    el.addEventListener('click', () => {
                        window.location.href = item.view_url;
                    });
                    sidebarContent.appendChild(el);
                });
                historyLoaded = true;
            } else {
                sidebarContent.innerHTML = `
                    <div style="text-align: center; color: var(--text-muted); padding: 3rem 1rem;">
                        <p>История пуста</p>
                        <p style="font-size: 0.8rem; margin-top: 0.5rem;">Загрузите файлы, чтобы они появились здесь</p>
                    </div>
                `;
            }
        } catch (e) {
            sidebarContent.innerHTML = '<div style="color: var(--danger); text-align: center; padding: 2rem;">Ошибка загрузки истории</div>';
        }
    }
});
