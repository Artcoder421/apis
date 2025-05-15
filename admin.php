<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "car_rental";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die(json_encode(["error" => "Connection failed: " . $conn->connect_error]));
}

$action = $_REQUEST['action'] ?? '';

/**
 * Format date for JSON output
 */
function formatDateForJson($dateString) {
    if (empty($dateString) ){
        return null;
    }
    try {
        $date = new DateTime($dateString);
        return $date->format(DateTime::ATOM); // ISO 8601 format
    } catch (Exception $e) {
        return null;
    }
}


function castTypes($row) {
    $intFields = ['id', 'user_id', 'seats', 'offers'];
    foreach ($intFields as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = ($row[$field] !== null && $row[$field] !== '') ? (int)$row[$field] : null;
        }
    }
    
    // Float fields
    $floatFields = ['price', 'price_daily', 'price_weekly', 'price_monthly'];
    foreach ($floatFields as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = (float)$row[$field];
        }
    }
    
    // JSON fields
    $jsonFields = ['images', 'specifications'];
    foreach ($jsonFields as $field) {
        if (array_key_exists($field, $row) && !empty($row[$field])) {
            $decoded = json_decode($row[$field], true);
            $row[$field] = ($decoded !== null) ? $decoded : [];
        } else {
            $row[$field] = [];
        }
    }
    
    // Date fields
    $dateFields = ['pickup_datetime', 'return_datetime', 'created_at'];
    foreach ($dateFields as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = formatDateForJson($row[$field]);
        }
    }
    
    // Ensure car_id remains a string
    if (array_key_exists('car_id', $row)) {
        $row['car_id'] = $row['car_id'];
    }
    
    return $row;
}

switch ($action) {
    case 'get_bookings':
        $result = $conn->query("SELECT * FROM bookings");
        $bookings = [];
        while($row = $result->fetch_assoc()) {
            $bookings[] = castTypes($row);
        }
        echo json_encode($bookings);
        break;
        
    case 'update_booking_status':
        $bookingId = (int)$_POST['booking_id'];
        $status = $_POST['status'];
        
        $stmt = $conn->prepare("UPDATE bookings SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $bookingId);
        $stmt->execute();
        
        echo json_encode([
            'success' => $stmt->affected_rows > 0,
            'updated' => $stmt->affected_rows
        ]);
        break;
        case 'get_recent_bookings':
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;
    $query = "SELECT 
                b.id, 
                b.status, 
                b.pickup_datetime, 
                b.return_datetime,
                u.username as customer_name,
                c.model as car_model
              FROM bookings b
              JOIN users u ON b.user_id = u.id
              JOIN cars c ON b.car_id = c.id
              ORDER BY b.created_at DESC
              LIMIT ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $bookings = [];
    while ($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }
    
    echo json_encode($bookings);
    break;
        case 'get_dashboard_stats':
    $totalBookings = $conn->query("SELECT COUNT(*) as total FROM bookings")->fetch_assoc()['total'];
    $availableCars = $conn->query("SELECT COUNT(*) as available FROM cars WHERE id NOT IN (SELECT car_id FROM bookings WHERE status = 'approved' AND return_datetime > NOW())")->fetch_assoc()['available'];
    $activeDealers = $conn->query("SELECT COUNT(*) as active FROM dealers")->fetch_assoc()['active'];
    
    echo json_encode([
        'totalBookings' => (int)$totalBookings,
        'availableCars' => (int)$availableCars,
        'activeDealers' => (int)$activeDealers,
    ]);
        break;
        
    case 'edit_booking':
        $id = (int)$_POST['id'];
        $carId = $_POST['car_id']; // Explicit string cast
        $pickup = $_POST['pickup_datetime'];
        $return = $_POST['return_datetime'];
        $subscription = $_POST['subscription_type'];
        
        try {
            $pickupDate = new DateTime($pickup);
            $returnDate = new DateTime($return);
            
            $stmt = $conn->prepare("UPDATE bookings SET car_id = ?, pickup_datetime = ?, return_datetime = ?, subscription_type = ? WHERE id = ?");
            $stmt->bind_param(
                "ssssi", 
                $carId,
                $pickupDate->format('Y-m-d H:i:s'),
                $returnDate->format('Y-m-d H:i:s'),
                $subscription,
                $id
            );
            $stmt->execute();
            
            echo json_encode([
                'success' => $stmt->affected_rows > 0,
                'updated' => $stmt->affected_rows
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'error' => 'Invalid date format',
                'details' => $e->getMessage()
            ]);
        }
        break;
        
    case 'get_cars':
        $result = $conn->query("SELECT * FROM cars");
        $cars = [];
        while($row = $result->fetch_assoc()) {
            $cars[] = castTypes($row);
        }
        echo json_encode($cars);
        break;
        
    case 'add_car':
        $required = ['brand', 'model', 'price_daily', 'price_weekly', 'price_monthly'];
        foreach ($required as $field) {
            if (!isset($_POST[$field])) {
                echo json_encode(['error' => "Missing required field: $field"]);
                exit;
            }
        }
        
        $brand = $_POST['brand'];
        $model = $_POST['model'];
        $priceDaily = (float)$_POST['price_daily'];
        $priceWeekly = (float)$_POST['price_weekly'];
        $priceMonthly = (float)$_POST['price_monthly'];
        $color = $_POST['color'] ?? '';
        $transmission = $_POST['transmission'] ?? '';
        $seats = isset($_POST['seats']) ? (int)$_POST['seats'] : null;
        $specifications = json_encode($_POST['specifications'] ?? []);
        
        $defaultImages = json_encode(["default_car.png"]);
        
        $stmt = $conn->prepare("INSERT INTO cars (brand, model, price, condition_type, price_daily, price_weekly, price_monthly, color, transmission, seats, specifications, images) VALUES (?, ?, 0, 'Daily', ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param(
            "ssdddssiss", 
            $brand, 
            $model, 
            $priceDaily, 
            $priceWeekly, 
            $priceMonthly, 
            $color, 
            $transmission, 
            $seats, 
            $specifications, 
            $defaultImages
        );
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'id' => $stmt->insert_id
            ]);
        } else {
            echo json_encode([
                'error' => 'Failed to add car',
                'db_error' => $conn->error
            ]);
        }
        break;
        
    case 'update_car':
        $id = (int)$_POST['id'];
        $brand = $_POST['brand'] ?? '';
        $model = $_POST['model'] ?? '';
        $priceDaily = isset($_POST['price_daily']) ? (float)$_POST['price_daily'] : null;
        $priceWeekly = isset($_POST['price_weekly']) ? (float)$_POST['price_weekly'] : null;
        $priceMonthly = isset($_POST['price_monthly']) ? (float)$_POST['price_monthly'] : null;
        $color = $_POST['color'] ?? null;
        $transmission = $_POST['transmission'] ?? null;
        $seats = isset($_POST['seats']) ? (int)$_POST['seats'] : null;
        $specifications = isset($_POST['specifications']) ? json_encode($_POST['specifications']) : null;
        
        // Build dynamic query based on provided fields
        $query = "UPDATE cars SET ";
        $params = [];
        $types = "";
        $values = [];
        
        $fields = [
            'brand' => ['value' => $brand, 'type' => 's'],
            'model' => ['value' => $model, 'type' => 's'],
            'price_daily' => ['value' => $priceDaily, 'type' => 'd'],
            'price_weekly' => ['value' => $priceWeekly, 'type' => 'd'],
            'price_monthly' => ['value' => $priceMonthly, 'type' => 'd'],
            'color' => ['value' => $color, 'type' => 's'],
            'transmission' => ['value' => $transmission, 'type' => 's'],
            'seats' => ['value' => $seats, 'type' => 'i'],
            'specifications' => ['value' => $specifications, 'type' => 's']
        ];
        
        foreach ($fields as $field => $data) {
            if ($data['value'] !== null) {
                $query .= "$field = ?, ";
                $types .= $data['type'];
                $values[] = $data['value'];
            }
        }
        
        $query = rtrim($query, ', ') . " WHERE id = ?";
        $types .= 'i';
        $values[] = $id;
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        
        echo json_encode([
            'success' => $stmt->affected_rows > 0,
            'updated' => $stmt->affected_rows
        ]);
        break;
        
    case 'delete_car':
        $id = (int)$_POST['id'];
        
        $stmt = $conn->prepare("DELETE FROM cars WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        echo json_encode([
            'success' => $stmt->affected_rows > 0,
            'deleted' => $stmt->affected_rows
        ]);
        break;
        
    case 'get_dealers':
        $result = $conn->query("SELECT * FROM dealers");
        $dealers = [];
        while($row = $result->fetch_assoc()) {
            $dealers[] = castTypes($row);
        }
        echo json_encode($dealers);
        break;
        
    case 'add_dealer':
        if (!isset($_POST['name']) || !isset($_POST['offers'])) {
            echo json_encode(['error' => 'Missing required fields']);
            exit;
        }
        
        $name = $_POST['name'];
        $offers = (int)$_POST['offers'];
        $imagePath = "default_dealer.png";
        
        $stmt = $conn->prepare("INSERT INTO dealers (name, offers, image_path) VALUES (?, ?, ?)");
        $stmt->bind_param("sis", $name, $offers, $imagePath);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'id' => $stmt->insert_id
            ]);
        } else {
            echo json_encode([
                'error' => 'Failed to add dealer',
                'db_error' => $conn->error
            ]);
        }
        break;
        
    case 'delete_dealer':
        $id = (int)$_POST['id'];
        
        $stmt = $conn->prepare("DELETE FROM dealers WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        echo json_encode([
            'success' => $stmt->affected_rows > 0,
            'deleted' => $stmt->affected_rows
        ]);
        break;
        
    default:
        echo json_encode([
            'error' => 'Invalid action',
            'available_actions' => [
                'get_bookings',
                'update_booking_status',
                'edit_booking',
                'get_cars',
                'add_car',
                'update_car',
                'delete_car',
                'get_dealers',
                'add_dealer',
                'delete_dealer'
            ]
        ]);
}

$conn->close();
?>