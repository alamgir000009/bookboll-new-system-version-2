# Bookboll — three standalone apps

| Project | Domain | Path | Port |
|---|---|---|---|
| **bookboll-web** | bookboll.com | [`apps/web`](apps/web) | 5173 |
| **bookboll-business** | business.bookboll.com | [`apps/business`](apps/business) | 5175 |
| **bookboll-admin** | admin.bookboll.com | [`apps/admin`](apps/admin) | 5174 |

Each app is fully independent (`package.json`, `.env`, Vite, `src/`).

```bash
cd apps/web && npm install && npm run dev
cd apps/business && npm install && npm run dev
cd apps/admin && npm install && npm run dev
```
