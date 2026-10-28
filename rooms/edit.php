
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

$error = "";

$room_number = $room["room_number"];
$room_type = $room["room_type"];
$rate = $room["rate"];
$room_status = $room["room_status"];

$valid_types = ["Standard", "Deluxe", "Suite"];
$valid_statuses = [
    "Available",
    "Occupied",
    "Maintenance",
    "Reserved",
    "Cleaning"
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $room_number = trim($_POST["room_number"] ?? "");
    $room_type = $_POST["room_type"] ?? "";
    $rate = trim($_POST["rate"] ?? "");
    $room_status = $_POST["room_status"] ?? "";

    if (
        $room_number === "" ||
        !is_numeric($rate) ||
        (float)$rate < 0 ||
        !in_array($room_type, $valid_types, true) ||
        !in_array($room_status, $valid_statuses, true)
    ) {
        $error = "Please enter valid room information.";
    } else {
        $rate = (float)$rate;

        $stmt = $conn->prepare(
            "UPDATE rooms
             SET room_number = ?,
                 room_type = ?,
                 rate = ?,
                 room_status = ?
             WHERE room_id = ?"
        );

        $stmt->bind_param(
            "ssdsi",
            $room_number,
            $room_type,
            $rate,
            $room_status,
            $id
        );

        if ($stmt->execute()) {
            $stmt->close();
            header("Location: view.php?id=" . $id . "&updated=1");
            exit;
        } else {
            if ($stmt->errno === 1062) {
                $error = "That room number is already being used.";
            } else {
                $error = "Unable to update the room. Please try again.";
            }

            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Room</title>

    <style>
        * {
            box-sizing: border-box;
        }

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

        label {
            display: block;
            margin: 16px 0 7px;
            font-weight: bold;
        }

        input, select {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccd2d8;
            border-radius: 5px;
            font-size: 15px;
        }

        button, .btn {
            display: inline-block;
            padding: 11px 15px;
            margin-top: 20px;
            border: none;
            border-radius: 5px;
            background: #174a7e;
            color: white;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        .cancel {
            background: #596579;
        }

        .error {
            color: #b42318;
        }
    </style>
</head>

<body>
<div class="container">
    <h1>Edit Room</h1>

    <?php if ($error !== ""): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="POST" action="edit.php?id=<?= (int)$id ?>">
        <label for="room_number">Room Number</label>
        <input
            type="text"
            id="room_number"
            name="room_number"
            value="<?= e($room_number) ?>"
            required
        >

        <label for="room_type">Room Type</label>
        <select id="room_type" name="room_type" required>
            <?php foreach ($valid_types as $type): ?>
                <option
                    value="<?= e($type) ?>"
                    <?= $room_type === $type ? "selected" : "" ?>
                >
                    <?= e($type) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="rate">Rate per Night (₱)</label>
        <input
            type="number"
            id="rate"
            name="rate"
            min="0"
            step="0.01"
            value="<?= e($rate) ?>"
            required
        >

        <label for="room_status">Room Status</label>
        <select id="room_status" name="room_status" required>
            <?php foreach ($valid_statuses as $status): ?>
                <option
                    value="<?= e($status) ?>"
                    <?= $room_status === $status ? "selected" : "" ?>
                >
                    <?= e($status) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit">Save Changes</button>

        <a class="btn cancel" href="index.php">Cancel</a>
    </form>
</div>
</body>
</html>

<?php $conn->close(); ?>
