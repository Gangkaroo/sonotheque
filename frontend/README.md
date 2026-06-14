# Music Library Frontend

Vue and Vuetify interface for browsing and configuring the local music
library.

## Requirements

- Node.js 22.12 or newer
- npm 10 or newer
- The Laravel backend running on `http://127.0.0.1:8000`

## Local Setup

```powershell
Copy-Item .env.example .env
npm install
npm run dev
```

The development server runs at `http://127.0.0.1:5173` and proxies `/api`
requests to Laravel.

## Verification

```powershell
npm run lint
npm run type-check
npm run test
npm run build
```

The initial shell includes Vue Router, Pinia preferences, English and German
translations, Vuetify light and dark themes, and placeholder routes for the
dashboard, artists, albums, genres, tracks, and settings.
