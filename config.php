<?php
$host = "localhost";
$db_user = "root"; // লোকাল সার্ভারের ডিফল্ট ইউজারনেম
$db_pass = ""; // লোকাল সার্ভারে সাধারণত পাসওয়ার্ড খালি থাকে
$db_name = "demo_crm"; // আপনার তৈরি করা ডেটাবেসের নাম

// ডেটাবেসের সাথে কানেকশন তৈরি
$conn = mysqli_connect($host, $db_user, $db_pass, $db_name);

// কানেকশন ঠিকমতো হয়েছে কিনা তা চেক করা
if (!$conn) {
    die("ডেটাবেস কানেকশন ফেইল করেছে: " . mysqli_connect_error());
}

// চেক করার জন্য সাময়িকভাবে নিচের লাইনটি আনকমেন্ট করতে পারেন
// echo "ডেটাবেস সফলভাবে কানেক্ট হয়েছে!";

// ================================================================
// GLOBAL SESSION FIX — সব page এ username নিশ্চিত করা
// session এ username না থাকলে DB থেকে নিয়ে session এ রাখো
// config.php সব page এ include হয়, তাই এটা সব জায়গায় কাজ করবে
// ================================================================
if (isset($_SESSION['user_id']) && empty($_SESSION['username'])) {
    $uid  = intval($_SESSION['user_id']);
    $uRes = mysqli_query($conn, "SELECT username FROM users WHERE id=$uid LIMIT 1");
    $uRow = $uRes ? mysqli_fetch_assoc($uRes) : null;
    if ($uRow) $_SESSION['username'] = $uRow['username'];
}
?>