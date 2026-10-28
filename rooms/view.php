
<?php
require_once "../config/database.php";

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    die("Invalid room ID. <a href='index.php'>Back to Room List</a>");
}

$stmt = $conn->prepare("SELECT * FROM rooms WHERE room_id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();

$result = $stmt->get_result();
$room = $result->fetch_assoc();

$stmt->close();

if (!$room) {
    die("Room not found. <a href='index.php'>Back to Room List</a>");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Room</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 25px;
            color: #263238;
        }

        .container {
            max-width: 700px;
            margin: 30px auto;
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 8px #00000010;
        }

        .detail {
            padding: 13px 0;
            border-bottom: 1px solid #e2e6ea;
        }

        .label {
            display: inline-block;
            width: 180px;
            font-weight: bold;
        }

        .btn {
            display: inline-block;
            padding: 10px 15px;
            margin: 15px 5px 0 0;
            background: #174a7e;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }

        .edit {
            background: #147d64;
        }
    </style>
</head>

<body>
<div class="container">
    <h1>Room Details</h1>

<?php if (isset($_GET["updated"]) && $_GET["updated"] === "1"): ?>
    <p style="color: green;">
        Room information updated successfully!
    </p>
<?php endif; ?>

    <div class="detail">
        <span class="label">Room ID:</span>
        <?= e($room["room_id"]) ?>
    </div>

    <div class="detail">
        <span class="label">Room Number:</span>
        <?= e($room["room_number"]) ?>
    </div>

    <div class="detail">
        <span class="label">Room Type:</span>
        <?= e($room["room_type"]) ?>
    </div>

    <div class="detail">
        <span class="label">Rate per Night:</span>
        ₱<?= number_format((float)$room["rate"], 2) ?>
    </div>

    <div class="detail">
        <span class="label">Room Status:</span>
        <?= e($room["room_status"]) ?>
    </div>

    <p>
        <a class="btn" href="index.php">Back to Room List</a>
        <a class="btn edit" href="edit.php?id=<?= (int)$room["room_id"] ?>">
            Edit Room
        </a>
    </p>
</div>
</body>
</html>

<?php $conn->close(); ?>
