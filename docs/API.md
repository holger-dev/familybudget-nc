# FamilyBudget API (Nextcloud App)

Diese Dokumentation ist für die Implementierung der Flutter‑App ausgelegt. Sie beschreibt alle relevanten OCS‑Endpunkte inklusive Request/Response‑Schemas und einen kompakten Flutter/Dart‑Beispielservice.

Version: 0.2.2

## Überblick

- App‑Routen (Web‑UI): `https://<cloud>/apps/familybudget/...` (Session/CSRF‑geschützt; nicht für mobile Clients)
- OCS‑API (für externe/mobile Clients): `https://<cloud>/ocs/v2.php/apps/familybudget/...`

Authentifizierung und Header (OCS):
- Basic Auth mit Nextcloud App‑Passwort: `Authorization: Basic <base64(username:app-password)>`
- Pflicht‑Header: `OCS-APIRequest: true`
- Content‑Type für Schreibaufrufe: `application/json`

Antwortformat: JSON‑Objekte mit Payload (kein OCS‑XML‑Wrapper erforderlich).

## Datenmodelle

Book
```
{ "id": number, "name": string, "owner_uid": string, "role": "owner"|"member" }
```

Member
```
{ "user_uid": string, "role": "owner"|"member", "created_at": string|null, "display_name"?: string }
```

Expense
```
{
  "id": number,
  "book_id": number,
  "user_uid": string,
  "amount_cents": number,
  "currency": string,
  "description": string|null,
  "occurred_at": "YYYY-MM-DD 00:00:00",
  "created_at": "YYYY-MM-DD HH:MM:SS"
}
```

Client‑Mapping (Empfehlung):
- `amount` (Double) ↔ `amount_cents` (int) mit Faktor 100 konvertieren
- `date` als `YYYY-MM-DD`; Server speichert `occurred_at` mit Zeit `00:00:00`
- `user` in UI aus `display_name` (fallback `user_uid`)

## Endpunkte (OCS)

Base: `https://<cloud>/ocs/v2.php/apps/familybudget`
Header: `OCS-APIRequest: true`

Books
- GET `/books` → `{ "books": Book[] }`
- POST `/books` Body `{ "name": string }` → `201 { ...Book }`
- PUT|PATCH `/books/{id}` Body `{ "name": string }` → `{ ok: true, id, name }`
- POST `/books/{id}/rename` Body `{ "name": string }` → `{ ok: true, id, name }`
- GET `/books/{id}/members` → `{ "members": Member[] }`
- POST `/books/{id}/invite` Body `{ "user": "<uid>" }` → `{ ok: true }`
- DELETE `/books/{id}/members/{uid}` → `{ ok: true }`
- DELETE `/books/{id}` → `{ ok: true }`

Expenses
- GET `/books/{id}/expenses` → `{ "expenses": Expense[] }`
  - Optional: Monatsfilter per Query
    - Einzelmonat: `?month=YYYY-MM`
    - Mehrere Monate (wiederholt): `?month=2025-07&month=2025-08`
    - Oder kommasepariert: `?months=2025-07,2025-08,2025-09`
    - Alternativ Zeitraum: `?from=YYYY-MM[&to=YYYY-MM]` (inkl. `from` bis exkl. Monat nach `to`)
  - Filter wirkt als OR über Monatsbereiche (jeweils von Monatsbeginn inkl. bis Folgemonatsbeginn exkl.).
  - Priorität: Wenn `from`/`to` gesetzt ist, wird die Monatsliste ignoriert.
- POST `/books/{id}/expenses` Body `{ amount: number, description?: string, date: "YYYY-MM-DD", currency?: string }` → `201 { id: number }`
- PATCH `/books/{id}/expenses/{eid}` Body beliebiges Teilset `{ amount?, description?, date?, currency? }` → `{ ok: true }`
- DELETE `/books/{id}/expenses/{eid}` → `{ ok: true }`

Statuscodes
- 200 OK, 201 Created
- 400 Bad Request (fehlende/ungültige Felder)
- 401 Unauthorized (Auth/Passwort fehlt/falsch)
- 403 Forbidden (kein Zugriff auf Buch)
- 404 Not Found (Ressource existiert nicht)

## Flutter‑Quickstart (Dio)

Beispiel‑Service (vereinfachter Ausschnitt). Erwartet `username` und `appPassword` (Nextcloud App‑Passwort).

```dart
import 'dart:convert';
import 'package:dio/dio.dart';

class NcApiService {
  final String baseUrl; // e.g. https://cloud.example.com/ocs/v2.php/apps/familybudget
  final Dio _dio;

  NcApiService({required this.baseUrl, required String username, required String appPassword})
      : _dio = Dio(BaseOptions(
          baseUrl: baseUrl,
          headers: {
            'OCS-APIRequest': 'true',
            'Authorization': 'Basic ' + base64Encode(utf8.encode('$username:$appPassword')),
          },
        ));

  // Books
  Future<List<dynamic>> listBooks() async {
    final res = await _dio.get('/books');
    return (res.data['books'] as List?) ?? [];
  }

  Future<Map<String, dynamic>> createBook(String name) async {
    final res = await _dio.post('/books', data: {'name': name});
    return Map<String, dynamic>.from(res.data);
  }

  Future<void> renameBook(int id, String name) async {
    await _dio.patch('/books/$id', data: {'name': name});
  }

  Future<List<dynamic>> listMembers(int id) async {
    final res = await _dio.get('/books/$id/members');
    return (res.data['members'] as List?) ?? [];
  }

  Future<void> inviteMember(int id, String uid) async {
    await _dio.post('/books/$id/invite', data: {'user': uid});
  }

  Future<void> removeMember(int id, String uid) async {
    await _dio.delete('/books/$id/members/$uid');
  }

  Future<void> deleteBook(int id) async {
    await _dio.delete('/books/$id');
  }

  // Expenses
  Future<List<dynamic>> listExpenses(int bookId) async {
    final res = await _dio.get('/books/$bookId/expenses');
    return (res.data['expenses'] as List?) ?? [];
  }

  // Mit Monatsfilter
  Future<List<dynamic>> listExpensesByMonths(int bookId, List<String> months) async {
    // months-Elemente: 'YYYY-MM'
    final query = months.isEmpty
        ? ''
        : months.map((m) => 'month=' + Uri.encodeQueryComponent(m)).join('&');
    final path = query.isEmpty
        ? '/books/$bookId/expenses'
        : '/books/$bookId/expenses?$query';
    final res = await _dio.get(path);
    return (res.data['expenses'] as List?) ?? [];
  }

  // Mit Zeitraumfilter (from/to als 'YYYY-MM')
  Future<List<dynamic>> listExpensesByRange(int bookId, {String? from, String? to}) async {
    final q = <String>[];
    if (from != null) q.add('from=' + Uri.encodeQueryComponent(from));
    if (to != null) q.add('to=' + Uri.encodeQueryComponent(to));
    final path = q.isEmpty
        ? '/books/$bookId/expenses'
        : '/books/$bookId/expenses?' + q.join('&');
    final res = await _dio.get(path);
    return (res.data['expenses'] as List?) ?? [];
  }

  Future<int> createExpense({
    required int bookId,
    required double amount,
    required String date, // YYYY-MM-DD
    String? description,
    String currency = 'EUR',
  }) async {
    final res = await _dio.post('/books/$bookId/expenses', data: {
      'amount': amount,
      'date': date,
      'description': description,
      'currency': currency,
    });
    return (res.data['id'] as num).toInt();
  }

  Future<void> updateExpense({
    required int bookId,
    required int expenseId,
    double? amount,
    String? date, // YYYY-MM-DD
    String? description,
    String? currency,
  }) async {
    final body = <String, dynamic>{};
    if (amount != null) body['amount'] = amount;
    if (date != null) body['date'] = date;
    if (description != null) body['description'] = description;
    if (currency != null) body['currency'] = currency;
    await _dio.patch('/books/$bookId/expenses/$expenseId', data: body);
  }

  Future<void> deleteExpense(int bookId, int expenseId) async {
    await _dio.delete('/books/$bookId/expenses/$expenseId');
  }
}
```

Hinweise zum Mapping:
- Für UI‑Darstellung können aus `occurred_at` ISO‑Dates abgeleitet werden (`YYYY-MM-DDT00:00:00.000Z`).
- Monatsfilter clientseitig umsetzen (API filtert noch nicht nach Zeitraum).

## Testen mit curl

```bash
curl -u USER:APP-PASS -H 'OCS-APIRequest: true' \
  https://<cloud>/ocs/v2.php/apps/familybudget/books

curl -u USER:APP-PASS -H 'OCS-APIRequest: true' -H 'Content-Type: application/json' \
  -d '{"name":"Haushalt"}' \
  https://<cloud>/ocs/v2.php/apps/familybudget/books
```

### PHP Smoke Test

Ein vollautomatisierter Durchlauf (Bücher listen → Buch erstellen/umbenennen → Ausgabe erstellen/listen/ändern/löschen → Buch löschen) ist im Repo enthalten:

```bash
php scripts/ocs_smoke.php https://<cloud>/ocs/v2.php/apps/familybudget USER APP-PASS
```

## Migration/Notizen

- Es gibt kein `/api/v1`. Mobile Clients müssen die OCS‑Basis verwenden.
- App‑Routen bleiben für die Web‑Oberfläche; nur GETs sind dort CSRF‑frei.
- Für alle Schreiboperationen extern: OCS‑Pfad + `OCS-APIRequest: true`.
