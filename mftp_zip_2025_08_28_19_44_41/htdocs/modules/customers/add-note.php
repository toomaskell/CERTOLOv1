<?php
/**
 * CERTOLO - Add Customer Note (Working Version)
 * Handle adding internal notes about customers using direct queries
 */

// Set JSON response header
header('Content-Type: application/json');

// Security check - only certifiers can access this endpoint
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'certifier') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get and validate inputs
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
?>