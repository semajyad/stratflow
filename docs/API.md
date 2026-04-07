# API / Route Reference

All routes are defined in `src/Config/routes.php`. The application uses a custom vanilla PHP router with support for URL parameter placeholders (`{id}`) and per-route middleware stacks.

## Middleware

| Key | Class | Description |
|-----|-------|-------------|
| `auth` | `AuthMiddleware` | Requires an active session; redirects to `/login` if unauthenticated |
| `csrf` | `CSRFMiddleware` | Validates the `_csrf_token` field in POST requests; aborts with 403 on mismatch |

Middleware is run in the order listed in the route definition.

---

## Route Table

| Method | Path | Controller | Middleware | Description |
|--------|------|------------|------------|-------------|
| GET | `/` | `PricingController@index` | — | Landing / pricing page |
| GET | `/pricing` | `PricingController@index` | — | Pricing page (alias) |
| POST | `/checkout` | `CheckoutController@create` | csrf | Create Stripe Checkout session |
| POST | `/webhook/stripe` | `WebhookController@handle` | — | Stripe signed webhook receiver |
| GET | `/success` | `SuccessController@index` | — | Post-payment success page |
| GET | `/login` | `AuthController@showLogin` | — | Login page |
| POST | `/login` | `AuthController@login` | csrf | Login form submit |
| POST | `/logout` | `AuthController@logout` | csrf, auth | Logout and destroy session |
| GET | `/app/home` | `HomeController@index` | auth | Dashboard — project list |
| POST | `/app/projects` | `HomeController@createProject` | auth, csrf | Create a new project |
| GET | `/app/upload` | `UploadController@index` | auth | Document upload page |
| POST | `/app/upload` | `UploadController@store` | auth, csrf | Upload and process a document file |
| POST | `/app/upload/summarise` | `UploadController@generateSummary` | auth, csrf | Generate AI summary for a document |
| GET | `/app/diagram` | `DiagramController@index` | auth | Strategy diagram page |
| POST | `/app/diagram/generate` | `DiagramController@generate` | auth, csrf | Generate Mermaid diagram via AI |
| POST | `/app/diagram/save` | `DiagramController@save` | auth, csrf | Persist the current diagram |
| POST | `/app/diagram/save-okr` | `DiagramController@saveOkr` | auth, csrf | Save OKR data for a diagram node |
| GET | `/app/work-items` | `WorkItemController@index` | auth | Work items list page |
| POST | `/app/work-items/generate` | `WorkItemController@generate` | auth, csrf | AI-generate work items from diagram |
| POST | `/app/work-items/reorder` | `WorkItemController@reorder` | auth | AJAX: update priority order |
| GET | `/app/work-items/export` | `WorkItemController@export` | auth | Download CSV or JSON export |
| POST | `/app/work-items/{id}` | `WorkItemController@update` | auth, csrf | Update a work item's fields |
| POST | `/app/work-items/{id}/delete` | `WorkItemController@delete` | auth, csrf | Delete a work item |
| POST | `/app/work-items/{id}/generate-description` | `WorkItemController@generateDescription` | auth | AI-generate a detailed scope description |

---

## Notes

### Stripe Webhook

`POST /webhook/stripe` deliberately has **no CSRF middleware**. Stripe sends a raw signed HTTP POST, not a browser form submission. The controller verifies the request authenticity using `STRIPE_WEBHOOK_SECRET` via Stripe's SDK signature verification instead.

### Static-before-dynamic route ordering

Work item routes are registered with static paths (`/generate`, `/reorder`, `/export`) before the dynamic `{id}` pattern. The router matches routes in registration order, so this prevents `/generate` from being caught as an `{id}` value.

### URL parameters

Placeholders like `{id}` in route patterns are converted to named regex capture groups (`(?P<id>[^/]+)`) by the router. The matched value is available to the controller via the `Request` object.

### Response formats

- Standard pages return HTML views rendered from `templates/`
- AJAX endpoints (`/reorder`, `/generate-description`) return JSON
- The export endpoint returns a file download (`text/csv` or `application/json`)
