<?php
session_start();

// Clean up temporary files
if (isset($_SESSION['file_id'])) {
    $fileId = $_SESSION['file_id'];
    $uploadDir = 'uploads/';
    $outputDir = 'outputs/';
    
    // Remove SQL file
    $sqlFile = $uploadDir . $fileId . '.sql';
    if (file_exists($sqlFile)) {
        unlink($sqlFile);
    }
    
    // Remove split files directory
    $splitDir = $outputDir . $fileId . '_split/';
    if (file_exists($splitDir)) {
        $files = glob($splitDir . '*');
        foreach ($files as $file) {
            if (is_file($file)) unlink($file);
        }
        if (is_dir($splitDir)) rmdir($splitDir);
    }
}

// Clear session
session_destroy();

echo json_encode(['success' => true], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);