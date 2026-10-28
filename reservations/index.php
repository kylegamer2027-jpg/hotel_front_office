
<?php
require_once "../config/database.php";

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$message = "";
$error = "";
$guest_id = "";
$room_id = "";
$check_in = "";
$check_out = "";
$adults = 1;
$children = 0;
$booking_source = "Walk-in";
$deposit = "0.00";

$valid_sources = ["Walk-in", "Phone", "Website", "Travel Agency"];

// Create reservation
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $guest_id = trim($_POST["guest_id"] ?? "");
    $room_id = trim($_POST["room_id"] ?? "");
    $check_in = trim($_POST["check_in"] ?? "");
    $check_out = trim($_POST["check_out"] ?? "");
    $adults = filter_var($_POST["adults"] ?? null, FILTER_VALIDATE_INT);
    $children = filter_var($_POST["children"] ?? null, FILTER_VALIDATE_INT);
    $booking_source = $_POST["booking_source"] ?? "";
    $deposit_input = trim($_POST["deposit"] ?? "0");

    if ($deposit_input === "" || !is_numeric($deposit_input)) {
        $error = "Please enter a valid deposit.";
    } else {
        $deposit = (float)$deposit_input;
    }

    if (
        $error === "" &&
        (
            !ctype_digit($guest_id) ||
            (int)$guest_id < 1 ||
            !ctype_digit($room_id) ||
            (int)$room_id < 1 ||
            $adults === false || $adults < 1 ||
            $children === false || $children < 0 ||
            $deposit < 0 ||
            !in_array($booking_source, $valid_sources, true)
        )
    ) {
        $error = "Please enter valid reservation information.";
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
        $error = "Check-out must be later than check-in. Please enter valid dates.";
    }

    // Verify guest exists
    if ($error === "") {
        $stmt = $conn->prepare(
            "SELECT guest_id FROM guests WHERE guest_id = ?"
        );
        $gid = (int)$guest_id;
        $stmt->bind_param("i", $gid);
        $stmt->execute();
        $guest_exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if (!$guest_exists) {
            $error = "The selected guest was not found.";
        }
    }

    // Check room and overlapping reservations
    if ($error === "") {
        $rid = (int)$room_id;

        $stmt = $conn->prepare(
            "SELECT room_status FROM rooms WHERE room_id = ?"
        );
        $stmt->bind_param("i", $rid);
        $stmt->execute();
        $room_result = $stmt->get_result();
        $room = $room_result->fetch_assoc();
        $stmt->close();

        if (!$room) {
            $error = "The selected room was not found.";
        } elseif ($room["room_status"] === "Maintenance") {
            $error = "This room is under maintenance and cannot be booked.";
        } else {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS total
                 FROM reservations
                 WHERE room_id = ?
                   AND reservation_status IN
                       ('Pending', 'Confirmed', 'Checked-in')
                   AND check_in < ?
                   AND check_out > ?"
            );

            $stmt->bind_param("iss", $rid, $check_out, $check_in);
            $stmt->execute();
            $overlap = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ((int)$overlap["total"] > 0) {
                $error = "This room already has a reservation during those dates.";
            }
        }
    }

    // Save reservation
    if ($error === "") {
        $gid = (int)$guest_id;
        $rid = (int)$room_id;
        $adult_count = (int)$adults;
        $child_count = (int)$children;
        $deposit_value = (float)$deposit;

        $stmt = $conn->prepare(
            "INSERT INTO reservations
             (guest_id, room_id, check_in, check_out, adults, children,
              booking_source, reservation_status, deposit)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', ?)"
        );

        $stmt->bind_param(
            "iissiisd",
            $gid,
            $rid,
            $check_in,
            $check_out,
            $adult_count,
            $child_count,
            $booking_source,
            $deposit_value
        );

        // Use the correct bind type sequence: integer, integer,
        // string, string, integer, integer, string, double.
        // Remove spaces from the type string.
        if ($stmt->execute()) {
            $new_id = $conn->insert_id;
            $stmt->close();

            header("Location: index.php?added=" . $new_id);
            exit;
        } else {
            $error = "Unable to save the reservation. Please try again.";
            $stmt->close();
        }
    }
}

if (isset($_GET["added"]) && ctype_digit($_GET["added"])) {
    $message = "Reservation #" . $_GET["added"] . " created successfully!";
}

// Load guests and bookable rooms for the form
$guests = $conn->query(
    "SELECT guest_id, first_name, last_name
     FROM guests ORDER BY last_name, first_name"
);

$rooms = $conn->query(
    "SELECT room_id, room_number, room_type, rate, room_status
     FROM rooms
     WHERE room_status <> 'Maintenance'
     ORDER BY room_number"
);

// Search reservations
$search = trim($_GET["q"] ?? "");

$sql = "SELECT r.*, g.first_name, g.last_name, rm.room_number
        FROM reservations r
        JOIN guests g ON r.guest_id = g.guest_id
        LEFT JOIN rooms rm ON r.room_id = rm.room_id";

if ($search !== "") {
    $sql .= " WHERE CAST(r.reservation_id AS CHAR) LIKE ?
              OR g.first_name LIKE ?
              OR g.last_name LIKE ?
              OR rm.room_number LIKE ?
              OR r.reservation_status LIKE ?";
}

$sql .= " ORDER BY r.created_at DESC";

$stmt = $conn->prepare($sql);

if ($search !== "") {
    $term = "%" . $search . "%";
    $stmt->bind_param("sssss", $term, $term, $term, $term, $term);
}

$stmt->execute();
$reservations = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reservation Management</title>

<style>
* { box-sizing: border-box; }
body {
    font-family: Arial, sans-serif;
    background: #f4f6f9;
    color: #263238;
    margin: 0;
    padding: 25px;
}
.container { max-width: 1200px; margin: auto; }
.top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.panel {
    background: white;
    padding: 22px;
    margin: 20px 0;
    border-radius: 10px;
    box-shadow: 0 2px 8px #0000000d;
}
.grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 15px;
}
label { display: block; font-weight: bold; margin-bottom: 7px; }
input, select {
    width: 100%;
    padding: 11px;
    border: 1px solid #ccd2d8;
    border-radius: 5px;
    font-size: 15px;
}
button, .btn {
    display: inline-block;
    padding: 10px 14px;
    border: 0;
    border-radius: 5px;
    background: #174a7e;
    color: white;
    text-decoration: none;
    cursor: pointer;
    font-size: 14px;
}
.secondary { background: #596579; }
.green { background: #147d64; }
.message { color: #147d39; }
.error { color: #b42318; }
.search {
    display: flex;
    gap: 10px;
    margin: 15px 0;
}
.search input { flex: 1; }
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; margin-top: 15px; }
th, td {
    padding: 12px;
    border-bottom: 1px solid #e2e6ea;
    text-align: left;
    white-space: nowrap;
}
th { background: #174a7e; color: white; }
@media (max-width: 650px) {
    body { padding: 12px; }
    .grid { grid-template-columns: 1fr; }
    .search { flex-wrap: wrap; }
}
</style>
</head>

<body>
<div class="container">

    <div class="top">
        <h1>Reservation Management</h1>
        <a class="btn secondary" href="../index.php">Back to Dashboard</a>
    </div>

    <?php if ($message !== ""): ?>
        <p class="message"><?= e($message) ?></p>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>

    <div class="panel">
        <h2>Create Reservation</h2>

        <?php if ($guests->num_rows === 0): ?>
            <p class="error">Register a guest before creating a reservation.</p>
        <?php else: ?>
        <form method="POST" action="index.php">
            <div class="grid">
                <div>
                    <label for="guest_id">Guest</label>
                    <select id="guest_id" name="guest_id" required>
                        <option value="">Select Guest</option>
                        <?php while ($guest = $guests->fetch_assoc()): ?>
                            <option
                                value="<?= (int)$guest["guest_id"] ?>"
                                <?= (string)$guest_id === (string)$guest["guest_id"] ? "selected" : "" ?>
                            >
                                <?= e($guest["first_name"] . " " . $guest["last_name"]) ?>
                                (ID: <?= (int)$guest["guest_id"] ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div>
                    <label for="room_id">Room</label>
                    <select id="room_id" name="room_id" required>
                        <option value="">Select Room</option>
                        <?php while ($room = $rooms->fetch_assoc()): ?>
                            <option
                                value="<?= (int)$room["room_id"] ?>"
                                <?= (string)$room_id === (string)$room["room_id"] ? "selected" : "" ?>
                            >
                                Room <?= e($room["room_number"]) ?> -
                                <?= e($room["room_type"]) ?> -
                                ₱<?= number_format((float)$room["rate"], 2) ?>/night
                                (<?= e($room["room_status"]) ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div>
                    <label for="check_in">Check-in Date</label>
                    <input type="date" id="check_in" name="check_in"
                           value="<?= e($check_in) ?>" required>
                </div>

                <div>
                    <label for="check_out">Check-out Date</label>
                    <input type="date" id="check_out" name="check_out"
                           value="<?= e($check_out) ?>" required>
                </div>

                <div>
                    <label for="adults">Number of Adults</label>
                    <input type="number" id="adults" name="adults"
                           min="1" value="<?= e($adults) ?>" required>
                </div>

                <div>
                    <label for="children">Number of Children</label>
                    <input type="number" id="children" name="children"
                           min="0" value="<?= e($children) ?>" required>
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
                    <input type="number" id="deposit" name="deposit"
                           min="0" step="0.01" value="<?= e($deposit) ?>" required>
                </div>
            </div>

            <p><button type="submit">Create Reservation</button></p>
        </form>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>Reservation List</h2>

        <form class="search" method="GET" action="index.php">
            <input type="text" name="q"
                   placeholder="Search reservation ID, guest, room, or status"
                   value="<?= e($search) ?>">
            <button type="submit">Search</button>
            <a class="btn secondary" href="index.php">Reset</a>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Reservation ID</th>
                    <th>Guest</th>
                    <th>Room</th>
                    <th>Check-in</th>
                    <th>Check-out</th>
                    <th>Deposit</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($reservations->num_rows > 0): ?>
                    <?php while ($r = $reservations->fetch_assoc()): ?>
                    <tr>
                        <td><?= (int)$r["reservation_id"] ?></td>
                        <td><?= e($r["first_name"] . " " . $r["last_name"]) ?></td>
                        <td><?= $r["room_number"] === null ? "Not assigned" : e($r["room_number"]) ?></td>
                        <td><?= e($r["check_in"]) ?></td>
                        <td><?= e($r["check_out"]) ?></td>
                        <td>₱<?= number_format((float)$r["deposit"], 2) ?></td>
                        <td><?= e($r["reservation_status"]) ?></td>
                        <td>
                            <a class="btn" href="view.php?id=<?= (int)$r["reservation_id"] ?>">View</a>
                            <a class="btn green" href="edit.php?id=<?= (int)$r["reservation_id"] ?>">Edit</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="8">No reservations found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
</body>
</html>

<?php
$stmt->close();
$conn->close();
?>
