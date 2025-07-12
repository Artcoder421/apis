<?php
header('Content-Type: application/json');

// Database configuration
$host = 'localhost';
$db = 'car_rental';
$user = 'root'; // adjust as needed
$pass = '';     // adjust if your DB has a password
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
if (!isset($_POST['booking_id']) || empty($_POST['booking_id'])) {
    echo json_encode([
        "success" => false,
        "error" => "Missing booking_id"
    ]);
    exit;
}

$booking_id = $conn->real_escape_string($_POST['booking_id']);

// Check if booking exists and can be cancelled
$checkBooking = $conn->query("
    SELECT status, pickup_datetime 
    FROM bookings 
    WHERE id = '$booking_id'
");

if ($checkBooking->num_rows == 0) {
    echo json_encode([
        "success" => false,
        "error" => "Booking not found"
    ]);
    exit;
}

$booking = $checkBooking->fetch_assoc();
$status = strtolower($booking['status']);
$pickupDate = new DateTime($booking['pickup_datetime']);
$currentDate = new DateTime();

// Validate booking can be cancelled
if ($status === 'cancelled') {
    echo json_encode([
        "success" => false,
        "error" => "Booking is already cancelled"
    ]);
    exit;
}

if ($status === 'paid') {
    echo json_encode([
        "success" => false,
        "error" => "Paid bookings cannot be cancelled"
    ]);
    exit;
}

if ($pickupDate < $currentDate) {
    echo json_encode([
        "success" => false,
        "error" => "Cannot cancel past bookings"
    ]);
    exit;
}

// Update booking status to cancelled
$updateBooking = $conn->query("
    UPDATE bookings 
    SET status = 'cancelled' 
    WHERE id = '$booking_id'
");

if (!$updateBooking) {
    echo json_encode([
        "success" => false,
        "error" => "Failed to cancel booking: " . $conn->error
    ]);
    exit;
}

// If there was a payment, refund it (in a real system, you would initiate a refund process)
$checkPayment = $conn->query("
    SELECT id 
    FROM payments 
    WHERE booking_id = '$booking_id' 
    AND payment_status = 'completed'
");

if ($checkPayment->num_rows > 0) {
    $conn->query("
        UPDATE payments 
        SET payment_status = 'refunded' 
        WHERE booking_id = '$booking_id'
    ");
}

echo json_encode([
    "success" => true,
    "message" => "Booking cancelled successfully"
]);

$conn->close();
?>