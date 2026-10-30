# Task comments

The CRM task details drawer has a Commentaires tab with author, timestamp and plain-text body. Comments are loaded on first opening, newest first, with 20 records per cursor page. Drafts survive tab changes within the same task; switching tasks clears them. Posting keeps the draft if the request fails and uses the existing success/error toasts.

## Database setup

Deploy the schema before using the new endpoints. Use one method:

```bash
php artisan migrate --path=database/migrations/2026_10_30_190000_create_task_comments_table.php --force
```

Or run `database/sql/task_comments.sql` in phpMyAdmin. The SQL includes the migration registration after table creation. Neither method inserts comments or changes existing tasks.

## Access

Both endpoints require authentication and the existing `tasks_read` permission.

- Main technician and helping users may read and post on their assigned task.
- A user with `tasks_view_all` may read comments on any task.
- Posting on an unassigned task requires both `tasks_view_all` and `tasks_write`.
- A task creator has no additional comment access merely from creating a task.

The API rechecks participation when posting, under a task-row lock. The author is always the authenticated user. Comment bodies must contain non-whitespace text and cannot exceed 5000 characters. Creation is throttled to 30 requests per minute per authenticated user.

## Endpoints

- `GET /api/v1/tasks/{task}/comments?before_id=123`: comments, `can_post`, and `next_cursor`.
- `POST /api/v1/tasks/{task}/comments` with `{ "body": "Commentaire" }`: created comment and success message.

Deleting a user preserves their comments with a null author; deleting a task cascades to its comments. Client merge and undo keep the same task IDs, so comments stay attached to their original tasks. Editing and deleting comments, attachments, and mobile/PWA integration are outside this change.

Tests use an isolated in-memory database: `php vendor/bin/phpunit tests/Feature/TaskCommentsTest.php`.
