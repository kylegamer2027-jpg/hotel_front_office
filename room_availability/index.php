
<?php
require_once "../config/database.php";

$today = date("Y-m-d");
$checkIn = $_GET["check_in"] ?? $today;
$checkOut = $_GET["check_out"] ?? date(
    "Y-m-d",
    strtotime("+1 day")
);

$rooms = [];
$error = "";

// Validate selected dates
$checkInDate = DateTime::createFromFormat("Y-m-d", $checkIn);
$checkOutDate = DateTime::createFromFormat("Y-m-d", $checkOut);

$validCheckIn = $checkInDate &&
    $checkInDate->format("Y-m-d") === $checkIn;

$validCheckOut = $checkOutDate &&
    $checkOutDate->format("Y-m-d") === $checkOut;

if (!$validCheckIn || !$validCheckOut) {
    $error = "Please select valid dates.";
} elseif ($checkIn < $today) {
    $error = "Check-in date cannot be in the past.";
} elseif ($checkOut <= $checkIn) {
    $error = "Check-out date must be after check-in date.";
} else {

    // Find rooms without overlapping active reservations
    $stmt = $conn->prepare("
        SELECT
            rm.room_id,
            rm.room_number,
            rm.room_type,
            rm.rate,
            rm.room_status
        FROM rooms rm
        WHERE rm.room_status = 'Available'
        AND NOT EXISTS (
            SELECT 1
            FROM reservations r
            WHERE r.room_id = rm.room_id
              AND r.reservation_status IN (
                  'Pending',
                  'Confirmed',
                  'Checked-in'
              )
              AND r.check_in < ?
              AND r.check_out > ?
        )
        ORDER BY rm.room_number ASC
    ");

    $stmt->bind_param("ss", $checkOut, $checkIn);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $rooms[] = $row;
    }

    $stmt->close();
}

// Count available rooms by type
$standardCount = 0;
$deluxeCount = 0;
$suiteCount = 0;

foreach ($rooms as $room) {
    if ($room["room_type"] === "Standard") {
        $standardCount++;
    } elseif ($room["room_type"] === "Deluxe") {
        $deluxeCount++;
    } elseif ($room["room_type"] === "Suite") {
        $suiteCount++;
    }
}

$totalAvailable = count($rooms);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Room Availability Checking</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 25px;
            color: #222;
        }

        .container {
            max-width: 1150px;
            margin: auto;
        }

        h1 {
            margin-bottom: 8px;
        }

        .subtitle {
            color: #666;
            margin-bottom: 25px;
        }

        .card {
            background: white;
            padding: 22px;
            border-radius: 8px;
            margin-bottom: 22px;
            box-shadow: 0 2px 8px #00000010;
        }

        .search-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 7px;
        }

        input {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        button {
            padding: 11px 16px;
            background: #172b4d;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }

        .summary-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px #00000010;
        }

        .summary-card h3 {
            font-size: 14px;
            color: #666;
            margin-top: 0;
        }

        .number {
            font-size: 28px;
            font-weight: bold;
            color: #172b4d;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        th, td {
            padding: 13px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        th {
            background: #172b4d;
            color: white;
        }

        .available {
            color: #198754;
            font-weight: bold;
        }

        .error {
            background: #f8d7da;
            color: #842029;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 18px;
        }

        .empty {
            text-align: center;
            padding: 25px;
            color: #777;
        }

        .back {
            display: inline-block;
            margin-top: 5px;
            color: #0d6efd;
            text-decoration: none;
        }

        @media (max-width: 600px) {
            body {
                padding: 12px;
            }

            .search-grid {
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

    <h1>Room Availability Checking</h1>

    <p class="subtitle">
        Search for rooms available during the selected dates.
    </p>

    <div class="card">
        <h2>Search Available Rooms</h2>

        <form method="GET">
            <div class="search-grid">

                <div>
                    <label for="check_in">Check-in Date</label>
                    <input
                        type="date"
                        id="check_in"
                        name="check_in"
                        min="<?= htmlspecialchars($today) ?>"
                        value="<?= htmlspecialchars($checkIn) ?>"
                        required
                    >
                </div>

                <div>
                    <label for="check_out">Check-out Date</label>
                    <input
                        type="date"
                        id="check_out"
                        name="check_out"
                        min="<?= htmlspecialchars($today) ?>"
                        value="<?= htmlspecialchars($checkOut) ?>"
                        required
                    >
                </div>

                <div class="full-width">
                    <button type="submit">
                        Check Availability
                    </button>
                </div>

            </div>
        </form>
    </div>

    <?php if ($error !== ""): ?>

        <div class="error">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php else: ?>

        <div class="summary">

            <div class="summary-card">
                <h3>Total Available Rooms</h3>
                <div class="number"><?= $totalAvailable ?></div>
            </div>

            <div class="summary-card">
                <h3>Standard Rooms</h3>
                <div class="number"><?= $standardCount ?></div>
            </div>

            <div class="summary-card">
                <h3>Deluxe Rooms</h3>
                <div class="number"><?= $deluxeCount ?></div>
            </div>

            <div class="summary-card">
                <h3>Suite Rooms</h3>
                <div class="number"><?= $suiteCount ?></div>
            </div>

        </div>

        <div class="card">
            <h2>Available Room List</h2>

            <p>
                Selected stay:
                <strong><?= htmlspecialchars($checkIn) ?></strong>
                to
                <strong><?= htmlspecialchars($checkOut) ?></strong>
            </p>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Room ID</th>
                            <th>Room Number</th>
                            <th>Room Type</th>
                            <th>Rate per Night</th>
                            <th>Room Status</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if ($totalAvailable > 0): ?>

                        <?php foreach ($rooms as $room): ?>

                            <tr>
                                <td>
                                    <?= (int) $room["room_id"] ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($room["room_number"]) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($room["room_type"]) ?>
                                </td>

                                <td>
                                    ₱<?= number_format(
                                        (float) $room["rate"],
                                        2
                                    ) ?>
                                </td>

                                <td class="available">
                                    <?= htmlspecialchars($room["room_status"]) ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="5" class="empty">
                                No available rooms found for the selected dates.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>

    <a class="back" href="../index.php">
        ← Back to Dashboard
    </a>

</div>
</body>
</html>
