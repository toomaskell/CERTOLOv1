<?php
/**
 * CERTOLO - View Certificate
 * Display certificate details
 */

// Get certificate ID
$certificateId = $id ?? $_GET['id'] ?? null;

if (!$certificateId) {
    header('Location: /certificates');
    exit;
}

try {
    $db = Database::getInstance();
    
    // Get certificate details with related data
    $stmt = $db->query(
        "SELECT c.*, 
                s.name as standard_name, s.type as standard_type, 
                s.description as standard_description, s.requirements as standard_requirements,
                s.validity_months, s.price,
                u_app.company_name as applicant_company, u_app.email as applicant_email,
                u_app.contact_person as applicant_contact, u_app.address as applicant_address,
                u_app.city as applicant_city, u_app.country as applicant_country,
                u_cert.company_name as certifier_name, u_cert.email as certifier_email,
                a.id as application_id, a.application_data, a.submitted_at, a.reviewed_at
         FROM certificates c
         JOIN standards s ON c.standard_id = s.id
         JOIN users u_app ON c.applicant_id = u_app.id
         JOIN users u_cert ON c.certifier_id = u_cert.id
         LEFT JOIN applications a ON c.application_id = a.id
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
    
    // Parse application data if available
    $applicationData = json_decode($certificate['application_data'], true) ?? [];
    
    // Calculate certificate status
    $expiryDate = new DateTime($certificate['expires_at']);
    $now = new DateTime();
    $daysToExpiry = $now->diff($expiryDate)->days;
    $isExpired = $expiryDate <= $now;
    $isExpiringSoon = !$isExpired && $daysToExpiry <= 30;
    
    // Set page title
    $pageTitle = 'Certificate ' . $certificate['certificate_number'];
    
    // Include header
    include INCLUDES_PATH . 'header.php';
    ?>
    
    <!-- Custom styles for white text on certificate badges -->
    <style>
    .badge.bg-green,
    .badge.bg-gray,
    .badge.bg-red,
    .badge.bg-secondary {
        color: #ffffff !important;
    }
    </style>
    
    <div class="page-header d-print-none">
        <div class="container-xl">
            <div class="row g-2 align-items-center">
                <div class="col">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="/certificates">Certificates</a></li>
                            <li class="breadcrumb-item active"><?php echo Security::escape($certificate['certificate_number']); ?></li>
                        </ol>
                    </nav>
                    <h2 class="page-title">
                        Certificate <?php echo Security::escape($certificate['certificate_number']); ?>
                    </h2>
                </div>
                <div class="col-auto">
                    <?php
                    $statusClass = match($certificate['status']) {
                        'active' => 'bg-green',
                        'expired' => 'bg-gray',
                        'revoked' => 'bg-red',
                        default => 'bg-secondary'
                    };
                    ?>
                    <span class="badge <?php echo $statusClass; ?> badge-lg">
                        <?php echo ucfirst($certificate['status']); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="page-body">
        <div class="container-xl">
            <div class="row g-4">
                <div class="col-lg-8">
                    <!-- Certificate Details -->
                    <div class="card">
                        <div class="card-header">
                            <div class="row align-items-center">
                                <div class="col">
                                    <h3 class="card-title">
                                        <i class="ti ti-certificate"></i>
                                        Certificate Details
                                    </h3>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="certificate-preview bg-light border rounded p-4 mb-4">
                                <div class="text-center mb-4">
                                    <h2 class="text-primary mb-1">CERTIFICATE OF COMPLIANCE</h2>
                                    <div class="text-muted">CERTOLO Certification Authority</div>
                                </div>
                                
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <strong>Certificate Number:</strong><br>
                                        <span class="fs-5 text-primary"><?php echo Security::escape($certificate['certificate_number']); ?></span>
                                    </div>
                                    <div class="col-md-6 text-md-end">
                                        <strong>Verification Code:</strong><br>
                                        <span class="fs-5 font-monospace"><?php echo Security::escape($certificate['verification_code']); ?></span>
                                    </div>
                                </div>
                                
                                <div class="text-center mb-4">
                                    <h4>This is to certify that</h4>
                                    <h3 class="text-primary mb-3"><?php echo Security::escape($certificate['applicant_company']); ?></h3>
                                    <p class="mb-4">
                                        has successfully met all requirements for compliance with
                                    </p>
                                    <h4 class="text-success"><?php echo Security::escape($certificate['standard_name']); ?></h4>
                                    <?php if ($certificate['standard_type']): ?>
                                        <div class="text-muted"><?php echo Security::escape($certificate['standard_type']); ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="row text-center">
                                    <div class="col-md-4">
                                        <strong>Issued Date:</strong><br>
                                        <?php echo date('d F Y', strtotime($certificate['issued_at'])); ?>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Valid Until:</strong><br>
                                        <?php echo date('d F Y', strtotime($certificate['expires_at'])); ?>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Issued By:</strong><br>
                                        <?php echo Security::escape($certificate['certifier_name']); ?>
                                    </div>
                                </div>
                                
                                <?php if ($certificate['status'] === 'revoked'): ?>
                                    <div class="alert alert-danger mt-4 text-center">
                                        <strong>REVOKED</strong><br>
                                        This certificate has been revoked on <?php echo date('d F Y', strtotime($certificate['revoked_at'])); ?>
                                        <?php if ($certificate['revocation_reason']): ?>
                                            <div class="mt-2 small">
                                                Reason: <?php echo Security::escape($certificate['revocation_reason']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($isExpired): ?>
                                    <div class="alert alert-warning mt-4 text-center">
                                        <strong>EXPIRED</strong><br>
                                        This certificate expired on <?php echo date('d F Y', strtotime($certificate['expires_at'])); ?>
                                    </div>
                                <?php elseif ($isExpiringSoon): ?>
                                    <div class="alert alert-info mt-4 text-center">
                                        <strong>EXPIRING SOON</strong><br>
                                        This certificate will expire on <?php echo date('d F Y', strtotime($certificate['expires_at'])); ?>
                                        (<?php echo $daysToExpiry; ?> days remaining)
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Certificate Actions -->
                            <div class="d-flex gap-2 flex-wrap">
                                <?php if ($certificate['certificate_file']): ?>
                                    <a href="/certificates/download/<?php echo $certificate['id']; ?>" 
                                       class="btn btn-primary">
                                        <i class="ti ti-download"></i> Download PDF
                                    </a>
                                <?php endif; ?>
                                
                                <a href="/certificates/verify/<?php echo $certificate['verification_code']; ?>" 
                                   class="btn btn-outline-info" target="_blank">
                                    <i class="ti ti-shield-check"></i> Public Verification
                                </a>
                                
                                <?php if ($certificate['application_id']): ?>
                                    <a href="/applications/view/<?php echo $certificate['application_id']; ?>" 
                                       class="btn btn-outline-secondary">
                                        <i class="ti ti-file-text"></i> View Application
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ($userRole === ROLE_CERTIFIER && $certificate['status'] === 'active'): ?>
                                    <button type="button" class="btn btn-outline-danger"
                                            onclick="revokeCertificate(<?php echo $certificate['id']; ?>)">
                                        <i class="ti ti-ban"></i> Revoke Certificate
                                    </button>
                                <?php endif; ?>
                                
                                <button onclick="window.print()" class="btn btn-outline-secondary">
                                    <i class="ti ti-printer"></i> Print
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Standard Information -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="ti ti-info-circle"></i>
                                Standard Information
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Standard Name:</label>
                                        <div><?php echo Security::escape($certificate['standard_name']); ?></div>
                                    </div>
                                    <?php if ($certificate['standard_type']): ?>
                                        <div class="mb-3">
                                            <label class="form-label">Type:</label>
                                            <div><?php echo Security::escape($certificate['standard_type']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Validity Period:</label>
                                        <div><?php echo $certificate['validity_months']; ?> months</div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Certifier:</label>
                                        <div><?php echo Security::escape($certificate['certifier_name']); ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($certificate['standard_description']): ?>
                                <div class="mb-3">
                                    <label class="form-label">Description:</label>
                                    <div><?php echo nl2br(Security::escape($certificate['standard_description'])); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <!-- Certificate Summary -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Certificate Summary</h3>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="row">
                                    <div class="col">Certificate Number:</div>
                                    <div class="col-auto">
                                        <strong><?php echo Security::escape($certificate['certificate_number']); ?></strong>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="row">
                                    <div class="col">Status:</div>
                                    <div class="col-auto">
                                        <span class="badge <?php echo $statusClass; ?>">
                                            <?php echo ucfirst($certificate['status']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="row">
                                    <div class="col">Issued:</div>
                                    <div class="col-auto">
                                        <?php echo date('d M Y', strtotime($certificate['issued_at'])); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="row">
                                    <div class="col">Expires:</div>
                                    <div class="col-auto">
                                        <?php echo date('d M Y', strtotime($certificate['expires_at'])); ?>
                                    </div>
                                </div>
                            </div>
                            <?php if ($certificate['status'] === 'active' && !$isExpired): ?>
                                <div class="mb-3">
                                    <div class="row">
                                        <div class="col">Days Remaining:</div>
                                        <div class="col-auto">
                                            <strong class="<?php echo $isExpiringSoon ? 'text-warning' : 'text-success'; ?>">
                                                <?php echo $daysToExpiry; ?>
                                            </strong>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="mb-3">
                                <div class="row">
                                    <div class="col">Verification Code:</div>
                                    <div class="col-auto">
                                        <code><?php echo Security::escape($certificate['verification_code']); ?></code>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Company Information -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Company Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Company Name:</label>
                                <div><strong><?php echo Security::escape($certificate['applicant_company']); ?></strong></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Contact Person:</label>
                                <div><?php echo Security::escape($certificate['applicant_contact']); ?></div>
                            </div>
                            <?php if ($certificate['applicant_address']): ?>
                                <div class="mb-3">
                                    <label class="form-label">Address:</label>
                                    <div>
                                        <?php echo Security::escape($certificate['applicant_address']); ?>
                                        <?php if ($certificate['applicant_city']): ?>
                                            <br><?php echo Security::escape($certificate['applicant_city']); ?>
                                        <?php endif; ?>
                                        <?php if ($certificate['applicant_country']): ?>
                                            <br><?php echo Security::escape($certificate['applicant_country']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if ($userRole === ROLE_CERTIFIER): ?>
                                <div class="mb-3">
                                    <label class="form-label">Email:</label>
                                    <div>
                                        <a href="mailto:<?php echo Security::escape($certificate['applicant_email']); ?>">
                                            <?php echo Security::escape($certificate['applicant_email']); ?>
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="/certificates" class="btn btn-outline-primary">
                                    <i class="ti ti-arrow-left"></i> Back to Certificates
                                </a>
                                
                                <?php if ($userRole === ROLE_APPLICANT && $certificate['status'] === 'active' && $isExpiringSoon): ?>
                                    <a href="/standards/view/<?php echo $certificate['standard_id']; ?>?renew=1" 
                                       class="btn btn-warning">
                                        <i class="ti ti-refresh"></i> Renew Certificate
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ($userRole === ROLE_CERTIFIER): ?>
                                    <a href="/applications?certifier_id=<?php echo $userId; ?>" 
                                       class="btn btn-outline-info">
                                        <i class="ti ti-file-text"></i> View Applications
                                    </a>
                                    <a href="/certificates?status=active" 
                                       class="btn btn-outline-success">
                                        <i class="ti ti-certificate"></i> All Active Certificates
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Revoke Certificate Modal -->
    <?php if ($userRole === ROLE_CERTIFIER && $certificate['status'] === 'active'): ?>
    <div class="modal modal-blur fade" id="revokeModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Revoke Certificate</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="revokeForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Revocation Reason</label>
                            <textarea name="revocation_reason" class="form-control" rows="3" required 
                                      placeholder="Please provide the reason for revoking this certificate..."></textarea>
                        </div>
                        <div class="alert alert-warning">
                            <i class="ti ti-alert-triangle"></i>
                            <strong>Warning:</strong> This action cannot be undone. The certificate will be permanently revoked and the holder will be notified.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="ti ti-ban"></i> Revoke Certificate
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <script>
    function revokeCertificate(certificateId) {
        const modal = new bootstrap.Modal(document.getElementById('revokeModal'));
        const form = document.getElementById('revokeForm');
        form.action = '/certificates/revoke/' + certificateId;
        modal.show();
    }
    </script>
    
    <?php
    // Include footer
    include INCLUDES_PATH . 'footer.php';

} catch (Exception $e) {
    error_log('CERTOLO: Error viewing certificate: ' . $e->getMessage());
    
    // Set page title
    $pageTitle = 'Certificate - Error';
    
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
                            An error occurred while loading the certificate. Please try again later.
                        </div>
                    </div>
                </div>
            </div>
            <div class="text-center">
                <a href="/certificates" class="btn btn-primary">
                    <i class="ti ti-arrow-left"></i> Back to Certificates
                </a>
            </div>
        </div>
    </div>
    
    <?php
    // Include footer
    include INCLUDES_PATH . 'footer.php';
}
?>