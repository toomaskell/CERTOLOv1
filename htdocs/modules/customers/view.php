<?php
/**
 * CERTOLO - Customer Detail View
 * Detailed customer profile with application and certificate history
 */

if (!$customerId) {
    header('Location: /customers');
    exit;
}

try {
    $db = Database::getInstance();
    
    // Get customer basic info
    $stmt = $db->query(
        "SELECT u.* FROM users u 
         INNER JOIN applications a ON u.id = a.applicant_id 
         WHERE u.id = :customer_id AND a.certifier_id = :certifier_id
         LIMIT 1",
        [
            'customer_id' => $customerId,
            'certifier_id' => $userId
        ]
    );
    $customer = $stmt->fetch();
    
    if (!$customer) {
        header('HTTP/1.0 404 Not Found');
        require_once 'error/404.php';
        exit;
    }
    
    // Get customer statistics
    $stmt = $db->query(
        "SELECT 
            COUNT(DISTINCT a.id) as total_applications,
            COUNT(DISTINCT CASE WHEN a.status = 'submitted' THEN a.id END) as submitted_applications,
            COUNT(DISTINCT CASE WHEN a.status = 'under_review' THEN a.id END) as under_review_applications,
            COUNT(DISTINCT CASE WHEN a.status = 'approved' THEN a.id END) as approved_applications,
            COUNT(DISTINCT CASE WHEN a.status = 'rejected' THEN a.id END) as rejected_applications,
            COUNT(DISTINCT c.id) as total_certificates,
            COUNT(DISTINCT CASE WHEN c.status = 'active' THEN c.id END) as active_certificates,
            COUNT(DISTINCT CASE WHEN c.status = 'expired' THEN c.id END) as expired_certificates,
            MIN(a.created_at) as first_application,
            MAX(a.updated_at) as last_activity
         FROM applications a
         LEFT JOIN certificates c ON a.id = c.application_id
         WHERE a.applicant_id = :customer_id AND a.certifier_id = :certifier_id",
        [
            'customer_id' => $customerId,
            'certifier_id' => $userId
        ]
    );
    $stats = $stmt->fetch();
    
    // Get applications history
    $stmt = $db->query(
        "SELECT a.*, s.name as standard_name, s.type as standard_type,
            c.certificate_number, c.status as certificate_status, 
            c.issued_at, c.expires_at
         FROM applications a
         JOIN standards s ON a.standard_id = s.id
         LEFT JOIN certificates c ON a.id = c.application_id
         WHERE a.applicant_id = :customer_id AND a.certifier_id = :certifier_id
         ORDER BY a.created_at DESC",
        [
            'customer_id' => $customerId,
            'certifier_id' => $userId
        ]
    );
    $applications = $stmt->fetchAll();
    
    // Get active certificates
    $stmt = $db->query(
        "SELECT c.*, s.name as standard_name, s.type as standard_type, a.id as application_id
         FROM certificates c
         JOIN applications a ON c.application_id = a.id
         JOIN standards s ON c.standard_id = s.id
         WHERE c.applicant_id = :customer_id AND c.certifier_id = :certifier_id
         AND c.status = 'active'
         ORDER BY c.expires_at ASC",
        [
            'customer_id' => $customerId,
            'certifier_id' => $userId
        ]
    );
    $activeCertificates = $stmt->fetchAll();
    
    // Get expiring certificates (next 90 days)
    $stmt = $db->query(
        "SELECT c.*, s.name as standard_name 
         FROM certificates c
         JOIN standards s ON c.standard_id = s.id
         WHERE c.applicant_id = :customer_id AND c.certifier_id = :certifier_id
         AND c.status = 'active'
         AND c.expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 90 DAY)
         ORDER BY c.expires_at ASC",
        [
            'customer_id' => $customerId,
            'certifier_id' => $userId
        ]
    );
    $expiringCertificates = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log('CERTOLO: Customer view error: ' . $e->getMessage());
    header('HTTP/1.0 500 Internal Server Error');
    require_once 'error/500.php';
    exit;
}

// Set page title
$pageTitle = Security::escape($customer['company_name']) . ' - Customer Details';

// Include header
include INCLUDES_PATH . 'header.php';
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="/customers">Customers</a></li>
                        <li class="breadcrumb-item active"><?php echo Security::escape($customer['company_name']); ?></li>
                    </ol>
                </nav>
                <h2 class="page-title">
                    <div class="avatar bg-primary text-white me-3">
                        <?php echo strtoupper(substr($customer['company_name'], 0, 1)); ?>
                    </div>
                    <?php echo Security::escape($customer['company_name']); ?>
                </h2>
            </div>
            <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                    <?php if ($customer['email']): ?>
                        <a href="mailto:<?php echo Security::escape($customer['email']); ?>" class="btn btn-primary">
                            <i class="ti ti-mail"></i>
                            Send Email
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        <div class="row">
            <!-- Customer Information -->
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Company Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="datagrid">
                            <div class="datagrid-item">
                                <div class="datagrid-title">Company Name</div>
                                <div class="datagrid-content"><?php echo Security::escape($customer['company_name']); ?></div>
                            </div>
                            <div class="datagrid-item">
                                <div class="datagrid-title">Contact Person</div>
                                <div class="datagrid-content"><?php echo Security::escape($customer['contact_person']); ?></div>
                            </div>
                            <?php if ($customer['email']): ?>
                            <div class="datagrid-item">
                                <div class="datagrid-title">Email</div>
                                <div class="datagrid-content">
                                    <a href="mailto:<?php echo Security::escape($customer['email']); ?>">
                                        <?php echo Security::escape($customer['email']); ?>
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if ($customer['phone']): ?>
                            <div class="datagrid-item">
                                <div class="datagrid-title">Phone</div>
                                <div class="datagrid-content"><?php echo Security::escape($customer['phone']); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if ($customer['address']): ?>
                            <div class="datagrid-item">
                                <div class="datagrid-title">Address</div>
                                <div class="datagrid-content"><?php echo nl2br(Security::escape($customer['address'])); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if ($customer['city'] || $customer['country']): ?>
                            <div class="datagrid-item">
                                <div class="datagrid-title">Location</div>
                                <div class="datagrid-content">
                                    <?php echo Security::escape($customer['city'] ?? ''); ?>
                                    <?php echo ($customer['city'] && $customer['country']) ? ', ' : ''; ?>
                                    <?php echo Security::escape($customer['country'] ?? ''); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="datagrid-item">
                                <div class="datagrid-title">Customer Since</div>
                                <div class="datagrid-content"><?php echo date('M j, Y', strtotime($stats['first_application'])); ?></div>
                            </div>
                            <div class="datagrid-item">
                                <div class="datagrid-title">Last Activity</div>
                                <div class="datagrid-content"><?php echo date('M j, Y g:i A', strtotime($stats['last_activity'])); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Statistics Card -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h3 class="card-title">Statistics</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-6">
                                <div class="text-center">
                                    <div class="h1 mb-0 text-blue"><?php echo $stats['total_applications']; ?></div>
                                    <div class="text-muted">Total Applications</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center">
                                    <div class="h1 mb-0 text-green"><?php echo $stats['active_certificates']; ?></div>
                                    <div class="text-muted">Active Certificates</div>
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="text-muted">Under Review</div>
                                <div class="h3 mb-0 text-yellow"><?php echo $stats['under_review_applications']; ?></div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted">Approved</div>
                                <div class="h3 mb-0 text-green"><?php echo $stats['approved_applications']; ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Expiring Certificates Alert -->
                <?php if (!empty($expiringCertificates)): ?>
                <div class="card mt-3">
                    <div class="card-header">
                        <h3 class="card-title text-warning">
                            <i class="ti ti-alert-triangle me-1"></i>
                            Expiring Soon
                        </h3>
                    </div>
                    <div class="card-body">
                        <?php foreach ($expiringCertificates as $cert): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <strong><?php echo Security::escape($cert['standard_name']); ?></strong><br>
                                    <small class="text-muted">#<?php echo Security::escape($cert['certificate_number']); ?></small>
                                </div>
                                <div class="text-end">
                                    <div class="text-warning">
                                        <?php 
                                        $daysLeft = ceil((strtotime($cert['expires_at']) - time()) / (60 * 60 * 24));
                                        echo $daysLeft; ?> days
                                    </div>
                                    <small class="text-muted"><?php echo date('M j, Y', strtotime($cert['expires_at'])); ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Applications and Certificates -->
            <div class="col-lg-8">
                <!-- Active Certificates -->
                <?php if (!empty($activeCertificates)): ?>
                <div class="card mb-3">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="ti ti-award me-2"></i>
                            Active Certificates (<?php echo count($activeCertificates); ?>)
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="row row-cards">
                            <?php foreach ($activeCertificates as $cert): ?>
                                <div class="col-md-6">
                                    <div class="card card-sm">
                                        <div class="card-body">
                                            <div class="d-flex align-items-center">
                                                <div class="me-3">
                                                    <i class="ti ti-certificate text-green" style="font-size: 2rem;"></i>
                                                </div>
                                                <div class="flex-fill">
                                                    <div class="font-weight-medium"><?php echo Security::escape($cert['standard_name']); ?></div>
                                                    <div class="text-muted">#<?php echo Security::escape($cert['certificate_number']); ?></div>
                                                    <div class="mt-1">
                                                        <span class="badge bg-green">Active</span>
                                                        <small class="text-muted ms-2">
                                                            Expires: <?php echo date('M j, Y', strtotime($cert['expires_at'])); ?>
                                                        </small>
                                                    </div>
                                                </div>
                                                <div class="ms-auto">
                                                    <a href="/certificates/view/<?php echo $cert['id']; ?>" class="btn btn-sm btn-primary">
                                                        View
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Applications History -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="ti ti-file-text me-2"></i>
                            Applications History (<?php echo count($applications); ?>)
                        </h3>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($applications)): ?>
                            <div class="p-4 text-center text-muted">
                                No applications found
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table card-table table-vcenter">
                                    <thead>
                                        <tr>
                                            <th>Standard</th>
                                            <th>Status</th>
                                            <th>Certificate</th>
                                            <th>Applied</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($applications as $app): ?>
                                            <tr>
                                                <td>
                                                    <div>
                                                        <strong><?php echo Security::escape($app['standard_name']); ?></strong>
                                                        <div class="small text-muted"><?php echo Security::escape($app['standard_type']); ?></div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php 
                                                        echo match($app['status']) {
                                                            'draft' => 'secondary',
                                                            'submitted' => 'blue',
                                                            'under_review' => 'yellow',
                                                            'approved' => 'green',
                                                            'rejected' => 'red',
                                                            'issued' => 'purple',
                                                            default => 'secondary'
                                                        };
                                                    ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $app['status'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($app['certificate_number']): ?>
                                                        <div>
                                                            <strong>#<?php echo Security::escape($app['certificate_number']); ?></strong>
                                                            <div class="small">
                                                                <span class="badge badge-<?php echo $app['certificate_status'] === 'active' ? 'green' : 'gray'; ?> badge-pill">
                                                                    <?php echo ucfirst($app['certificate_status']); ?>
                                                                </span>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="text-muted">
                                                        <?php echo date('M j, Y', strtotime($app['created_at'])); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="btn-list">
                                                        <a href="/applications/view/<?php echo $app['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="ti ti-eye"></i>
                                                            View
                                                        </a>
                                                        <?php if (in_array($app['status'], ['submitted', 'under_review'])): ?>
                                                            <a href="/applications/review/<?php echo $app['id']; ?>" class="btn btn-sm btn-primary">
                                                                <i class="ti ti-clipboard-check"></i>
                                                                Review
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Include footer
include INCLUDES_PATH . 'footer.php';
?>