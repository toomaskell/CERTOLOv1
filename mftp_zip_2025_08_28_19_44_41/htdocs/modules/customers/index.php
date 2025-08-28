<?php
/**
 * CERTOLO - Customers Module (Fixed Routing)
 * This version properly handles the action=view parameter
 */

ob_start();

// Auth check
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'certifier') {
    echo "<script>window.location.href='/login';</script>";
    exit;
}

$userId = $_SESSION['user_id'];

// CRITICAL: Check for customer view FIRST before any other processing
$action = $_GET['action'] ?? '';
$customerId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

// If requesting customer view, handle it immediately
if ($action === 'view' && $customerId) {
    // Set the ID for view.php to access
    $id = $customerId;
    
    // Try to include the view.php file
    $viewPath = __DIR__ . '/view.php';
    
    if (file_exists($viewPath)) {
        // Include the customer view page
        include $viewPath;
        exit; // IMPORTANT: Stop processing after showing the view
    } else {
        // Fallback: Show error if view.php doesn't exist
        include 'includes/header.php';
        echo "<div class='alert alert-danger'>";
        echo "<h4>Error: Customer view file not found</h4>";
        echo "<p>Expected location: " . $viewPath . "</p>";
        echo "<p><a href='/customers' class='btn btn-primary'>Back to Customers List</a></p>";
        echo "</div>";
        include 'includes/footer.php';
        exit;
    }
}

// Continue with normal customer list if not viewing specific customer
$search = $_GET['search'] ?? '';

// Include header
if (file_exists('includes/header.php')) {
    include 'includes/header.php';
} else {
    ?>
    <!DOCTYPE html>
    <html><head><title>CERTOLO - Customers</title>
    <link href="https://cdn.jsdelivr.net/npm/@tabler/core@latest/dist/css/tabler.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@tabler/icons@latest/iconfont/tabler-icons.min.css" rel="stylesheet">
    </head><body>
    <?php
}
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <h2 class="page-title">
                    <i class="ti ti-users me-2"></i>
                    Customers
                </h2>
                <div class="text-muted mt-1">
                    Your certification customers and their applications
                </div>
            </div>
            <div class="col-auto ms-auto d-print-none">
                <div class="btn-list">
                    <button class="btn btn-outline-primary" onclick="exportCustomers()">
                        <i class="ti ti-download"></i> Export
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        
        <!-- Search -->
        <div class="row mb-4">
            <div class="col-md-8">
                <form method="GET" class="d-flex gap-2">
                    <div class="input-icon flex-fill">
                        <span class="input-icon-addon">
                            <i class="ti ti-search"></i>
                        </span>
                        <input type="text" 
                               name="search" 
                               value="<?php echo htmlspecialchars($search); ?>" 
                               class="form-control" 
                               placeholder="Search by company name, contact person, or email...">
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-search"></i> Search
                    </button>
                    <?php if ($search): ?>
                        <a href="/customers" class="btn btn-outline-secondary">
                            <i class="ti ti-x"></i> Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="col-md-4 text-end">
                <small class="text-muted">
                    Logged in as: <strong><?php echo htmlspecialchars($_SESSION['company_name'] ?? 'User'); ?></strong>
                </small>
            </div>
        </div>

        <?php
        // Database operations (using direct queries)
        try {
            require_once 'config/database.php';
            $db = Database::getInstance();
            
            // Build customer query
            if ($search) {
                $searchClean = addslashes($search);
                $sql = "SELECT DISTINCT u.id, u.company_name, u.contact_person, u.email, u.phone, u.city, u.country, u.created_at
                        FROM users u 
                        INNER JOIN applications a ON u.id = a.applicant_id 
                        WHERE a.certifier_id = $userId 
                        AND (u.company_name LIKE '%$searchClean%' 
                             OR u.contact_person LIKE '%$searchClean%' 
                             OR u.email LIKE '%$searchClean%')
                        ORDER BY u.company_name ASC";
            } else {
                $sql = "SELECT DISTINCT u.id, u.company_name, u.contact_person, u.email, u.phone, u.city, u.country, u.created_at
                        FROM users u 
                        INNER JOIN applications a ON u.id = a.applicant_id 
                        WHERE a.certifier_id = $userId 
                        ORDER BY u.company_name ASC";
            }
            
            $stmt = $db->query($sql);
            $customers = $stmt->fetchAll();
            
            // Calculate summary statistics
            $totalCustomers = count($customers);
            $activeCustomers = 0;
            $pendingActions = 0;
            
            foreach ($customers as $customer) {
                // Check if customer has active certificates
                $certCheck = $db->query("SELECT COUNT(*) as count 
                                        FROM certificates c 
                                        JOIN applications a ON c.application_id = a.id 
                                        WHERE a.applicant_id = {$customer['id']} 
                                        AND a.certifier_id = $userId 
                                        AND c.status = 'active'");
                if ($certCheck->fetchColumn() > 0) {
                    $activeCustomers++;
                }
                
                // Count pending applications
                $pendingCheck = $db->query("SELECT COUNT(*) as count 
                                           FROM applications 
                                           WHERE applicant_id = {$customer['id']} 
                                           AND certifier_id = $userId 
                                           AND status = 'submitted'");
                $pendingActions += $pendingCheck->fetchColumn();
            }
            ?>
            
            <!-- Statistics Row -->
            <div class="row row-deck row-cards mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-primary text-white avatar">
                                        <i class="ti ti-users"></i>
                                    </span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">
                                        <?php echo $totalCustomers; ?>
                                    </div>
                                    <div class="text-secondary">
                                        Total Customers
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
                                        <?php echo $activeCustomers; ?>
                                    </div>
                                    <div class="text-secondary">
                                        Active Customers
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
                                        <i class="ti ti-clock"></i>
                                    </span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">
                                        <?php echo $pendingActions; ?>
                                    </div>
                                    <div class="text-secondary">
                                        Pending Applications
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
                                        <i class="ti ti-trending-up"></i>
                                    </span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">
                                        <?php echo $totalCustomers > 0 ? round(($activeCustomers / $totalCustomers) * 100, 1) : 0; ?>%
                                    </div>
                                    <div class="text-secondary">
                                        Active Rate
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="ti ti-users me-2"></i>
                        <?php if ($search): ?>
                            Search Results (<?php echo count($customers); ?>)
                        <?php else: ?>
                            Your Customers (<?php echo count($customers); ?>)
                        <?php endif; ?>
                    </h3>
                </div>
                
                <?php if (empty($customers)): ?>
                    <div class="empty">
                        <div class="empty-icon">
                            <i class="ti ti-users-off"></i>
                        </div>
                        <p class="empty-title">No customers found</p>
                        <p class="empty-subtitle text-secondary">
                            <?php if ($search): ?>
                                No customers match "<?php echo htmlspecialchars($search); ?>".
                            <?php else: ?>
                                You haven't received any applications yet. Customers will appear here once they submit applications for your standards.
                            <?php endif; ?>
                        </p>
                        <div class="empty-action">
                            <?php if ($search): ?>
                                <a href="/customers" class="btn btn-primary">Show All Customers</a>
                            <?php else: ?>
                                <a href="/standards" class="btn btn-primary">
                                    <i class="ti ti-plus"></i> Create Standard
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card-body">
                        <div class="row row-cards">
                            <?php foreach ($customers as $customer): 
                                // Get application stats
                                $appStmt = $db->query("SELECT COUNT(*) as total, 
                                                     SUM(CASE WHEN status = 'issued' THEN 1 ELSE 0 END) as issued,
                                                     SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                                                     SUM(CASE WHEN status = 'under_review' THEN 1 ELSE 0 END) as reviewing,
                                                     MAX(updated_at) as last_activity
                                                     FROM applications 
                                                     WHERE applicant_id = {$customer['id']} AND certifier_id = $userId");
                                $stats = $appStmt->fetch();
                                
                                // Get active certificates count
                                $certStmt = $db->query("SELECT COUNT(*) as count 
                                                       FROM certificates c 
                                                       JOIN applications a ON c.application_id = a.id 
                                                       WHERE a.applicant_id = {$customer['id']} 
                                                       AND a.certifier_id = $userId 
                                                       AND c.status = 'active'");
                                $activeCerts = $certStmt->fetchColumn();
                                
                                // Get latest status
                                $statusStmt = $db->query("SELECT status FROM applications 
                                                         WHERE applicant_id = {$customer['id']} AND certifier_id = $userId 
                                                         ORDER BY updated_at DESC LIMIT 1");
                                $latestStatus = $statusStmt->fetchColumn();
                            ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="card card-sm customer-card">
                                        <div class="card-body">
                                            <!-- Customer Header -->
                                            <div class="d-flex align-items-center mb-3">
                                                <div class="avatar bg-primary text-white me-3">
                                                    <?php echo strtoupper(substr($customer['company_name'], 0, 1)); ?>
                                                </div>
                                                <div class="flex-fill">
                                                    <div class="fw-bold">
                                                        <?php echo htmlspecialchars($customer['company_name']); ?>
                                                    </div>
                                                    <div class="text-muted small">
                                                        <?php echo htmlspecialchars($customer['contact_person']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Statistics -->
                                            <div class="row mb-3">
                                                <div class="col-6">
                                                    <div class="text-muted small">Applications</div>
                                                    <div class="h3 mb-0 text-blue"><?php echo $stats['total']; ?></div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="text-muted small">Active Certificates</div>
                                                    <div class="h3 mb-0 text-green"><?php echo $activeCerts; ?></div>
                                                </div>
                                            </div>
                                            
                                            <!-- Status & Breakdown -->
                                            <div class="mb-3">
                                                <?php 
                                                $badgeClass = match($latestStatus) {
                                                    'draft' => 'secondary',
                                                    'submitted' => 'blue', 
                                                    'under_review' => 'warning',
                                                    'approved' => 'success',
                                                    'rejected' => 'danger',
                                                    'issued' => 'purple',
                                                    default => 'secondary'
                                                };
                                                ?>
                                                <span class="badge bg-<?php echo $badgeClass; ?>">
                                                    Latest: <?php echo ucfirst(str_replace('_', ' ', $latestStatus)); ?>
                                                </span>
                                                
                                                <div class="mt-2 small">
                                                    <?php if ($stats['issued'] > 0): ?>
                                                        <span class="text-success me-2">✓ <?php echo $stats['issued']; ?> issued</span>
                                                    <?php endif; ?>
                                                    <?php if ($stats['rejected'] > 0): ?>
                                                        <span class="text-danger me-2">✗ <?php echo $stats['rejected']; ?> rejected</span>
                                                    <?php endif; ?>
                                                    <?php if ($stats['reviewing'] > 0): ?>
                                                        <span class="text-warning">⏳ <?php echo $stats['reviewing']; ?> reviewing</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <!-- Contact Info -->
                                            <?php if ($customer['email'] || $customer['city']): ?>
                                            <div class="mb-3 small text-muted">
                                                <?php if ($customer['email']): ?>
                                                    <div><i class="ti ti-mail"></i> <?php echo htmlspecialchars($customer['email']); ?></div>
                                                <?php endif; ?>
                                                <?php if ($customer['city']): ?>
                                                    <div><i class="ti ti-map-pin"></i> 
                                                        <?php echo htmlspecialchars($customer['city']); ?>
                                                        <?php if ($customer['country']): ?>
                                                            , <?php echo htmlspecialchars($customer['country']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <!-- Last Activity -->
                                            <div class="text-muted small mb-3">
                                                Last activity: <?php echo $stats['last_activity'] ? date('M j, Y', strtotime($stats['last_activity'])) : 'N/A'; ?>
                                            </div>
                                            
                                            <!-- Actions: Single Working Button -->
                                            <div class="btn-list">
                                                <a href="/customers?action=view&id=<?php echo $customer['id']; ?>" 
                                                   class="btn btn-primary btn-sm flex-fill">
                                                    <i class="ti ti-eye"></i> View Details
                                                </a>
                                                <?php if ($customer['email']): ?>
                                                <a href="mailto:<?php echo htmlspecialchars($customer['email']); ?>" 
                                                   class="btn btn-outline-secondary btn-sm">
                                                    <i class="ti ti-mail"></i>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
        <?php
        } catch (Exception $e) {
            ?>
            <div class="alert alert-danger">
                <strong>Database Error:</strong> <?php echo htmlspecialchars($e->getMessage()); ?>
            </div>
            <?php
        }
        ?>
    </div>
</div>

<script>
// Export customers function
function exportCustomers() {
    const customers = <?php echo json_encode($customers ?? []); ?>;
    
    if (customers.length === 0) {
        alert('No customers to export.');
        return;
    }
    
    let csv = 'Company Name,Contact Person,Email,Phone,City,Country,Registration Date\n';
    
    customers.forEach(customer => {
        const row = [
            customer.company_name || '',
            customer.contact_person || '',
            customer.email || '',
            customer.phone || '',
            customer.city || '',
            customer.country || '',
            customer.created_at || ''
        ].map(field => '"' + (field + '').replace(/"/g, '""') + '"').join(',');
        
        csv += row + '\n';
    });
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    
    const a = document.createElement('a');
    a.href = url;
    a.download = 'customers_' + new Date().toISOString().split('T')[0] + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}
</script>

<style>
.avatar {
    width: 2.5rem;
    height: 2.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    font-weight: 600;
}

.customer-card {
    transition: all 0.2s ease;
    border: 1px solid rgba(98, 105, 118, 0.16);
}

.customer-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    border-color: rgba(32, 107, 196, 0.3);
}

.bg-warning { background-color: #ffc107 !important; color: #000 !important; }
.bg-purple { background-color: #6f42c1 !important; }
.bg-success { background-color: #198754 !important; }
.bg-danger { background-color: #dc3545 !important; }

.text-success { color: #198754 !important; }
.text-danger { color: #dc3545 !important; }
.text-warning { color: #ffc107 !important; }

.btn-list {
    gap: 0.5rem;
}

@media (max-width: 768px) {
    .btn-list {
        flex-direction: column;
    }
    
    .btn-list .btn {
        width: 100%;
    }
}
</style>

<?php
if (file_exists('includes/footer.php')) {
    include 'includes/footer.php';
} else {
    echo "</body></html>";
}

ob_end_flush();
?>