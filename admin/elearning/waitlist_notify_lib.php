<?php
// /admin/elearning/waitlist_notify_lib.php
// Demo note: queue_course_notification() inserts into course_notify_jobs only.
// The cron that consumed this queue (cron_notify_waitlist.php) was removed in demo — no emails are sent.

function queue_course_notification(mysqli $conn, string $level, string $courseKey, string $courseTitle, string $courseUrl): void
{
  $level = strtolower(trim($level));
  if (!in_array($level, ['beginner','intermediate','advanced'], true)) return;

  $courseKey = trim($courseKey);
  $courseTitle = trim($courseTitle);
  $courseUrl = trim($courseUrl);
  if ($courseKey === '' || $courseTitle === '' || $courseUrl === '') return;

  // Create job (or ignore if already queued for same course_key)
  $stmt = $conn->prepare("
    INSERT INTO course_notify_jobs (level, course_key, course_title, course_url, status)
    VALUES (?, ?, ?, ?, 'pending')
    ON DUPLICATE KEY UPDATE
      course_title=VALUES(course_title),
      course_url=VALUES(course_url)
  ");
  $stmt->bind_param("ssss", $level, $courseKey, $courseTitle, $courseUrl);
  $stmt->execute();
  $stmt->close();
}