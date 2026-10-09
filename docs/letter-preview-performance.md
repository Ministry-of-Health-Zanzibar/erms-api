# Letter preview performance

Referral, transferred-referral, follow-up and boarded-out letters use the same
loading improvements. The dashboard opens the existing File viewer immediately,
shows the reusable loading state, downloads the authenticated PDF once, and
loads its iframe eagerly. Closing during preparation cancels the request. Print
is disabled until the PDF frame has loaded. Merely previewing does not record a print.

Prepared signature/stamp assets are cached for one day. Their actual file contents
form the key, so uploads, reset, or an in-place file replacement take effect
immediately. Transparency, ink color, resolution and letter templates are unchanged.

Generated PDF bytes are encrypted using the existing application key and cached
privately for ten minutes. Each request still checks permissions and resolves the
active letter from the database before using the cache. The rendered HTML forms
the version key: patient, destination hospital, language, dates/age, flights,
recommendations, template and branding changes cannot return an old version.
Print-audit updates alone do not require another PDF render. Cache outages,
expired entries or unreadable encrypted entries fall back to a fresh render.

Responses retain `private, no-store`; the UI bypasses its general GET-response
cache for these PDFs. No medical PDF is persisted in a browser cache or public
upload folder by this feature. Printing continues to use the original tracking
endpoint, including the distinction between original and transferred hospitals.

Deploy the API and UI together. No additional migration is required for this
performance change. Production must use a persistent private cache store (`file`
or `redis`, not `array` or `null`) for reuse across requests. With the existing
file cache, ensure `storage/framework/cache/data` is writable by the PHP service
user and not served publicly. Keep the existing `APP_KEY`; do not regenerate it.
Run `php artisan optimize:clear` after deployment to refresh compiled code/views.

The first preview after a content change still needs PDF rendering; subsequent
unchanged previews skip that work. Network and native browser PDF loading time
remain dependent on the deployed server and user's connection.
