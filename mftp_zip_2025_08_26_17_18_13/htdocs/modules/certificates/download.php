/**
 * modules/certificates/download.php - Download Certificate PDF
 */

$certificateId = $id ?? $_GET['id'] ?? null;

if (!$certificateId) {
    header('Location: /certificates');
    exit;
}

try {
    $db = Database::getInstance();
    
    // Get certificate details
    $stmt = $db->query(
        "SELECT c.*, s.name as standard_name 
         FROM certificates c
         JOIN standards s ON c.standard_id = s.id
         WHERE c.id = :id",
        ['id' => $certificateId]
    );
    
    $certificate = $stmt->fetch();
    
    if (!$certificate) {
        header('HTTP/1.0 404 Not Found');
        exit('Certificate not found');
    }
    
    // Check access rights
    if ($userRole === ROLE_APPLICANT && $certificate['applicant_id'] != $userId) {
        header('HTTP/1.0 403 Forbidden');
        exit('Access denied');
    } elseif ($userRole === ROLE_CERTIFIER && $certificate['certifier_id'] != $userId) {
        header('HTTP/1.0 403 Forbidden');
        exit('Access denied');
    }
    
    // Check if certificate file exists
    if (!$certificate['certificate_file']) {
        $_SESSION['error'] = 'Certificate PDF is not available. Please contact the certifier.';
        header('Location: /certificates/view/' . $certificateId);
        exit;
    }
    
    $filePath = UPLOAD_CERTIFICATES . $certificate['certificate_file'];
    
    if (!file_exists($filePath)) {
        $_SESSION['error'] = 'Certificate file not found on server.';
        header('Location: /certificates/view/' . $certificateId);
        exit;
    }
    
    // Set headers for file download
    $filename = 'Certificate_' . $certificate['certificate_number'] . '.pdf';
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    // Output file
    readfile($filePath);
    exit;
    
} catch (Exception $e) {
    error_log('Certificate download error: ' . $e->getMessage());
    $_SESSION['error'] = 'An error occurred while downloading the certificate.';
    header('Location: /certificates');
    exit;
}