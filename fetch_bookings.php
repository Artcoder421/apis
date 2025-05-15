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

// Ensure user_id is provided
if (!isset($_POST['user_id']) || empty($_POST['user_id'])) {
    echo json_encode([
        "success" => false,
        "error" => "Missing user_id"
    ]);
    exit;
}

$user_id = $conn->real_escape_string($_POST['user_id']);

// Query to fetch bookings
$sql = "SELECT 
            b.id,
            b.car_id,
            c.model,
            c.brand,
            c.images,
            b.pickup_datetime,
            b.return_datetime,
            b.subscription_type,
            b.price,
            b.status
        FROM bookings b
        JOIN cars c ON b.car_id = c.id
        WHERE b.user_id = '$user_id'
        ORDER BY b.pickup_datetime DESC";

$result = $conn->query($sql);

if ($result === false) {
    echo json_encode(["success" => false, "error" => "Query failed: " . $conn->error]);
    exit;
}

// Format the bookings
$bookings = [];

while ($row = $result->fetch_assoc()) {
    $bookings[] = [
        "booking_id"        => $row['id'],
        "car_id"            => $row['car_id'],
        "car_model"         => $row['model'],
        "car_brand"         => $row['brand'],
        "car_image_url"     => $row['images'],
        "pickup_datetime"   => $row['pickup_datetime'],
        "return_datetime"   => $row['return_datetime'],
        "subscription_type" => $row['subscription_type'],
        "price"             => $row['price'],
        "status"            => $row['status'] ?? "Pending"
    ];
}

// Respond with JSON
echo json_encode([
    "success" => true,
    "bookings" => $bookings
]);

$conn->close();
?>
