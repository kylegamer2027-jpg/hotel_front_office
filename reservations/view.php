
<?php
require_once "../config/database.php";

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    die("Invalid reservation ID. <a href='index.php'>Back to Reservations</a>");
}

$stmt = $conn->prepare(
    "SELECT r.*, g.first_name, g.last_name, g.email, g.phone,
            rm.room_number, rm.room_type, rm.rate
     FROM reservations r
     JOIN guests g ON r.guest_id = g.guest_id
     LEFT JOIN rooms rm ON r.room_id = rm.room_id
     WHERE r.reservation_id = ?"
);

$stmt->bind_param("i", $id);
$stmt->execute();
$reservation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$reservation) {
    die("Reservation not found. <a href='index.php'>Back to Reservations</a>");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reservation Details</title>
<style>
body {
    font-family: Arial, sans-serif;
    background: #f4f6f9;
    margin: 0;
    padding: 25px;
    color: #263238;
}
.container {
    max-width: 800px;
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
    width: 190px;
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
.edit { background: #147d64; }
.success { color: #147d39; }
@media (max-width: 600px) {
    .label { display: block; width: auto; margin-bottom: 5px; }
}
</style>
</head>
<body>
<div class="container">
    <h1>Reservation Details #<?= (int)$reservation["reservation_id"] ?></h1>

    <?php if (isset($_GET["updated"]) && $_GET["updated"] === "1"): ?>
        <p class="success">Reservation updated successfully!</p>
    <?php endif; ?>

    <h2>Guest Information</h2>
    <div class="detail"><span class="label">Guest Name:</span><?= e($reservation["first_name"] . " " . $reservation["last_name"]) ?></div>
    <div class="detail"><span class="label">Email:</span><?= e($reservation["email"] ?: "Not provided") ?></div>
    <div class="detail"><span class="label">Phone:</span><?= e($reservation["phone"] ?: "Not provided") ?></div>

    <h2>Booking Information</h2>
    <div class="detail"><span class="label">Room Number:</span><?= $reservation["room_number"] === null ? "Not assigned" : e($reservation["room_number"]) ?></div>
    <div class="detail"><span class="label">Room Type:</span><?= e($reservation["room_type"] ?? "Not assigned") ?></div>
    <div class="detail"><span class="label">Rate per Night:</span><?= $reservation["rate"] === null ? "Not available" : "₱" . number_format((float)$reservation["rate"], 2) ?></div>
    <div class="detail"><span class="label">Check-in:</span><?= e($reservation["check_in"]) ?></div>
    <div class="detail"><span class="label">Check-out:</span><?= e($reservation["check_out"]) ?></div>
    <div class="detail"><span class="label">Adults:</span><?= (int)$reservation["adults"] ?></div>
    <div class="detail"><span class="label">Children:</span><?= (int)$reservation["children"] ?></div>
    <div class="detail"><span class="label">Booking Source:</span><?= e($reservation["booking_source"]) ?></div>
    <div class="detail"><span class="label">Deposit:</span>₱<?= number_format((float)$reservation["deposit"], 2) ?></div>
    <div class="detail"><span class="label">Status:</span><?= e($reservation["reservation_status"]) ?></div>
    <div class="detail"><span class="label">Date Created:</span><?= e($reservation["created_at"]) ?></div>

    <a class="btn" href="index.php">Back to Reservations</a>
    <a class="btn edit" href="edit.php?id=<?= (int)$reservation["reservation_id"] ?>">Edit Reservation</a>
</div>
</body>
</html>

<?php $conn->close(); ?>
