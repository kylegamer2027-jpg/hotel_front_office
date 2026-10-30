
<?php
include "../config/database.php";

$message = "";
$message_type = "";

// REGISTER WALK-IN GUEST
if (isset($_POST["register_walkin"])) {

    $first_name = trim($_POST["first_name"] ?? "");
    $last_name = trim($_POST["last_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $id_type = trim($_POST["id_type"] ?? "");
    $id_number = trim($_POST["id_number"] ?? "");

    $room_id = intval($_POST["room_id"] ?? 0);
    $check_in = $_POST["check_in"] ?? "";
    $check_out = $_POST["check_out"] ?? "";
    $adults = intval($_POST["adults"] ?? 0);
    $children = intval($_POST["children"] ?? 0);
    $deposit = floatval($_POST["deposit"] ?? 0);

    $today = date("Y-m-d");

    if (
        $first_name === "" ||
        $last_name === "" ||
        $phone === "" ||
        $address === "" ||
        $id_type === "" ||
        $id_number === "" ||
        $room_id <= 0 ||
        empty($check_in) ||
        empty($check_out) ||
        $adults < 1 ||
        $children < 0 ||
        $deposit < 0 ||
        strtotime($check_in) === false ||
        strtotime($check_out) === false ||
        $check_in > $today ||
        $check_out <= $today
    ) {

        $message = "Please complete all required fields correctly. Walk-in check-in must be today or earlier, and check-out must be after today.";
        $message_type = "error";

    } elseif ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email address.";
        $message_type = "error";

    } else {

        $conn->begin_transaction();

        try {

            // LOCK AND CHECK SELECTED ROOM
            $stmt = $conn->prepare("
                SELECT room_status
                FROM rooms
                WHERE room_id = ?
                FOR UPDATE
            ");

            $stmt->bind_param("i", $room_id);
            $stmt->execute();

            $room = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$room) {
                throw new Exception("Selected room does not exist.");
            }

            if ($room["room_status"] !== "Available") {
                throw new Exception("Selected room is not available.");
            }

            // CHECK FOR OVERLAPPING RESERVATIONS
            $stmt = $conn->prepare("
                SELECT reservation_id
                FROM reservations
                WHERE room_id = ?
                AND reservation_status IN
                    ('Pending', 'Confirmed', 'Checked-in')
                AND check_in < ?
                AND check_out > ?
                LIMIT 1
            ");

            $stmt->bind_param(
                "iss",
                $room_id,
                $check_out,
                $check_in
            );

            $stmt->execute();

            $conflict = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($conflict) {
                throw new Exception(
                    "This room already has a reservation during the selected dates."
                );
            }

            // REGISTER GUEST
            $stmt = $conn->prepare("
                INSERT INTO guests
                (
                    first_name,
                    last_name,
                    email,
                    phone,
                    address,
                    id_type,
                    id_number
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                "sssssss",
                $first_name,
                $last_name,
                $email,
                $phone,
                $address,
                $id_type,
                $id_number
            );

            if (!$stmt->execute()) {
                throw new Exception("Unable to register guest.");
            }

            $guest_id = $conn->insert_id;
            $stmt->close();

            // CREATE WALK-IN RESERVATION
            $booking_source = "Walk-in";
            $reservation_status = "Checked-in";

            $stmt = $conn->prepare("
                INSERT INTO reservations
                (
                    guest_id,
                    room_id,
                    check_in,
                    check_out,
                    adults,
                    children,
                    booking_source,
                    reservation_status,
                    deposit
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                "iissiissd",
                $guest_id,
                $room_id,
                $check_in,
                $check_out,
                $adults,
                $children,
                $booking_source,
                $reservation_status,
                $deposit
            );

            if (!$stmt->execute()) {
                throw new Exception("Unable to create walk-in reservation.");
            }

            $reservation_id = $conn->insert_id;
            $stmt->close();

            // UPDATE ROOM STATUS
            $stmt = $conn->prepare("
                UPDATE rooms
                SET room_status = 'Occupied'
                WHERE room_id = ?
            ");

            $stmt->bind_param("i", $room_id);

            if (!$stmt->execute()) {
                throw new Exception("Unable to update room status.");
            }

            $stmt->close();

            // SAVE ALL CHANGES
            $conn->commit();

            $message = "Walk-in guest successfully registered and checked in! Reservation ID: #" .
                       $reservation_id;

            $message_type = "success";

        } catch (Exception $e) {

            $conn->rollback();

            $message = $e->getMessage();
            $message_type = "error";
        }
    }
}

// GET AVAILABLE ROOMS
$available_rooms = $conn->query("
    SELECT room_id, room_number, room_type, rate
    FROM rooms
    WHERE room_status = 'Available'
    ORDER BY room_number ASC
");

// GET WALK-IN HISTORY
$history = $conn->query("
    SELECT
        r.reservation_id,
        r.check_in,
        r.check_out,
        r.adults,
        r.children,
        r.deposit,
        r.reservation_status,
        g.first_name,
        g.last_name,
        g.phone,
        rm.room_number,
        rm.room_type
    FROM reservations r
    INNER JOIN guests g
        ON r.guest_id = g.guest_id
    LEFT JOIN rooms rm
        ON r.room_id = rm.room_id
    WHERE r.booking_source = 'Walk-in'
    ORDER BY r.created_at DESC
");
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Walk-in Guest Registration</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 25px;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: auto;
        }

        h1 {
            color: #243447;
            margin-bottom: 8px;
        }

        .subtitle {
            color: #777;
            margin-bottom: 25px;
        }

        .card {
            background: white;
            padding: 22px;
            border-radius: 8px;
            margin-bottom: 25px;
        }

        .message {
            padding: 14px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .success {
            background: #d4edda;
            color: #155724;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
        }

        .section-title {
            color: #243447;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 18px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        label {
            font-weight: bold;
            font-size: 14px;
        }

        input, select {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 14px;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .save-button {
            background: #243447;
            color: white;
            padding: 13px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }

        .save-button:hover {
            background: #172534;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        th, td {
            padding: 13px;
            text-align: left;
            border-bottom: 1px solid #eee;
            font-size: 13px;
        }

        th {
            background: #243447;
            color: white;
        }

        .status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
        }

        .checked-in {
            background: #d4edda;
            color: #155724;
        }

        .checked-out {
            background: #e2e3e5;
            color: #383d41;
        }

        .empty {
            text-align: center;
            padding: 25px;
            color: #777;
        }

        .note {
            background: #eaf2f8;
            color: #24506b;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 13px;
        }

        @media (max-width: 650px) {

            body {
                padding: 12px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .full-width {
                grid-column: auto;
            }
        }

    </style>

</head>

<body>

<div class="container">

    <h1>Walk-in Guest Registration</h1>

    <p class="subtitle">
        Register guests who arrive without an advance reservation.
    </p>

    <?php if ($message !== ""): ?>

        <div class="message <?php echo $message_type; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>

    <?php endif; ?>

    <div class="note">
        A successful registration automatically creates a checked-in
        reservation and changes the selected room status to Occupied.
    </div>

    <!-- REGISTRATION FORM -->
    <div class="card">

        <h2 class="section-title">Guest Information</h2>

        <form method="POST">

            <div class="form-grid">

                <div class="form-group">

                    <label>First Name</label>

                    <input
                        type="text"
                        name="first_name"
                        maxlength="100"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Last Name</label>

                    <input
                        type="text"
                        name="last_name"
                        maxlength="100"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Email Address</label>

                    <input
                        type="email"
                        name="email"
                        placeholder="Optional"
                    >

                </div>

                <div class="form-group">

                    <label>Phone Number</label>

                    <input
                        type="text"
                        name="phone"
                        maxlength="30"
                        required
                    >

                </div>

                <div class="form-group full-width">

                    <label>Address</label>

                    <input
                        type="text"
                        name="address"
                        maxlength="255"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>ID Type</label>

                    <select name="id_type" required>

                        <option value="">Select ID Type</option>
                        <option value="Passport">Passport</option>
                        <option value="Driver's License">Driver's License</option>
                        <option value="National ID">National ID</option>
                        <option value="PhilHealth ID">PhilHealth ID</option>
                        <option value="Postal ID">Postal ID</option>
                        <option value="School ID">School ID</option>
                        <option value="Other">Other</option>

                    </select>

                </div>

                <div class="form-group">

                    <label>ID Number</label>

                    <input
                        type="text"
                        name="id_number"
                        maxlength="100"
                        required
                    >

                </div>

            </div>

            <br>

            <h2 class="section-title">Reservation Information</h2>

            <div class="form-grid">

                <div class="form-group full-width">

                    <label>Select Available Room</label>

                    <select name="room_id" required>

                        <option value="">Select Room</option>

                        <?php if ($available_rooms && $available_rooms->num_rows > 0): ?>

                            <?php while ($room = $available_rooms->fetch_assoc()): ?>

                                <option value="<?php echo $room["room_id"]; ?>">

                                    <?php
                                    echo "Room " . htmlspecialchars($room["room_number"]);
                                    echo " - " . htmlspecialchars($room["room_type"]);
                                    echo " - ₱" . number_format($room["rate"], 2);
                                    ?>

                                </option>

                            <?php endwhile; ?>

                        <?php endif; ?>

                    </select>

                </div>

                <div class="form-group">

                    <label>Check-in Date</label>

                    <input
                        type="date"
                        name="check_in"
                        value="<?php echo date('Y-m-d'); ?>"
                        max="<?php echo date('Y-m-d'); ?>"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Expected Check-out Date</label>

                    <input
                        type="date"
                        name="check_out"
                        min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Number of Adults</label>

                    <input
                        type="number"
                        name="adults"
                        min="1"
                        value="1"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Number of Children</label>

                    <input
                        type="number"
                        name="children"
                        min="0"
                        value="0"
                        required
                    >

                </div>

                <div class="form-group full-width">

                    <label>Reservation Deposit (₱)</label>

                    <input
                        type="number"
                        name="deposit"
                        min="0"
                        step="0.01"
                        value="0"
                        required
                    >

                </div>

                <div class="form-group full-width">

                    <button
                        type="submit"
                        name="register_walkin"
                        class="save-button"
                    >
                        Register Walk-in Guest
                    </button>

                </div>

            </div>

        </form>

    </div>

    <!-- WALK-IN HISTORY -->
    <div class="card">

        <h2 class="section-title">Walk-in Registration History</h2>

        <div class="table-container">

            <table>

                <thead>

                    <tr>
                        <th>Reservation ID</th>
                        <th>Guest Name</th>
                        <th>Phone Number</th>
                        <th>Room</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Adults</th>
                        <th>Children</th>
                        <th>Deposit</th>
                        <th>Status</th>
                    </tr>

                </thead>

                <tbody>

                    <?php if ($history && $history->num_rows > 0): ?>

                        <?php while ($row = $history->fetch_assoc()): ?>

                            <tr>

                                <td>
                                    #<?php echo $row["reservation_id"]; ?>
                                </td>

                                <td>
                                    <?php
                                    echo htmlspecialchars(
                                        $row["first_name"] . " " . $row["last_name"]
                                    );
                                    ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($row["phone"]); ?>
                                </td>

                                <td>
                                    <?php
                                    echo htmlspecialchars($row["room_number"] ?? "N/A");
                                    echo " (" . htmlspecialchars($row["room_type"] ?? "N/A") . ")";
                                    ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($row["check_in"]); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($row["check_out"]); ?>
                                </td>

                                <td>
                                    <?php echo intval($row["adults"]); ?>
                                </td>

                                <td>
                                    <?php echo intval($row["children"]); ?>
                                </td>

                                <td>
                                    ₱<?php echo number_format((float)$row["deposit"], 2); ?>
                                </td>

                                <td>

                                    <span class="status <?php echo $row["reservation_status"] === "Checked-in" ? "checked-in" : ($row["reservation_status"] === "Checked-out" ? "checked-out" : ""); ?>">
                                        <?php echo htmlspecialchars($row["reservation_status"]); ?>
                                    </span>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="10" class="empty">
                                No walk-in registrations found.
                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

</body>
</html>

<?php
$conn->close();
?>
