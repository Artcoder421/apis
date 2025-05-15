<?php
header('Content-Type: application/json');

// DB config
$host = 'localhost';
$db = 'car_rental';
$user = 'root';
$pass = '';
$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    echo json_encode(["success" => false, "error" => "Connection failed: " . $conn->connect_error]);
    exit;
}

if (!isset($_GET['user_id']) || empty($_GET['user_id'])) {
    echo json_encode(["success" => false, "error" => "Missing user_id"]);
    exit;
}

$user_id = $conn->real_escape_string($_GET['user_id']);

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
        AND b.status = 'approved'
        ORDER BY b.pickup_datetime DESC";

$result = $conn->query($sql);

if (!$result) {
    echo json_encode(["success" => false, "error" => "Query error: " . $conn->error]);
    exit;
}

$reminders = [];
$now = new DateTime();

while ($row = $result->fetch_assoc()) {
    $pickup = new DateTime($row['pickup_datetime']);
    $intervalHours = $now->diff($pickup)->h + ($now->diff($pickup)->days * 24);
    $intervalDays = $now->diff($pickup)->days;
    $type = $row['subscription_type'];

    // Reminder logic
    if ($type === 'daily' && in_array($intervalHours, [6, 2, 1, 0])) {
        $reminders[] = [
            'title' => 'Pickup Reminder',
            'message' => "Your daily booking starts in $intervalHours hour(s).",
            'datetime' => $row['pickup_datetime'],
            'status' => $row['status']
        ];
    } elseif ($type === 'weekly' && $intervalDays <= 1) {
        $text = $intervalDays === 0 ? 'today' : 'tomorrow';
        $reminders[] = [
            'title' => 'Weekly Reminder',
            'message' => "Your weekly booking starts $text.",
            'datetime' => $row['pickup_datetime'],
            'status' => $row['status']
        ];
    } elseif ($type === 'monthly' && $intervalDays <= 3) {
        $reminders[] = [
            'title' => 'Monthly Reminder',
            'message' => "Your monthly booking starts in $intervalDays day(s).",
            'datetime' => $row['pickup_datetime'],
            'status' => $row['status']
        ];
    }
}

echo json_encode([
    "success" => true,
    "reminders" => $reminders
]);

$conn->close();
?>
