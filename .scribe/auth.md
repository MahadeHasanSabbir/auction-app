# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {YOUR_API_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

Create a token on the website under <b>Profile → API tokens</b> and send it as <code>Authorization: Bearer &lt;token&gt;</code>. A read-only token may only use GET.
