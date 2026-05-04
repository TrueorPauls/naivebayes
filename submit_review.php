<?php
session_start();
include("config.php");
header('Content-Type: application/json');

// 1. REMOVED: The session check exit 
// (Anyone can now pass through this script)

$review = trim($_POST['review'] ?? '');

if (empty($review)) {
    echo json_encode(['error' => 'Please write a review first.']);
    exit;
}

// 2. Call Flask API (Keep your existing URL)
$api_url = 'https://api-tyqn.onrender.com/predict';
$data = json_encode(['review' => $review]);

$options = [
    'http' => [
        'header'  => "Content-Type: application/json\r\n",
        'method'  => 'POST',
        'content' => $data,
        'timeout' => 60
    ],
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($api_url, false, $context);

if ($response === FALSE) {
    echo json_encode(['error' => 'API request failed.']);
    exit;
}

$result = json_decode($response, true);
$prediction = strtolower($result['prediction']);
$sentiment = ($prediction == '1' || $prediction == 'positive') ? 'positive' : 'negative';
$confidence = round((float)($result["confidence"] ?? 0), 2);

// 3. LOGIC CHANGE: Default to "Guest" if not logged in
$user_name = "Guest";
$user_id = "NULL"; // MySQL will accept literal NULL for an INT column

if (isset($_SESSION['user'])) {
    $email = mysqli_real_escape_string($conn, $_SESSION['user']);
    $u = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, full_name FROM users WHERE email='$email'"));
    if ($u) {
        $user_name = !empty($u['full_name']) ? $u['full_name'] : $email;
        $user_id = $u['id'];
    }
}

// 4. Save to DB (Now includes the 'confidence' column)
$review_esc = mysqli_real_escape_string($conn, $review);
$sent_esc   = mysqli_real_escape_string($conn, $sentiment);
$name_esc   = mysqli_real_escape_string($conn, $user_name);

$query = "INSERT INTO reviews (user_id, reviewer_name, review_text, sentiment, confidence, created_at)
          VALUES ($user_id, '$name_esc', '$review_esc', '$sent_esc', $confidence, NOW())";

if (mysqli_query($conn, $query)) {
    echo json_encode([
        'success'   => true,
        'review'    => $review,
        'sentiment' => $sentiment,
        'confidence' => $confidence . '%',
        'reviewer'  => $user_name,
    ]);
} else {
    echo json_encode(['error' => 'Database Error: ' . mysqli_error($conn)]);
}
?>