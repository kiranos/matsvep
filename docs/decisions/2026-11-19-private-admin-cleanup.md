# Private admin cleanup interface

Phase 1 needs a minimal `user` table for admin cleanup before household authentication arrives in phase 2. An admin account has a password hash and account-level login lockout. Every mutation writes an audit record. Recipe feature corrections use a separate override table and are applied after automatic derivation; manual dish-group membership is locked against regrouping.

The admin site is served only by an Apache virtual host bound to `127.0.0.1:8081`, with `Require local`, through a dedicated PHP-FPM pool. It is reached through an operator-controlled SSH tunnel. The public virtual host continues to serve its existing static document root. Login, session and CSRF checks remain required inside the private site. The English admin interface is an operator tool; app API responses continue to use codes and parameters rather than display text.
