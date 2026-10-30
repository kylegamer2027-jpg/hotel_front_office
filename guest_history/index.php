
<?php
require_once "../config/database.php";

$search = trim($_GET["search"] ?? "");
$selectedGuestId = (int) ($_GET["guest_id"] ?? 0);

$guests = [];
$guest = null;
$history = null;

// Search guests by name, email, or phone
if ($search !== "") {

    $stmt = $conn->prepare("
        SELECT
            guest_id,
            first_name,
            last_name,
            email,
            phone,
            address,
            id_type,
            id_number,
            created_at
        FROM guests
        WHERE CONCAT(first_name, ' ', last_name) LIKE ?
           OR email LIKE ?
           OR phone LIKE ?
        ORDER BY first_name ASC
    ");

    $searchTerm = "%" . $search . "%";

    $stmt->bind_param(
        "sss",
        $searchTerm,
        $searchTerm,
        $searchTerm
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $guests[] = $row;
    }

    $stmt->close();
}

// Get selected guest information
if ($selectedGuestId > 0) {

    $stmt = $conn->prepare("
        SELECT
            guest_id,
            first_name,
            last_name,
            email,
            phone,
            address,
            id_type,
            id_number,
            created_at
        FROM guests
        WHERE guest_id = ?
    ");

    $stmt->bind_param("i", $selectedGuestId);
    $stmt->execute();

    $guest = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    // Get reservation history of selected guest
    if ($guest) {

        $stmt = $conn->prepare("
            SELECT
                r.reservation_id,
                r.check_in,
                r.check_out,
                r.adults,
                r.children,
                r.booking_source,
                r.reservation_status,
                r.deposit,
                r.created_at,
                rm.room_number,
                rm.room_type,
                rm.rate
            FROM reservations r
            LEFT JOIN rooms rm
                ON r.room_id = rm.room_id
            WHERE r.guest_id = ?
            ORDER BY r.created_at DESC
        ");

        $stmt->bind_param("i", $selectedGuestId);
        $stmt->execute();

        $history = $stmt->get_result();

        $stmt->close();
    }
}

// Count guest reservations
$totalReservations = 0;
$totalDeposits = 0;
$checkedInCount = 0;
$checkedOutCount = 0;
$cancelledCount = 0;
$noShowCount = 0;

if ($history) {

    while ($row = $history->fetch_assoc()) {

        $totalReservations++;
        $totalDeposits += (float) $row["deposit"];

        if ($row["reservation_status"] === "Checked-in") {
            $checkedInCount++;
        }

        if ($row["reservation_status"] === "Checked-out") {
            $checkedOutCount++;
        }

        if ($row["reservation_status"] === "Cancelled") {
            $cancelledCount++;
        }

        if ($row["reservation_status"] === "No-show") {
            $noShowCount++;
        }
    }

    // Reset result pointer for displaying the table
    $history->data_seek(0);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Guest History Management</title>

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
            max-width: 1250px;
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

        .search-form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        input {
            flex: 1;
            min-width: 200px;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        button {
            background: #172b4d;
            color: white;
            border: none;
            padding: 12px 18px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }

        button:hover {
            background: #263f68;
        }

        .guest-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 15px;
        }

        .guest-item {
            border: 1px solid #ddd;
            padding: 16px;
            border-radius: 7px;
        }

        .guest-item h3 {
            margin-top: 0;
        }

        .guest-item p {
            color: #666;
            font-size: 14px;
        }

        .view-link {
            display: inline-block;
            margin-top: 8px;
            color: #0d6efd;
            text-decoration: none;
            font-weight: bold;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
        }

        .profile-item {
            padding: 12px;
            background: #f8f9fa;
            border-radius: 5px;
        }

        .profile-item strong {
            display: block;
            margin-bottom: 6px;
            color: #555;
            font-size: 13px;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 15px;
            margin-bottom: 22px;
        }

        .summary-card {
            background: white;
            padding: 18px;
            border-radius: 8px;
            box-shadow: 0 2px 8px #00000010;
        }

        .summary-card h3 {
            color: #666;
            font-size: 13px;
            margin-top: 0;
        }

        .number {
            font-size: 25px;
            font-weight: bold;
            color: #172b4d;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 950px;
        }

        th, td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        th {
            background: #172b4d;
            color: white;
        }

        .status {
            font-weight: bold;
        }

        .empty {
            text-align: center;
            padding: 22px;
            color: #777;
        }

        .back {
            display: inline-block;
            margin-top: 5px;
            color: #0d6efd;
            text-decoration: none;
        }

        .print-button {
            margin-bottom: 15px;
        }

        @media print {

            body {
                background: white;
                padding: 0;
            }

            .no-print,
            .back,
            .search-card {
                display: none !important;
            }

            .card,
            .summary-card {
                box-shadow: none;
                border: 1px solid #ddd;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <h1>Guest History Management</h1>

    <p class="subtitle">
        Search and review guest profiles and reservation history.
    </p>

    <!-- Search Guest -->

    <div class="card search-card">

        <h2>Search Guest</h2>

        <form method="GET" class="search-form">

            <input
                type="text"
                name="search"
                placeholder="Enter guest name, email, or phone number"
                value="<?= htmlspecialchars($search) ?>"
            >

            <button type="submit">
                Search Guest
            </button>

        </form>

    </div>

    <!-- Search Results -->

    <?php if ($search !== ""): ?>

        <div class="card">

            <h2>Search Results</h2>

            <div class="guest-list">

                <?php if (count($guests) > 0): ?>

                    <?php foreach ($guests as $g): ?>

                        <div class="guest-item">

                            <h3>
                                <?= htmlspecialchars(
                                    $g["first_name"] . " " . $g["last_name"]
                                ) ?>
                            </h3>

                            <p>
                                Guest ID:
                                #<?= (int) $g["guest_id"] ?>
                            </p>

                            <p>
                                Email:
                                <?= htmlspecialchars($g["email"] ?? "N/A") ?>
                            </p>

                            <p>
                                Phone:
                                <?= htmlspecialchars($g["phone"] ?? "N/A") ?>
                            </p>

                            <a
                                class="view-link"
                                href="?guest_id=<?= (int) $g["guest_id"] ?>"
                            >
                                View Guest History →
                            </a>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <p class="empty">
                        No guest records found.
                    </p>

                <?php endif; ?>

            </div>

        </div>

    <?php endif; ?>

    <!-- Selected Guest Profile -->

    <?php if ($selectedGuestId > 0 && $guest): ?>

        <div class="card">

            <h2>Guest Information</h2>

            <div class="profile-grid">

                <div class="profile-item">
                    <strong>Guest ID</strong>
                    #<?= (int) $guest["guest_id"] ?>
                </div>

                <div class="profile-item">
                    <strong>Full Name</strong>
                    <?= htmlspecialchars(
                        $guest["first_name"] . " " . $guest["last_name"]
                    ) ?>
                </div>

                <div class="profile-item">
                    <strong>Email Address</strong>
                    <?= htmlspecialchars($guest["email"] ?? "N/A") ?>
                </div>

                <div class="profile-item">
                    <strong>Phone Number</strong>
                    <?= htmlspecialchars($guest["phone"] ?? "N/A") ?>
                </div>

                <div class="profile-item">
                    <strong>Address</strong>
                    <?= htmlspecialchars($guest["address"] ?? "N/A") ?>
                </div>

                <div class="profile-item">
                    <strong>Identification Type</strong>
                    <?= htmlspecialchars($guest["id_type"] ?? "N/A") ?>
                </div>

                <div class="profile-item">
                    <strong>Identification Number</strong>
                    <?= htmlspecialchars($guest["id_number"] ?? "N/A") ?>
                </div>

                <div class="profile-item">
                    <strong>Date Registered</strong>
                    <?= htmlspecialchars($guest["created_at"]) ?>
                </div>

            </div>

        </div>

        <!-- Guest Summary -->

        <div class="summary">

            <div class="summary-card">
                <h3>Total Reservations</h3>
                <div class="number"><?= $totalReservations ?></div>
            </div>

            <div class="summary-card">
                <h3>Checked-in Reservations</h3>
                <div class="number"><?= $checkedInCount ?></div>
            </div>

            <div class="summary-card">
                <h3>Completed Stays</h3>
                <div class="number"><?= $checkedOutCount ?></div>
            </div>

            <div class="summary-card">
                <h3>Cancelled Reservations</h3>
                <div class="number"><?= $cancelledCount ?></div>
            </div>

            <div class="summary-card">
                <h3>No-show Reservations</h3>
                <div class="number"><?= $noShowCount ?></div>
            </div>

            <div class="summary-card">
                <h3>Total Recorded Deposits</h3>
                <div class="number">
                    ₱<?= number_format($totalDeposits, 2) ?>
                </div>
            </div>

        </div>

        <!-- Reservation History -->

        <div class="card">

            <h2>Reservation History</h2>

            <button
                class="print-button no-print"
                onclick="window.print()"
            >
                Print Guest History
            </button>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Reservation ID</th>
                            <th>Room Number</th>
                            <th>Room Type</th>
                            <th>Check-in</th>
                            <th>Check-out</th>
                            <th>Adults</th>
                            <th>Children</th>
                            <th>Booking Source</th>
                            <th>Deposit</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if ($history && $history->num_rows > 0): ?>

                        <?php while ($r = $history->fetch_assoc()): ?>

                            <tr>

                                <td>
                                    #<?= (int) $r["reservation_id"] ?>
                                </td>

                                <td>
                                    <?= $r["room_number"]
                                        ? htmlspecialchars($r["room_number"])
                                        : "Not Assigned" ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($r["room_type"] ?? "N/A") ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($r["check_in"]) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($r["check_out"]) ?>
                                </td>

                                <td>
                                    <?= (int) $r["adults"] ?>
                                </td>

                                <td>
                                    <?= (int) $r["children"] ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($r["booking_source"]) ?>
                                </td>

                                <td>
                                    ₱<?= number_format((float) $r["deposit"], 2) ?>
                                </td>

                                <td class="status">
                                    <?= htmlspecialchars($r["reservation_status"]) ?>
                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="10" class="empty">
                                This guest has no reservation history yet.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    <?php elseif ($selectedGuestId > 0 && !$guest): ?>

        <div class="card">
            <p class="empty">Guest record not found.</p>
        </div>

    <?php endif; ?>

    <a class="back" href="../index.php">
        ← Back to Dashboard
    </a>

</div>

</body>
</html>
