<?php
/**
 * CERTOLO - Revoke Certificate
 * Allows certifiers to revoke active certificates
 */

// Only certifiers can revoke certificates
if ($userRole !== ROLE_CERTIFIER) {
    header('HTTP/1.0 403 Forbidden');
    exit('Access denied');
}

$certificateId = $id ?? $_POST['certificate_id'] ?? null;

if (!$certificateId) {
    $_SESSION['error'] = 'Certificate ID is required.';
    header('Location: /certificates');
    exit;
}

// Only handle POST requests for revocation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid security token. Please try again.';
        header('Location: /certificates/view/' . $certificateId);
        exit;
    }
    
    $revocationReason = trim($_POST['revocation_reason'] ?? '');
    
    if (empty($revocationReason)) {
        $_SESSION['error'] = 'Revocation reason is required.';
        header('Location: /certificates/view/' . $certificateId);
        exit;
    }
    
    try {
        $db = Database::getInstance();
        $db->beginTransaction();
        
        // Verify certificate belongs to this certifier and is active
        $stmt = $db->query(
            "SELECT c.*, u.company_name, u.email, u.contact_person,
                    s.name as standard_name
             FROM certificates c
             JOIN users u ON c.applicant_id = u.id
             JOIN standards s ON c.standard_id = s.id
             WHERE c.id = :id AND c.certifier_id = :certifier_id AND c.status = 'active'",
            ['id' => $certificateId, 'certifier_id' => $userId]
        );
        
        $certificate = $stmt->fetch();
        
        if (!$certificate) {
            $_SESSION['error'] = 'Certificate not found, access denied, or certificate is already revoked.';
            header('Location: /certificates');
            exit;
        }
        
        // Update certificate status
        $db->query(
            "UPDATE certificates 
             SET status = 'revoked', 
                 revoked_at = NOW(), 
                 revocation_reason = :reason
             WHERE id = :id",
            ['id' => $certificateId, 'reason' => $revocationReason]
        );
        
        error_log('CERTOLO: Certificate revoked: ' . $certificate['certificate_number'] . ' by user: ' . $userId);
        
        // Try to log activity (optional - don't fail if table doesn't exist)
        try {
            $db->query(
                "INSERT INTO activity_logs (user_id, action, module, details) 
                 VALUES (:user_id, 'certificate_revoked', 'certificates', :details)",
                [
                    'user_id' => $userId,
                    'details' => json_encode([
                        'certificate_id' => $certificateId,
                        'certificate_number' => $certificate['certificate_number'],
                        'reason' => $revocationReason,
                        'applicant_company' => $certificate['company_name']
                    ])
                ]
            );
        } catch (Exception $e) {
            error_log('CERTOLO: Activity logging failed (non-critical): ' . $e->getMessage());
        }
        
        // Try to queue notification email (optional - don't fail if table doesn't exist)
        try {
            $db->query(
                "INSERT INTO email_logs (to_email, subject, template, data) 
                 VALUES (:email, :subject, 'certificate_revoked', :data)",
                [
                    'email' => $certificate['email'],
                    'subject' => 'Certificate Revoked - ' . $certificate['certificate_number'],
                    'data' => json_encode([
                        'company_name' => $certificate['company_name'],
                        'contact_person' => $certificate['contact_person'],
                        'certificate_number' => $certificate['certificate_number'],
                        'standard_name' => $certificate['standard_name'],
                        'revocation_reason' => $revocationReason,
                        'revoked_date' => date('d F Y'),
                        'revoked_by' => $_SESSION['company_name'] ?? 'Certifier'
                    ])
                ]
            );
        } catch (Exception $e) {
            error_log('CERTOLO: Email queueing failed (non-critical): ' . $e->getMessage());
        }
        
        $db->commit();
        
        $_SESSION['success'] = 'Certificate has been successfully revoked. The certificate holder has been notified.';
        
    } catch (Exception $e) {
        $db->rollback();
        error_log('CERTOLO: Certificate revocation error: ' . $e->getMessage());
        error_log('CERTOLO: Stack trace: ' . $e->getTraceAsString());
        $_SESSION['error'] = 'An error occurred while revoking the certificate. Please try again.';
    }
} else {
    // Not a POST request - redirect to certificate view
    $_SESSION['error'] = 'Invalid request method.';
}

// Redirect back to certificate view
header('Location: /certificates/view/' . $certificateId);
exit;
?>