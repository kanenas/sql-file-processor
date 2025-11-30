class SQLProcessorUI {
    constructor() {
        this.currentDeletePath = null;
        this.currentAnalysis = null; // Store analysis data for later use
        this.initializeEventListeners();
        // Ensure modal is hidden and body scrolling is enabled on startup
        this.hideDeleteModal();
        // Double-check body overflow is restored
        document.body.style.overflow = '';
        document.body.style.overflowY = '';
    }

    initializeEventListeners() {
        // File input change
        document.getElementById('sqlFile').addEventListener('change', (e) => {
            const fileName = e.target.files[0]?.name || 'No file chosen';
            document.querySelector('.file-name').textContent = fileName;
        });

        // Form submission
        document.getElementById('uploadForm').addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleFileUpload();
        });

        // Table selection
        document.getElementById('selectAll').addEventListener('click', () => {
            this.selectAllTables(true);
        });

        document.getElementById('deselectAll').addEventListener('click', () => {
            this.selectAllTables(false);
        });

        // Split tables
        document.getElementById('splitTables').addEventListener('click', () => {
            this.handleSplitTables();
        });

        // Navigation
        document.getElementById('newFile').addEventListener('click', () => {
            this.resetUI();
        });

        document.getElementById('tryAgain').addEventListener('click', () => {
            this.resetUI();
        });
        
        // File management events
        document.getElementById('refreshFiles').addEventListener('click', () => {
            this.refreshFileList();
        });
        
        document.getElementById('cleanupAll').addEventListener('click', () => {
            this.cleanupAllFiles();
        });
        
        // Delete modal events
        document.getElementById('cancelDelete').addEventListener('click', () => {
            this.hideDeleteModal();
        });
        
        document.querySelector('#deleteModal .modal-close').addEventListener('click', () => {
            this.hideDeleteModal();
        });
        
        // Close modal when clicking outside
        document.getElementById('deleteModal').addEventListener('click', (e) => {
            if (e.target.id === 'deleteModal') {
                this.hideDeleteModal();
            }
        });
        
        // Delegate events for dynamically created delete buttons
        document.addEventListener('click', (e) => {
            // Use closest to handle clicks on child elements (like text inside button)
            const deleteButton = e.target.closest('.btn-delete');
            if (deleteButton) {
                e.preventDefault();
                e.stopPropagation();
                const path = deleteButton.getAttribute('data-path');
                const name = deleteButton.getAttribute('data-name');
                const type = deleteButton.getAttribute('data-type');
                this.showDeleteModal(path, name, type);
            }
        });
        
        // Initial file list load
        this.refreshFileList();
    }

    async handleFileUpload() {
        const fileInput = document.getElementById('sqlFile');
        const file = fileInput.files[0];

        if (!file) {
            this.showError('Please select a file');
            return;
        }

        this.showSection('progressSection');
        this.updateProgress('Uploading file...', 10);

        const formData = new FormData();
        formData.append('sqlFile', file);
        formData.append('action', 'upload');

        try {
            this.updateProgress('Processing file...', 30);
            const response = await this.makeRequest('process.php', formData);
            
            if (response.success) {
                this.updateProgress('Analysis complete!', 100);
                setTimeout(() => {
                    this.displayAnalysis(response.fileInfo, response.analysis);
                }, 500);
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            this.showError(error.message);
        }
    }

    async handleSplitTables() {
        const selectedTables = this.getSelectedTables();
        
        if (selectedTables.length === 0) {
            this.showError('Please select at least one table');
            return;
        }

        this.showSection('progressSection');
        this.updateProgress('Splitting tables...', 10);

        const formData = new FormData();
        formData.append('action', 'split');
        formData.append('tables', JSON.stringify(selectedTables));

        try {
            this.updateProgress('Creating table files...', 50);
            const response = await this.makeRequest('process.php', formData);
            
            if (response.success) {
                this.updateProgress('Complete!', 100);
                setTimeout(() => {
                    this.displayDownloads(response.files);
                }, 500);
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            this.showError(error.message);
        }
    }

    displayAnalysis(fileInfo, analysis) {
        // Store analysis data for later use (e.g., viewing table structure)
        this.currentAnalysis = analysis;
        
        document.getElementById('fileName').textContent = fileInfo.name;
        document.getElementById('fileSize').textContent = fileInfo.size;
        document.getElementById('tableCount').textContent = fileInfo.tables;
        
        // Display enhanced database info if available
        if (fileInfo.database_info) {
            const dbInfo = fileInfo.database_info;
            document.getElementById('fileSize').innerHTML = 
                `${fileInfo.size} <small>(Uncompressed: ${dbInfo.total_size_formatted})</small>`;
            
            // Add additional info display
            this.displayDatabaseInfo(dbInfo);
        }

        this.displayTablesList(analysis);
        this.showSection('analysisSection');
    }

    displayDatabaseInfo(dbInfo) {
        const infoContainer = document.querySelector('.file-info');
        
        const additionalInfo = `
            <div class="info-item">
                <span class="label">Total Inserts:</span>
                <span>${dbInfo.total_inserts?.toLocaleString() || '0'}</span>
            </div>
            <div class="info-item">
                <span class="label">Estimated Rows:</span>
                <span>${dbInfo.total_rows_estimated?.toLocaleString() || 'N/A'}</span>
            </div>
            <div class="info-item">
                <span class="label">Storage Engines:</span>
                <span>${this.getEngineSummary(dbInfo)}</span>
            </div>
        `;
        
        infoContainer.innerHTML += additionalInfo;
    }

    getEngineSummary(dbInfo) {
        // This would require engine data from the backend
        return 'Mixed';
    }

    displayTablesList(analysis) {
        const tablesList = document.getElementById('tablesList');
        tablesList.innerHTML = '';

        Object.entries(analysis).forEach(([tableName, tableInfo]) => {
            const tableItem = document.createElement('div');
            tableItem.className = 'table-item';
            
            const hasStructure = tableInfo.create_start > 0 || tableInfo.structure;
            const size = this.formatBytes(tableInfo.size);
            const estimatedRows = tableInfo.estimated_rows || tableInfo.insert_count || 0;
            const engine = tableInfo.engine || 'Unknown';
            const columnCount = tableInfo.columns?.length || 0;
            const charset = tableInfo.charset || 'Unknown';
            const collation = tableInfo.collation || '';
            const primaryKey = tableInfo.primary_key || '';
            const indexes = tableInfo.indexes?.length || 0;
            const avgRowSize = tableInfo.avg_row_size ? this.formatBytes(tableInfo.avg_row_size) : '';
            
            // Build details string
            let details = [];
            if (charset !== 'Unknown') details.push(`Charset: ${charset}`);
            if (collation) details.push(`Collation: ${collation}`);
            if (primaryKey) details.push(`PK: ${primaryKey}`);
            if (indexes > 0) details.push(`${indexes} index${indexes > 1 ? 'es' : ''}`);
            if (tableInfo.row_format) details.push(`Format: ${tableInfo.row_format}`);
            if (avgRowSize) details.push(`Avg row: ${avgRowSize}`);
            
            tableItem.innerHTML = `
                <input type="checkbox" class="table-checkbox" id="table-${tableName}" value="${tableName}" checked>
                <div class="table-info">
                    <div class="table-name">${this.escapeHtml(tableName)}</div>
                    <div class="table-stats">
                        <strong>${estimatedRows.toLocaleString()}</strong> rows • 
                        <strong>${tableInfo.insert_count || 0}</strong> inserts • 
                        <strong>${size}</strong> • 
                        <strong>${columnCount}</strong> columns • 
                        <strong>${engine}</strong> engine
                        ${hasStructure ? ' • <span style="color: #27ae60;">✓ Structure</span>' : ' • <span style="color: #e74c3c;">Data only</span>'}
                    </div>
                    ${details.length > 0 ? `<div class="table-details">${details.join(' • ')}</div>` : ''}
                </div>
                ${hasStructure ? '<button class="btn-view-structure" data-table="' + this.escapeHtml(tableName) + '">View Structure</button>' : ''}
            `;
            
            tablesList.appendChild(tableItem);
        });

        // Add event listeners for view structure buttons
        document.querySelectorAll('.btn-view-structure').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const tableName = e.target.getAttribute('data-table');
                // Use stored analysis data instead of local variable
                const tableInfo = this.currentAnalysis && this.currentAnalysis[tableName] ? this.currentAnalysis[tableName] : null;
                this.viewTableStructure(tableName, tableInfo);
            });
        });
    }

    viewTableStructure(tableName, tableInfo) {
        // Add null check to prevent errors
        if (!tableInfo) {
            // Try to get from stored analysis if not provided
            if (this.currentAnalysis && this.currentAnalysis[tableName]) {
                tableInfo = this.currentAnalysis[tableName];
            } else {
                alert('Table information not available. Please refresh the page and try again.');
                return;
            }
        }
        
        const structure = tableInfo.structure || 'No structure information available.';
        const columns = tableInfo.columns || [];
        const engine = tableInfo.engine || 'Unknown';
        const charset = tableInfo.charset || 'Unknown';
        const collation = tableInfo.collation || '';
        const primaryKey = tableInfo.primary_key || '';
        const indexes = tableInfo.indexes || [];
        const foreignKeys = tableInfo.foreign_keys || [];
        const rowFormat = tableInfo.row_format || '';
        const autoIncrement = tableInfo.auto_increment || '';
        
        // Build detailed information HTML
        let detailsHtml = '<div style="margin-bottom: 1.5rem;">';
        detailsHtml += `<h4 style="margin-bottom: 0.5rem; color: #2c3e50;">Table Information</h4>`;
        detailsHtml += `<table style="width: 100%; border-collapse: collapse; margin-bottom: 1rem;">`;
        detailsHtml += `<tr><td style="padding: 0.5rem; border-bottom: 1px solid #eee; font-weight: bold; width: 40%;">Engine:</td><td style="padding: 0.5rem; border-bottom: 1px solid #eee;">${this.escapeHtml(engine)}</td></tr>`;
        if (charset !== 'Unknown') {
            detailsHtml += `<tr><td style="padding: 0.5rem; border-bottom: 1px solid #eee; font-weight: bold;">Charset:</td><td style="padding: 0.5rem; border-bottom: 1px solid #eee;">${this.escapeHtml(charset)}</td></tr>`;
        }
        if (collation) {
            detailsHtml += `<tr><td style="padding: 0.5rem; border-bottom: 1px solid #eee; font-weight: bold;">Collation:</td><td style="padding: 0.5rem; border-bottom: 1px solid #eee;">${this.escapeHtml(collation)}</td></tr>`;
        }
        if (rowFormat) {
            detailsHtml += `<tr><td style="padding: 0.5rem; border-bottom: 1px solid #eee; font-weight: bold;">Row Format:</td><td style="padding: 0.5rem; border-bottom: 1px solid #eee;">${this.escapeHtml(rowFormat)}</td></tr>`;
        }
        if (autoIncrement) {
            detailsHtml += `<tr><td style="padding: 0.5rem; border-bottom: 1px solid #eee; font-weight: bold;">Auto Increment:</td><td style="padding: 0.5rem; border-bottom: 1px solid #eee;">${this.escapeHtml(autoIncrement)}</td></tr>`;
        }
        if (primaryKey) {
            detailsHtml += `<tr><td style="padding: 0.5rem; border-bottom: 1px solid #eee; font-weight: bold;">Primary Key:</td><td style="padding: 0.5rem; border-bottom: 1px solid #eee;">${this.escapeHtml(primaryKey)}</td></tr>`;
        }
        detailsHtml += `</table></div>`;
        
        // Build columns table
        let columnsHtml = '';
        if (columns.length > 0) {
            columnsHtml += '<div style="margin-bottom: 1.5rem;">';
            columnsHtml += `<h4 style="margin-bottom: 0.5rem; color: #2c3e50;">Columns (${columns.length})</h4>`;
            columnsHtml += `<table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">`;
            columnsHtml += `<thead><tr style="background: #f8f9fa;">`;
            columnsHtml += `<th style="padding: 0.5rem; text-align: left; border-bottom: 2px solid #ddd;">Name</th>`;
            columnsHtml += `<th style="padding: 0.5rem; text-align: left; border-bottom: 2px solid #ddd;">Type</th>`;
            columnsHtml += `<th style="padding: 0.5rem; text-align: center; border-bottom: 2px solid #ddd;">Null</th>`;
            columnsHtml += `<th style="padding: 0.5rem; text-align: left; border-bottom: 2px solid #ddd;">Default</th>`;
            columnsHtml += `<th style="padding: 0.5rem; text-align: left; border-bottom: 2px solid #ddd;">Extra</th>`;
            columnsHtml += `<th style="padding: 0.5rem; text-align: left; border-bottom: 2px solid #ddd;">Key</th>`;
            columnsHtml += `</tr></thead><tbody>`;
            
            columns.forEach(col => {
                columnsHtml += `<tr>`;
                columnsHtml += `<td style="padding: 0.5rem; border-bottom: 1px solid #eee;"><strong>${this.escapeHtml(col.name)}</strong></td>`;
                columnsHtml += `<td style="padding: 0.5rem; border-bottom: 1px solid #eee;">${this.escapeHtml(col.type)}</td>`;
                columnsHtml += `<td style="padding: 0.5rem; text-align: center; border-bottom: 1px solid #eee;">${col.null ? 'YES' : 'NO'}</td>`;
                columnsHtml += `<td style="padding: 0.5rem; border-bottom: 1px solid #eee;">${col.default !== null ? this.escapeHtml(String(col.default)) : 'NULL'}</td>`;
                columnsHtml += `<td style="padding: 0.5rem; border-bottom: 1px solid #eee;">${col.extra || ''}</td>`;
                columnsHtml += `<td style="padding: 0.5rem; border-bottom: 1px solid #eee;">${col.key || ''}</td>`;
                columnsHtml += `</tr>`;
            });
            
            columnsHtml += `</tbody></table></div>`;
        }
        
        // Build indexes section
        let indexesHtml = '';
        if (indexes.length > 0) {
            indexesHtml += '<div style="margin-bottom: 1.5rem;">';
            indexesHtml += `<h4 style="margin-bottom: 0.5rem; color: #2c3e50;">Indexes (${indexes.length})</h4>`;
            indexesHtml += `<ul style="list-style: none; padding: 0;">`;
            indexes.forEach(idx => {
                indexesHtml += `<li style="padding: 0.25rem 0;">• <strong>${this.escapeHtml(idx.name || 'unnamed')}</strong> on <code>${this.escapeHtml(idx.column)}</code></li>`;
            });
            indexesHtml += `</ul></div>`;
        }
        
        // Build foreign keys section
        let fkHtml = '';
        if (foreignKeys.length > 0) {
            fkHtml += '<div style="margin-bottom: 1.5rem;">';
            fkHtml += `<h4 style="margin-bottom: 0.5rem; color: #2c3e50;">Foreign Keys (${foreignKeys.length})</h4>`;
            fkHtml += `<ul style="list-style: none; padding: 0;">`;
            foreignKeys.forEach(fk => {
                fkHtml += `<li style="padding: 0.25rem 0;">→ References <strong>${this.escapeHtml(fk.table)}</strong>${fk.column ? ` (${this.escapeHtml(fk.column)})` : ''}</li>`;
            });
            fkHtml += `</ul></div>`;
        }
        
        // Create modal
        const modal = document.createElement('div');
        modal.className = 'modal active';
        modal.innerHTML = `
            <div class="modal-content" style="max-width: 900px;">
                <div class="modal-header">
                    <h3>Table Structure: ${this.escapeHtml(tableName)}</h3>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    ${detailsHtml}
                    ${columnsHtml}
                    ${indexesHtml}
                    ${fkHtml}
                    <div style="margin-top: 1.5rem;">
                        <h4 style="margin-bottom: 0.5rem; color: #2c3e50;">Full CREATE TABLE Statement</h4>
                        <pre style="white-space: pre-wrap; background: #f5f5f5; padding: 1rem; border-radius: 4px; max-height: 300px; overflow: auto; font-size: 0.85rem;">${this.escapeHtml(structure)}</pre>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary close-structure-modal">Close</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        // Prevent background scrolling when structure modal is open
        document.body.style.overflow = 'hidden';
        
        // Close modal functionality
        const closeModal = () => {
            if (document.body.contains(modal)) {
                document.body.removeChild(modal);
                // Restore scrolling when modal is closed
                document.body.style.overflow = '';
                document.body.style.overflowY = '';
            }
        };
        
        modal.querySelector('.modal-close').addEventListener('click', closeModal);
        modal.querySelector('.close-structure-modal').addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                closeModal();
            }
        });
    }
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    displayDownloads(files) {
        const downloadList = document.getElementById('downloadList');
        downloadList.innerHTML = '';

        files.forEach(file => {
            // Use session file_id instead of URL path
            const fileId = 'current'; // This should come from session
            const downloadItem = document.createElement('div');
            downloadItem.className = 'download-item';
            
            downloadItem.innerHTML = `
                <div class="download-info">
                    <div class="download-name">${file.name}</div>
                    <div class="download-size">${file.size}</div>
                </div>
                <a href="download.php?name=${encodeURIComponent(file.name)}" class="download-link" download>
                    Download
                </a>
            `;
            
            downloadList.appendChild(downloadItem);
        });

        this.showSection('downloadSection');
    }

    getSelectedTables() {
        const checkboxes = document.querySelectorAll('.table-checkbox:checked');
        return Array.from(checkboxes).map(cb => cb.value);
    }

    selectAllTables(select) {
        const checkboxes = document.querySelectorAll('.table-checkbox');
        checkboxes.forEach(cb => cb.checked = select);
    }

    updateProgress(message, percent) {
        document.getElementById('progressText').textContent = message;
        document.getElementById('progressFill').style.width = percent + '%';
    }

    showSection(sectionId) {
        // Hide all sections
        const sections = ['uploadSection', 'analysisSection', 'downloadSection', 'progressSection', 'errorSection'];
        sections.forEach(section => {
            const element = document.getElementById(section);
            if (element) {
                element.classList.add('hidden');
            }
        });
        
        // Show target section
        const targetSection = document.getElementById(sectionId);
        if (targetSection) {
            targetSection.classList.remove('hidden');
        }
        
        // Ensure body scrolling is enabled (in case it was disabled by a modal)
        document.body.style.overflow = '';
        document.body.style.overflowY = '';
    }

    showError(message) {
        document.getElementById('errorMessage').textContent = message;
        this.showSection('errorSection');
    }

    resetUI() {
        // Reset form
        document.getElementById('uploadForm').reset();
        document.querySelector('.file-name').textContent = 'No file chosen';
        
        // Clear all sections
        document.getElementById('tablesList').innerHTML = '';
        document.getElementById('downloadList').innerHTML = '';
        
        // Show upload section
        this.showSection('uploadSection');
        
        // Reset session by making a cleanup request
        fetch('cleanup.php', { method: 'POST' })
            .catch(err => console.log('Cleanup completed'));
            
        // Refresh file list
        this.refreshFileList();
    }

    async makeRequest(url, formData) {
        const response = await fetch(url, {
            method: 'POST',
            body: formData
        });

        if (!response.ok) {
            throw new Error('Network request failed');
        }

        return await response.json();
    }

    formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    // File management methods
    async refreshFileList() {
        try {
            const response = await this.makeFileManagementRequest('refresh');
            if (response.success) {
                document.getElementById('uploadsList').innerHTML = response.uploads;
                document.getElementById('outputsList').innerHTML = response.outputs;
            }
        } catch (error) {
            console.error('Failed to refresh file list:', error);
        }
    }

    async cleanupAllFiles() {
        if (!confirm('This will delete ALL files in uploads and outputs directories. This action cannot be undone. Are you sure?')) {
            return;
        }
        
        try {
            const response = await this.makeFileManagementRequest('cleanup_all');
            if (response.success) {
                alert('All temporary files have been deleted successfully.');
                this.refreshFileList();
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            alert('Error during cleanup: ' + error.message);
        }
    }

    showDeleteModal(path, name, type) {
        const modal = document.getElementById('deleteModal');
        const message = document.getElementById('deleteMessage');
        
        if (!modal || !message) {
            console.error('Delete modal elements not found');
            return;
        }
        
        const article = type === 'directory' ? 'this directory and all its contents' : 'this file';
        message.textContent = `Are you sure you want to delete ${article}: ${name}? This action cannot be undone.`;
        
        // Store the path for confirmation
        this.currentDeletePath = path;
        
        // Show modal
        modal.classList.remove('hidden');
        modal.classList.add('active');
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
    }

    hideDeleteModal() {
        const modal = document.getElementById('deleteModal');
        if (modal) {
            modal.classList.remove('active');
            modal.classList.add('hidden'); // Ensure it's hidden
        }
        // Always restore scrolling
        document.body.style.overflow = '';
        document.body.style.overflowY = '';
        this.currentDeletePath = null;
    }

    async confirmDelete() {
        if (!this.currentDeletePath) {
            this.hideDeleteModal();
            return;
        }
        
        try {
            const response = await this.makeFileManagementRequest('delete', { path: this.currentDeletePath });
            if (response.success) {
                this.refreshFileList();
            } else {
                throw new Error(response.error);
            }
        } catch (error) {
            alert('Error deleting file: ' + error.message);
        } finally {
            this.hideDeleteModal();
        }
    }

    async makeFileManagementRequest(action, data = {}) {
        const formData = new FormData();
        formData.append('action', 'file_management');
        formData.append('sub_action', action);
        
        // Add additional data
        for (const [key, value] of Object.entries(data)) {
            formData.append(key, value);
        }
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error('Network request failed');
        }
        
        return await response.json();
    }
}

// Initialize the application when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    const processor = new SQLProcessorUI();
    document._sqlProcessor = processor;
    
    // Add event listener for confirm delete button
    document.getElementById('confirmDelete').addEventListener('click', () => {
        if (processor && processor.confirmDelete) {
            processor.confirmDelete();
        }
    });
});
