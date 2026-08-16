# Sonotheque Frontend

Vue and Vuetify interface for browsing, scanning, playing, favoriting, and
organizing the local music library.

## Requirements

- Node.js 22.18 or newer
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

See `../docs/runtime.md` for the complete local startup guide.

## Verification

```powershell
npm run lint
npm run type-check
npm run test
npm run build
```

The frontend uses Vue Router, Pinia, Vue I18n, Vuetify, English/German
translations, and a persistent player store for queue and playback state.
