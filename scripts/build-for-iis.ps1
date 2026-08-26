# Builds the app and assembles a self-contained folder for iisnode at .next/standalone.
# Run this ON the IIS server, from the project root, after `.env.production` is in place.
# See DEPLOY.md for the full procedure.

$ErrorActionPreference = "Stop"

if (-not (Test-Path ".env.production")) {
    Write-Warning ".env.production not found at project root — DATABASE_URL/AUTH_SECRET must be set some other way (see DEPLOY.md)."
}

npm ci
npx prisma generate
npm run build

$standalone = ".next/standalone"

Copy-Item -Path "public" -Destination "$standalone/public" -Recurse -Force
New-Item -ItemType Directory -Force -Path "$standalone/.next" | Out-Null
Copy-Item -Path ".next/static" -Destination "$standalone/.next/static" -Recurse -Force
Copy-Item -Path "web.config" -Destination "$standalone/web.config" -Force

Write-Host ""
Write-Host "Deployment folder ready: $standalone"
Write-Host "Point the IIS site's physical path at this folder (see DEPLOY.md)."
