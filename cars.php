<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

$conn = new mysqli("localhost", "root", "", "car_rental");

if ($conn->connect_error) {
    die(json_encode(["error" => "Connection failed: " . $conn->connect_error]));
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_cars':
        $filter = $_GET['filter'] ?? 'Best Match';
        getCars($conn, $filter);
        break;
    case 'get_dealers':
        getDealers($conn);
        break;
    case 'get_filters':
        getFilters($conn);
        break;
    case 'add_car':
        addCar($conn);
        break;
    default:
        echo json_encode(["error" => "Invalid action"]);
        break;
}

function getCars($conn, $filter) {
    $baseUrl = "http://192.168.1.154/CAR_RENTAL_API/uploads/";

    $query = "SELECT * FROM cars";

    switch ($filter) {
        case 'Highest Price':
            $query .= " ORDER BY price_monthly DESC";
            break;
        case 'Lowest Price':
            $query .= " ORDER BY price_monthly ASC";
            break;
        default:
            $query .= " ORDER BY created_at DESC";
    }

    $result = $conn->query($query);
    $cars = [];

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $imagePaths = [];

            if ($row['images']) {
                $rawImages = trim($row['images']);
                if (str_starts_with($rawImages, '[')) {
                    // JSON array format
                    $imagePaths = json_decode($rawImages, true);
                } else {
                    // Plain comma-separated format
                    $imagePaths = explode(',', $rawImages);
                }
            }

            $fullImages = array_map(function ($img) use ($baseUrl) {
                return $baseUrl . trim($img, "\" \t\n\r\0\x0B[]");
            }, $imagePaths);

            $cars[] = [
                'id' => (int)$row['id'],
                'brand' => $row['brand'],
                'model' => $row['model'],
                'price_daily' => (float)($row['price_daily'] ?? 0),
                'price_weekly' => (float)($row['price_weekly'] ?? 0),
                'price_monthly' => (float)($row['price_monthly'] ?? 0),
                'condition_type' => $row['condition_type'] ?? 'Unknown',
                'color' => $row['color'] ?? 'Unknown',
                'transmission' => $row['transmission'] ?? 'Automatic',
                'seats' => (int)($row['seats'] ?? 5),
                'gearbox' => $row['gearbox'] ?? 'Unknown',
                'motor' => $row['motor'] ?? 'Unknown',
                'speed' => $row['speed'] ?? 'Unknown',
                'top_speed' => $row['top_speed'] ?? 'Unknown',
                'specifications' => json_decode($row['specifications'] ?? '{}', true),
                'images' => $fullImages
            ];
        }
    } else {
        $cars = ["message" => "No cars available"];
    }

    echo json_encode($cars);
}

function getDealers($conn) {
    $baseUrl = "http://192.168.1.154/CAR_RENTAL_API/uploads/";
    $result = $conn->query("SELECT * FROM dealers");
    $dealers = [];

    while ($row = $result->fetch_assoc()) {
        $dealers[] = [
            'name' => $row['name'],
            'offers' => (int)$row['offers'],
            'image' => $baseUrl . $row['image_path']
        ];
    }

    echo json_encode($dealers);
}

function getFilters($conn) {
    $result = $conn->query("SELECT * FROM filters");
    $filters = [];

    while ($row = $result->fetch_assoc()) {
        $filters[] = [
            'name' => $row['name']
        ];
    }

    echo json_encode($filters);
}

function addCar($conn) {
    $data = json_decode(file_get_contents('php://input'), true);

    $required = ['brand', 'model', 'price_daily', 'price_weekly', 'price_monthly'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            echo json_encode(["error" => "$field is required"]);
            return;
        }
    }

    $uploaded_images = [];
    if (isset($_FILES['images'])) {
        $upload_dir = 'uploads/';
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];

        foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
            $file_type = $_FILES['images']['type'][$key];
            if (in_array($file_type, $allowed_types)) {
                $file_name = uniqid() . '_' . basename($_FILES['images']['name'][$key]);
                $file_path = $upload_dir . $file_name;

                if (move_uploaded_file($tmp_name, $file_path)) {
                    $uploaded_images[] = $file_name;
                }
            }
        }
    }

    // Save image names as JSON array
    $images_str = json_encode($uploaded_images);
    $specifications_str = isset($data['specifications']) && is_array($data['specifications']) ? json_encode($data['specifications']) : '{}';

    $stmt = $conn->prepare("INSERT INTO cars (
        brand, model, price_daily, price_weekly, price_monthly,
        condition_type, color, transmission, seats, gearbox, motor, speed, top_speed, specifications, images, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

    $stmt->bind_param(
        "ssdddsssissss",
        $data['brand'],
        $data['model'],
        $data['price_daily'],
        $data['price_weekly'],
        $data['price_monthly'],
        $data['condition_type'] ?? 'Unknown',
        $data['color'] ?? 'Unknown',
        $data['transmission'] ?? 'Automatic',
        $data['seats'] ?? 5,
        $data['gearbox'] ?? 'Unknown',
        $data['motor'] ?? 'Unknown',
        $data['speed'] ?? 'Unknown',
        $data['top_speed'] ?? 'Unknown',
        $specifications_str,
        $images_str
    );

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "car_id" => $stmt->insert_id]);
    } else {
        echo json_encode(["error" => "Insert failed: " . $stmt->error]);
    }

    $stmt->close();
}

$conn->close();
?>
