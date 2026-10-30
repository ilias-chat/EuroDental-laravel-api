# Task comments

The CRM task details drawer has a Commentaires tab with author, timestamp and plain-text body. Comments are loaded on first opening, newest first, with 20 records per cursor page. Drafts survive tab changes within the same task; switching tasks clears them. Posting keeps the draft if the request fails and uses the existing success/error toasts.

## Database setup

Deploy the schema before using the new endpoints. Use one method:

```bash
php artisan migrate --path=database/migrations/2026_10_30_190000_create_task_comments_table.php --force
```

Or run `database/sql/task_comments.sql` in phpMyAdmin. The SQL includes the migration registration after table creation. Neither method inserts comments or changes existing tasks.

## Access

All CRM comment endpoints require authentication and the existing `tasks_read` permission.

- Main technician and helping users may read and post on their assigned task.
- A user with `tasks_view_all` may read comments on any task.
- Posting on an unassigned task requires both `tasks_view_all` and `tasks_write`.
- A task creator has no additional comment access merely from creating a task.

The API rechecks participation when posting, under a task-row lock. The author is always the authenticated user. Comment bodies must contain non-whitespace text and cannot exceed 5000 characters. Creation is throttled to 30 requests per minute per authenticated user.

## Endpoints

- `GET /api/v1/tasks/{task}/comments?before_id=123`: comments, `can_post`, and `next_cursor`.
- `POST /api/v1/tasks/{task}/comments` with `{ "body": "Commentaire" }`: created comment and success message.
- `POST /api/v1/tasks/{task}/comments/read` with `{ "through_id": 123 }`: acknowledge comments actually displayed, without reading newer arrivals.

Deleting a user preserves their comments with a null author; deleting a task cascades to its comments. Client merge and undo keep the same task IDs, so comments stay attached to their original tasks. Editing and deleting comments and attachments are outside this change.

## Comment notifications (CRM and PWA)

- New comments notify the main technician, helpers and explicitly following admins, excluding the author and duplicate recipients. Unassigned managers are not automatically subscribed.
- One unread `task_comment` notification per recipient/task aggregates successive comments; after reading it, a subsequent comment creates a new notification.
- The existing shared `notifications` table is used. Both APIs must connect to the same database and have the `task_comments` table installed. Optional admin subscriptions use the shared `task_followers` table described below.
- Closing the notification panel no longer reads its contents. Opening Comments acknowledges through the latest fetched comment ID. Other notification types are read when clicked; explicit mark-all-read remains available through the existing API.
- CRM links use `/taches?task=123&tab=comments`; PWA links use `/tasks/123?tab=comments`. An unauthenticated user returns to this task after login.
- Laravel mobile exposes the same GET/POST/read endpoints under `/api/tasks/{task}/comments`. Mobile participants can read/post without a CRM-specific `tasks_read` permission. Unassigned viewers/managers follow the same scope rules above.
- Browser push is best effort after the comment transaction commits. A failed/denied push does not discard the inbox notification or the comment. No emails are sent. Bell counts use the existing 60-second polling.
- Web push uses existing `push_subscriptions` (CRM) and active `web_push_subscriptions` (PWA), deduplicated by endpoint. Each origin receives its own task URL. System notifications replace the previous notification for that task using a tag.

### Deployment

Deploy changes in **laravel-api**, **laravel-mobile**, **angular-crm** and **angular-pwa**. This does not change the separate Android app. Deploy the API endpoints before the frontends; update the PWA service worker as part of its normal production build.

For cross-app browser push, both APIs must use the **same existing VAPID key pair** (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`). Do not regenerate working keys: changing them invalidates existing browser subscriptions. HTTPS and user notification permission are required. In-app notifications work even without push configuration. The existing subscription registration is reused; no new email or queue setup is required. Push delivery is synchronous after commit in this first version, so a slow push provider can delay the POST response, but not roll back the saved comment.

Do not run a second migration against the shared database from the mobile API; use the CRM API migration or SQL described above once.

Tests use an isolated in-memory database: `php vendor/bin/phpunit tests/Feature/TaskCommentsTest.php`.

## Following a task

The CRM task drawer header contains **Suivre la tache**, toggling to **Ne plus suivre** after success. This subscribes the authenticated admin to future comment notifications only. It never changes the technician, helpers, task visibility or task permissions, and does not notify about status changes in this version.

- Following requires both `tasks_view_all` and `tasks_write`; CRM routes additionally require `tasks_read`.
- Permissions are rechecked for followers when each comment is posted. Losing either permission stops future follower notifications.
- Participants receive comments automatically. The CRM shows **Notifications equipe** instead of an optional follow toggle for participants; deleting a manual follow does not opt out of team notifications.
- Unfollowing stops future follower notifications; it does not remove existing notification history. A read-only admin who still has global visibility may unsubscribe an existing follow.
- Requests operate on the authenticated user only, are idempotent, and are serialized against comment posting with a task-row lock.
- Both APIs support GET (state), POST (follow), DELETE (unfollow) at `/tasks/{task}/follow`, beneath their usual `/api/v1` or `/api` prefixes. Responses include `following`, `can_follow`, `participant`.

Install the table **once** in the shared database before deploying the feature:

```bash
php artisan migrate --path=database/migrations/2026_10_30_200000_create_task_followers_table.php --force
```

Alternatively, run `database/sql/task_followers.sql` in phpMyAdmin. It includes the migration registration. Do not run both methods. It creates only the subscription table and does not modify existing tasks, teams, comments or notifications. Deleting a task or user cascades its subscriptions.

Deploy both APIs so comments posted from the PWA also notify followed admins, and deploy the updated CRM for the follow button. The PWA uses its existing notification panel and task links; its UI does not gain a separate follow control in this change. During a rolling deployment before this table exists, existing participant notifications continue to work; the follow control reports unavailable until the schema and endpoints are present.
