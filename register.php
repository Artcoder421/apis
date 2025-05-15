<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

$conn = new mysqli("localhost", "root", "", "car_rental");

if ($conn->connect_error) {
    die(json_encode(["error" => "Database connection failed"]));
}

$data = json_decode(file_get_contents("php://input"));

if (isset($data->username, $data->email, $data->phone, $data->location, $data->password)) {
    $username = $conn->real_escape_string($data->username);
    $email = $conn->real_escape_string($data->email);
    $phone = $conn->real_escape_string($data->phone);
    $location = $conn->real_escape_string($data->location);
    $password = password_hash($data->password, PASSWORD_BCRYPT);

    $sql = "INSERT INTO users (username, email, phone, location, password) VALUES ('$username', '$email', '$phone', '$location', '$password')";
    
    if ($conn->query($sql) === TRUE) {
        echo json_encode(["success" => "User registered successfully"]);
    } else {
        echo json_encode(["error" => "Registration failed"]);
    }
} else {
    echo json_encode(["error" => "Invalid input"]);
}

$conn->close();
?>
