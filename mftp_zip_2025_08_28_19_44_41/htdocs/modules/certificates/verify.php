/**
 * modules/certificates/verify.php - Public Certificate Verification
 */

$verificationCode = $id ?? $_GET['code'] ?? null;

if (!$verificationCode) {
    $pageTitle = 'Certificate Verification';
    include INCLUDES_PATH . 'header.php';
    ?>
    
    <div class="page-body">
        <div class="container-xl">
            <div class="row justify-content-center">
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header text-center">
                            <h3 class="card-title">
                                <i class="ti ti-shield-check"></i>
                                Certificate Verification
                            </h3>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="/certificates/verify">
                                <div class="mb-3">
                                    <label class="form-label">Verification Code</label>
                                    <input type="text" name="code" class="form-control" 
                                           placeholder="Enter verification code..." required>
                                    <div class="form-hint">
                                        Enter the verification code from the certificate to verify its authenticity.
                                    </div>
                                </div>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="ti ti-search"></i> Verify Certificate
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php
    include INCLUDES_PATH . 'footer.php';
    exit;
}

try {
    $db = Database::getInstance();
    
    // Get certificate by verification code
    $stmt = $db->query(
        "SELECT c.*, 
                s.name as standard_name, s.type as standard_type,
                u_app.company_name as applicant_company,
                u_cert.company_name as certifier_company
         FROM certificates c
         JOIN standards s ON c.standard_id = s.id
         JOIN users u_app ON c.applicant_id = u_app.id
         JOIN users u_cert ON c.certifier_id = u_cert.id
         WHERE c.verification_code = :code",
        ['code' => strtoupper(trim($verificationCode))]
    );
    
    $certificate = $stmt->fetch();
    
    $pageTitle = $certificate ? 'Certificate Verification - Valid' : 'Certificate Verification - Invalid';
    include INCLUDES_PATH . 'header.php';
    ?>
    
    <div class="page-body">
        <div class="container-xl">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <?php if ($certificate): ?>
                        <!-- Valid Certificate -->
                        <?php
                        $statusClass = match($certificate['status']) {
                            'active' => 'bg-green',
                            'expired' => 'bg-gray', 
                            'revoked' => 'bg-red',
                            default => 'bg-secondary'
                        };
                        
                        $isExpired = strtotime($certificate['expires_at']) <= time();
                        ?>
                        
                        <div class="card">
                            <div class="card-header text-center">
                                <div class="mb-3">
                                    <i class="ti ti-shield-check text-green" style="font-size: 4rem;"></i>
                                </div>
                                <h3 class="card-title text-green">Certificate Verified</h3>
                                <p class="text-muted">This certificate is authentic and issued by CERTOLO</p>
                            </div>
                            <div class="card-body">
                                <!-- Certificate Display -->
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
                                        <p class="mb-4">has successfully met all requirements for compliance with</p>
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
                                            <?php echo Security::escape($certificate['certifier_company']); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="text-center mt-4">
                                        <span class="badge <?php echo $statusClass; ?> badge-lg">
                                            <?php echo strtoupper($certificate['status']); ?>
                                        </span>
                                    </div>
                                    
                                    <?php if ($certificate['status'] === 'revoked'): ?>
                                        <div class="alert alert-danger mt-4 text-center">
                                            <strong>This certificate has been REVOKED</strong><br>
                                            Revoked on: <?php echo date('d F Y', strtotime($certificate['revoked_at'])); ?>
                                            <?php if ($certificate['revocation_reason']): ?>
                                                <div class="mt-2 small">
                                                    Reason: <?php echo Security::escape($certificate['revocation_reason']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($isExpired): ?>
                                        <div class="alert alert-warning mt-4 text-center">
                                            <strong>This certificate has EXPIRED</strong><br>
                                            Expired on: <?php echo date('d F Y', strtotime($certificate['expires_at'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Verification Details -->
                                <div class="row">
                                    <div class="col-md-6">
                                        <h5>Certificate Details</h5>
                                        <table class="table table-sm">
                                            <tr>
                                                <td>Status:</td>
                                                <td><span class="badge <?php echo $statusClass; ?>"><?php echo ucfirst($certificate['status']); ?></span></td>
                                            </tr>
                                            <tr>
                                                <td>Certificate Number:</td>
                                                <td><?php echo Security::escape($certificate['certificate_number']); ?></td>
                                            </tr>
                                            <tr>
                                                <td>Verification Code:</td>
                                                <td><?php echo Security::escape($certificate['verification_code']); ?></td>
                                            </tr>
                                            <tr>
                                                <td>Issued:</td>
                                                <td><?php echo date('d M Y', strtotime($certificate['issued_at'])); ?></td>
                                            </tr>
                                            <tr>
                                                <td>Expires:</td>
                                                <td><?php echo date('d M Y', strtotime($certificate['expires_at'])); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <h5>Organization Details</h5>
                                        <table class="table table-sm">
                                            <tr>
                                                <td>Company:</td>
                                                <td><?php echo Security::escape($certificate['applicant_company']); ?></td>
                                            </tr>
                                            <tr>
                                                <td>Standard:</td>
                                                <td><?php echo Security::escape($certificate['standard_name']); ?></td>
                                            </tr>
                                            <?php if ($certificate['standard_type']): ?>
                                                <tr>
                                                    <td>Type:</td>
                                                    <td><?php echo Security::escape($certificate['standard_type']); ?></td>
                                                </tr>
                                            <?php endif; ?>
                                            <tr>
                                                <td>Certifier:</td>
                                                <td><?php echo Security::escape($certificate['certifier_company']); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                                
                                <div class="alert alert-info mt-4">
                                    <i class="ti ti-info-circle"></i>
                                    <strong>Verification Complete:</strong> 
                                    This certificate was verified on <?php echo date('d F Y H:i'); ?> and is authentic.
                                </div>
                            </div>
                        </div>
                        
                    <?php else: ?>
                        <!-- Invalid Certificate -->
                        <div class="card">
                            <div class="card-header text-center">
                                <div class="mb-3">
                                    <i class="ti ti-shield-x text-red" style="font-size: 4rem;"></i>
                                </div>
                                <h3 class="card-title text-red">Certificate Not Found</h3>
                                <p class="text-muted">The verification code you entered is invalid</p>
                            </div>
                            <div class="card-body text-center">
                                <div class="alert alert-danger">
                                    <strong>Invalid Verification Code:</strong> <?php echo Security::escape($verificationCode); ?>
                                </div>
                                <p>The verification code you entered does not match any certificate in our system.</p>
                                <p class="text-muted">
                                    Please double-check the code and try again, or contact the certificate issuer 
                                    if you believe this is an error.
                                </p>
                                <div class="mt-4">
                                    <a href="/certificates/verify" class="btn btn-primary">
                                        <i class="ti ti-search"></i> Try Another Code
                                    </a>
                                    <a href="/" class="btn btn-link">
                                        Back to Home
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <?php
    include INCLUDES_PATH . 'footer.php';
    
} catch (Exception $e) {
    error_log('Certificate verification error: ' . $e->getMessage());
    
    $pageTitle = 'Certificate Verification - Error';
    include INCLUDES_PATH . 'header.php';
    ?>
    
    <div class="page-body">
        <div class="container-xl">
            <div class="alert alert-danger">
                <i class="ti ti-alert-circle"></i>
                An error occurred while verifying the certificate. Please try again later.
            </div>
        </div>
    </div>
    
    <?php
    include INCLUDES_PATH . 'footer.php';
}