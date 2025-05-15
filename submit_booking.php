<?php
$host = "localhost";
$user = "root";
$pass = "";
$db = "car_rental";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get data from POST
$user_id     = $_POST['user_id'];
$name        = $_POST['name'];
$email       = $_POST['email'];
$phone       = $_POST['phone'];
$location    = $_POST['location'];
$postal      = $_POST['postal_address'];
$nationality = $_POST['nationality'];
$pickup      = $_POST['pickup_datetime'];
$return      = $_POST['return_datetime'];
$car_id      = $_POST['car_id'];
$type        = $_POST['subscription_type'];
$price       = $_POST['price'];

// Insert into bookings table
$sql = "INSERT INTO bookings (
            user_id, car_id, name, email, phone, location, postal_address, nationality, 
            pickup_datetime, return_datetime, subscription_type, price
        ) VALUES (
            '$user_id', '$car_id', '$name', '$email', '$phone', '$location', '$postal', '$nationality',
            '$pickup', '$return', '$type', '$price'
        )";

if ($conn->query($sql) === TRUE) {
    echo "Booking recorded successfully";
} else {
    echo "Error: " . $conn->error;
}

$conn->close();
?>
