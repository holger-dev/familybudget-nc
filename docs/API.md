# FamilyBudget API (Nextcloud app)

This document describes the HTTP endpoints exposed by the FamilyBudget Nextcloud app, with a focus on creating and managing expenses. All routes are relative to your Nextcloud base URL.

- Base URL (new): `https://<your-nextcloud>/apps/familybudget`
- Note: If your instance still uses the legacy app id, the base URL is `.../apps/familybudget`.
- Auth: Nextcloud session or Basic Auth with app password
  - Example header: `Authorization: Basic <base64(username:app-password)>`
- Content type: `application/json`
- All endpoints below require an authenticated user and are marked `@NoAdminRequired`.

## Books

GET `/books`
- Returns the list of books the user can access.
- 200 response body:
```
{ "books": [ { "id": 1, "name": "Haushalt", "owner_uid": "alice", "role": "owner" }, ... ] }
```

POST `/books`
- Create a new book owned by the current user.
- Request body:
```
{ "name": "Haushalt" }
```
- 201 response body:
```
{ "id": 3, "name": "Haushalt", "owner_uid": "alice", "role": "owner" }
```

POST `/books/{id}/rename` (also supports PUT/PATCH `/books/{id}` in some setups)
- Rename a book (owner only).
- Request body: `{ "name": "Neuer Name" }`
- 200 response: `{ "ok": true, "id": 3, "name": "Neuer Name" }`

DELETE `/books/{id}`
- Delete a book (owner only). Also deletes members and expenses.
- 200 response: `{ "ok": true }`

POST `/books/{id}/invite`
- Invite another user to a book (must be a member; typically owner).
- Request body: `{ "user": "bob" }` (uid)
- 200 response: `{ "ok": true }`

GET `/books/{id}/members`
- List members of a book (must be a member).
- 200 response body:
```
{ "members": [
  { "user_uid": "alice", "role": "owner", "created_at": "2024-09-03 12:00:00", "display_name": "Alice Example" },
  { "user_uid": "bob",   "role": "member", "created_at": "2024-09-04 09:15:00", "display_name": "Bob Example" }
] }
```

## Expenses

GET `/books/{id}/expenses`
- List all expenses for a book (must be a member).
- 200 response body:
```
{ "expenses": [
  {
    "id": 12,
    "book_id": 3,
    "user_uid": "alice",
    "amount_cents": 1599,
    "currency": "EUR",
    "description": "Wocheneinkauf",
    "occurred_at": "2025-09-03 00:00:00",
    "created_at": "2025-09-03 10:22:31"
  },
  ...
] }
```

POST `/books/{id}/expenses`
- Create a new expense in a book (must be a member). The payer is the authenticated user.
- Request body:
```
{ "amount": 15.99, "description": "Wocheneinkauf", "date": "2025-09-03", "currency": "EUR" }
```
- 201 response body: `{ "id": 42 }`

PATCH `/books/{id}/expenses/{eid}`
- Update fields of an expense (member of the book).
- Request body (any subset):
```
{ "amount": 19.5, "description": "Bio-Einkauf", "date": "2025-09-04", "currency": "EUR" }
```
- 200 response: `{ "ok": true }`

DELETE `/books/{id}/expenses/{eid}`
- Delete an expense (member of the book).
- 200 response: `{ "ok": true }`

## Status codes
- 200 OK, 201 Created
- 400 Bad Request (missing/invalid fields)
- 401 Unauthorized (not logged in)
- 403 Forbidden (no access to the book)
- 404 Not Found (resource not found)

## iOS request examples

Swift (URLSession + Basic Auth with app password):

```swift
let base = URL(string: "https://yourcloud.example.com/apps/familybudget")!
let bookId = 3
let url = base.appendingPathComponent("books/\(bookId)/expenses")

var req = URLRequest(url: url)
req.httpMethod = "POST"
req.addValue("application/json", forHTTPHeaderField: "Content-Type")

// Basic auth (username:app-password)
let credentials = "alice:YOUR_APP_PASSWORD"
let token = Data(credentials.utf8).base64EncodedString()
req.addValue("Basic \(token)", forHTTPHeaderField: "Authorization")

struct CreateExpense: Codable { let amount: Double; let description: String?; let date: String; let currency: String }
let payload = CreateExpense(amount: 15.99, description: "Wocheneinkauf", date: "2025-09-03", currency: "EUR")
req.httpBody = try JSONEncoder().encode(payload)

URLSession.shared.dataTask(with: req) { data, resp, err in
    if let err = err { print("Error:", err); return }
    guard let http = resp as? HTTPURLResponse else { return }
    print("Status", http.statusCode)
    if http.statusCode == 201, let data = data {
        print(String(data: data, encoding: .utf8) ?? "")
    }
}.resume()
```

Listing expenses:
```swift
let listURL = base.appendingPathComponent("books/\(bookId)/expenses")
var listReq = URLRequest(url: listURL)
listReq.addValue("Basic \(token)", forHTTPHeaderField: "Authorization")
URLSession.shared.dataTask(with: listReq) { data, resp, _ in
    // parse JSON { expenses: [...] }
}.resume()
```

Update an expense:
```swift
let eid = 42
let updURL = base.appendingPathComponent("books/\(bookId)/expenses/\(eid)")
var updReq = URLRequest(url: updURL)
updReq.httpMethod = "PATCH"
updReq.addValue("application/json", forHTTPHeaderField: "Content-Type")
updReq.addValue("Basic \(token)", forHTTPHeaderField: "Authorization")
let body = ["amount": 19.50, "description": "Bio-Einkauf"] as [String : Any]
updReq.httpBody = try! JSONSerialization.data(withJSONObject: body)
URLSession.shared.dataTask(with: updReq) { _, resp, _ in
    print((resp as? HTTPURLResponse)?.statusCode as Any)
}.resume()
```

Delete an expense:
```swift
let delURL = base.appendingPathComponent("books/\(bookId)/expenses/\(eid)")
var delReq = URLRequest(url: delURL)
delReq.httpMethod = "DELETE"
delReq.addValue("Basic \(token)", forHTTPHeaderField: "Authorization")
URLSession.shared.dataTask(with: delReq) { _, resp, _ in
    print((resp as? HTTPURLResponse)?.statusCode as Any)
}.resume()
```

## Notes
- Amounts: create/update endpoints accept `amount` as a decimal (Double). The backend stores `amount_cents` as integer.
- Dates: send `date` in `YYYY-MM-DD`.
- Payer: the authenticated user is set as `user_uid` on creation.
- Permissions: only members of the book can list/create/update/delete its expenses.
- Members: `display_name` is included when available; otherwise, `user_uid` can be used as fallback.
