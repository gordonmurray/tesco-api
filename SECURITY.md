# Security

Use PHP 8.5.12 or a newer supported patch. The Dockerfile and Composer requirements set this minimum because of the October 21, 2026 PHP security release.

The default service is local-only. Remote access requires HTTPS, a strong `APP_API_TOKEN`, and request limits at a reverse proxy.

Do not include Tesco account credentials, local bearer tokens, or database contents in public issues. Report security defects through the repository's private vulnerability reporting feature when available.

This wrapper makes read-only requests to Tesco. Watchlist and history writes affect only its local SQLite database.
