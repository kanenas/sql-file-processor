<?php
session_start();

// Prevent any output before headers
ob_start();

$filename = $_GET['name'] ?? '';

// Check if filename is provided
if (empty($filename)) {
    ob_end_clean();
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Invalid download request: filename required');
}

// Use the session file_id for security (not from URL)
$fileId = $_SESSION['file_id'] ?? '';
if (empty($fileId)) {
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: text/plain');
    die('Session expired. Please upload the file again.');
}

$outputDir = 'outputs/' . $fileId . '_split/';
$filePath = $outputDir . $filename;

// Security checks - prevent directory traversal and invalid characters
if (strpos($filename, '..') !== false || 
    strpos($filename, '/') !== false || 
    strpos($filename, '\\') !== false ||
    strpos($filename, "\0") !== false ||
    preg_match('/[<>:"|?*]/', $filename)) {
    ob_end_clean();
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Invalid filename');
}

// Additional security: ensure file is within output directory
$realFilePath = realpath($filePath);
$realOutputDir = realpath($outputDir);
if ($realFilePath === false || $realOutputDir === false || 
    strpos($realFilePath, $realOutputDir) !== 0) {
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: text/plain');
    die('Access denied');
}

if (!file_exists($filePath)) {
    ob_end_clean();
    http_response_code(404);
    header('Content-Type: text/plain');
    die('File not found: ' . htmlspecialchars($filename, ENT_QUOTES, 'UTF-8'));
}

// Clear any previous output
ob_end_clean();

// Set proper headers for SQL file download
header('Content-Description: File Transfer');
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . filesize($filePath));

// Send file
readfile($filePath);
exit;