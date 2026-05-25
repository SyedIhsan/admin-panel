# Production Deployment Notes

Issues and workarounds discovered during InfinityFree deployment.

---

## order_products: VIEW → TABLE (phase 6.1)

**Problem:** InfinityFree free tier denies `CREATE VIEW` privilege.
`schema.sql` originally defined `order_products` as a view over `Orders`:

```sql
CREATE OR REPLACE VIEW `order_products` AS
SELECT id, email AS customer_email, status, product_name,
       amount, NULL AS product_type, created_at
FROM `Orders`;
```

This caused a `#1142: CREATE VIEW command denied` error on import.

**Fix:** Converted to a real table populated from `Orders` during seed:

```sql
CREATE TABLE `order_products` ( ... );   -- in schema.sql
INSERT INTO `order_products` ... SELECT ... FROM `Orders`;  -- in seed.sql
```

The `demo/reset.php` drop loop was also updated to use `SHOW FULL TABLES`
and issue `DROP VIEW IF EXISTS` for views vs `DROP TABLE IF EXISTS` for
tables, so future schema changes involving views don't break local resets.

**If migrating to a host that supports VIEWs:** the table could be
converted back for storage efficiency — remove the `CREATE TABLE` +
`INSERT` and restore the `CREATE OR REPLACE VIEW`.

---

## No other privilege issues found

`CREATE TRIGGER`, `CREATE PROCEDURE`, `CREATE FUNCTION`, and `CREATE EVENT`
are absent from `schema.sql`. No further workarounds needed.
