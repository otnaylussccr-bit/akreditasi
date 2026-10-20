# DB-18 Migration Recovery

DB-18 is locked to `AMS_APU_DB17T_Full_DDL_CANDIDATE.sql` with SHA-256 `b0e37c88f35f666153bbad1c9ee89a61a13f355d8d64c756c5aaeef489fcdd80`.

The migrations support forward-only recovery from these interrupted DDL states:

- A prefix of the three Phase 1 scope keys exists and each existing key exactly matches the LOCK.
- A LOCK-order prefix of the 20 DB-18 tables exists and every existing table passes metadata inspection.
- All 20 tables exist and neither circular assessment-revision foreign key exists.
- The complete schema exists but one or more DB-18 migration rows were not recorded.

Recovery stops without changing the schema when it finds a table-order gap, incompatible table or scope-key metadata, an inconsistent migration-record prefix, or exactly one circular pointer foreign key. It never automatically drops a table, index, constraint, migration row, or application record.

## Inspection

Run the read-only inspector against the configured connection intended for the migration:

```bash
php artisan db18:inspect --database=mariadb
```

The command reports the database and server identity, schema status, table count, scope-key count, circular-FK count, and recorded migration count. `INVALID` or an `INCONSISTENT_*` status requires evidence preservation and manual review. Do not retry the migration until the mismatch is understood.

## Forward Recovery

1. Preserve the inspector output and a database backup or snapshot.
2. Confirm the selected connection is the intended database, MariaDB is 10.4.x, `foreign_key_checks=1`, and the database default is `utf8mb4_general_ci` or `utf8mb4_unicode_ci` with the `utf8mb4` character set.
3. Confirm `DB18_SHA256` matches the locked checksum above.
4. Run the normal migration command once against that explicit connection.
5. Run `db18:inspect` again and require `DB18_STATUS=COMPLETE`, `DB18_TABLES=20/20`, `DB18_SCOPE_KEYS=3/3`, `DB18_POINTER_FKS=2/2`, and `DB18_MIGRATIONS=5/5`.

Do not use rollback for DB-18. All five migrations intentionally block `down()` because the scope keys and new structures may protect governed audit evidence.
