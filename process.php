<?php
session_start();

class SQLWebProcessor {
    private $uploadDir = 'uploads/';
    private $outputDir = 'outputs/';
    private $maxFileSize = 1024 * 1024 * 500; // 500MB

    public function __construct() {
        // Set higher limits for large files
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        
        // Create directories if they don't exist
        if (!file_exists($this->uploadDir)) mkdir($this->uploadDir, 0755, true);
        if (!file_exists($this->outputDir)) mkdir($this->outputDir, 0755, true);
    }

    public function handleRequest() {
        header('Content-Type: application/json');
        
        try {
            $action = $_POST['action'] ?? '';

            switch ($action) {
                case 'upload':
                    $this->handleUpload();
                    break;
                case 'analyze':
                    $this->handleAnalyze();
                    break;
                case 'split':
                    $this->handleSplit();
                    break;
                default:
                    throw new Exception('Invalid action');
            }
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        }
    }

    private function handleUpload() {
        if (!isset($_FILES['sqlFile'])) {
            throw new Exception('No file uploaded');
        }

        $file = $_FILES['sqlFile'];
        
        // Validate file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Upload error: ' . $file['error']);
        }

        if ($file['size'] > $this->maxFileSize) {
            throw new Exception('File too large. Maximum size: 500MB');
        }

        // Check file extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['gz', 'zip'])) {
            throw new Exception('Only .gz and .zip files are supported');
        }

        // Generate unique filename with sanitization
        $fileId = uniqid('', true);
        $originalName = basename($file['name']);
        // Sanitize filename to prevent directory traversal
        $originalName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $uploadedFile = $this->uploadDir . $fileId . '_' . $originalName;
        $sqlFile = $this->uploadDir . $fileId . '.sql';

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $uploadedFile)) {
            throw new Exception('Failed to save uploaded file');
        }

        // Extract file based on extension
        $this->updateProgress('Extracting file...', 25);
        $extractSuccess = false;
        if ($extension === 'gz') {
            $extractSuccess = $this->unzipGzFile($uploadedFile, $sqlFile);
        } elseif ($extension === 'zip') {
            $extractSuccess = $this->unzipZipFile($uploadedFile, $sqlFile);
        }
        
        if (!$extractSuccess) {
            unlink($uploadedFile);
            throw new Exception('Failed to extract file');
        }

        // Analyze SQL file
        $this->updateProgress('Analyzing SQL structure...', 50);
        $analysis = $this->analyzeSQLFile($sqlFile);

        // Store in session
        $_SESSION['file_id'] = $fileId;
        $_SESSION['original_name'] = $originalName;
        $_SESSION['sql_file'] = $sqlFile;
        $_SESSION['analysis'] = $analysis;

        $this->updateProgress('Complete!', 100);

        // Ensure structure is properly included (limit size to prevent JSON issues)
        foreach ($analysis as &$table) {
            // Limit structure size if too large (keep first 50000 chars for viewing)
            if (isset($table['structure']) && strlen($table['structure']) > 50000) {
                $table['structure'] = substr($table['structure'], 0, 50000) . "\n\n... (truncated, structure too large) ...";
            }
        }
        unset($table);
        
        echo json_encode([
            'success' => true,
            'fileInfo' => [
                'name' => $originalName,
                'size' => $this->formatBytes($file['size']),
                'tables' => count($analysis)
            ],
            'analysis' => $analysis
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        // Clean up uploaded archive file
        unlink($uploadedFile);
    }

    private function handleAnalyze() {
        $fileId = $_POST['fileId'] ?? '';
        $sqlFile = $this->uploadDir . $fileId . '.sql';

        if (!file_exists($sqlFile)) {
            throw new Exception('SQL file not found');
        }

        $analysis = $this->analyzeSQLFile($sqlFile);

        echo json_encode([
            'success' => true,
            'analysis' => $analysis
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    private function handleSplit() {
        $fileId = $_SESSION['file_id'] ?? '';
        $sqlFile = $_SESSION['sql_file'] ?? '';
        $selectedTables = json_decode($_POST['tables'] ?? '[]', true);

        if (!file_exists($sqlFile)) {
            throw new Exception('SQL file not found');
        }

        if (empty($selectedTables)) {
            throw new Exception('No tables selected');
        }

        $outputDir = $this->outputDir . $fileId . '_split/';
        if (!file_exists($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $analysis = $_SESSION['analysis'] ?? [];
        $createdFiles = [];

        foreach ($selectedTables as $tableName) {
            if (isset($analysis[$tableName])) {
                $outputFile = $outputDir . $tableName . '.sql';
                if ($this->extractTableToFile($tableName, $analysis[$tableName], $sqlFile, $outputFile)) {
                    $createdFiles[] = [
                        'name' => $tableName . '.sql',
                        'path' => $outputFile,
                        'size' => $this->formatBytes(filesize($outputFile))
                    ];
                }
            }
        }

        $_SESSION['output_files'] = $createdFiles;

        echo json_encode([
            'success' => true,
            'files' => $createdFiles
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    private function unzipGzFile($gzFile, $sqlFile) {
        try {
            $gzHandle = gzopen($gzFile, 'rb');
            if (!$gzHandle) {
                return false;
            }

            $sqlHandle = fopen($sqlFile, 'wb');
            if (!$sqlHandle) {
                gzclose($gzHandle);
                return false;
            }

            // Use larger buffer for better performance (64KB instead of 4KB)
            while (!gzeof($gzHandle)) {
                $buffer = gzread($gzHandle, 65536);
                if ($buffer === false) {
                    gzclose($gzHandle);
                    fclose($sqlHandle);
                    unlink($sqlFile);
                    return false;
                }
                fwrite($sqlHandle, $buffer);
            }

            gzclose($gzHandle);
            fclose($sqlHandle);

            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    private function unzipZipFile($zipFile, $sqlFile) {
        try {
            // Check if ZipArchive extension is available
            if (!class_exists('ZipArchive')) {
                throw new Exception('ZipArchive extension is not available');
            }
            
            $zip = new ZipArchive();
            $result = $zip->open($zipFile);
            
            if ($result !== true) {
                return false;
            }
            
            // Look for .sql file in the archive
            $sqlFileName = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $fileName = $zip->getNameIndex($i);
                // Skip directory entries and hidden files
                if (substr($fileName, -1) === '/' || basename($fileName)[0] === '.') {
                    continue;
                }
                // Check if it's a .sql file
                if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'sql') {
                    $sqlFileName = $fileName;
                    break;
                }
            }
            
            // If no .sql file found, try the first file
            if ($sqlFileName === null && $zip->numFiles > 0) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $fileName = $zip->getNameIndex($i);
                    if (substr($fileName, -1) !== '/' && basename($fileName)[0] !== '.') {
                        $sqlFileName = $fileName;
                        break;
                    }
                }
            }
            
            if ($sqlFileName === null) {
                $zip->close();
                throw new Exception('No SQL file found in ZIP archive');
            }
            
            // Extract the SQL file
            $content = $zip->getFromName($sqlFileName);
            if ($content === false) {
                $zip->close();
                return false;
            }
            
            // Write to output file
            $sqlHandle = fopen($sqlFile, 'wb');
            if (!$sqlHandle) {
                $zip->close();
                return false;
            }
            
            fwrite($sqlHandle, $content);
            fclose($sqlHandle);
            $zip->close();
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function analyzeSQLFile($sqlFile) {
        $tables = [];
        $databaseInfo = [
            'total_size' => 0,
            'total_tables' => 0,
            'total_inserts' => 0,
            'total_rows_estimated' => 0,
            'file_stats' => []
        ];
        
        $handle = fopen($sqlFile, 'r');
        $currentTable = null;
        $tableData = [
            'create_start' => 0,
            'create_end' => 0,
            'insert_count' => 0,
            'size' => 0,
            'insert_positions' => [],
            'row_estimates' => [],
            'structure' => '',
            'engine' => 'Unknown',
            'charset' => 'Unknown',
            'columns' => []
        ];

        $lineNumber = 0;
        $inCreateTable = false;
        $currentTableName = '';
        $createTableLines = [];

        while (($line = fgets($handle)) !== false) {
            $lineNumber++;
            $trimmedLine = trim($line);
            $databaseInfo['total_size'] += strlen($line);

            // Detect CREATE TABLE with more detailed parsing
            if (preg_match('/CREATE TABLE (?:IF NOT EXISTS )?`?([^`\s\(]+)`?/i', $trimmedLine, $matches)) {
                if ($currentTableName && $inCreateTable) {
                    $tableData['create_end'] = $lineNumber - 1;
                    $tableData['structure'] = implode('', $createTableLines);
                    $this->analyzeTableStructure($currentTableName, $tableData);
                    $tables[$currentTableName] = $tableData;
                    
                    $databaseInfo['total_tables']++;
                    $databaseInfo['total_inserts'] += $tableData['insert_count'];
                }

                $currentTableName = $matches[1];
                $inCreateTable = true;
                $createTableLines = [$line];
                $tableData = [
                    'create_start' => $lineNumber,
                    'create_end' => 0,
                    'insert_count' => 0,
                    'size' => 0,
                    'insert_positions' => [],
                    'row_estimates' => [],
                    'structure' => '',
                    'engine' => 'Unknown',
                    'charset' => 'Unknown',
                    'columns' => []
                ];
            }
            // Continue collecting CREATE TABLE lines
            else if ($inCreateTable) {
                $createTableLines[] = $line;
            }

            // Detect INSERT INTO with row estimation
            if (preg_match('/INSERT INTO `?([^`\s\(]+)`?/i', $trimmedLine, $matches)) {
                $tableName = $matches[1];
                if (!isset($tables[$tableName])) {
                    $tables[$tableName] = [
                        'create_start' => 0,
                        'create_end' => 0,
                        'insert_count' => 0,
                        'size' => 0,
                        'insert_positions' => [],
                        'row_estimates' => [],
                        'structure' => '',
                        'engine' => 'Unknown',
                        'charset' => 'Unknown',
                        'columns' => []
                    ];
                }
                $tables[$tableName]['insert_count']++;
                $tables[$tableName]['insert_positions'][] = $lineNumber;
                $tables[$tableName]['size'] += strlen($line);
                
                // Estimate rows in this INSERT statement
                $rowEstimate = $this->estimateRowsInInsert($trimmedLine);
                $tables[$tableName]['row_estimates'][] = $rowEstimate;
                $databaseInfo['total_rows_estimated'] += $rowEstimate;
            }

            // Detect end of CREATE TABLE
            if ($inCreateTable && strpos($trimmedLine, ';') !== false) {
                $tableData['create_end'] = $lineNumber;
                $tableData['structure'] = implode('', $createTableLines);
                if ($currentTableName) {
                    $this->analyzeTableStructure($currentTableName, $tableData);
                    $tables[$currentTableName] = $tableData;
                    $databaseInfo['total_tables']++;
                    $databaseInfo['total_inserts'] += $tableData['insert_count'];
                }
                $inCreateTable = false;
            }

            // Detect database operations
            $this->detectDatabaseOperations($trimmedLine, $databaseInfo);
        }

        // Handle last table
        if ($currentTableName && $inCreateTable) {
            $tableData['create_end'] = $lineNumber;
            $tableData['structure'] = implode('', $createTableLines);
            $this->analyzeTableStructure($currentTableName, $tableData);
            $tables[$currentTableName] = $tableData;
            $databaseInfo['total_tables']++;
            $databaseInfo['total_inserts'] += $tableData['insert_count'];
        }

        fclose($handle);
        
        // Store database info in session
        $_SESSION['database_info'] = $databaseInfo;
        
        return $tables;
    }

    private function analyzeTableStructure($tableName, &$tableData) {
        $structure = $tableData['structure'];
        
        // Detect storage engine
        if (preg_match('/ENGINE\s*=\s*(\w+)/i', $structure, $matches)) {
            $tableData['engine'] = $matches[1];
        }
        
        // Detect charset
        if (preg_match('/CHARSET\s*=\s*(\w+)/i', $structure, $matches)) {
            $tableData['charset'] = $matches[1];
        } elseif (preg_match('/CHARACTER\s+SET\s+(\w+)/i', $structure, $matches)) {
            $tableData['charset'] = $matches[1];
        }
        
        // Detect collation
        if (preg_match('/COLLATE\s*=\s*([^\s;]+)/i', $structure, $matches)) {
            $tableData['collation'] = trim($matches[1], "`'\"");
        }
        
        // Detect auto-increment value
        if (preg_match('/AUTO_INCREMENT\s*=\s*(\d+)/i', $structure, $matches)) {
            $tableData['auto_increment'] = (int)$matches[1];
        }
        
        // Detect row format
        if (preg_match('/ROW_FORMAT\s*=\s*(\w+)/i', $structure, $matches)) {
            $tableData['row_format'] = $matches[1];
        }
        
        // Detect default charset
        if (preg_match('/DEFAULT\s+CHARSET\s*=\s*(\w+)/i', $structure, $matches)) {
            if (empty($tableData['charset'])) {
                $tableData['charset'] = $matches[1];
            }
        }
        
        // Extract column definitions with full details
        if (preg_match('/\(([\s\S]*)\)[^)]*$/m', $structure, $matches)) {
            $columnSection = $matches[1];
            $lines = explode("\n", $columnSection);
            
            $primaryKey = null;
            $indexes = [];
            $foreignKeys = [];
            
            foreach ($lines as $line) {
                $line = trim($line);
                
                // Extract column definition
                if (preg_match('/^`([^`]+)`\s+([^\s,\(]+(?:\([^)]+\))?)\s*(.*?)(?:,|$)/', $line, $colMatches)) {
                    $columnName = $colMatches[1];
                    $columnType = $colMatches[2];
                    $columnOptions = isset($colMatches[3]) ? trim($colMatches[3]) : '';
                    
                    $column = [
                        'name' => $columnName,
                        'type' => $columnType,
                        'null' => stripos($columnOptions, 'NOT NULL') === false,
                        'default' => null,
                        'extra' => '',
                        'key' => ''
                    ];
                    
                    // Check for default value
                    if (preg_match("/DEFAULT\s+([^,\s]+)/i", $columnOptions, $defMatches)) {
                        $column['default'] = trim($defMatches[1], "'\"`");
                    }
                    
                    // Check for auto increment
                    if (stripos($columnOptions, 'AUTO_INCREMENT') !== false) {
                        $column['extra'] = 'auto_increment';
                    }
                    
                    // Check for primary key
                    if (stripos($columnOptions, 'PRIMARY KEY') !== false) {
                        $column['key'] = 'PRI';
                        $primaryKey = $columnName;
                    }
                    
                    // Check for unique
                    if (stripos($columnOptions, 'UNIQUE') !== false) {
                        $column['key'] = 'UNI';
                    }
                    
                    $tableData['columns'][] = $column;
                }
                // Detect PRIMARY KEY constraint
                elseif (preg_match('/PRIMARY\s+KEY\s*\(`?([^`\)]+)`?\)/i', $line, $pkMatches)) {
                    $primaryKey = $pkMatches[1];
                }
                // Detect KEY/INDEX definitions
                elseif (preg_match('/(?:KEY|INDEX)\s+(?:`?([^`\s]+)`?)?\s*\(`?([^`\)]+)`?\)/i', $line, $keyMatches)) {
                    $indexName = isset($keyMatches[1]) ? $keyMatches[1] : '';
                    $indexColumn = isset($keyMatches[2]) ? $keyMatches[2] : '';
                    if ($indexColumn) {
                        $indexes[] = [
                            'name' => $indexName ?: 'index',
                            'column' => $indexColumn
                        ];
                    }
                }
                // Detect FOREIGN KEY
                elseif (preg_match('/FOREIGN\s+KEY/i', $line)) {
                    if (preg_match('/REFERENCES\s+`?([^`\s]+)`?\s*\(`?([^`\)]+)`?/i', $line, $fkMatches)) {
                        $foreignKeys[] = [
                            'table' => $fkMatches[1],
                            'column' => isset($fkMatches[2]) ? $fkMatches[2] : ''
                        ];
                    }
                }
            }
            
            $tableData['primary_key'] = $primaryKey;
            $tableData['indexes'] = $indexes;
            $tableData['foreign_keys'] = $foreignKeys;
        }
        
        // Calculate estimated total rows
        $tableData['estimated_rows'] = array_sum($tableData['row_estimates']);
        
        // Calculate average row size (if we have size and row estimates)
        if ($tableData['estimated_rows'] > 0 && $tableData['size'] > 0) {
            $tableData['avg_row_size'] = round($tableData['size'] / $tableData['estimated_rows'], 2);
        }
    }

    private function estimateRowsInInsert($insertStatement) {
        // Count the number of value groups in the INSERT statement
        // Simple heuristic: count occurrences of pattern that looks like value groups
        if (preg_match('/VALUES\s*\((.*)\)/i', $insertStatement, $matches)) {
            $values = $matches[1];
            // Count the number of individual rows (separated by "),(" pattern)
            $rowCount = substr_count($values, '),(') + 1;
            return $rowCount;
        }
        return 1; // Default to 1 row if pattern not matched
    }

    private function detectDatabaseOperations($line, &$databaseInfo) {
        // Detect DROP statements
        if (preg_match('/^DROP TABLE/i', $line)) {
            $databaseInfo['has_drop_statements'] = true;
        }
        
        // Detect CREATE DATABASE
        if (preg_match('/^CREATE DATABASE/i', $line)) {
            $databaseInfo['has_create_database'] = true;
        }
        
        // Detect SET statements
        if (preg_match('/^SET/', $line)) {
            $databaseInfo['set_statements'][] = $line;
        }
        
        // Detect USE statements
        if (preg_match('/^USE `/i', $line)) {
            $databaseInfo['use_statements'][] = $line;
        }
    }

    private function extractTableToFile($tableName, $tableInfo, $inputFile, $outputFile) {
        $outputHandle = fopen($outputFile, 'w');
        if (!$outputHandle) {
            return false;
        }
        
        $inputHandle = fopen($inputFile, 'r');
        if (!$inputHandle) {
            fclose($outputHandle);
            return false;
        }

        // Write CREATE TABLE statement
        if ($tableInfo['create_start'] > 0) {
            $lineNumber = 0;
            while (($line = fgets($inputHandle)) !== false) {
                $lineNumber++;
                if ($lineNumber >= $tableInfo['create_start'] && $lineNumber <= $tableInfo['create_end']) {
                    fwrite($outputHandle, $line);
                }
                if ($lineNumber > $tableInfo['create_end']) break;
            }
        }

        // Write INSERT statements - optimized: read file once and extract needed lines
        if (!empty($tableInfo['insert_positions'])) {
            // Sort insert positions for sequential reading
            $insertPositions = $tableInfo['insert_positions'];
            sort($insertPositions);
            
            // Reset file pointer if we already read past the first insert
            $currentLine = isset($tableInfo['create_end']) ? $tableInfo['create_end'] : 0;
            if ($currentLine > 0 && $insertPositions[0] <= $currentLine) {
                fseek($inputHandle, 0);
                $currentLine = 0;
            }
            
            $insertIndex = 0;
            while (($line = fgets($inputHandle)) !== false && $insertIndex < count($insertPositions)) {
                $currentLine++;
                if ($currentLine == $insertPositions[$insertIndex]) {
                    fwrite($outputHandle, $line);
                    $insertIndex++;
                }
            }
        }

        fclose($outputHandle);
        fclose($inputHandle);
        return true;
    }

    private function updateProgress($message, $percent) {
        // For real progress updates, you might use a file or database
        // This is a simplified version
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['progress'] = [
                'message' => $message,
                'percent' => $percent
            ];
            session_write_close();
            session_start();
        }
    }

    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

// Handle the request
$processor = new SQLWebProcessor();
$processor->handleRequest();