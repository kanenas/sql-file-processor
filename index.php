<?php
session_start();

class FileManager {
    private $uploadsDir = 'uploads/';
    private $outputsDir = 'outputs/';
    
    public function listDirectoryContents($directory) {
        $fullPath = $directory . '/';
        $html = '';
        
        if (!file_exists($fullPath)) {
            return '<div class="no-files">Directory does not exist</div>';
        }
        
        $items = scandir($fullPath);
        $items = array_diff($items, ['.', '..']);
        
        if (empty($items)) {
            return '<div class="no-files">No files found</div>';
        }
        
        // Sort by modification time (newest first)
        usort($items, function($a, $b) use ($fullPath) {
            return filemtime($fullPath . $b) - filemtime($fullPath . $a);
        });
        
        foreach ($items as $item) {
            $itemPath = $fullPath . $item;
            $isDir = is_dir($itemPath);
            $isHtaccess = ($item === '.htaccess');
            $size = $isDir ? $this->getFolderSize($itemPath) : $this->formatBytes(filesize($itemPath));
            $modified = date('Y-m-d H:i:s', filemtime($itemPath));
            $icon = $isDir ? '📁' : '📄';
            $type = $isDir ? 'Directory' : pathinfo($item, PATHINFO_EXTENSION) . ' File';
            
            // Don't show delete button for .htaccess files
            $deleteButton = $isHtaccess ? '' : "
                <div class='file-actions'>
                    <button class='btn-delete' data-path='$itemPath' data-name='$item' data-type='" . ($isDir ? 'directory' : 'file') . "'>
                        Delete
                    </button>
                </div>
            ";
            
            $html .= "
            <div class='file-item'>
                <div class='file-info'>
                    <span class='file-icon'>$icon</span>
                    <div class='file-details'>
                        <div class='file-name'>$item</div>
                        <div class='file-meta'>
                            <span class='file-type'>$type</span>
                            <span class='file-size'>$size</span>
                            <span class='file-modified'>$modified</span>
                        </div>
                    </div>
                </div>
                $deleteButton
            </div>
            ";
        }
        
        return $html;
    }
    
    private function getFolderSize($path) {
        $totalSize = 0;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($files as $file) {
            $totalSize += $file->getSize();
        }
        
        return $this->formatBytes($totalSize);
    }
    
    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    public function deleteItem($path) {
        // Security check - prevent directory traversal
        if (strpos($path, '..') !== false) {
            return ['success' => false, 'error' => 'Invalid path'];
        }
        
        // Prevent deletion of .htaccess files
        $basename = basename($path);
        if ($basename === '.htaccess') {
            return ['success' => false, 'error' => 'Cannot delete .htaccess files'];
        }
        
        // Ensure the path is within our allowed directories
        $realPath = realpath($path);
        $uploadsReal = realpath($this->uploadsDir);
        $outputsReal = realpath($this->outputsDir);
        
        if (strpos($realPath, $uploadsReal) !== 0 && strpos($realPath, $outputsReal) !== 0) {
            return ['success' => false, 'error' => 'Access denied'];
        }
        
        if (!file_exists($path)) {
            return ['success' => false, 'error' => 'File/directory not found'];
        }
        
        try {
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function deleteDirectory($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        
        $items = array_diff(scandir($dir), ['.', '..', '.htaccess']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            // Skip .htaccess files even in subdirectories
            if ($item === '.htaccess') {
                continue;
            }
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        
        return rmdir($dir);
    }
    
    public function cleanupAll() {
        $results = [];
        
        // Clean uploads directory (skip .htaccess files)
        if (file_exists($this->uploadsDir)) {
            $items = array_diff(scandir($this->uploadsDir), ['.', '..', '.htaccess']);
            foreach ($items as $item) {
                $path = $this->uploadsDir . $item;
                $result = $this->deleteItem($path);
                $results[] = ['path' => $path, 'result' => $result];
            }
        }
        
        // Clean outputs directory (skip .htaccess files)
        if (file_exists($this->outputsDir)) {
            $items = array_diff(scandir($this->outputsDir), ['.', '..', '.htaccess']);
            foreach ($items as $item) {
                $path = $this->outputsDir . $item;
                $result = $this->deleteItem($path);
                $results[] = ['path' => $path, 'result' => $result];
            }
        }
        
        return $results;
    }
}

$fileManager = new FileManager();

// Handle AJAX requests for file management
if ($_POST['action'] ?? '' === 'file_management') {
    header('Content-Type: application/json');
    
    $subAction = $_POST['sub_action'] ?? '';
    $response = [];
    
    switch ($subAction) {
        case 'refresh':
            $response = [
                'success' => true,
                'uploads' => $fileManager->listDirectoryContents('uploads'),
                'outputs' => $fileManager->listDirectoryContents('outputs')
            ];
            break;
            
        case 'delete':
            $path = $_POST['path'] ?? '';
            if (empty($path)) {
                $response = ['success' => false, 'error' => 'No path provided'];
            } else {
                $response = $fileManager->deleteItem($path);
            }
            break;
            
        case 'cleanup_all':
            $response = [
                'success' => true,
                'results' => $fileManager->cleanupAll()
            ];
            break;
            
        default:
            $response = ['success' => false, 'error' => 'Invalid action'];
    }
    
    echo json_encode($response, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

// Ensure upload and output directories exist
if (!file_exists('uploads')) mkdir('uploads', 0755, true);
if (!file_exists('outputs')) mkdir('outputs', 0755, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SQL File Processor - Web Version</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>SQL File Processor</h1>
            <p>Upload, analyze, and split large SQL database files</p>
        </header>

        <div class="upload-section">
            <h2>Upload SQL File</h2>
            <form id="uploadForm" enctype="multipart/form-data">
                <div class="file-input-container">
                    <input type="file" id="sqlFile" name="sqlFile" accept=".sql.gz,.gz,.zip,.sql.zip" required>
                    <label for="sqlFile" class="file-label">
                        <span class="file-button">Choose File</span>
                        <span class="file-name">No file chosen</span>
                    </label>
                    <small style="display: block; margin-top: 0.5rem; color: #666;">Supported formats: .gz, .zip</small>
                </div>
                <button type="submit" class="btn-primary">Upload & Analyze</button>
            </form>
        </div>

        <div id="progressSection" class="progress-section hidden">
            <h3>Processing File</h3>
            <div class="progress-bar">
                <div class="progress-fill" id="progressFill"></div>
            </div>
            <div class="progress-text" id="progressText">Initializing...</div>
        </div>

        <div id="analysisSection" class="analysis-section hidden">
            <h2>File Analysis</h2>
            <div class="file-info">
                <div class="info-item">
                    <span class="label">Filename:</span>
                    <span id="fileName">-</span>
                </div>
                <div class="info-item">
                    <span class="label">File Size:</span>
                    <span id="fileSize">-</span>
                </div>
                <div class="info-item">
                    <span class="label">Tables Found:</span>
                    <span id="tableCount">-</span>
                </div>
            </div>

            <div class="tables-section">
                <h3>Database Tables</h3>
                <div class="table-actions">
                    <button id="selectAll" class="btn-secondary">Select All</button>
                    <button id="deselectAll" class="btn-secondary">Deselect All</button>
                </div>
                <div id="tablesList" class="tables-list"></div>
            </div>

            <div class="split-options">
                <h3>Split Options</h3>
                <div class="option-group">
                    <label>
                        <input type="radio" name="splitOption" value="selected" checked>
                        Split selected tables only
                    </label>
                    <label>
                        <input type="radio" name="splitOption" value="all">
                        Split all tables
                    </label>
                </div>
                <button id="splitTables" class="btn-primary">Split Selected Tables</button>
            </div>
        </div>

        <div id="downloadSection" class="download-section hidden">
            <h2>Download Files</h2>
            <div id="downloadList" class="download-list"></div>
            <button id="newFile" class="btn-secondary">Process New File</button>
        </div>

        <div id="errorSection" class="error-section hidden">
            <h3>Error</h3>
            <div id="errorMessage" class="error-message"></div>
            <button id="tryAgain" class="btn-secondary">Try Again</button>
        </div>

        <!-- File Management Section -->
        <div class="management-section">
            <h2>File Management</h2>
            <div class="management-actions">
                <button id="refreshFiles" class="btn-secondary">Refresh File List</button>
                <button id="cleanupAll" class="btn-danger">Cleanup All Temporary Files</button>
            </div>

            <div class="file-browser">
                <div class="directory-section">
                    <h3>Uploads Directory</h3>
                    <div class="file-list" id="uploadsList">
                        <?php echo $fileManager->listDirectoryContents('uploads'); ?>
                    </div>
                </div>

                <div class="directory-section">
                    <h3>Outputs Directory</h3>
                    <div class="file-list" id="outputsList">
                        <?php echo $fileManager->listDirectoryContents('outputs'); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal hidden">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <button class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <p id="deleteMessage">Are you sure you want to delete this file?</p>
            </div>
            <div class="modal-footer">
                <button id="confirmDelete" class="btn-danger">Delete</button>
                <button id="cancelDelete" class="btn-secondary">Cancel</button>
            </div>
        </div>
    </div>

    <script src="js/script.js"></script>
</body>
</html>