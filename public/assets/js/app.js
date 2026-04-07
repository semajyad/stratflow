// ===========================
// Sidebar Toggle
// ===========================
document.addEventListener('DOMContentLoaded', function() {
    const toggle  = document.getElementById('sidebar-toggle');
    const sidebar = document.getElementById('sidebar');

    if (toggle && sidebar) {
        toggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
        });
    }

    // ===========================
    // Flash Message Auto-Dismiss
    // ===========================
    document.querySelectorAll('.flash-message').forEach(function(el) {
        setTimeout(function() {
            el.style.opacity = '0';
            el.style.transition = 'opacity 0.3s ease';
            setTimeout(function() { el.remove(); }, 300);
        }, 5000);
    });

    // ===========================
    // Upload: Drag & Drop + Click-to-Browse
    // ===========================
    const dropZone    = document.getElementById('drop-zone');
    const fileInput   = document.getElementById('file-input');
    const selectedDiv = document.getElementById('selected-file');
    const selectedName = document.getElementById('selected-file-name');
    const clearBtn    = document.getElementById('clear-file');

    if (dropZone && fileInput) {
        // Click anywhere on drop zone to open file picker (excluding the hidden input itself
        // triggering a double-open — the hidden input already handles its own click)
        dropZone.addEventListener('click', function(e) {
            if (e.target !== fileInput) {
                fileInput.click();
            }
        });

        // Drag events
        dropZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            dropZone.classList.add('drag-over');
        });

        dropZone.addEventListener('dragleave', function() {
            dropZone.classList.remove('drag-over');
        });

        dropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                // Assign dropped file to the hidden input via DataTransfer
                const dt = new DataTransfer();
                dt.items.add(files[0]);
                fileInput.files = dt.files;
                showSelectedFile(files[0].name);
            }
        });

        // File picker selection
        fileInput.addEventListener('change', function() {
            if (fileInput.files.length > 0) {
                showSelectedFile(fileInput.files[0].name);
            }
        });

        // Clear selection
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                fileInput.value = '';
                selectedDiv.style.display = 'none';
                dropZone.style.display    = '';
            });
        }
    }

    /**
     * Show the selected filename and hide the drop zone.
     *
     * @param {string} name Filename to display
     */
    function showSelectedFile(name) {
        if (selectedName) { selectedName.textContent = name; }
        if (selectedDiv)  { selectedDiv.style.display = 'flex'; }
        if (dropZone)     { dropZone.style.display    = 'none'; }
    }

    // ===========================
    // Mermaid Diagram Rendering
    // ===========================
    if (typeof mermaid !== 'undefined') {
        mermaid.initialize({ startOnLoad: false, theme: 'default', securityLevel: 'loose' });
    }

    /**
     * Render the Mermaid diagram from the code textarea into the output div.
     * Clears previous SVG, generates a new one, and displays syntax errors.
     */
    function renderDiagram() {
        var codeEl   = document.getElementById('mermaid-code');
        var outputEl = document.getElementById('mermaid-output');
        if (!codeEl || !outputEl || typeof mermaid === 'undefined') { return; }

        var code = codeEl.value.trim();
        if (!code) {
            outputEl.innerHTML = '<p class="text-muted">No diagram code yet.</p>';
            return;
        }

        outputEl.innerHTML = '';
        var id = 'mermaid-' + Date.now();
        mermaid.render(id, code).then(function(result) {
            outputEl.innerHTML = result.svg;
        }).catch(function(err) {
            outputEl.innerHTML = '<p class="error">Invalid Mermaid syntax: ' + err.message + '</p>';
        });
    }

    // Auto-render diagram on page load and debounce on textarea input
    var codeEl = document.getElementById('mermaid-code');
    if (codeEl) {
        renderDiagram();
        var diagramTimeout;
        codeEl.addEventListener('input', function() {
            clearTimeout(diagramTimeout);
            diagramTimeout = setTimeout(renderDiagram, 500);
        });
    }

    // ===========================
    // OKR Save (AJAX)
    // ===========================
    document.querySelectorAll('.save-okr-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var item        = btn.closest('.node-okr-item');
            var nodeId      = item.getAttribute('data-node-id');
            var okrTitle    = item.querySelector('.okr-title').value;
            var okrDesc     = item.querySelector('.okr-description').value;
            var csrfToken   = document.querySelector('input[name="_csrf_token"]').value;

            var formData = new FormData();
            formData.append('_csrf_token', csrfToken);
            formData.append('node_id', nodeId);
            formData.append('okr_title', okrTitle);
            formData.append('okr_description', okrDesc);

            fetch('/app/diagram/save-okr', {
                method: 'POST',
                body: formData,
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.status === 'ok') {
                    btn.textContent = 'Saved!';
                    setTimeout(function() { btn.textContent = 'Save OKR'; }, 1500);
                }
            })
            .catch(function() {
                btn.textContent = 'Error';
                setTimeout(function() { btn.textContent = 'Save OKR'; }, 1500);
            });
        });
    });

    // ===========================
    // Upload: Extracted Text Toggle
    // ===========================
    document.querySelectorAll('.toggle-text').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const docId   = btn.getAttribute('data-doc-id');
            const preview = document.getElementById('preview-' + docId);
            const full    = document.getElementById('full-' + docId);
            if (!full) { return; }

            const isHidden = full.style.display === 'none';
            full.style.display    = isHidden ? 'block' : 'none';
            if (preview) { preview.style.display = isHidden ? 'none' : 'block'; }
            btn.textContent = isHidden ? 'Hide Full Text' : 'View Full Text';
        });
    });
});
