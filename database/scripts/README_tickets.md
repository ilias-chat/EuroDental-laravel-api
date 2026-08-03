# Tickets feature – database scripts

These scripts create the database objects needed for the tickets feature:

- Tables: `tickets`, `ticket_replies`, `ticket_attachments`
- Permissions: `tickets_create`, `tickets_manage`

**Use only one script**, depending on your database:

| Database   | Script                  | How to run |
|-----------|-------------------------|------------|
| PostgreSQL| `tickets_postgresql.sql`| `psql -U your_user -d your_database -f tickets_postgresql.sql` or run in pgAdmin |
| MySQL     | `tickets_mysql.sql`     | `mysql -u your_user -p your_database < tickets_mysql.sql` or run in phpMyAdmin |

Scripts are idempotent where possible: tables use `CREATE TABLE IF NOT EXISTS`, and permissions are inserted only when they do not already exist (PostgreSQL), or with `INSERT IGNORE` (MySQL).

After running the script, assign the new permissions to the right profiles in Admin → Utilisateurs (or your profile/permission management).
