<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$dataFile = __DIR__ . '/data.json';

function loadData() {
    global $dataFile;
    if (!file_exists($dataFile)) {
        return getDefaultData();
    }
    $content = file_get_contents($dataFile);
    return json_decode($content, true);
}

function saveData($data) {
    global $dataFile;
    $result = file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $result !== false;
}

function getDefaultData() {
    return [
        'settings' => ['logo_text' => 'LUXE STORE', 'logo_image' => '', 'admin_pin' => '1234', 'header_buttons' => []],
        'welcome_popup' => ['enabled' => false, 'title' => '', 'message' => '', 'buttons' => []],
        'hero_images' => [],
        'stories' => [],
        'categories' => [],
        'products' => [],
        'payment' => [
            'crypto' => ['enabled' => true, 'qr_image' => '', 'coin' => 'USDT', 'network' => 'TRC20', 'wallet_address' => '', 'instructions' => ''],
            'easypaisa' => ['enabled' => true, 'image' => '', 'account_name' => '', 'iban' => '', 'account_number' => '', 'instructions' => ''],
            'extra_fields' => []
        ],
        'orders' => []
    ];
}

function generateId($prefix = '') {
    return $prefix . uniqid() . '_' . bin2hex(random_bytes(4));
}

function handleImageUpload($fileKey, $destDir = 'uploads/') {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    $ext = strtolower(pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed)) {
        return null;
    }
    $filename = generateId('img_') . '.' . $ext;
    $dest = $destDir . $filename;
    if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $dest)) {
        return $dest;
    }
    return null;
}

$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (empty($action)) {
        $body = file_get_contents('php://input');
        $json = json_decode($body, true);
        $action = $json['action'] ?? '';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'get_data';
}

$data = loadData();

switch ($action) {
    // ─── GET DATA ───────────────────────────────────────────────────────────────
    case 'get_data':
        // Clean expired orders (24 hours, pending only)
        $now = time();
        $data['orders'] = array_values(array_filter($data['orders'], function($order) use ($now) {
            if ($order['status'] === 'pending') {
                $created = $order['created_at'] ?? 0;
                if (($now - $created) > 86400) return false;
            }
            return true;
        }));
        saveData($data);
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    // ─── VERIFY PIN ─────────────────────────────────────────────────────────────
    case 'verify_pin':
        $body = json_decode(file_get_contents('php://input'), true);
        $pin = $body['pin'] ?? '';
        if ($pin === $data['settings']['admin_pin']) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid PIN']);
        }
        break;

    // ─── SETTINGS ───────────────────────────────────────────────────────────────
    case 'save_settings':
        $body = json_decode(file_get_contents('php://input'), true);
        if (isset($body['settings'])) {
            $data['settings'] = array_merge($data['settings'], $body['settings']);
        }
        if (isset($body['welcome_popup'])) {
            $data['welcome_popup'] = array_merge($data['welcome_popup'], $body['welcome_popup']);
        }
        saveData($data);
        echo json_encode(['success' => true]);
        break;

    case 'upload_logo':
        $imgPath = handleImageUpload('logo');
        if ($imgPath) {
            $data['settings']['logo_image'] = $imgPath;
            saveData($data);
            echo json_encode(['success' => true, 'path' => $imgPath]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Upload failed']);
        }
        break;

    case 'change_pin':
        $body = json_decode(file_get_contents('php://input'), true);
        $currentPin = $body['current_pin'] ?? '';
        $newPin = $body['new_pin'] ?? '';
        if ($currentPin !== $data['settings']['admin_pin']) {
            echo json_encode(['success' => false, 'message' => 'Current PIN is incorrect']);
        } elseif (strlen($newPin) < 4) {
            echo json_encode(['success' => false, 'message' => 'PIN must be at least 4 digits']);
        } else {
            $data['settings']['admin_pin'] = $newPin;
            saveData($data);
            echo json_encode(['success' => true]);
        }
        break;

    // ─── HERO IMAGES ────────────────────────────────────────────────────────────
    case 'add_hero':
        $imgPath = handleImageUpload('hero_image');
        $caption = $_POST['caption'] ?? '';
        if ($imgPath) {
            $data['hero_images'][] = ['id' => generateId('hero'), 'url' => $imgPath, 'caption' => $caption];
            saveData($data);
            echo json_encode(['success' => true]);
        } else {
            // Support URL
            $body = json_decode(file_get_contents('php://input'), true);
            $url = $body['url'] ?? '';
            if ($url) {
                $data['hero_images'][] = ['id' => generateId('hero'), 'url' => $url, 'caption' => $body['caption'] ?? ''];
                saveData($data);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'No image provided']);
            }
        }
        break;

    case 'delete_hero':
        $body = json_decode(file_get_contents('php://input'), true);
        $id = $body['id'] ?? '';
        $data['hero_images'] = array_values(array_filter($data['hero_images'], fn($h) => $h['id'] !== $id));
        saveData($data);
        echo json_encode(['success' => true]);
        break;

    case 'update_hero':
        $body = json_decode(file_get_contents('php://input'), true);
        $id = $body['id'] ?? '';
        foreach ($data['hero_images'] as &$hero) {
            if ($hero['id'] === $id) {
                $hero['caption'] = $body['caption'] ?? $hero['caption'];
                if (!empty($body['url'])) $hero['url'] = $body['url'];
                break;
            }
        }
        saveData($data);
        echo json_encode(['success' => true]);
        break;

    // ─── STORIES ────────────────────────────────────────────────────────────────
    case 'add_story':
        $imgPath = handleImageUpload('story_image');
        $storyData = [
            'id' => generateId('story'),
            'title' => $_POST['title'] ?? '',
            'link' => $_POST['link'] ?? '',
            'button_text' => $_POST['button_text'] ?? 'View',
            'button_link' => $_POST['button_link'] ?? '',
            'image' => $imgPath ?? ($_POST['image_url'] ?? '')
        ];
        $data['stories'][] = $storyData;
        saveData($data);
        echo json_encode(['success' => true, 'story' => $storyData]);
        break;

    case 'update_story':
        $body = json_decode(file_get_contents('php://input'), true);
        $id = $body['id'] ?? '';
        foreach ($data['stories'] as &$story) {
            if ($story['id'] === $id) {
                $story = array_merge($story, array_intersect_key($body, $story));
                break;
            }
        }
        saveData($data);
        echo json_encode(['success' => true]);
        break;

    case 'delete_story':
        $body = json_decode(file_get_contents('php://input'), true);
        $id = $body['id'] ?? '';
        $data['stories'] = array_values(array_filter($data['stories'], fn($s) => $s['id'] !== $id));
        saveData($data);
        echo json_encode(['success' => true]);
        break;

    // ─── CATEGORIES ─────────────────────────────────────────────────────────────
    case 'add_category':
        $body = json_decode(file_get_contents('php://input'), true);
        $cat = ['id' => generateId('cat'), 'name' => $body['name'] ?? '', 'icon' => $body['icon'] ?? '🛍️'];
        $data['categories'][] = $cat;
        saveData($data);
        echo json_encode(['success' => true, 'category' => $cat]);
        break;

    case 'update_category':
        $body = json_decode(file_get_contents('php://input'), true);
        $id = $body['id'] ?? '';
        foreach ($data['categories'] as &$cat) {
            if ($cat['id'] === $id) {
                $cat['name'] = $body['name'] ?? $cat['name'];
                $cat['icon'] = $body['icon'] ?? $cat['icon'];
                break;
            }
        }
        saveData($data);
        echo json_encode(['success' => true]);
        break;

    case 'delete_category':
        $body = json_decode(file_get_contents('php://input'), true);
        $id = $body['id'] ?? '';
        $data['categories'] = array_values(array_filter($data['categories'], fn($c) => $c['id'] !== $id));
        saveData($data);
        echo json_encode(['success' => true]);
        break;

    // ─── PRODUCTS ───────────────────────────────────────────────────────────────
    case 'add_product':
        $imgPath = handleImageUpload('product_image');
        $customButtons = json_decode($_POST['custom_buttons'] ?? '[]', true) ?? [];
        $prod = [
            'id' => generateId('prod'),
            'title' => $_POST['title'] ?? '',
            'short_description' => $_POST['short_description'] ?? '',
            'description' => $_POST['description'] ?? '',
            'image' => $imgPath ?? ($_POST['image_url'] ?? ''),
            'price_usd' => floatval($_POST['price_usd'] ?? 0),
            'discount' => floatval($_POST['discount'] ?? 0),
            'sold' => intval($_POST['sold'] ?? 0),
            'category' => $_POST['category'] ?? '',
            'custom_buttons' => $customButtons
        ];
        $data['products'][] = $prod;
        saveData($data);
        echo json_encode(['success' => true, 'product' => $prod]);
        break;

    case 'update_product':
        $body = json_decode(file_get_contents('php://input'), true);
        $id = $body['id'] ?? '';
        foreach ($data['products'] as &$prod) {
            if ($prod['id'] === $id) {
                $fields = ['title','short_description','description','image','price_usd','discount','sold','category','custom_buttons'];
                foreach ($fields as $f) {
                    if (isset($body[$f])) $prod[$f] = $body[$f];
                }
                break;
            }
        }
        saveData($data);
        echo json_encode(['success' => true]);
        break;

    case 'delete_product':
        $body = json_decode(file_get_contents('php://input'), true);
        $id = $body['id'] ?? '';
        $data['products'] = array_values(array_filter($data['products'], fn($p) => $p['id'] !== $id));
        saveData($data);
        echo json_encode(['success' => true]);
        break;

    // ─── PAYMENT ────────────────────────────────────────────────────────────────
    case 'save_payment':
        $body = json_decode(file_get_contents('php://input'), true);
        if (isset($body['crypto'])) {
            $data['payment']['crypto'] = array_merge($data['payment']['crypto'], $body['crypto']);
        }
        if (isset($body['easypaisa'])) {
            $data['payment']['easypaisa'] = array_merge($data['payment']['easypaisa'], $body['easypaisa']);
        }
        if (isset($body['extra_fields'])) {
            $data['payment']['extra_fields'] = $body['extra_fields'];
        }
        saveData($data);
        echo json_encode(['success' => true]);
        break;

    case 'upload_payment_image':
        $type = $_POST['type'] ?? 'crypto';
        $imgPath = handleImageUpload('payment_image');
        if ($imgPath) {
            if ($type === 'crypto') {
                $data['payment']['crypto']['qr_image'] = $imgPath;
            } else {
                $data['payment']['easypaisa']['image'] = $imgPath;
            }
            saveData($data);
            echo json_encode(['success' => true, 'path' => $imgPath]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Upload failed']);
        }
        break;

    // ─── ORDERS ─────────────────────────────────────────────────────────────────
    case 'submit_order':
        $screenshotPath = handleImageUpload('screenshot');
        $body = [];
        if (!$screenshotPath) {
            $rawBody = file_get_contents('php://input');
            $body = json_decode($rawBody, true) ?? [];
        } else {
            $body = $_POST;
            $body['extra_fields'] = json_decode($_POST['extra_fields'] ?? '{}', true) ?? [];
        }
        $order = [
            'id' => generateId('order'),
            'product_id' => $body['product_id'] ?? '',
            'product_title' => $body['product_title'] ?? '',
            'product_price' => $body['product_price'] ?? '',
            'payment_method' => $body['payment_method'] ?? '',
            'full_name' => $body['full_name'] ?? '',
            'email' => $body['email'] ?? '',
            'mobile' => $body['mobile'] ?? '',
            'screenshot' => $screenshotPath ?? ($body['screenshot'] ?? ''),
            'extra_fields' => $body['extra_fields'] ?? [],
            'status' => 'pending',
            'created_at' => time(),
            'admin_note' => ''
        ];
        $data['orders'][] = $order;
        saveData($data);
        echo json_encode(['success' => true, 'order' => $order]);
        break;

    case 'update_order_status':
        $body = json_decode(file_get_contents('php://input'), true);
        $id = $body['id'] ?? '';
        $status = $body['status'] ?? 'pending';
        $note = $body['note'] ?? '';
        foreach ($data['orders'] as &$order) {
            if ($order['id'] === $id) {
                $order['status'] = $status;
                $order['admin_note'] = $note;
                break;
            }
        }
        saveData($data);
        echo json_encode(['success' => true]);
        break;

    case 'delete_order':
        $body = json_decode(file_get_contents('php://input'), true);
        $id = $body['id'] ?? '';
        $data['orders'] = array_values(array_filter($data['orders'], fn($o) => $o['id'] !== $id));
        saveData($data);
        echo json_encode(['success' => true]);
        break;

    case 'get_orders':
        // Clean expired pending orders
        $now = time();
        $data['orders'] = array_values(array_filter($data['orders'], function($order) use ($now) {
            if ($order['status'] === 'pending') {
                return ($now - ($order['created_at'] ?? 0)) <= 86400;
            }
            return true;
        }));
        saveData($data);
        echo json_encode(['success' => true, 'orders' => $data['orders']]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
        break;
}
?>
