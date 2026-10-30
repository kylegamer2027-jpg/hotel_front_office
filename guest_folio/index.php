
<?php
include "../config/database.php";

$message = "";
$messageType = "";

// Get selected reservation
$selectedReservation = isset($_GET['reservation_id'])
    ? intval($_GET['reservation_id'])
    : 0;

// Add a charge or payment
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_transaction"])) {

    $reservation_id = intval($_POST["reservation_id"]);
    $transaction_type = $_POST["transaction_type"];
    $description = trim($_POST["description"]);
    $amount = floatval($_POST["amount"]);

    if (
        $reservation_id <= 0 ||
        !in_array($transaction_type, ["Charge", "Payment"], true) ||
        $description === "" ||
        $amount <= 0
    ) {
        $message = "Please enter valid transaction details.";
        $messageType = "error";
    } else {

        // Check whether the reservation exists
        $check = $conn->prepare(
            "SELECT reservation_id, deposit
             FROM reservations
             WHERE reservation_id = ?"
        );

        $check->bind_param("i", $reservation_id);
        $check->execute();
        $reservationResult = $check->get_result();
        $reservation = $reservationResult->fetch_assoc();
        $check->close();

        if (!$reservation) {
            $message = "Reservation not found.";
            $messageType = "error";
        } else {

            // Calculate current balance
            $summary = $conn->prepare(
                "SELECT
                    COALESCE(SUM(CASE
                        WHEN transaction_type = 'Charge'
                        THEN amount ELSE 0 END), 0) AS total_charges,
                    COALESCE(SUM(CASE
                        WHEN transaction_type = 'Payment'
                        THEN amount ELSE 0 END), 0) AS total_payments
                 FROM guest_folio
                 WHERE reservation_id = ?"
            );

            $summary->bind_param("i", $reservation_id);
            $summary->execute();
            $totals = $summary->get_result()->fetch_assoc();
            $summary->close();

            $charges = floatval($totals["total_charges"]);
            $payments = floatval($totals["total_payments"]);
            $deposit = floatval($reservation["deposit"]);
            $balance = $charges - $payments - $deposit;

            if (
                $transaction_type === "Payment" &&
                $amount > max(0, $balance)
            ) {
                $message = "Payment cannot exceed the remaining balance.";
                $messageType = "error";
            } else {

                $stmt = $conn->prepare(
                    "INSERT INTO guest_folio
                    (reservation_id, transaction_type, description, amount)
                    VALUES (?, ?, ?, ?)"
                );

                $stmt->bind_param(
                    "issd",
                    $reservation_id,
                    $transaction_type,
                    $description,
                    $amount
                );

                if ($stmt->execute()) {
                    header(
                        "Location: index.php?reservation_id=" .
                        $reservation_id . "&success=1"
                    );
                    exit;
                } else {
                    $message = "Unable to save transaction.";
                    $messageType = "error";
                }

                $stmt->close();
            }
        }
    }

    $selectedReservation = $reservation_id;
}

// Success message
if (isset($_GET["success"])) {
    $message = "Transaction successfully recorded!";
    $messageType = "success";
}

// Reservation list
$reservations = $conn->query(
    "SELECT
        r.reservation_id,
        r.check_in,
        r.check_out,
        r.reservation_status,
        g.first_name,
        g.last_name,
        rm.room_number
     FROM reservations r
     JOIN guests g ON r.guest_id = g.guest_id
     LEFT JOIN rooms rm ON r.room_id = rm.room_id
     ORDER BY r.reservation_id DESC"
);

// Selected reservation details
$selected = null;
$transactions = null;

$totalCharges = 0;
$totalPayments = 0;
$deposit = 0;
$balance = 0;

if ($selectedReservation > 0) {

    $stmt = $conn->prepare(
        "SELECT
            r.reservation_id,
            r.check_in,
            r.check_out,
            r.reservation_status,
            r.deposit,
            g.first_name,
            g.last_name,
            rm.room_number
         FROM reservations r
         JOIN guests g ON r.guest_id = g.guest_id
         LEFT JOIN rooms rm ON r.room_id = rm.room_id
         WHERE r.reservation_id = ?"
    );

    $stmt->bind_param("i", $selectedReservation);
    $stmt->execute();
    $selected = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($selected) {

        $deposit = floatval($selected["deposit"]);

        $stmt = $conn->prepare(
            "SELECT
                COALESCE(SUM(CASE
                    WHEN transaction_type = 'Charge'
                    THEN amount ELSE 0 END), 0) AS total_charges,
                COALESCE(SUM(CASE
                    WHEN transaction_type = 'Payment'
                    THEN amount ELSE 0 END), 0) AS total_payments
             FROM guest_folio
             WHERE reservation_id = ?"
        );

        $stmt->bind_param("i", $selectedReservation);
        $stmt->execute();
        $totals = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $totalCharges = floatval($totals["total_charges"]);
        $totalPayments = floatval($totals["total_payments"]);
        $balance = $totalCharges - $totalPayments - $deposit;

        $stmt = $conn->prepare(
            "SELECT *
             FROM guest_folio
             WHERE reservation_id = ?
             ORDER BY transaction_date DESC, folio_id DESC"
        );

        $stmt->bind_param("i", $selectedReservation);
        $stmt->execute();
        $transactions = $stmt->get_result();
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Guest Folio Management</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 25px;
        }

        .container {
            max-width: 1200px;
            margin: auto;
        }

        h1 {
            color: #263445;
        }

        .card {
            background: white;
            padding: 22px;
            border-radius: 8px;
            margin-bottom: 22px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        input, select, button {
            padding: 10px;
            margin: 6px 0;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box;
        }

        input, select {
            width: 100%;
        }

        button {
            background: #263f5b;
            color: white;
            border: none;
            cursor: pointer;
            padding: 11px 18px;
        }

        button:hover {
            background: #172a40;
        }

        .success {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 15px;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 15px;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin: 18px 0;
        }

        .summary-box {
            background: #eef3f8;
            padding: 18px;
            border-radius: 7px;
        }

        .summary-box h3 {
            margin: 0 0 8px;
            font-size: 14px;
            color: #555;
        }

        .summary-box p {
            margin: 0;
            font-size: 22px;
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th, td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #263f5b;
            color: white;
        }

        .select-link {
            color: #1769aa;
            text-decoration: none;
            font-weight: bold;
        }

        .negative {
            color: #b42318;
        }

        .positive {
            color: #167344;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }

        @media print {
            .no-print {
                display: none;
            }

            body {
                background: white;
                padding: 0;
            }

            .card {
                box-shadow: none;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <h1>Guest Folio Management</h1>
    <p>Manage guest charges, payments, deposits, and outstanding balances.</p>

    <?php if ($message !== ""): ?>
        <div class="<?php echo htmlspecialchars($messageType); ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="card no-print">
        <h2>Select Reservation</h2>

        <form method="GET">
            <select name="reservation_id" required>
                <option value="">Choose a reservation</option>

                <?php while ($row = $reservations->fetch_assoc()): ?>
                    <option
                        value="<?php echo $row['reservation_id']; ?>"
                        <?php echo ($selectedReservation == $row['reservation_id']) ? 'selected' : ''; ?>
                    >
                        <?php
                        echo "Reservation #" . $row["reservation_id"] .
                             " - " . htmlspecialchars(
                                 $row["first_name"] . " " . $row["last_name"]
                             ) .
                             " - Room " . htmlspecialchars(
                                 $row["room_number"] ?? "Not assigned"
                             ) .
                             " (" . htmlspecialchars($row["reservation_status"]) . ")";
                        ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <button type="submit">View Guest Folio</button>
        </form>
    </div>

    <?php if ($selected): ?>

        <div class="card">
            <h2>Guest Information</h2>

            <p>
                <strong>Reservation ID:</strong>
                <?php echo $selected["reservation_id"]; ?>
            </p>

            <p>
                <strong>Guest Name:</strong>
                <?php echo htmlspecialchars(
                    $selected["first_name"] . " " . $selected["last_name"]
                ); ?>
            </p>

            <p>
                <strong>Room Number:</strong>
                <?php echo htmlspecialchars($selected["room_number"] ?? "Not assigned"); ?>
            </p>

            <p>
                <strong>Check-in:</strong>
                <?php echo htmlspecialchars($selected["check_in"]); ?>
            </p>

            <p>
                <strong>Check-out:</strong>
                <?php echo htmlspecialchars($selected["check_out"]); ?>
            </p>

            <p>
                <strong>Status:</strong>
                <?php echo htmlspecialchars($selected["reservation_status"]); ?>
            </p>

            <div class="summary">

                <div class="summary-box">
                    <h3>Total Charges</h3>
                    <p>₱<?php echo number_format($totalCharges, 2); ?></p>
                </div>

                <div class="summary-box">
                    <h3>Payments</h3>
                    <p>₱<?php echo number_format($totalPayments, 2); ?></p>
                </div>

                <div class="summary-box">
                    <h3>Reservation Deposit</h3>
                    <p>₱<?php echo number_format($deposit, 2); ?></p>
                </div>

                <div class="summary-box">
                    <h3>Remaining Balance</h3>
                    <p class="<?php echo $balance > 0 ? 'negative' : 'positive'; ?>">
                        ₱<?php echo number_format($balance, 2); ?>
                    </p>
                </div>

            </div>
        </div>

        <div class="card no-print">
            <h2>Add Folio Transaction</h2>

            <form method="POST">
                <input
                    type="hidden"
                    name="reservation_id"
                    value="<?php echo $selectedReservation; ?>"
                >

                <div class="form-grid">

                    <div>
                        <label>Transaction Type</label>
                        <select name="transaction_type" required>
                            <option value="Charge">Charge</option>
                            <option value="Payment">Payment</option>
                        </select>
                    </div>

                    <div>
                        <label>Description</label>
                        <input
                            type="text"
                            name="description"
                            placeholder="Example: Laundry service"
                            maxlength="255"
                            required
                        >
                    </div>

                    <div>
                        <label>Amount (₱)</label>
                        <input
                            type="number"
                            name="amount"
                            min="0.01"
                            step="0.01"
                            required
                        >
                    </div>

                </div>

                <button type="submit" name="add_transaction">
                    Save Transaction
                </button>
            </form>
        </div>

        <div class="card">
            <h2>Folio Transaction History</h2>

            <table>
                <thead>
                    <tr>
                        <th>Folio ID</th>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Amount</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($transactions && $transactions->num_rows > 0): ?>

                    <?php while ($transaction = $transactions->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $transaction["folio_id"]; ?></td>

                            <td>
                                <?php echo htmlspecialchars($transaction["transaction_date"]); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars($transaction["transaction_type"]); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars($transaction["description"]); ?>
                            </td>

                            <td>
                                ₱<?php echo number_format($transaction["amount"], 2); ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="5">No transactions recorded yet.</td>
                    </tr>

                <?php endif; ?>

                </tbody>
            </table>

            <br>

            <button class="no-print" onclick="window.print()">
                Print Guest Folio
            </button>
        </div>

    <?php elseif ($selectedReservation > 0): ?>

        <div class="card">
            Reservation not found.
        </div>

    <?php endif; ?>

</div>

</body>
</html>
