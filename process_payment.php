<?php
// process_payment.php
header('Content-Type: application/json');

// Database configuration
$host = 'localhost';
$db = 'car_rental';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

// Connect to the database
$conn = new mysqli($host, $user, $pass, $db);

// Check connection
if ($conn->connect_error) {
    echo json_encode([
        "success" => false,
        "error" => "Connection failed: " . $conn->connect_error
    ]);
    exit;
}

// Validate required fields
$required = ['user_id', 'booking_id', 'amount', 'car_id'];
foreach ($required as $field) {
    if (!isset($_POST[$field]) || empty($_POST[$field])) {
        echo json_encode([
            "success" => false,
            "error" => "Missing required field: $field"
        ]);
        exit;
    }
}

$user_id = $conn->real_escape_string($_POST['user_id']);
$booking_id = $conn->real_escape_string($_POST['booking_id']);
$car_id = $conn->real_escape_string($_POST['car_id']);
$amount = $conn->real_escape_string($_POST['amount']);

// Check if booking exists and belongs to user
$checkBooking = $conn->query("
    SELECT status 
    FROM bookings 
    WHERE id = '$booking_id' 
    AND user_id = '$user_id'
");

if ($checkBooking->num_rows == 0) {
    echo json_encode([
        "success" => false,
        "error" => "Booking not found or doesn't belong to user"
    ]);
    exit;
}

$booking = $checkBooking->fetch_assoc();

// Check if payment already exists for this booking
$checkPayment = $conn->query("
    SELECT id 
    FROM payments 
    WHERE booking_id = '$booking_id'
");

if ($checkPayment->num_rows > 0) {
    echo json_encode([
        "success" => false,
        "error" => "Payment already exists for this booking"
    ]);
    exit;
}

// Generate a transaction ID
$transaction_id = 'PAY-' . time() . '-' . rand(1000, 9999);

// Insert payment record only
$insertPayment = $conn->query("
    INSERT INTO payments (
        user_id, 
        booking_id, 
        car_id, 
        amount, 
        payment_status, 
        transaction_id,
        payment_date
    ) VALUES (
        '$user_id',
        '$booking_id',
        '$car_id',
        '$amount',
        'completed',
        '$transaction_id',
        NOW()
    )
");

if (!$insertPayment) {
    echo json_encode([
        "success" => false,
        "error" => "Payment recording failed: " . $conn->error
    ]);
    exit;
}

// Get the inserted payment details
$paymentDetails = $conn->query("
    SELECT * 
    FROM payments 
    WHERE id = {$conn->insert_id}
")->fetch_assoc();

echo json_encode([
    "success" => true,
    "payment_id" => $transaction_id,
    "payment_details" => $paymentDetails,
    "message" => "Payment recorded successfully"
]);

$conn->close();
?>