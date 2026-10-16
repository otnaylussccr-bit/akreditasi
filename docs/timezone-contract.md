# Timezone Contract

AMS APU uses the following timestamp contract:

- Laravel and Eloquent operate in `UTC`.
- MySQL/MariaDB application connections set their session timezone to `+00:00`.
- Operational instants are stored in UTC and presented in `Asia/Jakarta` (`WIB`).
- Calendar dates such as project and internal deadlines remain date-only values and are not timezone-converted.

Set these values in each deployed environment before warming the configuration cache:

```dotenv
APP_BUSINESS_TIMEZONE=Asia/Jakarta
DB_TIMEZONE=+00:00
```

The connection timezone is application-scoped. Do not change the database global timezone or operating-system timezone.

## Local Historical Data

The local database used before this contract had MariaDB sessions in UTC+7 while Laravel wrote UTC wall-clock values into `TIMESTAMP` columns. Its historical internal epochs are therefore not valid UTC instants. This hotfix does not modify or automatically correct those rows. Do not use the old local fixture to validate historical clock times without a separately reviewed remediation plan.

New timestamp behavior can be verified without touching permanent tables. The opt-in MariaDB test creates and drops a temporary table:

```bash
MARIADB_TEST_DATABASE=local_database_name RUN_MARIADB_TIMEZONE_TESTS=1 php artisan test --compact tests/Feature/MariaDbTimezoneContractTest.php
```

Before production release, verify the application connection reports `@@session.time_zone = '+00:00'`. If production already contains timestamps created under another session contract, stop and audit those records before enabling the new connection timezone.
