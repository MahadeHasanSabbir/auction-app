# Introduction

Version 1 of the REST API for bidding, orders and seller tools.

<aside>
    <strong>Base URL</strong>: <code>http://127.0.0.1:8000</code>
</aside>

    Everything here uses the same rules as the website: roles, identity checks, the biddable share of your wallet and rate limits.

    - **Base path:** `/api/v1`. Send `Accept: application/json`.
    - **Money** is `{"amount": 150000, "currency": "BDT", "formatted": "BDT 1,500.00"}`, an integer in minor units (paisa). When you send an amount, use major units as a string, for example `"1500.50"`.
    - **Times** are ISO 8601 in UTC.
    - **Lists** are paginated: `?page=2&per_page=20` (at most 50) and answer with `data`, `links` and `meta`.
    - **Errors:** `401` no or bad token, `403` not allowed (wrong role, read-only token, identity check pending), `404` not found, `422` a rule was not met (`errors` names the fields), `429` too many requests.
    - **Not in the API:** deposits and withdrawals, which need a payment page or a fresh emailed code.

