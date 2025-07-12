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

// Configuration - images go directly in uploads/
$uploadDir = __DIR__ . '/uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

/**
 * Format date for JSON output
 */
function formatDateForJson($dateString) {
    if (empty($dateString)) {
        return null;
    }
    try {
        $date = new DateTime($dateString);
        return $date->format(DateTime::ATOM); // ISO 8601 format
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Process uploaded images and return filenames
 */
function processUploadedImages($carId) {
    global $uploadDir;
    
    $uploadedImages = [];
    
    if (!empty($_FILES['images']['name'][0])) {
        foreach ($_FILES['images']['tmp_name'] as $key => $tmpName) {
            if ($_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['images']['name'][$key], PATHINFO_EXTENSION);
                $filename = "car_" . $carId . "_" . uniqid() . ".$ext";
                $dest = $uploadDir . $filename;
                
                if (move_uploaded_file($tmpName, $dest)) {
                    $uploadedImages[] = $filename;
                }
            }
        }
    }
    
    return $uploadedImages;
}

/**
 * Properly handle specifications JSON data
 */
function processSpecifications($specs) {
    if (is_array($specs)) {
        return $specs;
    }
    
    if (is_string($specs)) {
        $decoded = json_decode($specs, true);
        return ($decoded !== null) ? $decoded : [];
    }
    
    return [];
}

/**
 * Cast database fields to proper types with improved specifications handling
 */
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
    
    // Handle images as JSON array
    if (array_key_exists('images', $row)) {
        $row['images'] = json_decode($row['images'], true) ?? [];
    } else {
        $row['images'] = [];
    }
    
    // Handle specifications as proper JSON object
    if (array_key_exists('specifications', $row)) {
        $row['specifications'] = processSpecifications($row['specifications']);
    } else {
        $row['specifications'] = [];
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
        $row['car_id'] = (string)$row['car_id'];
    }
    
    return $row;
}

switch ($action) {
   case 'get_bookings':
    $statusFilter = $_GET['status'] ?? null;
    
    $query = "SELECT 
                b.*, 
                p.payment_status,
                p.transaction_id as payment_id,
                u.username as customer_name,
                c.model as car_model,
                c.brand as car_brand,
                c.images as car_images
              FROM bookings b
              LEFT JOIN payments p ON b.id = p.booking_id
              JOIN users u ON b.user_id = u.id
              JOIN cars c ON b.car_id = c.id";
              
    if ($statusFilter) {
        $query .= " WHERE b.status = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $statusFilter);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($query);
    }
    
    $bookings = [];
    while($row = $result->fetch_assoc()) {
        $bookings[] = castTypes($row);
    }
    echo json_encode($bookings);
    break;
        
    case 'get_dashboard_stats':
        $totalBookings = $conn->query("SELECT COUNT(*) as total FROM bookings")->fetch_assoc()['total'];
        $availableCars = $conn->query("SELECT COUNT(*) as available FROM cars WHERE id NOT IN (SELECT car_id FROM bookings WHERE status = 'approved' AND return_datetime > NOW())")->fetch_assoc()['available'];
        $activeDealers = $conn->query("SELECT COUNT(*) as active FROM dealers")->fetch_assoc()['active'];
        $revenue = $conn->query("SELECT SUM(price) as revenue FROM bookings WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc()['revenue'] ?? 0;
        
        echo json_encode([
            'totalBookings' => (int)$totalBookings,
            'availableCars' => (int)$availableCars,
            'activeDealers' => (int)$activeDealers,
            'revenue' => (float)$revenue,
        ]);
        break;
        
    case 'edit_booking':
        $id = (int)$_POST['id'];
        $carId = (string)$_POST['car_id'];
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
            $row = castTypes($row);
            // Convert image paths to full URLs (directly in uploads/)
            $row['images'] = array_map(function($img) {
                return "uploads/$img";
            }, $row['images']);
            $cars[] = $row;
        }
        echo json_encode($cars);
        break;
        
    case 'get_available_cars_count':
        $result = $conn->query("SELECT COUNT(*) as count FROM cars WHERE id NOT IN (SELECT car_id FROM bookings WHERE status = 'approved' AND return_datetime > NOW())");
        $data = $result->fetch_assoc();
        echo json_encode(['count' => (int)$data['count']]);
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
        $seats = isset($_POST['seats']) ? (int)$_POST['seats'] : 5;
        
        // Process specifications properly
        $specifications = [];
        if (isset($_POST['specifications'])) {
            $specifications = processSpecifications($_POST['specifications']);
        }
        $specificationsJson = json_encode($specifications);
        
        $stmt = $conn->prepare("INSERT INTO cars (brand, model, price_daily, price_weekly, price_monthly, color, transmission, seats, specifications, images) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '[]')");
        $stmt->bind_param(
            "ssdddssis", 
            $brand, 
            $model, 
            $priceDaily, 
            $priceWeekly, 
            $priceMonthly, 
            $color, 
            $transmission, 
            $seats, 
            $specificationsJson
        );
        
        if ($stmt->execute()) {
            $carId = $stmt->insert_id;
            $uploadedImages = processUploadedImages($carId);
            
            if (!empty($uploadedImages)) {
                $imagesJson = json_encode($uploadedImages);
                $updateStmt = $conn->prepare("UPDATE cars SET images = ? WHERE id = ?");
                $updateStmt->bind_param("si", $imagesJson, $carId);
                $updateStmt->execute();
            }
            
            echo json_encode([
                'success' => true,
                'id' => $carId,
                'images_uploaded' => count($uploadedImages)
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
        
        // First get current images
        $currentImages = [];
        $result = $conn->query("SELECT images FROM cars WHERE id = $id");
        if ($result && $row = $result->fetch_assoc()) {
            $currentImages = json_decode($row['images'], true) ?? [];
        }
        
        // Process new images
        $newImages = processUploadedImages($id);
        $allImages = array_merge($currentImages, $newImages);
        
        // Process specifications properly
        $specifications = [];
        if (isset($_POST['specifications'])) {
            $specifications = processSpecifications($_POST['specifications']);
        }
        $specificationsJson = json_encode($specifications);
        
        // Build update query
        $query = "UPDATE cars SET ";
        $params = [];
        $types = "";
        $values = [];
        
        $fields = [
            'brand' => ['value' => $_POST['brand'] ?? null, 'type' => 's'],
            'model' => ['value' => $_POST['model'] ?? null, 'type' => 's'],
            'price_daily' => ['value' => isset($_POST['price_daily']) ? (float)$_POST['price_daily'] : null, 'type' => 'd'],
            'price_weekly' => ['value' => isset($_POST['price_weekly']) ? (float)$_POST['price_weekly'] : null, 'type' => 'd'],
            'price_monthly' => ['value' => isset($_POST['price_monthly']) ? (float)$_POST['price_monthly'] : null, 'type' => 'd'],
            'color' => ['value' => $_POST['color'] ?? null, 'type' => 's'],
            'transmission' => ['value' => $_POST['transmission'] ?? null, 'type' => 's'],
            'seats' => ['value' => isset($_POST['seats']) ? (int)$_POST['seats'] : null, 'type' => 'i'],
            'specifications' => ['value' => $specificationsJson, 'type' => 's'],
            'images' => ['value' => !empty($allImages) ? json_encode($allImages) : null, 'type' => 's']
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
            'updated' => $stmt->affected_rows,
            'new_images' => $newImages
        ]);
        break;
        
    case 'delete_car':
        $id = (int)$_POST['id'];
        
        // First get images to delete from filesystem
        $result = $conn->query("SELECT images FROM cars WHERE id = $id");
        if ($result && $row = $result->fetch_assoc()) {
            $images = json_decode($row['images'], true) ?? [];
            foreach ($images as $image) {
                $filePath = $uploadDir . $image;
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }
        }
        
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
            $row = castTypes($row);
            // Convert image path to full URL
            if (!empty($row['image_path'])) {
                $row['image_path'] = "http://{$_SERVER['HTTP_HOST']}/uploads/{$row['image_path']}";
            }
            $dealers[] = $row;
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
        
        // Handle image upload if present
        if (!empty($_FILES['image']['tmp_name'])) {
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $imagePath = "dealer_" . uniqid() . ".$ext";
            $dest = $uploadDir . $imagePath;
            
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
                $imagePath = "default_dealer.png";
            }
        }
        
        $stmt = $conn->prepare("INSERT INTO dealers (name, offers, image_path) VALUES (?, ?, ?)");
        $stmt->bind_param("sis", $name, $offers, $imagePath);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'id' => $stmt->insert_id,
                'image_path' => $imagePath
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
        
        // First get image to delete from filesystem
        $result = $conn->query("SELECT image_path FROM dealers WHERE id = $id");
        if ($result && $row = $result->fetch_assoc() && $row['image_path'] != 'default_dealer.png') {
            $filePath = $uploadDir . $row['image_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
        
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
                'get_recent_bookings',
                'get_dashboard_stats',
                'edit_booking',
                'get_cars',
                'get_available_cars_count',
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