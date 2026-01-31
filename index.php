<?php
// =======================================================
// Telegram Shein Coupon Selling Bot (PHP, Webhook)
// Supabase PostgreSQL + UPI QR + UTR + Admin Approval
// Force-Join: ONLY 1 channel (ENV: FORCE_CHANNEL)
// =======================================================

date_default_timezone_set(getenv('TIMEZONE') ?: 'Asia/Kolkata');

$BOT_TOKEN = getenv("BOT_TOKEN");
$ADMIN_ID  = getenv("ADMIN_ID");

$DB_HOST = getenv("DB_HOST");
$DB_PORT = getenv("DB_PORT") ?: "5432";
$DB_NAME = getenv("DB_NAME");
$DB_USER = getenv("DB_USER");
$DB_PASS = getenv("DB_PASS");

// Only 1 force join channel (with @)
$FORCE_CHANNEL = trim(getenv("FORCE_CHANNEL") ?: "");
if ($FORCE_CHANNEL !== "" && $FORCE_CHANNEL[0] !== "@") $FORCE_CHANNEL = "@".$FORCE_CHANNEL;

if (!$BOT_TOKEN || !$ADMIN_ID || !$DB_HOST || !$DB_NAME || !$DB_USER || !$DB_PASS) {
  http_response_code(200);
  echo "Missing env vars";
  exit;
}
$ADMIN_ID = (int)$ADMIN_ID;

// ---------- DB ----------
try {
  $pdo = new PDO(
    "pgsql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME}",
    $DB_USER,
    $DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
} catch (Throwable $e) {
  http_response_code(200);
  echo "DB error";
  exit;
}

// ---------- Telegram Helpers ----------
function tg($method, $data) {
  global $BOT_TOKEN;
  $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);
  $res = curl_exec($ch);
  curl_close($ch);
  return $res ? json_decode($res, true) : null;
}

function answerCallback($id, $text="", $alert=false) {
  tg("answerCallbackQuery", [
    "callback_query_id"=>$id,
    "text"=>$text,
    "show_alert"=>$alert ? "true" : "false"
  ]);
}

function sendMsg($chat_id, $text, $reply_markup=null, $parse_mode="HTML") {
  $data = [
    "chat_id"=>$chat_id,
    "text"=>$text,
    "parse_mode"=>$parse_mode,
    "disable_web_page_preview"=>"true"
  ];
  if ($reply_markup) $data["reply_markup"] = json_encode($reply_markup);
  return tg("sendMessage", $data);
}

function editMsg($chat_id, $message_id, $text, $reply_markup=null, $parse_mode="HTML") {
  $data = [
    "chat_id"=>$chat_id,
    "message_id"=>$message_id,
    "text"=>$text,
    "parse_mode"=>$parse_mode,
    "disable_web_page_preview"=>"true"
  ];
  if ($reply_markup) $data["reply_markup"] = json_encode($reply_markup);
  return tg("editMessageText", $data);
}

function sendPhotoFileId($chat_id, $file_id, $caption="", $reply_markup=null) {
  $data = [
    "chat_id"=>$chat_id,
    "photo"=>$file_id,
    "caption"=>$caption,
    "parse_mode"=>"HTML"
  ];
  if ($reply_markup) $data["reply_markup"] = json_encode($reply_markup);
  return tg("sendPhoto", $data);
}

// ---------- Force Join (1 channel) ----------
function isJoinedRequiredChannel($user_id) {
  global $FORCE_CHANNEL;

  if ($FORCE_CHANNEL === "") return true; // not configured, do not block

  $res = tg("getChatMember", [
    "chat_id"=>$FORCE_CHANNEL,
    "user_id"=>$user_id
  ]);

  if (!$res || !$res["ok"]) return false;

  $status = $res["result"]["status"] ?? "left";
  return in_array($status, ["member","administrator","creator"], true);
}

function joinKeyboard() {
  global $FORCE_CHANNEL;
  $u = str_replace("@","",$FORCE_CHANNEL);
  return ["inline_keyboard"=>[
    [[ "text"=>"📢 Join Channel", "url"=>"https://t.me/".$u ]],
    [[ "text"=>"✅ Check Join", "callback_data"=>"check_join" ]]
  ]];
}

function sendJoinMessage($chat_id) {
  sendMsg(
    $chat_id,
    "🚫 <b>Join Required</b>\n\n".
    "To use this bot, you must join our channel.\n".
    "After joining, click <b>Check Join</b> ✅",
    joinKeyboard()
  );
}

function requireJoinOrExit($user_id, $chat_id, $cid=null) {
  global $ADMIN_ID;
  if ($user_id === $ADMIN_ID) return; // admin bypass
  if (!isJoinedRequiredChannel($user_id)) {
    if ($cid) answerCallback($cid, "Join channel first!", true);
    sendJoinMessage($chat_id);
    http_response_code(200); exit;
  }
}

// ---------- Settings ----------
function getSetting($key) {
  global $pdo;
  $st = $pdo->prepare("SELECT value FROM settings WHERE key=?");
  $st->execute([$key]);
  $r = $st->fetch();
  return $r ? $r["value"] : null;
}
function setSetting($key, $value) {
  global $pdo;
  $st = $pdo->prepare("
    INSERT INTO settings (key,value) VALUES (?,?)
    ON CONFLICT (key) DO UPDATE SET value=EXCLUDED.value
  ");
  $st->execute([$key,$value]);
}

// ---------- Admin session ----------
function adminSetStep($admin_id, $step=null, $product_id=null) {
  global $pdo;
  $st = $pdo->prepare("
    INSERT INTO admin_sessions (admin_id, step, product_id) VALUES (?,?,?)
    ON CONFLICT (admin_id) DO UPDATE SET step=EXCLUDED.step, product_id=EXCLUDED.product_id
  ");
  $st->execute([$admin_id, $step, $product_id]);
}
function adminGetSession($admin_id) {
  global $pdo;
  $st = $pdo->prepare("SELECT step, product_id FROM admin_sessions WHERE admin_id=?");
  $st->execute([$admin_id]);
  return $st->fetch() ?: ["step"=>null,"product_id"=>null];
}

// ---------- Users ----------
function upsertUser($user) {
  global $pdo;
  $uid = (int)$user["id"];
  $username = $user["username"] ?? null;
  $first = $user["first_name"] ?? null;

  $st = $pdo->prepare("
    INSERT INTO users (user_id, username, first_name) VALUES (?,?,?)
    ON CONFLICT (user_id) DO UPDATE SET username=EXCLUDED.username, first_name=EXCLUDED.first_name
  ");
  $st->execute([$uid, $username, $first]);
}

function formatUserLine($user_id) {
  global $pdo;
  $st = $pdo->prepare("SELECT username, first_name FROM users WHERE user_id=?");
  $st->execute([$user_id]);
  $u = $st->fetch();
  if (!$u) return "UserID: <code>{$user_id}</code>";
  $name = $u["first_name"] ? htmlspecialchars($u["first_name"]) : "User";
  $uname = $u["username"] ? "@".htmlspecialchars($u["username"]) : "(no username)";
  return "{$name} {$uname}\nUserID: <code>{$user_id}</code>";
}

// ---------- Products / Stock ----------
function getActiveProducts() {
  global $pdo;
  return $pdo->query("SELECT id, name, price_inr FROM products WHERE is_active=TRUE ORDER BY id ASC")->fetchAll();
}
function getProduct($id) {
  global $pdo;
  $st = $pdo->prepare("SELECT id, name, price_inr FROM products WHERE id=?");
  $st->execute([$id]);
  return $st->fetch();
}
function getStock($product_id) {
  global $pdo;
  $st = $pdo->prepare("SELECT COUNT(*) AS c FROM coupons WHERE product_id=? AND status='AVAILABLE'");
  $st->execute([$product_id]);
  return (int)$st->fetch()["c"];
}

// ---------- UI ----------
function mainMenuKeyboard() {
  $prods = getActiveProducts();
  $rows = [];
  foreach ($prods as $p) {
    $rows[] = [[ "text"=>$p["name"], "callback_data"=>"buy:".$p["id"] ]];
  }
  $rows[] = [[ "text"=>"📦 My Orders", "callback_data"=>"myorders" ]];
  return ["inline_keyboard"=>$rows];
}

function adminMenuKeyboard() {
  return ["inline_keyboard"=>[
    [
      ["text"=>"➕ Add Coupons","callback_data"=>"admin:addcoupons"],
      ["text"=>"📦 Stock","callback_data"=>"admin:stock"]
    ],
    [
      ["text"=>"🧾 Recent Orders","callback_data"=>"admin:orders"],
      ["text"=>"🖼 Upload QR","callback_data"=>"admin:uploadqr"]
    ],
    [
      ["text"=>"💰 Edit Prices","callback_data"=>"admin:editprices"]
    ]
  ]];
}

// ---------- Orders ----------
function createOrder($user_id, $product_id, $amount_inr) {
  global $pdo;
  $st = $pdo->prepare("
    INSERT INTO orders (user_id, product_id, amount_inr, status)
    VALUES (?,?,?, 'WAITING_UTR')
    RETURNING id
  ");
  $st->execute([$user_id,$product_id,$amount_inr]);
  return (int)$st->fetch()["id"];
}

function setOrderUTRAndPending($order_id, $utr) {
  global $pdo;
  $st = $pdo->prepare("
    UPDATE orders
    SET utr=?, status='PENDING_ADMIN'
    WHERE id=? AND status='WAITING_UTR'
  ");
  $st->execute([$utr,$order_id]);
  return $st->rowCount() > 0;
}

function getOrder($order_id) {
  global $pdo;
  $st = $pdo->prepare("SELECT * FROM orders WHERE id=?");
  $st->execute([$order_id]);
  return $st->fetch();
}

function deliverCouponForOrder($order_id) {
  global $pdo;

  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("SELECT * FROM orders WHERE id=? FOR UPDATE");
    $st->execute([$order_id]);
    $order = $st->fetch();
    if (!$order) { $pdo->rollBack(); return ["ok"=>false,"err"=>"Order not found"]; }
    if ($order["status"] === "DELIVERED") { $pdo->rollBack(); return ["ok"=>false,"err"=>"Already delivered"]; }
    if ($order["status"] !== "PENDING_ADMIN") { $pdo->rollBack(); return ["ok"=>false,"err"=>"Order not pending"]; }

    $st = $pdo->prepare("
      SELECT id, code FROM coupons
      WHERE product_id=? AND status='AVAILABLE'
      ORDER BY id ASC
      LIMIT 1
      FOR UPDATE
    ");
    $st->execute([(int)$order["product_id"]]);
    $coupon = $st->fetch();
    if (!$coupon) { $pdo->rollBack(); return ["ok"=>false,"err"=>"No stock available"]; }

    $st = $pdo->prepare("
      UPDATE coupons
      SET status='SOLD', sold_to_user_id=?, sold_order_id=?, sold_at=NOW()
      WHERE id=?
    ");
    $st->execute([(int)$order["user_id"], (int)$order_id, (int)$coupon["id"]]);

    $st = $pdo->prepare("
      UPDATE orders
      SET status='DELIVERED', delivered_coupon_id=?
      WHERE id=?
    ");
    $st->execute([(int)$coupon["id"], (int)$order_id]);

    $pdo->commit();
    return ["ok"=>true,"coupon_code"=>$coupon["code"],"coupon_id"=>$coupon["id"],"order"=>$order];
  } catch (Throwable $e) {
    $pdo->rollBack();
    return ["ok"=>false,"err"=>"DB error"];
  }
}

function rejectOrder($order_id, $note="Payment not received") {
  global $pdo;
  $st = $pdo->prepare("
    UPDATE orders
    SET status='REJECTED', admin_note=?
    WHERE id=? AND status='PENDING_ADMIN'
  ");
  $st->execute([$note,$order_id]);
  return $st->rowCount() > 0;
}

// ===================== WEBHOOK INPUT =====================
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) { http_response_code(200); exit; }

// ===================== MESSAGE HANDLER =====================
if (isset($update["message"])) {
  $msg = $update["message"];
  $chat_id = (int)$msg["chat"]["id"];
  $text = isset($msg["text"]) ? trim($msg["text"]) : null;

  if (isset($msg["from"])) upsertUser($msg["from"]);
  $from_id = (int)($msg["from"]["id"] ?? 0);

  // Admin uploads QR photo
  if (isset($msg["photo"]) && $from_id === $ADMIN_ID) {
    $sess = adminGetSession($from_id);
    if ($sess["step"] === "WAIT_QR_PHOTO") {
      $photos = $msg["photo"];
      $best = end($photos);
      $file_id = $best["file_id"];
      setSetting("QR_FILE_ID", $file_id);
      adminSetStep($from_id, null, null);
      sendMsg($chat_id, "✅ QR saved successfully.");
      http_response_code(200); exit;
    }
  }

  // /start
  if ($text === "/start") {
    requireJoinOrExit($from_id, $chat_id);

    sendMsg(
      $chat_id,
      "🎉 <b>Welcome to Shein Coupon Store</b>\n\nChoose a coupon to buy:",
      mainMenuKeyboard()
    );
    http_response_code(200); exit;
  }

  // /admin
  if ($text === "/admin" && $from_id === $ADMIN_ID) {
    sendMsg($chat_id, "🔐 <b>Admin Panel</b>", adminMenuKeyboard());
    http_response_code(200); exit;
  }

  // Admin steps
  if ($from_id === $ADMIN_ID) {
    $sess = adminGetSession($from_id);

    // Add coupons step
    if ($sess["step"] === "WAIT_COUPON_CODES" && $text) {
      $pid = (int)$sess["product_id"];

      $lines = preg_split("/\r\n|\n|\r/", $text);
      $codes = [];
      foreach ($lines as $ln) {
        $c = trim($ln);
        if ($c !== "") $codes[] = $c;
      }
      if (!$codes) { sendMsg($chat_id, "❌ Paste codes (one per line)."); http_response_code(200); exit; }

      $added=0; $skipped=0;
      $st = $pdo->prepare("
        INSERT INTO coupons (product_id, code, status, added_by_admin_id)
        VALUES (?,?, 'AVAILABLE', ?)
        ON CONFLICT (product_id, code) DO NOTHING
      ");

      foreach ($codes as $c) {
        $st->execute([$pid, $c, $from_id]);
        if ($st->rowCount() > 0) $added++; else $skipped++;
      }

      adminSetStep($from_id, null, null);
      $p = getProduct($pid);

      sendMsg(
        $chat_id,
        "✅ Added: <b>{$added}</b>\n⚠️ Duplicates skipped: <b>{$skipped}</b>\n\n".
        "Product: <b>".htmlspecialchars($p["name"])."</b>\n".
        "Stock now: <b>".getStock($pid)."</b>",
        adminMenuKeyboard()
      );
      http_response_code(200); exit;
    }

    // Edit price step
    if ($sess["step"] === "WAIT_PRICE_UPDATE" && $text) {
      $pid = (int)$sess["product_id"];
      if (!preg_match('/^\d{1,6}$/', $text)) {
        sendMsg($chat_id, "❌ Send only numbers. Example: <code>99</code>");
        http_response_code(200); exit;
      }

      $newPrice = (int)$text;
      $st = $pdo->prepare("UPDATE products SET price_inr=? WHERE id=?");
      $st->execute([$newPrice, $pid]);

      adminSetStep($from_id, null, null);
      $p = getProduct($pid);

      sendMsg(
        $chat_id,
        "✅ Price updated!\n\n<b>".htmlspecialchars($p["name"])."</b>\nNew price: <b>₹{$newPrice}</b>",
        adminMenuKeyboard()
      );
      http_response_code(200); exit;
    }
  }

  // User sends UTR
  if ($text && $from_id !== $ADMIN_ID) {
    requireJoinOrExit($from_id, $chat_id);

    $key = "PENDING_UTR_ORDER_".$from_id;
    $pending = getSetting($key);
    if ($pending) {
      $order_id = (int)$pending;
      $utr = preg_replace('/\s+/', '', $text);

      if (!preg_match('/^[A-Za-z0-9]{6,64}$/', $utr)) {
        sendMsg($chat_id, "❌ Invalid UTR. Paste a valid UTR (no spaces).");
        http_response_code(200); exit;
      }

      if (!setOrderUTRAndPending($order_id, $utr)) {
        setSetting($key, null);
        sendMsg($chat_id, "❌ Order expired. Send /start again.");
        http_response_code(200); exit;
      }

      setSetting($key, null);

      $order = getOrder($order_id);
      $product = getProduct((int)$order["product_id"]);

      $adminText =
        "🧾 <b>Payment Submitted</b>\n\n".
        formatUserLine((int)$order["user_id"])."\n\n".
        "OrderID: <code>{$order_id}</code>\n".
        "Product: <b>".htmlspecialchars($product["name"])."</b>\n".
        "Amount: <b>₹{$order["amount_inr"]}</b>\n".
        "UTR: <code>".htmlspecialchars($utr)."</code>";

      $kb = ["inline_keyboard"=>[[
        ["text"=>"✅ Approve","callback_data"=>"admin:approve:{$order_id}"],
        ["text"=>"❌ Reject","callback_data"=>"admin:reject:{$order_id}"]
      ]]];

      sendMsg($ADMIN_ID, $adminText, $kb);
      sendMsg($chat_id, "✅ UTR received! Admin will verify and then you will get the coupon.");
      http_response_code(200); exit;
    }
  }

  http_response_code(200);
  exit;
}

// ===================== CALLBACK HANDLER =====================
if (isset($update["callback_query"])) {
  $cq = $update["callback_query"];
  $data = $cq["data"];
  $cid  = $cq["id"];
  $from = $cq["from"];
  $user_id = (int)$from["id"];
  $chat_id = (int)$cq["message"]["chat"]["id"];
  $message_id = (int)$cq["message"]["message_id"];

  upsertUser($from);

  // Check join button
  if ($data === "check_join") {
    if ($user_id !== $ADMIN_ID && !isJoinedRequiredChannel($user_id)) {
      answerCallback($cid, "❌ Not joined yet!", true);
      http_response_code(200); exit;
    }
    editMsg($chat_id, $message_id, "✅ <b>Verified!</b>\n\nChoose a coupon:", mainMenuKeyboard());
    answerCallback($cid, "Verified");
    http_response_code(200); exit;
  }

  // Force join block (for user actions)
  if ($user_id !== $ADMIN_ID && !isJoinedRequiredChannel($user_id)) {
    answerCallback($cid, "Join channel first!", true);
    sendJoinMessage($chat_id);
    http_response_code(200); exit;
  }

  // My Orders
  if ($data === "myorders") {
    $st = $pdo->prepare("
      SELECT o.id, o.status, o.amount_inr, o.created_at, p.name
      FROM orders o JOIN products p ON p.id=o.product_id
      WHERE o.user_id=? ORDER BY o.id DESC LIMIT 10
    ");
    $st->execute([$user_id]);
    $rows = $st->fetchAll();

    if (!$rows) {
      editMsg($chat_id, $message_id, "📦 <b>Your Orders</b>\n\nNo orders yet.", mainMenuKeyboard());
      answerCallback($cid, "No orders");
      http_response_code(200); exit;
    }

    $t = "📦 <b>Your Orders</b>\n\n";
    foreach ($rows as $r) {
      $t .= "#<code>{$r["id"]}</code> — <b>".htmlspecialchars($r["name"])."</b>\n";
      $t .= "Status: <b>{$r["status"]}</b> | ₹{$r["amount_inr"]}\n";
      $t .= "Date: {$r["created_at"]}\n\n";
    }
    editMsg($chat_id, $message_id, $t, mainMenuKeyboard());
    answerCallback($cid, "Shown");
    http_response_code(200); exit;
  }

  // Buy
  if (strpos($data, "buy:") === 0) {
    $pid = (int)substr($data, 4);
    $p = getProduct($pid);
    if (!$p) { answerCallback($cid, "Not found"); http_response_code(200); exit; }

    $stock = getStock($pid);
    $price = (int)$p["price_inr"];

    $t = "🛒 <b>".htmlspecialchars($p["name"])."</b>\n\nPrice: <b>₹{$price}</b>\nStock: <b>{$stock}</b>\n\nClick Pay:";
    $kb = ["inline_keyboard"=>[[
      ["text"=>"💳 Pay","callback_data"=>"pay:{$pid}"],
      ["text"=>"⬅ Back","callback_data"=>"back:main"]
    ]]];

    editMsg($chat_id, $message_id, $t, $kb);
    answerCallback($cid, "Opened");
    http_response_code(200); exit;
  }

  if ($data === "back:main") {
    editMsg($chat_id, $message_id, "🎉 <b>Welcome</b>\n\nChoose a coupon:", mainMenuKeyboard());
    answerCallback($cid, "Back");
    http_response_code(200); exit;
  }

  // Pay -> Send QR
  if (strpos($data, "pay:") === 0) {
    $pid = (int)substr($data, 4);
    $p = getProduct($pid);
    if (!$p) { answerCallback($cid, "Not found"); http_response_code(200); exit; }

    if (getStock($pid) <= 0) {
      answerCallback($cid, "Out of stock", true);
      http_response_code(200); exit;
    }

    $amount = (int)$p["price_inr"];
    $order_id = createOrder($user_id, $pid, $amount);

    $qrFileId = getSetting("QR_FILE_ID");
    if (!$qrFileId) {
      sendMsg($chat_id, "⚠️ QR not set yet. Contact admin.\nOrderID: <code>{$order_id}</code>");
      sendMsg($ADMIN_ID, "⚠️ QR not set. A user tried to pay.\nOrderID: <code>{$order_id}</code>");
      answerCallback($cid, "QR missing", true);
      http_response_code(200); exit;
    }

    $caption =
      "💰 <b>Payment Instructions</b>\n\n".
      "Product: <b>".htmlspecialchars($p["name"])."</b>\n".
      "Amount: <b>₹{$amount}</b>\n\n".
      "1) Scan QR and pay\n".
      "2) Click <b>I Have Paid</b>\n\n".
      "OrderID: <code>{$order_id}</code>";

    $kb = ["inline_keyboard"=>[[
      ["text"=>"✅ I Have Paid","callback_data"=>"paid:{$order_id}"],
      ["text"=>"⬅ Back","callback_data"=>"back:main"]
    ]]];

    sendPhotoFileId($chat_id, $qrFileId, $caption, $kb);
    answerCallback($cid, "QR sent");
    http_response_code(200); exit;
  }

  // Paid -> Ask UTR
  if (strpos($data, "paid:") === 0) {
    $order_id = (int)substr($data, 5);
    $order = getOrder($order_id);
    if (!$order || (int)$order["user_id"] !== $user_id) {
      answerCallback($cid, "Invalid order", true);
      http_response_code(200); exit;
    }

    setSetting("PENDING_UTR_ORDER_".$user_id, (string)$order_id);
    sendMsg($chat_id, "🧾 Paste your <b>UTR</b> now (no spaces).\nOrderID: <code>{$order_id}</code>");
    answerCallback($cid, "Send UTR");
    http_response_code(200); exit;
  }

  // ---------------- ADMIN ----------------
  if (strpos($data, "admin:") === 0) {
    if ($user_id !== $ADMIN_ID) { answerCallback($cid, "Not allowed", true); http_response_code(200); exit; }

    if ($data === "admin:uploadqr") {
      adminSetStep($user_id, "WAIT_QR_PHOTO", null);
      sendMsg($chat_id, "🖼 Send QR photo now.");
      answerCallback($cid, "Send photo");
      http_response_code(200); exit;
    }

    if ($data === "admin:stock") {
      $prods = getActiveProducts();
      $t = "📦 <b>Stock</b>\n\n";
      foreach ($prods as $p) {
        $t .= "• <b>".htmlspecialchars($p["name"])."</b>: <b>".getStock((int)$p["id"])."</b>\n";
      }
      editMsg($chat_id, $message_id, $t, adminMenuKeyboard());
      answerCallback($cid, "Updated");
      http_response_code(200); exit;
    }

    if ($data === "admin:orders") {
      $rows = $pdo->query("
        SELECT o.id,o.status,o.amount_inr,o.created_at,o.utr,p.name,o.user_id
        FROM orders o JOIN products p ON p.id=o.product_id
        ORDER BY o.id DESC LIMIT 10
      ")->fetchAll();

      $t = "🧾 <b>Recent Orders</b>\n\n";
      if (!$rows) $t .= "No orders.";
      foreach ($rows as $r) {
        $t .= "#<code>{$r["id"]}</code> — <b>".htmlspecialchars($r["name"])."</b>\n";
        $t .= "Status: <b>{$r["status"]}</b> | ₹{$r["amount_inr"]}\n";
        $t .= "UserID: <code>{$r["user_id"]}</code>\n";
        if ($r["utr"]) $t .= "UTR: <code>".htmlspecialchars($r["utr"])."</code>\n";
        $t .= "Date: {$r["created_at"]}\n\n";
      }
      editMsg($chat_id, $message_id, $t, adminMenuKeyboard());
      answerCallback($cid, "Shown");
      http_response_code(200); exit;
    }

    if ($data === "admin:addcoupons") {
      $prods = getActiveProducts();
      $rows = [];
      foreach ($prods as $p) {
        $rows[] = [[ "text"=>"➕ ".$p["name"], "callback_data"=>"admin:addpick:".$p["id"] ]];
      }
      editMsg($chat_id, $message_id, "Select product:", ["inline_keyboard"=>$rows]);
      answerCallback($cid, "Pick");
      http_response_code(200); exit;
    }

    if (strpos($data, "admin:addpick:") === 0) {
      $pid = (int)substr($data, strlen("admin:addpick:"));
      $p = getProduct($pid);
      if (!$p) { answerCallback($cid, "Not found", true); http_response_code(200); exit; }

      adminSetStep($user_id, "WAIT_COUPON_CODES", $pid);
      sendMsg($chat_id, "Paste coupon codes for <b>".htmlspecialchars($p["name"])."</b> (one per line).");
      answerCallback($cid, "Paste");
      http_response_code(200); exit;
    }

    if ($data === "admin:editprices") {
      $prods = getActiveProducts();
      $rows = [];
      foreach ($prods as $p) {
        $rows[] = [[ "text"=>"✏️ ".$p["name"]." (₹".$p["price_inr"].")", "callback_data"=>"admin:pricepick:".$p["id"] ]];
      }
      editMsg($chat_id, $message_id, "Select product to edit price:", ["inline_keyboard"=>$rows]);
      answerCallback($cid, "Pick");
      http_response_code(200); exit;
    }

    if (strpos($data, "admin:pricepick:") === 0) {
      $pid = (int)substr($data, strlen("admin:pricepick:"));
      $p = getProduct($pid);
      if (!$p) { answerCallback($cid, "Not found", true); http_response_code(200); exit; }

      adminSetStep($user_id, "WAIT_PRICE_UPDATE", $pid);
      sendMsg($chat_id, "Send new price for <b>".htmlspecialchars($p["name"])."</b>\nCurrent: <b>₹{$p["price_inr"]}</b>");
      answerCallback($cid, "Send price");
      http_response_code(200); exit;
    }

    if (strpos($data, "admin:approve:") === 0) {
      $order_id = (int)substr($data, strlen("admin:approve:"));
      $result = deliverCouponForOrder($order_id);
      if (!$result["ok"]) { answerCallback($cid, $result["err"], true); http_response_code(200); exit; }

      $order = $result["order"];
      $p = getProduct((int)$order["product_id"]);
      sendMsg((int)$order["user_id"],
        "🎉 <b>Congratulations!</b>\n\nYour Coupon: <code>".htmlspecialchars($result["coupon_code"])."</code>\n\n".
        "Product: <b>".htmlspecialchars($p["name"])."</b>\nOrderID: <code>{$order_id}</code>"
      );

      editMsg($chat_id, $message_id,
        "✅ Delivered Order #<code>{$order_id}</code>\n\n".
        formatUserLine((int)$order["user_id"])."\n\n".
        "UTR: <code>".htmlspecialchars($order["utr"])."</code>\n".
        "CouponID: <code>{$result["coupon_id"]}</code>"
      );

      answerCallback($cid, "Delivered");
      http_response_code(200); exit;
    }

    if (strpos($data, "admin:reject:") === 0) {
      $order_id = (int)substr($data, strlen("admin:reject:"));
      $order = getOrder($order_id);
      if (!$order) { answerCallback($cid, "Order not found", true); http_response_code(200); exit; }

      if (!rejectOrder($order_id)) { answerCallback($cid, "Can't reject", true); http_response_code(200); exit; }

      sendMsg((int)$order["user_id"],
        "❌ <b>Payment Not Received</b>\n\nOrderID: <code>{$order_id}</code>\nIf you paid, contact admin."
      );

      editMsg($chat_id, $message_id, "❌ Rejected Order #<code>{$order_id}</code>");
      answerCallback($cid, "Rejected");
      http_response_code(200); exit;
    }
  }

  answerCallback($cid, "Done");
  http_response_code(200);
  exit;
}

http_response_code(200);
echo "OK";
