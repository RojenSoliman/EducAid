<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHPMailer Test</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        h2 {
            text-align: center;
        }
        label {
            font-weight: bold;
            margin-top: 10px;
        }
        input, textarea {
            width: 100%;
            padding: 10px;
            margin-top: 5px;
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        button {
            padding: 10px 15px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background-color: #45a049;
        }
        .message {
            text-align: center;
            margin-top: 20px;
            font-size: 18px;
        }
    </style>
</head>
<body>

<div class="container">
    <h2>Send Test Email</h2>
    <form action="send_test_email.php" method="POST">
        <label for="recipient">Recipient Email</label>
        <input type="email" id="recipient" name="recipient" required>

        <label for="subject">Subject</label>
        <input type="text" id="subject" name="subject" required>

        <label for="body">Email Body</label>
        <textarea id="body" name="body" rows="4" required></textarea>

        <button type="submit">Send Email</button>
    </form>

    <?php
    if (isset($_GET['status'])) {
        if ($_GET['status'] == 'success') {
            echo "<div class='message' style='color: green;'>Email sent successfully!</div>";
        } elseif ($_GET['status'] == 'error') {
            echo "<div class='message' style='color: red;'>Failed to send email.</div>";
        }
    }
    ?>
</div>

</body>
</html>
