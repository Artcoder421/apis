<?php
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
if (!isset($_POST['user_id']) || empty($_POST['user_id'])) {
    echo json_encode([
        "success" => false,
        "error" => "Missing user_id"
    ]);
    exit;
}

$user_id = $conn->real_escape_string($_POST['user_id']);

// Query to fetch bookings with payment info
$sql = "SELECT 
            b.id as booking_id,
            b.car_id,
            c.model as car_model,
            c.brand as car_brand,
            c.images as car_image_url,
            b.pickup_datetime,
            b.return_datetime,
            b.subscription_type,
            b.price,
            b.status,
            p.id as payment_id,
            p.payment_status,
            p.transaction_id,
            p.payment_date
        FROM bookings b
        JOIN cars c ON b.car_id = c.id
        LEFT JOIN payments p ON p.booking_id = b.id
        WHERE b.user_id = '$user_id'
        ORDER BY b.pickup_datetime DESC";

$result = $conn->query($sql);

if ($result === false) {
    echo json_encode([
        "success" => false, 
        "error" => "Query failed: " . $conn->error
    ]);
    exit;
}

// Format the bookings
$bookings = [];

while ($row = $result->fetch_assoc()) {
    $bookings[] = [
        "booking_id"        => $row['booking_id'],
        "car_id"            => $row['car_id'],
        "car_model"         => $row['car_model'],
        "car_brand"         => $row['car_brand'],
        "car_image_url"     => $row['car_image_url'],
        "pickup_datetime"   => $row['pickup_datetime'],
        "return_datetime"   => $row['return_datetime'],
        "subscription_type" => $row['subscription_type'],
        "price"             => $row['price'],
        "status"            => $row['status'] ?? "Pending",
        "payment_id"        => $row['payment_id'] ?? null,
        "payment_status"    => $row['payment_status'] ?? 'unpaid',
        "transaction_id"    => $row['transaction_id'] ?? null,
        "payment_date"      => $row['payment_date'] ?? null
    ];
}

echo json_encode([
    "success" => true,
    "bookings" => $bookings
]);

$conn->close();
?>