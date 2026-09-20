<?php
declare(strict_types=1);

/*
========================================================
 VEXO CENTRAL API
 https://api.vexo.gt.tc/api.php

 Database:
     ./vexo.db

 Uploads:
     ./upload/
        videos/
        thumbnails/
        profiles/
        other/

 No fake/seed data.
========================================================
*/

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

session_start();

/* ======================================================
   CONFIG
====================================================== */

const DB_FILE = __DIR__ . "/vexo.db";

const MAX_AVATAR_SIZE     = 8 * 1024 * 1024;
const MAX_THUMBNAIL_SIZE  = 12 * 1024 * 1024;
const MAX_VIDEO_SIZE      = 500 * 1024 * 1024;

const MAX_MESSAGE_LENGTH  = 4000;
const MAX_COMMENT_LENGTH  = 2000;

/* ======================================================
   RESPONSE
====================================================== */

function jsonResponse(
    bool $ok,
    string $message = "",
    mixed $data = null,
    int $status = 200
): never {
    http_response_code($status);

    echo json_encode(
        [
            "ok"      => $ok,
            "message" => $message,
            "data"    => $data
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function ok(mixed $data = null, string $message = "OK"): never
{
    jsonResponse(true, $message, $data);
}

function fail(string $message, int $status = 400): never
{
    jsonResponse(false, $message, null, $status);
}

/* ======================================================
   DATABASE
====================================================== */

try {
    $db = new PDO("sqlite:" . DB_FILE);

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $db->exec("PRAGMA foreign_keys = ON");
    $db->exec("PRAGMA journal_mode = WAL");

} catch (Throwable $e) {
    fail("Database connection failed.", 500);
}

/* ======================================================
   UPLOAD DIRECTORIES
====================================================== */

$uploadRoot = __DIR__ . "/upload";

$directories = [
    $uploadRoot,
    $uploadRoot . "/videos",
    $uploadRoot . "/thumbnails",
    $uploadRoot . "/profiles",
    $uploadRoot . "/other"
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

/* ======================================================
   DATABASE SCHEMA
====================================================== */

try {

$db->exec("

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    username TEXT NOT NULL UNIQUE,

    password_hash TEXT NOT NULL,

    display_name TEXT DEFAULT '',

    bio TEXT DEFAULT '',

    avatar TEXT DEFAULT '',

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    user_id INTEGER NOT NULL,

    token TEXT NOT NULL UNIQUE,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY(user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS followers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    follower_id INTEGER NOT NULL,

    following_id INTEGER NOT NULL,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    UNIQUE(follower_id, following_id),

    FOREIGN KEY(follower_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    FOREIGN KEY(following_id)
        REFERENCES users(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS videos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    user_id INTEGER NOT NULL,

    title TEXT NOT NULL,

    description TEXT DEFAULT '',

    video_url TEXT NOT NULL,

    thumbnail_url TEXT DEFAULT '',

    type TEXT NOT NULL DEFAULT 'long',

    views INTEGER DEFAULT 0,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY(user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS likes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    user_id INTEGER NOT NULL,

    video_id INTEGER NOT NULL,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    UNIQUE(user_id, video_id),

    FOREIGN KEY(user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    FOREIGN KEY(video_id)
        REFERENCES videos(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    user_id INTEGER NOT NULL,

    video_id INTEGER NOT NULL,

    text TEXT NOT NULL,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY(user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    FOREIGN KEY(video_id)
        REFERENCES videos(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS saved_videos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    user_id INTEGER NOT NULL,

    video_id INTEGER NOT NULL,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    UNIQUE(user_id, video_id),

    FOREIGN KEY(user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    FOREIGN KEY(video_id)
        REFERENCES videos(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS conversations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS conversation_members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    conversation_id INTEGER NOT NULL,

    user_id INTEGER NOT NULL,

    UNIQUE(conversation_id, user_id),

    FOREIGN KEY(conversation_id)
        REFERENCES conversations(id)
        ON DELETE CASCADE,

    FOREIGN KEY(user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    conversation_id INTEGER NOT NULL,

    sender_id INTEGER NOT NULL,

    text TEXT NOT NULL,

    is_read INTEGER DEFAULT 0,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY(conversation_id)
        REFERENCES conversations(id)
        ON DELETE CASCADE,

    FOREIGN KEY(sender_id)
        REFERENCES users(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS bots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    owner_id INTEGER NOT NULL,

    bot_name TEXT NOT NULL,

    bot_username TEXT NOT NULL UNIQUE,

    token_hash TEXT NOT NULL,

    status TEXT NOT NULL DEFAULT 'active',

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY(owner_id)
        REFERENCES users(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    reporter_id INTEGER NOT NULL,

    video_id INTEGER DEFAULT NULL,

    reported_user_id INTEGER DEFAULT NULL,

    reason TEXT NOT NULL,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY(reporter_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    FOREIGN KEY(video_id)
        REFERENCES videos(id)
        ON DELETE CASCADE,

    FOREIGN KEY(reported_user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_users_username
ON users(username);

CREATE INDEX IF NOT EXISTS idx_videos_user
ON videos(user_id);

CREATE INDEX IF NOT EXISTS idx_videos_type
ON videos(type);

CREATE INDEX IF NOT EXISTS idx_comments_video
ON comments(video_id);

CREATE INDEX IF NOT EXISTS idx_messages_conversation
ON messages(conversation_id);

CREATE INDEX IF NOT EXISTS idx_bots_owner
ON bots(owner_id);

");

} catch (Throwable $e) {
    fail("Database initialization failed.", 500);
}

/* ======================================================
   REQUEST HELPERS
====================================================== */

function input(): array
{
    $method = $_SERVER["REQUEST_METHOD"] ?? "GET";

    if ($method === "POST") {

        $contentType = $_SERVER["CONTENT_TYPE"] ?? "";

        if (stripos($contentType, "application/json") !== false) {

            $raw = file_get_contents("php://input");

            if ($raw !== false && trim($raw) !== "") {

                $decoded = json_decode($raw, true);

                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return $_POST;
    }

    return $_GET;
}

$input = input();

function value(string $key, mixed $default = null): mixed
{
    global $input;

    return $input[$key] ?? $default;
}

function cleanString(mixed $value): string
{
    return trim((string)$value);
}

/* ======================================================
   USERNAME
====================================================== */

function normalizeUsername(string $username): string
{
    $username = trim($username);

    if ($username === "") {
        return "";
    }

    if ($username[0] === "@") {
        $username = substr($username, 1);
    }

    return strtolower($username);
}

function displayUsername(string $username): string
{
    return "@" . ltrim($username, "@");
}

function validUsername(string $username): bool
{
    return preg_match('/^[A-Za-z0-9_]{3,32}$/', $username) === 1;
}

/* ======================================================
   AUTH TOKEN
====================================================== */

function createAuthToken(): string
{
    return bin2hex(random_bytes(32));
}

function bearerToken(): ?string
{
    $header = $_SERVER["HTTP_AUTHORIZATION"] ?? "";

    if (preg_match('/Bearer\s+(.+)/i', $header, $m)) {
        return trim($m[1]);
    }

    return null;
}

/* ======================================================
   CURRENT USER
====================================================== */

function currentUser(): ?array
{
    global $db;

    $token = bearerToken();

    if ($token) {

        $stmt = $db->prepare("
            SELECT
                u.*
            FROM sessions s
            JOIN users u ON u.id = s.user_id
            WHERE s.token = ?
            LIMIT 1
        ");

        $stmt->execute([$token]);

        $user = $stmt->fetch();

        if ($user) {
            return $user;
        }
    }

    if (!empty($_SESSION["user_id"])) {

        $stmt = $db->prepare("
            SELECT *
            FROM users
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([(int)$_SESSION["user_id"]]);

        $user = $stmt->fetch();

        if ($user) {
            return $user;
        }
    }

    return null;
}

function requireUser(): array
{
    $user = currentUser();

    if (!$user) {
        fail("Authentication required.", 401);
    }

    return $user;
}

/* ======================================================
   USER SERIALIZER
====================================================== */

function userData(array $user): array
{
    global $db;

    $followers = $db->prepare("
        SELECT COUNT(*)
        FROM followers
        WHERE following_id = ?
    ");

    $followers->execute([(int)$user["id"]]);

    $following = $db->prepare("
        SELECT COUNT(*)
        FROM followers
        WHERE follower_id = ?
    ");

    $following->execute([(int)$user["id"]]);

    $current = currentUser();

    $isFollowing = false;

    if ($current && (int)$current["id"] !== (int)$user["id"]) {

        $stmt = $db->prepare("
            SELECT 1
            FROM followers
            WHERE follower_id = ?
              AND following_id = ?
            LIMIT 1
        ");

        $stmt->execute([
            (int)$current["id"],
            (int)$user["id"]
        ]);

        $isFollowing = (bool)$stmt->fetchColumn();
    }

    return [
        "id"              => (int)$user["id"],
        "username"        => displayUsername($user["username"]),
        "username_raw"    => $user["username"],
        "display_name"    => $user["display_name"],
        "bio"             => $user["bio"],
        "avatar"          => $user["avatar"],
        "followers"       => (int)$followers->fetchColumn(),
        "following"       => (int)$following->fetchColumn(),
        "is_following"    => $isFollowing,
        "created_at"      => $user["created_at"]
    ];
}

/* ======================================================
   VIDEO SERIALIZER
====================================================== */

function videoData(array $video): array
{
    global $db;

    $userStmt = $db->prepare("
        SELECT *
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $userStmt->execute([(int)$video["user_id"]]);

    $user = $userStmt->fetch();

    $likeStmt = $db->prepare("
        SELECT COUNT(*)
        FROM likes
        WHERE video_id = ?
    ");

    $likeStmt->execute([(int)$video["id"]]);

    $commentStmt = $db->prepare("
        SELECT COUNT(*)
        FROM comments
        WHERE video_id = ?
    ");

    $commentStmt->execute([(int)$video["id"]]);

    $current = currentUser();

    $liked = false;
    $saved = false;

    if ($current) {

        $stmt = $db->prepare("
            SELECT 1
            FROM likes
            WHERE user_id = ?
              AND video_id = ?
            LIMIT 1
        ");

        $stmt->execute([
            (int)$current["id"],
            (int)$video["id"]
        ]);

        $liked = (bool)$stmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT 1
            FROM saved_videos
            WHERE user_id = ?
              AND video_id = ?
            LIMIT 1
        ");

        $stmt->execute([
            (int)$current["id"],
            (int)$video["id"]
        ]);

        $saved = (bool)$stmt->fetchColumn();
    }

    return [
        "id"             => (int)$video["id"],
        "title"          => $video["title"],
        "description"    => $video["description"],
        "video_url"      => $video["video_url"],
        "thumbnail_url"  => $video["thumbnail_url"],
        "type"           => $video["type"],
        "views"          => (int)$video["views"],
        "likes"          => (int)$likeStmt->fetchColumn(),
        "comments"       => (int)$commentStmt->fetchColumn(),
        "liked"          => $liked,
        "saved"          => $saved,
        "created_at"     => $video["created_at"],
        "user"           => $user ? userData($user) : null
    ];
}

/* ======================================================
   ACTION
====================================================== */

$action = cleanString(value("action", ""));

if ($action === "") {

    ok([
        "name" => "VEXO API",
        "version" => "1.0.0",
        "status" => "online"
    ], "Vexo API is online.");
}

/* ======================================================
   HEALTH
====================================================== */

if ($action === "health") {

    ok([
        "api" => "online",
        "database" => file_exists(DB_FILE),
        "upload" => is_dir($uploadRoot),
        "time" => date("c")
    ]);
}

/* ======================================================
   REGISTER
====================================================== */

if ($action === "register") {

    $username = normalizeUsername(
        cleanString(value("username"))
    );

    $password = (string)value("password", "");

    $displayName = cleanString(
        value("display_name", "")
    );

    if (!validUsername($username)) {
        fail("Username must contain 3-32 letters, numbers or underscores.");
    }

    if (strlen($password) < 6) {
        fail("Password must contain at least 6 characters.");
    }

    $stmt = $db->prepare("
        SELECT id
        FROM users
        WHERE username = ?
        LIMIT 1
    ");

    $stmt->execute([$username]);

    if ($stmt->fetch()) {
        fail("This username is already registered.", 409);
    }

    $hash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    $stmt = $db->prepare("
        INSERT INTO users
        (
            username,
            password_hash,
            display_name
        )
        VALUES (?, ?, ?)
    ");

    $stmt->execute([
        $username,
        $hash,
        $displayName
    ]);

    $userId = (int)$db->lastInsertId();

    $token = createAuthToken();

    $stmt = $db->prepare("
        INSERT INTO sessions
        (
            user_id,
            token
        )
        VALUES (?, ?)
    ");

    $stmt->execute([
        $userId,
        $token
    ]);

    $_SESSION["user_id"] = $userId;

    $stmt = $db->prepare("
        SELECT *
        FROM users
        WHERE id = ?
    ");

    $stmt->execute([$userId]);

    $user = $stmt->fetch();

    ok([
        "token" => $token,
        "user"  => userData($user)
    ], "Account created.");
}

/* ======================================================
   LOGIN
====================================================== */

if ($action === "login") {

    $username = normalizeUsername(
        cleanString(value("username"))
    );

    $password = (string)value("password", "");

    $stmt = $db->prepare("
        SELECT *
        FROM users
        WHERE username = ?
        LIMIT 1
    ");

    $stmt->execute([$username]);

    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user["password_hash"])) {
        fail("Invalid username or password.", 401);
    }

    $token = createAuthToken();

    $stmt = $db->prepare("
        INSERT INTO sessions
        (
            user_id,
            token
        )
        VALUES (?, ?)
    ");

    $stmt->execute([
        (int)$user["id"],
        $token
    ]);

    $_SESSION["user_id"] = (int)$user["id"];

    ok([
        "token" => $token,
        "user"  => userData($user)
    ], "Login successful.");
}

/* ======================================================
   LOGOUT
====================================================== */

if ($action === "logout") {

    $token = bearerToken();

    if ($token) {

        $stmt = $db->prepare("
            DELETE FROM sessions
            WHERE token = ?
        ");

        $stmt->execute([$token]);
    }

    $_SESSION = [];

    ok(null, "Logged out.");
}

/* ======================================================
   ME
====================================================== */

if ($action === "me") {

    $user = requireUser();

    ok(userData($user));
}

/* ======================================================
   UPDATE PROFILE
====================================================== */

if ($action === "update_profile") {

    $user = requireUser();

    $displayName = cleanString(
        value("display_name", $user["display_name"])
    );

    $bio = cleanString(
        value("bio", $user["bio"])
    );

    $stmt = $db->prepare("
        UPDATE users
        SET
            display_name = ?,
            bio = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");

    $stmt->execute([
        $displayName,
        $bio,
        (int)$user["id"]
    ]);

    $stmt = $db->prepare("
        SELECT *
        FROM users
        WHERE id = ?
    ");

    $stmt->execute([(int)$user["id"]]);

    ok(
        userData($stmt->fetch()),
        "Profile updated."
    );
}

/* ======================================================
   SEARCH USERS
====================================================== */

if ($action === "search_users") {

    $q = cleanString(value("q", ""));

    if ($q === "") {
        ok([]);
    }

    $q = ltrim($q, "@");

    $stmt = $db->prepare("
        SELECT *
        FROM users
        WHERE username LIKE ?
           OR display_name LIKE ?
        ORDER BY username ASC
        LIMIT 50
    ");

    $search = "%" . $q . "%";

    $stmt->execute([
        $search,
        $search
    ]);

    $result = [];

    foreach ($stmt->fetchAll() as $user) {
        $result[] = userData($user);
    }

    ok($result);
}

/* ======================================================
   PROFILE
====================================================== */

if ($action === "profile") {

    $username = normalizeUsername(
        cleanString(value("username", ""))
    );

    if ($username === "") {
        fail("Username required.");
    }

    $stmt = $db->prepare("
        SELECT *
        FROM users
        WHERE username = ?
        LIMIT 1
    ");

    $stmt->execute([$username]);

    $user = $stmt->fetch();

    if (!$user) {
        fail("User not found.", 404);
    }

    ok(userData($user));
}

/* ======================================================
   FOLLOW
====================================================== */

if ($action === "follow" || $action === "unfollow") {

    $user = requireUser();

    $targetId = (int)value("user_id", 0);

    if ($targetId <= 0) {
        fail("Invalid user ID.");
    }

    if ($targetId === (int)$user["id"]) {
        fail("You cannot follow yourself.");
    }

    $stmt = $db->prepare("
        SELECT id
        FROM users
        WHERE id = ?
    ");

    $stmt->execute([$targetId]);

    if (!$stmt->fetch()) {
        fail("User not found.", 404);
    }

    if ($action === "follow") {

        $stmt = $db->prepare("
            INSERT OR IGNORE INTO followers
            (
                follower_id,
                following_id
            )
            VALUES (?, ?)
        ");

        $stmt->execute([
            (int)$user["id"],
            $targetId
        ]);

        ok(null, "Followed.");
    }

    $stmt = $db->prepare("
        DELETE FROM followers
        WHERE follower_id = ?
          AND following_id = ?
    ");

    $stmt->execute([
        (int)$user["id"],
        $targetId
    ]);

    ok(null, "Unfollowed.");
}

/* ======================================================
   FOLLOWERS / FOLLOWING
====================================================== */

if ($action === "followers" || $action === "following") {

    $userId = (int)value("user_id", 0);

    if ($userId <= 0) {
        $current = requireUser();
        $userId = (int)$current["id"];
    }

    if ($action === "followers") {

        $stmt = $db->prepare("
            SELECT u.*
            FROM followers f
            JOIN users u ON u.id = f.follower_id
            WHERE f.following_id = ?
            ORDER BY f.created_at DESC
        ");

    } else {

        $stmt = $db->prepare("
            SELECT u.*
            FROM followers f
            JOIN users u ON u.id = f.following_id
            WHERE f.follower_id = ?
            ORDER BY f.created_at DESC
        ");
    }

    $stmt->execute([$userId]);

    $result = [];

    foreach ($stmt->fetchAll() as $u) {
        $result[] = userData($u);
    }

    ok($result);
}

/* ======================================================
   FEED / VIDEOS
====================================================== */

if (
    $action === "feed" ||
    $action === "videos" ||
    $action === "shorts"
) {

    $limit = min(
        max((int)value("limit", 30), 1),
        100
    );

    $offset = max(
        (int)value("offset", 0),
        0
    );

    if ($action === "shorts") {
        $type = "short";
    } else {
        $type = "long";
    }

    $stmt = $db->prepare("
        SELECT *
        FROM videos
        WHERE type = ?
        ORDER BY id DESC
        LIMIT ? OFFSET ?
    ");

    $stmt->bindValue(1, $type, PDO::PARAM_STR);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);

    $stmt->execute();

    $result = [];

    foreach ($stmt->fetchAll() as $video) {
        $result[] = videoData($video);
    }

    ok($result);
}

/* ======================================================
   SINGLE VIDEO
====================================================== */

if ($action === "video") {

    $id = (int)value("id", 0);

    if ($id <= 0) {
        fail("Invalid video ID.");
    }

    $stmt = $db->prepare("
        SELECT *
        FROM videos
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$id]);

    $video = $stmt->fetch();

    if (!$video) {
        fail("Video not found.", 404);
    }

    $db->prepare("
        UPDATE videos
        SET views = views + 1
        WHERE id = ?
    ")->execute([$id]);

    $video["views"]++;

    ok(videoData($video));
}

/* ======================================================
   CREATE VIDEO
====================================================== */

if ($action === "create_video") {

    $user = requireUser();

    $title = cleanString(value("title", ""));
    $description = cleanString(value("description", ""));
    $videoUrl = cleanString(value("video_url", ""));
    $thumbnailUrl = cleanString(value("thumbnail_url", ""));
    $type = cleanString(value("type", "long"));

    if ($title === "") {
        fail("Video title is required.");
    }

    if ($videoUrl === "") {
        fail("Video URL is required.");
    }

    if (!in_array($type, ["long", "short"], true)) {
        fail("Invalid video type.");
    }

    $stmt = $db->prepare("
        INSERT INTO videos
        (
            user_id,
            title,
            description,
            video_url,
            thumbnail_url,
            type
        )
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        (int)$user["id"],
        $title,
        $description,
        $videoUrl,
        $thumbnailUrl,
        $type
    ]);

    $id = (int)$db->lastInsertId();

    $stmt = $db->prepare("
        SELECT *
        FROM videos
        WHERE id = ?
    ");

    $stmt->execute([$id]);

    ok(
        videoData($stmt->fetch()),
        "Video created."
    );
}

/* ======================================================
   DELETE VIDEO
====================================================== */

if ($action === "delete_video") {

    $user = requireUser();

    $id = (int)value("id", 0);

    $stmt = $db->prepare("
        SELECT *
        FROM videos
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$id]);

    $video = $stmt->fetch();

    if (!$video) {
        fail("Video not found.", 404);
    }

    if ((int)$video["user_id"] !== (int)$user["id"]) {
        fail("You cannot delete this video.", 403);
    }

    $stmt = $db->prepare("
        DELETE FROM videos
        WHERE id = ?
    ");

    $stmt->execute([$id]);

    ok(null, "Video deleted.");
}

/* ======================================================
   LIKE / UNLIKE
====================================================== */

if ($action === "like_video" || $action === "unlike_video") {

    $user = requireUser();

    $videoId = (int)value("video_id", 0);

    if ($videoId <= 0) {
        fail("Invalid video ID.");
    }

    if ($action === "like_video") {

        $stmt = $db->prepare("
            INSERT OR IGNORE INTO likes
            (
                user_id,
                video_id
            )
            VALUES (?, ?)
        ");

        $stmt->execute([
            (int)$user["id"],
            $videoId
        ]);

        ok(null, "Liked.");
    }

    $stmt = $db->prepare("
        DELETE FROM likes
        WHERE user_id = ?
          AND video_id = ?
    ");

    $stmt->execute([
        (int)$user["id"],
        $videoId
    ]);

    ok(null, "Unliked.");
}

/* ======================================================
   COMMENTS
====================================================== */

if ($action === "comments") {

    $videoId = (int)value("video_id", 0);

    $stmt = $db->prepare("
        SELECT
            c.*,
            u.username,
            u.display_name,
            u.avatar
        FROM comments c
        JOIN users u ON u.id = c.user_id
        WHERE c.video_id = ?
        ORDER BY c.id DESC
    ");

    $stmt->execute([$videoId]);

    $result = [];

    foreach ($stmt->fetchAll() as $comment) {

        $result[] = [
            "id" => (int)$comment["id"],
            "text" => $comment["text"],
            "created_at" => $comment["created_at"],
            "user" => [
                "id" => (int)$comment["user_id"],
                "username" => displayUsername($comment["username"]),
                "display_name" => $comment["display_name"],
                "avatar" => $comment["avatar"]
            ]
        ];
    }

    ok($result);
}

if ($action === "add_comment") {

    $user = requireUser();

    $videoId = (int)value("video_id", 0);
    $text = cleanString(value("text", ""));

    if ($videoId <= 0) {
        fail("Invalid video ID.");
    }

    if ($text === "") {
        fail("Comment cannot be empty.");
    }

    if (strlen($text) > MAX_COMMENT_LENGTH) {
        fail("Comment is too long.");
    }

    $stmt = $db->prepare("
        INSERT INTO comments
        (
            user_id,
            video_id,
            text
        )
        VALUES (?, ?, ?)
    ");

    $stmt->execute([
        (int)$user["id"],
        $videoId,
        $text
    ]);

    ok([
        "id" => (int)$db->lastInsertId()
    ], "Comment added.");
}

/* ======================================================
   SAVE / UNSAVE
====================================================== */

if ($action === "save_video" || $action === "unsave_video") {

    $user = requireUser();

    $videoId = (int)value("video_id", 0);

    if ($action === "save_video") {

        $stmt = $db->prepare("
            INSERT OR IGNORE INTO saved_videos
            (
                user_id,
                video_id
            )
            VALUES (?, ?)
        ");

        $stmt->execute([
            (int)$user["id"],
            $videoId
        ]);

        ok(null, "Video saved.");
    }

    $stmt = $db->prepare("
        DELETE FROM saved_videos
        WHERE user_id = ?
          AND video_id = ?
    ");

    $stmt->execute([
        (int)$user["id"],
        $videoId
    ]);

    ok(null, "Video removed from saved.");
}

/* ======================================================
   SAVED VIDEOS
====================================================== */

if ($action === "saved_videos") {

    $user = requireUser();

    $stmt = $db->prepare("
        SELECT v.*
        FROM saved_videos s
        JOIN videos v ON v.id = s.video_id
        WHERE s.user_id = ?
        ORDER BY s.id DESC
    ");

    $stmt->execute([
        (int)$user["id"]
    ]);

    $result = [];

    foreach ($stmt->fetchAll() as $video) {
        $result[] = videoData($video);
    }

    ok($result);
}

/* ======================================================
   VxMSG - CONVERSATIONS
====================================================== */

if ($action === "conversations") {

    $user = requireUser();

    $stmt = $db->prepare("
        SELECT
            c.id,
            c.created_at,
            cm.user_id AS other_id
        FROM conversations c
        JOIN conversation_members cm
            ON cm.conversation_id = c.id
        WHERE cm.user_id != ?
          AND EXISTS (
              SELECT 1
              FROM conversation_members mine
              WHERE mine.conversation_id = c.id
                AND mine.user_id = ?
          )
        ORDER BY c.id DESC
    ");

    $stmt->execute([
        (int)$user["id"],
        (int)$user["id"]
    ]);

    $result = [];

    foreach ($stmt->fetchAll() as $row) {

        $uStmt = $db->prepare("
            SELECT *
            FROM users
            WHERE id = ?
        ");

        $uStmt->execute([
            (int)$row["other_id"]
        ]);

        $other = $uStmt->fetch();

        if (!$other) {
            continue;
        }

        $lastStmt = $db->prepare("
            SELECT text, created_at
            FROM messages
            WHERE conversation_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");

        $lastStmt->execute([
            (int)$row["id"]
        ]);

        $last = $lastStmt->fetch();

        $result[] = [
            "id" => (int)$row["id"],
            "other_id" => (int)$other["id"],
            "name" => $other["display_name"] !== ""
                ? $other["display_name"]
                : displayUsername($other["username"]),
            "username" => displayUsername($other["username"]),
            "avatar" => $other["avatar"],
            "last_message" => $last ?: null
        ];
    }

    ok($result);
}

/* ======================================================
   VxMSG - START CONVERSATION
====================================================== */

if ($action === "start_conversation") {

    $user = requireUser();

    $otherId = (int)value("user_id", 0);

    if ($otherId <= 0) {
        fail("Invalid user ID.");
    }

    if ($otherId === (int)$user["id"]) {
        fail("You cannot start a conversation with yourself.");
    }

    $stmt = $db->prepare("
        SELECT *
        FROM users
        WHERE id = ?
    ");

    $stmt->execute([$otherId]);

    $other = $stmt->fetch();

    if (!$other) {
        fail("User not found.", 404);
    }

    $stmt = $db->prepare("
        SELECT c.id
        FROM conversations c

        JOIN conversation_members a
            ON a.conversation_id = c.id

        JOIN conversation_members b
            ON b.conversation_id = c.id

        WHERE a.user_id = ?
          AND b.user_id = ?
          AND (
              SELECT COUNT(*)
              FROM conversation_members x
              WHERE x.conversation_id = c.id
          ) = 2

        LIMIT 1
    ");

    $stmt->execute([
        (int)$user["id"],
        $otherId
    ]);

    $existing = $stmt->fetch();

    if ($existing) {

        ok([
            "conversation_id" => (int)$existing["id"],
            "user" => userData($other)
        ]);
    }

    $db->beginTransaction();

    try {

        $db->exec("
            INSERT INTO conversations DEFAULT VALUES
        ");

        $conversationId = (int)$db->lastInsertId();

        $stmt = $db->prepare("
            INSERT INTO conversation_members
            (
                conversation_id,
                user_id
            )
            VALUES (?, ?), (?, ?)
        ");

        $stmt->execute([
            $conversationId,
            (int)$user["id"],
            $conversationId,
            $otherId
        ]);

        $db->commit();

        ok([
            "conversation_id" => $conversationId,
            "user" => userData($other)
        ], "Conversation created.");

    } catch (Throwable $e) {

        $db->rollBack();

        fail("Could not create conversation.", 500);
    }
}

/* ======================================================
   VxMSG - MESSAGES
====================================================== */

if ($action === "messages") {

    $user = requireUser();

    $conversationId = (int)value(
        "conversation_id",
        0
    );

    $stmt = $db->prepare("
        SELECT 1
        FROM conversation_members
        WHERE conversation_id = ?
          AND user_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $conversationId,
        (int)$user["id"]
    ]);

    if (!$stmt->fetch()) {
        fail("Conversation not found.", 404);
    }

    $stmt = $db->prepare("
        SELECT
            m.*,
            u.username,
            u.display_name,
            u.avatar
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        WHERE m.conversation_id = ?
        ORDER BY m.id ASC
        LIMIT 200
    ");

    $stmt->execute([
        $conversationId
    ]);

    $result = [];

    foreach ($stmt->fetchAll() as $message) {

        $result[] = [
            "id" => (int)$message["id"],
            "conversation_id" => $conversationId,
            "sender_id" => (int)$message["sender_id"],
            "text" => $message["text"],
            "is_read" => (bool)$message["is_read"],
            "created_at" => $message["created_at"],
            "sender" => [
                "username" => displayUsername($message["username"]),
                "display_name" => $message["display_name"],
                "avatar" => $message["avatar"]
            ]
        ];
    }

    ok($result);
}

/* ======================================================
   VxMSG - SEND MESSAGE
====================================================== */

if ($action === "send_message") {

    $user = requireUser();

    $conversationId = (int)value(
        "conversation_id",
        0
    );

    $text = cleanString(
        value("text", "")
    );

    if ($text === "") {
        fail("Message cannot be empty.");
    }

    if (strlen($text) > MAX_MESSAGE_LENGTH) {
        fail("Message is too long.");
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM conversation_members
        WHERE conversation_id = ?
          AND user_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $conversationId,
        (int)$user["id"]
    ]);

    if (!$stmt->fetch()) {
        fail("Conversation not found.", 404);
    }

    $stmt = $db->prepare("
        INSERT INTO messages
        (
            conversation_id,
            sender_id,
            text
        )
        VALUES (?, ?, ?)
    ");

    $stmt->execute([
        $conversationId,
        (int)$user["id"],
        $text
    ]);

    ok([
        "id" => (int)$db->lastInsertId()
    ], "Message sent.");
}

/* ======================================================
   MARK MESSAGES READ
====================================================== */

if ($action === "mark_read") {

    $user = requireUser();

    $conversationId = (int)value(
        "conversation_id",
        0
    );

    $stmt = $db->prepare("
        UPDATE messages
        SET is_read = 1
        WHERE conversation_id = ?
          AND sender_id != ?
    ");

    $stmt->execute([
        $conversationId,
        (int)$user["id"]
    ]);

    ok(null, "Messages marked as read.");
}

/* ======================================================
   BOT TOKEN GENERATOR
====================================================== */

function generateBotToken(): string
{
    return "vx_" . rtrim(
        strtr(
            base64_encode(random_bytes(36)),
            "+/",
            "-_"
        ),
        "="
    );
}

/* ======================================================
   CREATE BOT
====================================================== */

if (
    $action === "create_bot" ||
    $action === "newbot"
) {

    $user = requireUser();

    $botName = cleanString(
        value("bot_name", "")
    );

    $botUsername = normalizeUsername(
        cleanString(value("bot_username", ""))
    );

    if ($botName === "") {
        fail("Bot name is required.");
    }

    if (!validUsername($botUsername)) {
        fail("Invalid bot ID.");
    }

    $stmt = $db->prepare("
        SELECT id
        FROM bots
        WHERE bot_username = ?
        LIMIT 1
    ");

    $stmt->execute([
        $botUsername
    ]);

    if ($stmt->fetch()) {
        fail("This bot ID is already taken.", 409);
    }

    $token = generateBotToken();

    /*
     * IMPORTANT:
     * Only the hash is stored.
     */

    $tokenHash = password_hash(
        $token,
        PASSWORD_DEFAULT
    );

    $stmt = $db->prepare("
        INSERT INTO bots
        (
            owner_id,
            bot_name,
            bot_username,
            token_hash
        )
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([
        (int)$user["id"],
        $botName,
        $botUsername,
        $tokenHash
    ]);

    $botId = (int)$db->lastInsertId();

    ok([
        "id" => $botId,
        "bot_name" => $botName,
        "bot_username" => displayUsername($botUsername),

        /*
         * This is shown only at creation time.
         */
        "token" => $token,

        "token_storage" => "hashed"
    ], "Bot created. Save your token securely.");
}

/* ======================================================
   MY BOTS
====================================================== */

if ($action === "my_bots") {

    $user = requireUser();

    $stmt = $db->prepare("
        SELECT
            id,
            bot_name,
            bot_username,
            status,
            created_at,
            updated_at
        FROM bots
        WHERE owner_id = ?
        ORDER BY id DESC
    ");

    $stmt->execute([
        (int)$user["id"]
    ]);

    $result = [];

    foreach ($stmt->fetchAll() as $bot) {

        $result[] = [
            "id" => (int)$bot["id"],
            "bot_name" => $bot["bot_name"],
            "bot_username" => displayUsername(
                $bot["bot_username"]
            ),
            "status" => $bot["status"],
            "created_at" => $bot["created_at"],
            "updated_at" => $bot["updated_at"]
        ];
    }

    ok($result);
}

/* ======================================================
   BOT INFO
====================================================== */

if ($action === "bot") {

    $user = requireUser();

    $botId = (int)value("id", 0);

    $stmt = $db->prepare("
        SELECT
            id,
            bot_name,
            bot_username,
            status,
            created_at,
            updated_at
        FROM bots
        WHERE id = ?
          AND owner_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $botId,
        (int)$user["id"]
    ]);

    $bot = $stmt->fetch();

    if (!$bot) {
        fail("Bot not found.", 404);
    }

    $bot["id"] = (int)$bot["id"];

    $bot["bot_username"] =
        displayUsername($bot["bot_username"]);

    ok($bot);
}

/* ======================================================
   UPDATE BOT
====================================================== */

if ($action === "update_bot") {

    $user = requireUser();

    $botId = (int)value("id", 0);

    $botName = cleanString(
        value("bot_name", "")
    );

    $botUsername = normalizeUsername(
        cleanString(value("bot_username", ""))
    );

    if ($botName === "") {
        fail("Bot name is required.");
    }

    if (!validUsername($botUsername)) {
        fail("Invalid bot ID.");
    }

    $stmt = $db->prepare("
        SELECT *
        FROM bots
        WHERE id = ?
          AND owner_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $botId,
        (int)$user["id"]
    ]);

    $bot = $stmt->fetch();

    if (!$bot) {
        fail("Bot not found.", 404);
    }

    $stmt = $db->prepare("
        SELECT id
        FROM bots
        WHERE bot_username = ?
          AND id != ?
        LIMIT 1
    ");

    $stmt->execute([
        $botUsername,
        $botId
    ]);

    if ($stmt->fetch()) {
        fail("This bot ID is already taken.", 409);
    }

    $stmt = $db->prepare("
        UPDATE bots
        SET
            bot_name = ?,
            bot_username = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND owner_id = ?
    ");

    $stmt->execute([
        $botName,
        $botUsername,
        $botId,
        (int)$user["id"]
    ]);

    ok([
        "id" => $botId,
        "bot_name" => $botName,
        "bot_username" => displayUsername($botUsername)
    ], "Bot updated.");
}

/* ======================================================
   REGENERATE BOT TOKEN
====================================================== */

if ($action === "regenerate_bot_token") {

    $user = requireUser();

    $botId = (int)value("id", 0);

    $stmt = $db->prepare("
        SELECT *
        FROM bots
        WHERE id = ?
          AND owner_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $botId,
        (int)$user["id"]
    ]);

    $bot = $stmt->fetch();

    if (!$bot) {
        fail("Bot not found.", 404);
    }

    $newToken = generateBotToken();

    $newHash = password_hash(
        $newToken,
        PASSWORD_DEFAULT
    );

    $stmt = $db->prepare("
        UPDATE bots
        SET
            token_hash = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND owner_id = ?
    ");

    $stmt->execute([
        $newHash,
        $botId,
        (int)$user["id"]
    ]);

    ok([
        "id" => $botId,
        "token" => $newToken
    ], "New bot token generated.");
}

/* ======================================================
   DELETE BOT
====================================================== */

if ($action === "delete_bot") {

    $user = requireUser();

    $botId = (int)value("id", 0);

    $stmt = $db->prepare("
        DELETE FROM bots
        WHERE id = ?
          AND owner_id = ?
    ");

    $stmt->execute([
        $botId,
        (int)$user["id"]
    ]);

    if ($stmt->rowCount() === 0) {
        fail("Bot not found.", 404);
    }

    ok(null, "Bot deleted.");
}

/* ======================================================
   BOT AUTH
   External Vexo bot runtime can use:

   Authorization: Bearer vx_xxxxxxxxx

   The raw token is NEVER stored.
====================================================== */

if ($action === "bot_auth") {

    $token = bearerToken();

    if (!$token || !str_starts_with($token, "vx_")) {
        fail("Invalid bot token.", 401);
    }

    $stmt = $db->query("
        SELECT
            b.*,
            u.username AS owner_username
        FROM bots b
        JOIN users u
            ON u.id = b.owner_id
        WHERE b.status = 'active'
        ORDER BY b.id ASC
    ");

    $found = null;

    foreach ($stmt->fetchAll() as $bot) {

        if (
            password_verify(
                $token,
                $bot["token_hash"]
            )
        ) {
            $found = $bot;
            break;
        }
    }

    if (!$found) {
        fail("Invalid bot token.", 401);
    }

    ok([
        "bot" => [
            "id" => (int)$found["id"],
            "name" => $found["bot_name"],
            "username" => displayUsername(
                $found["bot_username"]
            ),
            "status" => $found["status"]
        ],

        "owner" => [
            "id" => (int)$found["owner_id"],
            "username" => displayUsername(
                $found["owner_username"]
            )
        ]
    ], "Bot authenticated.");
}

/* ======================================================
   REPORT
====================================================== */

if ($action === "report") {

    $user = requireUser();

    $videoId = value("video_id", null);
    $reportedUserId = value("reported_user_id", null);
    $reason = cleanString(
        value("reason", "")
    );

    if ($reason === "") {
        fail("Report reason is required.");
    }

    $stmt = $db->prepare("
        INSERT INTO reports
        (
            reporter_id,
            video_id,
            reported_user_id,
            reason
        )
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([
        (int)$user["id"],
        $videoId !== null ? (int)$videoId : null,
        $reportedUserId !== null
            ? (int)$reportedUserId
            : null,
        $reason
    ]);

    ok(null, "Report submitted.");
}

/* ======================================================
   404
====================================================== */

fail(
    "Unknown API action: " . $action,
    404
);
?>