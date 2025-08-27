<?php
/**
 * CERTOLO - Generate Certificate
 * Create certificate from approved application
 */

// Only certifiers can generate certificates
if ($userRole !== ROLE_CERTIFIER) {
    header('HTTP/1.0 403 Forbidden');
    exit('Access denied');
}

$errors = [];
$success = false;

// Get application ID from URL or POST
$applicationId = $id ?? $_POST['application_id'] ?? $_GET['application_id'] ?? null;

if (!$applicationId) {
    header('Location: /applications');
    exit;
}

try {
    $db = Database::getInstance();
    
    // Get application details
    $stmt = $db->query(
        "SELECT a.*, 
                s.name as standard_name, s.type as standard_type, 
                s.validity_months, s.price, s.certifier_id,
                u_app.company_name as applicant_company, u_app.contact_person,
                u_app.email as applicant_email,
                u_cert.company_name as certifier_name
         FROM applications a
         JOIN standards s ON a.standard_id = s.id
         JOIN users u_app ON a.applicant_id = u_app.id
         JOIN users u_cert ON a.certifier_id = u_cert.id
         WHERE a.id = :id AND a.certifier_id = :certifier_id",
        ['id' => $applicationId, 'certifier_id' => $userId]
    );
    
    $application = $stmt->fetch();
    
    if (!$application) {
        header('HTTP/1.0 404 Not Found');
        exit('Application not found or access denied');
    }
    
    // Check if application is approved
    if ($application['status'] !== 'approved') {
        $_SESSION['error'] = 'Only approved applications can have certificates generated.';
        header('Location: /applications/view/' . $applicationId);
        exit;
    }
    
    // Check if certificate already exists
    $existingCertStmt = $db->query(
        "SELECT id FROM certificates WHERE application_id = :app_id",
        ['app_id' => $applicationId]
    );
    
    if ($existingCertStmt->fetch()) {
        $_SESSION['error'] = 'Certificate already exists for this application.';
        header('Location: /applications/view/' . $applicationId);
        exit;
    }
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Verify CSRF token
        if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $errors[] = 'Invalid security token. Please refresh and try again.';
        } else {
            // Generate certificate
            try {
                error_log('CERTOLO: Starting certificate generation for application: ' . $applicationId);
                
                $db->beginTransaction();
                
                // Generate unique certificate number
                $certificateNumber = generateCertificateNumber($application['standard_id']);
                error_log('CERTOLO: Certificate number generated: ' . $certificateNumber);
                
                // Generate unique verification code
                $verificationCode = generateVerificationCode();
                error_log('CERTOLO: Verification code generated: ' . $verificationCode);
                
                // Calculate expiry date
                $issuedAt = new DateTime();
                $expiresAt = clone $issuedAt;
                $expiresAt->add(new DateInterval('P' . intval($application['validity_months']) . 'M'));
                
                // Insert certificate record
                $db->query(
                    "INSERT INTO certificates (
                        application_id, applicant_id, certifier_id, standard_id,
                        certificate_number, verification_code, status,
                        issued_at, expires_at
                    ) VALUES (
                        :application_id, :applicant_id, :certifier_id, :standard_id,
                        :cert_number, :verification_code, 'active',
                        :issued_at, :expires_at
                    )",
                    [
                        'application_id' => $applicationId,
                        'applicant_id' => $application['applicant_id'],
                        'certifier_id' => $userId,
                        'standard_id' => $application['standard_id'],
                        'cert_number' => $certificateNumber,
                        'verification_code' => $verificationCode,
                        'issued_at' => $issuedAt->format('Y-m-d H:i:s'),
                        'expires_at' => $expiresAt->format('Y-m-d H:i:s')
                    ]
                );
                
                $certificateId = $db->lastInsertId();
                error_log('CERTOLO: Certificate inserted with ID: ' . $certificateId);
                
                // Update application status to 'issued'
                $db->query(
                    "UPDATE applications SET status = 'issued' WHERE id = :id",
                    ['id' => $applicationId]
                );
                
                // Try to log activity (optional - don't fail if table doesn't exist)
                try {
                    $db->query(
                        "INSERT INTO activity_logs (user_id, action, module, details) 
                         VALUES (:user_id, 'certificate_generated', 'certificates', :details)",
                        [
                            'user_id' => $userId,
                            'details' => json_encode([
                                'certificate_id' => $certificateId,
                                'certificate_number' => $certificateNumber,
                                'application_id' => $applicationId,
                                'applicant_company' => $application['applicant_company']
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
                         VALUES (:email, :subject, 'certificate_issued', :data)",
                        [
                            'email' => $application['applicant_email'],
                            'subject' => 'Certificate Issued - ' . $application['standard_name'],
                            'data' => json_encode([
                                'company_name' => $application['applicant_company'],
                                'contact_person' => $application['contact_person'],
                                'standard_name' => $application['standard_name'],
                                'certificate_number' => $certificateNumber,
                                'verification_code' => $verificationCode,
                                'certificate_url' => SITE_URL . '/certificates/view/' . $certificateId,
                                'verification_url' => SITE_URL . '/certificates/verify/' . $verificationCode
                            ])
                        ]
                    );
                } catch (Exception $e) {
                    error_log('CERTOLO: Email queueing failed (non-critical): ' . $e->getMessage());
                }
                
                $db->commit();
                error_log('CERTOLO: Certificate generation completed successfully');
                
                $_SESSION['success'] = 'Certificate has been successfully generated!';
                header('Location: /certificates/view/' . $certificateId);
                exit;
                
            } catch (Exception $e) {
                $db->rollback();
                error_log('CERTOLO: Certificate generation error: ' . $e->getMessage());
                error_log('CERTOLO: Stack trace: ' . $e->getTraceAsString());
                $errors[] = 'An error occurred while generating the certificate. Error: ' . $e->getMessage();
            }
        }
    }
    
    // Set page title
    $pageTitle = 'Generate Certificate';
    
    // Include header
    include INCLUDES_PATH . 'header.php';
    ?>
    
    <div class="page-body">
        <div class="container-xl">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="ti ti-certificate"></i>
                                Generate Certificate
                            </h3>
                        </div>
                        
                        <form method="POST" action="/certificates/generate/<?php echo $applicationId; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                            <input type="hidden" name="application_id" value="<?php echo $applicationId; ?>">
                            
                            <div class="card-body">
                                <?php if (!empty($errors)): ?>
                                    <div class="alert alert-danger">
                                        <div class="d-flex">
                                            <div>
                                                <i class="ti ti-alert-circle icon alert-icon"></i>
                                            </div>
                                            <div>
                                                <h4 class="alert-title">Error occurred</h4>
                                                <ul class="mb-0">
                                                    <?php foreach ($errors as $error): ?>
                                                        <li><?php echo Security::escape($error); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="alert alert-info">
                                    <div class="d-flex">
                                        <div>
                                            <i class="ti ti-info-circle icon alert-icon"></i>
                                        </div>
                                        <div>
                                            <h4 class="alert-title">Ready to Generate Certificate</h4>
                                            <div class="text-muted">
                                                All requirements have been verified and the application has been approved.
                                                Click the button below to generate and issue the certificate.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Application Summary -->
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Applicant Company:</label>
                                            <div><strong><?php echo Security::escape($application['applicant_company']); ?></strong></div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Contact Person:</label>
                                            <div><?php echo Security::escape($application['contact_person']); ?></div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Email:</label>
                                            <div><?php echo Security::escape($application['applicant_email']); ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Standard:</label>
                                            <div><strong><?php echo Security::escape($application['standard_name']); ?></strong></div>
                                            <?php if ($application['standard_type']): ?>
                                                <div class="small text-muted"><?php echo Security::escape($application['standard_type']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Validity Period:</label>
                                            <div><?php echo $application['validity_months']; ?> months</div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Application Status:</label>
                                            <div>
                                                <span class="badge bg-green">Approved</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Certificate Preview Information -->
                                <div class="card bg-light">
                                    <div class="card-header">
                                        <h4 class="card-title">Certificate Information</h4>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-2">
                                                    <strong>Certificate Number:</strong> Will be auto-generated
                                                </div>
                                                <div class="mb-2">
                                                    <strong>Verification Code:</strong> Will be auto-generated
                                                </div>
                                                <div class="mb-2">
                                                    <strong>Issue Date:</strong> <?php echo date('d F Y'); ?>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-2">
                                                    <strong>Valid Until:</strong> 
                                                    <?php 
                                                    $expiryPreview = new DateTime();
                                                    $expiryPreview->add(new DateInterval('P' . intval($application['validity_months']) . 'M'));
                                                    echo $expiryPreview->format('d F Y');
                                                    ?>
                                                </div>
                                                <div class="mb-2">
                                                    <strong>Issued By:</strong> <?php echo Security::escape($application['certifier_name']); ?>
                                                </div>
                                                <div class="mb-2">
                                                    <strong>Status:</strong> <span class="badge bg-green">Active</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="alert alert-success mt-4">
                                    <h4 class="alert-title">What happens when you generate the certificate?</h4>
                                    <ul class="mb-0">
                                        <li>A unique certificate number and verification code will be generated</li>
                                        <li>The certificate will be marked as active and valid</li>
                                        <li>The applicant will receive an email notification</li>
                                        <li>The application status will be updated to "Issued"</li>
                                        <li>You will be redirected to view the generated certificate</li>
                                    </ul>
                                </div>
                            </div>
                            
                            <div class="card-footer text-end">
                                <a href="/applications/view/<?php echo $applicationId; ?>" class="btn btn-link">
                                    <i class="ti ti-arrow-left"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="ti ti-certificate"></i>
                                    Generate Certificate
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php
    // Include footer
    include INCLUDES_PATH . 'footer.php';

} catch (Exception $e) {
    error_log('CERTOLO: Error in certificate generation page: ' . $e->getMessage());
    
    // Set page title
    $pageTitle = 'Generate Certificate - Error';
    
    // Include header
    include INCLUDES_PATH . 'header.php';
    ?>
    
    <div class="page-body">
        <div class="container-xl">
            <div class="alert alert-danger">
                <div class="d-flex">
                    <div>
                        <i class="ti ti-alert-circle icon alert-icon"></i>
                    </div>
                    <div>
                        <h4 class="alert-title">An error occurred</h4>
                        <div class="text-muted">
                            An error occurred while loading the certificate generation page. Please try again later.
                        </div>
                    </div>
                </div>
            </div>
            <div class="text-center">
                <a href="/applications" class="btn btn-primary">
                    <i class="ti ti-arrow-left"></i> Back to Applications
                </a>
            </div>
        </div>
    </div>
    
    <?php
    // Include footer
    include INCLUDES_PATH . 'footer.php';
}

/**
 * Generate unique certificate number
 */
function generateCertificateNumber($standardId) {
    $prefix = defined('CERTIFICATE_PREFIX') ? CERTIFICATE_PREFIX : 'CERT';
    $year = date('Y');
    $month = date('m');
    
    // Format: CERT-2025-08-0001
    $baseNumber = $prefix . '-' . $year . '-' . $month . '-';
    
    try {
        // Get database instance
        $db = Database::getInstance();
        $stmt = $db->query(
            "SELECT certificate_number FROM certificates 
             WHERE certificate_number LIKE :pattern 
             ORDER BY certificate_number DESC LIMIT 1",
            ['pattern' => $baseNumber . '%']
        );
        
        $lastCert = $stmt->fetch();
        
        if ($lastCert) {
            // Extract the sequential number and increment
            $lastNumber = substr($lastCert['certificate_number'], strlen($baseNumber));
            $nextNumber = str_pad((int)$lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $nextNumber = '0001';
        }
        
        return $baseNumber . $nextNumber;
        
    } catch (Exception $e) {
        error_log('CERTOLO: Error generating certificate number: ' . $e->getMessage());
        // Fallback to timestamp-based number
        return $prefix . '-' . $year . '-' . $month . '-' . date('His');
    }
}

/**
 * Generate unique verification code
 */
function generateVerificationCode() {
    $length = defined('VERIFICATION_CODE_LENGTH') ? VERIFICATION_CODE_LENGTH : 10;
    
    $maxAttempts = 10; // Prevent infinite loop
    $attempts = 0;
    
    do {
        $attempts++;
        
        // Generate random alphanumeric code
        $code = '';
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        try {
            // Check if code already exists
            $db = Database::getInstance();
            $stmt = $db->query(
                "SELECT id FROM certificates WHERE verification_code = :code",
                ['code' => $code]
            );
            
            $exists = $stmt->fetch();
            
            if (!$exists || $attempts >= $maxAttempts) {
                break;
            }
            
        } catch (Exception $e) {
            error_log('CERTOLO: Error checking verification code uniqueness: ' . $e->getMessage());
            break; // Use the generated code anyway
        }
        
    } while ($attempts < $maxAttempts);
    
    return $code;
}

/**
 * Generate certificate PDF (placeholder implementation)
 */
function generateCertificatePDF($certificateId, $application, $certificateNumber, $verificationCode, $issuedAt, $expiresAt) {
    try {
        // For now, return null - PDF generation requires additional libraries
        // In production, you could use libraries like TCPDF, FPDF, or Dompdf
        
        /*
        // Example with basic HTML to PDF conversion:
        $html = generateCertificateHTML($application, $certificateNumber, $verificationCode, $issuedAt, $expiresAt);
        $filename = 'certificate_' . $certificateNumber . '.pdf';
        $filepath = UPLOAD_CERTIFICATES . $filename;
        
        // Generate PDF using your preferred library
        // $pdf = new SomePDFLibrary();
        // $pdf->generateFromHTML($html);
        // $pdf->save($filepath);
        
        return $filename;
        */
        
        return null;
        
    } catch (Exception $e) {
        error_log('CERTOLO: PDF generation error: ' . $e->getMessage());
        return null;
    }
}
?>