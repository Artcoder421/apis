<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

$conn = new mysqli("localhost", "root", "", "car_rental");

if ($conn->connect_error) {
    die(json_encode(["error" => "Database connection failed"]));
}

if (isset($_POST['email'], $_POST['password'])) {
    $email = $conn->real_escape_string($_POST['email']);
    $password = $_POST['password'];

    $sql = "SELECT * FROM users WHERE email = '$email'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user["password"])) {
            echo json_encode([
                "success" => true,
                "user" => [
                    "id" => $user["id"],
                    "username" => $user["username"],
                    "email" => $user["email"],
                    "phone" => $user["phone"],
                    "location" => $user["location"],
                    "role" => $user["role"] // Add this line to include the role
                ]
            ]);
        } else {
            echo json_encode(["error" => "Invalid password"]);
        }
    } else {
        echo json_encode(["error" => "User not found"]);
    }
} else {
    echo json_encode(["error" => "Invalid input"]);
}

$conn->close();
?>