<?php
/**
 * CERTOLO - Customer Details View with Inline Note Handling
 * Handles note submission directly in this file to avoid routing issues
 */

// Handle note submission if POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_note') {
    header('Content-Type: application/json');
    
    try {
        // Validate inputs
        $customerId = filter_var($_POST['customer_id'] ?? null, FILTER_VALIDATE_INT);
        $note = trim($_POST['note'] ?? '');
        $priority = $_POST['priority'] ?? 'normal';
        $certifierId = $_SESSION['user_id'];
        
        if (!$customerId || empty($note)) {
            throw new Exception('Customer ID and note are required');
        }
        
        if (!in_array($priority, ['low', 'normal', 'high'])) {
            $priority = 'normal';
        }
        
        // Sanitize inputs for direct query
        $noteClean = addslashes($note);
        $priorityClean = addslashes($priority);
        
        require_once 'config/database.php';
        $db = Database::getInstance();
        
        // Verify the customer exists and belongs to this certifier
        $customerCheckSQL = "SELECT u.id 
                             FROM users u
                             WHERE u.id = $customerId 
                             AND u.role = 'applicant'
                             AND EXISTS (
                                 SELECT 1 FROM applications a 
                                 WHERE a.applicant_id = u.id 
                                 AND a.certifier_id = $certifierId
                             )";
        
        $customerCheck = $db->query($customerCheckSQL);
        if (!$customerCheck->fetch()) {
            throw new Exception('Customer not found or you do not have access to this customer');
        }
        
        // Create customer_notes table if it doesn't exist
        $createTableSQL = "
            CREATE TABLE IF NOT EXISTS customer_notes (
                id INT PRIMARY KEY AUTO_INCREMENT,
                customer_id INT NOT NULL,
                certifier_id INT NOT NULL,
                note TEXT NOT NULL,
                priority ENUM('low', 'normal', 'high') DEFAULT 'normal',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (certifier_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX (customer_id, certifier_id),
                INDEX (priority, created_at)
            )
        ";
        
        $db->query($createTableSQL);
        
        // Insert the note using direct query
        $insertSQL = "INSERT INTO customer_notes (customer_id, certifier_id, note, priority) 
                      VALUES ($customerId, $certifierId, '$noteClean', '$priorityClean')";
        
        $result = $db->query($insertSQL);
        
        if ($result) {
            echo json_encode([
                'success' => true, 
                'message' => 'Note added successfully',
                'note_id' => $db->lastInsertId()
            ]);
        } else {
            throw new Exception('Failed to save note to database');
        }
        
    } catch (Exception $e) {
        error_log("Add customer note error: " . $e->getMessage());
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage()
        ]);
    }
    
    exit; // Stop processing after handling the note submission
}

// Security check - only certifiers can access this page
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'certifier') {
    echo "<script>window.location.href='/login';</script>";
    exit;
}

// Get customer ID from URL
$customerId = $id ?? $_GET['id'] ?? null;

if (!$customerId || !is_numeric($customerId)) {
    echo "<script>window.location.href='/customers';</script>";
    exit;
}

$userId = $_SESSION['user_id'];
$pageTitle = "Customer Details";

try {
    require_once 'config/database.php';
    $db = Database::getInstance();
    
    // Get customer basic information using direct query
    $customerSQL = "SELECT u.*, 
                           DATE(u.created_at) as registration_date
                    FROM users u 
                    WHERE u.id = $customerId 
                    AND u.role = 'applicant'
                    AND EXISTS (
                        SELECT 1 FROM applications 
                        WHERE applicant_id = u.id 
                        AND certifier_id = $userId
                    )";
    
    $customerStmt = $db->query($customerSQL);
    $customer = $customerStmt->fetch();
    
    if (!$customer) {
        echo "<script>alert('Customer not found or you do not have access to this customer.'); window.location.href='/customers';</script>";
        exit;
    }
    
    // Get applications overview
    $applicationsSQL = "SELECT a.*, s.name as standard_name, s.type as standard_type,
                               DATE(a.created_at) as application_date,
                               DATE(a.submitted_at) as submitted_date,
                               DATE(a.reviewed_at) as reviewed_date
                        FROM applications a
                        JOIN standards s ON a.standard_id = s.id
                        WHERE a.applicant_id = $customerId 
                        AND a.certifier_id = $userId
                        ORDER BY a.created_at DESC
                        LIMIT 10";
    
    $applicationsStmt = $db->query($applicationsSQL);
    $applications = $applicationsStmt->fetchAll();
    
    // Get application status statistics
    $statusStatsSQL = "SELECT status, COUNT(*) as count
                       FROM applications 
                       WHERE applicant_id = $customerId 
                       AND certifier_id = $userId
                       GROUP BY status";
    
    $statusStatsStmt = $db->query($statusStatsSQL);
    $statusStats = [];
    while ($row = $statusStatsStmt->fetch()) {
        $statusStats[$row['status']] = $row['count'];
    }
    
    // Get certificates
    $certificatesSQL = "SELECT c.*, s.name as standard_name, s.type as standard_type,
                               DATE(c.issued_at) as issued_date,
                               DATE(c.expires_at) as expiry_date,
                               DATEDIFF(c.expires_at, NOW()) as days_until_expiry
                        FROM certificates c
                        JOIN applications a ON c.application_id = a.id
                        JOIN standards s ON a.standard_id = s.id
                        WHERE a.applicant_id = $customerId 
                        AND a.certifier_id = $userId
                        ORDER BY c.issued_at DESC";
    
    $certificatesStmt = $db->query($certificatesSQL);
    $certificates = $certificatesStmt->fetchAll();
    
    // Separate active and expired certificates
    $activeCertificates = [];
    $expiredCertificates = [];
    $expiringCertificates = [];
    
    foreach ($certificates as $cert) {
        if ($cert['status'] === 'active' && $cert['days_until_expiry'] > 0) {
            $activeCertificates[] = $cert;
            if ($cert['days_until_expiry'] <= 30) {
                $expiringCertificates[] = $cert;
            }
        } elseif ($cert['status'] === 'expired' || $cert['days_until_expiry'] <= 0) {
            $expiredCertificates[] = $cert;
        }
    }
    
    // Get recent communications (application discussions) 
    $communicationsSQL = "SELECT cd.*, a.id as application_id, s.name as standard_name,
                                 u.contact_person, u.role as user_role,
                                 DATE(cd.created_at) as message_date
                          FROM criteria_discussions cd
                          JOIN applications a ON cd.application_id = a.id
                          JOIN standards s ON a.standard_id = s.id
                          JOIN users u ON cd.user_id = u.id
                          WHERE a.applicant_id = $customerId 
                          AND a.certifier_id = $userId
                          ORDER BY cd.created_at DESC
                          LIMIT 10";
    
    $communicationsStmt = $db->query($communicationsSQL);
    $communications = $communicationsStmt->fetchAll();
    
    // Get customer notes (with error handling for missing table)
    $customerNotes = [];
    try {
        // Check if customer_notes table exists
        $tableExistsSQL = "SHOW TABLES LIKE 'customer_notes'";
        $tableCheck = $db->query($tableExistsSQL);
        
        if ($tableCheck->fetch()) {
            // Table exists, get notes
            $notesSQL = "SELECT cn.*, u.contact_person as author_name
                         FROM customer_notes cn
                         LEFT JOIN users u ON cn.certifier_id = u.id
                         WHERE cn.customer_id = $customerId 
                         AND cn.certifier_id = $userId
                         ORDER BY cn.created_at DESC
                         LIMIT 10";
            
            $notesStmt = $db->query($notesSQL);
            $customerNotes = $notesStmt->fetchAll();
        }
        // If table doesn't exist, customerNotes remains empty array
    } catch (Exception $notesError) {
        // Table doesn't exist or other error - just continue with empty notes
        error_log("Customer notes query error (expected if table doesn't exist): " . $notesError->getMessage());
        $customerNotes = [];
    }
    
    // Calculate performance metrics
    $totalApps = array_sum($statusStats);
    $approvedApps = $statusStats['approved'] ?? 0;
    $rejectedApps = $statusStats['rejected'] ?? 0;
    $approvalRate = $totalApps > 0 ? round(($approvedApps / $totalApps) * 100, 1) : 0;
    
    // Calculate average processing time
    $avgProcessingSQL = "SELECT AVG(DATEDIFF(reviewed_at, submitted_at)) as avg_days
                         FROM applications
                         WHERE applicant_id = $customerId 
                         AND certifier_id = $userId
                         AND status IN ('approved', 'rejected') 
                         AND submitted_at IS NOT NULL 
                         AND reviewed_at IS NOT NULL";
    
    $avgProcessingStmt = $db->query($avgProcessingSQL);
    $avgProcessing = $avgProcessingStmt->fetch();
    $averageProcessingDays = $avgProcessing['avg_days'] ? round($avgProcessing['avg_days'], 1) : 0;

} catch (Exception $e) {
    error_log("Customer details error: " . $e->getMessage());
    $errorMessage = "An error occurred while loading customer details: " . $e->getMessage();
}

// Helper function for status badges
function getStatusBadge($status) {
    $badges = [
        'draft' => 'bg-secondary',
        'submitted' => 'bg-info',
        'under_review' => 'bg-warning',
        'approved' => 'bg-success',
        'rejected' => 'bg-danger',
        'issued' => 'bg-primary'
    ];
    
    $class = $badges[$status] ?? 'bg-secondary';
    return "<span class='badge {$class}'>" . ucfirst(str_replace('_', ' ', $status)) . "</span>";
}

// Include header
if (file_exists('includes/header.php')) {
    include 'includes/header.php';
} else {
    ?>
    <!DOCTYPE html>
    <html><head><title>CERTOLO - Customer Details</title>
    <link href="https://cdn.jsdelivr.net/npm/@tabler/core@latest/dist/css/tabler.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@tabler/icons@latest/iconfont/tabler-icons.min.css" rel="stylesheet">
    </head><body>
    <?php
}
?>

<div class="page-wrapper">
    <div class="page-header d-print-none">
        <div class="container-xl">
            <div class="row g-2 align-items-center">
                <div class="col">
                    <div class="page-pretitle">
                        Customer Management
                    </div>
                    <h2 class="page-title">
                        <i class="ti ti-building me-2"></i>
                        <?php echo htmlspecialchars($customer['company_name']); ?>
                    </h2>
                </div>
                <div class="col-auto ms-auto d-print-none">
                    <div class="btn-list">
                        <a href="/customers" class="btn btn-outline-secondary">
                            <i class="ti ti-arrow-left"></i> Back to Customers
                        </a>
                        <button class="btn btn-primary" onclick="sendEmail('<?php echo htmlspecialchars($customer['email']); ?>')">
                            <i class="ti ti-mail"></i> Send Email
                        </button>
                        <button class="btn btn-success" onclick="addNote(<?php echo $customerId; ?>)">
                            <i class="ti ti-note"></i> Add Note
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="page-body">
        <div class="container-xl">
            <?php if (isset($errorMessage)): ?>
                <div class="alert alert-danger">
                    <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
                </div>
            <?php endif; ?>

            <div class="row row-deck row-cards">
                <!-- Company Information Card -->
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="ti ti-building me-2"></i>Company Information
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="datagrid">
                                <div class="datagrid-item">
                                    <div class="datagrid-title">Company Name</div>
                                    <div class="datagrid-content"><?php echo htmlspecialchars($customer['company_name']); ?></div>
                                </div>
                                <div class="datagrid-item">
                                    <div class="datagrid-title">Contact Person</div>
                                    <div class="datagrid-content"><?php echo htmlspecialchars($customer['contact_person']); ?></div>
                                </div>
                                <div class="datagrid-item">
                                    <div class="datagrid-title">Email</div>
                                    <div class="datagrid-content">
                                        <a href="mailto:<?php echo htmlspecialchars($customer['email']); ?>">
                                            <?php echo htmlspecialchars($customer['email']); ?>
                                        </a>
                                    </div>
                                </div>
                                <div class="datagrid-item">
                                    <div class="datagrid-title">Phone</div>
                                    <div class="datagrid-content"><?php echo htmlspecialchars($customer['phone'] ?? 'Not provided'); ?></div>
                                </div>
                                <div class="datagrid-item">
                                    <div class="datagrid-title">Address</div>
                                    <div class="datagrid-content"><?php echo htmlspecialchars($customer['address'] ?? 'Not provided'); ?></div>
                                </div>
                                <div class="datagrid-item">
                                    <div class="datagrid-title">City</div>
                                    <div class="datagrid-content"><?php echo htmlspecialchars($customer['city'] ?? 'Not provided'); ?></div>
                                </div>
                                <div class="datagrid-item">
                                    <div class="datagrid-title">Country</div>
                                    <div class="datagrid-content"><?php echo htmlspecialchars($customer['country'] ?? 'Not provided'); ?></div>
                                </div>
                                <div class="datagrid-item">
                                    <div class="datagrid-title">Registration Date</div>
                                    <div class="datagrid-content"><?php echo htmlspecialchars($customer['registration_date']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Performance Analytics -->
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="ti ti-chart-bar me-2"></i>Performance Analytics
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-sm-6 col-lg-3">
                                    <div class="card card-sm">
                                        <div class="card-body">
                                            <div class="row align-items-center">
                                                <div class="col-auto">
                                                    <span class="bg-primary text-white avatar">
                                                        <i class="ti ti-file-text"></i>
                                                    </span>
                                                </div>
                                                <div class="col">
                                                    <div class="font-weight-medium">
                                                        <?php echo $totalApps; ?> Applications
                                                    </div>
                                                    <div class="text-secondary">
                                                        Total Submitted
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6 col-lg-3">
                                    <div class="card card-sm">
                                        <div class="card-body">
                                            <div class="row align-items-center">
                                                <div class="col-auto">
                                                    <span class="bg-success text-white avatar">
                                                        <i class="ti ti-check"></i>
                                                    </span>
                                                </div>
                                                <div class="col">
                                                    <div class="font-weight-medium">
                                                        <?php echo $approvalRate; ?>% Success Rate
                                                    </div>
                                                    <div class="text-secondary">
                                                        Approval Rate
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6 col-lg-3">
                                    <div class="card card-sm">
                                        <div class="card-body">
                                            <div class="row align-items-center">
                                                <div class="col-auto">
                                                    <span class="bg-info text-white avatar">
                                                        <i class="ti ti-clock"></i>
                                                    </span>
                                                </div>
                                                <div class="col">
                                                    <div class="font-weight-medium">
                                                        <?php echo $averageProcessingDays; ?> Days
                                                    </div>
                                                    <div class="text-secondary">
                                                        Avg. Processing
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6 col-lg-3">
                                    <div class="card card-sm">
                                        <div class="card-body">
                                            <div class="row align-items-center">
                                                <div class="col-auto">
                                                    <span class="bg-warning text-white avatar">
                                                        <i class="ti ti-award"></i>
                                                    </span>
                                                </div>
                                                <div class="col">
                                                    <div class="font-weight-medium">
                                                        <?php echo count($activeCertificates); ?> Active
                                                    </div>
                                                    <div class="text-secondary">
                                                        Certificates
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Applications Overview -->
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="ti ti-file-text me-2"></i>Applications Overview
                            </h3>
                            <div class="card-actions">
                                <a href="/applications?customer=<?php echo $customerId; ?>" class="btn btn-outline-primary btn-sm">
                                    View All
                                </a>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($applications)): ?>
                                <div class="empty">
                                    <div class="empty-icon">
                                        <i class="ti ti-file-x"></i>
                                    </div>
                                    <p class="empty-title">No applications found</p>
                                    <p class="empty-subtitle text-secondary">
                                        This customer has not submitted any applications yet.
                                    </p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-vcenter card-table">
                                        <thead>
                                            <tr>
                                                <th>Standard</th>
                                                <th>Status</th>
                                                <th>Submitted</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($applications as $app): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($app['standard_name']); ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($app['standard_type']); ?></small>
                                                </td>
                                                <td><?php echo getStatusBadge($app['status']); ?></td>
                                                <td>
                                                    <?php echo $app['submitted_date'] ? htmlspecialchars($app['submitted_date']) : 'Not submitted'; ?>
                                                </td>
                                                <td>
                                                    <a href="/applications/view/<?php echo $app['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                        View
                                                    </a>
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

                <!-- Internal Notes -->
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="ti ti-note me-2"></i>Internal Notes
                            </h3>
                            <div class="card-actions">
                                <button class="btn btn-sm btn-outline-primary" onclick="addNote(<?php echo $customerId; ?>)">
                                    <i class="ti ti-plus"></i> Add Note
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($customerNotes)): ?>
                                <div class="empty">
                                    <div class="empty-icon">
                                        <i class="ti ti-note-off"></i>
                                    </div>
                                    <p class="empty-title">No notes</p>
                                    <p class="empty-subtitle text-secondary">
                                        Add internal notes about this customer.
                                    </p>
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($customerNotes as $note): ?>
                                    <div class="list-group-item">
                                        <div class="row align-items-start">
                                            <div class="col-auto">
                                                <?php
                                                $priorityColors = [
                                                    'low' => 'bg-secondary',
                                                    'normal' => 'bg-info', 
                                                    'high' => 'bg-warning'
                                                ];
                                                $priorityColor = $priorityColors[$note['priority']] ?? 'bg-secondary';
                                                ?>
                                                <span class="avatar avatar-sm <?php echo $priorityColor; ?>">
                                                    <i class="ti ti-note"></i>
                                                </span>
                                            </div>
                                            <div class="col">
                                                <div class="text-body">
                                                    <?php echo htmlspecialchars($note['note']); ?>
                                                </div>
                                                <div class="text-secondary mt-1">
                                                    <small>
                                                        <?php echo htmlspecialchars($note['author_name']); ?> • 
                                                        <?php echo date('M j, Y', strtotime($note['created_at'])); ?> • 
                                                        <span class="badge badge-sm bg-<?php echo $note['priority'] === 'high' ? 'warning' : ($note['priority'] === 'low' ? 'secondary' : 'info'); ?>">
                                                            <?php echo ucfirst($note['priority']); ?>
                                                        </span>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Active Certificates -->
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="ti ti-award me-2"></i>Active Certificates
                            </h3>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($activeCertificates)): ?>
                                <div class="empty">
                                    <div class="empty-icon">
                                        <i class="ti ti-award-off"></i>
                                    </div>
                                    <p class="empty-title">No active certificates</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-vcenter card-table">
                                        <thead>
                                            <tr>
                                                <th>Certificate</th>
                                                <th>Issued</th>
                                                <th>Expires</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($activeCertificates as $cert): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($cert['standard_name']); ?></strong>
                                                    <br>
                                                    <small class="text-secondary"><?php echo htmlspecialchars($cert['certificate_number']); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($cert['issued_date']); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($cert['expiry_date']); ?>
                                                    <?php if ($cert['days_until_expiry'] <= 30): ?>
                                                        <br><small class="text-warning">Expires soon!</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="/certificates/view/<?php echo $cert['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                        View
                                                    </a>
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

                <!-- Expired Certificates -->
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="ti ti-clock-off me-2"></i>Expired Certificates
                            </h3>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($expiredCertificates)): ?>
                                <div class="empty">
                                    <div class="empty-icon">
                                        <i class="ti ti-check"></i>
                                    </div>
                                    <p class="empty-title">No expired certificates</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-vcenter card-table">
                                        <thead>
                                            <tr>
                                                <th>Certificate</th>
                                                <th>Issued</th>
                                                <th>Expired</th>
                                                <th>Actions</th>
                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($expiredCertificates as $cert): ?>
                                            <tr class="text-secondary">
                                                <td>
                                                    <?php echo htmlspecialchars($cert['standard_name']); ?>
                                                    <br>
                                                    <small><?php echo htmlspecialchars($cert['certificate_number']); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($cert['issued_date']); ?></td>
                                                <td><?php echo htmlspecialchars($cert['expiry_date']); ?></td>
                                                <td>
                                                    <a href="/certificates/view/<?php echo $cert['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                                        View
                                                    </a>
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
</div>

<!-- Simple CSS-only Modal -->
<div id="addNoteModal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <form id="addNoteForm">
            <div class="modal-header">
                <h5>Add Internal Note</h5>
                <button type="button" class="btn-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="add_note">
                <input type="hidden" name="customer_id" value="<?php echo $customerId; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo bin2hex(random_bytes(32)); ?>">
                
                <div class="mb-3">
                    <label class="form-label">Note</label>
                    <textarea name="note" class="form-control" rows="4" placeholder="Add internal note about this customer..." required></textarea>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Priority</label>
                    <select name="priority" class="form-select">
                        <option value="low">Low</option>
                        <option value="normal" selected>Normal</option>
                        <option value="high">High</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Note</button>
            </div>
        </form>
    </div>
</div>

<script>
// Email functionality
function sendEmail(customerEmail) {
    const subject = encodeURIComponent('Regarding your certification with <?php echo htmlspecialchars($_SESSION['company_name'] ?? 'us'); ?>');
    const mailtoLink = `mailto:${customerEmail}?subject=${subject}`;
    window.location.href = mailtoLink;
}

// Simple modal functions (no Bootstrap required)
function addNote(customerId) {
    console.log('addNote called with customerId:', customerId);
    document.getElementById('addNoteModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('addNoteModal').style.display = 'none';
    // Clear form
    document.getElementById('addNoteForm').reset();
}

// Handle note form submission - INLINE VERSION (no routing issues)
document.getElementById('addNoteForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    
    // Show loading state
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '⏳ Saving...';
    submitBtn.disabled = true;
    
    console.log('=== INLINE NOTE SUBMISSION ===');
    console.log('Submitting to same page (inline processing)');
    
    // Submit to the same page (this view.php file handles it)
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status);
        console.log('Response headers:', [...response.headers.entries()]);
        
        // Get content-type
        const contentType = response.headers.get('content-type');
        console.log('Content-Type:', contentType);
        
        if (contentType && contentType.includes('application/json')) {
            return response.json();
        } else {
            // If not JSON, get text to see what we're receiving
            return response.text().then(text => {
                console.log('Non-JSON response:', text.substring(0, 500));
                throw new Error('Expected JSON response but got: ' + contentType);
            });
        }
    })
    .then(data => {
        console.log('Parsed JSON data:', data);
        
        if (data.success) {
            closeModal();
            alert('Note added successfully!');
            location.reload(); // Refresh to show the new note
        } else {
            alert('Error adding note: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred: ' + error.message);
    })
    .finally(() => {
        // Reset button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
});

// Close modal when clicking outside
document.getElementById('addNoteModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});
</script>

<style>
.datagrid-item {
    border-bottom: 1px solid rgba(98, 105, 118, 0.16);
    padding: 0.75rem 0;
}

.datagrid-item:last-child {
    border-bottom: none;
}

.avatar {
    width: 2.25rem;
    height: 2.25rem;
}

/* Simple CSS Modal (no Bootstrap required) */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1050;
}

.modal-content {
    background: white;
    border-radius: 8px;
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
}

.modal-header {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h5 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid #dee2e6;
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
}

.btn-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: #6c757d;
    padding: 0;
    width: 1.5rem;
    height: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

.btn-close:hover {
    color: #000;
}

@media print {
    .page-header, .btn, .card-actions {
        display: none !important;
    }
    
    .card {
        box-shadow: none;
        border: 1px solid #ddd;
    }
}
</style>

<?php
if (file_exists('includes/footer.php')) {
    include 'includes/footer.php';
} else {
    echo "</body></html>";
}
?>