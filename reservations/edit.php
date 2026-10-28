
<?php
require_once "../config/database.php";

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    die("Invalid reservation ID. <a href='index.php'>Back to Reservations</a>");
}

$valid_sources = ["Walk-in", "Phone", "Website", "Travel Agency"];
$valid_statuses = [
    "Pending", "Confirmed", "Checked-in",
    "Checked-out", "Cancelled", "No-show"
];

$stmt = $conn->prepare(
    "SELECT * FROM reservations WHERE reservation_id = ?"
);
$stmt->bind_param("i", $id);
$stmt->execute();
$reservation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$reservation) {
    die("Reservation not found. <a href='index.php'>Back to Reservations</a>");
}

$guest_id = (string)$reservation["guest_id"];
$room_id = (string)($reservation["room_id"] ?? "");
$check_in = $reservation["check_in"];
$check_out = $reservation["check_out"];
$adults = (int)$reservation["adults"];
$children = (int)$reservation["children"];
$booking_source = $reservation["booking_source"];
$status = $reservation["reservation_status"];
$deposit = $reservation["deposit"];
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $guest_id = trim($_POST["guest_id"] ?? "");
    $room_id = trim($_POST["room_id"] ?? "");
    $check_in = trim($_POST["check_in"] ?? "");
    $check_out = trim($_POST["check_out"] ?? "");
    $adults = filter_var($_POST["adults"] ?? null, FILTER_VALIDATE_INT);
    $children = filter_var($_POST["children"] ?? null, FILTER_VALIDATE_INT);
    $booking_source = $_POST["booking_source"] ?? "";
    $status = $_POST["status"] ?? "";
    $deposit_input = trim($_POST["deposit"] ?? "");

    if (
        !ctype_digit($guest_id) || (int)$guest_id < 1 ||
        !ctype_digit($room_id) || (int)$room_id < 1 ||
        $adults === false || $adults < 1 ||
        $children === false || $children < 0 ||
        $deposit_input === "" || !is_numeric($deposit_input) ||
        (float)$deposit_input < 0 ||
        !in_array($booking_source, $valid_sources, true) ||
        !in_array($status, $valid_statuses, true)
    ) {
        $error = "Please enter valid reservation information.";
    } else {
        $deposit = (float)$deposit_input;
    }

    $start = DateTime::createFromFormat("!Y-m-d", $check_in);
    $end = DateTime::createFromFormat("!Y-m-d", $check_out);

    if (
        $error === "" &&
        (
            !$start || $start->format("Y-m-d") !== $check_in ||
            !$end || $end->format("Y-m-d") !== $check_out ||
            $check_out <= $check_in
        )
    ) {
        $error = "Check-out must be later than check-in.";
    }

    // Confirm guest exists.
    if ($error === "") {
        $gid = (int)$guest_id;
        $stmt = $conn->prepare(
            "SELECT guest_id FROM guests WHERE guest_id = ?"
        );
        $stmt->bind_param("i", $gid);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if (!$exists) {
            $error = "The selected guest does not exist.";
        }
    }

    // Check room and booking conflicts.
    if ($error === "") {
        $rid = (int)$room_id;
        $stmt = $conn->prepare(
            "SELECT room_status FROM rooms WHERE room_id = ?"
        );
        $stmt->bind_param("i", $rid);
        $stmt->execute();
        $room = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$room) {
            $error = "The selected room does not exist.";
        } elseif (
            $room["room_status"] === "Maintenance" &&
            !in_array($status, ["Cancelled", "No-show"], true)
        ) {
            $error = "This room is under maintenance and cannot be booked.";
        } else {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS total
                 FROM reservations
                 WHERE room_id = ?
                   AND reservation_id <> ?
                   AND reservation_status IN
                       ('Pending', 'Confirmed', 'Checked-in')
                   AND check_in < ?
                   AND check_out > ?"
            );
            $stmt->bind_param("iiss", $rid, $id, $check_out, $check_in);
            $stmt->execute();
            $conflict = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ((int)$conflict["total"] > 0) {
                $error = "This room has another reservation during those dates.";
            }
        }
    }

    // Save changes.
    if ($error === "") {
        $gid = (int)$guest_id;
        $rid = (int)$room_id;
        $adult_count = (int)$adults;
        $child_count = (int)$children;
        $deposit_value = (float)$deposit;

        $stmt = $conn->prepare(
            "UPDATE reservations
             SET guest_id = ?, room_id = ?, check_in = ?, check_out = ?,
                 adults = ?, children = ?, booking_source = ?,
                 reservation_status = ?, deposit = ?
             WHERE reservation_id = ?"
        );

        $stmt->bind_param(
            "iissiissdi",
            $gid,
            $rid,
            $check_in,
            $check_out,
            $adult_count,
            $child_count,
            $booking_source,
            $status,
            $deposit_value,
            $id
        );

        if ($stmt->execute()) {
            $stmt->close();
            header("Location: view.php?id=" . $id . "&updated=1");
            exit;
        } else {
            $error = "Unable to update the reservation.";
            $stmt->close();
        }
    }
}

$guests = $conn->query(
    "SELECT guest_id, first_name, last_name
     FROM guests ORDER BY last_name, first_name"
);

$rooms = $conn->query(
    "SELECT room_id, room_number, room_type, rate, room_status
     FROM rooms ORDER BY room_number"
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Reservation</title>
<style>
* { box-sizing: border-box; }
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
    padding: 25px;
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px #00000010;
}
.grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 15px;
}
label { display: block; font-weight: bold; margin: 14px 0 7px; }
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
}
.cancel { background: #596579; }
.error { color: #b42318; }
@media (max-width: 600px) {
    body { padding: 12px; }
    .grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="container">
    <h1>Edit Reservation #<?= (int)$id ?></h1>

    <?php if ($error !== ""): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="POST" action="edit.php?id=<?= (int)$id ?>">
        <div class="grid">
            <div>
                <label for="guest_id">Guest</label>
                <select id="guest_id" name="guest_id" required>
                    <?php while ($guest = $guests->fetch_assoc()): ?>
                        <option value="<?= (int)$guest["guest_id"] ?>"
                            <?= $guest_id === (string)$guest["guest_id"] ? "selected" : "" ?>>
                            <?= e($guest["first_name"] . " " . $guest["last_name"]) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div>
                <label for="room_id">Room</label>
                <select id="room_id" name="room_id" required>
                    <?php while ($room = $rooms->fetch_assoc()): ?>
                        <option value="<?= (int)$room["room_id"] ?>"
                            <?= $room_id === (string)$room["room_id"] ? "selected" : "" ?>>
                            Room <?= e($room["room_number"]) ?> -
                            <?= e($room["room_type"]) ?> -
                            <?= e($room["room_status"]) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div>
                <label for="check_in">Check-in</label>
                <input type="date" id="check_in" name="check_in"
                    value="<?= e($check_in) ?>" required>
            </div>

            <div>
                <label for="check_out">Check-out</label>
                <input type="date" id="check_out" name="check_out"
                    value="<?= e($check_out) ?>" required>
            </div>

            <div>
                <label for="adults">Adults</label>
                <input type="number" id="adults" name="adults" min="1"
                    value="<?= e($adults) ?>" required>
            </div>

            <div>
                <label for="children">Children</label>
                <input type="number" id="children" name="children" min="0"
                    value="<?= e($children) ?>" required>
            </div>

            <div>
                <label for="booking_source">Booking Source</label>
                <select id="booking_source" name="booking_source" required>
                    <?php foreach ($valid_sources as $source): ?>
                        <option value="<?= e($source) ?>"
                            <?= $booking_source === $source ? "selected" : "" ?>>
                            <?= e($source) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="deposit">Deposit (₱)</label>
                <input type="number" id="deposit" name="deposit" min="0"
                    step="0.01" value="<?= e($deposit) ?>" required>
            </div>

            <div>
                <label for="status">Reservation Status</label>
                <select id="status" name="status" required>
                    <?php foreach ($valid_statuses as $s): ?>
                        <option value="<?= e($s) ?>"
                            <?= $status === $s ? "selected" : "" ?>>
                            <?= e($s) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <button type="submit">Save Changes</button>
        <a class="btn cancel" href="index.php">Cancel</a>
    </form>
</div>
</body>
</html>

<?php $conn->close(); ?>
